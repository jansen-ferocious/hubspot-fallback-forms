<?php
/**
 * Plugin Name:       HubSpot Fallback Forms
 * Plugin URI:        https://ferociousmedia.com/
 * Description:        Replaces embedded HubSpot forms with self-hosted HTML forms that email submissions via the Mailgun API. A safety net for when HubSpot's form embeds are unavailable.
 * Version:           1.0.0
 * Author:            Ferocious Media
 * License:           GPL-2.0-or-later
 * Text Domain:       hubspot-fallback-forms
 * Update URI:        https://github.com/jansen-ferocious/hubspot-fallback-forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'HFF_VERSION', '1.0.0' );
define( 'HFF_FILE', __FILE__ );
define( 'HFF_DIR', plugin_dir_path( __FILE__ ) );
define( 'HFF_URL', plugin_dir_url( __FILE__ ) );

// Option keys.
define( 'HFF_OPT_SETTINGS', 'hff_settings' );   // General + Mailgun API settings.
define( 'HFF_OPT_FORMS', 'hff_forms' );         // Cached, normalized form definitions keyed by formId.

require_once HFF_DIR . 'includes/class-hff-hubspot-sync.php';
require_once HFF_DIR . 'includes/class-hff-renderer.php';
require_once HFF_DIR . 'includes/class-hff-replacer.php';
require_once HFF_DIR . 'includes/class-hff-mailer.php';
require_once HFF_DIR . 'includes/class-hff-submission.php';
require_once HFF_DIR . 'includes/class-hff-settings.php';

/**
 * Wire up automatic updates from the GitHub repository via the bundled
 * Plugin Update Checker library. Bumping the "Version" header above and
 * pushing to the "main" branch makes every install show an available update.
 */
function hff_init_updater() {
	$puc = HFF_DIR . 'plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $puc ) ) {
		return;
	}
	require_once $puc;

	if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/jansen-ferocious/hubspot-fallback-forms/',
		HFF_FILE,
		'hubspot-fallback-forms'
	);

	// Track the main branch; the version is read from the plugin header there.
	$checker->setBranch( 'main' );

	// Optional auth for a private repo (unused while the repo is public).
	// Define HFF_GITHUB_TOKEN in wp-config.php, or filter 'hff_github_token'.
	$token = defined( 'HFF_GITHUB_TOKEN' ) ? HFF_GITHUB_TOKEN : '';
	$token = apply_filters( 'hff_github_token', $token );
	if ( $token ) {
		$checker->setAuthentication( $token );
	}
}
hff_init_updater();

/**
 * Return plugin settings merged with defaults.
 *
 * @return array
 */
function hff_get_settings() {
	$defaults = array(
		'enabled'          => 0,
		'sync_styles'      => 1,
		'recipients'       => '',
		'portal_id'        => '',
		'region'           => 'na1',
		'mg_api_key'       => '',
		'mg_domain'        => '',
		'mg_region'        => 'us', // us | eu
		'from_name'        => get_bloginfo( 'name' ),
		'from_email'       => '',
	);

	$saved = get_option( HFF_OPT_SETTINGS, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( $defaults, $saved );
}

/**
 * Return all cached, normalized form definitions keyed by formId.
 *
 * @return array
 */
function hff_get_forms() {
	$forms = get_option( HFF_OPT_FORMS, array() );
	return is_array( $forms ) ? $forms : array();
}

/**
 * Boot the plugin.
 */
function hff_init() {
	// Admin settings page + AJAX sync handler.
	$settings = new HFF_Settings();
	$settings->hooks();

	// Front-end form submission handler (admin-ajax).
	$submission = new HFF_Submission();
	$submission->hooks();

	// Output-buffer replacement of HubSpot embeds.
	$replacer = new HFF_Replacer();
	$replacer->hooks();
}
add_action( 'plugins_loaded', 'hff_init' );

/**
 * Activation: seed default option so the settings page renders cleanly.
 */
function hff_activate() {
	if ( false === get_option( HFF_OPT_SETTINGS, false ) ) {
		add_option( HFF_OPT_SETTINGS, array() );
	}
	if ( false === get_option( HFF_OPT_FORMS, false ) ) {
		add_option( HFF_OPT_FORMS, array() );
	}
}
register_activation_hook( __FILE__, 'hff_activate' );
