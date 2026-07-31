<?php

/** Helper: a Places period. Day is 0=Sunday..6=Saturday. */
function places_period( int $open_day, int $open_h, int $open_m, ?int $close_day = null, ?int $close_h = null, ?int $close_m = null ): array {
	$p = [ 'open' => [ 'day' => $open_day, 'hour' => $open_h, 'minute' => $open_m ] ];
	if ( null !== $close_day ) {
		$p['close'] = [ 'day' => $close_day, 'hour' => $close_h, 'minute' => $close_m ];
	}
	return $p;
}

describe( 'canonicalize_places', function () {

	it( 'returns null for empty periods', function () {
		expect_null( GBP_Hours_Rules::canonicalize_places( [] ) );
	} );

	it( 'returns null when no period has a usable open day', function () {
		expect_null( GBP_Hours_Rules::canonicalize_places( [ [ 'close' => [ 'day' => 1, 'hour' => 9, 'minute' => 0 ] ] ] ) );
	} );

	it( 'maps a single weekday and closes the other six', function () {
		$rows = GBP_Hours_Rules::canonicalize_places( [ places_period( 1, 8, 0, 1, 18, 0 ) ] );
		expect_equals( 7, count( $rows ) );
		expect_equals(
			[ 'day' => 'MONDAY', 'open_time' => '8:00 AM', 'close_time' => '6:00 PM', 'is_closed' => 0 ],
			$rows[0]
		);
		expect_equals(
			[ 'day' => 'TUESDAY', 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ],
			$rows[1]
		);
	} );

	it( 'orders rows Monday first with Sunday last', function () {
		$rows = GBP_Hours_Rules::canonicalize_places( [
			places_period( 0, 9, 0, 0, 17, 0 ),  // Sunday
			places_period( 1, 8, 0, 1, 18, 0 ),  // Monday
		] );
		expect_equals( 'MONDAY', $rows[0]['day'] );
		expect_equals( 'SUNDAY', $rows[6]['day'] );
		expect_equals( '9:00 AM', $rows[6]['open_time'] );
	} );

	it( 'treats a period with no close as open 24 hours', function () {
		$rows = GBP_Hours_Rules::canonicalize_places( [ places_period( 1, 0, 0 ) ] );
		expect_equals( '12:00 AM', $rows[0]['open_time'] );
		expect_equals( '11:59 PM', $rows[0]['close_time'] );
		expect_equals( 0, $rows[0]['is_closed'] );
	} );

	it( 'assigns an overnight period to its opening day', function () {
		// Monday 8pm to Tuesday 2am.
		$rows = GBP_Hours_Rules::canonicalize_places( [ places_period( 1, 20, 0, 2, 2, 0 ) ] );
		expect_equals( 'MONDAY', $rows[0]['day'] );
		expect_equals( '8:00 PM', $rows[0]['open_time'] );
		expect_equals( '2:00 AM', $rows[0]['close_time'] );
		// Tuesday itself has no period of its own.
		expect_equals( 1, $rows[1]['is_closed'] );
	} );

	it( 'emits two rows for a split shift on one day', function () {
		$rows = GBP_Hours_Rules::canonicalize_places( [
			places_period( 1, 8, 0, 1, 12, 0 ),
			places_period( 1, 13, 0, 1, 18, 0 ),
		] );
		expect_equals( 8, count( $rows ) );
		expect_equals( '8:00 AM', $rows[0]['open_time'] );
		expect_equals( '12:00 PM', $rows[0]['close_time'] );
		expect_equals( 'MONDAY', $rows[1]['day'] );
		expect_equals( '1:00 PM', $rows[1]['open_time'] );
	} );

	it( 'maps a full seven-day week', function () {
		$periods = [];
		foreach ( [ 0, 1, 2, 3, 4, 5, 6 ] as $d ) {
			$periods[] = places_period( $d, 8, 0, $d, 18, 0 );
		}
		$rows = GBP_Hours_Rules::canonicalize_places( $periods );
		expect_equals( 7, count( $rows ) );
		foreach ( $rows as $row ) {
			expect_equals( 0, $row['is_closed'] );
			expect_equals( '8:00 AM', $row['open_time'] );
		}
	} );
} );
