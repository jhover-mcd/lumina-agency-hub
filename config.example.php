<?php
/**
 * Copy to config.php and fill in your values.
 */

return array(
	// Optional legacy fallback token for licenses without their own access_token.
	'instagram_access_token' => '',

	// Instagram app credentials (Meta App Dashboard → Instagram → API setup with Instagram login).
	'instagram_app_id'     => 'YOUR_INSTAGRAM_APP_ID',
	'instagram_app_secret' => 'YOUR_INSTAGRAM_APP_SECRET',

	// Public hub URL — must match your OAuth redirect URI domain (no trailing slash).
	'hub_public_url' => 'https://lumina.mcddigital.biz',

	// Secret used to protect the management UI.
	'admin_password' => 'change-this-to-a-strong-password',

	// How long to cache feed responses (seconds).
	// Default: 3600 (1 hour) - good for automatic feeds
	// Note: For curated feeds in WordPress, the plugin should refresh URLs
	// every 3-5 days to prevent Instagram URL expiration (URLs expire ~7 days).
	// See CURATED_FEED_FIX.md for WordPress plugin implementation.
	'cache_ttl' => 3600,

	// Path to the licenses file (relative to agency-hub directory).
	'licenses_file' => __DIR__ . '/licenses.json',
);
