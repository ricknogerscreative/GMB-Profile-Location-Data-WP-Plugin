# Handoff — GBP hours snapshot sync

**Last updated:** 2026-08-03
**State:** built, reviewed, merged, pushed. **Not yet verified inside WordPress.**

Read this first if you are picking the work back up. The per-task build ledger at
`.superpowers/sdd/2026-07-28-gbp-hours-snapshot-sync/progress.md` has more detail but is
git-ignored, so it does not survive a fresh clone — everything load-bearing is here.

---

## What was done

Three defects were fixed in how the plugin syncs hours from Google Business Profile.

| Defect | Root cause | Fix |
|---|---|---|
| Hours never populated on location creation | `map_to_acf()` set `$hours_mapped = true` before a parser that could write nothing, gating off the Places API fallback | Flag deleted along with five hours parsers |
| Hours never updated | `GBP_Cron::run_sync()` instantiated `GBP_Sync_Manager`, which was never in the require loop — every tick fatalled, so no scheduled sync had ever run | Dead path deleted; syncing is manual, with a staleness column |
| Hand-entered hours destroyed by the next sync | Every sync wrote `loc_hours` unconditionally | Snapshot diff — see below |

### The snapshot rule

`_gbp_hours_snapshot` stores the hours Google **last returned**. On each sync a new fetch is
compared against that snapshot, not against what is currently in the field:

- no snapshot + field empty → **populate**
- no snapshot + field filled → **adopt** (seed the snapshot, write nothing — this is what protects existing hand-entered hours on first run)
- snapshot matches fetch + field filled → **unchanged**
- snapshot matches fetch + field empty → **populate** (recovery path)
- snapshot differs from fetch → **write** (Google genuinely changed)
- fetch failed or incomplete → **skip**, nothing written, error recorded

Hours now come from **Places API (New)** first; SerpAPI is a fallback used only on a location
that has no snapshot yet. Business status comes from `businessStatus` and is written on every
successful fetch. Special hours are derived by diffing `currentOpeningHours` against
`regularOpeningHours`.

### Architecture

- `includes/class-hours-rules.php` — `GBP_Hours_Rules`, pure logic, zero WordPress dependencies, 66 unit tests
- `includes/class-hours-sync.php` — `GBP_Hours_Sync`, all IO (HTTP, ACF, post meta)

Keep that split: no WordPress calls in the rules class, no decision logic in the sync class.

### Tests

No Composer or PHPUnit — no PHP build on the dev machine ships the `phar` extension. `tests/`
contains a hand-rolled runner. This is deliberate; do not "fix" it by adding a framework.

```bash
"$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" tests/run-tests.php
```

Expected: `66 passed, 0 failed`.

---

## Current state

| | |
|---|---|
| `main` | `6383444`, pushed |
| Feature branch | `gbp-hours-snapshot-sync`, preserved at the same commit |
| Remote | `GMB-Profile-Location-Data-WP-Plugin.git` (renamed from `GMB-to-WP-Sync`) |
| Version | 2.1.0 |
| Build artifact | `~/Desktop/gbp-location-sync-2.1.0.zip` |

The zip contains an **uncommitted** `data/locations.json` edit (+9/−1) that is not on GitHub, so
a rebuild from a clean clone will not be byte-identical. Harmless — that file only feeds the
Import tab's search.

---

## Outstanding — start here

### 1. Task 12, manual verification (never run)

Full steps in `docs/superpowers/plans/2026-07-28-gbp-hours-snapshot-sync.md`. The build has
never executed inside WordPress. Highest-value checks:

- **adopt** — a location with hand-entered hours must come back "Manual entries kept" on the
  first sync, hours untouched, `_gbp_hours_snapshot` newly populated. If it overwrites instead,
  the core feature is failing.
- **write** — change hours in GBP, wait for propagation, sync, confirm they land.
- **failed fetch** — set a bogus `loc_place_id`, sync, confirm existing hours are untouched and
  an error is recorded.

After upload, **deactivate and reactivate** so `gbp_sync_maybe_upgrade()` runs. It clears the
orphaned `gbp_sync_cron_run` event and the dead `gbp_sync_frequency` option, and is guarded to
run once per version.

Settings → **Places API Key** must be set or `Sync Hours (All)` stays disabled.

### 2. The Places API probe (Task 12 Step 0b) — blocks a decision

The live response shape was never verified. `derive_special()` assumes
`currentOpeningHours.periods[].open.date` returns `{year, month, day}`. A fail-safe returns `[]`
rather than fabricating closures if that is wrong, so nothing breaks — but the probe result
decides whether follow-up 1 below needs doing.

### 3. Three known limitations

Documented with full reasoning in
`docs/superpowers/specs/2026-07-28-gbp-hours-sync-design.md` under "Known limitations and
follow-ups". Summary:

1. **Edge-window closures can be missed.** A holiday on the first or last day of the 7-day
   window is skipped, because a closed day carries no period and so cannot be distinguished from
   "Google didn't answer that far". Fails toward silence, not fabrication. Revisit after the probe.
2. **SerpAPI fallback unreachable once a snapshot exists.** A persistently broken Places API
   (revoked key, invalid Place ID) can no longer fall back. Accepted to prevent cross-source
   snapshot flapping, which destroys manual edits. Follow-up: an admin control to clear
   `_gbp_hours_snapshot`, which is also the recovery path for any future wedge.
3. **`Sync Hours (All)` runs 23 sequential 20s requests in one AJAX call** and may exceed
   `max_execution_time`. Not data-destructive. Needs batching.

---

## Things that will bite you

- The source directory (`gbp-location-data-import-sync-wp-plugin/`) and the deployed WordPress
  folder (`wp-content/plugins/gbp-location-sync/`) have **different names**.
- `get_field()` returns `false`, not `[]`, for an empty ACF repeater.
- `decide()` compares with `===`. The snapshot round-trips through JSON in post meta, so
  `is_closed` must stay an int. There is a test pinning this.
- PCRE's `\s` does not match U+00A0 or U+202F under `/u` without `PCRE_UCP`, and Google emits
  both inside time strings. `GBP_Hours_Rules::SPACE` lists them explicitly — do not simplify it.
- Two defects during the build lived in the seam **between** classes, not inside them, and every
  per-task review passed. If you extend this, review the handoffs specifically.
