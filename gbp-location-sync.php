<?php
/**
 * Plugin Name: GBP Location Sync
 * Plugin URI:  https://emergencydentalofamerica.com
 * Description: Syncs Google Maps place data (NAP, hours, status) to WP location CPT via SerpAPI and ACF.
 * Version:     2.1.0
 * Author:      Nick Rogers
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

define( 'GBP_SYNC_VERSION', '2.1.0' );
define( 'GBP_SYNC_FILE', __FILE__ );
define( 'GBP_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBP_SYNC_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'GBP_SYNC_POST_TYPE' ) ) {
	define( 'GBP_SYNC_POST_TYPE', 'location' );
}

foreach ( [
	'class-location-cpt',
	'class-hours-rules',
	'class-hours-sync',
	'class-serp-sync',
	'class-admin',
] as $file ) {
	require_once GBP_SYNC_DIR . 'includes/' . $file . '.php';
}

/**
 * Strip configured brand prefix from a GBP title to produce a short location name.
 * e.g. "Emergency Dental of Milwaukee" → "Milwaukee" when prefix = "Emergency Dental of "
 */
function gbp_derive_short_title( string $full_title ): string {
	$prefix = trim( get_option( 'gbp_sync_brand_prefix', '' ) );
	if ( ! $prefix ) {
		return $full_title;
	}
	if ( stripos( $full_title, $prefix ) === 0 ) {
		return trim( substr( $full_title, strlen( $prefix ) ) );
	}
	return $full_title;
}

function gbp_sync_boot(): void {
	GBP_Location_CPT::instance();
	GBP_Admin::instance();
}
add_action( 'plugins_loaded', 'gbp_sync_boot' );

register_activation_hook( __FILE__, 'gbp_sync_activate' );

function gbp_sync_activate(): void {
	flush_rewrite_rules();
}

/**
 * One-time cleanup when the plugin version changes.
 *
 * Syncing is manual as of 2.1.0. Without this, the gbp_sync_cron_run event
 * scheduled by earlier versions stays in the options table firing an action
 * nothing handles.
 */
function gbp_sync_maybe_upgrade(): void {
	if ( get_option( 'gbp_sync_version' ) === GBP_SYNC_VERSION ) {
		return;
	}
	wp_clear_scheduled_hook( 'gbp_sync_cron_run' );
	delete_option( 'gbp_sync_frequency' );
	update_option( 'gbp_sync_version', GBP_SYNC_VERSION );
}
add_action( 'plugins_loaded', 'gbp_sync_maybe_upgrade' );
