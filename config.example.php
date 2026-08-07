<?php
/**
 * Copy to config.php and fill in your values.
 */

return array(
	// Your single agency Instagram Graph API access token.
	'instagram_access_token' => 'YOUR_LONG_LIVED_TOKEN',

	// Instagram app credentials (Meta App Dashboard → Instagram → API setup with Instagram login).
	'instagram_app_id'     => 'YOUR_INSTAGRAM_APP_ID',
	'instagram_app_secret' => 'YOUR_INSTAGRAM_APP_SECRET',

	// Public hub URL — must match your OAuth redirect URI domain (no trailing slash).
	'hub_public_url' => 'https://lumina.mcddigital.biz',

	// Secret used to protect the management UI.
	'admin_password' => 'change-this-to-a-strong-password',

	// How long to cache feed responses (seconds).
	'cache_ttl' => 3600,

	// Path to the licenses file (relative to agency-hub directory).
	'licenses_file' => __DIR__ . '/licenses.json',
);
