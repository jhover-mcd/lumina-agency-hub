<?php
/**
 * Copy to config.php and fill in your values.
 */

return array(
	// Your single agency Instagram Graph API access token.
	'instagram_access_token' => 'YOUR_LONG_LIVED_TOKEN',

	// Secret used to protect the management UI.
	'admin_password' => 'change-this-to-a-strong-password',

	// How long to cache feed responses (seconds).
	'cache_ttl' => 3600,

	// Path to the licenses file (relative to agency-hub directory).
	'licenses_file' => __DIR__ . '/licenses.json',
);
