<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles front-end fallback form submissions via admin-ajax and emails them
 * through the Mailgun API.
 */
class HFF_Submission {

	/**
	 * Register hooks (logged-in and logged-out visitors).
	 */
	public function hooks() {
		add_action( 'wp_ajax_hff_submit', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_hff_submit', array( $this, 'handle' ) );
	}

	/**
	 * Process a submission.
	 */
	public function handle() {
		$settings = hff_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Form submissions are currently disabled.', 'hubspot-fallback-forms' ) ), 403 );
		}

		// Nonce check.
		$nonce = isset( $_POST['hff_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hff_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hff_submit' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'hubspot-fallback-forms' ) ), 400 );
		}

		// Honeypot: silently accept (pretend success) so bots don't retry.
		if ( ! empty( $_POST['hff_hp'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Thank you. Your submission has been received.', 'hubspot-fallback-forms' ) ) );
		}

		$form_id = isset( $_POST['hff_form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['hff_form_id'] ) ) : '';
		$forms   = hff_get_forms();
		$def     = isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : array();

		// Collect and validate the submitted values against the cached definition.
		$raw_fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$collected  = $this->collect_fields( $def, $raw_fields );

		if ( is_wp_error( $collected ) ) {
			wp_send_json_error( array( 'message' => $collected->get_error_message() ), 422 );
		}

		// Validate required consent checkboxes.
		if ( ! empty( $def['consents'] ) ) {
			foreach ( $def['consents'] as $consent ) {
				if ( ! empty( $consent['required'] ) && empty( $_POST[ $consent['name'] ] ) ) {
					wp_send_json_error( array( 'message' => __( 'Please tick all required consent boxes.', 'hubspot-fallback-forms' ) ), 422 );
				}
			}
		}
		$consent_rows = $this->collect_consents( $def );

		// Build and send the email.
		$page_url = isset( $_POST['hff_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['hff_page_url'] ) ) : '';
		$subject  = 'Hubspot Fallback Submission | ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body     = self::build_email_body( $collected, $consent_rows, $form_id, $def, $page_url );

		$recipients = array_filter( array_map( 'trim', explode( ',', (string) $settings['recipients'] ) ) );

		// Use the submitter's email as reply-to when available.
		$reply_to   = '';
		$reply_name = '';
		foreach ( $collected as $row ) {
			if ( 'email' === $row['type'] && is_email( $row['value'] ) ) {
				$reply_to = $row['value'];
			}
			if ( in_array( strtolower( $row['name'] ), array( 'firstname', 'lastname', 'name', 'fullname' ), true ) && '' === $reply_name ) {
				$reply_name = $row['value'];
			}
		}

		$sent = HFF_Mailer::send( $recipients, $subject, $body, $reply_to, $reply_name );

		if ( is_wp_error( $sent ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, there was a problem sending your submission. Please try again later.', 'hubspot-fallback-forms' ), 'detail' => $sent->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Thank you. Your submission has been received.', 'hubspot-fallback-forms' ) ) );
	}

	/**
	 * Sanitize submitted field values and enforce required fields.
	 *
	 * @param array $def        Cached form definition.
	 * @param array $raw_fields Raw submitted fields[name] => value.
	 * @return array|WP_Error   Array of rows: [ name, label, type, value ].
	 */
	protected function collect_fields( $def, $raw_fields ) {
		$rows = array();

		// If we have a definition, iterate it so labels/order/required are authoritative.
		if ( ! empty( $def['fields'] ) ) {
			foreach ( $def['fields'] as $field ) {
				$name = $field['name'];

				// Inline HTML/disclaimer blocks are not input fields.
				if ( 'html' === $field['type'] || '' === $name ) {
					continue;
				}

				// Boolean (single) checkboxes: checked => Yes, unchecked => No.
				if ( 'booleancheckbox' === $field['type'] ) {
					$checked = ! empty( $raw_fields[ $name ] );
					if ( ! empty( $field['required'] ) && empty( $field['hidden'] ) && ! $checked ) {
						return new WP_Error(
							'hff_required',
							sprintf( __( 'Please complete the required field: %s', 'hubspot-fallback-forms' ), wp_strip_all_tags( $field['label'] ) )
						);
					}
					$rows[] = array(
						'name'  => $name,
						'label' => wp_strip_all_tags( $field['label'] ),
						'type'  => $field['type'],
						'value' => $checked ? __( 'Yes', 'hubspot-fallback-forms' ) : __( 'No', 'hubspot-fallback-forms' ),
					);
					continue;
				}

				$value = isset( $raw_fields[ $name ] ) ? $raw_fields[ $name ] : '';
				$value = $this->sanitize_value( $field['type'], $value );

				if ( ! empty( $field['required'] ) && empty( $field['hidden'] ) ) {
					if ( '' === $value || array() === $value ) {
						return new WP_Error(
							'hff_required',
							sprintf( __( 'Please complete the required field: %s', 'hubspot-fallback-forms' ), wp_strip_all_tags( $field['label'] ) )
						);
					}
				}

				// Basic email format validation.
				if ( 'email' === $field['type'] && '' !== $value && ! is_email( $value ) ) {
					return new WP_Error( 'hff_bad_email', __( 'Please enter a valid email address.', 'hubspot-fallback-forms' ) );
				}

				$rows[] = array(
					'name'  => $name,
					'label' => wp_strip_all_tags( $field['label'] ),
					'type'  => $field['type'],
					'value' => is_array( $value ) ? implode( ', ', $value ) : $value,
				);
			}
			return $rows;
		}

		// No definition cached: accept whatever was submitted (defensive fallback).
		foreach ( $raw_fields as $name => $value ) {
			$rows[] = array(
				'name'  => sanitize_key( $name ),
				'label' => ucwords( str_replace( array( '_', '-' ), ' ', $name ) ),
				'type'  => 'text',
				'value' => is_array( $value ) ? implode( ', ', array_map( 'sanitize_text_field', $value ) ) : sanitize_text_field( $value ),
			);
		}
		return $rows;
	}

	/**
	 * Sanitize a value based on its field type.
	 *
	 * @param string $type
	 * @param mixed  $value
	 * @return mixed
	 */
	protected function sanitize_value( $type, $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}
		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Collect ticked consent boxes into printable rows.
	 *
	 * @param array $def
	 * @return array
	 */
	protected function collect_consents( $def ) {
		$rows = array();
		if ( empty( $def['consents'] ) ) {
			return $rows;
		}
		foreach ( $def['consents'] as $consent ) {
			$checked = ! empty( $_POST[ $consent['name'] ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle().
			$rows[]  = array(
				'label'   => wp_strip_all_tags( $consent['label'] ),
				'checked' => $checked,
			);
		}
		return $rows;
	}

	/**
	 * Generate dummy field/consent rows for a cached form definition, in the
	 * same shape build_email_body() expects. Used by the "Send test" tool.
	 *
	 * @param array $def Cached form definition.
	 * @return array [ 'fields' => array, 'consents' => array ]
	 */
	public static function dummy_submission( $def ) {
		$fields   = array();
		$consents = array();

		$def_fields = ! empty( $def['fields'] ) ? $def['fields'] : array();
		foreach ( $def_fields as $field ) {
			// Skip inline HTML/disclaimer blocks — they aren't inputs.
			if ( 'html' === $field['type'] || empty( $field['name'] ) ) {
				continue;
			}
			$fields[] = array(
				'name'  => $field['name'],
				'label' => wp_strip_all_tags( $field['label'] ),
				'type'  => $field['type'],
				'value' => self::dummy_value( $field ),
			);
		}

		// If the form had no cached fields, provide a generic sample set.
		if ( empty( $fields ) ) {
			$fields = array(
				array( 'name' => 'firstname', 'label' => 'First name', 'type' => 'text', 'value' => 'Jane' ),
				array( 'name' => 'lastname', 'label' => 'Last name', 'type' => 'text', 'value' => 'Doe' ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'jane.doe@example.com' ),
				array( 'name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'value' => 'This is a test submission.' ),
			);
		}

		if ( ! empty( $def['consents'] ) ) {
			foreach ( $def['consents'] as $consent ) {
				$consents[] = array(
					'label'   => wp_strip_all_tags( $consent['label'] ),
					'checked' => true,
				);
			}
		}

		return array(
			'fields'   => $fields,
			'consents' => $consents,
		);
	}

	/**
	 * Produce a plausible dummy value for a single field definition.
	 *
	 * @param array $field
	 * @return string
	 */
	protected static function dummy_value( $field ) {
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$name    = isset( $field['name'] ) ? strtolower( $field['name'] ) : '';
		$options = ! empty( $field['options'] ) ? $field['options'] : array();

		switch ( $type ) {
			case 'email':
				return 'jane.doe@example.com';
			case 'tel':
				return '+1 (555) 123-4567';
			case 'number':
				return '42';
			case 'date':
				return gmdate( 'Y-m-d' );
			case 'textarea':
				return 'This is a test message submitted by the HubSpot Fallback Forms test tool.';
			case 'select':
			case 'radio':
				return ! empty( $options[0]['label'] ) ? $options[0]['label'] : 'Sample option';
			case 'checkbox':
				$labels = array();
				foreach ( array_slice( $options, 0, 2 ) as $opt ) {
					$labels[] = $opt['label'];
				}
				return $labels ? implode( ', ', $labels ) : 'Sample choice';
			case 'booleancheckbox':
				return 'Yes';
			default:
				// Name-based sensible defaults for common HubSpot fields.
				$map = array(
					'firstname'   => 'Jane',
					'lastname'    => 'Doe',
					'fullname'    => 'Jane Doe',
					'name'        => 'Jane Doe',
					'company'     => 'Acme Inc.',
					'jobtitle'    => 'Marketing Manager',
					'website'     => 'https://example.com',
					'city'        => 'Springfield',
					'state'       => 'IL',
					'zip'         => '62701',
					'country'     => 'United States',
					'address'     => '123 Main St',
				);
				return isset( $map[ $name ] ) ? $map[ $name ] : 'Sample text';
		}
	}

	/**
	 * Build the HTML email body listing all fields and consent answers.
	 *
	 * @param array  $fields
	 * @param array  $consents
	 * @param string $form_id
	 * @param array  $def
	 * @param string $page_url
	 * @return string
	 */
	public static function build_email_body( $fields, $consents, $form_id, $def, $page_url ) {
		$form_name = ! empty( $def['name'] ) ? $def['name'] : $form_id;

		ob_start();
		?>
		<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;">
			<h2 style="margin:0 0 4px;">New form submission</h2>
			<p style="margin:0 0 16px;color:#555;">
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?> &mdash;
				<?php echo esc_html( $form_name ); ?>
			</p>
			<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;">
				<?php foreach ( $fields as $row ) : ?>
					<tr>
						<td style="border:1px solid #e2e2e2;background:#f7f7f7;font-weight:bold;vertical-align:top;width:35%;">
							<?php echo esc_html( $row['label'] ); ?>
						</td>
						<td style="border:1px solid #e2e2e2;vertical-align:top;">
							<?php echo nl2br( esc_html( $row['value'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>

				<?php foreach ( $consents as $c ) : ?>
					<tr>
						<td style="border:1px solid #e2e2e2;background:#f7f7f7;font-weight:bold;vertical-align:top;">
							<?php echo esc_html__( 'Consent', 'hubspot-fallback-forms' ); ?>
						</td>
						<td style="border:1px solid #e2e2e2;vertical-align:top;">
							<?php echo $c['checked'] ? '&#10003; ' : '&#10007; '; ?>
							<?php echo esc_html( $c['label'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<p style="margin:16px 0 0;color:#888;font-size:12px;">
				<?php if ( $page_url ) : ?>
					<?php echo esc_html__( 'Submitted from:', 'hubspot-fallback-forms' ); ?>
					<a href="<?php echo esc_url( $page_url ); ?>"><?php echo esc_html( $page_url ); ?></a><br />
				<?php endif; ?>
				<?php echo esc_html__( 'HubSpot form ID:', 'hubspot-fallback-forms' ); ?> <?php echo esc_html( $form_id ); ?><br />
				<?php
				printf(
					/* translators: %s: date/time */
					esc_html__( 'Received: %s', 'hubspot-fallback-forms' ),
					esc_html( current_time( 'Y-m-d H:i:s' ) )
				);
				?>
			</p>
			<p style="margin:8px 0 0;color:#aaa;font-size:11px;">
				<?php echo esc_html__( 'Sent via HubSpot Fallback Forms (HubSpot embed was bypassed).', 'hubspot-fallback-forms' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
