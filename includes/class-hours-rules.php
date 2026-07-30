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
}
