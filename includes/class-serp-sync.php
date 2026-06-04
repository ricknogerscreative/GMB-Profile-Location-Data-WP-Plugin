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
	 * Re-sync only locations whose loc_hours repeater is empty.
	 *
	 * Targets the slow-scrape locations that came back with no hours. The retry
	 * loop in get_place() gives each a fresh shot at a complete scrape.
	 */
	public function sync_missing_hours(): array {
		$results = [ 'synced' => 0, 'recovered' => 0, 'still_missing' => [], 'errors' => [], 'checked' => 0 ];

		$posts = get_posts( [
			'post_type'      => GBP_SYNC_POST_TYPE,
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => 'loc_place_id',
				'value'   => '',
				'compare' => '!=',
			] ],
		] );

		foreach ( $posts as $post ) {
			$hours = get_field( 'loc_hours', $post->ID );
			if ( ! empty( $hours ) ) {
				continue; // Already has hours — skip.
			}

			$results['checked']++;

			$place_id = get_field( 'loc_place_id', $post->ID );
			$err      = $this->sync_post( $post->ID, $place_id );

			if ( $err ) {
				$results['errors'][] = $post->post_title . ': ' . $err;
				continue;
			}

			$results['synced']++;

			// Did the re-sync actually populate hours?
			if ( ! empty( get_field( 'loc_hours', $post->ID ) ) ) {
				$results['recovered']++;
			} else {
				$results['still_missing'][] = $post->post_title;
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
		error_log( 'GBP place_keys post_id=' . $post_id . ' keys=' . implode( ',', array_keys( $place ) ) );
		error_log( 'GBP place_full post_id=' . $post_id . ' json=' . wp_json_encode( $place ) );
		$ext = $place['extensions'] ?? [];
		$hrs = $place['hours'] ?? $place['operating_hours'] ?? $ext['hours'] ?? $ext['operating_hours'] ?? 'NOT IN RESPONSE';
		set_transient( 'gbp_serp_debug_' . $post_id, [
			'hours'           => $hrs,
			'hours_type'      => gettype( $hrs ),
			'hours_json'      => wp_json_encode( $hrs ),
			'extensions'      => $ext,
			'extensions_keys' => is_array( $ext ) ? array_keys( $ext ) : 'not_array',
			'keys'            => array_keys( $place ),
		], 300 );

		$this->map_to_acf( $post_id, $place );
		return null;
	}

	// -------------------------------------------------------------------------
	// API
	// -------------------------------------------------------------------------

	/**
	 * Number of times to re-request a place when the scrape returns no hours.
	 *
	 * Fresh SerpAPI scrapes (total_time_taken > ~2s) sometimes return the "lite"
	 * Google Maps place panel with no `hours`/`open_state`. A retry usually lands
	 * a complete scrape, which SerpAPI then caches (~1h) for fast future syncs.
	 */
	private const HOURS_RETRY_ATTEMPTS = 3;
	private const HOURS_RETRY_DELAY_US = 1500000; // 1.5s between attempts.

	private function get_place( string $place_id ): array {
		$url = add_query_arg( [
			'engine'   => 'google_maps',
			'type'     => 'place',
			'place_id' => $place_id,
			'api_key'  => $this->api_key,
		], self::API_URL );

		$place = [];

		for ( $attempt = 1; $attempt <= self::HOURS_RETRY_ATTEMPTS; $attempt++ ) {
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

			$place = $body['place_results'] ?? [];

			// Complete scrape — hours present. Done.
			if ( isset( $place['hours'] ) || isset( $place['open_state'] ) ) {
				if ( $attempt > 1 ) {
					error_log( 'GBP SerpSync hours recovered for ' . $place_id . ' on attempt ' . $attempt );
				}
				return $place;
			}

			// No hours this pass — retry unless out of attempts.
			if ( $attempt < self::HOURS_RETRY_ATTEMPTS ) {
				error_log( 'GBP SerpSync no hours for ' . $place_id . ' (attempt ' . $attempt . '), retrying' );
				usleep( self::HOURS_RETRY_DELAY_US );
			} else {
				error_log( 'GBP SerpSync no hours for ' . $place_id . ' after ' . self::HOURS_RETRY_ATTEMPTS . ' attempts — likely no hours configured in GBP' );
			}
		}

		return $place;
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

		// Hours — check top-level first, then extensions (SerpAPI Google Maps buries hours there).
		$ext       = $place['extensions'] ?? [];
		$hours_raw = $place['hours'] ?? $place['operating_hours']
			?? ( is_array( $ext ) ? ( $ext['hours'] ?? $ext['operating_hours'] ?? [] ) : [] );
		error_log( 'GBP hours_raw post_id=' . $post_id . ' type=' . gettype( $hours_raw ) . ' value=' . wp_json_encode( $hours_raw ) );
		error_log( 'GBP extensions post_id=' . $post_id . ' keys=' . ( is_array( $ext ) ? implode( ',', array_keys( $ext ) ) : 'not_array' ) . ' value=' . wp_json_encode( $ext ) );
		$hours_mapped = false;
		if ( is_array( $hours_raw ) && ! empty( $hours_raw ) ) {
			$first     = reset( $hours_raw );
			$timetable = $hours_raw['timetable'] ?? null;
			if ( $timetable ) {
				// Structure A: hours.timetable.{day} = [{open,close}]
				$this->map_hours( $post_id, $timetable );
				$hours_mapped = true;
			} elseif ( isset( $hours_raw['monday'] ) || isset( $hours_raw['sunday'] ) ) {
				// Structure B: hours.{day} directly
				$this->map_hours( $post_id, $hours_raw );
				$hours_mapped = true;
			} elseif ( is_array( $first ) && ! empty( $first ) ) {
				// Structure C (actual SerpAPI): [{sunday:"9 AM–9 PM"},{monday:"10 AM–8 PM"},...]
				$this->map_hours_from_keyed_objects( $post_id, $hours_raw );
				$hours_mapped = true;
			} elseif ( is_string( $first ) ) {
				// Structure D: ["Monday: 8am-6pm", ...]
				$this->map_hours_from_strings( $post_id, $hours_raw );
				$hours_mapped = true;
			} else {
				error_log( 'GBP hours_raw unrecognized structure post_id=' . $post_id . ' first_type=' . gettype( $first ) . ' first_keys=' . ( is_array( $first ) ? implode( ',', array_keys( $first ) ) : 'n/a' ) );
			}
		} elseif ( is_string( $hours_raw ) && $hours_raw !== '' ) {
			// Fallback: single string like "Mon-Fri: 9AM-5PM"
			error_log( 'GBP hours_raw is string post_id=' . $post_id . ': ' . $hours_raw );
		}

		// SerpAPI returned no usable hours (common when GBP hours aren't on the
		// public Maps listing). Fall back to the authoritative Google Places API.
		if ( ! $hours_mapped && $pid ) {
			$this->map_hours_from_places_api( $post_id, $pid );
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

		// Keep post title and slug in sync using short name (prefix stripped).
		if ( ! empty( $place['title'] ) ) {
			$short_title = gbp_derive_short_title( $place['title'] );
			if ( get_the_title( $post_id ) !== $short_title ) {
				wp_update_post( [
					'ID'         => $post_id,
					'post_title' => $short_title,
					'post_name'  => sanitize_title( $short_title ),
				] );
			}
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
						'open_time'  => $this->normalize_time( (string) ( $period['open']  ?? $period['opens']  ?? '' ) ),
						'close_time' => $this->normalize_time( (string) ( $period['close'] ?? $period['closes'] ?? '' ) ),
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
	/**
	 * Authoritative hours fallback via Google Places API (New).
	 *
	 * SerpAPI scrapes the public Maps listing; some GBP hours never surface there.
	 * Places API reads Google's real data for the same place_id. Returns true if
	 * hours were written to loc_hours.
	 *
	 * Requires "Places API (New)" enabled + billing on the Google Cloud project.
	 * Uses gbp_sync_places_api_key, falling back to the Maps embed key.
	 */
	private function map_hours_from_places_api( int $post_id, string $place_id ): bool {
		$key = get_option( 'gbp_sync_places_api_key', '' );
		if ( ! $key ) {
			$key = get_option( 'gbp_sync_maps_embed_key', '' );
		}
		if ( ! $key ) {
			error_log( 'GBP Places API skipped (no key) post_id=' . $post_id );
			return false;
		}

		$url      = 'https://places.googleapis.com/v1/places/' . rawurlencode( $place_id );
		$response = wp_remote_get( $url, [
			'timeout' => 20,
			'headers' => [
				'X-Goog-Api-Key'   => $key,
				'X-Goog-FieldMask' => 'regularOpeningHours',
			],
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'GBP Places API error post_id=' . $post_id . ': ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code !== 200 ) {
			error_log( 'GBP Places API HTTP ' . $code . ' post_id=' . $post_id . ': ' . wp_json_encode( $body['error'] ?? $body ) );
			return false;
		}

		$periods = $body['regularOpeningHours']['periods'] ?? [];
		if ( empty( $periods ) ) {
			error_log( 'GBP Places API returned no periods post_id=' . $post_id . ' place_id=' . $place_id );
			return false;
		}

		return $this->map_hours_from_places_periods( $post_id, $periods );
	}

	/**
	 * Map Places API regularOpeningHours.periods to the ACF loc_hours repeater.
	 *
	 * Each period: {open:{day,hour,minute}, close:{day,hour,minute}}. day is
	 * 0=Sunday..6=Saturday. A 24-hour day has open with no close. Days absent
	 * from periods are closed.
	 */
	private function map_hours_from_places_periods( int $post_id, array $periods ): bool {
		$day_label = [ 0 => 'SUNDAY', 1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY' ];
		$order     = [ 1, 2, 3, 4, 5, 6, 0 ]; // Monday-first, matching SerpAPI mapping.

		$by_day = [];
		foreach ( $periods as $p ) {
			$od = $p['open']['day'] ?? null;
			if ( $od === null ) {
				continue;
			}
			$open = $this->fmt_clock( (int) ( $p['open']['hour'] ?? 0 ), (int) ( $p['open']['minute'] ?? 0 ) );

			if ( ! isset( $p['close'] ) ) {
				// Open 24 hours.
				$by_day[ $od ][] = [ 'open' => $open, 'close' => $this->fmt_clock( 23, 59 ) ];
				continue;
			}
			$close            = $this->fmt_clock( (int) ( $p['close']['hour'] ?? 0 ), (int) ( $p['close']['minute'] ?? 0 ) );
			$by_day[ $od ][] = [ 'open' => $open, 'close' => $close ];
		}

		$rows = [];
		foreach ( $order as $d ) {
			$label = $day_label[ $d ];
			if ( empty( $by_day[ $d ] ) ) {
				$rows[] = [ 'day' => $label, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
				continue;
			}
			foreach ( $by_day[ $d ] as $pr ) {
				$rows[] = [ 'day' => $label, 'open_time' => $pr['open'], 'close_time' => $pr['close'], 'is_closed' => 0 ];
			}
		}

		update_field( 'loc_hours', $rows, $post_id );
		error_log( 'GBP Places API hours mapped post_id=' . $post_id . ' rows=' . count( $rows ) );
		return true;
	}

	/**
	 * Format an hour/minute pair to ACF's "g:i A" (e.g. 11,0 → "11:00 AM").
	 */
	private function fmt_clock( int $hour, int $minute ): string {
		return date( 'g:i A', mktime( $hour, $minute, 0, 1, 1, 2000 ) );
	}

	private function split_time_range( string $range ): array {
		$parts = preg_split( '/\s*[–—\-]\s*/u', $range, 2 );
		return [
			$this->normalize_time( trim( $parts[0] ?? '' ) ),
			$this->normalize_time( trim( $parts[1] ?? '' ) ),
		];
	}

	/**
	 * Normalize a time token to ACF's "g:i A" format (e.g. "11 AM" → "11:00 AM").
	 *
	 * SerpAPI drops the minutes on round hours ("11 AM", "9 PM"); ACF time pickers
	 * store/display "g:i A", so the missing ":00" renders inconsistently. Returns
	 * the original string unchanged if it can't be parsed.
	 */
	private function normalize_time( string $time ): string {
		if ( $time === '' ) {
			return '';
		}
		// Normalize unicode spaces (incl. non-breaking) so strtotime parses cleanly.
		$clean = trim( preg_replace( '/\s+/u', ' ', $time ) );
		$ts    = strtotime( $clean );
		return $ts !== false ? date( 'g:i A', $ts ) : $time;
	}
}
