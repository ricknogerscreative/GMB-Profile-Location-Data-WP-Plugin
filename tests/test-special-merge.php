<?php

function special_row( string $date, int $closed = 1 ): array {
	return [ 'date' => $date, 'is_closed' => $closed, 'open_time' => '', 'close_time' => '' ];
}

describe( 'merge_special_window', function () {

	// Window runs 2026-11-23 (a Monday) through 2026-11-29.
	$end = '2026-11-29';

	it( 'drops rows dated before the window', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window( [ special_row( '2026-11-20' ) ], [], $end );
		expect_equals( [], $out );
	} );

	it( 'replaces existing rows that fall inside the window', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window(
			[ special_row( '2026-11-26', 0 ) ],
			[ special_row( '2026-11-26', 1 ) ],
			$end
		);
		expect_equals( 1, count( $out ) );
		expect_equals( 1, $out[0]['is_closed'] );
	} );

	it( 'preserves a hand-entered row dated beyond the window', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window(
			[ special_row( '2026-12-25' ) ],
			[ special_row( '2026-11-26' ) ],
			$end
		);
		expect_equals( 2, count( $out ) );
		expect_equals( '2026-11-26', $out[0]['date'] );
		expect_equals( '2026-12-25', $out[1]['date'] );
	} );

	it( 'drops a row dated exactly at the window end', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window( [ special_row( '2026-11-29' ) ], [], $end );
		expect_equals( [], $out );
	} );

	it( 'keeps the first row past the window end', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window( [ special_row( '2026-11-30' ) ], [], $end );
		expect_equals( 1, count( $out ) );
		expect_equals( '2026-11-30', $out[0]['date'] );
	} );

	it( 'sorts the merged result by date', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window(
			[ special_row( '2027-01-01' ), special_row( '2026-12-25' ) ],
			[ special_row( '2026-11-26' ) ],
			$end
		);
		expect_equals( [ '2026-11-26', '2026-12-25', '2027-01-01' ], array_column( $out, 'date' ) );
	} );

	it( 'drops rows with a missing or empty date', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window(
			[ [ 'is_closed' => 1 ], special_row( '' ) ],
			[],
			$end
		);
		expect_equals( [], $out );
	} );

	it( 'reindexes the returned array', function () use ( $end ) {
		$out = GBP_Hours_Rules::merge_special_window(
			[ special_row( '2026-11-20' ), special_row( '2026-12-25' ) ],
			[],
			$end
		);
		expect_equals( [ 0 ], array_keys( $out ) );
	} );
} );
