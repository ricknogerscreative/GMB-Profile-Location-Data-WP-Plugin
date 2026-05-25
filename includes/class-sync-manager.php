<?php
/**
 * Sync engine — maps GBP API data to WP CPT posts via ACF.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Sync_Manager {

	private GBP_API $api;
	private array   $results = [];

	public function __construct() {
		$this->api = new GBP_API();
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Sync all locations for the configured account.
	 */
	public function sync_all(): array {
		$this->results = [ 'synced' => 0, 'created' => 0, 'errors' => [], 'skipped' => 0 ];

		$account = get_option( 'gbp_sync_account_name', '' );
		if ( ! $account ) {
			$this->results['errors'][] = 'No GBP account configured.';
			return $this->results;
		}

		$locations = $this->api->get_locations( $account );
		if ( empty( $locations ) ) {
			$this->results['errors'][] = 'No locations returned from GBP API.';
			return $this->results;
		}

		foreach ( $locations as $location ) {
			$this->sync_location( $location );
		}

		update_option( 'gbp_sync_last_run', current_time( 'mysql' ) );
		return $this->results;
	}

	/**
	 * Sync a single location by its GBP resource name.
	 */
	public function sync_single( string $location_name ): array {
		$this->results = [ 'synced' => 0, 'created' => 0, 'errors' => [], 'skipped' => 0 ];
		$location      = $this->api->get_location( $location_name );

		if ( empty( $location ) ) {
			$this->results['errors'][] = 'Could not fetch location: ' . $location_name;
			return $this->results;
		}

		$this->sync_location( $location );
		return $this->results;
	}

	// -------------------------------------------------------------------------
	// Core sync
	// -------------------------------------------------------------------------

	private function sync_location( array $loc ): void {
		$location_name = $loc['name'] ?? '';
		if ( ! $location_name ) {
			$this->results['errors'][] = 'Location missing name field.';
			return;
		}

		$post_id = $this->find_or_create_post( $loc );
		if ( ! $post_id ) {
			$this->results['errors'][] = 'Failed to create/find post for: ' . $location_name;
			return;
		}

		$this->update_acf_fields( $post_id, $loc );

		// Sync reviews separately.
		$sync_reviews = get_option( 'gbp_sync_reviews_enabled', '1' );
		if ( $sync_reviews ) {
			$reviews = $this->api->get_reviews( $location_name, (int) get_option( 'gbp_sync_max_reviews', 50 ) );
			$this->update_reviews( $post_id, $reviews, $loc );
		}

		$this->results['synced']++;
	}

	private function find_or_create_post( array $loc ): int|false {
		$location_name  = $loc['name'];
		$business_name  = $loc['title'] ?? 'Unknown Location';

		// Find existing post by GBP ID meta.
		$existing = get_posts( [
			'post_type'  => GBP_SYNC_POST_TYPE,
			'meta_key'   => 'gbp_location_id',
			'meta_value' => $location_name,
			'fields'     => 'ids',
			'numberposts'=> 1,
		] );

		if ( ! empty( $existing ) ) {
			return $existing[0];
		}

		// Create new post.
		$post_id = wp_insert_post( [
			'post_type'   => GBP_SYNC_POST_TYPE,
			'post_title'  => $business_name,
			'post_status' => 'publish',
		] );

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		update_post_meta( $post_id, 'gbp_location_id', $location_name );
		$this->results['created']++;
		return $post_id;
	}

	private function update_acf_fields( int $post_id, array $loc ): void {
		// Profile.
		update_field( 'loc_name',      $loc['title'] ?? '',                                   $post_id );
		update_field( 'loc_phone',     $loc['phoneNumbers']['primaryPhone'] ?? '',             $post_id );
		update_field( 'loc_phone_alt', $loc['phoneNumbers']['additionalPhones'][0] ?? '',      $post_id );
		update_field( 'loc_website',   $loc['websiteUri'] ?? '',                               $post_id );
		update_field( 'loc_maps_url',  $loc['metadata']['mapsUrl'] ?? '',                      $post_id );

		// Address.
		$addr = $loc['storefrontAddress'] ?? [];
		update_field( 'loc_address_1', $addr['addressLines'][0] ?? '', $post_id );
		update_field( 'loc_address_2', $addr['addressLines'][1] ?? '', $post_id );
		update_field( 'loc_city',      $addr['locality'] ?? '',         $post_id );
		update_field( 'loc_state',     $addr['administrativeArea'] ?? '', $post_id );
		update_field( 'loc_zip',       $addr['postalCode'] ?? '',       $post_id );

		// Coordinates.
		$latlng = $loc['latlng'] ?? [];
		if ( $latlng ) {
			update_field( 'loc_lat', $latlng['latitude'] ?? '',  $post_id );
			update_field( 'loc_lng', $latlng['longitude'] ?? '', $post_id );
		}

		// Status — key field driving closure banners.
		$open_info = $loc['openInfo'] ?? [];
		$status    = $open_info['status'] ?? 'OPEN';
		// Normalise legacy GBP value.
		if ( $status === 'OPEN_FOR_BUSINESS_UNSPECIFIED' ) {
			$status = 'OPEN';
		}
		update_field( 'loc_status',     $status,                                  $post_id );
		update_field( 'loc_temp_closed', $status === 'CLOSED_TEMPORARILY' ? 1 : 0, $post_id );

		// Draft permanently closed locations.
		if ( $status === 'CLOSED_PERMANENTLY' ) {
			wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
		}

		// Hours.
		$this->update_regular_hours( $post_id, $loc['regularHours'] ?? [] );
		$this->update_special_hours( $post_id, $loc['specialHours'] ?? [] );

		// Reviews stats.
		if ( isset( $loc['averageRating'] ) ) {
			update_field( 'loc_rating', round( (float) $loc['averageRating'], 1 ), $post_id );
		}
		if ( isset( $loc['totalReviewCount'] ) ) {
			update_field( 'loc_review_count', (int) $loc['totalReviewCount'], $post_id );
		}

		// GBP sync meta.
		update_field( 'gbp_location_id', $loc['name'] ?? '', $post_id );
		update_field( 'gbp_last_synced', current_time( 'Y-m-d H:i:s' ), $post_id );

		// Keep WP post title in sync.
		if ( ! empty( $loc['title'] ) && get_the_title( $post_id ) !== $loc['title'] ) {
			wp_update_post( [ 'ID' => $post_id, 'post_title' => $loc['title'] ] );
		}
	}

	private function update_regular_hours( int $post_id, array $hours_data ): void {
		if ( empty( $hours_data['periods'] ) ) {
			return;
		}

		$rows = [];
		foreach ( $hours_data['periods'] as $period ) {
			$rows[] = [
				'day'        => $period['openDay'] ?? '',
				'open_time'  => $this->format_time( $period['openTime'] ?? [] ),
				'close_time' => $this->format_time( $period['closeTime'] ?? [] ),
				'is_closed'  => 0,
			];
		}

		update_field( 'loc_hours', $rows, $post_id );
	}

	private function update_special_hours( int $post_id, array $special_data ): void {
		if ( empty( $special_data['specialHourPeriods'] ) ) {
			update_field( 'loc_special_hours', [], $post_id );
			return;
		}

		$rows = [];
		foreach ( $special_data['specialHourPeriods'] as $period ) {
			$date     = $period['startDate'] ?? [];
			$date_str = sprintf( '%04d-%02d-%02d',
				$date['year']  ?? 0,
				$date['month'] ?? 0,
				$date['day']   ?? 0
			);

			$rows[] = [
				'date'       => $date_str,
				'is_closed'  => (bool) ( $period['isClosed'] ?? false ) ? 1 : 0,
				'open_time'  => $this->format_time( $period['openTime'] ?? [] ),
				'close_time' => $this->format_time( $period['closeTime'] ?? [] ),
			];
		}

		update_field( 'loc_special_hours', $rows, $post_id );
	}

	private function update_reviews( int $post_id, array $reviews, array $loc ): void {
		if ( empty( $reviews ) ) {
			return;
		}

		$rows = [];
		foreach ( $reviews as $review ) {
			$rows[] = [
				'review_id'     => $review['reviewId'] ?? '',
				'reviewer_name' => $review['reviewer']['displayName'] ?? 'Anonymous',
				'star_rating'   => $review['starRating'] ?? 'FIVE',
				'comment'       => $review['comment'] ?? '',
				'reply'         => $review['reviewReply']['comment'] ?? '',
				'create_time'   => $review['createTime'] ?? '',
			];
		}

		update_field( 'gbp_reviews', $rows, $post_id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert GBP TimeOfDay object to H:MM format.
	 */
	private function format_time( array $time ): string {
		if ( empty( $time ) ) {
			return '';
		}
		$h   = (int) ( $time['hours'] ?? 0 );
		$m   = (int) ( $time['minutes'] ?? 0 );
		$ampm = $h >= 12 ? 'PM' : 'AM';
		$h12  = $h % 12 ?: 12;
		return sprintf( '%d:%02d %s', $h12, $m, $ampm );
	}

	public function get_api(): GBP_API {
		return $this->api;
	}
}
