<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs via WooCommerce's bundled Action Scheduler.
 */
class Scheduler {

	const GROUP            = 'woo-etsy-sync';
	const HOOK_SYNC        = 'wes_sync_product';
	const HOOK_STOCK       = 'wes_push_stock';
	const HOOK_POLL_ORDERS = 'wes_poll_orders';
	const HOOK_LINK        = 'wes_link_page';
	const HOOK_RECONCILE   = 'wes_push_all_stock';
	const HOOK_DAILY       = 'wes_daily';

	public static function init() {
		add_action( self::HOOK_SYNC, array( __CLASS__, 'run_sync' ) );
		add_action( self::HOOK_STOCK, array( __CLASS__, 'run_stock' ) );
		add_action( self::HOOK_POLL_ORDERS, array( __CLASS__, 'run_poll' ) );
		add_action( self::HOOK_LINK, array( Linker::class, 'run_page' ), 10, 2 );
		add_action( self::HOOK_RECONCILE, array( __CLASS__, 'run_reconcile' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ), 20 );
		add_action( 'wes_settings_updated', array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Recurring.
	 * ------------------------------------------------------------------ */

	public static function ensure_schedules() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! Settings::is_connected() ) {
			return;
		}
		if ( Settings::get( 'sync_stock_from_etsy' ) ) {
			if ( false === as_next_scheduled_action( self::HOOK_POLL_ORDERS, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, (int) Settings::get( 'poll_minutes', 15 ) * MINUTE_IN_SECONDS, self::HOOK_POLL_ORDERS, array(), self::GROUP );
			}
		} elseif ( as_next_scheduled_action( self::HOOK_POLL_ORDERS, array(), self::GROUP ) ) {
			as_unschedule_all_actions( self::HOOK_POLL_ORDERS );
		}
		if ( false === as_next_scheduled_action( self::HOOK_DAILY, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_DAILY, array(), self::GROUP );
		}
	}

	public static function on_settings_updated( $new, $old ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		if ( (int) $new['poll_minutes'] !== (int) $old['poll_minutes'] || (bool) $new['sync_stock_from_etsy'] !== (bool) $old['sync_stock_from_etsy'] ) {
			as_unschedule_all_actions( self::HOOK_POLL_ORDERS );
			self::ensure_schedules();
		}
	}

	/* ---------------------------------------------------------------------
	 * Queueing.
	 * ------------------------------------------------------------------ */

	private static function enqueue_unique( $hook, array $args ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			do_action_ref_array( $hook, $args ); // Fallback: run now.
			return;
		}
		if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	public static function enqueue( $hook, array $args ) {
		self::enqueue_unique( $hook, $args );
	}

	public static function queue_sync( array $product_ids ) {
		$count = 0;
		foreach ( array_unique( array_map( 'absint', $product_ids ) ) as $id ) {
			if ( $id ) {
				update_post_meta( $id, '_wes_status', Product_Sync::listing_id( $id ) ? get_post_meta( $id, '_wes_status', true ) : 'queued' );
				self::enqueue_unique( self::HOOK_SYNC, array( $id ) );
				$count++;
			}
		}
		return $count;
	}

	public static function queue_stock_push( $product_id ) {
		self::enqueue_unique( self::HOOK_STOCK, array( (int) $product_id ) );
	}

	public static function pending_counts() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( 'sync' => 0, 'stock' => 0, 'link' => 0, 'ebay_sync' => 0, 'ebay_stock' => 0, 'ebay_link' => 0 );
		}
		$count = function ( $hook ) {
			return count(
				as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => self::GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 1000,
					),
					'ids'
				)
			);
		};
		return array(
			'sync'  => $count( self::HOOK_SYNC ),
			'stock' => $count( self::HOOK_STOCK ) + $count( self::HOOK_RECONCILE ),
			'link'  => $count( self::HOOK_LINK ),
			'ebay_sync'  => $count( Ebay_Scheduler::HOOK_SYNC ),
			'ebay_stock' => $count( Ebay_Scheduler::HOOK_STOCK ) + $count( Ebay_Scheduler::HOOK_RECONCILE ),
			'ebay_link'  => $count( Ebay_Scheduler::HOOK_LINK ),
		);
	}

	/**
	 * Run a few of our pending jobs right now (called while the admin screen is open,
	 * so bulk syncs progress even on sites with little traffic / WP-Cron disabled).
	 */
	public static function tick( $seconds = 20 ) {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return 0;
		}
		$start = microtime( true );
		$ran   = 0;
		foreach ( array( self::HOOK_LINK, self::HOOK_SYNC, self::HOOK_STOCK, self::HOOK_RECONCILE, Ebay_Scheduler::HOOK_LINK, Ebay_Scheduler::HOOK_SYNC, Ebay_Scheduler::HOOK_STOCK, Ebay_Scheduler::HOOK_RECONCILE, Feeds::HOOK_BUILD ) as $hook ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'         => $hook,
					'group'        => self::GROUP,
					'status'       => \ActionScheduler_Store::STATUS_PENDING,
					'date'         => gmdate( 'Y-m-d H:i:s', time() + 1 ),
					'date_compare' => '<=',
					'per_page'     => 25,
					'orderby'      => 'date',
					'order'        => 'ASC',
				),
				'ids'
			);
			foreach ( $ids as $action_id ) {
				if ( microtime( true ) - $start > $seconds ) {
					return $ran;
				}
				try {
					\ActionScheduler::runner()->process_action( $action_id, 'Woo Etsy Sync screen' );
				} catch ( \Exception $e ) {
					Logger::error( __( 'Background job failed: ', 'woo-etsy-sync' ) . $e->getMessage() );
				}
				$ran++;
			}
		}
		return $ran;
	}

	/* ---------------------------------------------------------------------
	 * Job runners.
	 * ------------------------------------------------------------------ */

	public static function run_sync( $product_id ) {
		try {
			Product_Sync::sync( (int) $product_id );
		} catch ( \Exception $e ) {
			// Already logged + saved on the product; don't make Action Scheduler retry forever.
			return;
		}
	}

	public static function run_stock( $product_id ) {
		try {
			Stock_Sync::push( (int) $product_id );
		} catch ( \Exception $e ) {
			Logger::error( __( 'Stock update to Etsy failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'stock', (int) $product_id, Product_Sync::listing_id( (int) $product_id ) );
		}
	}

	public static function run_poll() {
		try {
			$s = Stock_Sync::poll_orders();
			if ( $s['transactions'] ) {
				Logger::info( sprintf( __( 'Checked Etsy orders: %1$d new sale(s), %2$d stock adjustment(s).', 'woo-etsy-sync' ), $s['transactions'], $s['adjusted'] ), 'orders' );
			}
		} catch ( \Exception $e ) {
			Logger::error( __( 'Checking Etsy orders failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'orders' );
		}
	}

	/**
	 * Queue a stock push for every linked product.
	 */
	public static function run_reconcile() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wes_listing_id' AND meta_value <> ''" );
		foreach ( $ids as $id ) {
			self::queue_stock_push( (int) $id );
		}
		Logger::info( sprintf( __( 'Queued stock updates for %d linked product(s).', 'woo-etsy-sync' ), count( $ids ) ), 'stock' );
		return count( $ids );
	}

	public static function run_daily() {
		Logger::prune( 30 );
	}
}
