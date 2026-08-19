# Changelog - August 19, 2026 Update

## Version: 2026-08-19-url-refresh

### Problem Solved

**Broken images in curated Instagram feeds after ~1 week**

Instagram media URLs (CDN links) expire after approximately 5-7 days. When using curated feeds where posts are manually selected and stored in WordPress, these URLs become stale and images break. Users previously had to manually click "Import from Instagram" to fix broken images.

### What's New

#### 1. New API Endpoint: `/v1/refresh`

Force-refreshes the feed by bypassing cache and fetching fresh URLs from Instagram.

**Usage:**
```bash
curl -H "X-Lumina-License: YOUR_LICENSE_KEY" \
     https://your-hub.com/v1/refresh?limit=12
```

**Response:**
```json
{
  "active": true,
  "user_id": "123456",
  "username": "example_account",
  "items": [...],
  "fetched_at": 1724086800,
  "fetched_at_iso": "2026-08-19T16:00:00+00:00",
  "refreshed": true
}
```

#### 2. URL Freshness Timestamps

Both `/v1/feed` and `/v1/refresh` now include:
- `fetched_at` - Unix timestamp of when URLs were last fetched from Instagram
- `fetched_at_iso` - Human-readable ISO 8601 timestamp

This allows WordPress plugins to check URL age and refresh proactively.

#### 3. Cache Bypass Parameter

`/v1/feed` now accepts optional `refresh=1` query parameter:

```bash
# Bypass cache and get fresh URLs
GET /v1/feed?limit=12&refresh=1
```

#### 4. Improved Documentation

- Updated README.md with URL expiration explanation
- Added CURATED_FEED_FIX.md with WordPress plugin implementation guide
- Added test-refresh.php script for testing the new endpoint
- Updated config.example.php with cache_ttl guidance

### Files Changed

- `includes/Hub.php` - Added refresh endpoint, timestamps, and cache bypass
- `README.md` - Documented new endpoints and URL expiration behavior
- `config.example.php` - Added comments about cache_ttl for curated feeds
- `CURATED_FEED_FIX.md` - Complete implementation guide for WordPress
- `test-refresh.php` - Test script for new functionality

### Deployment Steps

#### For Agency Hub (This Repository)

1. **Deploy updated files** to your server:
   ```bash
   # Via git
   git pull origin main
   
   # Or via FTP/SSH
   # Upload: includes/Hub.php
   ```

2. **Test the new endpoint**:
   ```bash
   # Replace with your actual values
   curl -H "X-Lumina-License: YOUR_LICENSE_KEY" \
        https://lumina.mcddigital.biz/v1/refresh
   ```

3. **Verify health check** shows new build:
   ```bash
   curl https://lumina.mcddigital.biz/health
   # Should show: "hub_build": "2026-08-19-url-refresh"
   ```

#### For WordPress Plugin

You need to update the WordPress plugin to automatically refresh URLs. See `CURATED_FEED_FIX.md` for complete implementation guide.

**Quick summary of plugin changes needed:**

1. Store `fetched_at` timestamp when saving curated posts
2. Check URL age before display (alert if > 5 days old)
3. Automatically call `/v1/refresh` when URLs are stale
4. Option A: Refresh on admin page load (simple)
5. Option B: WP-Cron daily refresh (recommended)

### Testing

Run the included test script:

```bash
# Update the script with your hub URL and license key first
php test-refresh.php
```

Expected output:
```
Testing Lumina Agency Hub URL Refresh
=====================================

1. Testing health endpoint...
   ✓ Hub is online
   Build: 2026-08-19-url-refresh

2. Fetching current feed...
   ✓ Feed fetched successfully
   Posts: 5
   Last fetched: 2026-08-19T16:00:00+00:00 (0.0 days ago)

3. Testing refresh endpoint...
   ✓ Refresh endpoint works correctly
   Response time: 1234ms
   Posts: 5
   Fresh timestamp: 2026-08-19T16:01:30+00:00

4. Comparing media URLs...
   ✓ URLs refreshed (different from cached version)

5. Testing first image URL accessibility...
   ✓ Image URL is accessible

Test Summary
============
✓ All tests passed!
```

### Backward Compatibility

✅ **Fully backward compatible**

- Existing `/v1/feed` endpoint works exactly as before
- Added fields (`fetched_at`, `fetched_at_iso`) are optional - old plugins will ignore them
- No breaking changes to response format
- Old WordPress plugins will continue to work (but won't auto-refresh URLs)

### Recommended Actions

#### Immediate (Required)
1. ✅ Deploy updated `Hub.php` to your agency hub server
2. ✅ Test `/v1/refresh` endpoint works

#### Short-term (Recommended)
1. 📋 Update WordPress plugin with auto-refresh logic (see CURATED_FEED_FIX.md)
2. 📋 Test on one client site first
3. 📋 Roll out to all client sites

#### Long-term (Optional)
1. 📊 Monitor refresh frequency in logs
2. 📊 Set up alerts for URLs older than 6 days
3. 📋 Add admin notification in WordPress when URLs need refresh

### Support

If you encounter issues:

1. **Check hub build**: Visit `/health` endpoint - should show `2026-08-19-url-refresh`
2. **Test endpoint**: Run `test-refresh.php` script
3. **Check logs**: Look for errors in PHP error logs
4. **Verify cache**: Ensure `cache/` directory is writable

### Future Enhancements

Potential future improvements (not in this release):

- [ ] Per-license refresh endpoint: `/v1/refresh/LICENSE_KEY`
- [ ] Webhook to notify WordPress when URLs are refreshed
- [ ] Admin UI in `/manage` showing URL age per license
- [ ] Automatic scheduled refresh on the hub side
- [ ] URL expiration prediction based on Instagram response headers

### Technical Details

**How Instagram URLs expire:**

Instagram's Graph API returns CDN URLs that are signed and time-limited. The exact expiration varies but is typically:
- Short-lived tokens: ~1 hour
- Long-lived media URLs: 5-7 days

These URLs cannot be "refreshed" - you must fetch new ones from the Instagram API.

**Why the hub cache is fine:**

The hub's 1-hour cache (`cache_ttl: 3600`) works well for automatic feeds because:
- URLs are refreshed every hour
- Latest posts are fetched every hour
- URLs stay fresh

**Why curated feeds break:**

Curated feeds store selected posts for weeks/months:
- WordPress saves specific posts to database
- Those posts contain 7-day URLs
- After a week, URLs expire
- Images break

**The solution:**

WordPress must periodically fetch fresh URLs for the same post IDs:
- Call `/v1/refresh` to get latest feed
- Match stored post IDs with fresh post IDs
- Update only the `image_url` field
- Keep the curation (which posts to show)

---

**Build:** 2026-08-19-url-refresh  
**Compatible with:** PHP 8.1+  
**Breaking changes:** None
