<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all plugin options and transients from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all plugin options.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'catalog_ai_%'
	)
);

// Remove all plugin transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_catalog_ai_%',
		'_transient_timeout_catalog_ai_%'
	)
);

// Remove AI-generated image meta (optional — images stay in media library).
delete_metadata( 'post', 0, '_catalog_ai_generated', '', true );
delete_metadata( 'post', 0, '_catalog_ai_product', '', true );
delete_metadata( 'post', 0, '_catalog_ai_date', '', true );
