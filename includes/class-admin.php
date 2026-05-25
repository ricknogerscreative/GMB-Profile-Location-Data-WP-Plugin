<?php
/**
 * Admin settings page — SerpAPI key, sync controls, location table.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Admin {

	private static ?self $instance = null;
	private const MENU_SLUG = 'gbp-location-sync';
	private const NONCE     = 'gbp_sync_nonce';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks(): void {
		add_action( 'admin_menu',            [ $this, 'add_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_gbp_sync_all',          [ $this, 'ajax_sync_all' ] );
		add_action( 'wp_ajax_gbp_sync_one',          [ $this, 'ajax_sync_one' ] );
		add_action( 'wp_ajax_gbp_search_locations',  [ $this, 'ajax_search_locations' ] );
		add_action( 'wp_ajax_gbp_import_location',   [ $this, 'ajax_import_location' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=location',
			'Location Sync Settings',
			'Sync Settings',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		foreach ( [
			'gbp_sync_serpapi_key',
			'gbp_sync_frequency',
			'gbp_sync_maps_embed_key',
		] as $option ) {
			register_setting( 'gbp_sync_settings', $option, [ 'sanitize_callback' => 'sanitize_text_field' ] );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style(  'gbp-sync-admin', GBP_SYNC_URL . 'assets/css/admin.css', [], GBP_SYNC_VERSION );
		wp_enqueue_script( 'gbp-sync-admin', GBP_SYNC_URL . 'assets/js/admin.js', [ 'jquery' ], GBP_SYNC_VERSION, true );
		wp_localize_script( 'gbp-sync-admin', 'gbpSync', [
			'nonce'   => wp_create_nonce( self::NONCE ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function ajax_sync_all(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$results = ( new GBP_Serp_Sync() )->sync_all();
		wp_send_json_success( $results );
	}

	public function ajax_sync_one(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post_id' );
		}

		$results = ( new GBP_Serp_Sync() )->sync_single( $post_id );
		$results['debug_hours']   = get_transient( 'gbp_serp_debug_' . $post_id );
		$results['debug_address'] = get_transient( 'gbp_serp_debug_address_' . $post_id );
		wp_send_json_success( $results );
	}

	public function ajax_search_locations(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$results = ( new GBP_Serp_Sync() )->search_chain_locations();
		if ( isset( $results['error'] ) ) {
			wp_send_json_error( $results['error'] );
		}
		wp_send_json_success( $results );
	}

	public function ajax_import_location(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$place_id = sanitize_text_field( $_POST['place_id'] ?? '' );
		$title    = sanitize_text_field( $_POST['title']    ?? '' );

		if ( ! $place_id || ! $title ) {
			wp_send_json_error( 'Missing place_id or title.' );
		}

		$post_id = wp_insert_post( [
			'post_type'   => GBP_SYNC_POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'draft',
		] );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id->get_error_message() );
		}

		update_field( 'loc_place_id', $place_id, $post_id );

		$sync = ( new GBP_Serp_Sync() )->sync_single( $post_id );

		if ( empty( $sync['errors'] ) ) {
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
		}

		wp_send_json_success( [
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'errors'   => $sync['errors'],
		] );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		$serp      = new GBP_Serp_Sync();
		$connected = $serp->is_configured();
		$last_run  = get_option( 'gbp_sync_last_run', 'Never' );

		include GBP_SYNC_DIR . 'templates/admin-page.php';
	}
}
