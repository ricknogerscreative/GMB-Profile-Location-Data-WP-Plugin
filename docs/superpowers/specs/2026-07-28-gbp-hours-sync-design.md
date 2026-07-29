# GBP Hours Sync — Snapshot-Diff Design

**Date:** 2026-07-28
**Plugin:** GBP Location Sync (`gbp-location-sync`) v2.0.0 → v2.1.0
**Scope:** Regular hours, special hours, business status, sync triggering

---

## Problem

Hours of operation do not populate when a location is created, and do not update afterward. Staff work around this by entering hours by hand. Those manual entries are then destroyed by the next sync. Meanwhile Google Business Profile hours change frequently (pushed via SEMrush Listing Management, reflecting temporary closures), and none of those changes reach the site.

### Root causes found in the current code

1. **Cron never runs — fatal error.** `includes/class-cron.php:37` instantiates `GBP_Sync_Manager`. The require loop in `gbp-location-sync.php:22-29` loads only `class-location-cpt`, `class-serp-sync`, `class-admin`, `class-cron`. `class-sync-manager.php` and `class-gbp-api.php` are never loaded, so every scheduled tick fatals on an undefined class. No automated sync has ever executed.

2. **`$hours_mapped` reports success when nothing was written.** `class-serp-sync.php:368-371` sets `$hours_mapped = true` before calling `map_hours_from_strings()`, which internally guards `if ( $rows )` at `:534` and can write nothing. The Places API fallback at `:382` is gated on `! $hours_mapped` and therefore never fires. Result: hours land empty on creation, silently.

3. **Unconditional overwrite.** `map_to_acf()` always calls `update_field( 'loc_hours', $rows )`. There is no concept of a manual edit, so any sync destroys hand-entered hours.

4. **Missing day becomes a false closure.** `class-serp-sync.php:472-479` and `:563-566` emit `is_closed = 1` for any weekday absent from the scrape. A partial SerpAPI response overwrites correct hours with fabricated closures.

5. **Special hours have no writer on the live path.** `loc_special_hours` is populated only inside `GBP_Sync_Manager`, which is dead code. Holiday and one-off closures never sync.

6. **No provenance.** Nothing records where hours came from, when they were last fetched, or whether a human has touched them.

---

## Decisions taken

| Question | Decision |
|---|---|
| Manual edit vs. Google change | **Snapshot diff.** Store the hours Google last returned; write only when a new fetch differs from that snapshot. |
| Authoritative hours source | **Places API (New) first**, SerpAPI as fallback. |
| Dead GBP OAuth path | **Delete** `class-sync-manager.php` and `class-gbp-api.php`. |
| Special hours | **In scope** — derive from `currentOpeningHours` vs `regularOpeningHours`. |
| Triggering | **Manual only.** Delete cron. Admin buttons plus a staleness indicator. |

---

## Architecture

Two new files, split on a testability boundary.

### `includes/class-hours-rules.php` — `GBP_Hours_Rules`

Pure logic. Zero WordPress dependencies, zero IO. Every decision in the system lives here so it can be unit-tested without a WordPress bootstrap.

```php
final class GBP_Hours_Rules {
    public static function canonicalize_places( array $periods ): ?array;
    public static function canonicalize_serp( $raw ): ?array;
    public static function decide( ?array $fetched, ?array $snapshot, bool $current_is_empty ): string;
    public static function derive_special( array $regular, array $current_periods, string $today ): array;
    public static function merge_special_window( array $existing, array $derived, string $today, string $window_end ): array;
    public static function fmt_clock( int $hour, int $minute ): string;
    public static function normalize_time( string $time ): string;
    public static function split_time_range( string $range ): array;
}
```

### `includes/class-hours-sync.php` — `GBP_Hours_Sync`

IO and orchestration. Performs HTTP requests, reads and writes ACF fields and post meta, calls `GBP_Hours_Rules` for every decision.

```php
class GBP_Hours_Sync {
    public function __construct();
    public function is_configured(): bool;
    public function sync_all(): array;
    public function sync_location( int $post_id, $serp_hours_raw = null ): array;
}
```

`GBP_Serp_Sync::map_to_acf()` stops touching hours entirely and delegates to `GBP_Hours_Sync::sync_location()`, passing its already-fetched SerpAPI hours payload so the fallback costs no extra credit.

---

## Canonical form

Both sources normalize to this shape before any comparison or write. Day order is fixed Monday-first and times are always `g:i A`, which makes the form deterministic — so a plain string comparison of the JSON encoding is a valid change test.

```php
[
    [ 'day' => 'MONDAY',    'open_time' => '8:00 AM', 'close_time' => '6:00 PM', 'is_closed' => 0 ],
    [ 'day' => 'TUESDAY',   'open_time' => '',        'close_time' => '',        'is_closed' => 1 ],
    // … WEDNESDAY, THURSDAY, FRIDAY, SATURDAY, SUNDAY
]
```

This matches the existing `loc_hours` repeater sub-field names exactly (`day`, `open_time`, `close_time`, `is_closed`), so it is written to ACF unmodified.

### Canonicalization edge cases

| Case | Handling |
|---|---|
| Open 24 hours | Places period has `open` with no `close` → `12:00 AM` – `11:59 PM` |
| Overnight (`close.day != open.day`) | Row is assigned to the **open** day, e.g. Monday `8:00 PM` – `2:00 AM`. Expected to be common for emergency dental. |
| Split shift (two periods, same day) | Two rows for that day. The repeater supports it. |
| Weekday absent from Places response | `is_closed = 1` row. This is Google's own semantics and is authoritative. |
| Weekday absent from a SerpAPI response | Rejected — see completeness guard below. |

---

## Provenance

Stored as hidden post meta (underscore-prefixed), not ACF fields. This keeps the location editor uncluttered and avoids field-group changes.

| Meta key | Contents |
|---|---|
| `_gbp_hours_snapshot` | Canonical JSON of the regular hours Google last actually returned |
| `_gbp_special_snapshot` | Canonical JSON of the last derived special-hours set |
| `_gbp_hours_fetched_at` | MySQL datetime of the last **successful fetch** — distinct from last write |
| `_gbp_hours_source` | `places` or `serpapi` |
| `_gbp_hours_last_error` | Reason for the last failed fetch; deleted on success |

The existing ACF field `gbp_last_synced` is unaffected and keeps its current meaning — the last time a **full** sync ran against the location. `_gbp_hours_fetched_at` tracks hours specifically, which is what the staleness indicator reads, because `Sync Hours` runs independently of `Full Sync`.

`GBP_Hours_Sync::is_configured()` returns true when a Places API key is available, checking `gbp_sync_places_api_key` and falling back to `gbp_sync_maps_embed_key` — matching the existing lookup at `class-serp-sync.php:597-600`.

---

## The decision rule

`GBP_Hours_Rules::decide()` returns one of `populate`, `adopt`, `write`, `unchanged`, `skip`.

```
fetch: Places API (New)  →  on failure, SerpAPI fallback (Full Sync only)
  │
  ├─ both fail, OR result fails the completeness guard
  │     → skip. Write nothing. Record error, leave fetched_at unchanged.
  │       A bad fetch can never blank or falsify hours.
  │
  └─ got canonical $fetched
        │
        ├─ $snapshot === null   (first successful fetch for this location)
        │     ├─ loc_hours empty  → populate  : write hours, save snapshot
        │     └─ loc_hours filled → adopt     : save snapshot ONLY, do not write
        │
        └─ $snapshot !== null
              ├─ $fetched === $snapshot → unchanged : leave loc_hours alone
              └─ $fetched !== $snapshot → write     : overwrite, resnapshot
```

### Why `adopt` matters

On first run against the 23 existing locations, anything already populated by hand keeps its hours — the snapshot is seeded silently from what Google currently reports. From that point forward, only a genuine Google-side change overwrites. This is the migration path: no backfill script, no data loss on deploy.

### Completeness guard

- **Places API** results are accepted whenever `regularOpeningHours.periods` is present and non-empty. An absent weekday means genuinely closed.
- **SerpAPI** results are accepted only when all seven weekdays are accounted for in the response. A partial scrape returns `null` from `canonicalize_serp()` and is treated as a failed fetch.

This closes root cause 4 — a three-day scrape can no longer write `is_closed = 1` over the other four days.

---

## Business status

Not snapshot-gated. `businessStatus` comes from the same Places call and is written on every successful run:

| Places `businessStatus` | `loc_status` | `loc_temp_closed` |
|---|---|---|
| `OPERATIONAL` | `OPEN` | `0` |
| `CLOSED_TEMPORARILY` | `CLOSED_TEMPORARILY` | `1` |
| `CLOSED_PERMANENTLY` | `CLOSED_PERMANENTLY` | `0` |

These fields are machine-owned (the ACF instruction text already says "Auto-set by GBP sync") and drive site-wide closure banners, so they should always reflect Google. This replaces the `stripos( $place['open_state'], 'temporarily' )` string sniff at `class-serp-sync.php:340-341`.

`CLOSED_PERMANENTLY` does **not** change post status. The old `GBP_Sync_Manager` drafted such posts automatically; that behaviour is not carried forward, because unpublishing a live location page is a decision a human should make.

---

## Special hours

Same Places request, field mask `regularOpeningHours,currentOpeningHours,businessStatus` — one HTTP call covers regular hours, special hours, and status.

`currentOpeningHours` describes the actual next seven days. Any weekday whose current hours differ from `regularOpeningHours` is an override, and is emitted as a dated row into `loc_special_hours`:

```
regularOpeningHours  THU  8:00 AM – 6:00 PM
currentOpeningHours  THU  closed              ← differs

→ loc_special_hours row: date = 2026-11-26, is_closed = 1
```

The derived set is subject to the same snapshot rule via `_gbp_special_snapshot`.

**Window-scoped write.** When the derived set changes, only rows dated within `[today, today + 7 days]` are replaced. Rows outside that window are preserved verbatim. Hand-entered closures for future dates therefore survive, and no `source` sub-field needs adding to the repeater.

`loc_special_hours` stores `date` as a plain text sub-field; derived rows use `Y-m-d`, matching the format the dead `GBP_Sync_Manager` produced.

### Assumption requiring verification

This derivation assumes `currentOpeningHours.periods[].open.date` returns a `{year, month, day}` object identifying the concrete date of each period. This must be confirmed against a live Places API (New) response for one EDOA place ID **before** implementing `derive_special()`. If Google does not return dates there, special-hours derivation needs a different date anchor and the implementation plan must be revised at that step.

---

## Sync triggers

Cron is removed. Two admin actions plus per-location sync.

| Action | Calls | Cost per run (23 locations) |
|---|---|---|
| **Sync Hours** | Places API only. No SerpAPI fallback. | 23 Places calls |
| **Full Sync** | SerpAPI for everything, Places for hours, SerpAPI hours as fallback | 23 SerpAPI + 23 Places calls |
| **Sync Now** (per location) | Same as Full Sync, one location | 1 + 1 |
| **Import location** | Creates post, then Full Sync path | 1 + 1 |

`Sync Hours` deliberately does not fall back to SerpAPI. It is the cheap, frequent action and must not silently burn SerpAPI credits.

### Cost note

Places API (New) pricing for `regularOpeningHours` / `currentOpeningHours` sits in a higher-priced SKU tier than the basic fields. The exact per-call rate has **not** been verified and must be checked against the project's Google Cloud billing before rollout. Because triggering is manual, spend is bounded by clicks rather than by a schedule, which is why cadence is no longer a cost variable.

---

## Result reporting

Per location:

```php
[
    'post_id' => 412,
    'title'   => 'Milwaukee',
    'hours'   => 'written',   // populate|adopt|write|unchanged|skip|error
    'special' => 'unchanged',
    'source'  => 'places',    // places|serpapi|null
    'error'   => null,
]
```

Aggregate:

```php
[
    'checked'   => 23,
    'populated' => 2,
    'adopted'   => 6,
    'written'   => 3,
    'unchanged' => 11,
    'skipped'   => 1,
    'errors'    => [ 'Cincinnati: Places API HTTP 403 — API key not authorized' ],
    'locations' => [ /* per-location rows above */ ],
]
```

The admin results panel lists every location that did not receive hours and states why, rather than reporting a bare count.

---

## Admin UI changes

### `templates/admin-page.php`

- Remove the "Next sync" span at `:11` — it calls the deleted `GBP_Cron::get_next_run()`.
- Remove the Sync Frequency row at `:39-64`.
- Replace the "Re-sync Missing Hours" button at `:119-121` with **Sync Hours (All)**.
- Add an **Hours** column to the locations table showing source, age of last successful fetch, and a `⚠` when hours are empty or `_gbp_hours_fetched_at` is older than **7 days** or missing.
- Update the Places API Key field description at `:83-86` from "hours fallback" to "primary hours source — required for hours sync".
- Update the SerpAPI key description at `:33-36`, which currently describes a per-sync-cycle credit model that no longer applies.

### `includes/class-admin.php`

- Add AJAX actions `gbp_sync_hours_all` and `gbp_sync_hours_one`.
- Remove the `gbp_sync_missing_hours` action at `:26` and its handler at `:80-87`.
- Drop `gbp_sync_frequency` from `register_settings()` at `:44-52`.
- Existing nonce check and `manage_options` capability check pattern is retained on all new handlers.

### `assets/js/admin.js`

- Wire the new AJAX actions.
- Render the per-location breakdown in the results panel.

---

## Deletions

| File / code | Reason |
|---|---|
| `includes/class-sync-manager.php` | Dead — never loaded |
| `includes/class-gbp-api.php` | Dead — never loaded |
| `includes/class-cron.php` | Triggering is manual |
| `GBP_Serp_Sync::sync_missing_hours()` (`:73-114`) | Superseded — Sync Hours now applies the snapshot rule to every location |
| `GBP_Serp_Sync::map_hours()` (`:457-504`) | Replaced by canonicalization |
| `GBP_Serp_Sync::map_hours_from_strings()` (`:509-537`) | Replaced |
| `GBP_Serp_Sync::map_hours_from_keyed_objects()` (`:543-579`) | Replaced |
| `GBP_Serp_Sync::map_hours_from_places_api()` (`:596-635`) | Moved into `GBP_Hours_Sync` |
| `GBP_Serp_Sync::map_hours_from_places_periods()` (`:644-680`) | Replaced by `canonicalize_places()` |
| `GBP_Serp_Sync::fmt_clock()` (`:685-687`) | Moved to `GBP_Hours_Rules` |
| `GBP_Serp_Sync::split_time_range()` (`:689-695`) | Moved to `GBP_Hours_Rules` |
| `GBP_Serp_Sync::normalize_time()` (`:704-712`) | Moved to `GBP_Hours_Rules` |
| `HOURS_RETRY_ATTEMPTS` / `HOURS_RETRY_DELAY_US` retry loop (`:241-289`) | Places API is primary; the retry existed to coax hours out of the scrape. Removing it saves up to 3× SerpAPI credits per location. |
| Debug `error_log` calls at `:213-214`, `:350-351` | Dump the full JSON response on every sync |

`set_transient( 'gbp_serp_debug_…' )` at `:217-224` is retained — the admin per-location sync surfaces it and it remains useful for diagnosing scrape shape.

---

## Plugin bootstrap changes

`gbp-location-sync.php`:

- Bump `GBP_SYNC_VERSION` to `2.1.0`.
- Add `class-hours-rules` and `class-hours-sync` to the require loop; remove `class-cron`.
- Remove `GBP_Cron::instance()` from `gbp_sync_boot()`.
- Remove `GBP_Cron::schedule()` from `gbp_sync_activate()`; remove `gbp_sync_deactivate()`'s unschedule call.
- Add a one-time upgrade routine to clear the orphaned scheduled event and the obsolete option:

```php
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

Without this, the previously scheduled `gbp_sync_cron_run` event remains in the options table firing an action no longer registered.

---

## Error handling

| Condition | Behaviour |
|---|---|
| No Places API key configured | `Sync Hours` is disabled in the UI with an explanatory note. `Full Sync` proceeds and falls back to SerpAPI hours. |
| Places API returns non-200 | Record HTTP code and Google's error message in `_gbp_hours_last_error`; fall through to the SerpAPI fallback where one is available. |
| Places API returns 200 with no `regularOpeningHours` | Treated as a failed fetch — skip, do not write. |
| SerpAPI fallback returns incomplete hours | Rejected by the completeness guard — skip, do not write. |
| Location has no `loc_place_id` | Skipped, counted, and named in the results panel. |
| Any successful fetch | `_gbp_hours_last_error` is deleted and `_gbp_hours_fetched_at` is set. |

The invariant across every failure path: **a failed or incomplete fetch never writes to `loc_hours` or `loc_special_hours`.**

---

## Testing

The repository currently has no test infrastructure — no `composer.json`, no `tests/` directory, no PHPUnit. The snapshot rule has five branches and getting one wrong silently destroys hand-entered data, so this work warrants tests. `GBP_Hours_Rules` is deliberately pure so they need no WordPress bootstrap.

**Add:** `composer.json` with `phpunit/phpunit` as a dev dependency, and `tests/` with a plain autoload bootstrap. No WordPress test suite, no database.

**Coverage:**

| Unit | Cases |
|---|---|
| `decide()` | All five branches: populate, adopt, write, unchanged, skip |
| `canonicalize_places()` | Standard week; open-24-hours; overnight close; split shift; absent weekday; empty periods → `null` |
| `canonicalize_serp()` | Each of the response shapes currently handled (keyed objects, day-keyed map, timetable, string array); partial response → `null`; en-dash and non-breaking-space time ranges |
| `normalize_time()` | `"11 AM"` → `"11:00 AM"`; already-normalized passthrough; unparseable input returned unchanged |
| `derive_special()` | Weekday closed vs. regular open; changed hours; identical days produce no row |
| `merge_special_window()` | In-window rows replaced; out-of-window rows preserved; past-dated rows dropped |

**Manual verification** (`GBP_Hours_Sync` IO layer, in Local by Flywheel):

1. Confirm the `currentOpeningHours.periods[].open.date` assumption against a live response before building `derive_special()`.
2. Location with hand-entered hours → run `Sync Hours` → hours unchanged, snapshot seeded, result reports `adopted`.
3. Same location, second run → result reports `unchanged`, hours untouched.
4. Change hours in GBP for one test location, wait for propagation → run `Sync Hours` → result reports `written`, hours match Google.
5. Location with a deliberately invalid place ID → result reports `error`, existing hours intact.
6. Fresh import of a new location → hours populate on creation.

---

## Out of scope

- Reviving the GBP OAuth API path. If GBP API access is granted later it should be added deliberately as a hours source ranked above Places API, since it returns `specialHours` directly rather than by derivation.
- Automated or scheduled syncing.
- Reviews content — remains in Airtable, handled by the separate `edoa-review-sync` plugin.
- Any change to how templates render hours on the front end.
