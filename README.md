# Lumina Agency Hub

Host this on **your** server. It holds Instagram access tokens per client license and controls which client sites can receive feeds.

## Setup

1. Copy `config.example.php` to `config.php`
2. Copy `licenses.example.json` to `licenses.json`
3. Add your Meta Instagram app credentials to `config.php`
4. Point your web server document root to `agency-hub/public/`

Keep `config.php` and `licenses.json` **outside** the public web root if possible, or ensure your server blocks direct access to parent directories.

## Management UI

Open `/manage` on your hub domain and sign in with the admin password from `config.php`.

From there you can:

- **Connect Instagram per client** via OAuth from each license row in `/manage`
- Add a license key per client site
- Each license stores its own Instagram token and User ID
- **Revoke** a license to cut the feed remotely
- Change the Instagram User ID without touching the client site

## Instagram OAuth setup

1. In `config.php`, set `instagram_app_id`, `instagram_app_secret`, and `hub_public_url`.
2. In Meta App Dashboard → Instagram → **API setup with Instagram login** → Business login settings, add this redirect URI:

   `https://your-hub-domain.com/oauth/callback`

   It must match `hub_public_url` + `/oauth/callback` exactly.
3. Sign in to `/manage`.
4. In `/manage`, click **Connect Instagram** on the client row. The client (or you) authorizes on Instagram. The hub saves the long-lived token and User ID to that license automatically.
5. Paste the license key into the WordPress plugin settings on the client site.

`config.php` may still include a legacy `instagram_access_token` as a fallback for licenses that do not have their own token yet. For multiple clients, connect each row separately instead of relying on the global token.

Use scope **`instagram_business_basic`** only for feed reads. In Development mode, add the Instagram account as an app tester first. For production clients, submit App Review and switch the app to Live mode.

## API Endpoints

All API requests require the license key in the **`X-Lumina-License`** request header. Do not pass license keys in URLs or query strings.

### `GET /v1/feed?limit=12`

Headers:

```http
X-Lumina-License: SITE_KEY
Accept: application/json
```

Returns normalized feed items for the license.

**Optional query parameters:**

- `limit` (int, 1-50): Number of posts to fetch (default: 12)
- `refresh=1`: Force bypass cache and fetch fresh URLs from Instagram

**Response includes:**

- `items`: Array of Instagram posts with media URLs
- `fetched_at`: Unix timestamp when URLs were last fetched from Instagram
- `fetched_at_iso`: ISO 8601 timestamp of last fetch

**Important:** Instagram media URLs expire after several days. For curated feeds that display content longer than a week, use `/v1/refresh` periodically to get fresh URLs.

### `GET /v1/refresh?limit=12`

Headers:

```http
X-Lumina-License: SITE_KEY
Accept: application/json
```

Force-refreshes the feed by clearing cache and fetching fresh media URLs from Instagram. Use this endpoint when:

- Images have stopped loading (URLs expired)
- You need guaranteed fresh media URLs
- Implementing automatic URL refresh in curated feeds

Returns the same payload as `/v1/feed` with `refreshed: true`.

### `GET /v1/status`

Headers:

```http
X-Lumina-License: SITE_KEY
Accept: application/json
```

Returns account status for connection tests.

### `GET /health`

Returns hub health status.

## Client WordPress Sites

Each client site only needs the **license key** entered in **Lumina Instagram → Settings** inside WordPress. The hub URL is baked into the plugin.

## Instagram Media URL Expiration

**Important:** Instagram media URLs (CDN links) expire after approximately 5-7 days. This is an Instagram limitation, not a bug.

### How it affects different feed modes:

1. **Automatic feeds** (displaying latest posts): URLs refresh automatically every hour (configurable via `cache_ttl`)
2. **Curated feeds** (manually selected posts): URLs can become stale if posts are stored locally in WordPress

### Preventing broken images in curated feeds:

**Option 1: Periodic refresh (recommended)**

Configure the WordPress plugin to call `/v1/refresh` automatically:

- Once per day for curated content
- Every 3-5 days minimum to stay ahead of Instagram's expiration
- On page load if `fetched_at` timestamp is older than 5 days

**Option 2: On-demand refresh**

Users can manually click "Import from Instagram" when images break (current behavior).

**Option 3: Fetch-on-render**

Instead of storing full feed responses, store only post IDs and fetch fresh URLs from `/v1/feed` when rendering (adds API calls but guarantees fresh URLs).

### Checking URL freshness

The feed response includes timestamps:

```json
{
  "fetched_at": 1724086800,
  "fetched_at_iso": "2026-08-19T16:00:00+00:00",
  "items": [...]
}
```

Compare `fetched_at` against current time. If older than 5 days (432000 seconds), call `/v1/refresh` to prevent broken images.

## DigitalOcean Droplet Deploy

1. Create an Ubuntu droplet and point your subdomain (e.g. `feeds.youragency.com`) at its IP.
2. Install Apache or Nginx, PHP 8.1+, and enable HTTPS (Certbot recommended).
3. Upload the `agency-hub/` folder to the server, e.g. `/var/www/lumina-agency-hub/`.
4. Set the vhost document root to `/var/www/lumina-agency-hub/public`.
5. Create `config.php` and `licenses.json` on the server with production values.
6. Install outbound HTTPS support: `sudo apt install -y php-curl ca-certificates`
7. Ensure `cache/` is writable by the web server user: `chown -R www-data:www-data cache`.
8. Update `LUMINA_IG_AGENCY_HUB_URL` in the WordPress plugin before distributing to clients.

### Apache example

```apache
<VirtualHost *:80>
    ServerName feeds.youragency.com
    DocumentRoot /var/www/lumina-agency-hub/public

    <Directory /var/www/lumina-agency-hub/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Kill Switch

Set `"active": false` for a license in `licenses.json`, or click **Revoke Feed** in `/manage`.

The client site stops receiving new feed data on its next cache refresh (default: within 1 hour, or immediately if cache is flushed).
