<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Links listings that already exist on Etsy to WooCommerce products (by SKU, then exact title).
 * Runs page-by-page in the background. Nothing is changed on Etsy.
 */
class Linker {

	const REPORT_OPTION = 'wes_link_report';
	const STATES        = array( 'active', 'inactive', 'draft', 'sold_out', 'expired' );
	const PAGE_SIZE     = 100;

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
			as_unschedule_all_actions( Scheduler::HOOK_LINK );
			as_enqueue_async_action( Scheduler::HOOK_LINK, array( 0, 0 ), Scheduler::GROUP );
		} else {
			self::run_page( 0, 0 );
		}
	}

	private static function save( array $report ) {
		update_option( self::REPORT_OPTION, $report, false );
	}

	/**
	 * Process one page of listings, then queue the next.
	 */
	public static function run_page( $state_index, $offset ) {
		$report = self::report();
		if ( ! $report || 'running' !== $report['status'] ) {
			return;
		}
		$state_index = (int) $state_index;
		$offset      = (int) $offset;
		if ( $state_index >= count( self::STATES ) ) {
			$report['status']      = 'done';
			$report['finished_at'] = time();
			self::save( $report );
			Logger::info( sprintf( __( 'Finished matching Etsy listings: %1$d linked, %2$d not matched.', 'woo-etsy-sync' ), count( $report['linked'] ), count( $report['unmatched'] ) ), 'link' );
			return;
		}

		$state = self::STATES[ $state_index ];
		try {
			$res = Etsy_Api::instance()->get_shop_listings( Settings::shop_id(), $state, self::PAGE_SIZE, $offset );
		} catch ( Api_Error $e ) {
			// Some states (e.g. expired) may be unavailable for some shops; skip them.
			if ( in_array( $e->status(), array( 400, 403, 404 ), true ) ) {
				$res = array( 'results' => array() );
			} else {
				$report['status'] = 'error';
				$report['error']  = $e->getMessage();
				self::save( $report );
				Logger::error( __( 'Matching Etsy listings stopped: ', 'woo-etsy-sync' ) . $e->getMessage(), 'link' );
				return;
			}
		}
		$listings = $res['results'] ?? array();
		foreach ( $listings as $listing ) {
			$report['scanned']++;
			self::consider( $listing, $report );
		}
		self::save( $report );

		if ( count( $listings ) >= self::PAGE_SIZE ) {
			$next = array( $state_index, $offset + self::PAGE_SIZE );
		} else {
			$next = array( $state_index + 1, 0 );
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( Scheduler::HOOK_LINK, $next, Scheduler::GROUP );
		} else {
			self::run_page( $next[0], $next[1] );
		}
	}

	private static function consider( array $listing, array &$report ) {
		$listing_id = (int) $listing['listing_id'];
		$title      = html_entity_decode( (string) ( $listing['title'] ?? '' ), ENT_QUOTES, 'UTF-8' );

		if ( Product_Sync::product_for_listing( $listing_id ) ) {
			$report['already']++;
			return;
		}

		$product_id = 0;
		$via        = '';
		$skus       = array_filter( array_map( 'trim', (array) ( $listing['skus'] ?? array() ) ) );
		if ( $skus ) {
			$found = array();
			foreach ( $skus as $sku ) {
				$pid = (int) wc_get_product_id_by_sku( $sku );
				if ( $pid ) {
					$parent        = (int) wp_get_post_parent_id( $pid );
					$found[]       = $parent ? $parent : $pid;
				}
			}
			$found = array_values( array_unique( $found ) );
			if ( 1 === count( $found ) ) {
				$product_id = $found[0];
				$via        = 'SKU';
			} elseif ( count( $found ) > 1 ) {
				$report['conflicts'][] = array(
					'listing_id' => $listing_id,
					'title'      => $title,
					'reason'     => __( 'SKUs on this listing belong to several different WooCommerce products.', 'woo-etsy-sync' ),
				);
				return;
			}
		}

		if ( ! $product_id && ! empty( $report['match_titles'] ) && '' !== $title ) {
			$product_id = self::find_by_title( $title );
			$via        = $product_id ? 'title' : '';
		}

		if ( ! $product_id ) {
			if ( count( $report['unmatched'] ) < 500 ) {
				$report['unmatched'][] = array(
					'listing_id' => $listing_id,
					'title'      => $title,
					'state'      => $listing['state'] ?? '',
					'sku'        => implode( ', ', $skus ),
				);
			}
			return;
		}

		$existing = Product_Sync::listing_id( $product_id );
		if ( $existing && $existing !== $listing_id ) {
			$report['conflicts'][] = array(
				'listing_id' => $listing_id,
				'title'      => $title,
				'reason'     => sprintf( __( 'Matching product #%1$d is already linked to listing %2$d.', 'woo-etsy-sync' ), $product_id, $existing ),
			);
			return;
		}

		Product_Sync::link( $product_id, $listing_id, 'auto-' . strtolower( $via ) );
		update_post_meta( $product_id, '_wes_listing_state', $listing['state'] ?? '' );
		$report['linked'][] = array(
			'product_id' => $product_id,
			'listing_id' => $listing_id,
			'title'      => $title,
			'product'    => html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, 'UTF-8' ),
			'via'        => $via,
		);
		Logger::success( sprintf( __( 'Linked to existing Etsy listing (matched by %s).', 'woo-etsy-sync' ), $via ), 'link', $product_id, $listing_id );
	}

	private static function find_by_title( $title ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','private','draft','pending') AND LOWER(post_title) = LOWER(%s) LIMIT 2",
				$title
			)
		);
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}
}
