<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches HubSpot form definitions from the public embed endpoint (the same
 * JSONP endpoint the official embed script uses) — no API token required —
 * and normalizes them into a simple, render-friendly structure cached in the
 * WordPress options table.
 *
 * Endpoint: https://forms.hsforms.com/embed/v3/form/{portalId}/{formId}?callback=…
 * Returns JSONP: cb({ "form": { … legacy formFieldGroups schema … } })
 */
class HFF_Sync {

	/**
	 * Build the public form-definition URL for a portal/form/region.
	 *
	 * @param string $portal_id
	 * @param string $form_id
	 * @param string $region
	 * @return string
	 */
	protected static function endpoint( $portal_id, $form_id, $region ) {
		$region = strtolower( trim( (string) $region ) );
		// na1 is served by the generic host; other regions use a regional host.
		$host = ( '' === $region || 'na1' === $region )
			? 'https://forms.hsforms.com'
			: 'https://forms-' . preg_replace( '/[^a-z0-9]/', '', $region ) . '.hsforms.com';

		return $host . '/embed/v3/form/' . rawurlencode( $portal_id ) . '/' . rawurlencode( $form_id )
			. '?callback=hff&hs_static_app=forms-embed';
	}

	/**
	 * Fetch and normalize a single form from the public endpoint.
	 *
	 * @param string $portal_id HubSpot portal (hub) ID.
	 * @param string $form_id   HubSpot form GUID.
	 * @param string $region    HubSpot data region (e.g. na1, eu1). Optional.
	 * @return array|WP_Error Normalized form array or WP_Error on failure.
	 */
	public static function fetch_form( $portal_id, $form_id, $region = 'na1' ) {
		$portal_id = trim( (string) $portal_id );
		$form_id   = trim( (string) $form_id );

		if ( '' === $portal_id ) {
			return new WP_Error( 'hff_no_portal', __( 'No portal ID provided.', 'hubspot-fallback-forms' ) );
		}
		if ( '' === $form_id ) {
			return new WP_Error( 'hff_no_form_id', __( 'No form ID provided.', 'hubspot-fallback-forms' ) );
		}

		$response = wp_remote_get(
			self::endpoint( $portal_id, $form_id, $region ),
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/javascript, application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = self::unwrap_jsonp( $raw );

		if ( 200 !== $code ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				: sprintf( __( 'HubSpot returned HTTP %d. Check the portal ID, form ID, and region.', 'hubspot-fallback-forms' ), $code );
			return new WP_Error( 'hff_api_error', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) || empty( $data['form'] ) ) {
			return new WP_Error( 'hff_bad_response', __( 'Unexpected response from HubSpot (no form data).', 'hubspot-fallback-forms' ) );
		}

		return self::normalize( $portal_id, $form_id, $region, $data['form'] );
	}

	/**
	 * Strip the JSONP callback wrapper and decode the JSON payload.
	 *
	 * @param string $raw
	 * @return array|null
	 */
	protected static function unwrap_jsonp( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$raw = trim( $raw );

		// Extract the substring between the first "(" and the last ")".
		$open  = strpos( $raw, '(' );
		$close = strrpos( $raw, ')' );
		if ( false !== $open && false !== $close && $close > $open ) {
			$json = substr( $raw, $open + 1, $close - $open - 1 );
		} else {
			$json = $raw; // Maybe it was plain JSON.
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Normalize a legacy HubSpot form object (formFieldGroups schema) into our
	 * internal render structure.
	 *
	 * @param string $portal_id
	 * @param string $form_id
	 * @param string $region
	 * @param array  $form
	 * @return array
	 */
	public static function normalize( $portal_id, $form_id, $region, $form ) {
		$normalized = array(
			'formId'     => $form_id,
			'portalId'   => $portal_id,
			'region'     => $region,
			'name'       => isset( $form['name'] ) ? (string) $form['name'] : '',
			'submitText' => ! empty( $form['submitText'] ) ? (string) $form['submitText'] : 'Submit',
			'fields'     => array(),
			'consents'   => array(),
			'legalText'  => array(),
			'captcha'    => ! empty( $form['captchaEnabled'] ),
			'cssClass'   => isset( $form['cssClass'] ) ? (string) $form['cssClass'] : 'hs-form stacked',
			'themeName'  => isset( $form['themeName'] ) ? (string) $form['themeName'] : '',
			'style'      => array(),
			'synced_at'  => time(),
		);

		// The theme "style" tokens arrive as a JSON string; decode to an array.
		if ( ! empty( $form['style'] ) ) {
			if ( is_string( $form['style'] ) ) {
				$decoded = json_decode( $form['style'], true );
				if ( is_array( $decoded ) ) {
					$normalized['style'] = $decoded;
				}
			} elseif ( is_array( $form['style'] ) ) {
				$normalized['style'] = $form['style'];
			}
		}

		// Fields + inline richText disclaimers, in document order. Each field
		// group becomes a "row"; the number of visible fields in the group is
		// the column count (HubSpot's form-columns-N layout).
		if ( ! empty( $form['formFieldGroups'] ) && is_array( $form['formFieldGroups'] ) ) {
			$row_index = 0;
			foreach ( $form['formFieldGroups'] as $group ) {
				$group_fields = ( ! empty( $group['fields'] ) && is_array( $group['fields'] ) ) ? $group['fields'] : array();

				// Count visible (non-hidden) fields to determine the column count.
				$visible = 0;
				foreach ( $group_fields as $gf ) {
					if ( is_array( $gf ) && ! empty( $gf['name'] ) && empty( $gf['hidden'] ) ) {
						$visible++;
					}
				}
				$cols = $visible > 0 ? min( $visible, 4 ) : 1;

				foreach ( $group_fields as $field ) {
					$parsed = self::parse_field( $field );
					if ( ! $parsed ) {
						continue;
					}
					// Only visible fields participate in the column layout.
					if ( empty( $parsed['hidden'] ) ) {
						$parsed['row']  = $row_index;
						$parsed['cols'] = $cols;
					}
					$normalized['fields'][] = $parsed;
				}

				if ( $visible > 0 ) {
					$row_index++;
				}

				// A richText group break = an inline HTML disclaimer/section (full width).
				$rich = isset( $group['richText'] ) && is_array( $group['richText'] )
					? (string) ( $group['richText']['content'] ?? '' )
					: '';
				if ( '' !== trim( $rich ) ) {
					$normalized['fields'][] = array(
						'type' => 'html',
						'name' => '',
						'html' => wp_kses_post( $rich ),
					);
				}
			}
		}

		// GDPR / legal consent (present only on consent-enabled forms).
		if ( ! empty( $form['legalConsentOptions'] ) && is_array( $form['legalConsentOptions'] ) ) {
			self::parse_consent( $form['legalConsentOptions'], $normalized );
		}

		return $normalized;
	}

	/**
	 * Parse one legacy field object into our schema.
	 *
	 * @param array $field
	 * @return array|null
	 */
	protected static function parse_field( $field ) {
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return null;
		}

		$name     = (string) $field['name'];
		$label    = isset( $field['label'] ) ? (string) $field['label'] : $name;
		$hs_type  = isset( $field['fieldType'] ) ? strtolower( (string) $field['fieldType'] ) : 'text';
		$required = ! empty( $field['required'] );
		$hidden   = ! empty( $field['hidden'] );
		$type     = self::map_type( $hs_type, $name );

		// Default value: prefer selectedOptions for choice fields.
		$default = isset( $field['defaultValue'] ) ? (string) $field['defaultValue'] : '';
		if ( '' === $default && ! empty( $field['selectedOptions'] ) && is_array( $field['selectedOptions'] ) ) {
			$default = (string) $field['selectedOptions'][0];
		}

		// Options (drop hidden ones from display).
		$options = array();
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			foreach ( $field['options'] as $opt ) {
				if ( ! is_array( $opt ) || ! isset( $opt['value'] ) || ! empty( $opt['hidden'] ) ) {
					continue;
				}
				$options[] = array(
					'value' => (string) $opt['value'],
					'label' => isset( $opt['label'] ) ? (string) $opt['label'] : (string) $opt['value'],
				);
			}
		}

		return array(
			'name'        => $name,
			'label'       => $label,
			'type'        => $type,
			'required'    => $required,
			'hidden'      => $hidden,
			'placeholder' => isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '',
			'default'     => $default,
			'options'     => $options,
		);
	}

	/**
	 * Map a HubSpot legacy fieldType (and field name) to an internal input type.
	 *
	 * @param string $hs_type
	 * @param string $name
	 * @return string
	 */
	protected static function map_type( $hs_type, $name ) {
		$name_l = strtolower( $name );

		if ( 'email' === $name_l ) {
			return 'email';
		}
		if ( in_array( $name_l, array( 'phone', 'mobilephone' ), true ) ) {
			return 'tel';
		}

		switch ( $hs_type ) {
			case 'textarea':
				return 'textarea';
			case 'phonenumber':
				return 'tel';
			case 'number':
				return 'number';
			case 'date':
				return 'date';
			case 'select':
				return 'select';
			case 'radio':
				return 'radio';
			case 'checkbox':
				return 'checkbox';        // Multiple-choice checkboxes.
			case 'booleancheckbox':
				return 'booleancheckbox'; // Single yes/no consent-style checkbox.
			case 'text':
			default:
				return 'text';
		}
	}

	/**
	 * Parse legalConsentOptions into consent checkboxes and disclaimer text.
	 * Supports both legacy (v2) and newer key names defensively.
	 *
	 * @param array $legal
	 * @param array $normalized Passed by reference.
	 */
	protected static function parse_consent( $legal, &$normalized ) {
		// Free-standing disclaimer / privacy text.
		foreach ( array( 'processingConsentText', 'consentToProcessText', 'communicationConsentText', 'privacyPolicyText', 'privacyText', 'consentToProcessFooterText' ) as $key ) {
			if ( ! empty( $legal[ $key ] ) ) {
				$normalized['legalText'][] = wp_kses_post( (string) $legal[ $key ] );
			}
		}

		// Required "consent to process" checkbox (legacy: processingConsentType === REQUIRED_CHECKBOX).
		$process_label = '';
		if ( ! empty( $legal['processingConsentCheckboxLabel'] ) ) {
			$process_label = (string) $legal['processingConsentCheckboxLabel'];
		} elseif ( ! empty( $legal['consentToProcessCheckbox']['label'] ) ) {
			$process_label = (string) $legal['consentToProcessCheckbox']['label'];
		}
		if ( '' !== $process_label ) {
			$required = true;
			if ( isset( $legal['processingConsentType'] ) ) {
				$required = ( 'REQUIRED_CHECKBOX' === $legal['processingConsentType'] );
			} elseif ( isset( $legal['consentToProcessCheckbox']['required'] ) ) {
				$required = ! empty( $legal['consentToProcessCheckbox']['required'] );
			}
			$normalized['consents'][] = array(
				'name'     => 'hff_consent_process',
				'label'    => wp_kses_post( $process_label ),
				'required' => $required,
			);
		}

		// Communication subscription checkboxes.
		if ( ! empty( $legal['communicationsCheckboxes'] ) && is_array( $legal['communicationsCheckboxes'] ) ) {
			$i = 0;
			foreach ( $legal['communicationsCheckboxes'] as $box ) {
				if ( empty( $box['label'] ) ) {
					continue;
				}
				$normalized['consents'][] = array(
					'name'     => 'hff_consent_comm_' . $i,
					'label'    => wp_kses_post( (string) $box['label'] ),
					'required' => ! empty( $box['required'] ),
				);
				$i++;
			}
		}
	}
}
