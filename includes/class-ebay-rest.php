<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for the eBay screens.
 */
class Ebay_Rest {

	public static function register() {
		Rest::route( '/ebay/oauth/callback', 'GET', array( Ebay_OAuth::class, 'handle_callback' ), true );
		Rest::route( '/ebay/notifications', array( 'GET', 'POST' ), array( Ebay_OAuth::class, 'handle_notification' ), true );

		Rest::route( '/ebay/state', 'GET', array( __CLASS__, 'state' ) );
		Rest::route( '/ebay/credentials', 'POST', array( __CLASS__, 'credentials' ) );
		Rest::route( '/ebay/connect', 'POST', array( __CLASS__, 'connect' ) );
		Rest::route( '/ebay/disconnect', 'POST', array( __CLASS__, 'disconnect' ) );
		Rest::route( '/ebay/settings', 'POST', array( __CLASS__, 'save_settings' ) );
		Rest::route( '/ebay/policies', 'GET', array( __CLASS__, 'policies' ) );
		Rest::route( '/ebay/categories', 'GET', array( __CLASS__, 'categories' ) );
		Rest::route( '/ebay/aspects', 'GET', array( __CLASS__, 'aspects' ) );
		Rest::route( '/ebay/oos-control', 'POST', array( __CLASS__, 'oos_control' ) );

		Rest::route( '/ebay/products', 'GET', array( __CLASS__, 'products' ) );
		Rest::route( '/ebay/products/sync', 'POST', array( __CLASS__, 'queue_sync' ) );
		Rest::route( '/ebay/products/(?P<id>\d+)/sync-now', 'POST', array( __CLASS__, 'sync_now' ) );
		Rest::route( '/ebay/products/(?P<id>\d+)/preview', 'GET', array( __CLASS__, 'preview' ) );
		Rest::route( '/ebay/products/(?P<id>\d+)/unlink', 'POST', array( __CLASS__, 'unlink' ) );
		Rest::route( '/ebay/products/(?P<id>\d+)/link', 'POST', array( __CLASS__, 'link' ) );
		Rest::route( '/ebay/products/(?P<id>\d+)/exclude', 'POST', array( __CLASS__, 'exclude' ) );

		Rest::route( '/ebay/stock/push-all', 'POST', array( __CLASS__, 'push_all' ) );
		Rest::route( '/ebay/stock/poll-now', 'POST', array( __CLASS__, 'poll_now' ) );
		Rest::route( '/ebay/link/start', 'POST', array( __CLASS__, 'link_start' ) );
		Rest::route( '/ebay/wplister/import', 'POST', array( __CLASS__, 'wplister_import' ) );
	}

	public static function stats() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wes_ebay_item_id' AND m.meta_value <> '' AND p.post_status <> 'trash'" );
		$errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wes_ebay_status' AND m.meta_value = 'error' AND p.post_status <> 'trash'" );
		// phpcs:enable
		return array( 'products' => $total, 'linked' => $linked, 'errors' => $errors, 'log24h' => Logger::counts_since( DAY_IN_SECONDS, 'ebay' ) );
	}

	public static function state() {
		$a = Ebay_Settings::auth();
		return array(
			'connected'        => Ebay_Settings::is_connected(),
			'has_credentials'  => Ebay_Settings::has_credentials(),
			'needs_reconnect'  => (bool) get_option( 'wes_ebay_needs_reconnect' ),
			'client_id'        => $a['client_id'],
			'runame'           => $a['runame'],
			'sandbox'          => (bool) $a['sandbox'],
			'has_secret'       => '' !== $a['client_secret'],
			'user_id'          => $a['user_id'],
			'connected_at'     => (int) $a['connected_at'],
			'refresh_expires_at' => (int) $a['refresh_expires_at'],
			'accept_url'       => Ebay_OAuth::accept_url(),
			'notification_url' => Ebay_OAuth::notification_url(),
			'verification_token' => Ebay_Settings::verification_token(),
			'site_https'       => 0 === strpos( home_url(), 'https://' ),
			'privacy_url'      => get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/' ),
			'sites'            => array_map(
				function ( $k, $v ) {
					return array( 'id' => $k, 'label' => $v[2], 'currency' => $v[1], 'host' => $v[4] );
				},
				array_keys( Ebay_Settings::sites() ),
				array_values( Ebay_Settings::sites() )
			),
			'settings'         => Ebay_Settings::all(),
			'stats'            => self::stats(),
			'last_poll'        => get_option( 'wes_ebay_last_poll_summary', null ),
			'link_report'      => Ebay_Linker::report(),
			'wplister'         => Ebay_Linker::wplister_detect(),
			'wplister_active'  => Ebay_Settings::wplister_active(),
			'oos_control'      => (bool) get_option( 'wes_ebay_oos_control' ),
			'store_currency'   => get_woocommerce_currency(),
			'store_country'    => Plugin::store_country(),
		);
	}

	public static function credentials( \WP_REST_Request $r ) {
		$id     = trim( sanitize_text_field( (string) $r->get_param( 'client_id' ) ) );
		$secret = trim( sanitize_text_field( (string) $r->get_param( 'client_secret' ) ) );
		$runame = trim( (string) $r->get_param( 'runame' ) );
		// People often paste eBay's whole "Test sign-in" link: pull the RuName out of it.
		if ( false !== strpos( $runame, 'redirect_uri=' ) ) {
			$query = (string) wp_parse_url( $runame, PHP_URL_QUERY );
			parse_str( $query ? $query : $runame, $parts );
			$runame = (string) ( $parts['redirect_uri'] ?? '' );
		}
		$runame = trim( sanitize_text_field( $runame ) );
		if ( '' !== $runame && preg_match( '#^https?://#i', $runame ) ) {
			throw new Api_Error( __( 'The RuName is a short name like "YourName-YourName-MyApp-abcdef", not a web address. On eBay\'s User Tokens page it is shown under "RuName (eBay Redirect URL name)".', 'woo-etsy-sync' ) );
		}
		$cur    = Ebay_Settings::auth();
		if ( '' === $secret ) {
			$secret = $cur['client_secret'];
		}
		if ( '' === $id || '' === $secret || '' === $runame ) {
			throw new Api_Error( __( 'Please fill in the App ID, Cert ID and RuName.', 'woo-etsy-sync' ) );
		}
		if ( $id !== $cur['client_id'] || (bool) $r->get_param( 'sandbox' ) !== (bool) $cur['sandbox'] ) {
			Ebay_Settings::disconnect();
		}
		Ebay_Settings::update_auth(
			array(
				'client_id'     => $id,
				'client_secret' => $secret,
				'runame'        => $runame,
				'sandbox'       => (bool) $r->get_param( 'sandbox' ),
				'app_token'     => '',
				'app_expires_at' => 0,
			)
		);
		try {
			Ebay_Api::instance()->app_token();
		} catch ( Api_Error $e ) {
			throw new Api_Error( __( 'Saved, but eBay did not accept these keys: ', 'woo-etsy-sync' ) . $e->getMessage() . __( ' Check you copied the Production (not Sandbox) App ID and Cert ID.', 'woo-etsy-sync' ) );
		}
		return array( 'ok' => true, 'state' => self::state() );
	}

	public static function connect() {
		return array( 'url' => Ebay_OAuth::start_url() );
	}

	public static function disconnect() {
		Ebay_Settings::disconnect();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Ebay_Scheduler::HOOK_POLL );
		}
		Logger::info( __( 'Disconnected from eBay.', 'woo-etsy-sync' ), 'ebay-auth' );
		return self::state();
	}

	public static function save_settings( \WP_REST_Request $r ) {
		Ebay_Settings::update( (array) $r->get_json_params() );
		return self::state();
	}

	public static function policies( \WP_REST_Request $r ) {
		$key = 'wes_ebay_policies_' . Ebay_Settings::get( 'marketplace' );
		$v   = $r->get_param( 'refresh' ) ? false : get_transient( $key );
		if ( false === $v ) {
			$v = Ebay_Api::instance()->policies();
			set_transient( $key, $v, 6 * HOUR_IN_SECONDS );
		}
		return $v;
	}

	public static function categories( \WP_REST_Request $r ) {
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		return Ebay_Api::instance()->category_suggestions( $q );
	}

	public static function aspects( \WP_REST_Request $r ) {
		return Ebay_Api::instance()->aspects( (int) $r->get_param( 'category' ) );
	}

	public static function oos_control() {
		return array( 'ok' => Ebay_OAuth::enable_out_of_stock_control() );
	}

	/* ------------------------------------------------------------------ */

	public static function row( \WC_Product $p ) {
		$row     = Rest::product_row( $p );
		$id      = $p->get_id();
		$item_id = Ebay_Sync::item_id( $id );
		$status  = (string) get_post_meta( $id, '_wes_ebay_status', true );
		if ( Ebay_Mapper::is_excluded( $id ) ) {
			$status = 'excluded';
		} elseif ( ! $status ) {
			$status = $item_id ? 'linked' : 'not_synced';
		}
		$row['status']        = $status;
		$row['error']         = 'excluded' === $status ? '' : (string) get_post_meta( $id, '_wes_ebay_error', true );
		$row['listing_id']    = $item_id;
		$row['listing_url']   = Ebay_Sync::item_url( $item_id );
		$row['listing_state'] = (string) get_post_meta( $id, '_wes_ebay_state', true );
		$row['synced_at']     = (int) get_post_meta( $id, '_wes_ebay_synced_at', true );
		return $row;
	}

	private static function query_args( \WP_REST_Request $r ) {
		$args   = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
			'posts_per_page' => max( 1, min( 100, (int) ( $r->get_param( 'per_page' ) ? $r->get_param( 'per_page' ) : 20 ) ) ),
			'paged'          => max( 1, (int) $r->get_param( 'page' ) ),
			'fields'         => 'ids',
		);
		$search = trim( (string) $r->get_param( 'search' ) );
		if ( '' !== $search ) {
			$by_sku = wc_get_product_id_by_sku( $search );
			if ( $by_sku ) {
				$parent           = wp_get_post_parent_id( $by_sku );
				$args['post__in'] = array( $parent ? $parent : $by_sku );
			} else {
				$args['s'] = $search;
			}
		}
		// phpcs:disable WordPress.DB.SlowDBQuery
		switch ( (string) $r->get_param( 'status' ) ) {
			case 'linked':
				$args['meta_query'] = array( array( 'key' => '_wes_ebay_item_id', 'compare' => 'EXISTS' ) );
				break;
			case 'not_synced':
				$args['meta_query'] = array( 'relation' => 'AND', array( 'key' => '_wes_ebay_item_id', 'compare' => 'NOT EXISTS' ), array( 'key' => '_wes_ebay_exclude', 'compare' => 'NOT EXISTS' ) );
				break;
			case 'error':
				$args['meta_query'] = array( array( 'key' => '_wes_ebay_status', 'value' => 'error' ) );
				break;
			case 'excluded':
				$args['meta_query'] = array( array( 'key' => '_wes_ebay_exclude', 'value' => 'yes' ) );
				break;
		}
		// phpcs:enable
		return $args;
	}

	public static function products( \WP_REST_Request $r ) {
		$q    = new \WP_Query( self::query_args( $r ) );
		$rows = array();
		foreach ( $q->posts as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$rows[] = self::row( $p );
			}
		}
		return array( 'rows' => $rows, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages );
	}

	public static function queue_sync( \WP_REST_Request $r ) {
		if ( ! Ebay_Settings::is_connected() ) {
			throw new Api_Error( __( 'Connect your eBay account first.', 'woo-etsy-sync' ) );
		}
		$ids = array_map( 'absint', (array) $r->get_param( 'ids' ) );
		if ( $r->get_param( 'all' ) ) {
			$args                   = self::query_args( $r );
			$args['posts_per_page'] = -1;
			$args['post_status']    = array( 'publish' );
			unset( $args['paged'] );
			$ids = ( new \WP_Query( $args ) )->posts;
		}
		$n = Ebay_Scheduler::queue_sync( $ids );
		Logger::info( sprintf( __( 'Queued %d product(s) to sync to eBay.', 'woo-etsy-sync' ), $n ), 'ebay-sync' );
		return array( 'queued' => $n, 'pending' => Scheduler::pending_counts() );
	}

	public static function sync_now( \WP_REST_Request $r ) {
		$res = Ebay_Sync::sync( (int) $r['id'] );
		return array( 'result' => $res, 'row' => self::row( wc_get_product( (int) $r['id'] ) ) );
	}

	public static function preview( \WP_REST_Request $r ) {
		return Ebay_Sync::preview( (int) $r['id'], (bool) $r->get_param( 'verify' ) );
	}

	public static function unlink( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( $r->get_param( 'end_listing' ) ) {
			Ebay_Sync::end( $id );
		}
		Ebay_Sync::unlink( $id );
		Logger::info( __( 'Unlinked from eBay.', 'woo-etsy-sync' ), 'ebay-sync', $id );
		return self::row( wc_get_product( $id ) );
	}

	public static function link( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! preg_match( '/(\d{9,15})/', (string) $r->get_param( 'item' ), $m ) ) {
			throw new Api_Error( __( 'Paste an eBay listing link or item number.', 'woo-etsy-sync' ) );
		}
		$res = Ebay_Api::instance()->trading( 'GetItem', array( 'ItemID' => $m[1] ) );
		if ( strtolower( (string) $res->Item->Seller->UserID ) !== strtolower( (string) Ebay_Settings::auth()['user_id'] ) ) {
			throw new Api_Error( __( 'That listing belongs to a different eBay account.', 'woo-etsy-sync' ) );
		}
		Ebay_Sync::link( $id, $m[1] );
		update_post_meta( $id, '_wes_ebay_state', strtolower( (string) $res->Item->SellingStatus->ListingStatus ) );
		Logger::success( __( 'Linked manually to eBay item ', 'woo-etsy-sync' ) . $m[1] . '.', 'ebay-link', $id );
		return self::row( wc_get_product( $id ) );
	}

	public static function exclude( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( $r->get_param( 'exclude' ) ) {
			update_post_meta( $id, '_wes_ebay_exclude', 'yes' );
		} else {
			delete_post_meta( $id, '_wes_ebay_exclude' );
		}
		return self::row( wc_get_product( $id ) );
	}

	public static function push_all() {
		$n = Ebay_Scheduler::run_reconcile();
		return array( 'queued' => $n, 'pending' => Scheduler::pending_counts() );
	}

	public static function poll_now() {
		$s = Ebay_Sync::poll_orders();
		return array( 'summary' => $s, 'last_poll' => get_option( 'wes_ebay_last_poll_summary' ) );
	}

	public static function link_start( \WP_REST_Request $r ) {
		if ( ! Ebay_Settings::is_connected() ) {
			throw new Api_Error( __( 'Connect your eBay account first.', 'woo-etsy-sync' ) );
		}
		Ebay_Linker::start( (bool) $r->get_param( 'match_titles' ) );
		return Ebay_Linker::report();
	}

	public static function wplister_import() {
		return array( 'result' => Ebay_Linker::wplister_import(), 'state' => self::state() );
	}
}
