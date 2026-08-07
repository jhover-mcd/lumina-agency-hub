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
					'status'    => 'ok',
					'service'   => 'lumina-agency-hub',
					'hub_build' => '2026-08-07-me-media-v2',
					'demo'      => ! empty( $this->config['demo_mode'] ),
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

		if ( '/oauth/start' === $path ) {
			$this->handle_oauth_start();
		}

		if ( '/oauth/callback' === $path ) {
			$this->handle_oauth_callback();
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

		if ( empty( $this->config['demo_mode'] ) ) {
			$user_check = $this->validate_license_user_id( $license );
			if ( null !== $user_check ) {
				return $user_check;
			}
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

	private function instagram_graph_url( $path, array $query = array() ) {
		$url = 'https://graph.instagram.com/v22.0/' . ltrim( $path, '/' );

		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		return $url;
	}

	private function resolve_account_from_token( $access_token ) {
		$profile = $this->remote_get(
			$this->instagram_graph_url(
				'me',
				array(
					'fields'       => 'user_id,username,account_type,id',
					'access_token' => $access_token,
				)
			)
		);

		if ( isset( $profile['error'] ) ) {
			return $profile;
		}

		$user_id = (string) ( $profile['user_id'] ?? '' );

		if ( '' === $user_id ) {
			return array(
				'error' => 'Instagram /me did not return user_id. Confirm this is a Business or Creator account authorized for instagram_business_basic.',
				'code'  => 502,
			);
		}

		return array(
			'user_id'       => $user_id,
			'username'      => (string) ( $profile['username'] ?? '' ),
			'account_type'  => (string) ( $profile['account_type'] ?? '' ),
			'app_scoped_id' => (string) ( $profile['id'] ?? '' ),
		);
	}

	private function fetch_instagram_media( $user_id, $limit ) {
		unset( $user_id );

		$url = $this->instagram_graph_url(
			'me/media',
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
		unset( $user_id );

		return $this->fetch_instagram_profile_for_token();
	}

	private function fetch_instagram_profile_for_token() {
		$account = $this->resolve_account_from_token( (string) $this->config['instagram_access_token'] );

		if ( isset( $account['error'] ) ) {
			return $account;
		}

		return array(
			'id'           => $account['user_id'],
			'user_id'      => $account['user_id'],
			'username'     => $account['username'],
			'account_type' => $account['account_type'],
			'media_count'  => 0,
		);
	}

	private function normalize_instagram_profile( $profile ) {
		if ( ! is_array( $profile ) || isset( $profile['error'] ) ) {
			return $profile;
		}

		if ( ! empty( $profile['user_id'] ) ) {
			$profile['id'] = (string) $profile['user_id'];
		}

		return $profile;
	}

	private function validate_license_user_id( $license ) {
		$profile = $this->fetch_instagram_profile_for_token();

		if ( isset( $profile['error'] ) ) {
			return null;
		}

		$token_user_id    = (string) ( $profile['user_id'] ?? $profile['id'] ?? '' );
		$license_user_id  = (string) ( $license['user_id'] ?? '' );

		if ( '' === $token_user_id || '' === $license_user_id ) {
			return null;
		}

		if ( $token_user_id !== $license_user_id ) {
			return array(
				'error' => 'License User ID does not match the Instagram account for this token. In /manage, copy the "License User ID" from Current token account and paste it into this license.',
				'code'  => 422,
			);
		}

		return null;
	}

	private function remote_get( $url ) {
		return $this->http_request( 'GET', $url );
	}

	private function http_request( $method, $url, array $payload = array() ) {
		$method = strtoupper( $method );

		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );

			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 20,
					CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
				)
			);

			if ( 'POST' === $method ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $payload ) );
				curl_setopt(
					$ch,
					CURLOPT_HTTPHEADER,
					array(
						'Accept: application/json',
						'Content-Type: application/x-www-form-urlencoded',
					)
				);
			}

			$body  = curl_exec( $ch );
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );

			if ( false === $body || $errno ) {
				return array(
					'error' => 'Instagram request failed: ' . ( $error ?: 'network error' ),
					'code'  => 502,
				);
			}
		} else {
			$options = array(
				'http' => array(
					'timeout' => 20,
					'header'  => "Accept: application/json\r\n",
				),
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			);

			if ( 'POST' === $method ) {
				$options['http']['method']  = 'POST';
				$options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$options['http']['content'] = http_build_query( $payload );
			}

			$body = @file_get_contents( $url, false, stream_context_create( $options ) );

			if ( false === $body ) {
				return array(
					'error' => 'Instagram request failed. Install php-curl on the server for reliable outbound HTTPS.',
					'code'  => 502,
				);
			}
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array( 'error' => 'Invalid Instagram API response.', 'code' => 502 );
		}

		if ( ! empty( $data['error_message'] ) ) {
			return array( 'error' => (string) $data['error_message'], 'code' => 400 );
		}

		if ( ! empty( $data['error']['message'] ) ) {
			return array( 'error' => (string) $data['error']['message'], 'code' => 400 );
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
			$login_vars = array();

			if ( ! empty( $_GET['oauth_done'] ) ) {
				$login_vars['oauth_done']    = preg_replace( '/[^a-f0-9]/', '', (string) $_GET['oauth_done'] );
				$login_vars['oauth_pending'] = true;
			}

			$this->render_login_form( $login_vars );
			return;
		}

		$oauth_result = null;
		$oauth_error  = '';

		if ( ! empty( $_GET['oauth_done'] ) ) {
			$oauth_result = $this->consume_oauth_result( (string) $_GET['oauth_done'] );

			if ( null === $oauth_result ) {
				$oauth_error = 'OAuth result expired or was already viewed. Run Instagram connect again.';
			}
		}

		$token_account = null;
		if ( empty( $this->config['demo_mode'] ) && ! empty( $this->config['instagram_access_token'] ) ) {
			$token_account = $this->resolve_account_from_token( (string) $this->config['instagram_access_token'] );
		}

		$this->render_template(
			'manage.php',
			array(
				'page_title'    => 'Manage Licenses',
				'page_heading'  => 'License control center',
				'page_intro'    => 'Manage client site licenses, assign Instagram User IDs, and revoke feeds remotely.',
				'licenses'      => $this->licenses,
				'demo_mode'     => ! empty( $this->config['demo_mode'] ),
				'oauth_ready'   => $this->is_oauth_configured(),
				'oauth_result'  => $oauth_result,
				'oauth_error'   => $oauth_error,
				'oauth_redirect_uri' => $this->get_oauth_redirect_uri(),
				'token_account' => $token_account,
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

	private function render_login_form( array $extra = array() ) {
		$this->render_template(
			'login.php',
			array_merge(
				array(
					'page_title'   => 'Sign In',
					'page_heading' => 'Welcome back',
					'page_intro'   => 'Sign in to manage Lumina client licenses and Instagram feed access.',
					'narrow'       => true,
					'error'        => ! empty( $_GET['login_error'] ),
					'oauth_done'   => '',
					'oauth_pending'=> false,
				),
				$extra
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

	private function auth_cookie_options() {
		return array(
			'expires'  => time() + 86400,
			'path'     => '/',
			'secure'   => $this->is_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	private function is_admin_authenticated() {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && isset( $_POST['hub_password'] ) ) {
			if ( hash_equals( (string) $this->config['admin_password'], (string) $_POST['hub_password'] ) ) {
				setcookie(
					'lumina_hub_auth',
					hash( 'sha256', $this->config['admin_password'] ),
					$this->auth_cookie_options()
				);

				$query = '';
				if ( ! empty( $_POST['oauth_done'] ) ) {
					$state = preg_replace( '/[^a-f0-9]/', '', (string) $_POST['oauth_done'] );
					if ( '' !== $state ) {
						$query = 'oauth_done=' . rawurlencode( $state );
					}
				}

				$this->redirect_manage( $query );
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

	private function is_oauth_configured() {
		return ! empty( $this->config['instagram_app_id'] )
			&& ! empty( $this->config['instagram_app_secret'] )
			&& ! empty( $this->get_hub_public_url() );
	}

	private function get_hub_public_url() {
		if ( ! empty( $this->config['hub_public_url'] ) ) {
			return rtrim( (string) $this->config['hub_public_url'], '/' );
		}

		$scheme = $this->is_https() ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

		return $scheme . '://' . $host;
	}

	private function get_oauth_redirect_uri() {
		return $this->get_hub_public_url() . '/oauth/callback';
	}

	private function handle_oauth_start() {
		if ( ! $this->is_admin_authenticated() ) {
			$this->redirect_manage( 'login_error=1' );
		}

		if ( ! $this->is_oauth_configured() ) {
			$this->redirect_manage( 'oauth_config_error=1' );
		}

		$state = bin2hex( random_bytes( 16 ) );
		$this->store_oauth_state( $state );

		$params = array(
			'client_id'     => (string) $this->config['instagram_app_id'],
			'redirect_uri'  => $this->get_oauth_redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'instagram_business_basic',
			'state'         => $state,
		);

		$url = 'https://www.instagram.com/oauth/authorize?' . http_build_query( $params );
		$this->redirect( $url );
	}

	private function handle_oauth_callback() {
		if ( ! $this->is_oauth_configured() ) {
			$this->redirect_manage( 'oauth_config_error=1' );
		}

		if ( ! empty( $_GET['error'] ) ) {
			$message = trim( (string) ( $_GET['error_description'] ?? $_GET['error'] ) );
			$this->redirect_manage( 'oauth_error=' . rawurlencode( $message ) );
		}

		$code  = trim( (string) ( $_GET['code'] ?? '' ) );
		$state = trim( (string) ( $_GET['state'] ?? '' ) );

		if ( '' === $code || '' === $state ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Missing authorization code or state.' ) );
		}

		if ( ! $this->validate_oauth_state( $state ) ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Invalid or expired OAuth state. Start again from /manage.' ) );
		}

		$code = preg_replace( '/#_.*$/', '', $code );

		$short = $this->remote_post(
			'https://api.instagram.com/oauth/access_token',
			array(
				'client_id'     => (string) $this->config['instagram_app_id'],
				'client_secret' => (string) $this->config['instagram_app_secret'],
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->get_oauth_redirect_uri(),
				'code'          => $code,
			)
		);

		if ( isset( $short['error'] ) ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Token exchange: ' . (string) $short['error'] ) );
		}

		$short = $this->normalize_oauth_token_payload( $short );
		$short_token = (string) ( $short['access_token'] ?? '' );

		if ( '' === $short_token ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Instagram did not return an access token.' ) );
		}

		$long = $this->remote_get(
			'https://graph.instagram.com/access_token?' . http_build_query(
				array(
					'grant_type'    => 'ig_exchange_token',
					'client_secret' => (string) $this->config['instagram_app_secret'],
					'access_token'  => $short_token,
				)
			)
		);

		$token_note = '';
		if ( isset( $long['error'] ) ) {
			$access_token = $short_token;
			$expires_in   = 3600;
			$token_note   = 'Long-lived exchange failed (' . $long['error'] . '). Showing the short-lived token instead (~1 hour).';
		} else {
			$access_token = (string) ( $long['access_token'] ?? $short_token );
			$expires_in   = (int) ( $long['expires_in'] ?? 0 );
		}

		$account = $this->resolve_account_from_token( $access_token );

		if ( isset( $account['error'] ) ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Account lookup: ' . (string) $account['error'] ) );
		}

		$user_id      = (string) $account['user_id'];
		$username     = (string) $account['username'];
		$account_type = (string) $account['account_type'];

		$this->delete_oauth_state( $state );
		$this->store_oauth_result(
			$state,
			array(
				'user_id'       => $user_id,
				'username'      => $username,
				'account_type'  => $account_type,
				'app_scoped_id' => (string) ( $account['app_scoped_id'] ?? '' ),
				'access_token'  => $access_token,
				'expires_in'    => $expires_in,
				'token_note'    => $token_note,
				'connected_at'  => gmdate( 'c' ),
			)
		);

		$this->redirect_manage( 'oauth_done=' . rawurlencode( $state ) );
	}

	private function oauth_cache_dir() {
		$dir = __DIR__ . '/../cache/oauth';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		return $dir;
	}

	private function store_oauth_state( $state ) {
		$file = $this->oauth_cache_dir() . '/state_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';
		file_put_contents(
			$file,
			json_encode(
				array(
					'created_at' => time(),
					'expires_at' => time() + 600,
				)
			)
		);
	}

	private function validate_oauth_state( $state ) {
		$file = $this->oauth_cache_dir() . '/state_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';

		if ( ! file_exists( $file ) ) {
			return false;
		}

		$data = json_decode( (string) file_get_contents( $file ), true );

		if ( empty( $data['expires_at'] ) || time() > (int) $data['expires_at'] ) {
			@unlink( $file );
			return false;
		}

		return true;
	}

	private function delete_oauth_state( $state ) {
		$file = $this->oauth_cache_dir() . '/state_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';

		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	private function store_oauth_result( $state, array $result ) {
		$file = $this->oauth_cache_dir() . '/result_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';
		file_put_contents(
			$file,
			json_encode(
				array(
					'created_at' => time(),
					'expires_at' => time() + 900,
					'result'     => $result,
				)
			)
		);
	}

	private function consume_oauth_result( $state ) {
		$file = $this->oauth_cache_dir() . '/result_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';

		if ( ! file_exists( $file ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		@unlink( $file );

		if ( empty( $data['result'] ) || empty( $data['expires_at'] ) || time() > (int) $data['expires_at'] ) {
			return null;
		}

		return $data['result'];
	}

	private function normalize_oauth_token_payload( array $payload ) {
		if ( ! empty( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$first = $payload['data'][0] ?? array();
			if ( is_array( $first ) ) {
				return array_merge( $payload, $first );
			}
		}

		return $payload;
	}

	private function remote_post( $url, array $payload ) {
		return $this->http_request( 'POST', $url, $payload );
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
