<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a normalized form definition as an accessible HTML form.
 */
class HFF_Renderer {

	/**
	 * Whether to emit HubSpot class names + theme styling for the current render.
	 *
	 * @var bool
	 */
	protected static $hs = false;

	/**
	 * Render the fallback form for a given formId.
	 *
	 * @param string $form_id
	 * @param array  $def Normalized form definition.
	 * @return string HTML.
	 */
	public static function render( $form_id, $def ) {
		$fields   = isset( $def['fields'] ) ? $def['fields'] : array();
		$consents = isset( $def['consents'] ) ? $def['consents'] : array();
		$legal    = isset( $def['legalText'] ) ? $def['legalText'] : array();
		$submit   = ! empty( $def['submitText'] ) ? $def['submitText'] : 'Submit';

		$settings  = hff_get_settings();
		self::$hs  = ! empty( $settings['sync_styles'] );

		$uid   = 'hff-' . substr( md5( $form_id . wp_rand() ), 0, 8 );
		$nonce = wp_create_nonce( 'hff_submit' );

		// Form classes: mirror HubSpot's so the site theme's form CSS applies.
		$form_class = 'hff-form';
		if ( self::$hs ) {
			$css_class  = ! empty( $def['cssClass'] ) ? $def['cssClass'] : 'hs-form stacked';
			$form_class = trim( $css_class . ' hff-form' );
		}

		// Scoped theme CSS generated from the form's synced style tokens.
		$theme_css = self::$hs && ! empty( $def['style'] ) ? self::build_theme_css( $uid, $def['style'] ) : '';

		// NOTE: This runs inside the output-buffer callback, where ob_start() is
		// forbidden by PHP. Build the markup by string concatenation only.
		$html  = $theme_css;
		$html .= '<div class="hff-form-wrap" data-hff-form-id="' . esc_attr( $form_id ) . '">';
		$html .= '<form class="' . esc_attr( $form_class ) . '" id="' . esc_attr( $uid ) . '" method="post" novalidate>';
		$html .= '<input type="hidden" name="action" value="hff_submit" />';
		$html .= '<input type="hidden" name="hff_form_id" value="' . esc_attr( $form_id ) . '" />';
		$html .= '<input type="hidden" name="hff_nonce" value="' . esc_attr( $nonce ) . '" />';
		$html .= '<input type="hidden" name="hff_page_url" value="' . esc_attr( home_url( add_query_arg( array() ) ) ) . '" />';

		// Honeypot: bots fill this; humans never see it.
		$html .= '<div class="hff-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">';
		$html .= '<label>Leave this field empty<input type="text" name="hff_hp" tabindex="-1" autocomplete="off" /></label></div>';

		// Render fields, wrapping each group of visible fields in a responsive
		// column row (mirrors HubSpot's form-columns-N layout).
		$open_row = null;
		foreach ( $fields as $field ) {
			$is_html   = isset( $field['type'] ) && 'html' === $field['type'];
			$is_hidden = ! empty( $field['hidden'] );
			$row       = isset( $field['row'] ) ? $field['row'] : null;

			// HTML blocks and hidden inputs are full width / layout-neutral:
			// close any open row first.
			if ( $is_html || $is_hidden || null === $row ) {
				if ( null !== $open_row ) {
					$html    .= '</div>';
					$open_row = null;
				}
				$html .= self::render_field( $field );
				continue;
			}

			// Start a new column row when the row index changes.
			if ( $row !== $open_row ) {
				if ( null !== $open_row ) {
					$html .= '</div>';
				}
				$cols     = ! empty( $field['cols'] ) ? (int) $field['cols'] : 1;
				$html    .= '<div class="hff-columns hff-columns-' . esc_attr( $cols ) . ' form-columns-' . esc_attr( $cols ) . '">';
				$open_row = $row;
			}

			$html .= self::render_field( $field );
		}
		if ( null !== $open_row ) {
			$html .= '</div>';
		}

		if ( ! empty( $legal ) ) {
			$html .= '<div class="hff-legal">';
			foreach ( $legal as $text ) {
				$html .= '<div class="hff-legal-text">' . wp_kses_post( $text ) . '</div>';
			}
			$html .= '</div>';
		}

		foreach ( $consents as $consent ) {
			$req    = ! empty( $consent['required'] );
			$html  .= '<div class="hff-field hff-consent"><label class="hff-checkbox-label">';
			$html  .= '<input type="checkbox" name="' . esc_attr( $consent['name'] ) . '" value="1"' . ( $req ? ' required' : '' ) . ' />';
			$html  .= '<span>' . wp_kses_post( $consent['label'] ) . ( $req ? ' <span class="hff-req">*</span>' : '' ) . '</span>';
			$html  .= '</label></div>';
		}

		$html .= '<div class="hff-actions' . ( self::$hs ? ' hs-submit' : '' ) . '">';
		$html .= '<button type="submit" class="hff-submit' . ( self::$hs ? ' hs-button primary large' : '' ) . '">' . esc_html( $submit ) . '</button>';
		$html .= '<span class="hff-spinner" aria-hidden="true"></span></div>';
		$html .= '<div class="hff-message" role="status" aria-live="polite"></div>';
		$html .= '</form></div>';

		return $html;
	}

	/**
	 * Render a single field row.
	 *
	 * @param array $field
	 * @return string
	 */
	protected static function render_field( $field ) {
		$name        = isset( $field['name'] ) ? $field['name'] : '';
		$label       = isset( $field['label'] ) ? $field['label'] : $name;
		$type        = isset( $field['type'] ) ? $field['type'] : 'text';
		$required    = ! empty( $field['required'] );
		$hidden      = ! empty( $field['hidden'] );
		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$default     = isset( $field['default'] ) ? $field['default'] : '';
		$options     = isset( $field['options'] ) ? $field['options'] : array();

		// Inline richText / HTML disclaimer blocks carry no field name.
		if ( 'html' === $type ) {
			$html = isset( $field['html'] ) ? $field['html'] : '';
			return '' === trim( $html ) ? '' : '<div class="hff-richtext">' . wp_kses_post( $html ) . '</div>';
		}

		if ( '' === $name ) {
			return '';
		}

		$input_name = 'fields[' . $name . ']';
		$id         = 'hff-f-' . sanitize_html_class( $name ) . '-' . substr( md5( $name ), 0, 4 );
		$req_attr   = $required ? ' required' : '';
		$req_star   = $required ? ' <span class="hff-req hs-form-required">*</span>' : '';

		// HubSpot class names so the site theme's form CSS applies to the fallback.
		$wrap_cls    = 'hff-field hff-field-' . $type . ( self::$hs ? ' hs-form-field' : '' );
		$input_class = self::$hs ? ' class="hs-input"' : '';

		// Hidden fields: emit and stop.
		if ( $hidden || 'hidden' === $type ) {
			return sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $input_name ),
				esc_attr( $default )
			);
		}

		// Built by concatenation (no ob_start): this may run inside the
		// output-buffer callback, where ob_start() is forbidden by PHP.
		$out = '<div class="' . esc_attr( $wrap_cls ) . '">';

		switch ( $type ) {
			case 'textarea':
				$out .= sprintf( '<label for="%s">%s%s</label>', esc_attr( $id ), esc_html( $label ), $req_star );
				$out .= sprintf(
					'<textarea id="%s" name="%s" rows="4" placeholder="%s"%s%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $input_name ),
					esc_attr( $placeholder ),
					$input_class,
					$req_attr,
					esc_textarea( $default )
				);
				break;

			case 'select':
				$out .= sprintf( '<label for="%s">%s%s</label>', esc_attr( $id ), esc_html( $label ), $req_star );
				$out .= sprintf( '<select id="%s" name="%s"%s%s>', esc_attr( $id ), esc_attr( $input_name ), $input_class, $req_attr );
				$out .= '<option value="">' . esc_html__( 'Please select', 'hubspot-fallback-forms' ) . '</option>';
				foreach ( $options as $opt ) {
					$out .= sprintf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $opt['value'] ),
						selected( $default, $opt['value'], false ),
						esc_html( $opt['label'] )
					);
				}
				$out .= '</select>';
				break;

			case 'radio':
				$out .= sprintf( '<span class="hff-group-label">%s%s</span>', esc_html( $label ), $req_star );
				$out .= '<div class="hff-options">';
				foreach ( $options as $i => $opt ) {
					$out .= sprintf(
						'<label class="hff-radio-label"><input type="radio" name="%s" value="%s"%s /> <span>%s</span></label>',
						esc_attr( $input_name ),
						esc_attr( $opt['value'] ),
						( $required && 0 === $i ) ? ' required' : '',
						esc_html( $opt['label'] )
					);
				}
				$out .= '</div>';
				break;

			case 'checkbox': // Multiple choice checkboxes -> array submission.
				$out .= sprintf( '<span class="hff-group-label">%s%s</span>', esc_html( $label ), $req_star );
				$out .= '<div class="hff-options">';
				foreach ( $options as $opt ) {
					$out .= sprintf(
						'<label class="hff-checkbox-label"><input type="checkbox" name="%s[]" value="%s" /> <span>%s</span></label>',
						esc_attr( $input_name ),
						esc_attr( $opt['value'] ),
						esc_html( $opt['label'] )
					);
				}
				$out .= '</div>';
				break;

			case 'booleancheckbox': // Single yes/no checkbox; label may contain HTML (e.g. consent text + links).
				$out .= sprintf(
					'<label class="hff-checkbox-label"><input type="checkbox" id="%s" name="%s" value="1"%s /> <span>%s%s</span></label>',
					esc_attr( $id ),
					esc_attr( $input_name ),
					$req_attr,
					wp_kses_post( $label ),
					$req_star
				);
				break;

			case 'email':
			case 'tel':
			case 'number':
			case 'date':
			case 'text':
			default:
				$input_type = in_array( $type, array( 'email', 'tel', 'number', 'date' ), true ) ? $type : 'text';
				$out       .= sprintf( '<label for="%s">%s%s</label>', esc_attr( $id ), esc_html( $label ), $req_star );
				$out       .= sprintf(
					'<input type="%s" id="%s" name="%s" value="%s" placeholder="%s"%s%s />',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $input_name ),
					esc_attr( $default ),
					esc_attr( $placeholder ),
					$input_class,
					$req_attr
				);
				break;
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * Build a scoped <style> block from the form's synced HubSpot theme tokens.
	 * All values are validated against strict whitelists before output.
	 *
	 * @param string $uid   The form element id (used as the CSS scope).
	 * @param array  $style Decoded style-token array.
	 * @return string
	 */
	protected static function build_theme_css( $uid, $style ) {
		if ( empty( $style ) || ! is_array( $style ) ) {
			return '';
		}

		$id    = preg_replace( '/[^a-zA-Z0-9_-]/', '', $uid );
		$sel   = '#' . $id;
		$rules = array();

		// Reset any fixed input height the theme forces (id specificity wins).
		$rules[] = "{$sel} input, {$sel} select, {$sel} textarea, {$sel} .hff-submit{height:auto;}";

		$font = ! empty( $style['fontFamily'] ) ? self::css_font( $style['fontFamily'] ) : '';
		if ( $font ) {
			$rules[] = "{$sel}, {$sel} input, {$sel} select, {$sel} textarea, {$sel} button{font-family:{$font};}";
		}

		$bg = ! empty( $style['backgroundColor'] ) ? self::css_color( $style['backgroundColor'] ) : '';
		if ( $bg ) {
			$rules[] = "{$sel}{background-color:{$bg};}";
		}

		$pad = ! empty( $style['padding'] ) ? self::css_padding( $style['padding'] ) : '';
		if ( '' !== $pad ) {
			$rules[] = "{$sel}{padding:{$pad};}";
		}

		$radius = ! empty( $style['borderRadius'] ) ? self::css_size( $style['borderRadius'] ) : '';
		if ( '' !== $radius ) {
			$rules[] = "{$sel} input, {$sel} select, {$sel} textarea, {$sel} .hff-submit{border-radius:{$radius};}";
		}

		// Labels.
		$label_c = ! empty( $style['labelTextColor'] ) ? self::css_color( $style['labelTextColor'] ) : '';
		$label_s = ! empty( $style['labelTextSize'] ) ? self::css_size( $style['labelTextSize'] ) : '';
		if ( $label_c || '' !== $label_s ) {
			$rules[] = "{$sel} label, {$sel} .hff-group-label{" . ( $label_c ? "color:{$label_c};" : '' ) . ( '' !== $label_s ? "font-size:{$label_s};" : '' ) . '}';
		}

		// Links.
		$link_c = ! empty( $style['linkColor'] ) ? self::css_color( $style['linkColor'] ) : '';
		if ( $link_c ) {
			$rules[] = "{$sel} a{color:{$link_c};}";
		}
		$clicked = ! empty( $style['clickedLinkColor'] ) ? self::css_color( $style['clickedLinkColor'] ) : '';
		if ( $clicked ) {
			$rules[] = "{$sel} a:visited{color:{$clicked};}";
		}

		// Legal / consent text.
		$legal_c = ! empty( $style['legalConsentTextColor'] ) ? self::css_color( $style['legalConsentTextColor'] ) : '';
		$legal_s = ! empty( $style['legalConsentTextSize'] ) ? self::css_size( $style['legalConsentTextSize'] ) : '';
		if ( $legal_c || '' !== $legal_s ) {
			$rules[] = "{$sel} .hff-legal, {$sel} .hff-consent, {$sel} .hff-richtext{" . ( $legal_c ? "color:{$legal_c};" : '' ) . ( '' !== $legal_s ? "font-size:{$legal_s};" : '' ) . '}';
		}

		// Submit button.
		$sub_bg   = ! empty( $style['submitColor'] ) ? self::css_color( $style['submitColor'] ) : '';
		$sub_fg   = ! empty( $style['submitFontColor'] ) ? self::css_color( $style['submitFontColor'] ) : '';
		$sub_size = ! empty( $style['submitSize'] ) ? self::css_size( $style['submitSize'] ) : '';
		if ( $sub_bg || $sub_fg || '' !== $sub_size ) {
			$decl    = ( $sub_bg ? "background-color:{$sub_bg};background:{$sub_bg};" : '' ) . ( $sub_fg ? "color:{$sub_fg};" : '' ) . ( '' !== $sub_size ? "font-size:{$sub_size};" : '' );
			$rules[] = "{$sel} .hff-submit{{$decl}}";
			if ( $sub_bg ) {
				$rules[] = "{$sel} .hff-submit:hover{background-color:{$sub_bg};background:{$sub_bg};opacity:.92;}";
			}
		}

		// Submit alignment (our actions row is flex).
		if ( ! empty( $style['submitAlignment'] ) ) {
			$map = array(
				'left'   => 'flex-start',
				'center' => 'center',
				'right'  => 'flex-end',
			);
			$key = strtolower( (string) $style['submitAlignment'] );
			if ( isset( $map[ $key ] ) ) {
				$rules[] = "{$sel} .hff-actions{justify-content:{$map[ $key ]};text-align:{$key};}";
			}
		}

		if ( empty( $rules ) ) {
			return '';
		}

		// Guard against any stray "<" leaking a tag out of the style block.
		$css = str_replace( '<', '', implode( '', $rules ) );
		return "<style id=\"{$id}-theme\">{$css}</style>";
	}

	/**
	 * Validate a CSS color value (hex, rgb/rgba, or a simple keyword).
	 *
	 * @param mixed $v
	 * @return string Empty string if invalid.
	 */
	protected static function css_color( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( in_array( strtolower( $v ), array( 'transparent', 'inherit', 'initial', 'currentcolor', 'none' ), true ) ) {
			return strtolower( $v );
		}
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v ) ) {
			return $v;
		}
		if ( preg_match( '/^rgba?\(\s*[0-9.,%\s]+\)$/i', $v ) ) {
			return $v;
		}
		if ( preg_match( '/^[a-zA-Z]{3,20}$/', $v ) ) { // Named color, e.g. "white".
			return strtolower( $v );
		}
		return '';
	}

	/**
	 * Validate a single CSS size value (number + unit, or 0).
	 *
	 * @param mixed $v
	 * @return string Empty string if invalid.
	 */
	protected static function css_size( $v ) {
		$v = trim( (string) $v );
		if ( '0' === $v ) {
			return '0';
		}
		if ( preg_match( '/^-?\d+(\.\d+)?(px|em|rem|%|pt|vw|vh)$/', $v ) ) {
			return $v;
		}
		return '';
	}

	/**
	 * Validate a CSS padding shorthand (1–4 size tokens).
	 *
	 * @param mixed $v
	 * @return string Empty string if invalid.
	 */
	protected static function css_padding( $v ) {
		$v     = trim( (string) $v );
		$parts = preg_split( '/\s+/', $v );
		if ( ! $parts || count( $parts ) > 4 ) {
			return '';
		}
		foreach ( $parts as $p ) {
			if ( '' === self::css_size( $p ) ) {
				return '';
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Sanitize a font-family list and append a safe fallback.
	 *
	 * @param mixed $v
	 * @return string Empty string if invalid.
	 */
	protected static function css_font( $v ) {
		$v = preg_replace( '/[^A-Za-z0-9 ,\-]/', '', (string) $v );
		$v = trim( $v );
		if ( '' === $v ) {
			return '';
		}
		$out = array();
		foreach ( explode( ',', $v ) as $family ) {
			$family = trim( $family );
			if ( '' === $family ) {
				continue;
			}
			$out[] = ( false !== strpos( $family, ' ' ) ) ? '"' . $family . '"' : $family;
		}
		if ( empty( $out ) ) {
			return '';
		}
		$out[] = 'sans-serif';
		return implode( ', ', $out );
	}
}
