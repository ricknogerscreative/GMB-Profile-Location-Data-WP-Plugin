<?php

describe( 'fmt_clock', function () {
	it( 'formats a morning hour', function () {
		expect_equals( '8:00 AM', GBP_Hours_Rules::fmt_clock( 8, 0 ) );
	} );
	it( 'formats an afternoon hour', function () {
		expect_equals( '6:30 PM', GBP_Hours_Rules::fmt_clock( 18, 30 ) );
	} );
	it( 'formats midnight as 12 AM', function () {
		expect_equals( '12:00 AM', GBP_Hours_Rules::fmt_clock( 0, 0 ) );
	} );
	it( 'formats noon as 12 PM', function () {
		expect_equals( '12:00 PM', GBP_Hours_Rules::fmt_clock( 12, 0 ) );
	} );
	it( 'formats the end-of-day sentinel', function () {
		expect_equals( '11:59 PM', GBP_Hours_Rules::fmt_clock( 23, 59 ) );
	} );
} );

describe( 'normalize_time', function () {
	it( 'adds missing minutes to a bare hour', function () {
		expect_equals( '11:00 AM', GBP_Hours_Rules::normalize_time( '11 AM' ) );
	} );
	it( 'passes an already-normalized time through', function () {
		expect_equals( '9:30 PM', GBP_Hours_Rules::normalize_time( '9:30 PM' ) );
	} );
	it( 'handles a non-breaking space before the meridiem', function () {
		expect_equals( '9:00 PM', GBP_Hours_Rules::normalize_time( "9\u{00A0}PM" ) );
	} );
	it( 'handles a narrow no-break space before the meridiem', function () {
		expect_equals( '9:00 PM', GBP_Hours_Rules::normalize_time( "9\u{202F}PM" ) );
	} );
	it( 'returns an empty string unchanged', function () {
		expect_equals( '', GBP_Hours_Rules::normalize_time( '' ) );
	} );
	it( 'returns unparseable input unchanged', function () {
		expect_equals( 'whenever', GBP_Hours_Rules::normalize_time( 'whenever' ) );
	} );
} );

describe( 'split_time_range', function () {
	it( 'splits on an en-dash', function () {
		expect_equals( [ '9:00 AM', '9:00 PM' ], GBP_Hours_Rules::split_time_range( '9 AM–9 PM' ) );
	} );
	it( 'splits on a hyphen with spaces', function () {
		expect_equals( [ '8:00 AM', '6:00 PM' ], GBP_Hours_Rules::split_time_range( '8:00 AM - 6:00 PM' ) );
	} );
	it( 'splits on an em-dash', function () {
		expect_equals( [ '10:00 AM', '8:00 PM' ], GBP_Hours_Rules::split_time_range( '10 AM—8 PM' ) );
	} );
	it( 'splits when non-breaking spaces surround the dash', function () {
		expect_equals( [ '9:00 AM', '9:00 PM' ], GBP_Hours_Rules::split_time_range( "9\u{202F}AM\u{202F}–\u{202F}9\u{202F}PM" ) );
	} );
	it( 'returns an empty close when there is no separator', function () {
		expect_equals( [ '8:00 AM', '' ], GBP_Hours_Rules::split_time_range( '8 AM' ) );
	} );
} );
