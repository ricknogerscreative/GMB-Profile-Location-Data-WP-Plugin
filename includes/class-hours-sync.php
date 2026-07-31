<?php
/**
 * Hours sync — IO and orchestration.
 *
 * Fetches from Places API (New), optionally falling back to a SerpAPI payload
 * supplied by the caller, and runs every write through
 * GBP_Hours_Rules::decide(). A failed or incomplete fetch never writes.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Hours_Sync {

	private const PLACES_URL = 'https://places.googleapis.com/v1/places/';
	private const FIELD_MASK = 'regularOpeningHours,currentOpeningHours,businessStatus';

	/** Hours older than this are flagged in the admin list. */
	public const STALE_AFTER = 7 * DAY_IN_SECONDS;

	private const META_SNAPSHOT         = '_gbp_hours_snapshot';
	private const META_SPECIAL_SNAPSHOT = '_gbp_special_snapshot';
	private const META_FETCHED_AT       = '_gbp_hours_fetched_at';
	private const META_SOURCE           = '_gbp_hours_source';
	private const META_LAST_ERROR       = '_gbp_hours_last_error';

	private string $key;
	private ?string $last_error = null;

	public function __construct() {
		$key = get_option( 'gbp_sync_places_api_key', '' );
		if ( ! $key ) {
			$key = get_option( 'gbp_sync_maps_embed_key', '' );
		}
		$this->key = (string) $key;
	}

	public function is_configured(): bool {
		return '' !== $this->key;
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Run the hours sync across every location that has a Place ID.
	 *
	 * Places API only — no SerpAPI fallback. This is the cheap, frequently-run
	 * action and must not silently spend SerpAPI credits.
	 */
	public function sync_all(): array {
		$posts = get_posts( [
			'post_type'      => GBP_SYNC_POST_TYPE,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [ [
				'key'     => 'loc_place_id',
				'value'   => '',
				'compare' => '!=',
			] ],
		] );

		$agg = [
			'checked'   => 0,
			'populated' => 0,
			'adopted'   => 0,
			'written'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'errors'    => [],
			'locations' => [],
		];

		foreach ( $posts as $post ) {
			$result = $this->sync_location( $post->ID );

			$agg['checked']++;
			$agg['locations'][] = $result;

			switch ( $result['hours'] ) {
				case GBP_Hours_Rules::POPULATE:
					$agg['populated']++;
					break;
				case GBP_Hours_Rules::ADOPT:
					$agg['adopted']++;
					break;
				case GBP_Hours_Rules::WRITE:
					$agg['written']++;
					break;
				case GBP_Hours_Rules::UNCHANGED:
					$agg['unchanged']++;
					break;
				default:
					$agg['skipped']++;
			}

			if ( $result['error'] ) {
				$agg['errors'][] = $result['title'] . ': ' . $result['error'];
			}
		}

		update_option( 'gbp_sync_hours_last_run', current_time( 'mysql' ) );
		return $agg;
	}

	/**
	 * Sync hours for one location.
	 *
	 * @param int   $post_id
	 * @param mixed $serp_hours_raw Optional SerpAPI hours payload to fall back
	 *                              on when Places API yields nothing. Passing it
	 *                              costs no extra credit because the caller has
	 *                              already made the SerpAPI request.
	 */
	public function sync_location( int $post_id, $serp_hours_raw = null ): array {
		$this->last_error = null;

		$result = [
			'post_id'       => $post_id,
			'title'         => get_the_title( $post_id ),
			'hours'         => GBP_Hours_Rules::SKIP,
			'special'       => GBP_Hours_Rules::SKIP,
			'source'        => null,
			'status_source' => null,
			'error'         => null,
		];

		$place_id = get_field( 'loc_place_id', $post_id );
		if ( ! $place_id ) {
			$result['error'] = 'No Google Place ID set.';
			return $this->record_error( $post_id, $result );
		}

		$places  = $this->fetch_places( (string) $place_id );
		$regular = null;

		if ( null !== $places ) {
			$this->write_status( $post_id, $places['status'] );
			$result['status_source'] = 'places';
			$regular = GBP_Hours_Rules::canonicalize_places( $places['regular_periods'] );
			if ( null !== $regular ) {
				$result['source'] = 'places';
			} else {
				$this->last_error = 'Places API returned no usable opening hours.';
			}
		}

		// Fall back to the caller's SerpAPI payload only when one was supplied.
		if ( null === $regular && null !== $serp_hours_raw ) {
			$regular = GBP_Hours_Rules::canonicalize_serp( $serp_hours_raw );
			if ( null !== $regular ) {
				$result['source'] = 'serpapi';
				$this->last_error = null;
			}
		}

		if ( null === $regular ) {
			$result['error'] = $this->last_error ?: 'No usable hours from Places API or SerpAPI.';
			return $this->record_error( $post_id, $result );
		}

		delete_post_meta( $post_id, self::META_LAST_ERROR );
		update_post_meta( $post_id, self::META_FETCHED_AT, current_time( 'mysql' ) );
		update_post_meta( $post_id, self::META_SOURCE, $result['source'] );

		$result['hours'] = $this->apply_regular( $post_id, $regular );

		// Special hours are derivable only from the Places response.
		if ( null !== $places && 'places' === $result['source'] ) {
			$result['special'] = $this->apply_special( $post_id, $regular, $places['current_periods'] );
		}

		return $result;
	}

	/**
	 * Hours freshness for one location, for the admin list column.
	 */
	public static function staleness( int $post_id ): array {
		$source = (string) get_post_meta( $post_id, self::META_SOURCE, true );
		$error  = (string) get_post_meta( $post_id, self::META_LAST_ERROR, true );
		$hours  = get_field( 'loc_hours', $post_id );

		if ( empty( $hours ) ) {
			return [ 'stale' => true, 'label' => 'No hours', 'source' => $source, 'error' => $error ];
		}

		$fetched = (string) get_post_meta( $post_id, self::META_FETCHED_AT, true );
		if ( '' === $fetched ) {
			return [ 'stale' => true, 'label' => 'Never synced', 'source' => $source, 'error' => $error ];
		}

		$then = strtotime( $fetched );
		$now  = (int) current_time( 'timestamp' );

		return [
			'stale'  => ( $now - $then ) > self::STALE_AFTER,
			'label'  => human_time_diff( $then, $now ) . ' ago',
			'source' => $source,
			'error'  => $error,
		];
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	private function apply_regular( int $post_id, array $fetched ): string {
		$current = get_field( 'loc_hours', $post_id );
		$action  = GBP_Hours_Rules::decide(
			$fetched,
			$this->read_snapshot( $post_id, self::META_SNAPSHOT ),
			empty( $current )
		);

		if ( GBP_Hours_Rules::POPULATE === $action || GBP_Hours_Rules::WRITE === $action ) {
			update_field( 'loc_hours', $fetched, $post_id );
		}

		if ( GBP_Hours_Rules::SKIP !== $action ) {
			update_post_meta( $post_id, self::META_SNAPSHOT, wp_json_encode( $fetched ) );
		}

		return $action;
	}

	private function apply_special( int $post_id, array $regular, array $current_periods ): string {
		$today      = current_time( 'Y-m-d' );
		$window_end = date( 'Y-m-d', strtotime( $today . ' +6 day' ) );

		$derived = GBP_Hours_Rules::derive_special( $regular, $current_periods, $today );

		$current = get_field( 'loc_special_hours', $post_id );
		$current = is_array( $current ) ? $current : [];

		$action = GBP_Hours_Rules::decide(
			$derived,
			$this->read_snapshot( $post_id, self::META_SPECIAL_SNAPSHOT ),
			empty( $current )
		);

		if ( GBP_Hours_Rules::POPULATE === $action || GBP_Hours_Rules::WRITE === $action ) {
			$merged = GBP_Hours_Rules::merge_special_window( $current, $derived, $window_end );
			update_field( 'loc_special_hours', $merged, $post_id );
		}

		if ( GBP_Hours_Rules::SKIP !== $action ) {
			update_post_meta( $post_id, self::META_SPECIAL_SNAPSHOT, wp_json_encode( $derived ) );
		}

		return $action;
	}

	/**
	 * Business status is machine-owned and drives site-wide closure banners, so
	 * it is written on every successful fetch rather than snapshot-gated.
	 *
	 * CLOSED_PERMANENTLY deliberately does not unpublish the post — taking a
	 * live location page down is a decision for a human.
	 */
	private function write_status( int $post_id, string $business_status ): void {
		$map = [
			'OPERATIONAL'        => [ 'OPEN', 0 ],
			'CLOSED_TEMPORARILY' => [ 'CLOSED_TEMPORARILY', 1 ],
			'CLOSED_PERMANENTLY' => [ 'CLOSED_PERMANENTLY', 0 ],
		];

		if ( ! isset( $map[ $business_status ] ) ) {
			return;
		}

		[ $status, $temp_closed ] = $map[ $business_status ];
		update_field( 'loc_status', $status, $post_id );
		update_field( 'loc_temp_closed', $temp_closed, $post_id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return ?array [ regular_periods, current_periods, status ] or null on failure.
	 */
	private function fetch_places( string $place_id ): ?array {
		if ( ! $this->is_configured() ) {
			$this->last_error = 'No Places API key configured.';
			return null;
		}

		$response = wp_remote_get( self::PLACES_URL . rawurlencode( $place_id ), [
			'timeout' => 20,
			'headers' => [
				'X-Goog-Api-Key'   => $this->key,
				'X-Goog-FieldMask' => self::FIELD_MASK,
			],
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = 'Places API: ' . $response->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : [];

		if ( 200 !== $code ) {
			$this->last_error = 'Places API HTTP ' . $code . ' — ' . ( $body['error']['message'] ?? 'unknown error' );
			return null;
		}

		return [
			'regular_periods' => $body['regularOpeningHours']['periods'] ?? [],
			'current_periods' => $body['currentOpeningHours']['periods'] ?? [],
			'status'          => (string) ( $body['businessStatus'] ?? '' ),
		];
	}

	private function read_snapshot( int $post_id, string $meta_key ): ?array {
		$raw = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function record_error( int $post_id, array $result ): array {
		update_post_meta( $post_id, self::META_LAST_ERROR, $result['error'] );
		$result['hours'] = 'error';
		return $result;
	}
}
