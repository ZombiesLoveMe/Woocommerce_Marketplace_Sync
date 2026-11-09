<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Link listings already live on eBay to WooCommerce products – from WP-Lister's records
 * and/or by matching SKUs and titles. Nothing on eBay is changed.
 */
class Ebay_Linker {

	const REPORT_OPTION = 'wes_ebay_link_report';

	public static function report() {
		$r = get_option( self::REPORT_OPTION, array() );
		return is_array( $r ) && $r ? $r : null;
	}

	public static function start( $match_titles = true ) {
		update_option(
			self::REPORT_OPTION,
			array(
				'status'       => 'running',
				'match_titles' => (bool) $match_titles,
				'started_at'   => time(),
				'finished_at'  => 0,
				'scanned'      => 0,
				'linked'       => array(),
				'already'      => 0,
				'unmatched'    => array(),
				'conflicts'    => array(),
				'error'        => '',
			),
			false
		);
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_unschedule_all_actions( Ebay_Scheduler::HOOK_LINK );
			as_enqueue_async_action( Ebay_Scheduler::HOOK_LINK, array( 1 ), Scheduler::GROUP );
		} else {
			self::run_page( 1 );
		}
	}

	public static function run_page( $page ) {
		$r = self::report();
		if ( ! $r || 'running' !== $r['status'] ) {
			return;
		}
		$page = max( 1, (int) $page );
		try {
			$res = Ebay_Api::instance()->trading(
				'GetMyeBaySelling',
				array(
					'ActiveList' => array(
						'Include'    => true,
						'Pagination' => array(
							'EntriesPerPage' => 200,
							'PageNumber'     => $page,
						),
					),
				)
			);
		} catch ( Api_Error $e ) {
			$r['status'] = 'error';
			$r['error']  = $e->getMessage();
			update_option( self::REPORT_OPTION, $r, false );
			Logger::error( __( 'Matching eBay listings stopped: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-link' );
			return;
		}
		foreach ( $res->ActiveList->ItemArray->Item ?? array() as $item ) {
			$r['scanned']++;
			self::consider( $item, $r );
		}
		$pages = (int) $res->ActiveList->PaginationResult->TotalNumberOfPages;
		if ( $page < $pages && $page < 100 ) {
			update_option( self::REPORT_OPTION, $r, false );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( Ebay_Scheduler::HOOK_LINK, array( $page + 1 ), Scheduler::GROUP );
			} else {
				self::run_page( $page + 1 );
			}
			return;
		}
		$r['status']      = 'done';
		$r['finished_at'] = time();
		update_option( self::REPORT_OPTION, $r, false );
		Logger::info( sprintf( __( 'Finished matching eBay listings: %1$d linked, %2$d not matched.', 'woo-etsy-sync' ), count( $r['linked'] ), count( $r['unmatched'] ) ), 'ebay-link' );
	}

	private static function consider( \SimpleXMLElement $item, array &$r ) {
		$item_id = (string) $item->ItemID;
		$title   = (string) $item->Title;
		if ( Ebay_Sync::product_for_item( $item_id ) ) {
			$r['already']++;
			return;
		}
		$skus = array();
		if ( '' !== (string) $item->SKU ) {
			$skus[] = (string) $item->SKU;
		}
		foreach ( $item->Variations->Variation ?? array() as $v ) {
			if ( '' !== (string) $v->SKU ) {
				$skus[] = (string) $v->SKU;
			}
		}
		$found = array();
		foreach ( array_unique( $skus ) as $sku ) {
			$pid = (int) wc_get_product_id_by_sku( $sku );
			if ( ! $pid && preg_match( '/^WC(\d+)$/', $sku, $m ) ) {
				$pid = (int) $m[1];
			}
			if ( $pid ) {
				$parent  = (int) wp_get_post_parent_id( $pid );
				$found[] = $parent ? $parent : $pid;
			}
		}
		$found = array_values( array_unique( $found ) );
		$pid   = 0;
		$via   = '';
		if ( 1 === count( $found ) ) {
			$pid = $found[0];
			$via = 'SKU';
		} elseif ( count( $found ) > 1 ) {
			$r['conflicts'][] = array( 'item_id' => $item_id, 'title' => $title, 'reason' => __( 'SKUs on this listing belong to several different WooCommerce products.', 'woo-etsy-sync' ) );
			return;
		}
		if ( ! $pid && ! empty( $r['match_titles'] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ebay_title' WHERE p.post_type = 'product' AND p.post_status IN ('publish','private','draft') AND ( LOWER(p.post_title) = LOWER(%s) OR LOWER(m.meta_value) = LOWER(%s) ) LIMIT 2", $title, $title ) );
			$ids = array_values( array_unique( $ids ) );
			if ( 1 === count( $ids ) ) {
				$pid = (int) $ids[0];
				$via = 'title';
			}
		}
		if ( ! $pid ) {
			if ( count( $r['unmatched'] ) < 500 ) {
				$r['unmatched'][] = array( 'item_id' => $item_id, 'title' => $title, 'sku' => implode( ', ', array_slice( $skus, 0, 3 ) ) );
			}
			return;
		}
		$existing = Ebay_Sync::item_id( $pid );
		if ( $existing && $existing !== $item_id ) {
			$r['conflicts'][] = array( 'item_id' => $item_id, 'title' => $title, 'reason' => sprintf( __( 'Matching product #%1$d is already linked to eBay item %2$s.', 'woo-etsy-sync' ), $pid, $existing ) );
			return;
		}
		Ebay_Sync::link( $pid, $item_id, 'auto-' . strtolower( $via ) );
		update_post_meta( $pid, '_wes_ebay_state', 'active' );
		$r['linked'][] = array( 'product_id' => $pid, 'product' => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ), 'item_id' => $item_id, 'title' => $title, 'via' => $via );
		Logger::success( sprintf( __( 'Linked to existing eBay listing %1$s (matched by %2$s).', 'woo-etsy-sync' ), $item_id, $via ), 'ebay-link', $pid );
	}

	/* ---------------------------------------------------------------------
	 * WP-Lister for eBay import.
	 * ------------------------------------------------------------------ */

	private static function wpl_table() {
		global $wpdb;
		$t = $wpdb->prefix . 'ebay_auctions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t ? $t : '';
	}

	public static function wplister_detect() {
		global $wpdb;
		$t = self::wpl_table();
		if ( ! $t ) {
			return null;
		}
		// phpcs:ignore WordPress.DB
		$n = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$t} WHERE ebay_id <> '' AND ebay_id IS NOT NULL AND status IN ('published','changed','prepared','verified')" );
		return array(
			'listings' => $n,
			'active'   => Ebay_Settings::wplister_active(),
			'done'     => (bool) get_option( 'wes_ebay_wpl_imported' ),
		);
	}

	public static function wplister_import() {
		global $wpdb;
		$t   = self::wpl_table();
		$out = array( 'linked' => 0, 'skipped' => 0 );
		if ( ! $t ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( "SELECT post_id, ebay_id FROM {$t} WHERE ebay_id <> '' AND ebay_id IS NOT NULL AND status IN ('published','changed','prepared','verified') ORDER BY id ASC", ARRAY_A );
		foreach ( $rows as $row ) {
			$pid = (int) $row['post_id'];
			$parent = (int) wp_get_post_parent_id( $pid );
			$pid = $parent ? $parent : $pid;
			$iid = preg_replace( '/[^0-9]/', '', (string) $row['ebay_id'] );
			if ( ! $pid || ! $iid || 'product' !== get_post_type( $pid ) || Ebay_Sync::item_id( $pid ) || Ebay_Sync::product_for_item( $iid ) ) {
				$out['skipped']++;
				continue;
			}
			Ebay_Sync::link( $pid, $iid, 'wplister-import' );
			update_post_meta( $pid, '_wes_ebay_state', 'active' );
			$out['linked']++;
		}
		update_option( 'wes_ebay_wpl_imported', time(), false );
		Logger::success( sprintf( __( 'Imported %1$d eBay listing links from WP-Lister (%2$d skipped).', 'woo-etsy-sync' ), $out['linked'], $out['skipped'] ), 'ebay-link' );
		return $out;
	}
}
