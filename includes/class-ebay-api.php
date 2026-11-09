<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * eBay client: OAuth (user + application tokens), REST APIs (Account, Taxonomy, Fulfillment)
 * and the XML Trading API (listing create/revise/end, stock). Trading API calls are made with
 * the OAuth user token, so listings created by other tools (e.g. WP-Lister) can be managed too.
 */
class Ebay_Api {

	const COMPAT_LEVEL = '1451';
	const SCOPES       = array(
		'https://api.ebay.com/oauth/api_scope',
		'https://api.ebay.com/oauth/api_scope/sell.inventory',
		'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
		'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
	);

	/** @var Ebay_Api|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ---------------------------------------------------------------------
	 * Endpoints.
	 * ------------------------------------------------------------------ */

	private static function sandbox() {
		return ! empty( Ebay_Settings::auth()['sandbox'] );
	}
	public static function auth_url() {
		return self::sandbox() ? 'https://auth.sandbox.ebay.com/oauth2/authorize' : 'https://auth.ebay.com/oauth2/authorize';
	}
	public static function api_base() {
		return self::sandbox() ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
	}
	public static function token_url() {
		return self::api_base() . '/identity/v1/oauth2/token';
	}
	public static function trading_url() {
		return self::api_base() . '/ws/api.dll';
	}

	/* ---------------------------------------------------------------------
	 * OAuth.
	 * ------------------------------------------------------------------ */

	private function basic() {
		$a = Ebay_Settings::auth();
		return 'Basic ' . base64_encode( $a['client_id'] . ':' . $a['client_secret'] ); // phpcs:ignore
	}

	private function token_request( array $body ) {
		$res = wp_remote_post(
			self::token_url(),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $this->basic(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new Api_Error( __( 'Could not reach eBay: ', 'woo-etsy-sync' ) . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			$msg = is_array( $data ) ? ( $data['error_description'] ?? ( $data['error'] ?? '' ) ) : '';
			throw new Api_Error( sprintf( __( 'eBay login failed (HTTP %1$d) %2$s', 'woo-etsy-sync' ), $code, $msg ), $code );
		}
		return $data;
	}

	public function exchange_code( $code ) {
		$a = Ebay_Settings::auth();
		return $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $a['runame'],
			)
		);
	}

	/** Application token (client credentials) – used for the Taxonomy API. */
	public function app_token() {
		$a = Ebay_Settings::auth();
		if ( $a['app_token'] && (int) $a['app_expires_at'] > time() + 60 ) {
			return $a['app_token'];
		}
		$data = $this->token_request(
			array(
				'grant_type' => 'client_credentials',
				'scope'      => 'https://api.ebay.com/oauth/api_scope',
			)
		);
		Ebay_Settings::update_auth(
			array(
				'app_token'      => $data['access_token'],
				'app_expires_at' => time() + (int) $data['expires_in'],
			)
		);
		return $data['access_token'];
	}

	/** User access token, refreshed when needed. */
	public function user_token( $force = false ) {
		$a = Ebay_Settings::auth();
		if ( '' === $a['refresh_token'] ) {
			throw new Api_Error( __( 'Your eBay account is not connected yet. Open Marketplaces → eBay → Settings → Connection.', 'woo-etsy-sync' ), 401 );
		}
		if ( ! $force && $a['access_token'] && (int) $a['expires_at'] > time() + 60 ) {
			return $a['access_token'];
		}
		try {
			$data = $this->token_request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $a['refresh_token'],
					'scope'         => implode( ' ', self::SCOPES ),
				)
			);
		} catch ( Api_Error $e ) {
			if ( in_array( $e->status(), array( 400, 401 ), true ) ) {
				update_option( 'wes_ebay_needs_reconnect', 1, false );
				Logger::error( __( 'eBay rejected the saved login (it may have expired or been revoked). Please reconnect eBay.', 'woo-etsy-sync' ), 'ebay-auth' );
			}
			throw $e;
		}
		delete_option( 'wes_ebay_needs_reconnect' );
		Ebay_Settings::update_auth(
			array(
				'access_token' => $data['access_token'],
				'expires_at'   => time() + (int) $data['expires_in'],
			)
		);
		return $data['access_token'];
	}

	/* ---------------------------------------------------------------------
	 * REST.
	 * ------------------------------------------------------------------ */

	public function rest( $method, $path, array $query = array(), $body = null, $app = false, $retry = 1 ) {
		$url = self::api_base() . $path;
		if ( $query ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$headers = array(
			'Authorization'           => 'Bearer ' . ( $app ? $this->app_token() : $this->user_token() ),
			'Accept'                  => 'application/json',
			'Content-Type'            => 'application/json',
			'X-EBAY-C-MARKETPLACE-ID' => Ebay_Settings::get( 'marketplace', Ebay_Settings::default_marketplace() ),
		);
		$res = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 45,
				'headers' => $headers,
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new Api_Error( __( 'Could not reach eBay: ', 'woo-etsy-sync' ) . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}
		if ( 401 === $code && $retry > 0 && ! $app ) {
			$this->user_token( true );
			return $this->rest( $method, $path, $query, $body, $app, 0 );
		}
		if ( in_array( $code, array( 429, 500, 502, 503 ), true ) && $retry > 0 ) {
			sleep( 2 );
			return $this->rest( $method, $path, $query, $body, $app, 0 );
		}
		$msg = '';
		if ( ! empty( $data['errors'][0] ) ) {
			$msg = $data['errors'][0]['longMessage'] ?? ( $data['errors'][0]['message'] ?? '' );
		}
		throw new Api_Error( sprintf( __( 'eBay said: %1$s (HTTP %2$d)', 'woo-etsy-sync' ), $msg ? $msg : __( 'unknown error', 'woo-etsy-sync' ), $code ), $code );
	}

	/* ---------------------------------------------------------------------
	 * Trading API (XML).
	 * ------------------------------------------------------------------ */

	public static function xml( array $data, $indent = '' ) {
		$out = '';
		foreach ( $data as $tag => $value ) {
			if ( null === $value || ( is_array( $value ) && ! $value ) ) {
				continue;
			}
			if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
				foreach ( $value as $item ) {
					$out .= self::xml( array( $tag => $item ), $indent );
				}
				continue;
			}
			if ( is_array( $value ) ) {
				$out .= "<{$tag}>" . self::xml( $value ) . "</{$tag}>";
			} elseif ( is_bool( $value ) ) {
				$out .= "<{$tag}>" . ( $value ? 'true' : 'false' ) . "</{$tag}>";
			} elseif ( 'Description' === $tag ) {
				$out .= "<{$tag}><![CDATA[" . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . "]]></{$tag}>";
			} else {
				$out .= "<{$tag}>" . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</{$tag}>";
			}
		}
		return $out;
	}

	/**
	 * Call the Trading API.
	 *
	 * @return \SimpleXMLElement
	 * @throws Api_Error On failure (warnings are returned in ->warnings on the result).
	 */
	public function trading( $call, array $body, $retry = 1 ) {
		$site = Ebay_Settings::site();
		$a    = Ebay_Settings::auth();
		$xml  = '<?xml version="1.0" encoding="utf-8"?>' .
			"<{$call}Request xmlns=\"urn:ebay:apis:eBLBaseComponents\">" .
			'<ErrorLanguage>' . ( 0 === strpos( (string) $site[5], 'en_' ) ? $site[5] : 'en_US' ) . '</ErrorLanguage><WarningLevel>High</WarningLevel>' .
			self::xml( $body ) . "</{$call}Request>";
		$res  = wp_remote_post(
			self::trading_url(),
			array(
				'timeout' => 90,
				'headers' => array(
					'X-EBAY-API-CALL-NAME'           => $call,
					'X-EBAY-API-SITEID'              => (string) $site[0],
					'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPAT_LEVEL,
					'X-EBAY-API-IAF-TOKEN'           => $this->user_token(),
					'X-EBAY-API-APP-NAME'            => $a['client_id'],
					'X-EBAY-API-CERT-NAME'           => $a['client_secret'],
					'Content-Type'                   => 'text/xml; charset=utf-8',
				),
				'body'    => $xml,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new Api_Error( __( 'Could not reach eBay: ', 'woo-etsy-sync' ) . $res->get_error_message() );
		}
		$raw = wp_remote_retrieve_body( $res );
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			throw new Api_Error( __( 'Your web host is missing the PHP "SimpleXML" extension, which eBay needs. Ask your host to enable it.', 'woo-etsy-sync' ) );
		}
		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $raw );
		if ( ! $doc ) {
			throw new Api_Error( sprintf( __( 'eBay returned an unreadable response (HTTP %d).', 'woo-etsy-sync' ), wp_remote_retrieve_response_code( $res ) ) );
		}
		$errors   = array();
		$warnings = array();
		$expired  = false;
		foreach ( $doc->Errors ?? array() as $e ) {
			$msg = trim( (string) ( $e->LongMessage ? $e->LongMessage : $e->ShortMessage ) );
			$msg = wp_strip_all_tags( html_entity_decode( $msg ) );
			if ( in_array( (string) $e->ErrorCode, array( '21917053', '932', '931', '16110' ), true ) ) {
				$expired = true;
			}
			if ( 'Warning' === (string) $e->SeverityCode ) {
				$warnings[] = $msg;
			} else {
				$errors[] = $msg . ' (' . (string) $e->ErrorCode . ')';
			}
		}
		if ( $expired && $retry > 0 ) {
			$this->user_token( true );
			return $this->trading( $call, $body, 0 );
		}
		$ack = (string) $doc->Ack;
		if ( 'Failure' === $ack || ( $errors && 'Success' !== $ack && 'Warning' !== $ack ) ) {
			throw new Api_Error( __( 'eBay said: ', 'woo-etsy-sync' ) . implode( ' ', array_unique( $errors ) ) );
		}
		$doc->addChild( 'WesWarnings', htmlspecialchars( implode( ' | ', array_unique( $warnings ) ), ENT_XML1 ) );
		return $doc;
	}

	/* ---------------------------------------------------------------------
	 * Convenience calls.
	 * ------------------------------------------------------------------ */

	public function get_user() {
		return $this->trading( 'GetUser', array() );
	}

	public function policies() {
		$m   = Ebay_Settings::get( 'marketplace', Ebay_Settings::default_marketplace() );
		$out = array( 'shipping' => array(), 'returns' => array(), 'payment' => array() );
		$f   = $this->rest( 'GET', '/sell/account/v1/fulfillment_policy', array( 'marketplace_id' => $m ) );
		foreach ( $f['fulfillmentPolicies'] ?? array() as $p ) {
			$out['shipping'][] = array( 'id' => (string) $p['fulfillmentPolicyId'], 'label' => (string) $p['name'] );
		}
		$r = $this->rest( 'GET', '/sell/account/v1/return_policy', array( 'marketplace_id' => $m ) );
		foreach ( $r['returnPolicies'] ?? array() as $p ) {
			$out['returns'][] = array( 'id' => (string) $p['returnPolicyId'], 'label' => (string) $p['name'] );
		}
		$pp = $this->rest( 'GET', '/sell/account/v1/payment_policy', array( 'marketplace_id' => $m ) );
		foreach ( $pp['paymentPolicies'] ?? array() as $p ) {
			$out['payment'][] = array( 'id' => (string) $p['paymentPolicyId'], 'label' => (string) $p['name'] );
		}
		return $out;
	}

	public function category_tree_id() {
		$key = 'wes_ebay_tree_' . Ebay_Settings::get( 'marketplace' );
		$id  = get_transient( $key );
		if ( false === $id ) {
			$r  = $this->rest( 'GET', '/commerce/taxonomy/v1/get_default_category_tree_id', array( 'marketplace_id' => Ebay_Settings::get( 'marketplace' ) ), null, true );
			$id = (string) ( $r['categoryTreeId'] ?? '' );
			set_transient( $key, $id, WEEK_IN_SECONDS );
		}
		return $id;
	}

	public function category_suggestions( $q ) {
		$r   = $this->rest( 'GET', '/commerce/taxonomy/v1/category_tree/' . rawurlencode( $this->category_tree_id() ) . '/get_category_suggestions', array( 'q' => $q ), null, true );
		$out = array();
		foreach ( $r['categorySuggestions'] ?? array() as $s ) {
			$path = array();
			foreach ( array_reverse( $s['categoryTreeNodeAncestors'] ?? array() ) as $anc ) {
				$path[] = $anc['categoryName'];
			}
			$path[] = $s['category']['categoryName'];
			$out[]  = array(
				'id'   => (int) $s['category']['categoryId'],
				'path' => implode( ' › ', $path ),
			);
		}
		return $out;
	}

	/** Item specifics for a category: [ [ name, required, values[] ] ]. */
	public function aspects( $category_id ) {
		$key = 'wes_ebay_aspects_' . Ebay_Settings::get( 'marketplace' ) . '_' . (int) $category_id;
		$out = get_transient( $key );
		if ( false !== $out ) {
			return $out;
		}
		$r   = $this->rest( 'GET', '/commerce/taxonomy/v1/category_tree/' . rawurlencode( $this->category_tree_id() ) . '/get_item_aspects_for_category', array( 'category_id' => (int) $category_id ), null, true );
		$out = array();
		foreach ( $r['aspects'] ?? array() as $a ) {
			$out[] = array(
				'name'     => (string) $a['localizedAspectName'],
				'required' => ! empty( $a['aspectConstraint']['aspectRequired'] ),
				'values'   => array_slice( wp_list_pluck( $a['aspectValues'] ?? array(), 'localizedValue' ), 0, 30 ),
			);
		}
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}
}
