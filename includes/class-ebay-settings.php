<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * eBay settings + connection data (kept separate from the Etsy ones).
 */
class Ebay_Settings {

	const OPTION      = 'wes_ebay_settings';
	const AUTH_OPTION = 'wes_ebay_auth';

	/** @var array|null */
	private static $cache = null;

	/** Supported eBay sites: marketplace id => [ site id, currency, label, country, host, language ]. */
	public static function sites() {
		return array(
			'EBAY_US' => array( 0, 'USD', __( 'eBay US (ebay.com)', 'woo-etsy-sync' ), 'US', 'www.ebay.com', 'en_US' ),
			'EBAY_GB' => array( 3, 'GBP', __( 'eBay UK (ebay.co.uk)', 'woo-etsy-sync' ), 'GB', 'www.ebay.co.uk', 'en_GB' ),
			'EBAY_IE' => array( 205, 'EUR', __( 'eBay Ireland (ebay.ie)', 'woo-etsy-sync' ), 'IE', 'www.ebay.ie', 'en_IE' ),
			'EBAY_CA' => array( 2, 'CAD', __( 'eBay Canada (ebay.ca)', 'woo-etsy-sync' ), 'CA', 'www.ebay.ca', 'en_CA' ),
			'EBAY_AU' => array( 15, 'AUD', __( 'eBay Australia (ebay.com.au)', 'woo-etsy-sync' ), 'AU', 'www.ebay.com.au', 'en_AU' ),
			'EBAY_DE' => array( 77, 'EUR', __( 'eBay Germany (ebay.de)', 'woo-etsy-sync' ), 'DE', 'www.ebay.de', 'de_DE' ),
			'EBAY_AT' => array( 16, 'EUR', __( 'eBay Austria (ebay.at)', 'woo-etsy-sync' ), 'AT', 'www.ebay.at', 'de_AT' ),
			'EBAY_CH' => array( 193, 'CHF', __( 'eBay Switzerland (ebay.ch)', 'woo-etsy-sync' ), 'CH', 'www.ebay.ch', 'de_CH' ),
			'EBAY_FR' => array( 71, 'EUR', __( 'eBay France (ebay.fr)', 'woo-etsy-sync' ), 'FR', 'www.ebay.fr', 'fr_FR' ),
			'EBAY_IT' => array( 101, 'EUR', __( 'eBay Italy (ebay.it)', 'woo-etsy-sync' ), 'IT', 'www.ebay.it', 'it_IT' ),
			'EBAY_ES' => array( 186, 'EUR', __( 'eBay Spain (ebay.es)', 'woo-etsy-sync' ), 'ES', 'www.ebay.es', 'es_ES' ),
			'EBAY_NL' => array( 146, 'EUR', __( 'eBay Netherlands (ebay.nl)', 'woo-etsy-sync' ), 'NL', 'www.ebay.nl', 'nl_NL' ),
			'EBAY_PL' => array( 212, 'PLN', __( 'eBay Poland (ebay.pl)', 'woo-etsy-sync' ), 'PL', 'www.ebay.pl', 'pl_PL' ),
		);
	}

	/** The eBay site that best matches the shop's own country (falls back to eBay US). */
	public static function default_marketplace() {
		$country = \WooEtsySync\Plugin::store_country();
		foreach ( self::sites() as $id => $s ) {
			if ( $s[3] === $country ) {
				return $id;
			}
		}
		return 'EBAY_US';
	}

	public static function defaults() {
		return array(
			'marketplace'        => self::default_marketplace(),
			'postcode'           => '',
			'location'           => '',
			'country'            => \WooEtsySync\Plugin::store_country(),
			'default_category'   => 0,
			'default_category_label' => '',
			'category_map'       => array(),   // wc term id => [ id, label ].
			'condition_id'       => 1000,      // New.
			'brand'              => '',
			'mpn'                => 'Does Not Apply',
			'custom_specifics'   => '',        // "Name: Value" per line.
			'use_attributes'     => true,
			'shipping_policy'    => '',
			'return_policy'      => '',
			'payment_policy'     => '',
			'title_template'     => '{title}',
			'description_source' => 'description',
			'description_footer' => '',
			'max_images'         => 12,
			'variation_images'   => true,
			'use_sale_price'     => true,
			'price_adjust_type'  => 'none',
			'price_adjust_value' => 0,
			'price_rounding'     => 'none',
			'untracked_stock_qty' => 10,
			'max_qty'            => 999,
			'sync_stock_to_ebay' => true,
			'sync_stock_from_ebay' => true,
			'poll_minutes'       => 15,
			'out_of_stock_control' => true,
			'auto_sync_on_save'  => true,
			'on_trash'           => 'end',     // end | nothing.
			'update_fields'      => array(
				'title'       => true,
				'description' => true,
				'price'       => true,
				'images'      => true,
				'specifics'   => true,
			),
			'gpsr_enabled'       => false,
			'gpsr_company'       => '',
			'gpsr_street'        => '',
			'gpsr_city'          => '',
			'gpsr_postcode'      => '',
			'gpsr_country'       => \WooEtsySync\Plugin::store_country(),
			'gpsr_email'         => '',
			'gpsr_phone'         => '',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$defaults    = self::defaults();
			self::$cache = array_merge( $defaults, is_array( $saved ) ? $saved : array() );
			self::$cache['update_fields'] = array_merge( $defaults['update_fields'], (array) self::$cache['update_fields'] );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$old    = self::all();
		$merged = array_merge( $old, self::sanitize( $values ) );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
		do_action( 'wes_ebay_settings_updated', self::all(), $old );
		return self::all();
	}

	public static function sanitize( array $values ) {
		$defaults = self::defaults();
		$out      = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$default = $defaults[ $key ];
			switch ( $key ) {
				case 'marketplace':
					$out[ $key ] = isset( self::sites()[ $value ] ) ? $value : self::default_marketplace();
					break;
				case 'price_adjust_type':
					$out[ $key ] = in_array( $value, array( 'none', 'percent', 'fixed' ), true ) ? $value : 'none';
					break;
				case 'price_rounding':
					$out[ $key ] = in_array( $value, array( 'none', '99', 'whole' ), true ) ? $value : 'none';
					break;
				case 'description_source':
					$out[ $key ] = in_array( $value, array( 'description', 'short', 'both' ), true ) ? $value : 'description';
					break;
				case 'on_trash':
					$out[ $key ] = in_array( $value, array( 'end', 'nothing' ), true ) ? $value : 'end';
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
				case 'update_fields':
					$out[ $key ] = array();
					foreach ( $default as $f => $on ) {
						$out[ $key ][ $f ] = ! empty( $value[ $f ] );
					}
					break;
				case 'custom_specifics':
				case 'description_footer':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'shipping_policy':
				case 'return_policy':
				case 'payment_policy':
					$out[ $key ] = preg_replace( '/[^0-9]/', '', (string) $value );
					break;
				case 'price_adjust_value':
					$out[ $key ] = (float) $value;
					break;
				case 'max_images':
					$out[ $key ] = max( 1, min( 24, absint( $value ) ) );
					break;
				case 'max_qty':
					$out[ $key ] = max( 1, min( 9999, absint( $value ) ) );
					break;
				case 'poll_minutes':
					$out[ $key ] = max( 5, min( 1440, absint( $value ) ) );
					break;
				case 'gpsr_email':
					$out[ $key ] = sanitize_email( (string) $value );
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

	public static function site() {
		$sites = self::sites();
		$m     = self::get( 'marketplace', self::default_marketplace() );
		return $sites[ $m ] ?? $sites[ self::default_marketplace() ];
	}

	/* ------------------------------------------------------------------ */

	public static function auth() {
		$a = get_option( self::AUTH_OPTION, array() );
		return wp_parse_args(
			is_array( $a ) ? $a : array(),
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'runame'         => '',
				'sandbox'        => false,
				'access_token'   => '',
				'expires_at'     => 0,
				'refresh_token'  => '',
				'refresh_expires_at' => 0,
				'app_token'      => '',
				'app_expires_at' => 0,
				'user_id'        => '',
				'connected_at'   => 0,
				'verification_token' => '',
			)
		);
	}

	public static function update_auth( array $values ) {
		$a = array_merge( self::auth(), $values );
		update_option( self::AUTH_OPTION, $a, false );
		return $a;
	}

	public static function has_credentials() {
		$a = self::auth();
		return '' !== $a['client_id'] && '' !== $a['client_secret'] && '' !== $a['runame'];
	}

	public static function is_connected() {
		$a = self::auth();
		return self::has_credentials() && '' !== $a['refresh_token'];
	}

	public static function disconnect() {
		self::update_auth(
			array(
				'access_token'  => '',
				'expires_at'    => 0,
				'refresh_token' => '',
				'refresh_expires_at' => 0,
				'user_id'       => '',
				'connected_at'  => 0,
			)
		);
	}

	public static function verification_token() {
		$a = self::auth();
		if ( '' === $a['verification_token'] ) {
			$a = self::update_auth( array( 'verification_token' => 'wes_' . wp_generate_password( 40, false ) ) );
		}
		return $a['verification_token'];
	}

	/** True when WP-Lister for eBay is active (it would double-handle stock). */
	public static function wplister_active() {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $plugin ) {
			if ( false !== stripos( $plugin, 'wp-lister' ) && false !== stripos( $plugin, 'ebay' ) ) {
				return true;
			}
		}
		return defined( 'WPLISTER_VERSION' ) || class_exists( 'WPL_WPLister' );
	}
}
