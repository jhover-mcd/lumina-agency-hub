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
