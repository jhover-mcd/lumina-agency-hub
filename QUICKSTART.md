# Quick Start Guide - Fix Broken Images in Curated Feeds

## 🔍 What's the Issue?

Your curated Instagram feed images break after ~1 week because Instagram's media URLs expire. This is normal Instagram behavior.

## ✅ Solution Overview

**Hub Side (This Repo):** ✓ Fixed - New refresh endpoint and timestamps added  
**WordPress Plugin:** ⚠️ Needs update - Must call refresh endpoint periodically

---

## 📦 Step 1: Deploy Hub Updates (5 minutes)

### Option A: Via Git (Recommended)

```bash
# SSH into your server
cd /var/www/lumina-agency-hub
git pull origin main
```

### Option B: Manual Upload

Upload only this file via FTP/SFTP:
- `includes/Hub.php`

### Verify Deployment

```bash
curl https://lumina.mcddigital.biz/health

# Should return:
# {"status":"ok","service":"lumina-agency-hub","hub_build":"2026-08-19-url-refresh","demo":false}
```

✅ If you see `"hub_build":"2026-08-19-url-refresh"` → Hub is updated!

---

## 🧪 Step 2: Test New Endpoint (2 minutes)

```bash
curl -H "X-Lumina-License: YOUR_LICENSE_KEY" \
     https://lumina.mcddigital.biz/v1/refresh?limit=5

# Should return JSON with:
# - "items": [array of posts]
# - "fetched_at": 1724086800
# - "fetched_at_iso": "2026-08-19T16:00:00+00:00"
# - "refreshed": true
```

Or use the test script:

```bash
# Edit test-refresh.php with your license key first
php test-refresh.php
```

✅ If you see fresh timestamps → Refresh endpoint works!

---

## 🔧 Step 3: Update WordPress Plugin (30 minutes)

### What to Add to Your Plugin

**1. Store URL age when importing:**

```php
// When user clicks "Import from Instagram"
$response = $this->fetch_from_hub( '/v1/refresh?limit=50' );

update_option( 'lumina_curated_posts', $response['items'] );
update_option( 'lumina_curated_fetched_at', $response['fetched_at'] ); // NEW
```

**2. Check URL age before display:**

```php
function lumina_need_url_refresh() {
    $fetched_at = get_option( 'lumina_curated_fetched_at', 0 );
    $days_old = ( time() - $fetched_at ) / 86400;
    return $days_old > 5; // Refresh if older than 5 days
}
```

**3. Auto-refresh stale URLs (add to admin page load):**

```php
// In your settings page render function
if ( lumina_need_url_refresh() ) {
    $response = $this->fetch_from_hub( '/v1/refresh?limit=50' );
    
    if ( ! is_wp_error( $response ) && ! empty( $response['items'] ) ) {
        // Update image URLs in stored posts
        $stored = get_option( 'lumina_curated_posts', [] );
        
        foreach ( $stored as &$post ) {
            foreach ( $response['items'] as $fresh ) {
                if ( $post['id'] === $fresh['id'] ) {
                    $post['image_url'] = $fresh['image_url'];
                    break;
                }
            }
        }
        
        update_option( 'lumina_curated_posts', $stored );
        update_option( 'lumina_curated_fetched_at', $response['fetched_at'] );
        
        add_settings_error( 'lumina', 'refreshed', '✓ Instagram URLs refreshed', 'updated' );
    }
}
```

**4. Add visual indicator in admin:**

```php
$fetched_at = get_option( 'lumina_curated_fetched_at', 0 );
if ( $fetched_at ) {
    $days_old = round( ( time() - $fetched_at ) / 86400, 1 );
    $status = $days_old > 5 ? '⚠️ Old' : '✓ Fresh';
    
    echo "<p>Instagram URLs: $status (fetched $days_old days ago)</p>";
    
    if ( $days_old > 6 ) {
        echo '<div class="notice notice-warning">';
        echo '<p>Instagram URLs are old and may break soon. Click "Import from Instagram" to refresh.</p>';
        echo '</div>';
    }
}
```

See **CURATED_FEED_FIX.md** for complete code examples including WP-Cron approach.

---

## 📋 Quick Reference

### New Endpoints

| Endpoint | Purpose | When to Use |
|----------|---------|-------------|
| `/v1/feed` | Get feed (cached) | Normal display |
| `/v1/feed?refresh=1` | Get feed (bypass cache) | Force fresh URLs |
| `/v1/refresh` | Force refresh + clear cache | URL refresh in plugin |

### Response Fields (New)

```json
{
  "items": [...],
  "fetched_at": 1724086800,        // Unix timestamp - NEW
  "fetched_at_iso": "2026-08-19...", // ISO date - NEW
  "refreshed": true                 // Only in /v1/refresh - NEW
}
```

### Recommendations

| Feed Type | Cache Duration | Refresh Frequency |
|-----------|---------------|------------------|
| Automatic (latest posts) | 1 hour | Automatic via cache |
| Curated (selected posts) | 5 days max | Every 3-5 days |
| High traffic | 30 min | Via WP-Cron |

---

## 🚨 Troubleshooting

### Hub not updating?

```bash
# Check file was uploaded
ls -la /var/www/lumina-agency-hub/includes/Hub.php

# Check for PHP errors
tail -f /var/log/php8.1-fpm.log
tail -f /var/log/apache2/error.log

# Test health endpoint
curl https://lumina.mcddigital.biz/health
```

### Refresh endpoint returns error?

```bash
# Test with verbose output
curl -v -H "X-Lumina-License: YOUR_KEY" \
     https://lumina.mcddigital.biz/v1/refresh

# Common issues:
# - Missing X-Lumina-License header
# - Invalid license key
# - License not active
# - No Instagram token for that license
```

### URLs still breaking?

1. Check when last refreshed: Look at `fetched_at` in response
2. Verify refresh happens automatically: Add logging to WordPress plugin
3. Test URL directly: Copy an `image_url` and open in browser
4. Check Instagram token: Make sure it's not expired (reconnect in `/manage`)

---

## 📞 Need Help?

1. **Read documentation:**
   - `CURATED_FEED_FIX.md` - Full implementation guide
   - `CHANGELOG.md` - Technical details
   - `README.md` - API documentation

2. **Run tests:**
   ```bash
   php test-refresh.php
   ```

3. **Check logs:**
   - Hub server: PHP error logs
   - WordPress: Debug log (`WP_DEBUG_LOG`)

---

## ✨ Summary

**The Fix:**
1. ✅ Hub now provides `/v1/refresh` endpoint
2. ✅ Hub includes `fetched_at` timestamps
3. ⚠️ WordPress plugin must call refresh every 3-5 days

**Result:**
- No more broken images in curated feeds
- Automatic URL refresh before they expire
- Better user experience (no manual imports needed)

**Next Steps:**
1. Deploy hub updates (Step 1)
2. Test refresh endpoint (Step 2)
3. Update WordPress plugin (Step 3)
4. Monitor for a week to confirm fix

---

**Last Updated:** August 19, 2026  
**Hub Build:** 2026-08-19-url-refresh  
**Status:** ✅ Ready to deploy
