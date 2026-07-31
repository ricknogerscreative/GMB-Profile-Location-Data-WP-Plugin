<?php

/** Canonical regular hours: open 8-6 every day. */
function regular_all_open(): array {
	$rows = [];
	foreach ( GBP_Hours_Rules::DAYS as $day ) {
		$rows[] = [ 'day' => $day, 'open_time' => '8:00 AM', 'close_time' => '6:00 PM', 'is_closed' => 0 ];
	}
	return $rows;
}

/** A currentOpeningHours period carrying a concrete date. */
function current_period( string $date, int $open_h, int $open_m, int $close_h, int $close_m ): array {
	[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
	return [
		'open'  => [ 'date' => [ 'year' => $y, 'month' => $m, 'day' => $d ], 'hour' => $open_h, 'minute' => $open_m ],
		'close' => [ 'date' => [ 'year' => $y, 'month' => $m, 'day' => $d ], 'hour' => $close_h, 'minute' => $close_m ],
	];
}

/** Seven consecutive days of standard 8-6 periods starting at $today. */
function current_standard_week( string $today ): array {
	$out = [];
	for ( $i = 0; $i < 7; $i++ ) {
		$date  = date( 'Y-m-d', strtotime( $today . ' +' . $i . ' day' ) );
		$out[] = current_period( $date, 8, 0, 18, 0 );
	}
	return $out;
}

describe( 'derive_special', function () {

	$today = '2026-11-23'; // A Monday.

	it( 'emits nothing when current hours match regular hours', function () use ( $today ) {
		$out = GBP_Hours_Rules::derive_special( regular_all_open(), current_standard_week( $today ), $today );
		expect_equals( [], $out );
	} );

	it( 'emits a closed row for a day absent from current hours', function () use ( $today ) {
		$periods = current_standard_week( $today );
		array_splice( $periods, 3, 1 ); // Drop Thursday 2026-11-26.
		$out = GBP_Hours_Rules::derive_special( regular_all_open(), $periods, $today );
		expect_equals( 1, count( $out ) );
		expect_equals( '2026-11-26', $out[0]['date'] );
		expect_equals( 1, $out[0]['is_closed'] );
		expect_equals( '', $out[0]['open_time'] );
	} );

	it( 'emits an adjusted row when current hours differ', function () use ( $today ) {
		$periods    = current_standard_week( $today );
		$periods[4] = current_period( '2026-11-27', 8, 0, 13, 0 ); // Friday closes early.
		$out        = GBP_Hours_Rules::derive_special( regular_all_open(), $periods, $today );
		expect_equals( 1, count( $out ) );
		expect_equals( '2026-11-27', $out[0]['date'] );
		expect_equals( 0, $out[0]['is_closed'] );
		expect_equals( '8:00 AM', $out[0]['open_time'] );
		expect_equals( '1:00 PM', $out[0]['close_time'] );
	} );

	it( 'emits nothing for a day that is closed in both regular and current hours', function () use ( $today ) {
		$regular = regular_all_open();
		$regular[6] = [ 'day' => 'SUNDAY', 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
		$periods = current_standard_week( $today );
		array_pop( $periods ); // Drop Sunday 2026-11-29.
		$out = GBP_Hours_Rules::derive_special( $regular, $periods, $today );
		expect_equals( [], $out );
	} );

	it( 'ignores periods with no date object', function () use ( $today ) {
		$periods   = current_standard_week( $today );
		$periods[] = [ 'open' => [ 'day' => 1, 'hour' => 8, 'minute' => 0 ] ];
		$out       = GBP_Hours_Rules::derive_special( regular_all_open(), $periods, $today );
		expect_equals( [], $out );
	} );

	it( 'covers only the seven-day window starting today', function () use ( $today ) {
		$periods   = current_standard_week( $today );
		$periods[] = current_period( '2026-12-25', 8, 0, 13, 0 ); // Outside the window.
		$out       = GBP_Hours_Rules::derive_special( regular_all_open(), $periods, $today );
		expect_equals( [], $out );
	} );

	it( 'returns nothing when periods carry no dates at all', function () use ( $today ) {
		// The date assumption failing must not fabricate a week of closures.
		$dateless = [
			[ 'open' => [ 'day' => 1, 'hour' => 8, 'minute' => 0 ], 'close' => [ 'day' => 1, 'hour' => 18, 'minute' => 0 ] ],
			[ 'open' => [ 'day' => 2, 'hour' => 8, 'minute' => 0 ], 'close' => [ 'day' => 2, 'hour' => 18, 'minute' => 0 ] ],
		];
		expect_equals( [], GBP_Hours_Rules::derive_special( regular_all_open(), $dateless, $today ) );
	} );

	it( 'treats a 24-hour period as open until 11:59 PM', function () use ( $today ) {
		$periods    = current_standard_week( $today );
		$periods[1] = [
			'open' => [ 'date' => [ 'year' => 2026, 'month' => 11, 'day' => 24 ], 'hour' => 0, 'minute' => 0 ],
		];
		$out = GBP_Hours_Rules::derive_special( regular_all_open(), $periods, $today );
		expect_equals( 1, count( $out ) );
		expect_equals( '12:00 AM', $out[0]['open_time'] );
		expect_equals( '11:59 PM', $out[0]['close_time'] );
	} );
} );
