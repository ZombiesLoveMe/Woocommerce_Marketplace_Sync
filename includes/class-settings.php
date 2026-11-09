<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored as one array option, plus a separate (non-autoloaded) auth option.
 */
class Settings {

	const OPTION      = 'wes_settings';
	const AUTH_OPTION = 'wes_auth';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// Listing defaults.
			'listing_state'            => 'draft',      // draft | active.
			'who_made'                 => 'i_did',
			'when_made'                => 'made_to_order',
			'is_supply'                => false,
			'default_taxonomy_id'      => 0,
			'default_taxonomy_label'   => '',
			'category_map'             => array(),      // wc term id => array( id, label ).
			'section_map'              => array(),      // wc term id => etsy section id.
			'default_shipping_profile' => 0,
			'shipping_class_map'       => array(),      // wc shipping class id => etsy profile id.
			'default_readiness_state'  => 0,
			'default_return_policy'    => 0,

			// Content.
			'title_template'           => '{title}',
			'description_source'       => 'description', // description | short | both.
			'description_footer'       => '',
			'use_product_tags'         => true,
			'use_categories_as_tags'   => false,
			'extra_tags'               => '',
			'default_materials'        => '',
			'max_images'               => 10,
			'sync_variation_images'    => true,
			'send_dimensions'          => true,

			// Pricing.
			'use_sale_price'           => true,
			'price_adjust_type'        => 'none',       // none | percent | fixed.
			'price_adjust_value'       => 0,
			'price_rounding'           => 'none',       // none | 99 | whole.

			// Stock.
			'untracked_stock_qty'      => 10,
			'max_qty'                  => 999,
			'sync_stock_to_etsy'       => true,
			'sync_stock_from_etsy'     => true,
			'poll_minutes'             => 15,
			'deactivate_out_of_stock'  => true,

			// Behaviour.
			'auto_sync_on_save'        => true,
			'on_trash'                 => 'deactivate', // nothing | deactivate.
			'update_fields'            => array(
				'title'       => true,
				'description' => true,
				'price'       => true,
				'stock'       => true,
				'images'      => true,
				'tags'        => true,
			),
			'setup_complete'           => false,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			$defaults    = self::defaults();
			self::$cache = array_merge( $defaults, $saved );
			// Merge nested arrays so newly-added keys get defaults.
			self::$cache['update_fields'] = array_merge( $defaults['update_fields'], (array) self::$cache['update_fields'] );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$current = self::all();
		$clean   = self::sanitize( $values );
		$merged  = array_merge( $current, $clean );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
		do_action( 'wes_settings_updated', $merged, $current );
		return self::all();
	}

	/**
	 * Sanitize incoming settings against the defaults schema.
	 */
	public static function sanitize( array $values ) {
		$defaults = self::defaults();
		$out      = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$default = $defaults[ $key ];
			switch ( $key ) {
				case 'listing_state':
					$out[ $key ] = in_array( $value, array( 'draft', 'active' ), true ) ? $value : 'draft';
					break;
				case 'who_made':
					$out[ $key ] = in_array( $value, array( 'i_did', 'someone_else', 'collective' ), true ) ? $value : 'i_did';
					break;
				case 'when_made':
					$out[ $key ] = in_array( $value, Mapper::when_made_options(), true ) ? $value : 'made_to_order';
					break;
				case 'description_source':
					$out[ $key ] = in_array( $value, array( 'description', 'short', 'both' ), true ) ? $value : 'description';
					break;
				case 'price_adjust_type':
					$out[ $key ] = in_array( $value, array( 'none', 'percent', 'fixed' ), true ) ? $value : 'none';
					break;
				case 'price_rounding':
					$out[ $key ] = in_array( $value, array( 'none', '99', 'whole' ), true ) ? $value : 'none';
					break;
				case 'on_trash':
					$out[ $key ] = in_array( $value, array( 'nothing', 'deactivate' ), true ) ? $value : 'deactivate';
					break;
				case 'category_map':
					$out[ $key ] = array();
					foreach ( (array) $value as $term_id => $row ) {
						$row = (array) $row;
						if ( absint( $term_id ) && ! empty( $row['id'] ) ) {
							$out[ $key ][ absint( $term_id ) ] = array(
								'id'    => absint( $row['id'] ),
								'label' => sanitize_text_field( $row['label'] ?? '' ),
							);
						}
					}
					break;
				case 'section_map':
				case 'shipping_class_map':
					$out[ $key ] = array();
					foreach ( (array) $value as $k => $v ) {
						if ( absint( $k ) && absint( $v ) ) {
							$out[ $key ][ absint( $k ) ] = absint( $v );
						}
					}
					break;
				case 'update_fields':
					$out[ $key ] = array();
					foreach ( $default as $field => $on ) {
						$out[ $key ][ $field ] = ! empty( $value[ $field ] );
					}
					break;
				case 'description_footer':
					$out[ $key ] = sanitize_textarea_field( $value );
					break;
				case 'price_adjust_value':
					$out[ $key ] = (float) $value;
					break;
				case 'max_images':
					$out[ $key ] = max( 1, min( 20, absint( $value ) ) );
					break;
				case 'max_qty':
					$out[ $key ] = max( 1, min( 999, absint( $value ) ) );
					break;
				case 'poll_minutes':
					$out[ $key ] = max( 5, min( 1440, absint( $value ) ) );
					break;
				default:
					if ( is_bool( $default ) ) {
						$out[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
					} elseif ( is_int( $default ) ) {
						$out[ $key ] = absint( $value );
					} else {
						$out[ $key ] = sanitize_text_field( (string) $value );
					}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Auth / connection data.
	 * ------------------------------------------------------------------ */

	public static function auth() {
		$auth = get_option( self::AUTH_OPTION, array() );
		return wp_parse_args(
			is_array( $auth ) ? $auth : array(),
			array(
				'keystring'     => '',
				'shared_secret' => '',
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
				'user_id'       => 0,
				'shop_id'       => 0,
				'shop_name'     => '',
				'shop_url'      => '',
				'currency'      => '',
				'connected_at'  => 0,
			)
		);
	}

	public static function update_auth( array $values ) {
		$auth = array_merge( self::auth(), $values );
		update_option( self::AUTH_OPTION, $auth, false );
		return $auth;
	}

	public static function has_credentials() {
		$a = self::auth();
		return '' !== $a['keystring'] && '' !== $a['shared_secret'];
	}

	public static function is_connected() {
		$a = self::auth();
		return self::has_credentials() && '' !== $a['refresh_token'] && ! empty( $a['shop_id'] );
	}

	public static function disconnect() {
		self::update_auth(
			array(
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
				'shop_id'       => 0,
				'shop_name'     => '',
				'shop_url'      => '',
				'user_id'       => 0,
				'currency'      => '',
				'connected_at'  => 0,
			)
		);
	}

	public static function shop_id() {
		return (int) self::auth()['shop_id'];
	}
}
