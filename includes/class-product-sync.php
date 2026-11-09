<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates Etsy listings from WooCommerce products.
 */
class Product_Sync {

	/* ---------------------------------------------------------------------
	 * Link helpers (post meta).
	 * ------------------------------------------------------------------ */

	public static function listing_id( $product_id ) {
		return (int) get_post_meta( $product_id, '_wes_listing_id', true );
	}

	public static function listing_url( $listing_id ) {
		return $listing_id ? 'https://www.etsy.com/listing/' . (int) $listing_id : '';
	}

	public static function link( $product_id, $listing_id, $source = 'manual' ) {
		$other = self::product_for_listing( $listing_id );
		if ( $other && $other !== (int) $product_id ) {
			throw new Api_Error( sprintf( __( 'Etsy listing %1$d is already linked to product #%2$d.', 'woo-etsy-sync' ), $listing_id, $other ) );
		}
		update_post_meta( $product_id, '_wes_listing_id', (int) $listing_id );
		update_post_meta( $product_id, '_wes_status', 'linked' );
		update_post_meta( $product_id, '_wes_linked_via', $source );
		delete_post_meta( $product_id, '_wes_error' );
		delete_post_meta( $product_id, '_wes_images' );
	}

	public static function unlink( $product_id ) {
		foreach ( array( 'listing_id', 'status', 'error', 'images', 'synced_at', 'listing_state', 'auto_off', 'linked_via', 'variant_map' ) as $k ) {
			delete_post_meta( $product_id, '_wes_' . $k );
		}
	}

	public static function product_for_listing( $listing_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wes_listing_id' AND meta_value = %s LIMIT 1", (string) (int) $listing_id ) );
		return $id ? (int) $id : 0;
	}

	public static function mark_error( $product_id, $message ) {
		update_post_meta( $product_id, '_wes_status', 'error' );
		update_post_meta( $product_id, '_wes_error', $message );
	}

	/* ---------------------------------------------------------------------
	 * Validation.
	 * ------------------------------------------------------------------ */

	/**
	 * Return a list of human-readable problems that would stop this product syncing.
	 */
	public static function preflight( \WC_Product $product ) {
		$problems = array();
		if ( ! Settings::is_connected() ) {
			$problems[] = __( 'Your Etsy shop is not connected.', 'woo-etsy-sync' );
		}
		if ( Mapper::is_excluded( $product->get_id() ) ) {
			$problems[] = __( 'This product is set to "Don\'t sync to Etsy".', 'woo-etsy-sync' );
		}
		if ( $product->is_type( array( 'grouped', 'external' ) ) ) {
			$problems[] = __( 'Grouped and external/affiliate products can\'t be listed on Etsy.', 'woo-etsy-sync' );
		}
		if ( $product->is_downloadable() ) {
			$problems[] = __( 'Digital download products aren\'t supported yet.', 'woo-etsy-sync' );
		}
		if ( ! Mapper::taxonomy_id( $product ) ) {
			$problems[] = __( 'No Etsy category: set a default category in Settings → Listing defaults (or map this product\'s category).', 'woo-etsy-sync' );
		}
		if ( ! Mapper::shipping_profile_id( $product ) ) {
			$problems[] = __( 'No Etsy shipping profile: choose a default in Settings → Shipping & processing.', 'woo-etsy-sync' );
		}
		if ( ! Mapper::readiness_state_id( $product ) ) {
			$problems[] = __( 'No Etsy processing profile: choose a default in Settings → Shipping & processing.', 'woo-etsy-sync' );
		}
		if ( ! $product->is_type( 'variable' ) && '' === $product->get_price() && '' === $product->get_regular_price() ) {
			$problems[] = __( 'The product has no price.', 'woo-etsy-sync' );
		}
		return $problems;
	}

	/* ---------------------------------------------------------------------
	 * Main sync.
	 * ------------------------------------------------------------------ */

	/**
	 * Create or update the Etsy listing for a product.
	 *
	 * @return array Summary: listing_id, created (bool), state.
	 * @throws Api_Error On failure (also recorded on the product).
	 */
	public static function sync( $product_id, $allow_recreate = true ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product ) {
			throw new Api_Error( __( 'Product not found.', 'woo-etsy-sync' ) );
		}
		$product_id = $product->get_id();

		try {
			$result = self::do_sync( $product, $allow_recreate );
			update_post_meta( $product_id, '_wes_status', 'synced' );
			update_post_meta( $product_id, '_wes_synced_at', time() );
			delete_post_meta( $product_id, '_wes_error' );
			Logger::success(
				$result['created'] ? __( 'Created Etsy listing.', 'woo-etsy-sync' ) : __( 'Updated Etsy listing.', 'woo-etsy-sync' ),
				'sync',
				$product_id,
				$result['listing_id']
			);
			do_action( 'wes_product_synced', $product_id, $result );
			return $result;
		} catch ( \Exception $e ) {
			self::mark_error( $product_id, $e->getMessage() );
			Logger::error( $e->getMessage(), 'sync', $product_id, self::listing_id( $product_id ) );
			throw ( $e instanceof Api_Error ? $e : new Api_Error( $e->getMessage() ) );
		}
	}

	private static function do_sync( \WC_Product $product, $allow_recreate ) {
		$problems = self::preflight( $product );
		if ( $problems ) {
			throw new Api_Error( implode( ' ', $problems ) );
		}

		$api        = Etsy_Api::instance();
		$shop_id    = Settings::shop_id();
		$product_id = $product->get_id();
		$listing_id = self::listing_id( $product_id );
		$fields_on  = (array) Settings::get( 'update_fields' );
		$built      = Mapper::inventory( $product );
		$total_qty  = self::total_quantity( $built['inventory'] );
		$created    = false;

		if ( ! $listing_id ) {
			if ( $total_qty <= 0 ) {
				throw new Api_Error( __( 'Out of stock, so it was not listed on Etsy. It will be listed once it has stock and is synced again.', 'woo-etsy-sync' ) );
			}
			$res        = $api->create_draft_listing( $shop_id, Mapper::listing_fields( $product, false ) );
			$listing_id = (int) ( $res['listing_id'] ?? 0 );
			if ( ! $listing_id ) {
				throw new Api_Error( __( 'Etsy did not return a listing id.', 'woo-etsy-sync' ) );
			}
			update_post_meta( $product_id, '_wes_listing_id', $listing_id );
			update_post_meta( $product_id, '_wes_listing_state', 'draft' );
			delete_post_meta( $product_id, '_wes_images' );
			$created = true;
		} else {
			try {
				$res = $api->update_listing( $shop_id, $listing_id, Mapper::listing_fields( $product, true ) );
				if ( ! empty( $res['state'] ) ) {
					update_post_meta( $product_id, '_wes_listing_state', $res['state'] );
				}
			} catch ( Api_Error $e ) {
				if ( 404 === $e->status() && $allow_recreate ) {
					Logger::warning( __( 'The linked Etsy listing no longer exists — creating a new one.', 'woo-etsy-sync' ), 'sync', $product_id, $listing_id );
					self::unlink( $product_id );
					return self::do_sync( $product, false );
				}
				throw $e;
			}
		}

		// Images.
		$image_count = 0;
		if ( $created || ! empty( $fields_on['images'] ) ) {
			$image_count = self::sync_images( $product, $listing_id, $created );
		}

		// Inventory (variations, prices, SKUs, stock).
		if ( $created || ! empty( $fields_on['price'] ) ) {
			if ( $total_qty > 0 ) {
				$api->update_inventory( $listing_id, $built['inventory'] );
				update_post_meta( $product_id, '_wes_variant_map', $built['map'] );
				if ( $product->is_type( 'variable' ) && Settings::get( 'sync_variation_images' ) ) {
					self::sync_variation_images( $product, $listing_id, $built );
				}
			}
		} elseif ( ! empty( $fields_on['stock'] ) ) {
			Stock_Sync::push( $product_id, false );
		}

		// Listing state.
		$state = get_post_meta( $product_id, '_wes_listing_state', true );
		if ( $created ) {
			if ( 'active' === Settings::get( 'listing_state' ) ) {
				if ( $image_count > 0 ) {
					try {
						$res   = $api->update_listing( $shop_id, $listing_id, array( 'state' => 'active' ) );
						$state = $res['state'] ?? 'active';
					} catch ( Api_Error $e ) {
						Logger::warning( __( 'Listing was created as a draft but Etsy would not publish it yet: ', 'woo-etsy-sync' ) . $e->getMessage(), 'sync', $product_id, $listing_id );
					}
				} else {
					Logger::warning( __( 'Listing left as a draft because the product has no images (Etsy needs at least one to publish).', 'woo-etsy-sync' ), 'sync', $product_id, $listing_id );
				}
			}
		} else {
			$state = Stock_Sync::apply_stock_state( $product_id, $listing_id, $total_qty, $state );
		}
		update_post_meta( $product_id, '_wes_listing_state', $state ? $state : 'draft' );

		return array(
			'listing_id' => $listing_id,
			'created'    => $created,
			'state'      => $state,
			'url'        => self::listing_url( $listing_id ),
		);
	}

	public static function total_quantity( array $inventory ) {
		$total = 0;
		foreach ( $inventory['products'] as $p ) {
			foreach ( $p['offerings'] as $o ) {
				if ( ! empty( $o['is_enabled'] ) ) {
					$total += (int) $o['quantity'];
				}
			}
		}
		return $total;
	}

	/* ---------------------------------------------------------------------
	 * Images.
	 * ------------------------------------------------------------------ */

	/**
	 * Attachment ids that should be on the listing, in order.
	 */
	public static function desired_images( \WC_Product $product ) {
		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$ids[] = (int) $gid;
		}
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, (int) Settings::get( 'max_images', 10 ) );
		if ( $product->is_type( 'variable' ) && Settings::get( 'sync_variation_images' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$img = (int) get_post_thumbnail_id( $vid );
				if ( $img && ! in_array( $img, $ids, true ) && count( $ids ) < 20 ) {
					$ids[] = $img;
				}
			}
		}
		return apply_filters( 'wes_listing_image_ids', $ids, $product );
	}

	/**
	 * Get a local file path for an attachment, downloading it if it lives on a CDN.
	 *
	 * @return array{0:string,1:bool} path, is_temp.
	 */
	private static function image_file( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( $path && file_exists( $path ) ) {
			// Etsy rejects very large files; fall back to the "large" size.
			if ( filesize( $path ) > 9 * MB_IN_BYTES ) {
				$large = image_get_intermediate_size( $attachment_id, 'large' );
				if ( $large && ! empty( $large['path'] ) ) {
					$candidate = trailingslashit( wp_upload_dir()['basedir'] ) . $large['path'];
					if ( file_exists( $candidate ) ) {
						return array( $candidate, false );
					}
				}
			}
			return array( $path, false );
		}
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return array( '', false );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return array( '', false );
		}
		$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		if ( $ext ) {
			$renamed = $tmp . '.' . $ext;
			if ( @rename( $tmp, $renamed ) ) { // phpcs:ignore
				$tmp = $renamed;
			}
		}
		return array( $tmp, true );
	}

	/**
	 * Make the listing's images match the product. Returns how many images the listing has.
	 */
	public static function sync_images( \WC_Product $product, $listing_id, $fresh ) {
		$api        = Etsy_Api::instance();
		$shop_id    = Settings::shop_id();
		$product_id = $product->get_id();
		$desired    = self::desired_images( $product );
		$map        = $fresh ? array() : (array) get_post_meta( $product_id, '_wes_images', true );
		$map        = array_filter( $map );

		$on_etsy = array();
		if ( ! $fresh ) {
			$res = $api->get_listing_images( $listing_id );
			foreach ( $res['results'] ?? array() as $img ) {
				$on_etsy[ (int) $img['listing_image_id'] ] = true;
			}
		}

		$new_map = array();
		$rank    = 1;
		$failed  = 0;
		foreach ( $desired as $attachment_id ) {
			$existing = isset( $map[ $attachment_id ] ) ? (int) $map[ $attachment_id ] : 0;
			if ( $existing && isset( $on_etsy[ $existing ] ) ) {
				$new_map[ $attachment_id ] = $existing;
				$rank++;
				continue;
			}
			list( $file, $is_temp ) = self::image_file( $attachment_id );
			if ( ! $file ) {
				$failed++;
				continue;
			}
			try {
				$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				$res = $api->upload_listing_image( $shop_id, $listing_id, $file, $rank, $alt );
				if ( ! empty( $res['listing_image_id'] ) ) {
					$new_map[ $attachment_id ] = (int) $res['listing_image_id'];
					$rank++;
				}
			} catch ( Api_Error $e ) {
				$failed++;
				Logger::warning( sprintf( __( 'Image #%1$d could not be uploaded: %2$s', 'woo-etsy-sync' ), $attachment_id, $e->getMessage() ), 'images', $product_id, $listing_id );
			}
			if ( $is_temp ) {
				wp_delete_file( $file );
			}
		}

		// Remove Etsy images that are no longer on the product (WooCommerce is the source of truth).
		if ( ! $fresh && $new_map ) {
			$keep = array_flip( array_values( $new_map ) );
			foreach ( array_keys( $on_etsy ) as $image_id ) {
				if ( ! isset( $keep[ $image_id ] ) ) {
					try {
						$api->delete_listing_image( $shop_id, $listing_id, $image_id );
					} catch ( Api_Error $e ) {
						Logger::warning( __( 'Could not remove an old Etsy image: ', 'woo-etsy-sync' ) . $e->getMessage(), 'images', $product_id, $listing_id );
					}
				}
			}
		}

		update_post_meta( $product_id, '_wes_images', $new_map );
		if ( $failed ) {
			Logger::warning( sprintf( __( '%d image(s) could not be sent to Etsy.', 'woo-etsy-sync' ), $failed ), 'images', $product_id, $listing_id );
		}
		return count( $new_map ) ? count( $new_map ) : ( $fresh ? 0 : count( $on_etsy ) );
	}

	/**
	 * Attach variation images to the first variation property (Etsy supports one).
	 */
	private static function sync_variation_images( \WC_Product $product, $listing_id, array $built ) {
		$map = (array) get_post_meta( $product->get_id(), '_wes_images', true );
		if ( ! $map ) {
			return;
		}
		try {
			$inv          = Etsy_Api::instance()->get_inventory( $listing_id );
			$first_prop   = reset( $built['prop_ids'] );
			$first_key    = key( $built['prop_ids'] );
			$value_ids    = array(); // normalized value => value_id.
			foreach ( $inv['products'] ?? array() as $p ) {
				foreach ( $p['property_values'] ?? array() as $pv ) {
					if ( (int) $pv['property_id'] === $first_prop && ! empty( $pv['value_ids'][0] ) ) {
						$value_ids[ Mapper::norm( $pv['values'][0] ?? '' ) ] = (int) $pv['value_ids'][0];
					}
				}
			}
			$payload = array();
			$done    = array();
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				$img       = $variation ? (int) $variation->get_image_id( 'edit' ) : 0;
				if ( ! $img || empty( $map[ $img ] ) ) {
					continue;
				}
				$slug  = $variation->get_attributes()[ str_replace( 'attribute_', '', $first_key ) ] ?? '';
				$name  = Mapper::option_name( $built['attrs'][ $first_key ], $slug );
				$norm  = Mapper::norm( $name );
				if ( '' === $slug || isset( $done[ $norm ] ) || empty( $value_ids[ $norm ] ) ) {
					continue;
				}
				$done[ $norm ] = true;
				$payload[]     = array(
					'property_id' => $first_prop,
					'value_id'    => $value_ids[ $norm ],
					'image_id'    => (int) $map[ $img ],
				);
			}
			if ( $payload ) {
				Etsy_Api::instance()->update_variation_images( Settings::shop_id(), $listing_id, $payload );
			}
		} catch ( Api_Error $e ) {
			Logger::warning( __( 'Variation images could not be set: ', 'woo-etsy-sync' ) . $e->getMessage(), 'images', $product->get_id(), $listing_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Other listing actions.
	 * ------------------------------------------------------------------ */

	public static function set_state( $product_id, $state ) {
		$listing_id = self::listing_id( $product_id );
		if ( ! $listing_id ) {
			return;
		}
		$res = Etsy_Api::instance()->update_listing( Settings::shop_id(), $listing_id, array( 'state' => $state ) );
		update_post_meta( $product_id, '_wes_listing_state', $res['state'] ?? $state );
		Logger::info( sprintf( __( 'Etsy listing set to %s.', 'woo-etsy-sync' ), $state ), 'sync', $product_id, $listing_id );
	}

	/**
	 * Read-only preview of what would be sent to Etsy.
	 */
	public static function preview( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new Api_Error( __( 'Product not found.', 'woo-etsy-sync' ) );
		}
		$problems = self::preflight( $product );
		$out      = array(
			'problems'   => $problems,
			'listing_id' => self::listing_id( $product_id ),
		);
		try {
			$out['fields']    = Mapper::listing_fields( $product, false );
			$built            = Mapper::inventory( $product );
			$out['inventory'] = $built['inventory'];
			$out['images']    = count( self::desired_images( $product ) );
		} catch ( Api_Error $e ) {
			$out['problems'][] = $e->getMessage();
		}
		return $out;
	}
}
