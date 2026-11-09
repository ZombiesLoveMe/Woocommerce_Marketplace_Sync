<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything together.
 */
class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Two-letter country code of the shop (WooCommerce → Settings → General). */
	public static function store_country() {
		$c = '';
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$c = WC()->countries->get_base_country();
		}
		if ( ! $c ) {
			$c = strtok( (string) get_option( 'woocommerce_default_country', 'US' ), ':' );
		}
		return strtoupper( $c ? $c : 'US' );
	}

	/** Shop country name, e.g. "United Kingdom (UK)". */
	public static function store_country_name() {
		$code = self::store_country();
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			$all = WC()->countries->get_countries();
			if ( isset( $all[ $code ] ) ) {
				return html_entity_decode( $all[ $code ], ENT_QUOTES, 'UTF-8' );
			}
		}
		return $code;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		GitHub_Updater::init();
		add_action( 'admin_init', array( Install::class, 'maybe_upgrade' ) );
		Scheduler::init();
		Ebay_Scheduler::init();
		Ebay_Admin::init();
		Feeds_Admin::init();
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'rest_api_init', array( Ebay_Rest::class, 'register' ) );
		if ( is_admin() ) {
			Admin::init();
		}

		// WooCommerce → Etsy stock.
		add_action( 'woocommerce_product_set_stock', array( Stock_Sync::class, 'on_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( Stock_Sync::class, 'on_stock_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );

		// Auto-sync edits.
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_variation_saved' ), 20 );

		// Trash / delete.
		add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'woo-etsy-sync', false, dirname( plugin_basename( WES_FILE ) ) . '/languages' );
	}

	public function on_stock_status( $id, $status = '', $product = null ) {
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( $id );
		}
		if ( $product && ! $product->managing_stock() ) {
			Stock_Sync::on_stock_change( $product );
		}
	}

	public function on_product_saved( $product_id, $product = null ) {
		if ( ! Settings::get( 'auto_sync_on_save' ) || ! Settings::is_connected() ) {
			return;
		}
		if ( 'publish' !== get_post_status( $product_id ) || Mapper::is_excluded( $product_id ) || ! Product_Sync::listing_id( $product_id ) ) {
			return;
		}
		Scheduler::queue_sync( array( $product_id ) );
	}

	public function on_variation_saved( $variation_id ) {
		$parent = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent ) {
			$this->on_product_saved( $parent );
		}
	}

	public function on_trash( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) || 'deactivate' !== Settings::get( 'on_trash' ) || ! Settings::is_connected() ) {
			return;
		}
		if ( ! Product_Sync::listing_id( $post_id ) || 'active' !== get_post_meta( $post_id, '_wes_listing_state', true ) ) {
			return; // Drafts / already inactive listings don't need touching.
		}
		try {
			Product_Sync::set_state( $post_id, 'inactive' );
		} catch ( \Exception $e ) {
			Logger::warning( __( 'Product was trashed but the Etsy listing could not be deactivated: ', 'woo-etsy-sync' ) . $e->getMessage(), 'sync', $post_id, Product_Sync::listing_id( $post_id ) );
		}
	}
}
