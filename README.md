# GBP Location Sync — WordPress Plugin

Syncs Google Business Profile (NAP, hours, reviews, temp closures) to a WordPress CPT `location` with ACF fields. GBP is the single source of truth. Cron-job.org with mu-plugins disabling wp cron. Still testing the cron process. We open new locations currently at a rate of 7x a year. So if a location is closed due to weather, instead of closing a location on multiple channels x number of times set temp closed on GMB and it will sync the hours accordingly. 

## Requirements

- WordPress 6.0+
- ACF Pro 6.0+
- PHP 8.0+
- Serp API
- GMB Profiles ClientID's
- 

## Setup

### 1. Google Cloud Console

1. Create project at console.cloud.google.com
2. Enable APIs:
   - **My Business Business Information API**
   - **My Business Account Management API**
   - (Reviews come through the v4 endpoint automatically — no separate API to enable)
3. Create OAuth 2.0 credentials (Web application type)
4. Add Authorized Redirect URI: `https://your-site.com/wp-admin/edit.php?post_type=gbp_location&page=gbp-location-sync&gbp_oauth_code=`

### 2. Install Plugin

Copy `gbp-location-sync/` to `wp-content/plugins/` and activate.

### 3. Connect

1. Go to **Locations → GBP Sync**
2. Enter Client ID + Client Secret → Save
3. Click **Authorize with Google** → approve scopes
4. Select your GBP account → Save
5. Click **Sync All Locations Now** on the Locations tab

## How Temp Closure Sync Works

When a location is marked **Temporarily Closed** in Google Business Profile (e.g., for a snow storm):

1. GBP API returns `openInfo.status = "CLOSED_TEMPORARILY"`
2. Next sync (auto or manual) sets:
   - ACF `gbp_status` = `CLOSED_TEMPORARILY`
   - ACF `gbp_temp_closed` = `true`
3. Use these fields in your theme/templates to show closure banners

When GBP reopens the location, next sync reverts both fields automatically.

### Special Hours (Holiday / Emergency)

GBP `specialHours` periods sync to the `gbp_special_hours` ACF repeater field:
- `date` — YYYY-MM-DD
- `is_closed` — true/false
- `open_time` / `close_time`

## Sync Schedule

Configurable via Settings tab: 15min / 30min / 1hr / 6hr / 12hr / daily.
Manual sync available per-location or for all 20+ locations at once.

## ACF Fields Reference

| Field Name | Type | Source |
|---|---|---|
| `gbp_location_id` | text | `name` (resource ID) |
| `gbp_business_name` | text | `title` |
| `gbp_phone` | text | `phoneNumbers.primaryPhone` |
| `gbp_address_*` | text | `storefrontAddress` |
| `gbp_website` | url | `websiteUri` |
| `gbp_status` | select | `openInfo.status` |
| `gbp_temp_closed` | true_false | derived from status |
| `gbp_regular_hours` | repeater | `regularHours.periods` |
| `gbp_special_hours` | repeater | `specialHours.specialHourPeriods` |
| `gbp_rating` | number | `averageRating` |
| `gbp_review_count` | number | `totalReviewCount` |
| `gbp_reviews` | repeater | Reviews API |
| `gbp_last_synced` | datetime | set on each sync |

## Template Usage

```php
// Check temp closure in template
$temp_closed = get_field('gbp_temp_closed', $post->ID);
if ($temp_closed) {
    echo '<div class="alert">This location is temporarily closed.</div>';
}

// Display hours
$hours = get_field('gbp_regular_hours', $post->ID);
foreach ($hours as $row) {
    if (!$row['is_closed']) {
        echo $row['day'] . ': ' . $row['open_time'] . ' – ' . $row['close_time'];
    }
}
```
