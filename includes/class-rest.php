<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * REST API used by the admin screens.
 */
class Rest {

	const NS = 'woo-etsy-sync/v1';

	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function route( $path, $methods, $callback, $public = false ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => function ( \WP_REST_Request $request ) use ( $callback ) {
					try {
						$result = call_user_func( $callback, $request );
						return $result instanceof \WP_REST_Response ? $result : rest_ensure_response( $result );
					} catch ( \Exception $e ) {
						$status = ( $e instanceof Api_Error && $e->status() >= 400 && $e->status() < 600 ) ? $e->status() : 400;
						if ( 401 === $status ) {
							$status = 400; // Don't confuse the browser's own WP login state.
						}
						return new \WP_Error( 'wes_error', $e->getMessage(), array( 'status' => $status ) );
					}
				},
				'permission_callback' => $public ? '__return_true' : array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function register() {
		self::route( '/oauth/callback', 'GET', array( OAuth::class, 'handle_callback' ), true );

		self::route( '/state', 'GET', array( __CLASS__, 'state' ) );
		self::route( '/credentials', 'POST', array( __CLASS__, 'save_credentials' ) );
		self::route( '/connect', 'POST', array( __CLASS__, 'connect' ) );
		self::route( '/disconnect', 'POST', array( __CLASS__, 'disconnect' ) );
		self::route( '/settings', 'POST', array( __CLASS__, 'save_settings' ) );
		self::route( '/shop-data', 'GET', array( __CLASS__, 'shop_data' ) );
		self::route( '/taxonomy', 'GET', array( __CLASS__, 'taxonomy' ) );
		self::route( '/readiness', 'POST', array( __CLASS__, 'create_readiness' ) );
		self::route( '/wc-terms', 'GET', array( __CLASS__, 'wc_terms' ) );

		self::route( '/products', 'GET', array( __CLASS__, 'products' ) );
		self::route( '/products/sync', 'POST', array( __CLASS__, 'queue_sync' ) );
		self::route( '/products/(?P<id>\d+)/sync-now', 'POST', array( __CLASS__, 'sync_now' ) );
		self::route( '/products/(?P<id>\d+)/unlink', 'POST', array( __CLASS__, 'unlink' ) );
		self::route( '/products/(?P<id>\d+)/link', 'POST', array( __CLASS__, 'link' ) );
		self::route( '/products/(?P<id>\d+)/preview', 'GET', array( __CLASS__, 'preview' ) );
		self::route( '/products/(?P<id>\d+)/state', 'POST', array( __CLASS__, 'set_state' ) );
		self::route( '/products/(?P<id>\d+)/exclude', 'POST', array( __CLASS__, 'exclude' ) );

		self::route( '/stock/push-all', 'POST', array( __CLASS__, 'push_all' ) );
		self::route( '/stock/poll-now', 'POST', array( __CLASS__, 'poll_now' ) );
		self::route( '/link/start', 'POST', array( __CLASS__, 'link_start' ) );
		self::route( '/link/clear', 'POST', array( __CLASS__, 'link_clear' ) );
		self::route( '/migrate', 'POST', array( __CLASS__, 'migrate' ) );

		self::route( '/log', 'GET', array( __CLASS__, 'log' ) );
		self::route( '/log', 'DELETE', array( __CLASS__, 'clear_log' ) );
		self::route( '/tick', 'POST', array( __CLASS__, 'tick' ) );
	}

	/* ---------------------------------------------------------------------
	 * State / connection.
	 * ------------------------------------------------------------------ */

	public static function stats() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wes_listing_id' AND m.meta_value <> '' AND p.post_status <> 'trash'" );
		$errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wes_status' AND m.meta_value = 'error' AND p.post_status <> 'trash'" );
		// phpcs:enable
		return array(
			'products' => $total,
			'linked'   => $linked,
			'errors'   => $errors,
			'pending'  => Scheduler::pending_counts(),
			'log24h'   => Logger::counts_since( DAY_IN_SECONDS ),
		);
	}

	public static function state() {
		$auth = Settings::auth();
		return array(
			'connected'       => Settings::is_connected(),
			'has_credentials' => Settings::has_credentials(),
			'needs_reconnect' => (bool) get_option( 'wes_needs_reconnect' ),
			'keystring'       => $auth['keystring'],
			'has_secret'      => '' !== $auth['shared_secret'],
			'shop'            => array(
				'id'           => (int) $auth['shop_id'],
				'name'         => $auth['shop_name'],
				'url'          => $auth['shop_url'],
				'currency'     => $auth['currency'],
				'connected_at' => (int) $auth['connected_at'],
			),
			'store_currency'  => get_woocommerce_currency(),
			'redirect_uri'    => OAuth::redirect_uri(),
			'site_https'      => 0 === strpos( OAuth::redirect_uri(), 'https://' ),
			'settings'        => Settings::all(),
			'stats'           => self::stats(),
			'last_poll'       => get_option( 'wes_last_poll_summary', null ),
			'link_report'     => Linker::report(),
			'platy'           => Migrate::detect(),
			'when_made'       => Mapper::when_made_options(),
			'woo_units'       => array(
				'weight'    => get_option( 'woocommerce_weight_unit' ),
				'dimension' => get_option( 'woocommerce_dimension_unit' ),
			),
		);
	}

	public static function save_credentials( \WP_REST_Request $r ) {
		$keystring = trim( sanitize_text_field( (string) $r->get_param( 'keystring' ) ) );
		$secret    = trim( sanitize_text_field( (string) $r->get_param( 'shared_secret' ) ) );
		if ( '' === $keystring ) {
			throw new Api_Error( __( 'Please paste your Etsy keystring.', 'woo-etsy-sync' ) );
		}
		$current = Settings::auth();
		if ( '' === $secret ) {
			$secret = $current['shared_secret'];
		}
		if ( '' === $secret ) {
			throw new Api_Error( __( 'Please paste your Etsy shared secret.', 'woo-etsy-sync' ) );
		}
		if ( $keystring !== $current['keystring'] && $current['refresh_token'] ) {
			Settings::disconnect(); // Different app = old tokens are useless.
		}
		Settings::update_auth(
			array(
				'keystring'     => $keystring,
				'shared_secret' => $secret,
			)
		);
		try {
			Etsy_Api::instance()->ping();
		} catch ( Api_Error $e ) {
			throw new Api_Error( __( 'Saved, but Etsy did not accept these keys: ', 'woo-etsy-sync' ) . $e->getMessage() . __( ' Double-check the keystring and shared secret. If your app still says "Pending Personal Approval" on Etsy, that is normal: your keys are saved, so just come back and click Connect once Etsy approves it.', 'woo-etsy-sync' ) );
		}
		return array(
			'ok'    => true,
			'state' => self::state(),
		);
	}

	public static function connect() {
		return array( 'url' => OAuth::start_url() );
	}

	public static function disconnect() {
		Settings::disconnect();
		Cache::flush();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::HOOK_POLL_ORDERS );
		}
		Logger::info( __( 'Disconnected from Etsy.', 'woo-etsy-sync' ), 'auth' );
		return self::state();
	}

	public static function save_settings( \WP_REST_Request $r ) {
		$values = (array) $r->get_json_params();
		Settings::update( $values );
		return self::state();
	}

	public static function shop_data( \WP_REST_Request $r ) {
		$refresh = (bool) $r->get_param( 'refresh' );
		if ( ! Settings::is_connected() ) {
			throw new Api_Error( __( 'Connect your Etsy shop first.', 'woo-etsy-sync' ) );
		}
		if ( $refresh ) {
			Cache::flush();
		}
		return array(
			'shipping'  => Cache::shipping_profiles(),
			'readiness' => Cache::readiness_states(),
			'sections'  => Cache::sections(),
			'returns'   => Cache::return_policies(),
		);
	}

	public static function taxonomy() {
		return Cache::taxonomy();
	}

	public static function create_readiness( \WP_REST_Request $r ) {
		$type = 'made_to_order' === $r->get_param( 'readiness_state' ) ? 'made_to_order' : 'ready_to_ship';
		$min  = max( 1, absint( $r->get_param( 'min' ) ) );
		$max  = max( $min, absint( $r->get_param( 'max' ) ) );
		$unit = 'weeks' === $r->get_param( 'unit' ) ? 'weeks' : 'days';
		$res  = Etsy_Api::instance()->create_readiness_state(
			Settings::shop_id(),
			array(
				'readiness_state'      => $type,
				'min_processing_time'  => $min,
				'max_processing_time'  => $max,
				'processing_time_unit' => $unit,
			)
		);
		Cache::readiness_states( true );
		return array(
			'id'        => (int) ( $res['readiness_state_id'] ?? 0 ),
			'readiness' => Cache::readiness_states(),
		);
	}

	public static function wc_terms() {
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$out  = array( 'categories' => array(), 'shipping_classes' => array() );
		foreach ( is_wp_error( $cats ) ? array() : $cats as $t ) {
			$out['categories'][] = array(
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode( $t->name ),
				'parent' => (int) $t->parent,
				'count'  => (int) $t->count,
			);
		}
		$classes = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		foreach ( is_wp_error( $classes ) ? array() : $classes as $t ) {
			$out['shipping_classes'][] = array(
				'id'    => (int) $t->term_id,
				'name'  => html_entity_decode( $t->name ),
				'count' => (int) $t->count,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Products.
	 * ------------------------------------------------------------------ */

	public static function product_row( \WC_Product $p ) {
		$id         = $p->get_id();
		$listing_id = Product_Sync::listing_id( $id );
		$status     = (string) get_post_meta( $id, '_wes_status', true );
		if ( Mapper::is_excluded( $id ) ) {
			$status = 'excluded';
		} elseif ( ! $status ) {
			$status = $listing_id ? 'linked' : 'not_synced';
		}
		if ( $p->managing_stock() ) {
			$stock = (string) (int) $p->get_stock_quantity();
		} elseif ( $p->is_type( 'variable' ) ) {
			$sum     = 0;
			$tracked = false;
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && $v->managing_stock() ) {
					$tracked = true;
					$sum    += max( 0, (int) $v->get_stock_quantity() );
				}
			}
			$stock = $tracked ? (string) $sum : ( 'outofstock' === $p->get_stock_status() ? __( 'Out of stock', 'woo-etsy-sync' ) : __( 'In stock', 'woo-etsy-sync' ) );
		} else {
			$stock = 'outofstock' === $p->get_stock_status() ? __( 'Out of stock', 'woo-etsy-sync' ) : __( 'In stock', 'woo-etsy-sync' );
		}
		$thumb = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '';
		return array(
			'id'            => $id,
			'name'          => html_entity_decode( $p->get_name(), ENT_QUOTES ),
			'sku'           => $p->get_sku(),
			'type'          => $p->get_type(),
			'variations'    => $p->is_type( 'variable' ) ? count( $p->get_children() ) : 0,
			'price'         => html_entity_decode( wp_strip_all_tags( wc_price( (float) $p->get_price() ) ) ),
			'stock'         => $stock,
			'thumb'         => $thumb ? $thumb : '',
			'status'        => $status,
			'error'         => 'excluded' === $status ? '' : (string) get_post_meta( $id, '_wes_error', true ),
			'listing_id'    => $listing_id,
			'listing_url'   => Product_Sync::listing_url( $listing_id ),
			'listing_state' => (string) get_post_meta( $id, '_wes_listing_state', true ),
			'synced_at'     => (int) get_post_meta( $id, '_wes_synced_at', true ),
			'edit_url'      => get_edit_post_link( $id, 'raw' ),
		);
	}

	private static function product_query_args( \WP_REST_Request $r ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
			'posts_per_page' => max( 1, min( 100, (int) ( $r->get_param( 'per_page' ) ? $r->get_param( 'per_page' ) : 20 ) ) ),
			'paged'          => max( 1, (int) $r->get_param( 'page' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
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
		$cat = (int) $r->get_param( 'category' );
		if ( $cat ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $cat,
				),
			);
		}
		switch ( (string) $r->get_param( 'status' ) ) {
			case 'linked':
				$args['meta_query'] = array( array( 'key' => '_wes_listing_id', 'compare' => 'EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'not_synced':
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array( 'key' => '_wes_listing_id', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wes_exclude', 'compare' => 'NOT EXISTS' ),
				);
				break;
			case 'error':
				$args['meta_query'] = array( array( 'key' => '_wes_status', 'value' => 'error' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'excluded':
				$args['meta_query'] = array( array( 'key' => '_wes_exclude', 'value' => 'yes' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
		}
		return $args;
	}

	public static function products( \WP_REST_Request $r ) {
		$q    = new \WP_Query( self::product_query_args( $r ) );
		$rows = array();
		foreach ( $q->posts as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$rows[] = self::product_row( $p );
			}
		}
		return array(
			'rows'  => $rows,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
		);
	}

	public static function queue_sync( \WP_REST_Request $r ) {
		if ( ! Settings::is_connected() ) {
			throw new Api_Error( __( 'Connect your Etsy shop first.', 'woo-etsy-sync' ) );
		}
		$ids = array_map( 'absint', (array) $r->get_param( 'ids' ) );
		if ( $r->get_param( 'all' ) ) {
			$r->set_param( 'per_page', 100 );
			$args                   = self::product_query_args( $r );
			$args['posts_per_page'] = -1;
			$args['post_status']    = array( 'publish' );
			unset( $args['paged'] );
			$q   = new \WP_Query( $args );
			$ids = $q->posts;
		}
		$ids = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					return ! Mapper::is_excluded( $id );
				}
			)
		);
		$count = Scheduler::queue_sync( $ids );
		Logger::info( sprintf( __( 'Queued %d product(s) to sync to Etsy.', 'woo-etsy-sync' ), $count ), 'sync' );
		return array(
			'queued'  => $count,
			'pending' => Scheduler::pending_counts(),
		);
	}

	public static function sync_now( \WP_REST_Request $r ) {
		$id     = (int) $r['id'];
		$result = Product_Sync::sync( $id );
		return array(
			'result' => $result,
			'row'    => self::product_row( wc_get_product( $id ) ),
		);
	}

	public static function unlink( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$listing_id = Product_Sync::listing_id( $id );
		$delete     = (bool) $r->get_param( 'delete_listing' );
		if ( $delete && $listing_id ) {
			Etsy_Api::instance()->delete_listing( $listing_id );
			Logger::info( __( 'Etsy listing deleted.', 'woo-etsy-sync' ), 'sync', $id, $listing_id );
		}
		Product_Sync::unlink( $id );
		Logger::info( __( 'Unlinked from Etsy.', 'woo-etsy-sync' ), 'sync', $id, $listing_id );
		return self::product_row( wc_get_product( $id ) );
	}

	public static function link( \WP_REST_Request $r ) {
		$id    = (int) $r['id'];
		$input = (string) $r->get_param( 'listing' );
		if ( ! preg_match( '/(\d{6,})/', $input, $m ) ) {
			throw new Api_Error( __( 'Paste an Etsy listing link or listing number.', 'woo-etsy-sync' ) );
		}
		$listing_id = (int) $m[1];
		$listing    = Etsy_Api::instance()->get_listing( $listing_id );
		if ( (int) ( $listing['shop_id'] ?? 0 ) !== Settings::shop_id() ) {
			throw new Api_Error( __( 'That listing belongs to a different Etsy shop.', 'woo-etsy-sync' ) );
		}
		Product_Sync::link( $id, $listing_id );
		update_post_meta( $id, '_wes_listing_state', $listing['state'] ?? '' );
		Logger::success( __( 'Linked manually to an existing Etsy listing.', 'woo-etsy-sync' ), 'link', $id, $listing_id );
		return self::product_row( wc_get_product( $id ) );
	}

	public static function preview( \WP_REST_Request $r ) {
		return Product_Sync::preview( (int) $r['id'] );
	}

	public static function set_state( \WP_REST_Request $r ) {
		$state = 'active' === $r->get_param( 'state' ) ? 'active' : 'inactive';
		Product_Sync::set_state( (int) $r['id'], $state );
		delete_post_meta( (int) $r['id'], '_wes_auto_off' );
		return self::product_row( wc_get_product( (int) $r['id'] ) );
	}

	public static function exclude( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( $r->get_param( 'exclude' ) ) {
			update_post_meta( $id, '_wes_exclude', 'yes' );
		} else {
			delete_post_meta( $id, '_wes_exclude' );
		}
		return self::product_row( wc_get_product( $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Stock / linking / log.
	 * ------------------------------------------------------------------ */

	public static function push_all() {
		$count = Scheduler::run_reconcile();
		return array(
			'queued'  => $count,
			'pending' => Scheduler::pending_counts(),
		);
	}

	public static function poll_now() {
		$summary = Stock_Sync::poll_orders();
		if ( ! get_option( 'wes_last_poll_summary' ) ) {
			update_option( 'wes_last_poll_summary', array_merge( $summary, array( 'at' => time() ) ), false );
		}
		return array(
			'summary'   => $summary,
			'last_poll' => get_option( 'wes_last_poll_summary' ),
		);
	}

	public static function link_start( \WP_REST_Request $r ) {
		if ( ! Settings::is_connected() ) {
			throw new Api_Error( __( 'Connect your Etsy shop first.', 'woo-etsy-sync' ) );
		}
		Linker::start( (bool) $r->get_param( 'match_titles' ) );
		return Linker::report();
	}

	public static function migrate() {
		$result = Migrate::run();
		return array(
			'result' => $result,
			'state'  => self::state(),
		);
	}

	public static function link_clear() {
		delete_option( Linker::REPORT_OPTION );
		return array( 'ok' => true );
	}

	public static function log( \WP_REST_Request $r ) {
		return Logger::query(
			array(
				'level'      => sanitize_key( (string) $r->get_param( 'level' ) ),
				'product_id' => (int) $r->get_param( 'product_id' ),
				'search'     => sanitize_text_field( (string) $r->get_param( 'search' ) ),
				'channel'    => sanitize_key( (string) $r->get_param( 'channel' ) ),
				'page'       => (int) $r->get_param( 'page' ),
			)
		);
	}

	public static function clear_log() {
		Logger::clear();
		return array( 'ok' => true );
	}

	public static function tick() {
		$ran = Scheduler::tick( 15 );
		return array(
			'ran'         => $ran,
			'stats'       => self::stats(),
			'link_report' => Linker::report(),
			'ebay_stats'  => Ebay_Rest::stats(),
			'ebay_link_report' => Ebay_Linker::report(),
		);
	}
}
