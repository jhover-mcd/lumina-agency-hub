<?php
/**
 * Lumina Agency Hub — public entry point.
 */

$config_file = dirname( __DIR__ ) . '/config.php';

if ( ! file_exists( $config_file ) ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( array( 'message' => 'Agency hub is not configured. Copy config.example.php to config.php.' ) );
	exit;
}

$config = require $config_file;
require_once dirname( __DIR__ ) . '/includes/Hub.php';

if ( PHP_SESSION_NONE === session_status() ) {
	session_start(
		array(
			'cookie_httponly' => true,
			'cookie_secure'   => ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
				|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ),
			'cookie_samesite' => 'Lax',
		)
	);
}

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$path = rtrim( $path, '/' ) ?: '/';

$hub = new Lumina_Agency_Hub( $config );
$hub->handle_request( $path );
