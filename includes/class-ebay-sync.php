<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * eBay listings + two-way stock.
 */
class Ebay_Sync {

	const LAST_POLL_OPTION = 'wes_ebay_last_order_poll';
	const PROCESSED_OPTION = 'wes_ebay_processed_lines';

	/** Product ids whose stock is changing because of an eBay sale (don't echo back to eBay). */
	private static $from_ebay = array();

	/* ---------------------------------------------------------------------
	 * Links.
	 * ------------------------------------------------------------------ */

	public static function item_id( $product_id ) {
		return (string) get_post_meta( $product_id, '_wes_ebay_item_id', true );
	}

	public static function item_url( $item_id ) {
		if ( ! $item_id ) {
			return '';
		}
		$site = Ebay_Settings::site();
		return 'https://' . $site[4] . '/itm/' . rawurlencode( $item_id );
	}

	public static function product_for_item( $item_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wes_ebay_item_id' AND meta_value = %s LIMIT 1", (string) $item_id ) );
		return $id ? (int) $id : 0;
	}

	public static function link( $product_id, $item_id, $source = 'manual' ) {
		$item_id = preg_replace( '/[^0-9]/', '', (string) $item_id );
		$other   = self::product_for_item( $item_id );
		if ( $other && $other !== (int) $product_id ) {
			throw new Api_Error( sprintf( __( 'eBay item %1$s is already linked to product #%2$d.', 'woo-etsy-sync' ), $item_id, $other ) );
		}
		update_post_meta( $product_id, '_wes_ebay_item_id', $item_id );
		update_post_meta( $product_id, '_wes_ebay_status', 'linked' );
		update_post_meta( $product_id, '_wes_ebay_linked_via', $source );
		delete_post_meta( $product_id, '_wes_ebay_error' );
	}

	public static function unlink( $product_id ) {
		foreach ( array( 'item_id', 'status', 'error', 'synced_at', 'state', 'linked_via', 'variations' ) as $k ) {
			delete_post_meta( $product_id, '_wes_ebay_' . $k );
		}
	}

	private static function mark_error( $product_id, $msg ) {
		update_post_meta( $product_id, '_wes_ebay_status', 'error' );
		update_post_meta( $product_id, '_wes_ebay_error', $msg );
	}

	/* ---------------------------------------------------------------------
	 * Listing.
	 * ------------------------------------------------------------------ */

	public static function preflight( \WC_Product $p ) {
		$out = array();
		if ( ! Ebay_Settings::is_connected() ) {
			$out[] = __( 'Your eBay account is not connected.', 'woo-etsy-sync' );
		}
		if ( Ebay_Mapper::is_excluded( $p->get_id() ) ) {
			$out[] = __( 'This product is set to "Don\'t sync to eBay".', 'woo-etsy-sync' );
		}
		if ( $p->is_type( array( 'grouped', 'external' ) ) || $p->is_downloadable() ) {
			$out[] = __( 'Grouped, external and downloadable products can\'t be listed on eBay.', 'woo-etsy-sync' );
		}
		if ( ! Ebay_Mapper::category_id( $p ) ) {
			$out[] = __( 'No eBay category: set a default in eBay → Settings → Listing defaults (or map this product\'s category).', 'woo-etsy-sync' );
		}
		if ( ! self::item_id( $p->get_id() ) ) {
			if ( ! Ebay_Settings::get( 'shipping_policy' ) || ! Ebay_Settings::get( 'return_policy' ) || ! Ebay_Settings::get( 'payment_policy' ) ) {
				$out[] = __( 'Choose your eBay postage, returns and payment policies in eBay → Settings → Policies & location.', 'woo-etsy-sync' );
			}
			if ( ! Ebay_Settings::get( 'postcode' ) && ! Ebay_Settings::get( 'location' ) ) {
				$out[] = __( 'Add the postcode or town you post from in eBay → Settings → Policies & location.', 'woo-etsy-sync' );
			}
		}
		if ( ! $p->is_type( 'variable' ) && '' === $p->get_price() && '' === $p->get_regular_price() ) {
			$out[] = __( 'The product has no price.', 'woo-etsy-sync' );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) && ! apply_filters( 'wes_ebay_allow_http_images', false ) ) {
			$out[] = __( 'eBay needs photos on an https:// address. Switch your site to HTTPS.', 'woo-etsy-sync' );
		}
		return $out;
	}

	/**
	 * Check required item specifics for the category (best effort, cached).
	 */
	public static function missing_specifics( \WC_Product $p, array $item ) {
		try {
			$aspects = Ebay_Api::instance()->aspects( Ebay_Mapper::category_id( $p ) );
		} catch ( \Exception $e ) {
			return array();
		}
		$have = array();
		foreach ( $item['ItemSpecifics']['NameValueList'] ?? array() as $nv ) {
			$have[] = strtolower( $nv['Name'] );
		}
		foreach ( $item['Variations']['VariationSpecificsSet']['NameValueList'] ?? array() as $nv ) {
			$have[] = strtolower( $nv['Name'] );
		}
		$missing = array();
		foreach ( $aspects as $a ) {
			if ( $a['required'] && ! in_array( strtolower( $a['name'] ), $have, true ) ) {
				$missing[] = $a['name'] . ( $a['values'] ? ' (e.g. ' . implode( ', ', array_slice( $a['values'], 0, 3 ) ) . ')' : '' );
			}
		}
		return $missing;
	}

	private static function total_qty( array $item ) {
		if ( isset( $item['Variations']['Variation'] ) ) {
			return array_sum( wp_list_pluck( $item['Variations']['Variation'], 'Quantity' ) );
		}
		return (int) ( $item['Quantity'] ?? 1 );
	}

	public static function sync( $product_id, $allow_recreate = true ) {
		$p = wc_get_product( $product_id );
		if ( $p && $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		if ( ! $p ) {
			throw new Api_Error( __( 'Product not found.', 'woo-etsy-sync' ) );
		}
		$product_id = $p->get_id();
		try {
			$problems = self::preflight( $p );
			if ( $problems ) {
				throw new Api_Error( implode( ' ', $problems ) );
			}
			$api     = Ebay_Api::instance();
			$item_id = self::item_id( $product_id );
			$created = false;
			if ( ! $item_id ) {
				$built = Ebay_Mapper::item( $p );
				if ( self::total_qty( $built['item'] ) <= 0 ) {
					throw new Api_Error( __( 'Out of stock, so it was not listed on eBay. Sync again once it has stock.', 'woo-etsy-sync' ) );
				}
				$res     = $api->trading( 'AddFixedPriceItem', array( 'Item' => $built['item'] ) );
				$item_id = (string) $res->ItemID;
				if ( ! $item_id ) {
					throw new Api_Error( __( 'eBay did not return an item number.', 'woo-etsy-sync' ) );
				}
				update_post_meta( $product_id, '_wes_ebay_item_id', $item_id );
				$created = true;
			} else {
				$built = Ebay_Mapper::item( $p, $item_id, true );
				try {
					$res = $api->trading( 'ReviseFixedPriceItem', array( 'Item' => $built['item'] ) );
				} catch ( Api_Error $e ) {
					$ended = preg_match( '/\b(ended|not found|invalid item ?id)\b|\((291|17|1047)\)/i', $e->getMessage() );
					if ( $ended && $allow_recreate ) {
						Logger::warning( __( 'The linked eBay listing has ended or no longer exists — creating a new one.', 'woo-etsy-sync' ), 'ebay-sync', $product_id );
						self::unlink( $product_id );
						return self::sync( $product_id, false );
					}
					throw $e;
				}
			}
			if ( $built['specs_by_sku'] ) {
				$prev = (array) get_post_meta( $product_id, '_wes_ebay_variations', true );
				update_post_meta( $product_id, '_wes_ebay_variations', array_merge( $prev, $built['specs_by_sku'] ) );
			}
			$warn = trim( (string) $res->WesWarnings );
			if ( $warn ) {
				Logger::warning( __( 'eBay accepted the listing with a note: ', 'woo-etsy-sync' ) . $warn, 'ebay-sync', $product_id, 0 );
			}
			update_post_meta( $product_id, '_wes_ebay_status', 'synced' );
			update_post_meta( $product_id, '_wes_ebay_state', 'active' );
			update_post_meta( $product_id, '_wes_ebay_synced_at', time() );
			delete_post_meta( $product_id, '_wes_ebay_error' );
			Logger::success( ( $created ? __( 'Listed on eBay', 'woo-etsy-sync' ) : __( 'Updated eBay listing', 'woo-etsy-sync' ) ) . ' (item ' . $item_id . ').', 'ebay-sync', $product_id );
			do_action( 'wes_ebay_product_synced', $product_id, $item_id, $created );
			return array(
				'item_id' => $item_id,
				'created' => $created,
				'url'     => self::item_url( $item_id ),
			);
		} catch ( \Exception $e ) {
			self::mark_error( $product_id, $e->getMessage() );
			Logger::error( $e->getMessage(), 'ebay-sync', $product_id );
			throw ( $e instanceof Api_Error ? $e : new Api_Error( $e->getMessage() ) );
		}
	}

	/**
	 * Ask eBay to validate the listing without creating it (free), plus a local preview.
	 */
	public static function preview( $product_id, $ask_ebay = false ) {
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			throw new Api_Error( __( 'Product not found.', 'woo-etsy-sync' ) );
		}
		$out = array(
			'problems' => self::preflight( $p ),
			'item_id'  => self::item_id( $product_id ),
		);
		try {
			$built        = Ebay_Mapper::item( $p );
			$item         = $built['item'];
			$out['item']  = array(
				'title'       => $item['Title'] ?? '',
				'category'    => $item['PrimaryCategory']['CategoryID'] ?? 0,
				'condition'   => $item['ConditionID'] ?? 0,
				'price'       => $item['StartPrice'] ?? null,
				'quantity'    => $item['Quantity'] ?? null,
				'images'      => count( $item['PictureDetails']['PictureURL'] ?? array() ),
				'specifics'   => $item['ItemSpecifics']['NameValueList'] ?? array(),
				'variations'  => array_map(
					function ( $v ) {
						return array(
							'sku'      => $v['SKU'],
							'price'    => $v['StartPrice'],
							'quantity' => $v['Quantity'],
							'options'  => implode( ' / ', wp_list_pluck( $v['VariationSpecifics']['NameValueList'], 'Value' ) ),
						);
					},
					$item['Variations']['Variation'] ?? array()
				),
				'description' => wp_strip_all_tags( str_replace( array( '</p>', '<li>' ), array( "\n\n", "\n• " ), $item['Description'] ?? '' ) ),
			);
			$missing = Ebay_Settings::is_connected() ? self::missing_specifics( $p, $item ) : array();
			if ( $missing ) {
				$out['problems'][] = __( 'eBay requires these item specifics for this category: ', 'woo-etsy-sync' ) . implode( '; ', $missing ) . __( '. Add them under the product\'s eBay tab or in eBay → Settings → Item specifics.', 'woo-etsy-sync' );
			}
			if ( $ask_ebay && ! $out['problems'] ) {
				$res = Ebay_Api::instance()->trading( 'VerifyAddFixedPriceItem', array( 'Item' => $item ) );
				$fees = 0.0;
				foreach ( $res->Fees->Fee ?? array() as $fee ) {
					$fees += (float) $fee->Fee;
				}
				$out['ebay_ok']   = true;
				$out['ebay_note'] = trim( (string) $res->WesWarnings );
				$out['fees']      = round( $fees, 2 );
			}
		} catch ( Api_Error $e ) {
			$out['problems'][] = $e->getMessage();
		}
		return $out;
	}

	public static function end( $product_id ) {
		$item_id = self::item_id( $product_id );
		if ( ! $item_id ) {
			return;
		}
		Ebay_Api::instance()->trading(
			'EndFixedPriceItem',
			array(
				'ItemID'       => $item_id,
				'EndingReason' => 'NotAvailable',
			)
		);
		update_post_meta( $product_id, '_wes_ebay_state', 'ended' );
		Logger::info( __( 'eBay listing ended.', 'woo-etsy-sync' ), 'ebay-sync', $product_id );
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce → eBay stock.
	 * ------------------------------------------------------------------ */

	public static function on_stock_change( $product ) {
		if ( ! $product instanceof \WC_Product || ! Ebay_Settings::get( 'sync_stock_to_ebay' ) || ! Ebay_Settings::is_connected() ) {
			return;
		}
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( isset( self::$from_ebay[ $parent ] ) || ! self::item_id( $parent ) || Ebay_Mapper::is_excluded( $parent ) ) {
			return;
		}
		Scheduler::enqueue( Ebay_Scheduler::HOOK_STOCK, array( (int) $parent ) );
	}

	/**
	 * Push WooCommerce quantities to the linked eBay listing (prices left untouched).
	 * Works for listings made by this plugin and by other tools, matching variations by SKU.
	 */
	public static function push_stock( $product_id, $log = true ) {
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return 0;
		}
		$item_id = self::item_id( $product_id );
		if ( ! $item_id ) {
			return 0;
		}
		$api  = Ebay_Api::instance();
		$res  = $api->trading( 'GetItem', array( 'ItemID' => $item_id, 'DetailLevel' => 'ReturnAll' ) );
		$item = $res->Item;
		$state = strtolower( (string) $item->SellingStatus->ListingStatus );
		update_post_meta( $product_id, '_wes_ebay_state', 'active' === $state ? 'active' : $state );
		if ( 'active' !== $state ) {
			if ( $log ) {
				Logger::warning( __( 'The eBay listing is not active (', 'woo-etsy-sync' ) . $state . __( '), so stock was not sent.', 'woo-etsy-sync' ), 'ebay-stock', $product_id );
			}
			return 0;
		}

		$changes   = array();
		$unmatched = 0;
		if ( isset( $item->Variations->Variation ) && count( $item->Variations->Variation ) ) {
			$index = Stock_Sync::index( $p );
			foreach ( $item->Variations->Variation as $v ) {
				$sku    = (string) $v->SKU;
				$values = array();
				foreach ( $v->VariationSpecifics->NameValueList ?? array() as $nv ) {
					$values[] = (string) $nv->Value;
				}
				$match = self::match_variation( $index, $p, $sku, $values );
				if ( ! $match || '' === $sku ) {
					$unmatched++;
					continue;
				}
				$avail = (int) $v->Quantity - (int) $v->SellingStatus->QuantitySold;
				$want  = Ebay_Mapper::quantity( $match );
				if ( $want !== $avail ) {
					$changes[] = array( 'ItemID' => $item_id, 'SKU' => $sku, 'Quantity' => $want );
				}
			}
		} else {
			$avail = (int) $item->Quantity - (int) $item->SellingStatus->QuantitySold;
			$want  = Ebay_Mapper::quantity( $p );
			if ( $want !== $avail ) {
				$changes[] = array( 'ItemID' => $item_id, 'Quantity' => $want );
			}
		}
		foreach ( array_chunk( $changes, 4 ) as $chunk ) {
			$api->trading( 'ReviseInventoryStatus', array( 'InventoryStatus' => $chunk ) );
		}
		if ( $log && $changes ) {
			Logger::info( sprintf( __( 'Stock sent to eBay (%1$d change%2$s).', 'woo-etsy-sync' ), count( $changes ), 1 === count( $changes ) ? '' : 's' ), 'ebay-stock', $product_id );
		}
		if ( $log && $unmatched ) {
			Logger::warning( sprintf( __( '%d eBay variation(s) could not be matched to WooCommerce (they need matching SKUs), so their stock was left alone.', 'woo-etsy-sync' ), $unmatched ), 'ebay-stock', $product_id );
		}
		update_post_meta( $product_id, '_wes_ebay_stock_pushed_at', time() );
		return count( $changes );
	}

	private static function match_variation( array $index, \WC_Product $p, $sku, array $values ) {
		if ( $sku ) {
			if ( isset( $index['sku'][ $sku ] ) ) {
				return $index['sku'][ $sku ];
			}
			if ( preg_match( '/^WC(\d+)$/', $sku, $m ) ) {
				$v = wc_get_product( (int) $m[1] );
				if ( $v && $v->get_parent_id() === $p->get_id() ) {
					return $v;
				}
			}
		}
		return Stock_Sync::match( $index, '', $values );
	}

	/* ---------------------------------------------------------------------
	 * eBay → WooCommerce stock (from orders).
	 * ------------------------------------------------------------------ */

	public static function poll_orders() {
		$sum = array( 'orders' => 0, 'lines' => 0, 'adjusted' => 0, 'skipped' => 0 );
		if ( ! Ebay_Settings::get( 'sync_stock_from_ebay' ) || ! Ebay_Settings::is_connected() ) {
			return $sum;
		}
		if ( Ebay_Settings::wplister_active() ) {
			update_option( 'wes_ebay_last_poll_summary', array_merge( $sum, array( 'at' => time(), 'blocked' => 'wplister' ) ), false );
			return $sum;
		}
		$now  = time();
		$last = (int) get_option( self::LAST_POLL_OPTION, 0 );
		if ( ! $last ) {
			update_option( self::LAST_POLL_OPTION, $now, false );
			return $sum;
		}
		$since     = gmdate( 'Y-m-d\TH:i:s.000\Z', $last - 15 * MINUTE_IN_SECONDS );
		$processed = get_option( self::PROCESSED_OPTION, array() );
		$processed = is_array( $processed ) ? $processed : array();
		$offset    = 0;
		do {
			$res    = Ebay_Api::instance()->rest(
				'GET',
				'/sell/fulfillment/v1/order',
				array(
					'filter' => 'creationdate:[' . $since . '..]',
					'limit'  => 200,
					'offset' => $offset,
				)
			);
			$orders = $res['orders'] ?? array();
			foreach ( $orders as $o ) {
				$sum['orders']++;
				if ( 'CANCELED' === ( $o['cancelStatus']['cancelState'] ?? '' ) ) {
					continue;
				}
				foreach ( $o['lineItems'] ?? array() as $li ) {
					$key = (string) ( $li['lineItemId'] ?? '' );
					if ( '' === $key || isset( $processed[ $key ] ) ) {
						continue;
					}
					$processed[ $key ] = $now;
					$sum['lines']++;
					if ( self::apply_line( $li, (string) $o['orderId'] ) ) {
						$sum['adjusted']++;
					} else {
						$sum['skipped']++;
					}
				}
			}
			$offset += count( $orders );
		} while ( count( $orders ) === 200 && $offset < 2000 );

		foreach ( $processed as $k => $t ) {
			if ( $t < $now - 30 * DAY_IN_SECONDS ) {
				unset( $processed[ $k ] );
			}
		}
		update_option( self::PROCESSED_OPTION, $processed, false );
		update_option( self::LAST_POLL_OPTION, $now, false );
		update_option( 'wes_ebay_last_poll_summary', array_merge( $sum, array( 'at' => $now ) ), false );
		return $sum;
	}

	public static function apply_line( array $li, $order_id ) {
		$item_id = (string) ( $li['legacyItemId'] ?? '' );
		$qty     = (int) ( $li['quantity'] ?? 0 );
		$pid     = self::product_for_item( $item_id );
		if ( ! $pid || $qty <= 0 ) {
			return false;
		}
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return false;
		}
		$sku    = (string) ( $li['sku'] ?? '' );
		$values = array();
		foreach ( $li['variationAspects'] ?? array() as $va ) {
			$values[] = (string) ( $va['value'] ?? '' );
		}
		$target = $p->is_type( 'variable' ) ? self::match_variation( Stock_Sync::index( $p ), $p, $sku, $values ) : $p;
		if ( ! $target ) {
			Logger::warning( sprintf( __( 'eBay order %1$s: could not tell which variation was sold (SKU "%2$s"), so stock was not changed.', 'woo-etsy-sync' ), $order_id, $sku ), 'ebay-orders', $pid );
			return false;
		}
		if ( ! $target->managing_stock() ) {
			Logger::info( sprintf( __( 'eBay order %1$s: sold %2$d × "%3$s" (stock isn\'t tracked in WooCommerce).', 'woo-etsy-sync' ), $order_id, $qty, $target->get_name() ), 'ebay-orders', $pid );
			return false;
		}
		self::$from_ebay[ $pid ] = true;
		$new = wc_update_product_stock( $target, $qty, 'decrease' );
		unset( self::$from_ebay[ $pid ] );
		Logger::success( sprintf( __( 'eBay order %1$s: sold %2$d × "%3$s" — WooCommerce stock is now %4$s.', 'woo-etsy-sync' ), $order_id, $qty, $target->get_name(), is_numeric( $new ) ? (int) $new : '?' ), 'ebay-orders', $pid );
		do_action( 'wes_ebay_sale_applied', $target, $qty, $li, $order_id );
		return true;
	}
}
