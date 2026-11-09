<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin Etsy Open API v3 client built on the WordPress HTTP API.
 *
 * Auth: OAuth 2.0 (PKCE) with the user's own Etsy app. Every request sends
 * `x-api-key: keystring:shared_secret` (required by Etsy since Feb 2026).
 */
class Etsy_Api {

	const API_BASE    = 'https://api.etsy.com/v3/application';
	const TOKEN_URL   = 'https://api.etsy.com/v3/public/oauth/token';
	const CONNECT_URL = 'https://www.etsy.com/oauth/connect';
	const SCOPES      = array( 'listings_r', 'listings_w', 'listings_d', 'shops_r', 'shops_w', 'transactions_r', 'profile_r' );

	/** @var Etsy_Api|null */
	private static $instance = null;

	/** Count of requests made in this PHP request (used for gentle throttling). */
	private $request_count = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ---------------------------------------------------------------------
	 * Low level.
	 * ------------------------------------------------------------------ */

	private function api_key_header() {
		$auth = Settings::auth();
		return $auth['keystring'] . ':' . $auth['shared_secret'];
	}

	/**
	 * Get a valid access token, refreshing it if needed.
	 *
	 * @throws Api_Error When not connected or refresh fails.
	 */
	public function access_token( $force_refresh = false ) {
		$auth = Settings::auth();
		if ( empty( $auth['refresh_token'] ) ) {
			throw new Api_Error( __( 'Not connected to Etsy yet. Open Marketplaces → Etsy → Settings → Connection to connect your shop.', 'woo-etsy-sync' ), 401 );
		}
		if ( ! $force_refresh && ! empty( $auth['access_token'] ) && (int) $auth['expires_at'] > time() + 60 ) {
			return $auth['access_token'];
		}
		return $this->refresh_token();
	}

	/**
	 * Refresh the OAuth token (with a short lock so parallel jobs don't race).
	 */
	public function refresh_token() {
		$lock_key = 'wes_refresh_lock';
		if ( get_transient( $lock_key ) ) {
			// Another process is refreshing; wait briefly then reuse its result.
			for ( $i = 0; $i < 10; $i++ ) {
				usleep( 300000 );
				wp_cache_delete( Settings::AUTH_OPTION, 'options' );
				$auth = Settings::auth();
				if ( (int) $auth['expires_at'] > time() + 60 ) {
					return $auth['access_token'];
				}
			}
		}
		set_transient( $lock_key, 1, 30 );

		$auth     = Settings::auth();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'x-api-key' => $this->api_key_header() ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $auth['keystring'],
					'refresh_token' => $auth['refresh_token'],
				),
			)
		);
		delete_transient( $lock_key );

		$data = $this->decode_token_response( $response );
		if ( is_wp_error( $data ) ) {
			$code = (int) $data->get_error_data();
			if ( in_array( $code, array( 400, 401 ), true ) ) {
				Logger::error( __( 'Etsy rejected the saved login (it may have been revoked or expired). Please reconnect your shop.', 'woo-etsy-sync' ), 'auth' );
				Settings::update_auth( array( 'access_token' => '', 'expires_at' => 0 ) );
				update_option( 'wes_needs_reconnect', 1, false );
			}
			throw new Api_Error( $data->get_error_message(), $code ? $code : 401 );
		}
		delete_option( 'wes_needs_reconnect' );
		Settings::update_auth(
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $data['refresh_token'],
				'expires_at'    => time() + (int) $data['expires_in'],
			)
		);
		return $data['access_token'];
	}

	/**
	 * Exchange an authorization code for tokens.
	 */
	public function exchange_code( $code, $verifier, $redirect_uri ) {
		$auth     = Settings::auth();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'x-api-key' => $this->api_key_header() ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $auth['keystring'],
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
					'code_verifier' => $verifier,
				),
			)
		);
		$data = $this->decode_token_response( $response );
		if ( is_wp_error( $data ) ) {
			throw new Api_Error( $data->get_error_message(), (int) $data->get_error_data() );
		}
		return $data;
	}

	private function decode_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'http', $response->get_error_message(), 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) ? ( $body['error_description'] ?? ( $body['error'] ?? '' ) ) : '';
			return new \WP_Error( 'token', sprintf( __( 'Etsy login failed (HTTP %1$d) %2$s', 'woo-etsy-sync' ), $code, $msg ), $code );
		}
		return $body;
	}

	/**
	 * Perform an API request.
	 *
	 * @param string $method  GET|POST|PUT|PATCH|DELETE.
	 * @param string $path    Path relative to /v3/application, e.g. /shops/123/listings.
	 * @param array  $args    query, form, json, multipart (raw body + boundary), auth (bool, default true).
	 * @return array Decoded JSON (empty array for 204).
	 * @throws Api_Error On failure.
	 */
	public function request( $method, $path, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'query'     => array(),
				'form'      => null,
				'json'      => null,
				'multipart' => null,
				'auth'      => true,
				'retry'     => 2,
			)
		);

		$url = self::API_BASE . $path;
		if ( ! empty( $args['query'] ) ) {
			$query = array();
			foreach ( $args['query'] as $k => $v ) {
				$query[ $k ] = is_array( $v ) ? implode( ',', $v ) : ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v );
			}
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$headers = array(
			'x-api-key' => $this->api_key_header(),
			'Accept'    => 'application/json',
		);
		if ( $args['auth'] ) {
			$headers['Authorization'] = 'Bearer ' . $this->access_token();
		}

		$body = null;
		if ( null !== $args['json'] ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $args['json'] );
		} elseif ( null !== $args['multipart'] ) {
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $args['multipart']['boundary'];
			$body                    = $args['multipart']['body'];
		} elseif ( null !== $args['form'] ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = self::build_form( $args['form'] );
		}

		// Etsy allows ~10 requests/second; be polite on long batch runs.
		$this->request_count++;
		if ( $this->request_count > 1 ) {
			usleep( 120000 );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => null !== $args['multipart'] ? 120 : 45,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $args['retry'] > 0 ) {
				sleep( 2 );
				$args['retry']--;
				return $this->request( $method, $path, $args );
			}
			throw new Api_Error( __( 'Could not reach Etsy: ', 'woo-etsy-sync' ) . $response->get_error_message(), 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		// Expired token: refresh once and retry.
		if ( 401 === $code && $args['auth'] && $args['retry'] > 0 ) {
			$this->access_token( true );
			$args['retry'] = 0;
			return $this->request( $method, $path, $args );
		}

		// Rate limited or temporary server problem: back off and retry.
		if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $args['retry'] > 0 ) {
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			sleep( max( 1, min( 10, $wait ? $wait : 3 ) ) );
			$args['retry']--;
			return $this->request( $method, $path, $args );
		}

		$message = '';
		if ( is_array( $data ) ) {
			$message = $data['error'] ?? ( $data['error_description'] ?? ( $data[0]['message'] ?? '' ) );
		}
		if ( '' === $message ) {
			$message = wp_strip_all_tags( substr( (string) $raw, 0, 300 ) );
		}
		throw new Api_Error( sprintf( __( 'Etsy said: %1$s (HTTP %2$d)', 'woo-etsy-sync' ), $message, $code ), $code );
	}

	/**
	 * Encode form data the way Etsy expects. Array values (tags, materials,
	 * image_ids, styles) are sent as comma-separated lists, as in Etsy's docs.
	 */
	public static function build_form( array $data ) {
		$parts = array();
		foreach ( $data as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$parts[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * Build a multipart body.
	 *
	 * @param array $fields Plain fields.
	 * @param array $files  name => array( path, filename, mime ).
	 */
	public static function build_multipart( array $fields, array $files ) {
		$boundary = 'wes' . wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}
		foreach ( $files as $name => $file ) {
			$contents = file_get_contents( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$body    .= '--' . $boundary . $eol;
			$body    .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . basename( $file['filename'] ) . '"' . $eol;
			$body    .= 'Content-Type: ' . $file['mime'] . $eol . $eol;
			$body    .= $contents . $eol;
		}
		$body .= '--' . $boundary . '--' . $eol;
		return array(
			'boundary' => $boundary,
			'body'     => $body,
		);
	}

	/* ---------------------------------------------------------------------
	 * Endpoints used by the plugin.
	 * ------------------------------------------------------------------ */

	public function ping() {
		return $this->request( 'GET', '/openapi-ping', array( 'auth' => false ) );
	}

	public function get_me() {
		return $this->request( 'GET', '/users/me' );
	}

	public function get_shop( $shop_id ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id );
	}

	public function get_shipping_profiles( $shop_id ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/shipping-profiles' );
	}

	public function get_readiness_states( $shop_id ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/readiness-state-definitions', array( 'query' => array( 'limit' => 100 ) ) );
	}

	public function create_readiness_state( $shop_id, array $data ) {
		return $this->request( 'POST', '/shops/' . (int) $shop_id . '/readiness-state-definitions', array( 'form' => $data ) );
	}

	public function get_return_policies( $shop_id ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/policies/return' );
	}

	public function get_sections( $shop_id ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/sections' );
	}

	public function get_seller_taxonomy() {
		return $this->request( 'GET', '/seller-taxonomy/nodes' );
	}

	public function create_draft_listing( $shop_id, array $data ) {
		return $this->request( 'POST', '/shops/' . (int) $shop_id . '/listings', array( 'form' => $data ) );
	}

	public function update_listing( $shop_id, $listing_id, array $data ) {
		return $this->request( 'PATCH', '/shops/' . (int) $shop_id . '/listings/' . (int) $listing_id, array( 'form' => $data ) );
	}

	public function get_listing( $listing_id, array $includes = array() ) {
		$query = $includes ? array( 'includes' => $includes ) : array();
		return $this->request( 'GET', '/listings/' . (int) $listing_id, array( 'query' => $query ) );
	}

	public function delete_listing( $listing_id ) {
		return $this->request( 'DELETE', '/listings/' . (int) $listing_id );
	}

	public function get_shop_listings( $shop_id, $state, $limit = 100, $offset = 0, array $includes = array() ) {
		$query = array(
			'state'  => $state,
			'limit'  => $limit,
			'offset' => $offset,
		);
		if ( $includes ) {
			$query['includes'] = $includes;
		}
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/listings', array( 'query' => $query ) );
	}

	public function get_inventory( $listing_id ) {
		return $this->request( 'GET', '/listings/' . (int) $listing_id . '/inventory' );
	}

	public function update_inventory( $listing_id, array $inventory ) {
		return $this->request( 'PUT', '/listings/' . (int) $listing_id . '/inventory', array( 'json' => $inventory ) );
	}

	public function get_listing_images( $listing_id ) {
		return $this->request( 'GET', '/listings/' . (int) $listing_id . '/images' );
	}

	public function upload_listing_image( $shop_id, $listing_id, $file_path, $rank, $alt = '' ) {
		$mime   = wp_check_filetype( $file_path );
		$fields = array(
			'rank'      => (string) (int) $rank,
			'overwrite' => 'true',
		);
		if ( $alt ) {
			$fields['alt_text'] = mb_substr( $alt, 0, 250 );
		}
		$multipart = self::build_multipart(
			$fields,
			array(
				'image' => array(
					'path'     => $file_path,
					'filename' => basename( $file_path ),
					'mime'     => $mime['type'] ? $mime['type'] : 'image/jpeg',
				),
			)
		);
		return $this->request( 'POST', '/shops/' . (int) $shop_id . '/listings/' . (int) $listing_id . '/images', array( 'multipart' => $multipart ) );
	}

	public function delete_listing_image( $shop_id, $listing_id, $image_id ) {
		return $this->request( 'DELETE', '/shops/' . (int) $shop_id . '/listings/' . (int) $listing_id . '/images/' . (int) $image_id );
	}

	public function update_variation_images( $shop_id, $listing_id, array $variation_images ) {
		return $this->request(
			'POST',
			'/shops/' . (int) $shop_id . '/listings/' . (int) $listing_id . '/variation-images',
			array( 'json' => array( 'variation_images' => $variation_images ) )
		);
	}

	public function get_receipts( $shop_id, array $query ) {
		return $this->request( 'GET', '/shops/' . (int) $shop_id . '/receipts', array( 'query' => $query ) );
	}
}
