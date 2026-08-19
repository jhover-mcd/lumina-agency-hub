# Fixing Broken Images in Curated Feeds

## The Problem

Instagram media URLs expire after ~5-7 days. When using curated feeds (manually selected posts), the WordPress plugin stores these posts locally. After a week, the Instagram CDN URLs become invalid, causing images to break.

## What Changed in This Update

### Hub API Changes (v2026-08-19)

1. **New `/v1/refresh` endpoint** - Force-fetches fresh URLs from Instagram
2. **URL freshness timestamps** - Feed responses now include `fetched_at` and `fetched_at_iso`
3. **Cache bypass option** - `/v1/feed?refresh=1` bypasses cache for fresh URLs

### Response Format

Before:
```json
{
  "active": true,
  "user_id": "123456",
  "username": "example",
  "items": [...]
}
```

After:
```json
{
  "active": true,
  "user_id": "123456",
  "username": "example",
  "items": [...],
  "fetched_at": 1724086800,
  "fetched_at_iso": "2026-08-19T16:00:00+00:00"
}
```

## WordPress Plugin Update (Required)

### Step 1: Store URL Fetch Timestamp

When saving curated feed data, also store the `fetched_at` timestamp:

```php
// When fetching and saving curated posts
$response = $this->fetch_from_hub( '/v1/feed?limit=50' );

update_option( 'lumina_curated_posts', $response['items'] );
update_option( 'lumina_curated_fetched_at', $response['fetched_at'] );
```

### Step 2: Check URL Age Before Display

Before rendering curated feeds, check if URLs are stale:

```php
function lumina_should_refresh_urls() {
	$fetched_at = get_option( 'lumina_curated_fetched_at', 0 );
	$age_in_days = ( time() - $fetched_at ) / 86400;
	
	// Refresh if older than 5 days (to stay ahead of 7-day expiration)
	return $age_in_days > 5;
}
```

### Step 3: Auto-refresh Stale URLs

Option A: Refresh on admin page load (simple)

```php
// In your admin settings page
if ( lumina_should_refresh_urls() ) {
	$response = $this->fetch_from_hub( '/v1/refresh?limit=50' );
	
	if ( ! is_wp_error( $response ) ) {
		// Update stored posts with fresh URLs
		$stored_posts = get_option( 'lumina_curated_posts', [] );
		
		foreach ( $stored_posts as &$stored_post ) {
			foreach ( $response['items'] as $fresh_post ) {
				if ( $stored_post['id'] === $fresh_post['id'] ) {
					$stored_post['image_url'] = $fresh_post['image_url'];
					break;
				}
			}
		}
		
		update_option( 'lumina_curated_posts', $stored_posts );
		update_option( 'lumina_curated_fetched_at', $response['fetched_at'] );
		
		add_settings_error(
			'lumina_messages',
			'lumina_urls_refreshed',
			'Instagram media URLs refreshed automatically.',
			'updated'
		);
	}
}
```

Option B: WP-Cron scheduled refresh (better for high-traffic sites)

```php
// Register cron event
add_action( 'admin_init', 'lumina_schedule_url_refresh' );
function lumina_schedule_url_refresh() {
	if ( ! wp_next_scheduled( 'lumina_refresh_urls_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'lumina_refresh_urls_cron' );
	}
}

// Cron callback
add_action( 'lumina_refresh_urls_cron', 'lumina_refresh_curated_urls' );
function lumina_refresh_curated_urls() {
	if ( ! lumina_should_refresh_urls() ) {
		return;
	}
	
	$hub_url = LUMINA_IG_AGENCY_HUB_URL;
	$license_key = get_option( 'lumina_license_key' );
	
	$response = wp_remote_get( 
		$hub_url . '/v1/refresh?limit=50',
		[
			'headers' => [
				'X-Lumina-License' => $license_key,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		]
	);
	
	if ( is_wp_error( $response ) ) {
		error_log( 'Lumina URL refresh failed: ' . $response->get_error_message() );
		return;
	}
	
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	
	if ( empty( $body['items'] ) ) {
		return;
	}
	
	// Update stored posts with fresh URLs
	$stored_posts = get_option( 'lumina_curated_posts', [] );
	
	foreach ( $stored_posts as &$stored_post ) {
		foreach ( $body['items'] as $fresh_post ) {
			if ( $stored_post['id'] === $fresh_post['id'] ) {
				$stored_post['image_url'] = $fresh_post['image_url'];
				$stored_post['permalink'] = $fresh_post['permalink'];
				break;
			}
		}
	}
	
	update_option( 'lumina_curated_posts', $stored_posts );
	update_option( 'lumina_curated_fetched_at', $body['fetched_at'] );
	
	error_log( 'Lumina: Refreshed Instagram URLs for ' . count( $stored_posts ) . ' curated posts' );
}
```

### Step 4: Add Manual Refresh Button

Keep the existing "Import from Instagram" button but clarify its purpose:

```php
<button class="button button-secondary">
	Refresh Instagram URLs
</button>
<p class="description">
	Click to fetch fresh media URLs from Instagram. 
	Instagram URLs expire after ~7 days, so refresh weekly for curated feeds.
</p>
```

## Testing the Fix

1. **Deploy hub updates** - Push the updated `Hub.php` to your server
2. **Test the new endpoint**:
   ```bash
   curl -H "X-Lumina-License: YOUR_KEY" \
        https://your-hub.com/v1/refresh
   ```
3. **Verify timestamps** - Check that response includes `fetched_at`
4. **Update WordPress plugin** with the changes above
5. **Test refresh flow** - Wait for URLs to age or manually trigger refresh

## Monitoring

Log when refreshes happen:

```php
if ( $refreshed ) {
	error_log( sprintf(
		'Lumina: Refreshed %d curated post URLs (last fetch was %d days old)',
		count( $posts ),
		round( $age_in_days, 1 )
	) );
}
```

Set up monitoring to alert if:
- Refresh endpoint returns errors
- `fetched_at` exceeds 6 days without refresh
- Multiple 404s on image URLs

## Recommended Settings

For curated feeds (manually selected posts displayed long-term):

- **Refresh frequency**: Every 3-5 days
- **Alert threshold**: 6 days
- **Hub cache_ttl**: Keep at 3600 (1 hour) - this is fine
- **WordPress transient**: Don't cache curated feeds longer than 5 days

For automatic feeds (latest posts):

- **No changes needed** - Hourly cache refresh keeps URLs fresh
