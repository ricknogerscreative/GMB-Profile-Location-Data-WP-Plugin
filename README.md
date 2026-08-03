# GBP Location Sync — WordPress Plugin

Syncs Google Business Profile data (NAP, hours, rating, temp closures) to a WordPress CPT `location` with ACF fields. Google is the source of truth for everything except hand-edited hours, which survive until Google's own hours change.

We open roughly 7 new locations a year. When a location closes for weather, mark it **Temporarily Closed** in GBP once and the next sync propagates it here — no closing the same location on several channels by hand.

## Requirements

- WordPress 6.0+
- ACF Pro 6.0+
- PHP 8.0+
- **Places API (New)** key — hours, holiday overrides and open/closed status
- **SerpAPI** key — name, address, phone, rating, review count
- Maps Embed API key (optional — falls back to a keyless lat/lng embed)

## Setup

### 1. Google Cloud Console

1. Create a project at console.cloud.google.com
2. Enable **Places API (New)** and billing on that project
3. (Optional) Enable **Maps Embed API** on the same project
4. Create an API key

No OAuth. The plugin reads only public place data, by Place ID.

### 2. Install Plugin

Copy the plugin folder to `wp-content/plugins/` and activate.

### 3. Configure

1. Go to **Locations → Sync Settings**
2. Paste the SerpAPI key and the Places API key → **Save Settings**
3. Set **Brand Name Prefix** (e.g. `Emergency Dental of `) so post titles and slugs come out as the short location name
4. Give each location post a **Google Place ID** — edit the post → Profile tab. Locations without one are reported but not synced.

## Syncing

Manual only — there is no cron. Two buttons on the **Locations** tab:

| Button | What it does | Cost |
|---|---|---|
| **Sync Hours (All)** | Hours, holiday overrides, open/closed status. Places API only. | Places API calls |
| **Full Sync (All)** | Everything above, plus name, address, phone, rating and review count. | 1 SerpAPI credit per location |

**Sync Now** on a row runs a full sync for that one location.

### How hours are protected

The plugin stores a snapshot of whatever hours Google last returned and writes only when a new fetch differs from that snapshot. A hand-edited value therefore survives indefinitely while Google is unchanged, and is overwritten the moment Google genuinely moves. Emptying the hours field forces a repopulate on the next sync.

## How Temp Closure Sync Works

When a location is marked **Temporarily Closed** in Google Business Profile (e.g. for a snow storm):

1. Places API returns `businessStatus = "CLOSED_TEMPORARILY"`
2. The next sync sets ACF `loc_status` = `CLOSED_TEMPORARILY` and `loc_temp_closed` = `1`
3. Use these fields in your theme templates to show closure banners

When Google reopens the location, the next sync reverts both fields. Business status is machine-owned and is written on every successful fetch — it is not snapshot-gated. `CLOSED_PERMANENTLY` deliberately does not unpublish the post.

### Special Hours (Holiday / Emergency)

Derived by diffing the Places API `currentOpeningHours` (next seven days, each period dated) against the regular week. Any date whose hours differ becomes a row in the `loc_special_hours` repeater:

- `date` — YYYY-MM-DD
- `is_closed` — 1/0
- `open_time` / `close_time`

Rows dated beyond the seven-day window are hand-entered future closures and are left alone. Dates Google did not answer for are treated as unknown, never as closed.

## ACF Fields Reference

| Field Name | Type | Source |
|---|---|---|
| `loc_place_id` | text | entered by hand — the key everything else syncs from |
| `loc_name` | text | SerpAPI `title` |
| `loc_phone` | text | SerpAPI `phone` |
| `loc_website` | url | SerpAPI `website` |
| `loc_address_1` / `loc_address_2` / `loc_city` / `loc_state` / `loc_zip` | text | parsed from SerpAPI `address` |
| `loc_lat` / `loc_lng` | text | SerpAPI `gps_coordinates` |
| `loc_maps_url` / `loc_maps_embed` | url / textarea | built from Place ID |
| `loc_status` | select | Places `businessStatus` (SerpAPI fallback) |
| `loc_temp_closed` | true_false | derived from status |
| `loc_hours` | repeater | Places `regularOpeningHours.periods` |
| `loc_special_hours` | repeater | diff of Places `currentOpeningHours` vs. `loc_hours` |
| `loc_rating` | number | SerpAPI `rating` |
| `loc_review_count` | number | SerpAPI `reviews` |
| `gbp_last_synced` | datetime | set on each full sync |

Review *content* is not synced here — it lives in Airtable. This plugin stores the rating and count only.

## Template Usage

```php
// Check temp closure in template
$temp_closed = get_field('loc_temp_closed', $post->ID);
if ($temp_closed) {
    echo '<div class="alert">This location is temporarily closed.</div>';
}

// Display hours
$hours = get_field('loc_hours', $post->ID);
foreach ($hours as $row) {
    if (!$row['is_closed']) {
        echo $row['day'] . ': ' . $row['open_time'] . ' – ' . $row['close_time'];
    }
}
```

## Tests

`GBP_Hours_Rules` is pure logic — no WordPress, no IO — and is covered by a
zero-dependency runner (Local's bundled PHP has no phar extension, so there is
no Composer or PHPUnit here):

```bash
"$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" tests/run-tests.php
```
