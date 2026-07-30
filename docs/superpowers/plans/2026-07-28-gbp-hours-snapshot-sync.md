# GBP Hours Snapshot Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Google Business Profile hours populate on location creation and stay current, without ever destroying hours a human entered by hand.

**Architecture:** All hours decisions move into `GBP_Hours_Rules`, a pure static class with zero WordPress dependencies so it can be unit-tested without a bootstrap. `GBP_Hours_Sync` does the IO — Places API (New) requests, ACF writes, post meta — and calls into the rules class for every decision. The core rule is a snapshot diff: store the hours Google last returned, write only when a new fetch differs from that snapshot. Cron is deleted; syncing becomes manual admin actions.

**Tech Stack:** PHP 8.2, WordPress, ACF Pro, Google Places API (New), SerpAPI (fallback), jQuery (admin UI).

**Spec:** `docs/superpowers/specs/2026-07-28-gbp-hours-sync-design.md`

## Global Constraints

- **PHP binary:** No `php` or `composer` on PATH. Use Local by Flywheel's bundled binary for all CLI work:
  `"$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"`
- **No Composer, no PHPUnit.** None of Local's three PHP builds have the `phar` extension. Tests use the hand-rolled runner built in Task 2. Do not add a `composer.json`.
- **Plugin version:** bump `GBP_SYNC_VERSION` to `2.1.0` in Task 10.
- **Every PHP file starts with** `defined( 'ABSPATH' ) || exit;` after its docblock — matches every existing file in `includes/`.
- **Canonical hours row shape** — used by every function that produces or compares hours. Keys and order are fixed; day order is Monday-first; times are always `g:i A`:
  ```php
  [ 'day' => 'MONDAY', 'open_time' => '8:00 AM', 'close_time' => '6:00 PM', 'is_closed' => 0 ]
  ```
  These key names match the `loc_hours` ACF repeater sub-fields exactly (`class-location-cpt.php:227-262`), so canonical rows are written to ACF unmodified.
- **Canonical special-hours row shape** — matches the `loc_special_hours` sub-fields (`class-location-cpt.php:272-298`):
  ```php
  [ 'date' => '2026-11-26', 'is_closed' => 1, 'open_time' => '', 'close_time' => '' ]
  ```
- **Hard invariant:** a failed or incomplete fetch must never write to `loc_hours` or `loc_special_hours`. Every task that touches a write path must preserve this.
- **Code style:** tabs for indentation, WordPress spacing inside parens (`foo( $bar )`), matching the existing `includes/` files.

---

### Task 1: Verify the Places API response shape

This is a gate. The special-hours derivation in Task 7 assumes `currentOpeningHours.periods[].open.date` returns a `{year, month, day}` object. If Google does not return dates there, Task 7 needs a different date anchor and this plan must be revised before that task starts. Running this first also confirms the `regularOpeningHours.periods` shape that Task 3 depends on.

**Files:**
- Create: `/private/tmp/claude-501/.../scratchpad/probe-places.sh` (throwaway — do not commit)

- [ ] **Step 1: Get the Places API key and one place ID out of the WordPress database**

The key lives in `wp_options` under `gbp_sync_places_api_key`, falling back to `gbp_sync_maps_embed_key`. A place ID lives in `wp_postmeta` under `loc_place_id`.

Ask the user to run these in the Local site shell (Local app → right-click site → Open Site Shell), or to paste the values directly:

```bash
wp option get gbp_sync_places_api_key
wp option get gbp_sync_maps_embed_key
wp post meta list $(wp post list --post_type=location --format=ids --posts_per_page=1) --keys=loc_place_id
```

- [ ] **Step 2: Probe the live API**

Substitute the real key and place ID:

```bash
curl -s "https://places.googleapis.com/v1/places/PLACE_ID_HERE" \
  -H "X-Goog-Api-Key: API_KEY_HERE" \
  -H "X-Goog-FieldMask: regularOpeningHours,currentOpeningHours,businessStatus" \
  | python3 -m json.tool
```

- [ ] **Step 3: Check the response against three assumptions**

1. `regularOpeningHours.periods[]` entries have `open.day` (int, 0=Sunday), `open.hour`, `open.minute`, and usually `close.{day,hour,minute}`.
2. `currentOpeningHours.periods[].open` contains a `date` object with `year`, `month`, `day`.
3. `businessStatus` is one of `OPERATIONAL`, `CLOSED_TEMPORARILY`, `CLOSED_PERMANENTLY`.

If assumption 1 or 3 fails, stop and report — Tasks 3 and 8 need revising.
If assumption 2 fails, Tasks 1–6 and 8–12 still proceed unchanged; stop before Task 7 and report so the special-hours derivation can be redesigned.

- [ ] **Step 4: Record the finding**

Append a short note to the spec under the "Assumption requiring verification" heading stating what the live response actually contained, then commit:

```bash
git add docs/superpowers/specs/2026-07-28-gbp-hours-sync-design.md
git commit -m "docs: record verified Places API response shape"
```

---

### Task 2: Test harness and time helpers

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/run-tests.php`
- Create: `tests/test-time-helpers.php`
- Create: `includes/class-hours-rules.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `GBP_Hours_Rules::DAYS` — `['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY']`
  - `GBP_Hours_Rules::fmt_clock( int $hour, int $minute ): string`
  - `GBP_Hours_Rules::normalize_time( string $time ): string`
  - `GBP_Hours_Rules::split_time_range( string $range ): array` — returns `[ open, close ]`
  - Test functions `describe()`, `it()`, `expect_equals()`, `expect_null()`

- [ ] **Step 1: Write the test harness**

Create `tests/bootstrap.php`:

```php
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
```

Create `tests/run-tests.php`:

```php
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
```

- [ ] **Step 2: Write the failing tests**

Create `tests/test-time-helpers.php`:

```php
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
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: PHP fatal error — `Failed to open stream: includes/class-hours-rules.php`.

- [ ] **Step 4: Write the implementation**

Create `includes/class-hours-rules.php`:

```php
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
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `16 passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add tests/ includes/class-hours-rules.php
git commit -m "test: zero-dependency harness and hours time helpers

Local's bundled PHP has no phar extension, so composer and phpunit
cannot run on this machine. GBP_Hours_Rules is pure and dependency-free,
so a plain runner gives the same coverage with no toolchain.

Time parsing handles U+00A0 and U+202F explicitly — PCRE's \\s does not
match either under /u without PCRE_UCP, which is why the previous
en-dash splitting left garbage in the close side."
```

---

### Task 3: `canonicalize_places()`

**Files:**
- Modify: `includes/class-hours-rules.php`
- Create: `tests/test-canonicalize-places.php`

**Interfaces:**
- Consumes: `GBP_Hours_Rules::DAYS`, `GBP_Hours_Rules::fmt_clock()`
- Produces:
  - `GBP_Hours_Rules::canonicalize_places( array $periods ): ?array` — canonical rows, or `null` when the input yields nothing usable
  - `GBP_Hours_Rules::build_rows( array $by_day ): array` (private) — shared by Task 4

- [ ] **Step 1: Write the failing tests**

Create `tests/test-canonicalize-places.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: 8 failures, each `Call to undefined method GBP_Hours_Rules::canonicalize_places()`.

- [ ] **Step 3: Write the implementation**

Add to `includes/class-hours-rules.php`, inside the class, after the `SPACE` constant:

```php
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
```

And add these methods to the class:

```php
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
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `24 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add tests/test-canonicalize-places.php includes/class-hours-rules.php
git commit -m "feat: canonicalize Places API periods to hours rows"
```

---

### Task 4: `canonicalize_serp()` and the completeness guard

This is what closes the fake-closure bug. The old code emitted `is_closed = 1` for any weekday a partial scrape did not mention, overwriting good hours with fabricated closures. Here an incomplete scrape is rejected outright.

**Files:**
- Modify: `includes/class-hours-rules.php`
- Create: `tests/test-canonicalize-serp.php`

**Interfaces:**
- Consumes: `GBP_Hours_Rules::DAYS`, `split_time_range()`
- Produces: `GBP_Hours_Rules::canonicalize_serp( $raw ): ?array`

- [ ] **Step 1: Write the failing tests**

Create `tests/test-canonicalize-serp.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: 11 failures, `Call to undefined method GBP_Hours_Rules::canonicalize_serp()`.

- [ ] **Step 3: Write the implementation**

Add these methods to `GBP_Hours_Rules`:

```php
	/**
	 * Convert a SerpAPI hours payload to canonical rows, or null.
	 *
	 * SerpAPI is a scrape of the public Maps panel and returns hours in several
	 * shapes, sometimes partially. Anything short of a complete, fully parseable
	 * seven-day week is rejected: writing a partial result would mark the
	 * unseen days closed, which is how correct hours were previously destroyed.
	 *
	 * @param mixed $raw Whatever sat under the response's hours key.
	 */
	public static function canonicalize_serp( $raw ): ?array {
		$by_day = self::flatten_serp( $raw );
		if ( null === $by_day ) {
			return null;
		}

		$rows = [];
		foreach ( self::DAYS as $label ) {
			if ( ! array_key_exists( $label, $by_day ) ) {
				return null; // Incomplete scrape.
			}

			$range = (string) $by_day[ $label ];

			if ( '' === $range || false !== stripos( $range, 'closed' ) ) {
				$rows[] = [ 'day' => $label, 'open_time' => '', 'close_time' => '', 'is_closed' => 1 ];
				continue;
			}

			[ $open, $close ] = self::split_time_range( $range );
			if ( '' === $open || '' === $close ) {
				return null; // Unparseable range — reject rather than write half a row.
			}

			$rows[] = [ 'day' => $label, 'open_time' => $open, 'close_time' => $close, 'is_closed' => 0 ];
		}

		return $rows;
	}

	/**
	 * Reduce any of SerpAPI's hours shapes to [ DAY_LABEL => range string ].
	 */
	private static function flatten_serp( $raw ): ?array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		// Shape: { timetable: { monday: … } }
		if ( isset( $raw['timetable'] ) && is_array( $raw['timetable'] ) ) {
			return self::flatten_day_map( $raw['timetable'] );
		}

		// Shape: { monday: …, tuesday: … }
		$lower = array_change_key_case( $raw, CASE_LOWER );
		if ( isset( $lower['monday'] ) || isset( $lower['sunday'] ) ) {
			return self::flatten_day_map( $raw );
		}

		$first = reset( $raw );

		// Shape: [ { sunday: "9 AM–9 PM" }, { monday: "10 AM–8 PM" }, … ]
		if ( is_array( $first ) ) {
			$merged = [];
			foreach ( $raw as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( $item as $day => $range ) {
					$merged[ $day ] = $range;
				}
			}
			return $merged ? self::flatten_day_map( $merged ) : null;
		}

		// Shape: [ "Monday: 8:00 AM–6:00 PM", … ]
		if ( is_string( $first ) ) {
			$merged = [];
			foreach ( $raw as $line ) {
				if ( is_string( $line ) && preg_match( '/^\s*(\w+)\s*:\s*(.+)$/u', $line, $m ) ) {
					$merged[ $m[1] ] = trim( $m[2] );
				}
			}
			return $merged ? self::flatten_day_map( $merged ) : null;
		}

		return null;
	}

	/**
	 * Normalize day keys to canonical labels and reduce each value to a single
	 * range string. Values arrive as a string, an array of strings, or an array
	 * of {open,close} objects.
	 */
	private static function flatten_day_map( array $map ): ?array {
		$out = [];

		foreach ( $map as $day => $value ) {
			$label = strtoupper( trim( (string) $day ) );
			if ( ! in_array( $label, self::DAYS, true ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$out[ $label ] = $value;
				continue;
			}

			if ( ! is_array( $value ) || empty( $value ) ) {
				continue;
			}

			$first = reset( $value );

			if ( is_string( $first ) ) {
				$out[ $label ] = $first;
			} elseif ( is_array( $first ) ) {
				$open  = (string) ( $first['open'] ?? $first['opens'] ?? '' );
				$close = (string) ( $first['close'] ?? $first['closes'] ?? '' );
				$out[ $label ] = ( '' !== $open && '' !== $close ) ? $open . ' - ' . $close : 'Closed';
			}
		}

		return $out ?: null;
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `35 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add tests/test-canonicalize-serp.php includes/class-hours-rules.php
git commit -m "feat: canonicalize SerpAPI hours with a completeness guard

Consolidates four ad-hoc parsers into one function that returns
canonical rows or null. Anything short of a fully parseable seven-day
week is rejected — the old code emitted is_closed=1 for days a partial
scrape never mentioned, overwriting correct hours with invented
closures."
```

---

### Task 5: `decide()` — the snapshot rule

**Files:**
- Modify: `includes/class-hours-rules.php`
- Create: `tests/test-decide.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `GBP_Hours_Rules::POPULATE` = `'populate'`
  - `GBP_Hours_Rules::ADOPT` = `'adopt'`
  - `GBP_Hours_Rules::WRITE` = `'write'`
  - `GBP_Hours_Rules::UNCHANGED` = `'unchanged'`
  - `GBP_Hours_Rules::SKIP` = `'skip'`
  - `GBP_Hours_Rules::decide( ?array $fetched, ?array $snapshot, bool $current_is_empty ): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/test-decide.php`:

```php
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

	it( 'survives a snapshot round-tripped through JSON', function () {
		$rows     = decide_rows();
		$snapshot = json_decode( json_encode( $rows ), true );
		expect_equals( GBP_Hours_Rules::UNCHANGED, GBP_Hours_Rules::decide( $rows, $snapshot, false ) );
	} );
} );
```

The JSON round-trip test matters: the snapshot is stored as a JSON string in post meta and decoded on read. `decide()` compares with `===`, which is type-strict, so `is_closed => 0` must survive as an int rather than coming back as a string.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: 9 failures, `Undefined constant GBP_Hours_Rules::SKIP`.

- [ ] **Step 3: Write the implementation**

Add to `GBP_Hours_Rules`, immediately after the `DAYS` constant:

```php
	/** decide() outcomes. */
	public const POPULATE  = 'populate';
	public const ADOPT     = 'adopt';
	public const WRITE     = 'write';
	public const UNCHANGED = 'unchanged';
	public const SKIP      = 'skip';
```

And add this method:

```php
	/**
	 * Resolve manual edits against a Google-side change.
	 *
	 * The snapshot holds whatever Google last returned. Comparing a new fetch
	 * against it — rather than against what is currently in the field — is what
	 * lets a hand-edited value survive indefinitely while Google is unchanged,
	 * and still be overwritten the moment Google genuinely moves.
	 *
	 * A null fetch means the request failed or the result was rejected, and can
	 * never write. An empty array is a valid result: a normal week derives no
	 * special hours at all.
	 *
	 * @param ?array $fetched          Canonical rows from Google, or null on failure.
	 * @param ?array $snapshot         What Google returned last time, or null if never.
	 * @param bool   $current_is_empty Whether the target field is currently empty.
	 */
	public static function decide( ?array $fetched, ?array $snapshot, bool $current_is_empty ): string {
		if ( null === $fetched ) {
			return self::SKIP;
		}

		if ( null === $snapshot ) {
			// First successful fetch. If someone already filled the field in by
			// hand, adopt their value as the baseline instead of clobbering it.
			return $current_is_empty ? self::POPULATE : self::ADOPT;
		}

		return $fetched === $snapshot ? self::UNCHANGED : self::WRITE;
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `44 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add tests/test-decide.php includes/class-hours-rules.php
git commit -m "feat: snapshot-diff decision rule for hours writes

Compares a new fetch against what Google last returned rather than
against the current field value. A hand-edited value survives until
Google itself changes; the adopt branch seeds the snapshot on first run
so existing manual entries are not destroyed on deploy."
```

---

### Task 6: `merge_special_window()`

Built before `derive_special()` because it is independent of the Task 1 date-shape finding, so it can land even if special-hours derivation needs redesigning.

**Files:**
- Modify: `includes/class-hours-rules.php`
- Create: `tests/test-special-merge.php`

**Interfaces:**
- Consumes: nothing
- Produces: `GBP_Hours_Rules::merge_special_window( array $existing, array $derived, string $window_end ): array`

Note there is no `$today` parameter. Rows dated before today and rows inside the window are both dropped, and `window_end` is always the later of the two bounds, so a single `<=` comparison expresses the whole rule. Taking `$today` as well would leave a redundant condition and an argument that changes nothing.

- [ ] **Step 1: Write the failing tests**

Create `tests/test-special-merge.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: 8 failures, `Call to undefined method GBP_Hours_Rules::merge_special_window()`.

- [ ] **Step 3: Write the implementation**

Add to `GBP_Hours_Rules`:

```php
	/**
	 * Fold derived special hours into the existing set, scoped to a date window.
	 *
	 * Rows dated beyond window_end are hand-entered future closures and are kept
	 * verbatim. Everything at or before it is dropped — either it is in the past,
	 * or it sits inside the window the derived rows now own. Scoping by date is
	 * what removes the need for a "source" sub-field on the repeater.
	 *
	 * All dates are Y-m-d, so plain string comparison orders them correctly.
	 */
	public static function merge_special_window( array $existing, array $derived, string $window_end ): array {
		$kept = [];

		foreach ( $existing as $row ) {
			$date = (string) ( $row['date'] ?? '' );

			if ( '' === $date || $date <= $window_end ) {
				// Malformed, past, or inside the derived window.
				continue;
			}

			$kept[] = $row;
		}

		$merged = array_merge( $kept, $derived );
		usort( $merged, static fn( $a, $b ) => strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) ) );

		return array_values( $merged );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `52 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add tests/test-special-merge.php includes/class-hours-rules.php
git commit -m "feat: window-scoped merge for derived special hours"
```

---

### Task 7: `derive_special()`

**Gate:** do not start until Task 1 confirmed that `currentOpeningHours.periods[].open.date` returns a `{year, month, day}` object. If it does not, stop and report — this task needs redesigning.

**Files:**
- Modify: `includes/class-hours-rules.php`
- Create: `tests/test-derive-special.php`

**Interfaces:**
- Consumes: `GBP_Hours_Rules::DAYS`, `fmt_clock()`
- Produces: `GBP_Hours_Rules::derive_special( array $regular, array $current_periods, string $today ): array`

- [ ] **Step 1: Write the failing tests**

Create `tests/test-derive-special.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: 7 failures, `Call to undefined method GBP_Hours_Rules::derive_special()`.

- [ ] **Step 3: Write the implementation**

Add to `GBP_Hours_Rules`:

```php
	/**
	 * Derive dated special-hours rows by diffing current against regular hours.
	 *
	 * Places API returns currentOpeningHours for the next seven days with a
	 * concrete date on each period. Any date whose actual hours differ from the
	 * regular hours for that weekday is a holiday or short-notice override.
	 *
	 * The whole seven-day window is walked rather than only the dates present in
	 * $current_periods, so a day Google omits registers as a closure instead of
	 * silently disappearing.
	 *
	 * @param array  $regular         Canonical regular-hours rows.
	 * @param array  $current_periods Raw currentOpeningHours.periods.
	 * @param string $today           Y-m-d for the first day of the window.
	 */
	public static function derive_special( array $regular, array $current_periods, string $today ): array {
		// Regular hours as comparable tokens, keyed by weekday label.
		$expected_by_day = [];
		foreach ( $regular as $row ) {
			$label = (string) ( $row['day'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$expected_by_day[ $label ][] = ! empty( $row['is_closed'] )
				? 'CLOSED'
				: $row['open_time'] . '|' . $row['close_time'];
		}

		// Actual hours as the same tokens, keyed by concrete date.
		$actual_by_date = [];
		foreach ( $current_periods as $period ) {
			$date = self::period_date( $period['open'] ?? [] );
			if ( null === $date ) {
				continue;
			}

			$open = self::fmt_clock(
				(int) ( $period['open']['hour'] ?? 0 ),
				(int) ( $period['open']['minute'] ?? 0 )
			);

			$close = isset( $period['close'] )
				? self::fmt_clock( (int) ( $period['close']['hour'] ?? 0 ), (int) ( $period['close']['minute'] ?? 0 ) )
				: self::fmt_clock( 23, 59 );

			$actual_by_date[ $date ][] = $open . '|' . $close;
		}

		$rows = [];

		for ( $offset = 0; $offset < 7; $offset++ ) {
			$date  = date( 'Y-m-d', strtotime( $today . ' +' . $offset . ' day' ) );
			$label = self::DAYS[ (int) date( 'N', strtotime( $date ) ) - 1 ];

			$actual   = $actual_by_date[ $date ] ?? [ 'CLOSED' ];
			$expected = $expected_by_day[ $label ] ?? [ 'CLOSED' ];

			sort( $actual );
			sort( $expected );

			if ( $actual === $expected ) {
				continue; // Matches the regular week — not a special day.
			}

			if ( [ 'CLOSED' ] === $actual ) {
				$rows[] = [ 'date' => $date, 'is_closed' => 1, 'open_time' => '', 'close_time' => '' ];
				continue;
			}

			foreach ( $actual as $token ) {
				[ $open, $close ] = explode( '|', $token );
				$rows[] = [ 'date' => $date, 'is_closed' => 0, 'open_time' => $open, 'close_time' => $close ];
			}
		}

		return $rows;
	}

	/**
	 * Extract Y-m-d from a Places period's open.date object, or null.
	 */
	private static function period_date( array $open ): ?string {
		$date = $open['date'] ?? null;
		if ( ! is_array( $date ) || ! isset( $date['year'], $date['month'], $date['day'] ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day'] );
	}
```

Note `date( 'N' )` returns 1 for Monday through 7 for Sunday, and `self::DAYS` is Monday-first, so `DAYS[ N - 1 ]` maps correctly.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `59 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add tests/test-derive-special.php includes/class-hours-rules.php
git commit -m "feat: derive dated special hours from Places currentOpeningHours"
```

---

### Task 8: `GBP_Hours_Sync` — the IO layer

**Files:**
- Create: `includes/class-hours-sync.php`

**Interfaces:**
- Consumes: all of `GBP_Hours_Rules`; the `GBP_SYNC_POST_TYPE` constant from `gbp-location-sync.php:19`
- Produces:
  - `GBP_Hours_Sync::__construct()`
  - `GBP_Hours_Sync::is_configured(): bool`
  - `GBP_Hours_Sync::sync_all(): array` — aggregate result
  - `GBP_Hours_Sync::sync_location( int $post_id, $serp_hours_raw = null ): array` — per-location result
  - `GBP_Hours_Sync::staleness( int $post_id ): array` (static) — `[ 'stale' => bool, 'label' => string, 'source' => string, 'error' => string ]`
  - `GBP_Hours_Sync::STALE_AFTER` — `7 * DAY_IN_SECONDS`

Per-location result shape, relied on by Tasks 9 and 11:

```php
[
    'post_id' => 412,
    'title'   => 'Milwaukee',
    'hours'   => 'write',      // populate|adopt|write|unchanged|skip|error
    'special' => 'unchanged',
    'source'  => 'places',     // places|serpapi|null
    'error'   => null,
]
```

Aggregate result shape:

```php
[
    'checked'   => 23,
    'populated' => 2,
    'adopted'   => 6,
    'written'   => 3,
    'unchanged' => 11,
    'skipped'   => 1,
    'errors'    => [ 'Cincinnati: Places API HTTP 403 — …' ],
    'locations' => [ /* per-location results */ ],
]
```

This class does HTTP and database work, so it has no unit tests — it is covered by the manual verification in Task 12.

- [ ] **Step 1: Write the class**

Create `includes/class-hours-sync.php`:

```php
<?php
/**
 * Hours sync — IO and orchestration.
 *
 * Fetches from Places API (New), optionally falling back to a SerpAPI payload
 * supplied by the caller, and runs every write through
 * GBP_Hours_Rules::decide(). A failed or incomplete fetch never writes.
 */
defined( 'ABSPATH' ) || exit;

class GBP_Hours_Sync {

	private const PLACES_URL = 'https://places.googleapis.com/v1/places/';
	private const FIELD_MASK = 'regularOpeningHours,currentOpeningHours,businessStatus';

	/** Hours older than this are flagged in the admin list. */
	public const STALE_AFTER = 7 * DAY_IN_SECONDS;

	private const META_SNAPSHOT         = '_gbp_hours_snapshot';
	private const META_SPECIAL_SNAPSHOT = '_gbp_special_snapshot';
	private const META_FETCHED_AT       = '_gbp_hours_fetched_at';
	private const META_SOURCE           = '_gbp_hours_source';
	private const META_LAST_ERROR       = '_gbp_hours_last_error';

	private string $key;
	private ?string $last_error = null;

	public function __construct() {
		$key = get_option( 'gbp_sync_places_api_key', '' );
		if ( ! $key ) {
			$key = get_option( 'gbp_sync_maps_embed_key', '' );
		}
		$this->key = (string) $key;
	}

	public function is_configured(): bool {
		return '' !== $this->key;
	}

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Run the hours sync across every location that has a Place ID.
	 *
	 * Places API only — no SerpAPI fallback. This is the cheap, frequently-run
	 * action and must not silently spend SerpAPI credits.
	 */
	public function sync_all(): array {
		$posts = get_posts( [
			'post_type'      => GBP_SYNC_POST_TYPE,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [ [
				'key'     => 'loc_place_id',
				'value'   => '',
				'compare' => '!=',
			] ],
		] );

		$agg = [
			'checked'   => 0,
			'populated' => 0,
			'adopted'   => 0,
			'written'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'errors'    => [],
			'locations' => [],
		];

		foreach ( $posts as $post ) {
			$result = $this->sync_location( $post->ID );

			$agg['checked']++;
			$agg['locations'][] = $result;

			switch ( $result['hours'] ) {
				case GBP_Hours_Rules::POPULATE:
					$agg['populated']++;
					break;
				case GBP_Hours_Rules::ADOPT:
					$agg['adopted']++;
					break;
				case GBP_Hours_Rules::WRITE:
					$agg['written']++;
					break;
				case GBP_Hours_Rules::UNCHANGED:
					$agg['unchanged']++;
					break;
				default:
					$agg['skipped']++;
			}

			if ( $result['error'] ) {
				$agg['errors'][] = $result['title'] . ': ' . $result['error'];
			}
		}

		update_option( 'gbp_sync_hours_last_run', current_time( 'mysql' ) );
		return $agg;
	}

	/**
	 * Sync hours for one location.
	 *
	 * @param int   $post_id
	 * @param mixed $serp_hours_raw Optional SerpAPI hours payload to fall back
	 *                              on when Places API yields nothing. Passing it
	 *                              costs no extra credit because the caller has
	 *                              already made the SerpAPI request.
	 */
	public function sync_location( int $post_id, $serp_hours_raw = null ): array {
		$this->last_error = null;

		$result = [
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'hours'   => GBP_Hours_Rules::SKIP,
			'special' => GBP_Hours_Rules::SKIP,
			'source'  => null,
			'error'   => null,
		];

		$place_id = get_field( 'loc_place_id', $post_id );
		if ( ! $place_id ) {
			$result['error'] = 'No Google Place ID set.';
			return $this->record_error( $post_id, $result );
		}

		$places  = $this->fetch_places( (string) $place_id );
		$regular = null;

		if ( null !== $places ) {
			$this->write_status( $post_id, $places['status'] );
			$regular = GBP_Hours_Rules::canonicalize_places( $places['regular_periods'] );
			if ( null !== $regular ) {
				$result['source'] = 'places';
			} else {
				$this->last_error = 'Places API returned no usable opening hours.';
			}
		}

		// Fall back to the caller's SerpAPI payload only when one was supplied.
		if ( null === $regular && null !== $serp_hours_raw ) {
			$regular = GBP_Hours_Rules::canonicalize_serp( $serp_hours_raw );
			if ( null !== $regular ) {
				$result['source'] = 'serpapi';
				$this->last_error = null;
			}
		}

		if ( null === $regular ) {
			$result['error'] = $this->last_error ?: 'No usable hours from Places API or SerpAPI.';
			return $this->record_error( $post_id, $result );
		}

		delete_post_meta( $post_id, self::META_LAST_ERROR );
		update_post_meta( $post_id, self::META_FETCHED_AT, current_time( 'mysql' ) );
		update_post_meta( $post_id, self::META_SOURCE, $result['source'] );

		$result['hours'] = $this->apply_regular( $post_id, $regular );

		// Special hours are derivable only from the Places response.
		if ( null !== $places && 'places' === $result['source'] ) {
			$result['special'] = $this->apply_special( $post_id, $regular, $places['current_periods'] );
		}

		return $result;
	}

	/**
	 * Hours freshness for one location, for the admin list column.
	 */
	public static function staleness( int $post_id ): array {
		$source = (string) get_post_meta( $post_id, self::META_SOURCE, true );
		$error  = (string) get_post_meta( $post_id, self::META_LAST_ERROR, true );
		$hours  = get_field( 'loc_hours', $post_id );

		if ( empty( $hours ) ) {
			return [ 'stale' => true, 'label' => 'No hours', 'source' => $source, 'error' => $error ];
		}

		$fetched = (string) get_post_meta( $post_id, self::META_FETCHED_AT, true );
		if ( '' === $fetched ) {
			return [ 'stale' => true, 'label' => 'Never synced', 'source' => $source, 'error' => $error ];
		}

		$then = strtotime( $fetched );
		$now  = (int) current_time( 'timestamp' );

		return [
			'stale'  => ( $now - $then ) > self::STALE_AFTER,
			'label'  => human_time_diff( $then, $now ) . ' ago',
			'source' => $source,
			'error'  => $error,
		];
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	private function apply_regular( int $post_id, array $fetched ): string {
		$current = get_field( 'loc_hours', $post_id );
		$action  = GBP_Hours_Rules::decide(
			$fetched,
			$this->read_snapshot( $post_id, self::META_SNAPSHOT ),
			empty( $current )
		);

		if ( GBP_Hours_Rules::POPULATE === $action || GBP_Hours_Rules::WRITE === $action ) {
			update_field( 'loc_hours', $fetched, $post_id );
		}

		if ( GBP_Hours_Rules::SKIP !== $action ) {
			update_post_meta( $post_id, self::META_SNAPSHOT, wp_json_encode( $fetched ) );
		}

		return $action;
	}

	private function apply_special( int $post_id, array $regular, array $current_periods ): string {
		$today      = current_time( 'Y-m-d' );
		$window_end = date( 'Y-m-d', strtotime( $today . ' +6 day' ) );

		$derived = GBP_Hours_Rules::derive_special( $regular, $current_periods, $today );

		$current = get_field( 'loc_special_hours', $post_id );
		$current = is_array( $current ) ? $current : [];

		$action = GBP_Hours_Rules::decide(
			$derived,
			$this->read_snapshot( $post_id, self::META_SPECIAL_SNAPSHOT ),
			empty( $current )
		);

		if ( GBP_Hours_Rules::POPULATE === $action || GBP_Hours_Rules::WRITE === $action ) {
			$merged = GBP_Hours_Rules::merge_special_window( $current, $derived, $window_end );
			update_field( 'loc_special_hours', $merged, $post_id );
		}

		if ( GBP_Hours_Rules::SKIP !== $action ) {
			update_post_meta( $post_id, self::META_SPECIAL_SNAPSHOT, wp_json_encode( $derived ) );
		}

		return $action;
	}

	/**
	 * Business status is machine-owned and drives site-wide closure banners, so
	 * it is written on every successful fetch rather than snapshot-gated.
	 *
	 * CLOSED_PERMANENTLY deliberately does not unpublish the post — taking a
	 * live location page down is a decision for a human.
	 */
	private function write_status( int $post_id, string $business_status ): void {
		$map = [
			'OPERATIONAL'        => [ 'OPEN', 0 ],
			'CLOSED_TEMPORARILY' => [ 'CLOSED_TEMPORARILY', 1 ],
			'CLOSED_PERMANENTLY' => [ 'CLOSED_PERMANENTLY', 0 ],
		];

		if ( ! isset( $map[ $business_status ] ) ) {
			return;
		}

		[ $status, $temp_closed ] = $map[ $business_status ];
		update_field( 'loc_status', $status, $post_id );
		update_field( 'loc_temp_closed', $temp_closed, $post_id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return ?array [ regular_periods, current_periods, status ] or null on failure.
	 */
	private function fetch_places( string $place_id ): ?array {
		if ( ! $this->is_configured() ) {
			$this->last_error = 'No Places API key configured.';
			return null;
		}

		$response = wp_remote_get( self::PLACES_URL . rawurlencode( $place_id ), [
			'timeout' => 20,
			'headers' => [
				'X-Goog-Api-Key'   => $this->key,
				'X-Goog-FieldMask' => self::FIELD_MASK,
			],
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = 'Places API: ' . $response->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : [];

		if ( 200 !== $code ) {
			$this->last_error = 'Places API HTTP ' . $code . ' — ' . ( $body['error']['message'] ?? 'unknown error' );
			return null;
		}

		return [
			'regular_periods' => $body['regularOpeningHours']['periods'] ?? [],
			'current_periods' => $body['currentOpeningHours']['periods'] ?? [],
			'status'          => (string) ( $body['businessStatus'] ?? '' ),
		];
	}

	private function read_snapshot( int $post_id, string $meta_key ): ?array {
		$raw = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function record_error( int $post_id, array $result ): array {
		update_post_meta( $post_id, self::META_LAST_ERROR, $result['error'] );
		$result['hours'] = 'error';
		return $result;
	}
}
```

- [ ] **Step 2: Check the file parses**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -l includes/class-hours-sync.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the unit tests to confirm nothing regressed**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" tests/run-tests.php
```

Expected: `59 passed, 0 failed`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-hours-sync.php
git commit -m "feat: GBP_Hours_Sync — Places-first hours IO layer

Places API (New) becomes the primary hours source; SerpAPI hours are
accepted only as a fallback payload passed in by the caller, so the
cheap hours-only action never spends SerpAPI credits. businessStatus
replaces the open_state string sniff."
```

---

### Task 9: Wire the hours sync into `GBP_Serp_Sync` and delete the old hours code

**Files:**
- Modify: `includes/class-serp-sync.php`

**Interfaces:**
- Consumes: `GBP_Hours_Sync::sync_location()`
- Produces: no new public interface — `GBP_Serp_Sync::sync_all()` and `sync_single()` keep their existing signatures and result shapes

- [ ] **Step 1: Replace the hours and status block in `map_to_acf()`**

Delete lines 337-384 (everything from the `// Status — temp closure is the key use case.` comment through the closing brace of the `if ( ! $hours_mapped && $pid )` block) and replace with:

```php
		// Hours and status are owned by GBP_Hours_Sync. The SerpAPI hours payload
		// is handed over as a fallback so it costs no extra credit if Places API
		// comes back empty.
		$ext       = $place['extensions'] ?? [];
		$hours_raw = $place['hours'] ?? $place['operating_hours']
			?? ( is_array( $ext ) ? ( $ext['hours'] ?? $ext['operating_hours'] ?? null ) : null );

		$hours_result = ( new GBP_Hours_Sync() )->sync_location( $post_id, $hours_raw );

		// Places API owns status when it answered. When it did not, fall back to
		// what SerpAPI reported so a Places outage cannot leave a closed location
		// showing as open.
		if ( 'places' !== $hours_result['source'] ) {
			$temp_closed = (bool) ( $place['temporarily_closed'] ?? false );
			if ( ! $temp_closed && isset( $place['open_state'] ) ) {
				$temp_closed = false !== stripos( $place['open_state'], 'temporarily' );
			}
			update_field( 'loc_temp_closed', $temp_closed ? 1 : 0, $post_id );
			update_field( 'loc_status', $temp_closed ? 'CLOSED_TEMPORARILY' : 'OPEN', $post_id );
		}
```

- [ ] **Step 2: Delete the superseded methods and constants**

Remove these members from `GBP_Serp_Sync` entirely:

| Member | Current lines |
|---|---|
| `sync_missing_hours()` | 73-114 |
| `HOURS_RETRY_ATTEMPTS`, `HOURS_RETRY_DELAY_US` | 241-242 |
| `map_hours()` | 457-504 |
| `map_hours_from_strings()` | 509-537 |
| `map_hours_from_keyed_objects()` | 543-579 |
| `map_hours_from_places_api()` | 596-635 |
| `map_hours_from_places_periods()` | 644-680 |
| `fmt_clock()` | 685-687 |
| `split_time_range()` | 689-695 |
| `normalize_time()` | 704-712 |

- [ ] **Step 3: Collapse the retry loop in `get_place()`**

Replace the whole `for` loop body (lines 252-289) so the method makes exactly one request. Places API is primary now; the retries existed only to coax hours out of the scrape.

```php
	private function get_place( string $place_id ): array {
		$url = add_query_arg( [
			'engine'   => 'google_maps',
			'type'     => 'place',
			'place_id' => $place_id,
			'api_key'  => $this->api_key,
		], self::API_URL );

		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			error_log( 'GBP SerpSync error: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( 200 !== $code ) {
			error_log( 'GBP SerpSync HTTP ' . $code . ': ' . wp_json_encode( $body ) );
			return [ 'error' => 'HTTP ' . $code . ' — ' . ( $body['error'] ?? 'Unknown' ) ];
		}

		return $body['place_results'] ?? [];
	}
```

- [ ] **Step 4: Delete the debug log spam**

Remove these `error_log()` calls, which dump the entire API response on every sync:
- lines 213-214 (`GBP place_keys`, `GBP place_full`)
- lines 350-351 (`GBP hours_raw`, `GBP extensions`)

Keep the `set_transient( 'gbp_serp_debug_' . $post_id, … )` block at 217-224 — the per-location admin sync surfaces it and it stays useful for diagnosing scrape shape.

- [ ] **Step 5: Check the file parses**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -l includes/class-serp-sync.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Confirm no dangling references to the deleted methods**

```bash
grep -rn "map_hours\|sync_missing_hours\|HOURS_RETRY\|normalize_time\|split_time_range\|fmt_clock" \
  --include="*.php" --include="*.js" . | grep -v "docs/\|class-hours-rules.php\|tests/"
```

Expected: no output. Any hit outside the rules class, the tests, and the docs is a live call to something that no longer exists.

- [ ] **Step 7: Commit**

```bash
git add includes/class-serp-sync.php
git commit -m "refactor: delegate hours to GBP_Hours_Sync

Removes five hours parsers, the three-attempt retry loop, and the
full-response debug logging. The \$hours_mapped flag is gone with them —
it reported success when zero rows were written, which is what gated off
the Places API fallback and left hours empty on create.

SerpAPI status is kept as a fallback so a Places outage cannot leave a
closed location showing as open."
```

---

### Task 10: Delete the dead GBP path and the cron

**Files:**
- Delete: `includes/class-sync-manager.php`
- Delete: `includes/class-gbp-api.php`
- Delete: `includes/class-cron.php`
- Modify: `gbp-location-sync.php`

**Interfaces:**
- Consumes: nothing
- Produces: `gbp_sync_maybe_upgrade()` — one-time cleanup on version change

- [ ] **Step 1: Delete the three dead files**

```bash
git rm includes/class-sync-manager.php includes/class-gbp-api.php includes/class-cron.php
```

- [ ] **Step 2: Update the plugin bootstrap**

In `gbp-location-sync.php`:

Bump the version at line 6 and line 13 to `2.1.0`.

Replace the require loop at lines 22-29 with:

```php
foreach ( [
	'class-location-cpt',
	'class-hours-rules',
	'class-hours-sync',
	'class-serp-sync',
	'class-admin',
] as $file ) {
	require_once GBP_SYNC_DIR . 'includes/' . $file . '.php';
}
```

Replace `gbp_sync_boot()` at lines 46-51 with:

```php
function gbp_sync_boot(): void {
	GBP_Location_CPT::instance();
	GBP_Admin::instance();
}
add_action( 'plugins_loaded', 'gbp_sync_boot' );
```

Replace the activation and deactivation block at lines 53-63 with:

```php
register_activation_hook( __FILE__, 'gbp_sync_activate' );

function gbp_sync_activate(): void {
	flush_rewrite_rules();
}

/**
 * One-time cleanup when the plugin version changes.
 *
 * Syncing is manual as of 2.1.0. Without this, the gbp_sync_cron_run event
 * scheduled by earlier versions stays in the options table firing an action
 * nothing handles.
 */
function gbp_sync_maybe_upgrade(): void {
	if ( get_option( 'gbp_sync_version' ) === GBP_SYNC_VERSION ) {
		return;
	}
	wp_clear_scheduled_hook( 'gbp_sync_cron_run' );
	delete_option( 'gbp_sync_frequency' );
	update_option( 'gbp_sync_version', GBP_SYNC_VERSION );
}
add_action( 'plugins_loaded', 'gbp_sync_maybe_upgrade' );
```

Note the deactivation hook is dropped entirely — there is no longer anything to unschedule.

- [ ] **Step 3: Check the file parses and nothing references the deleted classes**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -l gbp-location-sync.php
grep -rn "GBP_Cron\|GBP_Sync_Manager\|GBP_API\|gbp_sync_deactivate" --include="*.php" --include="*.js" . | grep -v docs/
```

Expected: `No syntax errors detected`, and the grep returns hits only in `templates/admin-page.php:11` — which Task 11 removes.

- [ ] **Step 4: Commit**

```bash
git add -A gbp-location-sync.php includes/
git commit -m "refactor: delete dead GBP OAuth path and the cron

class-sync-manager.php and class-gbp-api.php were never in the require
loop, so GBP_Cron::run_sync() fatalled on an undefined class every tick
— no scheduled sync has ever run. Syncing is manual now, so all three
files go.

The upgrade routine clears the orphaned gbp_sync_cron_run event left in
the options table by earlier versions."
```

---

### Task 11: Admin AJAX, settings, template and JS

**Files:**
- Modify: `includes/class-admin.php`
- Modify: `templates/admin-page.php`
- Modify: `assets/js/admin.js`

**Interfaces:**
- Consumes: `GBP_Hours_Sync::sync_all()`, `sync_location()`, `staleness()`
- Produces: AJAX actions `gbp_sync_hours_all` and `gbp_sync_hours_one`

- [ ] **Step 1: Update `class-admin.php` hooks and settings**

In `hooks()`, replace the `gbp_sync_missing_hours` registration at line 26 with two new actions:

```php
		add_action( 'wp_ajax_gbp_sync_hours_all', [ $this, 'ajax_sync_hours_all' ] );
		add_action( 'wp_ajax_gbp_sync_hours_one', [ $this, 'ajax_sync_hours_one' ] );
```

In `register_settings()`, drop `'gbp_sync_frequency'` from the option list at lines 44-50.

- [ ] **Step 2: Replace the `ajax_sync_missing_hours()` handler**

Delete the method at lines 80-87 and add:

```php
	public function ajax_sync_hours_all(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$sync = new GBP_Hours_Sync();
		if ( ! $sync->is_configured() ) {
			wp_send_json_error( 'No Places API key configured. Add one under Settings.' );
		}

		wp_send_json_success( $sync->sync_all() );
	}

	public function ajax_sync_hours_one(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post_id' );
		}

		wp_send_json_success( ( new GBP_Hours_Sync() )->sync_location( $post_id ) );
	}
```

- [ ] **Step 3: Pass the hours sync into the template**

In `render_page()` at lines 162-168, add the hours-sync instance and last-run option:

```php
	public function render_page(): void {
		$serp       = new GBP_Serp_Sync();
		$connected  = $serp->is_configured();
		$hours_sync = new GBP_Hours_Sync();
		$hours_ready = $hours_sync->is_configured();
		$last_run   = get_option( 'gbp_sync_last_run', 'Never' );
		$hours_run  = get_option( 'gbp_sync_hours_last_run', 'Never' );

		include GBP_SYNC_DIR . 'templates/admin-page.php';
	}
```

- [ ] **Step 4: Update the template status bar**

In `templates/admin-page.php`, replace lines 6-12 with:

```php
	<div class="gbp-sync-status-bar">
		<span class="gbp-status <?php echo $connected ? 'connected' : 'disconnected'; ?>">
			<?php echo $connected ? '● SerpAPI Connected' : '● SerpAPI Not Configured'; ?>
		</span>
		<span class="gbp-status <?php echo $hours_ready ? 'connected' : 'disconnected'; ?>">
			<?php echo $hours_ready ? '● Places API Connected' : '● Places API Not Configured'; ?>
		</span>
		<span class="gbp-last-run">Last full sync: <strong><?php echo esc_html( $last_run ); ?></strong></span>
		<span class="gbp-last-run">Last hours sync: <strong><?php echo esc_html( $hours_run ); ?></strong></span>
	</div>
```

- [ ] **Step 5: Remove the sync frequency setting**

Delete the entire Sync Frequency table row at lines 39-64. Syncing is manual now.

- [ ] **Step 6: Update the API key descriptions**

Replace the SerpAPI key description at lines 33-37 with:

```php
							<p class="description">
								<a href="https://serpapi.com/manage-api-key" target="_blank">serpapi.com/manage-api-key</a>
								— used by <strong>Full Sync</strong> only, 1 credit per location. Hours come from the Places API.
							</p>
```

Replace the Places API key label and description at lines 78-87 with:

```php
						<th><label for="gbp_sync_places_api_key">Places API Key <span style="font-weight:normal;color:#666">(primary hours source)</span></label></th>
						<td>
							<input type="password" id="gbp_sync_places_api_key" name="gbp_sync_places_api_key"
								value="<?php echo esc_attr( get_option( 'gbp_sync_places_api_key', '' ) ); ?>"
								class="regular-text" autocomplete="off">
							<p class="description">
								<strong>Required for hours.</strong> Hours, holiday overrides and open/closed status all read from <strong>Places API (New)</strong>.
								Google Cloud Console &rarr; enable "Places API (New)" + billing on the same project. Leave blank to reuse the Maps Embed key above.
							</p>
						</td>
```

- [ ] **Step 7: Replace the sync buttons**

Replace the action block at lines 115-124 with:

```php
			<div class="gbp-sync-actions">
				<button id="gbp-sync-hours-btn" class="button button-primary" <?php echo ! $hours_ready ? 'disabled' : ''; ?>>
					Sync Hours (All)
				</button>
				<button id="gbp-sync-all-btn" class="button" <?php echo ! $connected ? 'disabled' : ''; ?>>
					Full Sync (All)
				</button>
				<span id="gbp-sync-spinner" class="spinner"></span>
				<div id="gbp-sync-result"></div>
			</div>

			<p class="description" style="margin-bottom:12px">
				<strong>Sync Hours</strong> reads hours, holiday overrides and open/closed status from the Places API — no SerpAPI credits.
				<strong>Full Sync</strong> additionally refreshes name, address, phone, rating and review count via SerpAPI.
				Hand-edited hours are kept until Google's own hours change.
			</p>
```

- [ ] **Step 8: Add the Hours column to the locations table**

Add a header cell between `Status` and `Rating` in the `<thead>` at lines 133-140:

```php
						<th>Hours</th>
```

Inside the `foreach` at line 155, after the existing `get_field()` calls, add:

```php
						$hours_state = GBP_Hours_Sync::staleness( $loc_post->ID );
```

Then add this cell between the Status `<td>` and the Rating `<td>`:

```php
						<td>
							<?php if ( $hours_state['stale'] ) : ?>
								<span style="color:#d63638">⚠ <?php echo esc_html( $hours_state['label'] ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $hours_state['label'] ); ?>
							<?php endif; ?>
							<?php if ( $hours_state['source'] ) : ?>
								<br><span class="description"><?php echo esc_html( $hours_state['source'] ); ?></span>
							<?php endif; ?>
							<?php if ( $hours_state['error'] ) : ?>
								<br><span class="description" style="color:#d63638" title="<?php echo esc_attr( $hours_state['error'] ); ?>">sync error</span>
							<?php endif; ?>
						</td>
```

Update the empty-state row at line 153 from `colspan="6"` to `colspan="7"`.

- [ ] **Step 9: Update the admin JS**

In `assets/js/admin.js`, replace the entire `#gbp-sync-missing-hours-btn` handler at lines 59-108 with an hours handler that renders the per-location breakdown:

```javascript
    // Render the per-location breakdown returned by the hours sync.
    function renderHoursResult(d) {
        var counts = [
            'Checked: <strong>' + d.checked + '</strong>',
            'Updated from Google: <strong>' + d.written + '</strong>',
            'First populated: <strong>' + d.populated + '</strong>',
            'Manual entries kept: <strong>' + (d.adopted + d.unchanged) + '</strong>'
        ];
        if (d.skipped) {
            counts.push('No hours: <strong>' + d.skipped + '</strong>');
        }

        var detail = '';
        var problems = (d.locations || []).filter(function (l) {
            return l.hours === 'error' || l.hours === 'skip';
        });

        if (problems.length) {
            detail = '<p style="margin:6px 0 0">Locations with no hours:</p><ul style="margin:4px 0 0 16px">' +
                problems.map(function (l) {
                    return '<li><strong>' + l.title + '</strong> — ' + (l.error || 'no usable hours returned') + '</li>';
                }).join('') + '</ul>';
        }

        return '<div class="notice notice-' + (problems.length ? 'warning' : 'success') + ' inline"><p>' +
            counts.join(' | ') + '</p>' + detail + '</div>';
    }

    // Sync hours for every location — Places API only.
    $('#gbp-sync-hours-btn').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#gbp-sync-spinner');
        var $result  = $('#gbp-sync-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('<p>Reading hours from the Places API…</p>');

        $.post(gbpSync.ajaxUrl, {
            action: 'gbp_sync_hours_all',
            nonce:  gbpSync.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.html(renderHoursResult(res.data));
                setTimeout(function () { location.reload(); }, 2500);
            } else {
                $result.html('<div class="notice notice-error inline"><p>Hours sync failed: ' + (res.data || 'Unknown error') + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error inline"><p>Request failed. Check server logs.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });
```

- [ ] **Step 10: Check everything parses**

```bash
PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
"$PHP" -l includes/class-admin.php
"$PHP" -l templates/admin-page.php
node --check assets/js/admin.js
```

Expected: no syntax errors from any of the three.

- [ ] **Step 11: Confirm no references to removed actions remain**

```bash
grep -rn "gbp_sync_missing_hours\|gbp-sync-missing-hours-btn\|gbp_sync_frequency\|GBP_Cron" \
  --include="*.php" --include="*.js" . | grep -v docs/
```

Expected: no output.

- [ ] **Step 12: Commit**

```bash
git add includes/class-admin.php templates/admin-page.php assets/js/admin.js
git commit -m "feat: manual sync controls with hours staleness column

Splits the cheap Places-only hours sync from the full SerpAPI sync, and
reports which locations came back with no hours and why instead of a
bare count. Drops the sync frequency setting — syncing is manual."
```

---

### Task 12: Manual verification in Local

`GBP_Hours_Sync` does HTTP and database work and has no unit tests. This task is its test.

**Files:** none — verification only.

- [ ] **Step 1: Activate and confirm the plugin loads clean**

Start the site from the Local app. Confirm:
- No PHP fatal errors in `local-env/logs/php/error.log`.
- Locations → Sync Settings renders.
- The status bar shows both SerpAPI and Places API connection states.
- There is no "Next sync" indicator and no Sync Frequency dropdown.

- [ ] **Step 2: Confirm the orphaned cron event is gone**

In the Local site shell:

```bash
wp cron event list | grep gbp_sync_cron_run
wp option get gbp_sync_frequency
```

Expected: no matching cron event, and `gbp_sync_frequency` reports as not set.

- [ ] **Step 3: Verify the adopt branch — the one that protects existing data**

Pick a location that already has hand-entered hours. Record them.

```bash
wp post meta get <POST_ID> _gbp_hours_snapshot
```

Expected: empty — no snapshot yet.

Click **Sync Hours (All)**. Then:
- The hours on that location are **unchanged** from what you recorded.
- The result panel counts it under "Manual entries kept".
- `wp post meta get <POST_ID> _gbp_hours_snapshot` now returns a JSON array.

- [ ] **Step 4: Verify the unchanged branch**

Click **Sync Hours (All)** again without changing anything in Google.

Expected: hours still unchanged, still counted under "Manual entries kept", and the snapshot value is identical to Step 3.

- [ ] **Step 5: Verify the write branch**

Change the hours for one test location in its Google Business Profile and wait for the change to appear on Google Maps.

Click **Sync Hours (All)**.

Expected: that location is counted under "Updated from Google", its `loc_hours` now matches Google, and its snapshot has updated.

- [ ] **Step 6: Verify a failed fetch cannot destroy hours**

Pick a location with hours. Temporarily set its `loc_place_id` to `ChIJinvalidplaceid`. Click its per-row **Sync Now**.

Expected: `loc_hours` is untouched, the Hours column shows a sync error, and `wp post meta get <POST_ID> _gbp_hours_last_error` returns a Places API error message.

Restore the correct Place ID afterward.

- [ ] **Step 7: Verify population on creation**

In the Import tab, click **Search for Missing Locations**, then import one.

Expected: the new location post has `loc_hours` populated on creation, and its Hours column shows a fresh timestamp rather than "No hours".

If no un-imported locations exist, instead create a location post by hand, set a valid `loc_place_id`, and use its **Sync Now** button.

- [ ] **Step 8: Verify special hours**

Check a location whose Google profile has an upcoming holiday override within the next seven days.

Expected: `loc_special_hours` contains a dated row matching the override. If no location has one in the window, note that this went unverified rather than marking it passed.

- [ ] **Step 9: Verify status mapping**

Confirm the Status badge in the locations table matches each location's real Google state, and that no location was drafted or unpublished by the sync.

- [ ] **Step 10: Record the results and commit**

Write what passed and what did not into `docs/superpowers/plans/2026-07-28-gbp-hours-snapshot-sync.md` under a new "Verification results" heading. Record any step that could not be exercised as unverified rather than passed.

```bash
git add docs/superpowers/plans/2026-07-28-gbp-hours-snapshot-sync.md
git commit -m "docs: record manual verification results for hours sync"
```

---

## Self-review

**Spec coverage**

| Spec section | Task |
|---|---|
| `GBP_Hours_Rules` pure class | 2-7 |
| `GBP_Hours_Sync` IO class | 8 |
| Canonical form | 2 (constraints), 3, 4 |
| Canonicalization edge cases | 3 |
| Provenance meta keys | 8 |
| Decision rule, five branches | 5 |
| `adopt` migration path | 5, verified in 12 |
| Completeness guard | 4 |
| Business status mapping | 8 |
| `CLOSED_PERMANENTLY` does not draft | 8 |
| Special hours derivation | 7 |
| Window-scoped write | 6 |
| Date-shape assumption gate | 1, gating 7 |
| Sync triggers, Places-only hours action | 8, 11 |
| Result reporting shapes | 8, 11 |
| Admin UI changes | 11 |
| Deletions | 9, 10 |
| Bootstrap and upgrade routine | 10 |
| Error handling table | 8 |
| Testing | 2-7 unit, 12 manual |

Two deliberate deviations from the spec, both noted at the point they occur:

1. **No Composer or PHPUnit.** None of Local's three PHP builds have the `phar` extension and there is no system PHP, so neither can run on this machine. Task 2 builds a hand-rolled runner instead. Coverage is unchanged.
2. **SerpAPI status fallback retained.** The spec has Places API `businessStatus` replacing the `open_state` string sniff outright. Task 9 keeps the sniff as a fallback for when Places API did not answer, so a Places outage cannot leave a temporarily-closed location displaying as open.

**Placeholder scan:** no TBD, TODO, "add error handling", or "similar to Task N" instances. Every code step carries real code. The one conditional in the plan — Task 1's gate on Task 7 — states its exact failure branch.

**Type consistency:** `decide()` returns the five `GBP_Hours_Rules` constants; Task 8's `switch` matches on those same constants, and Task 11's JS matches the string values `'error'` and `'skip'`. The per-location result shape declared in Task 8 is what Task 11's `renderHoursResult()` reads and what Task 12 verifies. `build_rows()` is defined private in Task 3 and used only within the class. `staleness()` is declared static in Task 8 and called statically in Task 11.
