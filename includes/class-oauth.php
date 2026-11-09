<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.0 + PKCE connect flow, handled entirely on this site.
 */
class OAuth {

	public static function redirect_uri() {
		return rest_url( 'woo-etsy-sync/v1/oauth/callback' );
	}

	private static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Build the Etsy consent URL and remember the PKCE verifier for the callback.
	 */
	public static function start_url() {
		$auth = Settings::auth();
		if ( ! Settings::has_credentials() ) {
			throw new Api_Error( __( 'Add your Etsy keystring and shared secret first.', 'woo-etsy-sync' ) );
		}
		$verifier  = self::b64url( random_bytes( 48 ) );
		$challenge = self::b64url( hash( 'sha256', $verifier, true ) );
		$state     = self::b64url( random_bytes( 18 ) );

		set_transient(
			'wes_oauth_' . $state,
			array(
				'verifier' => $verifier,
				'user_id'  => get_current_user_id(),
			),
			20 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'scope'                 => rawurlencode( implode( ' ', Etsy_Api::SCOPES ) ),
				'client_id'             => rawurlencode( $auth['keystring'] ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			Etsy_Api::CONNECT_URL
		);
	}

	/**
	 * REST callback target. Etsy redirects the browser here with ?code&state.
	 */
	public static function handle_callback( \WP_REST_Request $request ) {
		$admin = admin_url( 'admin.php?page=woo-etsy-sync#/connection' );

		$error = $request->get_param( 'error' );
		if ( $error ) {
			$desc = (string) $request->get_param( 'error_description' );
			Logger::error( __( 'Etsy connection was cancelled or refused: ', 'woo-etsy-sync' ) . ( $desc ? $desc : $error ), 'auth' );
			return self::redirect( add_query_arg( 'wes_error', rawurlencode( $desc ? $desc : $error ), $admin ) );
		}

		$state   = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code    = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$pending = $state ? get_transient( 'wes_oauth_' . $state ) : false;
		if ( ! $pending || ! $code ) {
			return self::redirect( add_query_arg( 'wes_error', rawurlencode( __( 'This connection link has expired. Please click Connect again.', 'woo-etsy-sync' ) ), $admin ) );
		}
		delete_transient( 'wes_oauth_' . $state );

		try {
			$api    = Etsy_Api::instance();
			$tokens = $api->exchange_code( $code, $pending['verifier'], self::redirect_uri() );
			Settings::update_auth(
				array(
					'access_token'  => $tokens['access_token'],
					'refresh_token' => $tokens['refresh_token'],
					'expires_at'    => time() + (int) $tokens['expires_in'],
				)
			);
			$me     = $api->get_me();
			$shop_id = (int) ( $me['shop_id'] ?? 0 );
			if ( ! $shop_id ) {
				throw new Api_Error( __( 'This Etsy account does not have a shop yet.', 'woo-etsy-sync' ) );
			}
			$shop = $api->get_shop( $shop_id );
			Settings::update_auth(
				array(
					'user_id'      => (int) ( $me['user_id'] ?? 0 ),
					'shop_id'      => $shop_id,
					'shop_name'    => (string) ( $shop['shop_name'] ?? '' ),
					'shop_url'     => (string) ( $shop['url'] ?? '' ),
					'currency'     => (string) ( $shop['currency_code'] ?? '' ),
					'connected_at' => time(),
				)
			);
			delete_option( 'wes_needs_reconnect' );
			if ( ! get_option( Stock_Sync::LAST_POLL_OPTION ) ) {
				update_option( Stock_Sync::LAST_POLL_OPTION, time(), false ); // Only sales after connecting count.
			}
			Cache::flush();
			Scheduler::ensure_schedules();
			Logger::success( sprintf( __( 'Connected to Etsy shop "%s".', 'woo-etsy-sync' ), $shop['shop_name'] ?? $shop_id ), 'auth' );
			return self::redirect( add_query_arg( 'wes_connected', '1', $admin ) );
		} catch ( \Exception $e ) {
			Logger::error( __( 'Connecting to Etsy failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'auth' );
			return self::redirect( add_query_arg( 'wes_error', rawurlencode( $e->getMessage() ), $admin ) );
		}
	}

	private static function redirect( $url ) {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}
}
