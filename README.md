# Lumina Agency Hub

Host this on **your** server. It holds the single Instagram access token and controls which client sites can receive feeds.

## Setup

1. Copy `config.example.php` to `config.php`
2. Copy `licenses.example.json` to `licenses.json`
3. Add your agency Instagram access token to `config.php`
4. Point your web server document root to `agency-hub/public/`

Keep `config.php` and `licenses.json` **outside** the public web root if possible, or ensure your server blocks direct access to parent directories.

## Management UI

Open `/manage` on your hub domain and sign in with the admin password from `config.php`.

From there you can:

- Add a license key per client site
- Assign any Instagram User ID to each license
- **Revoke** a license to cut the feed remotely
- Change the Instagram User ID without touching the client site

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
6. Ensure `cache/` is writable by the web server user: `chown -R www-data:www-data cache`.
7. Update `LUMINA_IG_AGENCY_HUB_URL` in the WordPress plugin before distributing to clients.

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
