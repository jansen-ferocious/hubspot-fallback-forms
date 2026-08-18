<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans rendered front-end HTML for HubSpot form embeds and swaps in the
 * self-hosted fallback form when the master toggle is on and a cached
 * definition exists for the embed's formId.
 */
class HFF_Replacer {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 1 );
	}

	/**
	 * Start output buffering on eligible front-end requests.
	 */
	public function maybe_start_buffer() {
		$settings = hff_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}

		ob_start( array( $this, 'filter_html' ) );
	}

	/**
	 * Output-buffer callback: replace HubSpot embed scripts with fallback forms.
	 *
	 * @param string $html
	 * @return string
	 */
	public function filter_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, 'hbspt.forms.create' ) ) {
			return $html;
		}

		$forms = hff_get_forms();

		// Whitespace class that also tolerates the non-breaking space (\x{00A0}),
		// zero-width space (\x{200B}) and BOM (\x{FEFF}) that some page builders
		// (e.g. Cornerstone/X) inject into embed markup. PHP's plain \s only
		// matches ASCII whitespace unless the /u flag is used, so an injected
		// nbsp would otherwise stop the pattern from matching the embed.
		$ws = '[\s\x{00A0}\x{200B}\x{FEFF}]';

		// Match each inline script block that calls hbspt.forms.create({...}).
		$pattern = '#<script\b[^>]*>' . $ws . '*hbspt\.forms\.create' . $ws . '*\(' . $ws . '*\{.*?\}' . $ws . '*\)' . $ws . '*;?' . $ws . '*</script>#isu';

		$result = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $forms, $ws ) {
				$block = $matches[0];

				// Extract the formId from the create() call.
				if ( ! preg_match( '#formId' . $ws . '*:' . $ws . '*["\']([^"\']+)["\']#iu', $block, $m ) ) {
					return $block; // Can't identify it — leave untouched.
				}
				$form_id = $m[1];

				// No cached definition — leave the original embed so nothing breaks.
				if ( empty( $forms[ $form_id ] ) || empty( $forms[ $form_id ]['fields'] ) ) {
					return $block;
				}

				// Never let a render error blank the whole page (this runs inside
				// the output-buffer callback): fall back to the original embed.
				try {
					return HFF_Renderer::render( $form_id, $forms[ $form_id ] );
				} catch ( \Throwable $e ) {
					return $block;
				}
			},
			$html
		);

		// If PCRE fails (e.g. backtrack limit on a huge page), keep the original
		// HTML rather than blanking the page.
		return ( null === $result || PREG_NO_ERROR !== preg_last_error() ) ? $html : $result;
	}
}
