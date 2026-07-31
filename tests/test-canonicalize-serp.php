<?php

/** Every weekday present, as the shape SerpAPI actually returns. */
function serp_full_week(): array {
	return [
		[ 'monday'    => '8 AM–6 PM' ],
		[ 'tuesday'   => '8 AM–6 PM' ],
		[ 'wednesday' => '8 AM–6 PM' ],
		[ 'thursday'  => '8 AM–6 PM' ],
		[ 'friday'    => '8 AM–6 PM' ],
		[ 'saturday'  => '9 AM–3 PM' ],
		[ 'sunday'    => 'Closed' ],
	];
}

describe( 'canonicalize_serp — completeness guard', function () {

	it( 'rejects a partial week', function () {
		expect_null( GBP_Hours_Rules::canonicalize_serp( [
			[ 'monday'  => '8 AM–6 PM' ],
			[ 'tuesday' => '8 AM–6 PM' ],
		] ) );
	} );

	it( 'rejects an empty array', function () {
		expect_null( GBP_Hours_Rules::canonicalize_serp( [] ) );
	} );

	it( 'rejects a non-array', function () {
		expect_null( GBP_Hours_Rules::canonicalize_serp( 'Mon-Fri 9-5' ) );
	} );

	it( 'rejects a full week containing an unparseable range', function () {
		$week = serp_full_week();
		$week[2] = [ 'wednesday' => 'by appointment' ];
		expect_null( GBP_Hours_Rules::canonicalize_serp( $week ) );
	} );

	it( 'accepts a complete week', function () {
		$rows = GBP_Hours_Rules::canonicalize_serp( serp_full_week() );
		expect_equals( 7, count( $rows ) );
	} );
} );

describe( 'canonicalize_serp — shapes', function () {

	it( 'handles keyed objects, the shape SerpAPI returns', function () {
		$rows = GBP_Hours_Rules::canonicalize_serp( serp_full_week() );
		expect_equals(
			[ 'day' => 'MONDAY', 'open_time' => '8:00 AM', 'close_time' => '6:00 PM', 'is_closed' => 0 ],
			$rows[0]
		);
		expect_equals(
			[ 'day' => 'SUNDAY', 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ],
			$rows[6]
		);
	} );

	it( 'handles a flat day-keyed map', function () {
		$rows = GBP_Hours_Rules::canonicalize_serp( [
			'monday'    => '8 AM–6 PM',
			'tuesday'   => '8 AM–6 PM',
			'wednesday' => '8 AM–6 PM',
			'thursday'  => '8 AM–6 PM',
			'friday'    => '8 AM–6 PM',
			'saturday'  => '9 AM–3 PM',
			'sunday'    => 'Closed',
		] );
		expect_equals( '8:00 AM', $rows[0]['open_time'] );
		expect_equals( 1, $rows[6]['is_closed'] );
	} );

	it( 'handles a timetable wrapper with {open,close} objects', function () {
		$timetable = [];
		foreach ( [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ] as $d ) {
			$timetable[ $d ] = [ [ 'open' => '8:00 AM', 'close' => '6:00 PM' ] ];
		}
		$rows = GBP_Hours_Rules::canonicalize_serp( [ 'timetable' => $timetable ] );
		expect_equals( 7, count( $rows ) );
		expect_equals( '8:00 AM', $rows[0]['open_time'] );
		expect_equals( '6:00 PM', $rows[0]['close_time'] );
	} );

	it( 'handles a "Day: range" string array', function () {
		$rows = GBP_Hours_Rules::canonicalize_serp( [
			'Monday: 8:00 AM–6:00 PM',
			'Tuesday: 8:00 AM–6:00 PM',
			'Wednesday: 8:00 AM–6:00 PM',
			'Thursday: 8:00 AM–6:00 PM',
			'Friday: 8:00 AM–6:00 PM',
			'Saturday: 9:00 AM–3:00 PM',
			'Sunday: Closed',
		] );
		expect_equals( '8:00 AM', $rows[0]['open_time'] );
		expect_equals( 1, $rows[6]['is_closed'] );
	} );

	it( 'is case-insensitive on day names', function () {
		$rows = GBP_Hours_Rules::canonicalize_serp( [
			[ 'Monday'    => '8 AM–6 PM' ],
			[ 'TUESDAY'   => '8 AM–6 PM' ],
			[ 'wednesday' => '8 AM–6 PM' ],
			[ 'Thursday'  => '8 AM–6 PM' ],
			[ 'Friday'    => '8 AM–6 PM' ],
			[ 'Saturday'  => '9 AM–3 PM' ],
			[ 'Sunday'    => 'Closed' ],
		] );
		expect_equals( 7, count( $rows ) );
		expect_equals( 'MONDAY', $rows[0]['day'] );
	} );

	it( 'treats "Open 24 hours" as a closed-day rejection rather than a guess', function () {
		$week    = serp_full_week();
		$week[0] = [ 'monday' => 'Open 24 hours' ];
		expect_null( GBP_Hours_Rules::canonicalize_serp( $week ) );
	} );
} );
