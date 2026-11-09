<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce products into eBay Trading API <Item> data.
 * Falls back to WP-Lister's saved product fields (_ebay_title etc.) when present.
 */
class Ebay_Mapper {

	public static function meta( $id, $key, $fallback = '' ) {
		$v = get_post_meta( $id, '_wes_ebay_' . $key, true );
		return ( '' === $v || null === $v ) ? $fallback : $v;
	}

	public static function is_excluded( $id ) {
		return 'yes' === get_post_meta( $id, '_wes_ebay_exclude', true );
	}

	public static function clean_title( $t ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' );
		$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
		return mb_strlen( $t ) > 80 ? rtrim( mb_substr( $t, 0, 80 ) ) : $t;
	}

	public static function title( \WC_Product $p ) {
		$id = $p->get_id();
		$o  = self::meta( $id, 'title' );
		if ( '' === $o ) {
			$o = (string) get_post_meta( $id, '_ebay_title', true ); // WP-Lister.
		}
		if ( '' !== trim( $o ) ) {
			return self::clean_title( $o );
		}
		$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		$t    = strtr(
			(string) Ebay_Settings::get( 'title_template', '{title}' ),
			array(
				'{title}'    => $p->get_name(),
				'{sku}'      => $p->get_sku(),
				'{category}' => ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '',
			)
		);
		$t = self::clean_title( apply_filters( 'wes_ebay_title', $t, $p ) );
		return '' !== $t ? $t : self::clean_title( $p->get_name() );
	}

	/**
	 * eBay allows simple HTML but no links to other sites, scripts or forms.
	 */
	public static function clean_html( $html ) {
		$html = strip_shortcodes( (string) $html );
		$html = str_replace( '\\n', "\n", $html );
		$html = preg_replace( '#<(script|style|iframe|form|object|embed)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#</?a\b[^>]*>#i', '', $html );                // Keep link text, drop link.
		$html = preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $html );     // Bare URLs.
		$html = wp_kses(
			$html,
			array(
				'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
				'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
				'table' => array(), 'tr' => array(), 'td' => array(), 'th' => array(), 'tbody' => array(), 'thead' => array(),
			)
		);
		if ( false === stripos( $html, '<p' ) && false === stripos( $html, '<li' ) ) {
			$html = wpautop( $html );
		}
		return trim( $html );
	}

	public static function description( \WC_Product $p ) {
		$long  = self::clean_html( $p->get_description() );
		$short = self::clean_html( $p->get_short_description() );
		switch ( Ebay_Settings::get( 'description_source' ) ) {
			case 'short':
				$html = $short ? $short : $long;
				break;
			case 'both':
				$html = $short . $long;
				break;
			default:
				$html = $long ? $long : $short;
		}
		$footer = trim( (string) Ebay_Settings::get( 'description_footer' ) );
		if ( $footer ) {
			$html .= wpautop( esc_html( $footer ) );
		}
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			$html = '<p>' . esc_html( $p->get_name() ) . '</p>';
		}
		return apply_filters( 'wes_ebay_description', $html, $p );
	}

	public static function price( \WC_Product $p ) {
		$price = Ebay_Settings::get( 'use_sale_price' ) ? $p->get_price() : $p->get_regular_price();
		if ( '' === $price || null === $price ) {
			$price = $p->get_regular_price();
		}
		$price = (float) $price;
		$v     = (float) Ebay_Settings::get( 'price_adjust_value', 0 );
		switch ( Ebay_Settings::get( 'price_adjust_type' ) ) {
			case 'percent':
				$price *= 1 + $v / 100;
				break;
			case 'fixed':
				$price += $v;
				break;
		}
		switch ( Ebay_Settings::get( 'price_rounding' ) ) {
			case '99':
				$price = max( 0.99, ceil( $price ) - 0.01 );
				break;
			case 'whole':
				$price = max( 1, round( $price ) );
				break;
		}
		return (float) apply_filters( 'wes_ebay_price', round( max( 0.99, $price ), 2 ), $p );
	}

	public static function quantity( \WC_Product $p ) {
		$max = (int) Ebay_Settings::get( 'max_qty', 999 );
		if ( $p->managing_stock() ) {
			$q = (int) $p->get_stock_quantity();
			if ( $q <= 0 && $p->backorders_allowed() ) {
				$q = (int) Ebay_Settings::get( 'untracked_stock_qty', 10 );
			}
		} else {
			$q = 'outofstock' === $p->get_stock_status() ? 0 : (int) Ebay_Settings::get( 'untracked_stock_qty', 10 );
		}
		return (int) apply_filters( 'wes_ebay_quantity', max( 0, min( $max, $q ) ), $p );
	}

	public static function category_id( \WC_Product $p ) {
		$id = $p->get_id();
		$o  = (int) self::meta( $id, 'category', 0 );
		if ( $o ) {
			return $o;
		}
		$wpl = (int) get_post_meta( $id, '_ebay_category_1_id', true );
		if ( $wpl ) {
			return $wpl;
		}
		$map = (array) Ebay_Settings::get( 'category_map', array() );
		if ( $map ) {
			$terms = wp_get_post_terms( $id, 'product_cat' );
			$best  = 0;
			$depth = -1;
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$t = $term;
				$d = count( get_ancestors( $term->term_id, 'product_cat' ) );
				while ( $t ) {
					if ( ! empty( $map[ $t->term_id ]['id'] ) ) {
						if ( $d > $depth ) {
							$best  = (int) $map[ $t->term_id ]['id'];
							$depth = $d;
						}
						break;
					}
					$t = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
					$t = ( $t && ! is_wp_error( $t ) ) ? $t : null;
					$d--;
				}
			}
			if ( $best ) {
				return $best;
			}
		}
		return (int) Ebay_Settings::get( 'default_category', 0 );
	}

	public static function condition_id( \WC_Product $p ) {
		$o = (int) self::meta( $p->get_id(), 'condition', 0 );
		if ( ! $o ) {
			$o = (int) get_post_meta( $p->get_id(), '_ebay_condition_id', true );
		}
		return $o ? $o : (int) Ebay_Settings::get( 'condition_id', 1000 );
	}

	/** Parse "Name: Value" lines (values may be separated with |). */
	public static function parse_lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $val ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $name && '' !== $val ) {
				$out[ $name ] = array_values( array_filter( array_map( 'trim', explode( '|', $val ) ), 'strlen' ) );
			}
		}
		return $out;
	}

	/**
	 * Item specifics (excluding the ones used for variations).
	 */
	public static function specifics( \WC_Product $p, array $exclude_names = array() ) {
		$id    = $p->get_id();
		$specs = array();
		$brand = (string) get_post_meta( $id, '_ebay_brand', true );
		$brand = $brand ? $brand : (string) Ebay_Settings::get( 'brand' );
		if ( $brand ) {
			$specs['Brand'] = array( $brand );
		}
		$mpn = (string) get_post_meta( $id, '_ebay_mpn', true );
		$mpn = $mpn ? $mpn : (string) Ebay_Settings::get( 'mpn' );
		if ( $mpn ) {
			$specs['MPN'] = array( $mpn );
		}
		if ( Ebay_Settings::get( 'use_attributes' ) ) {
			foreach ( $p->get_attributes() as $attr ) {
				if ( ! is_object( $attr ) || $attr->get_variation() ) {
					continue;
				}
				$name = wc_attribute_label( $attr->get_name(), $p );
				$vals = $attr->is_taxonomy() ? wc_get_product_terms( $id, $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
				$vals = array_values( array_filter( array_map( 'strval', (array) $vals ) ) );
				if ( $vals && 'brand' !== strtolower( $name ) ) {
					$specs[ $name ] = $vals;
				}
			}
		}
		$specs = array_merge( $specs, self::parse_lines( Ebay_Settings::get( 'custom_specifics' ) ) );
		$specs = array_merge( $specs, self::parse_lines( self::meta( $id, 'specifics' ) ) );
		$lower = array_map( 'strtolower', $exclude_names );
		$out   = array();
		foreach ( $specs as $name => $vals ) {
			if ( in_array( strtolower( $name ), $lower, true ) ) {
				continue;
			}
			$out[] = array(
				'Name'  => mb_substr( $name, 0, 65 ),
				'Value' => array_map(
					function ( $v ) {
						return mb_substr( $v, 0, 65 );
					},
					array_slice( $vals, 0, 30 )
				),
			);
		}
		return apply_filters( 'wes_ebay_specifics', $out, $p );
	}

	public static function image_urls( array $attachment_ids ) {
		$urls = array();
		foreach ( $attachment_ids as $aid ) {
			$url = wp_get_attachment_url( $aid );
			if ( $url ) {
				$urls[] = set_url_scheme( $url, 'https' );
			}
		}
		return $urls;
	}

	public static function images( \WC_Product $p ) {
		$ids = array();
		if ( $p->get_image_id() ) {
			$ids[] = (int) $p->get_image_id();
		}
		foreach ( $p->get_gallery_image_ids() as $g ) {
			$ids[] = (int) $g;
		}
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, (int) Ebay_Settings::get( 'max_images', 12 ) );
		return self::image_urls( $ids );
	}

	/** The SKU eBay will use for a variation (Woo SKU, or a stable generated one). */
	public static function variation_sku( \WC_Product $v ) {
		$sku = (string) $v->get_sku();
		return '' !== $sku ? $sku : 'WC' . $v->get_id();
	}

	/**
	 * Variation data.
	 *
	 * @return array{set: array, variations: array, pictures: array|null, names: array, specs_by_sku: array}
	 */
	public static function variations( \WC_Product $p ) {
		$attrs = Mapper::variation_attributes( $p );
		if ( count( $attrs ) > 5 ) {
			throw new Api_Error( __( 'eBay allows at most 5 variation options per listing.', 'woo-etsy-sync' ) );
		}
		$names = array();
		foreach ( $attrs as $k => $a ) {
			$names[ $k ] = mb_substr( $a['label'], 0, 65 );
		}
		$set          = array();
		$rows         = array();
		$seen         = array();
		$specs_by_sku = array();
		$pictures     = array();
		$first_key    = key( $attrs );
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== $v->get_status() || '' === $v->get_price() ) {
				continue;
			}
			$raw   = $v->get_attributes();
			$specs = array();
			$skip  = false;
			foreach ( $attrs as $k => $a ) {
				$slug = $raw[ str_replace( 'attribute_', '', $k ) ] ?? '';
				if ( '' === $slug ) {
					$skip = true; // "Any …" variations can't be represented on eBay.
					break;
				}
				$val                  = mb_substr( Mapper::option_name( $a, $slug ), 0, 65 );
				$specs[ $names[ $k ] ] = $val;
				$set[ $names[ $k ] ][ $val ] = true;
			}
			if ( $skip ) {
				continue;
			}
			$key = strtolower( implode( '|', $specs ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$sku          = self::variation_sku( $v );
			$specs_by_sku[ $sku ] = $specs;
			$rows[]       = array(
				'SKU'                => $sku,
				'StartPrice'         => self::price( $v ),
				'Quantity'           => self::quantity( $v ),
				'VariationSpecifics' => array(
					'NameValueList' => array_map(
						function ( $n, $val ) {
							return array( 'Name' => $n, 'Value' => $val );
						},
						array_keys( $specs ),
						array_values( $specs )
					),
				),
			);
			if ( Ebay_Settings::get( 'variation_images' ) ) {
				$img = (int) $v->get_image_id( 'edit' );
				$fv  = $specs[ $names[ $first_key ] ];
				if ( $img && ! isset( $pictures[ $fv ] ) ) {
					$u = self::image_urls( array( $img ) );
					if ( $u ) {
						$pictures[ $fv ] = $u[0];
					}
				}
			}
		}
		if ( ! $rows ) {
			throw new Api_Error( __( 'This variable product has no published variations with a price (variations set to "Any …" can\'t be listed on eBay).', 'woo-etsy-sync' ) );
		}
		if ( count( $rows ) > 250 ) {
			throw new Api_Error( sprintf( __( 'eBay allows up to 250 variations per listing; this product has %d.', 'woo-etsy-sync' ), count( $rows ) ) );
		}
		$set_list = array();
		foreach ( $set as $n => $vals ) {
			$set_list[] = array( 'Name' => $n, 'Value' => array_keys( $vals ) );
		}
		$pic = null;
		if ( $pictures ) {
			$pic = array(
				'VariationSpecificName'       => $names[ $first_key ],
				'VariationSpecificPictureSet' => array_map(
					function ( $val, $url ) {
						return array( 'VariationSpecificValue' => $val, 'PictureURL' => $url );
					},
					array_keys( $pictures ),
					array_values( $pictures )
				),
			);
		}
		return array(
			'set'          => $set_list,
			'variations'   => $rows,
			'pictures'     => $pic,
			'names'        => array_values( $names ),
			'specs_by_sku' => $specs_by_sku,
		);
	}

	/**
	 * Build the <Item> for Add/Revise/VerifyAdd FixedPriceItem.
	 *
	 * @param int  $item_id  Existing eBay item id (revise) or 0.
	 * @param bool $partial  Respect the "update fields" switches (revise only).
	 */
	public static function item( \WC_Product $p, $item_id = 0, $partial = false ) {
		$site   = Ebay_Settings::site();
		$fields = (array) Ebay_Settings::get( 'update_fields' );
		$want   = function ( $k ) use ( $partial, $fields ) {
			return ! $partial || ! empty( $fields[ $k ] );
		};
		$item = array();
		if ( $item_id ) {
			$item['ItemID'] = (string) $item_id;
		}
		$var = $p->is_type( 'variable' ) ? self::variations( $p ) : null;

		if ( $want( 'title' ) ) {
			$item['Title'] = self::title( $p );
		}
		if ( $want( 'description' ) ) {
			$item['Description'] = self::description( $p );
		}
		if ( ! $item_id ) {
			$item['PrimaryCategory'] = array( 'CategoryID' => self::category_id( $p ) );
			$item['ConditionID']     = self::condition_id( $p );
			$item['Country']         = Ebay_Settings::get( 'country', $site[3] );
			$item['Currency']        = $site[1];
			$item['ListingDuration'] = 'GTC';
			$item['ListingType']     = 'FixedPriceItem';
			$item['Location']        = Ebay_Settings::get( 'location' ) ? Ebay_Settings::get( 'location' ) : null;
			$item['PostalCode']      = Ebay_Settings::get( 'postcode' );
			$item['SellerProfiles']  = array(
				'SellerShippingProfile' => array( 'ShippingProfileID' => Ebay_Settings::get( 'shipping_policy' ) ),
				'SellerReturnProfile'   => array( 'ReturnProfileID' => Ebay_Settings::get( 'return_policy' ) ),
				'SellerPaymentProfile'  => array( 'PaymentProfileID' => Ebay_Settings::get( 'payment_policy' ) ),
			);
		}
		if ( $p->get_sku() ) {
			$item['SKU'] = $p->get_sku();
		}
		if ( $want( 'images' ) ) {
			$imgs = self::images( $p );
			if ( $imgs ) {
				$item['PictureDetails'] = array( 'PictureURL' => $imgs );
			}
		}
		if ( $want( 'specifics' ) ) {
			$specs = self::specifics( $p, $var ? $var['names'] : array() );
			if ( $specs ) {
				$item['ItemSpecifics'] = array( 'NameValueList' => $specs );
			}
		}
		if ( ! $item_id && Ebay_Settings::get( 'gpsr_enabled' ) && Ebay_Settings::get( 'gpsr_company' ) ) {
			$item['Regulatory'] = array(
				'Manufacturer' => array(
					'CompanyName' => Ebay_Settings::get( 'gpsr_company' ),
					'Street1'     => Ebay_Settings::get( 'gpsr_street' ),
					'CityName'    => Ebay_Settings::get( 'gpsr_city' ),
					'PostalCode'  => Ebay_Settings::get( 'gpsr_postcode' ),
					'Country'     => Ebay_Settings::get( 'gpsr_country' ),
					'Email'       => Ebay_Settings::get( 'gpsr_email' ) ? Ebay_Settings::get( 'gpsr_email' ) : null,
					'Phone'       => Ebay_Settings::get( 'gpsr_phone' ) ? Ebay_Settings::get( 'gpsr_phone' ) : null,
				),
			);
		}
		if ( $var ) {
			if ( ! $item_id || $want( 'price' ) ) {
				$rows = $var['variations'];
				// Variations listed before but gone now are kept at quantity 0 (eBay won't delete sold variations).
				$prev = (array) get_post_meta( $p->get_id(), '_wes_ebay_variations', true );
				foreach ( $prev as $sku => $specs ) {
					if ( ! isset( $var['specs_by_sku'][ $sku ] ) && is_array( $specs ) && $item_id ) {
						$known = true;
						foreach ( $specs as $n => $v ) {
							$found = false;
							foreach ( $var['set'] as $i => $s ) {
								if ( $s['Name'] === $n ) {
									$found = true;
									if ( ! in_array( $v, $var['set'][ $i ]['Value'], true ) ) {
										$var['set'][ $i ]['Value'][] = $v;
									}
								}
							}
							$known = $known && $found;
						}
						if ( $known ) {
							$rows[] = array(
								'SKU'                => (string) $sku,
								'StartPrice'         => $rows[0]['StartPrice'],
								'Quantity'           => 0,
								'VariationSpecifics' => array(
									'NameValueList' => array_map(
										function ( $n, $v ) {
											return array( 'Name' => $n, 'Value' => $v );
										},
										array_keys( $specs ),
										array_values( $specs )
									),
								),
							);
						}
					}
				}
				$item['Variations'] = array(
					'VariationSpecificsSet' => array( 'NameValueList' => $var['set'] ),
					'Variation'             => $rows,
					'Pictures'              => ( $want( 'images' ) ? $var['pictures'] : null ),
				);
			}
		} elseif ( ! $item_id || $want( 'price' ) ) {
			$item['StartPrice'] = self::price( $p );
			$item['Quantity']   = self::quantity( $p );
		}
		return array(
			'item'         => apply_filters( 'wes_ebay_item', $item, $p, $item_id ),
			'specs_by_sku' => $var ? $var['specs_by_sku'] : array(),
		);
	}
}
