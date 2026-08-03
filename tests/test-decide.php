<?php

function decide_rows( string $open = '8:00 AM' ): array {
	return [ [ 'day' => 'MONDAY', 'open_time' => $open, 'close_time' => '6:00 PM', 'is_closed' => 0 ] ];
}

describe( 'decide', function () {

	it( 'skips when the fetch failed', function () {
		expect_equals( GBP_Hours_Rules::SKIP, GBP_Hours_Rules::decide( null, null, true ) );
	} );

	it( 'skips a failed fetch even when a snapshot exists', function () {
		expect_equals( GBP_Hours_Rules::SKIP, GBP_Hours_Rules::decide( null, decide_rows(), false ) );
	} );

	it( 'populates on first fetch when the field is empty', function () {
		expect_equals( GBP_Hours_Rules::POPULATE, GBP_Hours_Rules::decide( decide_rows(), null, true ) );
	} );

	it( 'adopts on first fetch when the field was filled by hand', function () {
		expect_equals( GBP_Hours_Rules::ADOPT, GBP_Hours_Rules::decide( decide_rows(), null, false ) );
	} );

	it( 'leaves manual edits alone when Google has not changed', function () {
		$rows = decide_rows();
		expect_equals( GBP_Hours_Rules::UNCHANGED, GBP_Hours_Rules::decide( $rows, $rows, false ) );
	} );

	it( 'writes when Google has changed', function () {
		expect_equals(
			GBP_Hours_Rules::WRITE,
			GBP_Hours_Rules::decide( decide_rows( '9:00 AM' ), decide_rows( '8:00 AM' ), false )
		);
	} );

	it( 'writes when Google changed even though the field is empty', function () {
		expect_equals(
			GBP_Hours_Rules::WRITE,
			GBP_Hours_Rules::decide( decide_rows( '9:00 AM' ), decide_rows( '8:00 AM' ), true )
		);
	} );

	it( 'treats an empty fetched set as valid, not as a failure', function () {
		// Special hours legitimately derive to an empty set in a normal week.
		expect_equals( GBP_Hours_Rules::POPULATE, GBP_Hours_Rules::decide( [], null, true ) );
		expect_equals( GBP_Hours_Rules::UNCHANGED, GBP_Hours_Rules::decide( [], [], false ) );
	} );

	it( 'repopulates when the snapshot matches but the field was emptied', function () {
		// An operator deleting bad rows must be able to re-sync them. Returning
		// UNCHANGED here wedges the location at empty hours forever.
		$rows = decide_rows();
		expect_equals( GBP_Hours_Rules::POPULATE, GBP_Hours_Rules::decide( $rows, $rows, true ) );
	} );

	it( 'does not rewrite an empty field when the fetch itself is empty', function () {
		// Special hours in a normal week: nothing fetched, nothing to write.
		expect_equals( GBP_Hours_Rules::UNCHANGED, GBP_Hours_Rules::decide( [], [], true ) );
	} );

	it( 'adopts an empty fetch over a hand-filled field on first sync', function () {
		expect_equals( GBP_Hours_Rules::ADOPT, GBP_Hours_Rules::decide( [], null, false ) );
	} );

	it( 'survives a snapshot round-tripped through JSON', function () {
		$rows     = decide_rows();
		$snapshot = json_decode( json_encode( $rows ), true );
		expect_equals( GBP_Hours_Rules::UNCHANGED, GBP_Hours_Rules::decide( $rows, $snapshot, false ) );
	} );
} );
