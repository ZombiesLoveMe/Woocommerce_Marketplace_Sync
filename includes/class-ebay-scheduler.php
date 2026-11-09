<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs for eBay (Action Scheduler, same group as the Etsy jobs).
 */
class Ebay_Scheduler {

	const HOOK_SYNC        = 'wes_ebay_sync_product';
	const HOOK_STOCK       = 'wes_ebay_push_stock';
	const HOOK_POLL        = 'wes_ebay_poll_orders';
	const HOOK_LINK        = 'wes_ebay_link_page';
	const HOOK_RECONCILE   = 'wes_ebay_push_all_stock';

	public static function init() {
		add_action( self::HOOK_SYNC, array( __CLASS__, 'run_sync' ) );
		add_action( self::HOOK_STOCK, array( __CLASS__, 'run_stock' ) );
		add_action( self::HOOK_POLL, array( __CLASS__, 'run_poll' ) );
		add_action( self::HOOK_LINK, array( Ebay_Linker::class, 'run_page' ), 10, 1 );
		add_action( self::HOOK_RECONCILE, array( __CLASS__, 'run_reconcile' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ), 21 );
		add_action( 'wes_ebay_settings_updated', array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	public static function ensure_schedules() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! Ebay_Settings::is_connected() ) {
			return;
		}
		$want = Ebay_Settings::get( 'sync_stock_from_ebay' );
		$has  = as_next_scheduled_action( self::HOOK_POLL, array(), Scheduler::GROUP );
		if ( $want && false === $has ) {
			as_schedule_recurring_action( time() + 90, (int) Ebay_Settings::get( 'poll_minutes', 15 ) * MINUTE_IN_SECONDS, self::HOOK_POLL, array(), Scheduler::GROUP );
		} elseif ( ! $want && $has ) {
			as_unschedule_all_actions( self::HOOK_POLL );
		}
	}

	public static function on_settings_updated( $new, $old ) {
		if ( function_exists( 'as_unschedule_all_actions' ) && ( (int) $new['poll_minutes'] !== (int) $old['poll_minutes'] || (bool) $new['sync_stock_from_ebay'] !== (bool) $old['sync_stock_from_ebay'] ) ) {
			as_unschedule_all_actions( self::HOOK_POLL );
			self::ensure_schedules();
		}
	}

	public static function queue_sync( array $ids ) {
		$n = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id && ! Ebay_Mapper::is_excluded( $id ) ) {
				if ( ! Ebay_Sync::item_id( $id ) ) {
					update_post_meta( $id, '_wes_ebay_status', 'queued' );
				}
				Scheduler::enqueue( self::HOOK_SYNC, array( $id ) );
				$n++;
			}
		}
		return $n;
	}

	public static function run_sync( $id ) {
		try {
			Ebay_Sync::sync( (int) $id );
		} catch ( \Exception $e ) {
			return; // Logged on the product.
		}
	}

	public static function run_stock( $id ) {
		try {
			Ebay_Sync::push_stock( (int) $id );
		} catch ( \Exception $e ) {
			Logger::error( __( 'Stock update to eBay failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-stock', (int) $id );
		}
	}

	public static function run_poll() {
		try {
			$s = Ebay_Sync::poll_orders();
			if ( $s['lines'] ) {
				Logger::info( sprintf( __( 'Checked eBay orders: %1$d new sale(s), %2$d stock adjustment(s).', 'woo-etsy-sync' ), $s['lines'], $s['adjusted'] ), 'ebay-orders' );
			}
		} catch ( \Exception $e ) {
			Logger::error( __( 'Checking eBay orders failed: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-orders' );
		}
	}

	public static function run_reconcile() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wes_ebay_item_id' AND meta_value <> ''" );
		foreach ( $ids as $id ) {
			Scheduler::enqueue( self::HOOK_STOCK, array( (int) $id ) );
		}
		Logger::info( sprintf( __( 'Queued eBay stock updates for %d linked product(s).', 'woo-etsy-sync' ), count( $ids ) ), 'ebay-stock' );
		return count( $ids );
	}
}
