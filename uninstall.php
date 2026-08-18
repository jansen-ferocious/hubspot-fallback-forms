<?php
/**
 * Fired when the plugin is uninstalled. Removes stored options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hff_settings' );
delete_option( 'hff_forms' );
