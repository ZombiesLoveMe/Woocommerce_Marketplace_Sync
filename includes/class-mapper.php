<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce products into Etsy listing / inventory payloads.
 */
class Mapper {

	/** Etsy "custom" variation property ids (used for any WooCommerce attribute). */
	const CUSTOM_PROPERTY_IDS = array( 513, 514 );

	/* ---------------------------------------------------------------------
	 * Static option lists.
	 * ------------------------------------------------------------------ */

	public static function when_made_options() {
		return array( 'made_to_order', '2020_2026', '2010_2019', '2007_2009', 'before_2007', '2000_2006', '1990s', '1980s', '1970s', '1960s', '1950s', '1940s', '1930s', '1920s', '1910s', '1900s', '1800s', '1700s', 'before_1700' );
	}

	/* ---------------------------------------------------------------------
	 * Per-product overrides (set in the product's "Etsy" tab).
	 * ------------------------------------------------------------------ */

	public static function meta( $product_id, $key, $fallback = '' ) {
		$v = get_post_meta( $product_id, '_wes_' . $key, true );
		return ( '' === $v || null === $v ) ? $fallback : $v;
	}

	public static function is_excluded( $product_id ) {
		return 'yes' === get_post_meta( $product_id, '_wes_exclude', true );
	}

	/* ---------------------------------------------------------------------
	 * Text helpers.
	 * ------------------------------------------------------------------ */

	public static function clean_title( $title ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
		$title = str_replace( array( '–', '—' ), '-', $title );
		// Etsy: letters, numbers, punctuation, math symbols, whitespace, ™ © ® only.
		$title = preg_replace( '/[^\p{L}\p{Nd}\p{P}\p{Sm}\p{Zs}™©®]/u', '', $title );
		// Etsy: % : & may each appear only once.
		foreach ( array( '%', ':', '&' ) as $ch ) {
			$pos = mb_strpos( $title, $ch );
			if ( false !== $pos ) {
				$title = mb_substr( $title, 0, $pos + 1 ) . str_replace( $ch, ' ', mb_substr( $title, $pos + 1 ) );
			}
		}
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		if ( mb_strlen( $title ) > 140 ) {
			$title = rtrim( mb_substr( $title, 0, 140 ) );
		}
		return $title;
	}

	public static function clean_tag( $tag ) {
		$tag = html_entity_decode( (string) $tag, ENT_QUOTES, 'UTF-8' );
		$tag = preg_replace( "/[^\p{L}\p{Nd}\p{Zs}\-'™©®]/u", ' ', $tag );
		$tag = trim( preg_replace( '/\s+/u', ' ', $tag ) );
		return mb_substr( $tag, 0, 20 );
	}

	public static function clean_material( $m ) {
		$m = html_entity_decode( (string) $m, ENT_QUOTES, 'UTF-8' );
		$m = preg_replace( '/[^\p{L}\p{Nd}\p{Zs}]/u', ' ', $m );
		$m = trim( preg_replace( '/\s+/u', ' ', $m ) );
		return mb_substr( $m, 0, 45 );
	}

	private static function unique_list( array $items, $cleaner, $max ) {
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			$item = call_user_func( $cleaner, $item );
			$key  = mb_strtolower( $item );
			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	private static function split_csv( $str ) {
		return array_filter( array_map( 'trim', explode( ',', (string) $str ) ), 'strlen' );
	}

	/**
	 * Convert product HTML into clean plain text (Etsy descriptions are plain text).
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		$html = strip_shortcodes( $html );
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<li[^>]*>#i', "\n• ", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|ul|ol|table|tr|blockquote)>#i', "\n\n", $html );
		$html = preg_replace( '#<h[1-6][^>]*>#i', "\n", $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/* ---------------------------------------------------------------------
	 * Field builders.
	 * ------------------------------------------------------------------ */

	public static function title( \WC_Product $product ) {
		$override = self::meta( $product->get_id(), 'title' );
		if ( $override ) {
			return self::clean_title( $override );
		}
		$cats     = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$template = (string) Settings::get( 'title_template', '{title}' );
		$title    = strtr(
			$template,
			array(
				'{title}'    => $product->get_name(),
				'{sku}'      => $product->get_sku(),
				'{category}' => ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '',
			)
		);
		$title = apply_filters( 'wes_listing_title', $title, $product );
		$title = self::clean_title( $title );
		return '' !== $title ? $title : self::clean_title( $product->get_name() );
	}

	public static function description( \WC_Product $product ) {
		$source = Settings::get( 'description_source', 'description' );
		$long   = self::html_to_text( $product->get_description() );
		$short  = self::html_to_text( $product->get_short_description() );
		switch ( $source ) {
			case 'short':
				$text = $short ? $short : $long;
				break;
			case 'both':
				$text = trim( $short . "\n\n" . $long );
				break;
			default:
				$text = $long ? $long : $short;
		}
		$footer = trim( (string) Settings::get( 'description_footer', '' ) );
		if ( $footer ) {
			$text = trim( $text . "\n\n" . $footer );
		}
		if ( '' === $text ) {
			$text = $product->get_name();
		}
		return apply_filters( 'wes_listing_description', $text, $product );
	}

	public static function tags( \WC_Product $product ) {
		$id       = $product->get_id();
		$override = self::meta( $id, 'tags' );
		if ( $override ) {
			$raw = self::split_csv( $override );
		} else {
			$raw = array();
			if ( Settings::get( 'use_product_tags' ) ) {
				$names = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
				$raw   = array_merge( $raw, is_wp_error( $names ) ? array() : $names );
			}
			if ( Settings::get( 'use_categories_as_tags' ) ) {
				$names = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
				$raw   = array_merge( $raw, is_wp_error( $names ) ? array() : array_diff( $names, array( 'Uncategorized', 'Uncategorised' ) ) );
			}
			$raw = array_merge( $raw, self::split_csv( Settings::get( 'extra_tags', '' ) ) );
		}
		return apply_filters( 'wes_listing_tags', self::unique_list( $raw, array( __CLASS__, 'clean_tag' ), 13 ), $product );
	}

	public static function materials( \WC_Product $product ) {
		$override = self::meta( $product->get_id(), 'materials' );
		$raw      = self::split_csv( $override ? $override : Settings::get( 'default_materials', '' ) );
		return self::unique_list( $raw, array( __CLASS__, 'clean_material' ), 13 );
	}

	/**
	 * Etsy price for a simple product or a single variation.
	 */
	public static function price( \WC_Product $product ) {
		$price = Settings::get( 'use_sale_price' ) ? $product->get_price() : $product->get_regular_price();
		if ( '' === $price || null === $price ) {
			$price = $product->get_regular_price();
		}
		$price = (float) $price;

		$type  = Settings::get( 'price_adjust_type', 'none' );
		$value = (float) Settings::get( 'price_adjust_value', 0 );
		if ( 'percent' === $type ) {
			$price = $price * ( 1 + $value / 100 );
		} elseif ( 'fixed' === $type ) {
			$price = $price + $value;
		}

		switch ( Settings::get( 'price_rounding', 'none' ) ) {
			case '99':
				$price = max( 0.99, ceil( $price ) - 0.01 );
				break;
			case 'whole':
				$price = max( 1, round( $price ) );
				break;
		}
		$price = round( max( 0.20, $price ), 2 );
		return (float) apply_filters( 'wes_listing_price', $price, $product );
	}

	/**
	 * Quantity to advertise on Etsy for a simple product or variation.
	 */
	public static function quantity( \WC_Product $product ) {
		$max = (int) Settings::get( 'max_qty', 999 );
		if ( $product->managing_stock() ) {
			$qty = (int) $product->get_stock_quantity();
			if ( $qty <= 0 && $product->backorders_allowed() ) {
				$qty = (int) Settings::get( 'untracked_stock_qty', 10 );
			}
		} else {
			$qty = 'outofstock' === $product->get_stock_status() ? 0 : (int) Settings::get( 'untracked_stock_qty', 10 );
		}
		return (int) apply_filters( 'wes_listing_quantity', max( 0, min( $max, $qty ) ), $product );
	}

	public static function taxonomy_id( \WC_Product $product ) {
		$override = (int) self::meta( $product->get_id(), 'taxonomy_id', 0 );
		if ( $override ) {
			return $override;
		}
		$map = (array) Settings::get( 'category_map', array() );
		if ( $map ) {
			$terms = wp_get_post_terms( $product->get_id(), 'product_cat' );
			if ( ! is_wp_error( $terms ) ) {
				// Prefer the deepest mapped category, walking up parents if needed.
				$best = 0;
				$best_depth = -1;
				foreach ( $terms as $term ) {
					$t     = $term;
					$depth = count( get_ancestors( $term->term_id, 'product_cat' ) );
					while ( $t ) {
						if ( ! empty( $map[ $t->term_id ]['id'] ) ) {
							if ( $depth > $best_depth ) {
								$best       = (int) $map[ $t->term_id ]['id'];
								$best_depth = $depth;
							}
							break;
						}
						$t = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
						$t = ( $t && ! is_wp_error( $t ) ) ? $t : null;
						$depth--;
					}
				}
				if ( $best ) {
					return $best;
				}
			}
		}
		return (int) Settings::get( 'default_taxonomy_id', 0 );
	}

	public static function shipping_profile_id( \WC_Product $product ) {
		$override = (int) self::meta( $product->get_id(), 'shipping_profile', 0 );
		if ( $override ) {
			return $override;
		}
		$class = (int) $product->get_shipping_class_id();
		$map   = (array) Settings::get( 'shipping_class_map', array() );
		if ( $class && ! empty( $map[ $class ] ) ) {
			return (int) $map[ $class ];
		}
		return (int) Settings::get( 'default_shipping_profile', 0 );
	}

	public static function readiness_state_id( \WC_Product $product ) {
		$override = (int) self::meta( $product->get_id(), 'readiness_state', 0 );
		return $override ? $override : (int) Settings::get( 'default_readiness_state', 0 );
	}

	public static function section_id( \WC_Product $product ) {
		$override = (int) self::meta( $product->get_id(), 'section', 0 );
		if ( $override ) {
			return $override;
		}
		$map = (array) Settings::get( 'section_map', array() );
		if ( $map ) {
			$ids = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
			foreach ( is_wp_error( $ids ) ? array() : $ids as $tid ) {
				if ( ! empty( $map[ $tid ] ) ) {
					return (int) $map[ $tid ];
				}
			}
		}
		return 0;
	}

	/**
	 * Weight & dimensions converted to Etsy's units.
	 */
	public static function dimensions( \WC_Product $product ) {
		if ( ! Settings::get( 'send_dimensions' ) ) {
			return array();
		}
		$out   = array();
		$wmap  = array( 'kg' => 'kg', 'g' => 'g', 'lbs' => 'lb', 'oz' => 'oz' );
		$dmap  = array( 'm' => 'm', 'cm' => 'cm', 'mm' => 'mm', 'in' => 'in', 'yd' => 'yd' );
		$wunit = get_option( 'woocommerce_weight_unit', 'kg' );
		$dunit = get_option( 'woocommerce_dimension_unit', 'cm' );
		$w     = (float) $product->get_weight();
		if ( $w > 0 && isset( $wmap[ $wunit ] ) ) {
			$out['item_weight']      = $w;
			$out['item_weight_unit'] = $wmap[ $wunit ];
		}
		$l = (float) $product->get_length();
		$wd = (float) $product->get_width();
		$h = (float) $product->get_height();
		if ( $l > 0 && $wd > 0 && $h > 0 && isset( $dmap[ $dunit ] ) ) {
			$out['item_length']          = $l;
			$out['item_width']           = $wd;
			$out['item_height']          = $h;
			$out['item_dimensions_unit'] = $dmap[ $dunit ];
		}
		return $out;
	}

	/**
	 * Fields for createDraftListing / updateListing.
	 *
	 * @param bool $for_update Only include fields allowed by the "update fields" setting.
	 */
	public static function listing_fields( \WC_Product $product, $for_update = false ) {
		$id      = $product->get_id();
		$fields  = (array) Settings::get( 'update_fields', array() );
		$include = function ( $key ) use ( $for_update, $fields ) {
			return ! $for_update || ! empty( $fields[ $key ] );
		};

		$data = array(
			'who_made'            => self::meta( $id, 'who_made', Settings::get( 'who_made' ) ),
			'when_made'           => self::meta( $id, 'when_made', Settings::get( 'when_made' ) ),
			'is_supply'           => (bool) Settings::get( 'is_supply' ),
			'taxonomy_id'         => self::taxonomy_id( $product ),
			'shipping_profile_id' => self::shipping_profile_id( $product ),
			'type'                => 'physical',
		);
		if ( $include( 'title' ) ) {
			$data['title'] = self::title( $product );
		}
		if ( $include( 'description' ) ) {
			$data['description'] = self::description( $product );
		}
		if ( $include( 'tags' ) ) {
			$tags = self::tags( $product );
			if ( $tags || $for_update ) {
				$data['tags'] = $tags;
			}
			$materials = self::materials( $product );
			if ( $materials || $for_update ) {
				$data['materials'] = $materials;
			}
		}
		if ( ! $for_update ) {
			$data['readiness_state_id'] = self::readiness_state_id( $product );
			$data['quantity']           = max( 1, self::max_quantity( $product ) );
			$data['price']              = self::min_price( $product );
			$data['should_auto_renew']  = true;
		}
		if ( $for_update ) {
			unset( $data['type'] );
		}
		$section = self::section_id( $product );
		if ( $section ) {
			$data['shop_section_id'] = $section;
		}
		$return_policy = (int) Settings::get( 'default_return_policy', 0 );
		if ( $return_policy ) {
			$data['return_policy_id'] = $return_policy;
		}
		$data = array_merge( $data, self::dimensions( $product ) );
		if ( $for_update && empty( $data['shipping_profile_id'] ) ) {
			unset( $data['shipping_profile_id'] );
		}
		if ( $for_update && empty( $data['taxonomy_id'] ) ) {
			unset( $data['taxonomy_id'] );
		}
		return apply_filters( 'wes_listing_fields', $data, $product, $for_update );
	}

	private static function max_quantity( \WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return self::quantity( $product );
		}
		$max = 0;
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v ) {
				$max = max( $max, self::quantity( $v ) );
			}
		}
		return $max;
	}

	private static function min_price( \WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return self::price( $product );
		}
		$min = null;
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && '' !== $v->get_price() ) {
				$p   = self::price( $v );
				$min = null === $min ? $p : min( $min, $p );
			}
		}
		return null === $min ? self::price( $product ) : $min;
	}

	/* ---------------------------------------------------------------------
	 * Variations.
	 * ------------------------------------------------------------------ */

	/**
	 * Clean a variation option value for Etsy (no parentheses, max 45 chars).
	 */
	public static function clean_value( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$value = str_replace( array( '(', ')', '[', ']' ), array( '- ', '', '- ', '' ), $value );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ), " -\t" );
		return mb_substr( $value, 0, 45 );
	}

	public static function norm( $value ) {
		return mb_strtolower( self::clean_value( $value ) );
	}

	/**
	 * Describe the variable product's attributes: [ attr_key => [ 'label', 'options' => [ slug => name ] ] ].
	 */
	public static function variation_attributes( \WC_Product_Variable $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$options = array();
			if ( $attr->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'all' ) );
				foreach ( $terms as $term ) {
					$options[ $term->slug ] = $term->name;
				}
			} else {
				foreach ( $attr->get_options() as $opt ) {
					$options[ $opt ] = $opt;
				}
			}
			$out[ 'attribute_' . sanitize_title( $key ) ] = array(
				'label'   => wc_attribute_label( $attr->get_name(), $product ),
				'options' => $options,
			);
		}
		return $out;
	}

	/**
	 * Resolve a variation's stored attribute value (slug or text) to the option's display name.
	 */
	public static function option_name( array $attr, $stored ) {
		if ( isset( $attr['options'][ $stored ] ) ) {
			return $attr['options'][ $stored ];
		}
		$want = sanitize_title( $stored );
		foreach ( $attr['options'] as $key => $name ) {
			if ( sanitize_title( $key ) === $want || sanitize_title( $name ) === $want ) {
				return $name;
			}
		}
		return $stored;
	}

	/**
	 * Build the full Etsy inventory for a product.
	 *
	 * @return array{inventory: array, map: array} map = normalized "valueA|valueB" => variation id.
	 * @throws Api_Error When the product can't be represented on Etsy.
	 */
	public static function inventory( \WC_Product $product ) {
		$readiness = self::readiness_state_id( $product );

		if ( ! $product->is_type( 'variable' ) ) {
			$qty = self::quantity( $product );
			return array(
				'inventory' => array(
					'products'                    => array(
						array(
							'sku'             => (string) $product->get_sku(),
							'property_values' => array(),
							'offerings'       => array(
								array(
									'price'              => self::price( $product ),
									'quantity'           => $qty,
									'is_enabled'         => true,
									'readiness_state_id' => $readiness ? $readiness : null,
								),
							),
						),
					),
					'price_on_property'           => array(),
					'quantity_on_property'        => array(),
					'sku_on_property'             => array(),
					'readiness_state_on_property' => array(),
				),
				'map'       => array(),
			);
		}

		/** @var \WC_Product_Variable $product */
		$attrs = self::variation_attributes( $product );
		if ( count( $attrs ) > 2 ) {
			throw new Api_Error( sprintf( __( 'Etsy allows at most 2 variation options per listing, but this product varies by %1$d attributes (%2$s).', 'woo-etsy-sync' ), count( $attrs ), implode( ', ', wp_list_pluck( $attrs, 'label' ) ) ) );
		}
		if ( ! $attrs ) {
			throw new Api_Error( __( 'This variable product has no attributes used for variations.', 'woo-etsy-sync' ) );
		}
		$keys     = array_keys( $attrs );
		$prop_ids = array();
		foreach ( $keys as $i => $k ) {
			$prop_ids[ $k ] = self::CUSTOM_PROPERTY_IDS[ $i ];
		}

		// Collect each variation's concrete combinations ("any" attributes expand to all options).
		$cells     = array(); // combo key => data.
		$values_in = array(); // attr key => [ display value => true ] in order.
		foreach ( $product->get_children() as $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || 'publish' !== $variation->get_status() ) { // "private" = disabled variation.
				continue;
			}
			$combos = array( array() );
			foreach ( $keys as $k ) {
				$raw  = $variation->get_attributes();
				$slug = $raw[ str_replace( 'attribute_', '', $k ) ] ?? '';
				if ( '' === $slug ) {
					$choices = array_values( $attrs[ $k ]['options'] ); // "Any" option.
				} else {
					$choices = array( self::option_name( $attrs[ $k ], $slug ) );
				}
				$next = array();
				foreach ( $combos as $c ) {
					foreach ( $choices as $choice ) {
						$c2       = $c;
						$c2[ $k ] = self::clean_value( $choice );
						$next[]   = $c2;
					}
				}
				$combos = $next;
			}
			foreach ( $combos as $combo ) {
				$ck = implode( '|', array_map( 'mb_strtolower', $combo ) );
				if ( isset( $cells[ $ck ] ) ) {
					continue; // First matching variation wins (same as WooCommerce).
				}
				foreach ( $combo as $k => $v ) {
					$values_in[ $k ][ $v ] = true;
				}
				$cells[ $ck ] = array(
					'combo'        => $combo,
					'variation_id' => $variation->get_id(),
					'sku'          => $variation->get_sku() ? $variation->get_sku() : '',
					'price'        => self::price( $variation ),
					'quantity'     => self::quantity( $variation ),
					'enabled'      => $variation->is_purchasable() && '' !== $variation->get_price(),
				);
			}
		}
		if ( ! $cells ) {
			throw new Api_Error( __( 'This variable product has no published variations.', 'woo-etsy-sync' ) );
		}

		// Etsy expects every combination; fill gaps with disabled offerings.
		$grid = array( array() );
		foreach ( $keys as $k ) {
			$next = array();
			foreach ( $grid as $g ) {
				foreach ( array_keys( $values_in[ $k ] ?? array() ) as $v ) {
					$g2       = $g;
					$g2[ $k ] = $v;
					$next[]   = $g2;
				}
			}
			$grid = $next;
		}
		$fallback_price = min( wp_list_pluck( $cells, 'price' ) );
		$products       = array();
		$map            = array();
		$prices         = array();
		$skus           = array();
		$any_enabled    = false;
		foreach ( $grid as $combo ) {
			$ck   = implode( '|', array_map( 'mb_strtolower', $combo ) );
			$cell = $cells[ $ck ] ?? null;
			$pv   = array();
			foreach ( $combo as $k => $v ) {
				$pv[] = array(
					'property_id'   => $prop_ids[ $k ],
					'property_name' => mb_substr( self::clean_value( $attrs[ $k ]['label'] ), 0, 45 ),
					'value_ids'     => array(),
					'values'        => array( $v ),
				);
			}
			$qty     = $cell ? (int) $cell['quantity'] : 0;
			$enabled = $cell && $cell['enabled'] && $qty > 0;
			$price   = $cell ? $cell['price'] : $fallback_price;
			$sku     = $cell && $cell['sku'] ? $cell['sku'] : (string) $product->get_sku();
			$any_enabled = $any_enabled || $enabled;
			$prices[ (string) $price ] = true;
			$skus[ $sku ]              = true;
			$products[]                = array(
				'sku'             => $sku,
				'property_values' => $pv,
				'offerings'       => array(
					array(
						'price'              => $price,
						'quantity'           => $enabled ? $qty : 0,
						'is_enabled'         => $enabled,
						'readiness_state_id' => $readiness ? $readiness : null,
					),
				),
			);
			if ( $cell ) {
				$map[ $ck ] = $cell['variation_id'];
			}
		}

		$all_props = array_values( $prop_ids );
		return array(
			'inventory'   => array(
				'products'                    => $products,
				'price_on_property'           => count( $prices ) > 1 ? $all_props : array(),
				'quantity_on_property'        => $all_props,
				'sku_on_property'             => count( $skus ) > 1 ? $all_props : array(),
				'readiness_state_on_property' => array(),
			),
			'map'         => $map,
			'any_enabled' => $any_enabled,
			'attrs'       => $attrs,
			'prop_ids'    => $prop_ids,
		);
	}
}
