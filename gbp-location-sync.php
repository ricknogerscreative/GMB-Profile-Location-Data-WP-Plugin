<?php
/**
 * Plugin Name: GBP Location Sync
 * Plugin URI:  https://emergencydentalofamerica.com
 * Description: Syncs Google Maps place data (NAP, hours, status) to WP location CPT via SerpAPI and ACF.
 * Version:     2.0.0
 * Author:      Nick Rogers
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

define( 'GBP_SYNC_VERSION', '2.0.0' );
define( 'GBP_SYNC_FILE', __FILE__ );
define( 'GBP_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBP_SYNC_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'GBP_SYNC_POST_TYPE' ) ) {
	define( 'GBP_SYNC_POST_TYPE', 'location' );
}

foreach ( [
	'class-location-cpt',
	'class-serp-sync',
	'class-admin',
	'class-cron',
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
	GBP_Cron::instance();
}
add_action( 'plugins_loaded', 'gbp_sync_boot' );

register_activation_hook( __FILE__, 'gbp_sync_activate' );
register_deactivation_hook( __FILE__, 'gbp_sync_deactivate' );

function gbp_sync_activate(): void {
	GBP_Cron::schedule();
	flush_rewrite_rules();
}

function gbp_sync_deactivate(): void {
	GBP_Cron::unschedule();
}
