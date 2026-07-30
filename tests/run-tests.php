<?php
/**
 * Run with Local by Flywheel's bundled PHP:
 *
 *   "$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" tests/run-tests.php
 */

require __DIR__ . '/bootstrap.php';

// The plugin's class files guard on ABSPATH. Satisfy it so they can be loaded
// outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/class-hours-rules.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

exit( gbp_test_summary() );
