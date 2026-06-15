<?php
/**
 * Lumina Agency Hub — license and feed management.
 */

class Lumina_Agency_Hub {
	private $config;
	private $licenses;

	public function __construct( array $config ) {
		$this->config = $config;
		$this->licenses = $this->load_licenses();
	}

	public function handle_request( $path ) {
		if ( '/' === $path ) {
			$this->redirect( '/manage' );
		}

		if ( '/health' === $path ) {
			$this->json(
				array(
					'status'  => 'ok',
					'service' => 'lumina-agency-hub',
					'demo'    => ! empty( $this->config['demo_mode'] ),
				)
			);
		}

		if ( '/v1/feed' === $path ) {
			$this->json( $this->get_feed() );
		}

		if ( '/v1/status' === $path ) {
			$this->json( $this->get_status() );
		}

		if ( '/manage' === $path && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$this->validate_csrf();
			$this->handle_manage_post();
		}

		if ( '/manage' === $path ) {
			$this->render_manage_page();
			exit;
		}

		$this->json( array( 'message' => 'Not found. Try /manage' ), 404 );
	}

	private function get_license_key() {
		if ( ! empty( $_SERVER['HTTP_X_LUMINA_LICENSE'] ) ) {
			return trim( (string) $_SERVER['HTTP_X_LUMINA_LICENSE'] );
		}

		return '';
	}

	private function resolve_license( $license_key ) {
		if ( empty( $license_key ) || empty( $this->licenses[ $license_key ] ) ) {
			return array( 'error' => empty( $license_key ) ? 'Missing license key. Send the X-Lumina-License header.' : 'Invalid license key.', 'code' => 403 );
		}

		$license = $this->licenses[ $license_key ];

		if ( empty( $license['active'] ) ) {
			return array( 'error' => 'This feed has been revoked by the agency.', 'code' => 403 );
		}

		if ( empty( $license['user_id'] ) ) {
			return array( 'error' => 'No Instagram User ID is assigned to this license.', 'code' => 422 );
		}

		return $license;
	}

	private function get_feed() {
		$license_key = $this->get_license_key();
		$license     = $this->resolve_license( $license_key );

		if ( isset( $license['error'] ) ) {
			return $this->error_payload( $license['error'], $license['code'] );
		}

		$limit = min( 50, max( 1, (int) ( $_GET['limit'] ?? 12 ) ) );
		$cache = $this->read_cache( $license_key, $limit );

		if ( null !== $cache ) {
			return $cache;
		}

		if ( ! empty( $this->config['demo_mode'] ) ) {
			$items   = $this->demo_media( $license, $limit );
			$profile = $this->demo_profile( $license );
		} else {
			$items = $this->fetch_instagram_media( $license['user_id'], $limit );

			if ( isset( $items['error'] ) ) {
				return $this->error_payload( $items['error'], $items['code'] ?? 500 );
			}

			$profile = $this->fetch_instagram_profile( $license['user_id'] );

			if ( isset( $profile['error'] ) ) {
				return $this->error_payload( $profile['error'], $profile['code'] ?? 500 );
			}
		}
		$payload = array(
			'active'   => true,
			'user_id'  => $license['user_id'],
			'label'    => $license['label'] ?? '',
			'username' => $profile['username'] ?? '',
			'items'    => $items,
		);

		$this->write_cache( $license_key, $limit, $payload );

		return $payload;
	}

	private function get_status() {
		$license_key = $this->get_license_key();
		$license     = $this->resolve_license( $license_key );

		if ( isset( $license['error'] ) ) {
			return $this->error_payload( $license['error'], $license['code'] );
		}

		if ( ! empty( $this->config['demo_mode'] ) ) {
			$profile = $this->demo_profile( $license );
		} else {
			$profile = $this->fetch_instagram_profile( $license['user_id'] );

			if ( isset( $profile['error'] ) ) {
				return $this->error_payload( $profile['error'], $profile['code'] ?? 500 );
			}
		}

		return array(
			'active'       => true,
			'user_id'      => $license['user_id'],
			'label'        => $license['label'] ?? '',
			'username'     => $profile['username'] ?? '',
			'account_type' => $profile['account_type'] ?? '',
			'media_count'  => $profile['media_count'] ?? 0,
		);
	}

	private function demo_profile( array $license ) {
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $license['label'] ?? 'demo' ) ) ?: 'demo';

		return array(
			'username'     => $slug . '_studio',
			'account_type' => 'BUSINESS',
			'media_count'  => 24,
		);
	}

	private function demo_media( array $license, $limit ) {
		$items  = array();
		$slug   = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $license['label'] ?? 'demo' ) ) ?: 'demo';
		$labels = array( 'Studio session', 'New project drop', 'Behind the scenes', 'Fresh install', 'Weekend mood', 'Team spotlight' );

		for ( $i = 0; $i < $limit; $i++ ) {
			$seed = md5( ( $license['user_id'] ?? 'demo' ) . $i );
			$items[] = array(
				'id'         => 'demo-' . $i,
				'caption'    => ( $labels[ $i % count( $labels ) ] ?? 'Demo post' ) . ' #' . ( $i + 1 ),
				'media_type' => 2 === $i % 5 ? 'VIDEO' : 'IMAGE',
				'image_url'  => 'https://picsum.photos/seed/' . $seed . '/800/800',
				'permalink'  => 'https://instagram.com/p/demo' . $i,
				'timestamp'  => gmdate( 'c', time() - ( $i * 86400 ) ),
				'username'   => $slug . '_studio',
				'date'       => gmdate( 'M j, Y', time() - ( $i * 86400 ) ),
			);
		}

		return $items;
	}

	private function fetch_instagram_media( $user_id, $limit ) {
		$url = 'https://graph.instagram.com/' . rawurlencode( $user_id ) . '/media?' . http_build_query(
			array(
				'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username',
				'limit'        => $limit,
				'access_token' => $this->config['instagram_access_token'],
			)
		);

		$response = $this->remote_get( $url );

		if ( isset( $response['error'] ) ) {
			return $response;
		}

		$items = array();

		foreach ( $response['data'] ?? array() as $item ) {
			$media_type = $item['media_type'] ?? 'IMAGE';
			$image_url  = $item['media_url'] ?? '';

			if ( 'VIDEO' === $media_type && ! empty( $item['thumbnail_url'] ) ) {
				$image_url = $item['thumbnail_url'];
			}

			$items[] = array(
				'id'         => (string) ( $item['id'] ?? '' ),
				'caption'    => (string) ( $item['caption'] ?? '' ),
				'media_type' => (string) $media_type,
				'image_url'  => (string) $image_url,
				'permalink'  => (string) ( $item['permalink'] ?? '' ),
				'timestamp'  => (string) ( $item['timestamp'] ?? '' ),
				'username'   => (string) ( $item['username'] ?? '' ),
				'date'       => $this->format_date( $item['timestamp'] ?? '' ),
			);
		}

		return $items;
	}

	private function fetch_instagram_profile( $user_id ) {
		$url = 'https://graph.instagram.com/' . rawurlencode( $user_id ) . '?' . http_build_query(
			array(
				'fields'       => 'id,username,account_type,media_count',
				'access_token' => $this->config['instagram_access_token'],
			)
		);

		return $this->remote_get( $url );
	}

	private function remote_get( $url ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'timeout' => 20,
					'header'  => "Accept: application/json\r\n",
				),
			)
		);

		$body = @file_get_contents( $url, false, $context );

		if ( false === $body ) {
			return array( 'error' => 'Instagram API request failed.', 'code' => 502 );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array( 'error' => 'Invalid Instagram API response.', 'code' => 502 );
		}

		if ( ! empty( $data['error']['message'] ) ) {
			return array( 'error' => $data['error']['message'], 'code' => 400 );
		}

		return $data;
	}

	private function cache_file( $license_key, $limit ) {
		$dir = __DIR__ . '/../cache';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		return $dir . '/' . md5( $license_key . '_' . $limit ) . '.json';
	}

	private function read_cache( $license_key, $limit ) {
		$file = $this->cache_file( $license_key, $limit );

		if ( ! file_exists( $file ) ) {
			return null;
		}

		$raw = json_decode( (string) file_get_contents( $file ), true );

		if ( empty( $raw['expires_at'] ) || time() > (int) $raw['expires_at'] ) {
			return null;
		}

		return $raw['payload'] ?? null;
	}

	private function write_cache( $license_key, $limit, $payload ) {
		file_put_contents(
			$this->cache_file( $license_key, $limit ),
			json_encode(
				array(
					'expires_at' => time() + (int) ( $this->config['cache_ttl'] ?? 3600 ),
					'payload'    => $payload,
				)
			)
		);
	}

	private function load_licenses() {
		$file = $this->config['licenses_file'];

		if ( ! file_exists( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $data ) ? $data : array();
	}

	private function save_licenses() {
		file_put_contents(
			$this->config['licenses_file'],
			json_encode( $this->licenses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}

	private function handle_manage_post() {
		if ( ! $this->is_admin_authenticated() ) {
			$this->redirect_manage( 'login_error=1' );
		}

		$action      = $_POST['action'] ?? '';
		$license_key = trim( (string) ( $_POST['license_key'] ?? '' ) );

		if ( 'revoke' === $action && isset( $this->licenses[ $license_key ] ) ) {
			$this->licenses[ $license_key ]['active'] = false;
			$this->save_licenses();
			$this->redirect_manage( 'revoked=1' );
		}

		if ( 'activate' === $action && isset( $this->licenses[ $license_key ] ) ) {
			$this->licenses[ $license_key ]['active'] = true;
			$this->save_licenses();
			$this->redirect_manage( 'activated=1' );
		}

		if ( 'add' === $action && '' !== $license_key ) {
			$this->licenses[ $license_key ] = array(
				'label'   => trim( (string) ( $_POST['label'] ?? '' ) ),
				'user_id' => trim( (string) ( $_POST['user_id'] ?? '' ) ),
				'active'  => true,
			);
			$this->save_licenses();
			$this->redirect_manage( 'added=1' );
		}

		if ( 'update_user_id' === $action && isset( $this->licenses[ $license_key ] ) ) {
			$this->licenses[ $license_key ]['user_id'] = trim( (string) ( $_POST['user_id'] ?? '' ) );
			$this->save_licenses();
			$this->redirect_manage( 'updated=1' );
		}

		$this->redirect_manage();
	}

	private function render_manage_page() {
		if ( ! $this->is_admin_authenticated() ) {
			$this->render_login_form();
			return;
		}

		$this->render_template(
			'manage.php',
			array(
				'page_title'   => 'Manage Licenses',
				'page_heading' => 'License control center',
				'page_intro'   => 'Manage client site licenses, assign Instagram User IDs, and revoke feeds remotely.',
				'licenses'     => $this->licenses,
				'demo_mode'    => ! empty( $this->config['demo_mode'] ),
			)
		);
	}

	private function get_csrf_token() {
		if ( empty( $_SESSION['lumina_hub_csrf'] ) ) {
			$_SESSION['lumina_hub_csrf'] = bin2hex( random_bytes( 32 ) );
		}

		return $_SESSION['lumina_hub_csrf'];
	}

	private function validate_csrf() {
		$token    = $_POST['_hub_csrf'] ?? '';
		$expected = $_SESSION['lumina_hub_csrf'] ?? '';

		if ( '' === $expected || ! hash_equals( $expected, (string) $token ) ) {
			$this->redirect_manage( 'csrf_error=1' );
		}
	}

	private function is_https() {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
			return true;
		}

		return isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'];
	}

	private function render_login_form() {
		$this->render_template(
			'login.php',
			array(
				'page_title'   => 'Sign In',
				'page_heading' => 'Welcome back',
				'page_intro'   => 'Sign in to manage Lumina client licenses and Instagram feed access.',
				'narrow'       => true,
				'error'        => ! empty( $_GET['login_error'] ),
			)
		);
	}

	private function render_template( $template, array $vars = array() ) {
		$template_path = __DIR__ . '/../templates/' . ltrim( $template, '/' );

		if ( ! file_exists( $template_path ) ) {
			$this->json( array( 'message' => 'Template not found.' ), 500 );
		}

		if ( ! defined( 'LUMINA_HUB_RENDER' ) ) {
			define( 'LUMINA_HUB_RENDER', true );
		}

		extract( $vars, EXTR_SKIP );
		$csrf_token = $this->get_csrf_token();

		ob_start();
		include $template_path;
		$content = ob_get_clean();

		include __DIR__ . '/../templates/layout.php';
	}

	private function is_admin_authenticated() {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && isset( $_POST['hub_password'] ) ) {
			if ( hash_equals( (string) $this->config['admin_password'], (string) $_POST['hub_password'] ) ) {
				setcookie(
					'lumina_hub_auth',
					hash( 'sha256', $this->config['admin_password'] ),
					array(
						'expires'  => time() + 86400,
						'path'     => '/',
						'secure'   => $this->is_https(),
						'httponly' => true,
						'samesite' => 'Strict',
					)
				);
				$this->redirect_manage();
			}

			$this->redirect_manage( 'login_error=1' );
		}

		$cookie = $_COOKIE['lumina_hub_auth'] ?? '';

		return hash_equals( hash( 'sha256', (string) $this->config['admin_password'] ), (string) $cookie );
	}

	private function redirect( $path ) {
		header( 'Location: ' . $path );
		exit;
	}

	private function redirect_manage( $query = '' ) {
		$location = '/manage';

		if ( '' !== $query ) {
			$location .= '?' . $query;
		}

		$this->redirect( $location );
	}

	private function format_date( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return '';
		}

		$time = strtotime( $timestamp );

		return $time ? gmdate( 'M j, Y', $time ) : '';
	}

	private function error_payload( $message, $code ) {
		return array(
			'message' => $message,
			'error'   => $message,
			'code'    => $code,
		);
	}

	private function json( $payload, $status = 200 ) {
		if ( isset( $payload['code'] ) && isset( $payload['error'] ) && 200 === $status ) {
			$status = (int) $payload['code'];
		}

		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $payload );
		exit;
	}
}
