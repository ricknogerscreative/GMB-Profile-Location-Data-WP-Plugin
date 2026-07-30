<?php
/**
 * Zero-dependency test harness.
 *
 * The only unit-testable surface here is GBP_Hours_Rules — a pure static class
 * with no WordPress or Composer dependencies. Local by Flywheel's bundled PHP
 * has no phar extension, so composer and phpunit cannot run on this machine.
 * A hand-rolled runner gives identical coverage with zero toolchain.
 */

$GLOBALS['gbp_tests'] = [ 'pass' => 0, 'fail' => 0 ];

function describe( string $name, callable $fn ): void {
	echo "\n{$name}\n";
	$fn();
}

function it( string $name, callable $fn ): void {
	try {
		$fn();
		$GLOBALS['gbp_tests']['pass']++;
		echo "  PASS  {$name}\n";
	} catch ( Throwable $e ) {
		$GLOBALS['gbp_tests']['fail']++;
		echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
	}
}

function expect_equals( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Exception(
			( $msg ? $msg . ': ' : '' )
			. 'expected ' . var_export( $expected, true )
			. ', got ' . var_export( $actual, true )
		);
	}
}

function expect_null( $actual, string $msg = '' ): void {
	if ( null !== $actual ) {
		throw new Exception( ( $msg ? $msg . ': ' : '' ) . 'expected null, got ' . var_export( $actual, true ) );
	}
}

function gbp_test_summary(): int {
	$t = $GLOBALS['gbp_tests'];
	echo "\n" . str_repeat( '-', 52 ) . "\n";
	echo "{$t['pass']} passed, {$t['fail']} failed\n";
	return $t['fail'] > 0 ? 1 : 0;
}
