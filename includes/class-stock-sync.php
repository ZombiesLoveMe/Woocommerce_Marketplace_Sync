<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Two-way stock syncing.
 *
 *  WooCommerce → Etsy: whenever stock changes, the listing's quantities are updated
 *                      (Etsy's own prices/structure are preserved).
 *  Etsy → WooCommerce: new Etsy orders are polled and the sold quantities are
 *                      deducted from WooCommerce stock.
 */
class Stock_Sync {

	const PROCESSED_OPTION = 'wes_processed_transactions';
	const LAST_POLL_OPTION = 'wes_last_receipt_poll';

	/** Product ids whose stock we're changing because of an Etsy sale (avoid needless echo). */
	private static $from_etsy = array();

	/* ---------------------------------------------------------------------
	 * Matching Etsy inventory rows to WooCommerce products/variations.
	 * ------------------------------------------------------------------ */

	/**
	 * Index a product's sellable units by SKU and by option values.
	 *
	 * @return array{sku: array, values: array, simple: \WC_Product|null}
	 */
	public static function index( \WC_Product $product ) {
		$index = array(
			'sku'    => array(),
			'values' => array(),
			'simple' => null,
		);
		if ( ! $product->is_type( 'variable' ) ) {
			$index['simple'] = $product;
			if ( $product->get_sku() ) {
				$index['sku'][ $product->get_sku() ] = $product;
			}
			return $index;
		}
		$attrs    = Mapper::variation_attributes( $product );
		$sku_seen = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			if ( $v->get_sku() ) {
				$sku_seen[ $v->get_sku() ] = isset( $sku_seen[ $v->get_sku() ] ) ? false : $v;
			}
			$combos = array( array() );
			$raw    = $v->get_attributes();
			foreach ( $attrs as $k => $attr ) {
				$slug    = $raw[ str_replace( 'attribute_', '', $k ) ] ?? '';
				$choices = '' === $slug ? array_values( $attr['options'] ) : array( Mapper::option_name( $attr, $slug ) );
				$next    = array();
				foreach ( $combos as $c ) {
					foreach ( $choices as $ch ) {
						$c2     = $c;
						$c2[]   = Mapper::norm( $ch );
						$next[] = $c2;
					}
				}
				$combos = $next;
			}
			foreach ( $combos as $c ) {
				sort( $c );
				$key = implode( '|', $c );
				if ( ! isset( $index['values'][ $key ] ) ) {
					$index['values'][ $key ] = $v;
				}
			}
		}
		$index['sku'] = array_filter( $sku_seen );
		return $index;
	}

	/**
	 * Find the WooCommerce product/variation for an Etsy sku + list of option values.
	 */
	public static function match( array $index, $sku, array $values ) {
		if ( $index['simple'] ) {
			return $index['simple'];
		}
		if ( $sku && isset( $index['sku'][ $sku ] ) ) {
			return $index['sku'][ $sku ];
		}
		$norm = array_map( array( Mapper::class, 'norm' ), $values );
		sort( $norm );
		$key = implode( '|', $norm );
		return $index['values'][ $key ] ?? null;
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce → Etsy.
	 * ------------------------------------------------------------------ */

	/**
	 * Push current WooCommerce stock to the linked Etsy listing.
	 */
	public static function push( $product_id, $log = true ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product ) {
			return;
		}
		$product_id = $product->get_id();
		$listing_id = Product_Sync::listing_id( $product_id );
		if ( ! $listing_id || Mapper::is_excluded( $product_id ) ) {
			return;
		}

		$api       = Etsy_Api::instance();
		$current   = $api->get_inventory( $listing_id );
		$index     = self::index( $product );
		$readiness = Mapper::readiness_state_id( $product );
		$total     = 0;
		$changed   = false;
		$unmatched = 0;
		$products  = array();

		foreach ( $current['products'] ?? array() as $row ) {
			if ( ! empty( $row['is_deleted'] ) ) {
				continue;
			}
			$values = array();
			$pvs    = array();
			foreach ( $row['property_values'] ?? array() as $pv ) {
				$values[] = $pv['values'][0] ?? '';
				$clean    = array(
					'property_id' => (int) $pv['property_id'],
					'value_ids'   => array_map( 'intval', $pv['value_ids'] ?? array() ),
					'values'      => array_values( $pv['values'] ?? array() ),
				);
				if ( ! empty( $pv['property_name'] ) ) {
					$clean['property_name'] = $pv['property_name'];
				}
				if ( ! empty( $pv['scale_id'] ) ) {
					$clean['scale_id'] = (int) $pv['scale_id'];
				}
				$pvs[] = $clean;
			}
			$match     = self::match( $index, (string) ( $row['sku'] ?? '' ), $values );
			$offerings = array();
			foreach ( $row['offerings'] ?? array() as $o ) {
				if ( ! empty( $o['is_deleted'] ) ) {
					continue;
				}
				$price   = isset( $o['price']['amount'] ) ? $o['price']['amount'] / max( 1, (int) $o['price']['divisor'] ) : (float) $o['price'];
				$qty     = (int) $o['quantity'];
				$enabled = ! empty( $o['is_enabled'] );
				if ( $match ) {
					$new_qty = Mapper::quantity( $match );
					if ( $new_qty !== $qty || ( $enabled && $new_qty <= 0 ) || ( ! $enabled && $new_qty > 0 && 'publish' === $match->get_status() ) ) {
						$changed = true;
					}
					$qty     = $new_qty;
					$enabled = $qty > 0 && ( $match->is_type( 'variation' ) ? 'publish' === $match->get_status() : true );
				} else {
					$unmatched++;
				}
				if ( $enabled ) {
					$total += $qty;
				}
				$offerings[] = array(
					'price'              => round( (float) $price, 2 ),
					'quantity'           => $enabled ? max( 1, $qty ) : max( 0, $qty ),
					'is_enabled'         => $enabled,
					'readiness_state_id' => ! empty( $o['readiness_state_id'] ) ? (int) $o['readiness_state_id'] : ( $readiness ? $readiness : null ),
				);
			}
			$products[] = array(
				'sku'             => (string) ( $row['sku'] ?? '' ),
				'property_values' => $pvs,
				'offerings'       => $offerings,
			);
		}

		if ( $unmatched && $log ) {
			Logger::warning( sprintf( __( '%d Etsy variation(s) could not be matched to WooCommerce variations, so their stock was left alone. Matching uses SKUs first, then option names.', 'woo-etsy-sync' ), $unmatched ), 'stock', $product_id, $listing_id );
		}

		$state = get_post_meta( $product_id, '_wes_listing_state', true );
		if ( $total > 0 && $changed ) {
			$api->update_inventory(
				$listing_id,
				array(
					'products'                    => $products,
					'price_on_property'           => array_map( 'intval', $current['price_on_property'] ?? array() ),
					'quantity_on_property'        => array_map( 'intval', $current['quantity_on_property'] ?? array() ),
					'sku_on_property'             => array_map( 'intval', $current['sku_on_property'] ?? array() ),
					'readiness_state_on_property' => array_map( 'intval', $current['readiness_state_on_property'] ?? array() ),
				)
			);
			if ( $log ) {
				Logger::info( sprintf( __( 'Stock sent to Etsy (%d available).', 'woo-etsy-sync' ), $total ), 'stock', $product_id, $listing_id );
			}
		}
		$state = self::apply_stock_state( $product_id, $listing_id, $total, $state );
		update_post_meta( $product_id, '_wes_listing_state', $state );
		update_post_meta( $product_id, '_wes_stock_pushed_at', time() );
	}

	/**
	 * Deactivate a listing when it runs out, reactivate it when it's back.
	 *
	 * @return string The listing state afterwards.
	 */
	public static function apply_stock_state( $product_id, $listing_id, $total, $state ) {
		$api = Etsy_Api::instance();
		if ( ! $state ) {
			$listing = $api->get_listing( $listing_id );
			$state   = $listing['state'] ?? '';
		}
		try {
			if ( $total <= 0 && Settings::get( 'deactivate_out_of_stock' ) && 'active' === $state ) {
				$res   = $api->update_listing( Settings::shop_id(), $listing_id, array( 'state' => 'inactive' ) );
				$state = $res['state'] ?? 'inactive';
				update_post_meta( $product_id, '_wes_auto_off', 'yes' );
				Logger::info( __( 'Out of stock — Etsy listing deactivated. It will be reactivated automatically when stock returns.', 'woo-etsy-sync' ), 'stock', $product_id, $listing_id );
			} elseif ( $total > 0 && 'yes' === get_post_meta( $product_id, '_wes_auto_off', true ) && in_array( $state, array( 'inactive', 'sold_out' ), true ) ) {
				$res   = $api->update_listing( Settings::shop_id(), $listing_id, array( 'state' => 'active' ) );
				$state = $res['state'] ?? 'active';
				delete_post_meta( $product_id, '_wes_auto_off' );
				Logger::info( __( 'Back in stock — Etsy listing reactivated.', 'woo-etsy-sync' ), 'stock', $product_id, $listing_id );
			}
		} catch ( Api_Error $e ) {
			Logger::warning( __( 'Could not change the Etsy listing state: ', 'woo-etsy-sync' ) . $e->getMessage(), 'stock', $product_id, $listing_id );
		}
		return (string) $state;
	}

	/**
	 * Hooked to WooCommerce stock changes; queues a push for the parent product.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function on_stock_change( $product ) {
		if ( ! Settings::get( 'sync_stock_to_etsy' ) || ! Settings::is_connected() || ! $product instanceof \WC_Product ) {
			return;
		}
		$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( isset( self::$from_etsy[ $parent_id ] ) || ! Product_Sync::listing_id( $parent_id ) ) {
			// Etsy already knows about its own sales, so no need to echo them back.
			return;
		}
		Scheduler::queue_stock_push( $parent_id );
	}

	/* ---------------------------------------------------------------------
	 * Etsy → WooCommerce.
	 * ------------------------------------------------------------------ */

	/**
	 * Poll new Etsy receipts and deduct sold quantities from WooCommerce stock.
	 *
	 * @return array Summary counts.
	 */
	public static function poll_orders() {
		$summary = array(
			'receipts'     => 0,
			'transactions' => 0,
			'adjusted'     => 0,
			'skipped'      => 0,
		);
		if ( ! Settings::get( 'sync_stock_from_etsy' ) || ! Settings::is_connected() ) {
			return $summary;
		}

		$now   = time();
		$last  = (int) get_option( self::LAST_POLL_OPTION, 0 );
		if ( ! $last ) {
			// First run: start from now so historical orders are never deducted.
			update_option( self::LAST_POLL_OPTION, $now, false );
			return $summary;
		}
		$since     = $last - 10 * MINUTE_IN_SECONDS; // Overlap; duplicates are skipped below.
		$processed = get_option( self::PROCESSED_OPTION, array() );
		$processed = is_array( $processed ) ? $processed : array();
		$api       = Etsy_Api::instance();
		$offset    = 0;

		do {
			$res = $api->get_receipts(
				Settings::shop_id(),
				array(
					'min_created' => $since,
					'limit'       => 100,
					'offset'      => $offset,
					'sort_on'     => 'created',
					'sort_order'  => 'asc',
				)
			);
			$receipts = $res['results'] ?? array();
			foreach ( $receipts as $receipt ) {
				$summary['receipts']++;
				if ( ! empty( $receipt['is_canceled'] ) || ( isset( $receipt['status'] ) && 'canceled' === strtolower( $receipt['status'] ) ) ) {
					continue;
				}
				foreach ( $receipt['transactions'] ?? array() as $tx ) {
					$tid = (string) $tx['transaction_id'];
					if ( isset( $processed[ $tid ] ) ) {
						continue;
					}
					$summary['transactions']++;
					$processed[ $tid ] = $now;
					if ( self::apply_transaction( $tx, $receipt ) ) {
						$summary['adjusted']++;
					} else {
						$summary['skipped']++;
					}
				}
			}
			$offset += count( $receipts );
		} while ( count( $receipts ) === 100 && $offset < 2000 );

		// Keep the processed list bounded (14 days).
		$cutoff = $now - 14 * DAY_IN_SECONDS;
		foreach ( $processed as $tid => $ts ) {
			if ( $ts < $cutoff ) {
				unset( $processed[ $tid ] );
			}
		}
		update_option( self::PROCESSED_OPTION, $processed, false );
		update_option( self::LAST_POLL_OPTION, $now, false );
		update_option( 'wes_last_poll_summary', array_merge( $summary, array( 'at' => $now ) ), false );
		return $summary;
	}

	/**
	 * Deduct one Etsy sale from WooCommerce stock.
	 */
	public static function apply_transaction( array $tx, array $receipt ) {
		$listing_id = (int) ( $tx['listing_id'] ?? 0 );
		$product_id = Product_Sync::product_for_listing( $listing_id );
		$qty        = (int) ( $tx['quantity'] ?? 0 );
		$order_ref  = '#' . ( $receipt['receipt_id'] ?? '?' );
		if ( ! $product_id || $qty <= 0 ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		$values = array();
		foreach ( $tx['variations'] ?? array() as $var ) {
			$values[] = $var['formatted_value'] ?? '';
		}
		$target = self::match( self::index( $product ), (string) ( $tx['sku'] ?? '' ), $values );
		if ( ! $target ) {
			Logger::warning( sprintf( __( 'Etsy order %1$s: could not tell which variation was sold (%2$s), so stock was not changed.', 'woo-etsy-sync' ), $order_ref, implode( ', ', $values ) ), 'orders', $product_id, $listing_id );
			return false;
		}
		// Variations that don't manage stock themselves inherit from the parent.
		$stock_holder = $target;
		if ( $target->is_type( 'variation' ) && 'parent' === $target->get_manage_stock() ) {
			$stock_holder = $product;
		}
		if ( ! $stock_holder->managing_stock() ) {
			Logger::info( sprintf( __( 'Etsy order %1$s: sold %2$d × "%3$s" (stock isn\'t tracked in WooCommerce, nothing to deduct).', 'woo-etsy-sync' ), $order_ref, $qty, $target->get_name() ), 'orders', $product_id, $listing_id );
			return false;
		}
		self::$from_etsy[ $product_id ] = true;
		$new = wc_update_product_stock( $stock_holder, $qty, 'decrease' );
		unset( self::$from_etsy[ $product_id ] );
		Logger::success( sprintf( __( 'Etsy order %1$s: sold %2$d × "%3$s" — WooCommerce stock is now %4$s.', 'woo-etsy-sync' ), $order_ref, $qty, $target->get_name(), is_numeric( $new ) ? (int) $new : '?' ), 'orders', $product_id, $listing_id );
		do_action( 'wes_etsy_sale_applied', $target, $qty, $tx, $receipt );
		return true;
	}
}
