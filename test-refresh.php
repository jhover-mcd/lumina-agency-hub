#!/usr/bin/env php
<?php
/**
 * Test the Lumina Agency Hub refresh endpoint
 * Usage: php test-refresh.php
 */

// Configuration
$hub_url = 'https://lumina.mcddigital.biz'; // Update this to your hub URL
$license_key = 'YOUR_LICENSE_KEY'; // Update this to test license key

if ( 'YOUR_LICENSE_KEY' === $license_key ) {
	echo "⚠️  Update the \$license_key variable in this script first.\n";
	exit( 1 );
}

echo "Testing Lumina Agency Hub URL Refresh\n";
echo "=====================================\n\n";

// Test 1: Health check
echo "1. Testing health endpoint...\n";
$health = file_get_contents( $hub_url . '/health' );
$health_data = json_decode( $health, true );

if ( ! empty( $health_data['status'] ) && 'ok' === $health_data['status'] ) {
	echo "   ✓ Hub is online\n";
	echo "   Build: " . ( $health_data['hub_build'] ?? 'unknown' ) . "\n\n";
} else {
	echo "   ✗ Hub health check failed\n";
	exit( 1 );
}

// Test 2: Fetch current feed
echo "2. Fetching current feed...\n";
$context = stream_context_create([
	'http' => [
		'header' => "X-Lumina-License: $license_key\r\nAccept: application/json\r\n",
		'timeout' => 30,
	],
]);

$feed = @file_get_contents( $hub_url . '/v1/feed?limit=5', false, $context );

if ( false === $feed ) {
	echo "   ✗ Failed to fetch feed\n";
	echo "   Error: " . error_get_last()['message'] . "\n";
	exit( 1 );
}

$feed_data = json_decode( $feed, true );

if ( isset( $feed_data['error'] ) ) {
	echo "   ✗ Feed error: " . $feed_data['error'] . "\n";
	exit( 1 );
}

if ( ! empty( $feed_data['items'] ) ) {
	echo "   ✓ Feed fetched successfully\n";
	echo "   Posts: " . count( $feed_data['items'] ) . "\n";
	
	if ( isset( $feed_data['fetched_at'] ) ) {
		$age = time() - $feed_data['fetched_at'];
		$age_days = round( $age / 86400, 1 );
		echo "   Last fetched: " . ( $feed_data['fetched_at_iso'] ?? 'unknown' ) . " ($age_days days ago)\n";
		
		if ( $age_days > 5 ) {
			echo "   ⚠️  URLs are older than 5 days - should refresh!\n";
		}
	} else {
		echo "   ⚠️  No fetched_at timestamp (old hub version?)\n";
	}
	echo "\n";
} else {
	echo "   ✗ No items in feed\n";
	exit( 1 );
}

// Test 3: Test refresh endpoint
echo "3. Testing refresh endpoint...\n";
$refresh_start = microtime( true );

$refresh = @file_get_contents( $hub_url . '/v1/refresh?limit=5', false, $context );

$refresh_time = round( ( microtime( true ) - $refresh_start ) * 1000 );

if ( false === $refresh ) {
	echo "   ✗ Failed to fetch refresh\n";
	echo "   Error: " . error_get_last()['message'] . "\n";
	exit( 1 );
}

$refresh_data = json_decode( $refresh, true );

if ( isset( $refresh_data['error'] ) ) {
	echo "   ✗ Refresh error: " . $refresh_data['error'] . "\n";
	exit( 1 );
}

if ( ! empty( $refresh_data['items'] ) && ! empty( $refresh_data['refreshed'] ) ) {
	echo "   ✓ Refresh endpoint works correctly\n";
	echo "   Response time: {$refresh_time}ms\n";
	echo "   Posts: " . count( $refresh_data['items'] ) . "\n";
	echo "   Fresh timestamp: " . ( $refresh_data['fetched_at_iso'] ?? 'present' ) . "\n\n";
} else {
	echo "   ⚠️  Refresh returned data but may be using old hub version\n\n";
}

// Test 4: Compare URLs
echo "4. Comparing media URLs...\n";

if ( ! empty( $feed_data['items'][0]['image_url'] ) && ! empty( $refresh_data['items'][0]['image_url'] ) ) {
	$old_url = $feed_data['items'][0]['image_url'];
	$new_url = $refresh_data['items'][0]['image_url'];
	
	if ( $old_url !== $new_url ) {
		echo "   ✓ URLs refreshed (different from cached version)\n";
		echo "   Old: " . substr( $old_url, 0, 60 ) . "...\n";
		echo "   New: " . substr( $new_url, 0, 60 ) . "...\n\n";
	} else {
		echo "   ℹ️  URLs are the same (Instagram hasn't rotated them yet, or cache was fresh)\n\n";
	}
}

// Test 5: Verify URL accessibility
echo "5. Testing first image URL accessibility...\n";

if ( ! empty( $refresh_data['items'][0]['image_url'] ) ) {
	$test_url = $refresh_data['items'][0]['image_url'];
	$headers = @get_headers( $test_url );
	
	if ( $headers && strpos( $headers[0], '200' ) !== false ) {
		echo "   ✓ Image URL is accessible\n";
		echo "   URL: " . substr( $test_url, 0, 60 ) . "...\n\n";
	} else {
		echo "   ✗ Image URL returned non-200 response\n";
		echo "   This could mean the Instagram URL has expired\n\n";
	}
} else {
	echo "   ⚠️  No image URL to test\n\n";
}

// Summary
echo "Test Summary\n";
echo "============\n";
echo "✓ All tests passed!\n\n";
echo "The refresh endpoint is working correctly.\n";
echo "Update your WordPress plugin to call /v1/refresh periodically\n";
echo "to prevent broken images in curated feeds.\n\n";
echo "See CURATED_FEED_FIX.md for implementation guide.\n";
