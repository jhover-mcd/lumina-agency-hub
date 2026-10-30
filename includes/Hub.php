<?php
/**
 * Lumina Agency Hub — license and feed management.
 */

class Lumina_Agency_Hub {
	const TOKEN_MIN_AGE       = 86400;
	const TOKEN_TTL           = 5184000;
	const TOKEN_EXPIRING_SOON = 1209600;

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
					'hub_build' => '2026-10-30-token-refresh',
					'demo'      => ! empty( $this->config['demo_mode'] ),
				)
			);
		}

		if ( '/cron/refresh-tokens' === $path ) {
			$this->handle_cron_refresh_tokens();
		}

		if ( '/v1/feed' === $path ) {
			$this->json( $this->get_feed() );
		}

		if ( '/v1/status' === $path ) {
			$this->json( $this->get_status() );
		}

		if ( '/v1/refresh' === $path ) {
			$this->json( $this->refresh_feed() );
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
		$prepared = $this->prepare_license_request();
		$license_key = $prepared['key'];
		$license     = $prepared['license'];

		if ( isset( $license['error'] ) ) {
			return $this->error_payload( $license['error'], $license['code'] );
		}

		$limit = min( 50, max( 1, (int) ( $_GET['limit'] ?? 12 ) ) );
		$force_refresh = ! empty( $_GET['refresh'] ) && '1' === $_GET['refresh'];

		if ( ! $force_refresh ) {
			$cache = $this->read_cache( $license_key, $limit );

			if ( null !== $cache ) {
				return $this->with_token_fields( $cache, $license_key );
			}
		}

		if ( ! empty( $this->config['demo_mode'] ) ) {
			$items   = $this->demo_media( $license, $limit );
			$profile = $this->demo_profile( $license );
		} else {
			$items = $this->fetch_instagram_media_for_license( $license, $limit );

			if ( isset( $items['error'] ) ) {
				return $this->error_payload( $items['error'], $items['code'] ?? 500 );
			}

			$profile = $this->fetch_instagram_profile_for_license( $license );

			if ( isset( $profile['error'] ) ) {
				return $this->error_payload( $profile['error'], $profile['code'] ?? 500 );
			}
		}
		$payload = $this->with_token_fields(
			array(
				'active'         => true,
				'user_id'        => $license['user_id'],
				'label'          => $license['label'] ?? '',
				'username'       => $profile['username'] ?? '',
				'items'          => $items,
				'fetched_at'     => time(),
				'fetched_at_iso' => gmdate( 'c' ),
			),
			$license_key
		);

		$this->write_cache( $license_key, $limit, $payload );

		return $payload;
	}

	private function refresh_feed() {
		$prepared = $this->prepare_license_request();
		$license_key = $prepared['key'];
		$license     = $prepared['license'];

		if ( isset( $license['error'] ) ) {
			return $this->error_payload( $license['error'], $license['code'] );
		}

		$limit = min( 50, max( 1, (int) ( $_GET['limit'] ?? 12 ) ) );

		$this->clear_cache( $license_key, $limit );

		if ( ! empty( $this->config['demo_mode'] ) ) {
			$items   = $this->demo_media( $license, $limit );
			$profile = $this->demo_profile( $license );
		} else {
			$items = $this->fetch_instagram_media_for_license( $license, $limit );

			if ( isset( $items['error'] ) ) {
				return $this->error_payload( $items['error'], $items['code'] ?? 500 );
			}

			$profile = $this->fetch_instagram_profile_for_license( $license );

			if ( isset( $profile['error'] ) ) {
				return $this->error_payload( $profile['error'], $profile['code'] ?? 500 );
			}
		}

		$payload = $this->with_token_fields(
			array(
				'active'         => true,
				'user_id'        => $license['user_id'],
				'label'          => $license['label'] ?? '',
				'username'       => $profile['username'] ?? '',
				'items'          => $items,
				'fetched_at'     => time(),
				'fetched_at_iso' => gmdate( 'c' ),
				'refreshed'      => true,
			),
			$license_key
		);

		$this->write_cache( $license_key, $limit, $payload );

		return $payload;
	}

	private function get_status() {
		$prepared = $this->prepare_license_request();
		$license_key = $prepared['key'];
		$license     = $prepared['license'];

		if ( isset( $license['error'] ) ) {
			return $this->error_payload( $license['error'], $license['code'] );
		}

		if ( ! empty( $this->config['demo_mode'] ) ) {
			$profile = $this->demo_profile( $license );
		} else {
			$profile = $this->fetch_instagram_profile_for_license( $license );

			if ( isset( $profile['error'] ) ) {
				return $this->error_payload( $profile['error'], $profile['code'] ?? 500 );
			}
		}

		return $this->with_token_fields(
			array(
				'active'       => true,
				'user_id'      => $license['user_id'],
				'label'        => $license['label'] ?? '',
				'username'     => $profile['username'] ?? '',
				'account_type' => $profile['account_type'] ?? '',
				'media_count'  => $profile['media_count'] ?? 0,
			),
			$license_key
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
			$seed    = md5( ( $license['user_id'] ?? 'demo' ) . $i );
			$is_reel = 2 === $i % 5;
			$items[] = array(
				'id'                 => 'demo-' . $i,
				'caption'            => ( $labels[ $i % count( $labels ) ] ?? 'Demo post' ) . ' #' . ( $i + 1 ),
				'media_type'         => $is_reel ? 'VIDEO' : 'IMAGE',
				'media_product_type' => $is_reel ? 'REELS' : 'FEED',
				'image_url'          => 'https://picsum.photos/seed/' . $seed . '/800/800',
				'video_url'          => $is_reel ? 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4' : '',
				'permalink'          => $is_reel ? 'https://instagram.com/reel/demo' . $i : 'https://instagram.com/p/demo' . $i,
				'timestamp'          => gmdate( 'c', time() - ( $i * 86400 ) ),
				'username'           => $slug . '_studio',
				'date'               => gmdate( 'M j, Y', time() - ( $i * 86400 ) ),
			);
		}

		return $items;
	}

	private function instagram_graph_url( $path, array $query = array() ) {
		$url = 'https://graph.instagram.com/' . ltrim( $path, '/' );

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

	private function get_license_access_token( array $license ) {
		if ( ! empty( $license['access_token'] ) ) {
			return (string) $license['access_token'];
		}

		if ( ! empty( $this->config['instagram_access_token'] ) ) {
			return (string) $this->config['instagram_access_token'];
		}

		return '';
	}

	private function fetch_instagram_media_for_license( array $license, $limit ) {
		$token = $this->get_license_access_token( $license );

		if ( '' === $token ) {
			return array(
				'error' => 'No Instagram token for this license. In /manage, click Connect Instagram on this client row.',
				'code'  => 422,
			);
		}

		return $this->fetch_instagram_media( $token, $limit );
	}

	private function fetch_instagram_profile_for_license( array $license ) {
		$token = $this->get_license_access_token( $license );

		if ( '' === $token ) {
			return array(
				'error' => 'No Instagram token for this license. In /manage, click Connect Instagram on this client row.',
				'code'  => 422,
			);
		}

		return $this->fetch_instagram_profile_for_token( $token );
	}

	private function fetch_instagram_media( $access_token, $limit ) {
		$url = $this->instagram_graph_url(
			'me/media',
			array(
				'fields'       => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,username',
				'limit'        => $limit,
				'access_token' => $access_token,
			)
		);

		$response = $this->remote_get( $url );

		if ( isset( $response['error'] ) ) {
			return $response;
		}

		$items = array();

		foreach ( $response['data'] ?? array() as $item ) {
			$media_type = $item['media_type'] ?? 'IMAGE';
			$permalink  = (string) ( $item['permalink'] ?? '' );
			$product    = (string) ( $item['media_product_type'] ?? '' );
			$image_url  = $item['media_url'] ?? '';
			$video_url  = '';

			if ( 'VIDEO' === $media_type ) {
				$video_url = (string) ( $item['media_url'] ?? '' );

				if ( ! empty( $item['thumbnail_url'] ) ) {
					$image_url = $item['thumbnail_url'];
				}
			}

			if ( '' === $product && false !== strpos( $permalink, '/reel/' ) ) {
				$product = 'REELS';
			}

			$items[] = array(
				'id'                 => (string) ( $item['id'] ?? '' ),
				'caption'            => (string) ( $item['caption'] ?? '' ),
				'media_type'         => (string) $media_type,
				'media_product_type' => $product,
				'image_url'          => (string) $image_url,
				'video_url'          => $video_url,
				'permalink'          => $permalink,
				'timestamp'          => (string) ( $item['timestamp'] ?? '' ),
				'username'           => (string) ( $item['username'] ?? '' ),
				'date'               => $this->format_date( $item['timestamp'] ?? '' ),
			);
		}

		return $items;
	}

	private function fetch_instagram_profile_for_token( $access_token ) {
		$profile = $this->remote_get(
			$this->instagram_graph_url(
				'me',
				array(
					'fields'       => 'user_id,username,account_type,media_count,id',
					'access_token' => $access_token,
				)
			)
		);

		$profile = $this->normalize_instagram_profile( $profile );

		if ( isset( $profile['error'] ) ) {
			return $profile;
		}

		return array(
			'id'           => $profile['user_id'] ?? $profile['id'] ?? '',
			'user_id'      => $profile['user_id'] ?? $profile['id'] ?? '',
			'username'     => $profile['username'] ?? '',
			'account_type' => $profile['account_type'] ?? '',
			'media_count'  => $profile['media_count'] ?? 0,
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
		$token = $this->get_license_access_token( $license );

		if ( '' === $token ) {
			return array(
				'error' => 'No Instagram token for this license. In /manage, click Connect Instagram on this client row.',
				'code'  => 422,
			);
		}

		$account = $this->resolve_account_from_token( $token );

		if ( isset( $account['error'] ) ) {
			return array(
				'error' => 'Could not verify this license token: ' . (string) $account['error'],
				'code'  => 422,
			);
		}

		$token_user_id   = (string) ( $account['user_id'] ?? '' );
		$license_user_id = (string) ( $license['user_id'] ?? '' );

		if ( '' === $token_user_id || '' === $license_user_id ) {
			return null;
		}

		if ( $token_user_id !== $license_user_id ) {
			return array(
				'error' => 'License User ID does not match this client\'s Instagram token. Click Connect Instagram on this client row, or update the license User ID to ' . $token_user_id . '.',
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

	private function clear_cache( $license_key, $limit ) {
		$file = $this->cache_file( $license_key, $limit );

		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
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

		if ( 'refresh_token' === $action && isset( $this->licenses[ $license_key ] ) ) {
			$updated = $this->parse_time( $this->licenses[ $license_key ]['token_updated_at'] ?? '' );

			if ( $updated && ( time() - $updated ) < self::TOKEN_MIN_AGE ) {
				$this->redirect_manage( 'token_refresh_error=' . rawurlencode( 'Instagram will not renew a token until it is at least 24 hours old. It will renew automatically after that.' ) );
			}

			$ok = $this->refresh_license_token( $license_key, true );

			if ( $ok ) {
				$this->redirect_manage( 'token_refreshed=1' );
			}

			$error = (string) ( $this->licenses[ $license_key ]['token_refresh_error'] ?? 'Could not refresh this Instagram token.' );
			$this->redirect_manage( 'token_refresh_error=' . rawurlencode( $error ) );
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

		$license_accounts = array();
		$token_health     = array();
		foreach ( $this->licenses as $key => $license ) {
			$token_health[ $key ] = $this->get_token_health( $license );

			if ( empty( $license['access_token'] ) ) {
				continue;
			}

			$account = $this->resolve_account_from_token( (string) $license['access_token'] );

			if ( ! isset( $account['error'] ) ) {
				$license_accounts[ $key ] = $account;
			}
		}

		$this->render_template(
			'manage.php',
			array(
				'page_title'         => 'Manage Licenses',
				'page_heading'       => 'License control center',
				'page_intro'         => 'Manage client site licenses, connect each client Instagram account, and revoke feeds remotely.',
				'licenses'           => $this->licenses,
				'demo_mode'          => ! empty( $this->config['demo_mode'] ),
				'oauth_ready'        => $this->is_oauth_configured(),
				'oauth_result'       => $oauth_result,
				'oauth_error'        => $oauth_error,
				'oauth_redirect_uri' => $this->get_oauth_redirect_uri(),
				'token_account'      => $token_account,
				'license_accounts'   => $license_accounts,
				'token_health'       => $token_health,
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
		$license_key = trim( (string) ( $_GET['license_key'] ?? '' ) );

		if ( '' !== $license_key && ! isset( $this->licenses[ $license_key ] ) ) {
			$this->redirect_manage( 'oauth_error=' . rawurlencode( 'Unknown license key.' ) );
		}

		$this->store_oauth_state( $state, $license_key );

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

		$oauth_state = $this->read_oauth_state( $state );
		$license_key   = trim( (string) ( $oauth_state['license_key'] ?? '' ) );

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

		$saved_to_license = false;
		$license_label    = '';

		if ( '' !== $license_key && isset( $this->licenses[ $license_key ] ) ) {
			$this->licenses[ $license_key ]['user_id']  = $user_id;
			$this->licenses[ $license_key ]['username'] = $username;
			$this->save_licenses();
			$this->store_license_token( $license_key, $access_token, $expires_in );
			$saved_to_license = true;
			$license_label    = (string) ( $this->licenses[ $license_key ]['label'] ?? $license_key );
		}

		$this->delete_oauth_state( $state );
		$this->store_oauth_result(
			$state,
			array(
				'user_id'            => $user_id,
				'username'           => $username,
				'account_type'       => $account_type,
				'app_scoped_id'      => (string) ( $account['app_scoped_id'] ?? '' ),
				'access_token'       => $access_token,
				'expires_in'         => $expires_in,
				'token_note'         => $token_note,
				'connected_at'       => gmdate( 'c' ),
				'license_key'        => $license_key,
				'license_label'      => $license_label,
				'saved_to_license'   => $saved_to_license,
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

	private function store_oauth_state( $state, $license_key = '' ) {
		$file = $this->oauth_cache_dir() . '/state_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';
		file_put_contents(
			$file,
			json_encode(
				array(
					'created_at'  => time(),
					'expires_at'  => time() + 600,
					'license_key' => (string) $license_key,
				)
			)
		);
	}

	private function read_oauth_state( $state ) {
		$file = $this->oauth_cache_dir() . '/state_' . preg_replace( '/[^a-f0-9]/', '', (string) $state ) . '.json';

		if ( ! file_exists( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $data ) ? $data : array();
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

	private function prepare_license_request() {
		$license_key = $this->get_license_key();
		$license     = $this->resolve_license( $license_key );

		if ( isset( $license['error'] ) ) {
			return array(
				'key'     => $license_key,
				'license' => $license,
			);
		}

		$this->maybe_refresh_license_token( $license_key );

		$license = $this->licenses[ $license_key ] ?? $license;
		$health  = $this->get_token_health( $license );

		if ( 'expired' === $health['status'] ) {
			return array(
				'key'     => $license_key,
				'license' => array(
					'error' => 'Instagram access expired. Reconnect Instagram for this client in the agency hub.',
					'code'  => 403,
				),
			);
		}

		return array(
			'key'     => $license_key,
			'license' => $license,
		);
	}

	private function maybe_refresh_license_token( $license_key ) {
		if ( ! empty( $this->config['demo_mode'] ) || '' === $license_key ) {
			return;
		}

		static $attempted = array();

		if ( isset( $attempted[ $license_key ] ) ) {
			return;
		}

		$attempted[ $license_key ] = true;

		$this->with_token_lock(
			$license_key,
			function () use ( $license_key ) {
				$this->licenses = $this->load_licenses();

				if ( empty( $this->licenses[ $license_key ] ) ) {
					return;
				}

				if ( ! $this->token_is_due_for_refresh( $this->licenses[ $license_key ] ) ) {
					return;
				}

				$this->refresh_license_token( $license_key );
			}
		);
	}

	private function token_is_due_for_refresh( array $license ) {
		if ( empty( $license['access_token'] ) ) {
			return false;
		}

		$now     = time();
		$updated = $this->parse_time( $license['token_updated_at'] ?? '' );
		$expires = $this->get_token_expiry_ts( $license );

		if ( $expires && $now >= $expires ) {
			return false;
		}

		if ( $updated && ( $now - $updated ) < self::TOKEN_MIN_AGE ) {
			return false;
		}

		return true;
	}

	private function refresh_license_token( $license_key, $force = false ) {
		$license = $this->licenses[ $license_key ] ?? array();
		$token   = (string) ( $license['access_token'] ?? '' );

		if ( '' === $token ) {
			$this->mark_token_refresh_error( $license_key, 'No Instagram token saved on this license.' );
			return false;
		}

		$expires = $this->get_token_expiry_ts( $license );

		if ( $expires && time() >= $expires ) {
			$this->mark_token_refresh_error( $license_key, 'Token expired. Reconnect Instagram on this client row.' );
			return false;
		}

		if ( ! $force && ! $this->token_is_due_for_refresh( $license ) ) {
			return false;
		}

		$response = $this->remote_get(
			$this->instagram_graph_url(
				'refresh_access_token',
				array(
					'grant_type'   => 'ig_refresh_token',
					'access_token' => $token,
				)
			)
		);

		if ( isset( $response['error'] ) ) {
			$error = (string) $response['error'];

			if ( $this->is_nonfatal_refresh_error( $error ) ) {
				return false;
			}

			$this->mark_token_refresh_error( $license_key, $error );
			return false;
		}

		$new_token  = (string) ( $response['access_token'] ?? '' );
		$expires_in = (int) ( $response['expires_in'] ?? 0 );

		if ( '' === $new_token ) {
			$this->mark_token_refresh_error( $license_key, 'Instagram did not return a refreshed token.' );
			return false;
		}

		$this->store_license_token( $license_key, $new_token, $expires_in );

		return true;
	}

	private function store_license_token( $license_key, $access_token, $expires_in = 0 ) {
		$this->licenses = $this->load_licenses();

		if ( ! isset( $this->licenses[ $license_key ] ) ) {
			return;
		}

		$now = time();
		$this->licenses[ $license_key ]['access_token']     = (string) $access_token;
		$this->licenses[ $license_key ]['token_updated_at'] = gmdate( 'c', $now );

		if ( (int) $expires_in > 0 ) {
			$this->licenses[ $license_key ]['token_expires_at'] = gmdate( 'c', $now + (int) $expires_in );
			$this->licenses[ $license_key ]['token_expires_in'] = (int) $expires_in;
		} else {
			$this->licenses[ $license_key ]['token_expires_at'] = gmdate( 'c', $now + self::TOKEN_TTL );
			$this->licenses[ $license_key ]['token_expires_in'] = self::TOKEN_TTL;
		}

		unset( $this->licenses[ $license_key ]['token_refresh_error'], $this->licenses[ $license_key ]['token_refresh_failed_at'] );
		$this->save_licenses();
	}

	private function mark_token_refresh_error( $license_key, $message ) {
		$this->licenses = $this->load_licenses();

		if ( ! isset( $this->licenses[ $license_key ] ) ) {
			return;
		}

		$this->licenses[ $license_key ]['token_refresh_error']     = (string) $message;
		$this->licenses[ $license_key ]['token_refresh_failed_at'] = gmdate( 'c' );
		$this->save_licenses();
	}

	private function is_nonfatal_refresh_error( $message ) {
		$haystack = strtolower( (string) $message );

		return false !== strpos( $haystack, '24 hour' )
			|| false !== strpos( $haystack, 'at least 24' )
			|| false !== strpos( $haystack, 'not yet' )
			|| false !== strpos( $haystack, 'too recent' )
			|| false !== strpos( $haystack, 'less than 24' );
	}

	private function parse_time( $value ) {
		if ( empty( $value ) ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$time = strtotime( (string) $value );

		return $time ? $time : 0;
	}

	private function get_token_expiry_ts( array $license ) {
		$expires = $this->parse_time( $license['token_expires_at'] ?? '' );

		if ( $expires ) {
			return $expires;
		}

		$updated = $this->parse_time( $license['token_updated_at'] ?? '' );

		if ( $updated ) {
			return $updated + self::TOKEN_TTL;
		}

		return 0;
	}

	private function get_token_health( array $license ) {
		if ( empty( $license['access_token'] ) ) {
			return array(
				'status'         => 'missing',
				'expires_at'     => '',
				'days_remaining' => null,
				'error'          => '',
			);
		}

		$now       = time();
		$expires   = $this->get_token_expiry_ts( $license );
		$remaining = $expires ? ( $expires - $now ) : null;
		$error     = (string) ( $license['token_refresh_error'] ?? '' );
		$status    = 'ok';

		if ( null !== $remaining && $remaining <= 0 ) {
			$status = 'expired';
		} elseif ( '' !== $error ) {
			$status = 'error';
		} elseif ( null !== $remaining && $remaining <= self::TOKEN_EXPIRING_SOON ) {
			$status = 'expiring';
		} elseif ( null === $remaining ) {
			$status = 'unknown';
		}

		return array(
			'status'         => $status,
			'expires_at'     => $expires ? gmdate( 'c', $expires ) : '',
			'days_remaining' => null === $remaining ? null : (int) floor( $remaining / 86400 ),
			'error'          => $error,
		);
	}

	private function with_token_fields( array $payload, $license_key ) {
		$health = $this->get_token_health( $this->licenses[ $license_key ] ?? array() );

		$payload['token_status']         = $health['status'];
		$payload['token_expires_at']     = $health['expires_at'];
		$payload['token_days_remaining'] = $health['days_remaining'];

		return $payload;
	}

	private function with_token_lock( $license_key, $callback ) {
		$dir = __DIR__ . '/../cache';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$file = $dir . '/token-lock-' . md5( (string) $license_key ) . '.lock';
		$fp   = fopen( $file, 'c' );

		if ( ! $fp || ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
			if ( $fp ) {
				fclose( $fp );
			}

			return false;
		}

		try {
			return $callback();
		} finally {
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}
	}

	private function handle_cron_refresh_tokens() {
		$secret = (string) ( $this->config['cron_secret'] ?? '' );

		if ( '' === $secret ) {
			$this->json( array( 'message' => 'Not found.' ), 404 );
		}

		$provided = '';

		if ( ! empty( $_SERVER['HTTP_X_LUMINA_CRON'] ) ) {
			$provided = (string) $_SERVER['HTTP_X_LUMINA_CRON'];
		} elseif ( ! empty( $_GET['key'] ) ) {
			$provided = (string) $_GET['key'];
		}

		if ( ! hash_equals( $secret, $provided ) ) {
			$this->json( array( 'error' => 'Unauthorized.' ), 403 );
		}

		$results = array();

		foreach ( array_keys( $this->licenses ) as $key ) {
			$this->maybe_refresh_license_token( $key );
			$health = $this->get_token_health( $this->licenses[ $key ] ?? array() );
			$results[ $key ] = array(
				'label'          => $this->licenses[ $key ]['label'] ?? '',
				'status'         => $health['status'],
				'days_remaining' => $health['days_remaining'],
			);
		}

		$this->json(
			array(
				'ok'       => true,
				'licenses' => $results,
			)
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
