<?php
/**
 * SerpAPI sync engine.
 * Fetches public Google Maps place data by Place ID and maps to ACF fields.
 * Used when GBP API access is pending or unavailable.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Serp_Sync {

	private const API_URL = 'https://serpapi.com/search.json';

	private string $api_key;

	public function __construct() {
		$this->api_key = get_option( 'gbp_sync_serpapi_key', '' );
	}

	public function is_configured(): bool {
		return ! empty( $this->api_key );
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Sync all location posts that have a Place ID set.
	 */
	public function sync_all(): array {
		$results = [ 'synced' => 0, 'created' => 0, 'errors' => [], 'skipped' => 0 ];

		$posts = get_posts( [
			'post_type'      => GBP_SYNC_POST_TYPE,
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => 'loc_place_id',
				'value'   => '',
				'compare' => '!=',
			] ],
		] );

		if ( empty( $posts ) ) {
			$results['errors'][] = 'No location posts have a Google Place ID set. Edit each location and add its Place ID.';
			return $results;
		}

		foreach ( $posts as $post ) {
			$place_id = get_field( 'loc_place_id', $post->ID );
			if ( ! $place_id ) {
				$results['skipped']++;
				continue;
			}

			$err = $this->sync_post( $post->ID, $place_id );
			if ( $err ) {
				$results['errors'][] = $post->post_title . ': ' . $err;
			} else {
				$results['synced']++;
			}
		}

		update_option( 'gbp_sync_last_run', current_time( 'mysql' ) );
		return $results;
	}

	/**
	 * Compare data/locations.json against existing WP posts and return new vs. already-imported.
	 */
	public function search_chain_locations(): array {
		$json_file = GBP_SYNC_DIR . 'data/locations.json';

		if ( ! file_exists( $json_file ) ) {
			return [ 'error' => 'data/locations.json not found in plugin directory.' ];
		}

		$all = json_decode( file_get_contents( $json_file ), true );

		if ( ! is_array( $all ) ) {
			return [ 'error' => 'Could not parse locations.json.' ];
		}

		$existing = $this->get_existing_place_ids();
		$new      = [];
		$already  = [];

		foreach ( $all as $loc ) {
			$pid = $loc['place_id'] ?? '';
			if ( ! $pid ) {
				continue;
			}
			$entry = [
				'place_id' => $pid,
				'title'    => $loc['name']    ?? '',
				'address'  => trim( ( $loc['address'] ?? '' ) . ', ' . ( $loc['city'] ?? '' ) . ', ' . ( $loc['state'] ?? '' ), ', ' ),
			];
			if ( isset( $existing[ $pid ] ) ) {
				$entry['post_id'] = $existing[ $pid ];
				$already[]        = $entry;
			} else {
				$new[] = $entry;
			}
		}

		return [
			'new'     => $new,
			'already' => $already,
			'total'   => count( $all ),
		];
	}

	private function get_existing_place_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = 'loc_place_id' AND meta_value != ''",
			ARRAY_A
		);
		$map = [];
		foreach ( $rows as $row ) {
			$map[ $row['meta_value'] ] = (int) $row['post_id'];
		}
		return $map;
	}

	/**
	 * Sync a single location post by WP post ID.
	 */
	public function sync_single( int $post_id ): array {
		$results  = [ 'synced' => 0, 'created' => 0, 'errors' => [], 'skipped' => 0 ];
		$place_id = get_field( 'loc_place_id', $post_id );

		if ( ! $place_id ) {
			$results['errors'][] = 'No Google Place ID set on this location. Edit the location post and add it under the Profile tab.';
			return $results;
		}

		$err = $this->sync_post( $post_id, $place_id );
		if ( $err ) {
			$results['errors'][] = $err;
		} else {
			$results['synced'] = 1;
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Core
	// -------------------------------------------------------------------------

	private function sync_post( int $post_id, string $place_id ): ?string {
		$place = $this->get_place( $place_id );

		if ( empty( $place ) ) {
			return 'SerpAPI returned no data. Check Place ID and API key.';
		}

		if ( isset( $place['error'] ) ) {
			return 'SerpAPI error: ' . $place['error'];
		}

		// Store full response for admin debug view (5 min TTL).
		set_transient( 'gbp_serp_debug_' . $post_id, [
			'hours'   => $place['hours'] ?? $place['operating_hours'] ?? 'NOT IN RESPONSE',
			'keys'    => array_keys( $place ),
		], 300 );

		$this->map_to_acf( $post_id, $place );
		return null;
	}

	// -------------------------------------------------------------------------
	// API
	// -------------------------------------------------------------------------

	private function get_place( string $place_id ): array {
		$url = add_query_arg( [
			'engine'   => 'google_maps',
			'place_id' => $place_id,
			'api_key'  => $this->api_key,
		], self::API_URL );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			error_log( 'GBP SerpSync error: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code !== 200 ) {
			error_log( 'GBP SerpSync HTTP ' . $code . ': ' . wp_json_encode( $body ) );
			return [ 'error' => 'HTTP ' . $code . ' — ' . ( $body['error'] ?? 'Unknown' ) ];
		}

		return $body['place_results'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Field mapping
	// -------------------------------------------------------------------------

	private function map_to_acf( int $post_id, array $place ): void {
		// Profile.
		update_field( 'loc_name',    $place['title']   ?? '', $post_id );
		update_field( 'loc_phone',   $place['phone']   ?? '', $post_id );
		update_field( 'loc_website', $place['website'] ?? '', $post_id );

		// Email — only set default if not already populated.
		if ( ! get_field( 'loc_email', $post_id ) && ! empty( $place['title'] ) ) {
			$slug = strtolower( preg_replace( '/[^a-z0-9]/i', '', $place['title'] ) );
			update_field( 'loc_email', $slug . '@emergencydentalofamerica.com', $post_id );
		}

		// Address — parse flat string "Street, City, ST ZIP, Country".
		$this->parse_address( $post_id, $place['address'] ?? '' );

		// Coordinates.
		$gps = $place['gps_coordinates'] ?? [];
		if ( $gps ) {
			update_field( 'loc_lat', $gps['latitude']  ?? '', $post_id );
			update_field( 'loc_lng', $gps['longitude'] ?? '', $post_id );
		}

		// Google Maps URL + embed iframe.
		$pid = $place['place_id'] ?? '';
		if ( $pid ) {
			update_field( 'loc_maps_url', 'https://www.google.com/maps/place/?q=place_id:' . $pid, $post_id );

			$embed_key = get_option( 'gbp_sync_maps_embed_key', '' );
			if ( $embed_key ) {
				$src = 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode( $embed_key ) . '&q=place_id:' . rawurlencode( $pid );
			} else {
				// Keyless fallback — uses lat/lng if available, otherwise place_id search.
				$gps = $place['gps_coordinates'] ?? [];
				$src = $gps
					? 'https://maps.google.com/maps?q=' . $gps['latitude'] . ',' . $gps['longitude'] . '&z=15&output=embed'
					: 'https://maps.google.com/maps?q=place_id:' . rawurlencode( $pid ) . '&output=embed';
			}
			$iframe = '<iframe src="' . esc_attr( $src ) . '" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
			update_field( 'loc_maps_embed', $iframe, $post_id );
		}

		// Status — temp closure is the key use case.
		$temp_closed = (bool) ( $place['temporarily_closed'] ?? false );
		// Also check open_state text as fallback.
		if ( ! $temp_closed && isset( $place['open_state'] ) ) {
			$temp_closed = stripos( $place['open_state'], 'temporarily' ) !== false;
		}
		update_field( 'loc_temp_closed', $temp_closed ? 1 : 0, $post_id );
		update_field( 'loc_status', $temp_closed ? 'CLOSED_TEMPORARILY' : 'OPEN', $post_id );

		// Hours — handle multiple SerpAPI response structures.
		$hours_raw = $place['hours'] ?? $place['operating_hours'] ?? [];
		if ( is_array( $hours_raw ) ) {
			$first     = reset( $hours_raw );
			$timetable = $hours_raw['timetable'] ?? null;
			if ( $timetable ) {
				// Structure A: hours.timetable.{day} = [{open,close}]
				$this->map_hours( $post_id, $timetable );
			} elseif ( isset( $hours_raw['monday'] ) || isset( $hours_raw['sunday'] ) ) {
				// Structure B: hours.{day} directly
				$this->map_hours( $post_id, $hours_raw );
			} elseif ( is_array( $first ) && ! empty( $first ) ) {
				// Structure C (actual SerpAPI): [{sunday:"9 AM–9 PM"},{monday:"10 AM–8 PM"},...]
				$this->map_hours_from_keyed_objects( $post_id, $hours_raw );
			} elseif ( is_string( $first ) ) {
				// Structure D: ["Monday: 8am-6pm", ...]
				$this->map_hours_from_strings( $post_id, $hours_raw );
			}
		}

		// Reviews.
		if ( isset( $place['rating'] ) ) {
			update_field( 'loc_rating', round( (float) $place['rating'], 1 ), $post_id );
		}
		if ( isset( $place['reviews'] ) ) {
			update_field( 'loc_review_count', (int) $place['reviews'], $post_id );
		}

		// Sync meta.
		update_field( 'gbp_last_synced', current_time( 'Y-m-d H:i:s' ), $post_id );

		// Keep post title in sync.
		if ( ! empty( $place['title'] ) && get_the_title( $post_id ) !== $place['title'] ) {
			wp_update_post( [ 'ID' => $post_id, 'post_title' => $place['title'] ] );
		}
	}

	/**
	 * Parse "123 Main St, Buffalo, NY 14225" (or with trailing "United States") into ACF address fields.
	 */
	private function parse_address( int $post_id, string $address ): void {
		if ( ! $address ) {
			return;
		}

		// Store raw string so admin debug can show it.
		set_transient( 'gbp_serp_debug_address_' . $post_id, $address, 300 );

		$parts = array_map( 'trim', explode( ',', $address ) );

		if ( count( $parts ) < 2 ) {
			update_field( 'loc_address_1', $address, $post_id );
			return;
		}

		// Drop trailing country name if present ("United States", "USA", "US").
		if ( preg_match( '/^(United States|USA|US)$/i', end( $parts ) ) ) {
			array_pop( $parts );
		}

		if ( count( $parts ) < 2 ) {
			update_field( 'loc_address_1', implode( ', ', $parts ), $post_id );
			return;
		}

		// Last part = "ST ZIP" e.g. "NY 14225" or "OH 43205-1234".
		$state_zip = array_pop( $parts );
		preg_match( '/^([A-Za-z]{2})\s+([\d-]+)$/', trim( $state_zip ), $m );
		update_field( 'loc_state', isset( $m[1] ) ? strtoupper( $m[1] ) : '', $post_id );
		update_field( 'loc_zip',   $m[2] ?? '', $post_id );

		// New last part = city.
		$city = array_pop( $parts );
		update_field( 'loc_city', $city, $post_id );

		// Remaining = street address (1-2 parts for suite lines).
		update_field( 'loc_address_1', implode( ', ', array_slice( $parts, 0, 1 ) ), $post_id );
		update_field( 'loc_address_2', implode( ', ', array_slice( $parts, 1 ) ),    $post_id );
	}

	/**
	 * Map SerpAPI timetable to ACF loc_hours repeater.
	 * Handles: {day: [{open,close}]} and {day: ["8am-6pm"]} formats.
	 */
	private function map_hours( int $post_id, array $timetable ): void {
		$day_map = [
			'monday'    => 'MONDAY',
			'tuesday'   => 'TUESDAY',
			'wednesday' => 'WEDNESDAY',
			'thursday'  => 'THURSDAY',
			'friday'    => 'FRIDAY',
			'saturday'  => 'SATURDAY',
			'sunday'    => 'SUNDAY',
		];

		$rows = [];
		foreach ( $day_map as $serp_day => $acf_day ) {
			$periods = $timetable[ $serp_day ] ?? null;

			if ( null === $periods ) {
				$rows[] = [
					'day'        => $acf_day,
					'open_time'  => '',
					'close_time' => '',
					'is_closed'  => 1,
				];
				continue;
			}

			foreach ( (array) $periods as $period ) {
				if ( is_array( $period ) ) {
					// Format: {"open": "8:00 AM", "close": "6:00 PM"}
					$rows[] = [
						'day'        => $acf_day,
						'open_time'  => $period['open']  ?? $period['opens']  ?? '',
						'close_time' => $period['close'] ?? $period['closes'] ?? '',
						'is_closed'  => 0,
					];
				} elseif ( is_string( $period ) ) {
					// Format: "8:00 AM–6:00 PM" or "8:00 AM - 6:00 PM" or "Closed"
					if ( stripos( $period, 'closed' ) !== false ) {
						$rows[] = [ 'day' => $acf_day, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
					} else {
						[ $open, $close ] = $this->split_time_range( $period );
						$rows[] = [ 'day' => $acf_day, 'open_time' => $open, 'close_time' => $close, 'is_closed' => 0 ];
					}
				}
			}
		}

		update_field( 'loc_hours', $rows, $post_id );
	}

	/**
	 * Parse hours from ["Monday: 8:00 AM–6:00 PM", ...] string array.
	 */
	private function map_hours_from_strings( int $post_id, array $strings ): void {
		$day_map = [
			'monday'    => 'MONDAY', 'tuesday'  => 'TUESDAY',
			'wednesday' => 'WEDNESDAY', 'thursday' => 'THURSDAY',
			'friday'    => 'FRIDAY', 'saturday'  => 'SATURDAY', 'sunday' => 'SUNDAY',
		];

		$rows = [];
		foreach ( $strings as $line ) {
			if ( ! preg_match( '/^(\w+):\s*(.+)$/i', $line, $m ) ) {
				continue;
			}
			$day_key = strtolower( $m[1] );
			$acf_day = $day_map[ $day_key ] ?? null;
			if ( ! $acf_day ) continue;

			$range = trim( $m[2] );
			if ( stripos( $range, 'closed' ) !== false ) {
				$rows[] = [ 'day' => $acf_day, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
			} else {
				[ $open, $close ] = $this->split_time_range( $range );
				$rows[] = [ 'day' => $acf_day, 'open_time' => $open, 'close_time' => $close, 'is_closed' => 0 ];
			}
		}

		if ( $rows ) {
			update_field( 'loc_hours', $rows, $post_id );
		}
	}

	/**
	 * Handle actual SerpAPI format: [{sunday:"9 AM–9 PM"},{monday:"10 AM–8 PM"},...]
	 * Each element is a single-key object where key=day, value=time range string.
	 */
	private function map_hours_from_keyed_objects( int $post_id, array $items ): void {
		$day_map = [
			'monday'    => 'MONDAY', 'tuesday'   => 'TUESDAY',
			'wednesday' => 'WEDNESDAY', 'thursday' => 'THURSDAY',
			'friday'    => 'FRIDAY', 'saturday'  => 'SATURDAY', 'sunday' => 'SUNDAY',
		];

		// Flatten [{day:range}, ...] into [day => range].
		$by_day = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item as $day => $range ) {
				$by_day[ strtolower( $day ) ] = $range;
			}
		}

		$rows = [];
		foreach ( $day_map as $serp_day => $acf_day ) {
			$range = $by_day[ $serp_day ] ?? null;
			if ( null === $range ) {
				$rows[] = [ 'day' => $acf_day, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
				continue;
			}
			if ( stripos( (string) $range, 'closed' ) !== false ) {
				$rows[] = [ 'day' => $acf_day, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
			} else {
				[ $open, $close ] = $this->split_time_range( (string) $range );
				$rows[] = [ 'day' => $acf_day, 'open_time' => $open, 'close_time' => $close, 'is_closed' => 0 ];
			}
		}

		if ( $rows ) {
			update_field( 'loc_hours', $rows, $post_id );
		}
	}

	/**
	 * Split "8:00 AM–6:00 PM" or "8:00 AM - 6:00 PM" into [open, close].
	 * /u flag required — en-dash is U+2013 (multi-byte UTF-8); without it the byte
	 * split leaves garbage in the close side and ACF saves an empty string.
	 */
	private function split_time_range( string $range ): array {
		$parts = preg_split( '/\s*[–—\-]\s*/u', $range, 2 );
		return [ trim( $parts[0] ?? '' ), trim( $parts[1] ?? '' ) ];
	}
}
