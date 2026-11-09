<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * eBay OAuth connect flow + the "Marketplace account deletion" notification endpoint
 * that eBay requires every production app to have.
 */
class Ebay_OAuth {

	public static function accept_url() {
		return rest_url( 'woo-etsy-sync/v1/ebay/oauth/callback' );
	}

	public static function notification_url() {
		return rest_url( 'woo-etsy-sync/v1/ebay/notifications' );
	}

	public static function start_url() {
		if ( ! Ebay_Settings::has_credentials() ) {
			throw new Api_Error( __( 'Add your eBay App ID, Cert ID and RuName first.', 'woo-etsy-sync' ) );
		}
		$a     = Ebay_Settings::auth();
		$state = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		set_transient( 'wes_ebay_oauth_' . $state, get_current_user_id(), 20 * MINUTE_IN_SECONDS );
		return Ebay_Api::auth_url() . '?' . http_build_query(
			array(
				'client_id'     => $a['client_id'],
				'redirect_uri'  => $a['runame'],
				'response_type' => 'code',
				'scope'         => implode( ' ', Ebay_Api::SCOPES ),
				'state'         => $state,
				'prompt'        => 'login',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function handle_callback( \WP_REST_Request $r ) {
		$admin = admin_url( 'admin.php?page=woo-etsy-sync#/ebay' );
		if ( $r->get_param( 'error' ) || ! $r->get_param( 'code' ) ) {
			$msg = (string) ( $r->get_param( 'error_description' ) ? $r->get_param( 'error_description' ) : __( 'eBay connection was cancelled.', 'woo-etsy-sync' ) );
			Logger::error( __( 'eBay connection was cancelled or refused: ', 'woo-etsy-sync' ) . $msg, 'ebay-auth' );
			return self::redirect( add_query_arg( 'wes_ebay_error', rawurlencode( $msg ), $admin ) );
		}
		$state = sanitize_text_field( (string) $r->get_param( 'state' ) );
		if ( ! $state || false === get_transient( 'wes_ebay_oauth_' . $state ) ) {
			return self::redirect( add_query_arg( 'wes_ebay_error', rawurlencode( __( 'This connection link has expired. Please click Connect again.', 'woo-etsy-sync' ) ), $admin ) );
		}
		delete_transient( 'wes_ebay_oauth_' . $state );
		try {
			$api    = Ebay_Api::instance();
			$tokens = $api->exchange_code( (string) $r->get_param( 'code' ) );
			Ebay_Settings::update_auth(
				array(
					'access_token'       => $tokens['access_token'],
					'expires_at'         => time() + (int) $tokens['expires_in'],
					'refresh_token'      => $tokens['refresh_token'] ?? '',
					'refresh_expires_at' => time() + (int) ( $tokens['refresh_token_expires_in'] ?? 0 ),
					'connected_at'       => time(),
				)
			);
			$user = $api->get_user();
			Ebay_Settings::update_auth( array( 'user_id' => (string) $user->User->UserID ) );
			delete_option( 'wes_ebay_needs_reconnect' );
			if ( ! get_option( Ebay_Sync::LAST_POLL_OPTION ) ) {
				update_option( Ebay_Sync::LAST_POLL_OPTION, time(), false );
			}
			if ( Ebay_Settings::get( 'out_of_stock_control' ) ) {
				self::enable_out_of_stock_control();
			}
			Ebay_Scheduler::ensure_schedules();
			Logger::success( sprintf( __( 'Connected to eBay as %s.', 'woo-etsy-sync' ), (string) $user->User->UserID ), 'ebay-auth' );
			return self::redirect( add_query_arg( 'wes_ebay_connected', '1', $admin ) );
		} catch ( \Exception $e ) {
			Logger::error( __( 'Connecting to eBay failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-auth' );
			return self::redirect( add_query_arg( 'wes_ebay_error', rawurlencode( $e->getMessage() ), $admin ) );
		}
	}

	/** Keep listings alive (hidden) at quantity 0 instead of ending them. */
	public static function enable_out_of_stock_control() {
		try {
			Ebay_Api::instance()->trading( 'SetUserPreferences', array( 'OutOfStockControlPreference' => true ) );
			update_option( 'wes_ebay_oos_control', 1, false );
			return true;
		} catch ( \Exception $e ) {
			Logger::warning( __( 'Could not switch on eBay\'s "out of stock" option: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-auth' );
			return false;
		}
	}

	private static function redirect( $url ) {
		$res = new \WP_REST_Response( null, 302 );
		$res->header( 'Location', $url );
		return $res;
	}

	/**
	 * eBay marketplace account deletion notifications.
	 * GET with ?challenge_code= must answer SHA-256(challenge + token + endpoint).
	 * We store no eBay buyer/user data, so POSTed notices only need acknowledging.
	 */
	public static function handle_notification( \WP_REST_Request $r ) {
		$challenge = (string) $r->get_param( 'challenge_code' );
		if ( 'GET' === $r->get_method() && '' !== $challenge ) {
			$hash = hash( 'sha256', $challenge . Ebay_Settings::verification_token() . self::notification_url() );
			$res  = new \WP_REST_Response( array( 'challengeResponse' => $hash ), 200 );
			$res->header( 'Content-Type', 'application/json' );
			return $res;
		}
		return new \WP_REST_Response( null, 204 );
	}
}
