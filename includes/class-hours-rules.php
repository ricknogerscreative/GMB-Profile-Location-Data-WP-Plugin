<?php
/**
 * Pure hours logic — no WordPress, no IO, no state.
 *
 * Every hours decision in the plugin lives here so it can be unit-tested
 * without a WordPress bootstrap. GBP_Hours_Sync performs the HTTP and ACF work
 * and calls into this class for each decision.
 */
defined( 'ABSPATH' ) || exit;

final class GBP_Hours_Rules {

	/** Canonical day order — Monday first. */
	public const DAYS = [ 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY' ];

	/**
	 * Unicode spaces Google emits inside time strings. PCRE's \s does not match
	 * these even with /u unless PCRE_UCP is on, so they are listed explicitly.
	 */
	private const SPACE = '[\s\x{00A0}\x{202F}]';

	/** Places API day index (0=Sunday) to canonical label. */
	private const PLACES_DAY = [
		0 => 'SUNDAY',
		1 => 'MONDAY',
		2 => 'TUESDAY',
		3 => 'WEDNESDAY',
		4 => 'THURSDAY',
		5 => 'FRIDAY',
		6 => 'SATURDAY',
	];

	/**
	 * Format an hour/minute pair as "g:i A".
	 *
	 * Done arithmetically rather than via date()/mktime() so the result cannot
	 * shift with the server timezone.
	 */
	public static function fmt_clock( int $hour, int $minute ): string {
		$h12  = $hour % 12 ?: 12;
		$ampm = $hour >= 12 ? 'PM' : 'AM';
		return sprintf( '%d:%02d %s', $h12, $minute, $ampm );
	}

	/**
	 * Normalize a time token to "g:i A" — e.g. "11 AM" becomes "11:00 AM".
	 *
	 * SerpAPI drops the minutes on round hours; ACF stores and displays "g:i A",
	 * so the missing ":00" renders inconsistently. Returns the input unchanged
	 * when it cannot be parsed.
	 */
	public static function normalize_time( string $time ): string {
		if ( '' === $time ) {
			return '';
		}
		$clean = trim( (string) preg_replace( '/' . self::SPACE . '+/u', ' ', $time ) );
		$ts    = strtotime( $clean );
		return false !== $ts ? date( 'g:i A', $ts ) : $time;
	}

	/**
	 * Split "8:00 AM–6:00 PM" into [ open, close ], normalizing both sides.
	 *
	 * The separator may be a hyphen, en-dash or em-dash, and Google surrounds it
	 * with ordinary, non-breaking or narrow no-break spaces.
	 */
	public static function split_time_range( string $range ): array {
		$pattern = '/' . self::SPACE . '*[–—-]' . self::SPACE . '*/u';
		$parts   = preg_split( $pattern, $range, 2 );
		return [
			self::normalize_time( trim( $parts[0] ?? '' ) ),
			self::normalize_time( trim( $parts[1] ?? '' ) ),
		];
	}

	/**
	 * Convert Places API regularOpeningHours.periods to canonical rows.
	 *
	 * A period is anchored to its opening day, so an overnight close lands on
	 * the day the location opened. A weekday absent from the response is closed
	 * — that is Google's own semantics and is authoritative. Returns null when
	 * the input yields no usable period.
	 */
	public static function canonicalize_places( array $periods ): ?array {
		if ( empty( $periods ) ) {
			return null;
		}

		$by_day = [];
		foreach ( $periods as $p ) {
			$day = $p['open']['day'] ?? null;
			if ( null === $day || ! isset( self::PLACES_DAY[ $day ] ) ) {
				continue;
			}

			$open = self::fmt_clock( (int) ( $p['open']['hour'] ?? 0 ), (int) ( $p['open']['minute'] ?? 0 ) );

			// A period with an open and no close is Google's "open 24 hours".
			$close = isset( $p['close'] )
				? self::fmt_clock( (int) ( $p['close']['hour'] ?? 0 ), (int) ( $p['close']['minute'] ?? 0 ) )
				: self::fmt_clock( 23, 59 );

			$by_day[ self::PLACES_DAY[ $day ] ][] = [ 'open' => $open, 'close' => $close ];
		}

		return empty( $by_day ) ? null : self::build_rows( $by_day );
	}

	/**
	 * Expand a [ DAY => [ {open,close}, … ] ] map into canonical rows in fixed
	 * Monday-first order, emitting a closed row for any day with no periods.
	 */
	private static function build_rows( array $by_day ): array {
		$rows = [];
		foreach ( self::DAYS as $label ) {
			if ( empty( $by_day[ $label ] ) ) {
				$rows[] = [ 'day' => $label, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
				continue;
			}
			foreach ( $by_day[ $label ] as $period ) {
				$rows[] = [
					'day'        => $label,
					'open_time'  => $period['open'],
					'close_time' => $period['close'],
					'is_closed'  => 0,
				];
			}
		}
		return $rows;
	}
}
