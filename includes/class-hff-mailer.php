<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the submission email through the Mailgun HTTP API, independent of
 * wp_mail() / any other mail plugin.
 *
 * Docs: POST https://api.mailgun.net/v3/{domain}/messages
 * Auth: HTTP Basic with username "api" and password = your Mailgun API key.
 */
class HFF_Mailer {

	/**
	 * Send an email via the Mailgun API.
	 *
	 * @param array  $recipients Array of recipient email addresses.
	 * @param string $subject
	 * @param string $body_html
	 * @param string $reply_to   Optional reply-to email.
	 * @param string $reply_name Optional reply-to name.
	 * @return true|WP_Error
	 */
	public static function send( $recipients, $subject, $body_html, $reply_to = '', $reply_name = '' ) {
		$settings = hff_get_settings();

		$api_key = isset( $settings['mg_api_key'] ) ? trim( $settings['mg_api_key'] ) : '';
		$domain  = isset( $settings['mg_domain'] ) ? trim( $settings['mg_domain'] ) : '';
		$region  = isset( $settings['mg_region'] ) && 'eu' === $settings['mg_region'] ? 'eu' : 'us';

		if ( '' === $api_key ) {
			return new WP_Error( 'hff_no_api_key', __( 'No Mailgun API key configured.', 'hubspot-fallback-forms' ) );
		}
		if ( '' === $domain ) {
			return new WP_Error( 'hff_no_domain', __( 'No Mailgun sending domain configured.', 'hubspot-fallback-forms' ) );
		}

		// Validate recipients.
		$valid = array();
		foreach ( (array) $recipients as $to ) {
			$to = trim( $to );
			if ( is_email( $to ) ) {
				$valid[] = $to;
			}
		}
		if ( empty( $valid ) ) {
			return new WP_Error( 'hff_no_recipients', __( 'No valid recipient email addresses.', 'hubspot-fallback-forms' ) );
		}

		// From address (must be on the Mailgun sending domain).
		$from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : 'postmaster@' . $domain;
		$from_name  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' );
		$from       = sprintf( '%s <%s>', $from_name, $from_email );

		$base = 'eu' === $region ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$url  = $base . '/v3/' . rawurlencode( $domain ) . '/messages';

		$body = array(
			'from'    => $from,
			'to'      => implode( ',', $valid ),
			'subject' => $subject,
			'html'    => $body_html,
			'text'    => wp_strip_all_tags( $body_html ),
		);

		if ( $reply_to && is_email( $reply_to ) ) {
			$body['h:Reply-To'] = $reply_name ? sprintf( '%s <%s>', $reply_name, $reply_to ) : $reply_to;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = is_array( $decoded ) && ! empty( $decoded['message'] )
				? $decoded['message']
				: sprintf( __( 'Mailgun returned HTTP %d.', 'hubspot-fallback-forms' ), $code );
			return new WP_Error( 'hff_mailgun_error', sprintf( __( 'Email could not be sent: %s', 'hubspot-fallback-forms' ), $message ), array( 'status' => $code ) );
		}

		return true;
	}
}
