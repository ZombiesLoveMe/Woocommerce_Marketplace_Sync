<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * REST + product editor bits for shopping feeds.
 */
class Feeds_Admin {

	public static function init() {
		Feeds::init();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( is_admin() ) {
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
			add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk' ), 12 );
			add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
	}

	public static function routes() {
		Rest::route( '/feeds/state', 'GET', array( __CLASS__, 'state' ) );
		Rest::route( '/feeds/(?P<key>[a-z]+)/enable', 'POST', array( __CLASS__, 'enable' ) );
		Rest::route( '/feeds/(?P<key>[a-z]+)/disable', 'POST', array( __CLASS__, 'disable' ) );
		Rest::route( '/feeds/(?P<key>[a-z]+)/new-link', 'POST', array( __CLASS__, 'new_link' ) );
		Rest::route( '/feeds/settings', 'POST', array( __CLASS__, 'settings' ) );
		Rest::route( '/feeds/rebuild', 'POST', array( __CLASS__, 'rebuild' ) );
		Rest::route( '/feeds/preview', 'GET', array( __CLASS__, 'preview' ) );
	}

	public static function state() {
		return Feeds::state();
	}
	public static function enable( \WP_REST_Request $r ) {
		Feeds::enable( (string) $r['key'] );
		return Feeds::state();
	}
	public static function disable( \WP_REST_Request $r ) {
		Feeds::disable( (string) $r['key'] );
		return Feeds::state();
	}
	public static function new_link( \WP_REST_Request $r ) {
		Feeds::new_token( (string) $r['key'] );
		Logger::info( __( 'Made a new private feed link (the old one stops working).', 'woo-etsy-sync' ), 'feeds' );
		return Feeds::state();
	}
	public static function settings( \WP_REST_Request $r ) {
		Feeds::update_settings( (array) $r->get_json_params() );
		return Feeds::state();
	}
	public static function rebuild() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		Feeds::build();
		return Feeds::state();
	}
	public static function preview() {
		return Feeds::preview();
	}

	/* ------------------------------------------------------------------ */

	public static function tab( $tabs ) {
		$tabs['wes_feeds'] = array(
			'label'    => __( 'Shopping feeds', 'woo-etsy-sync' ),
			'target'   => 'wes_feeds_panel',
			'class'    => array( 'hide_if_grouped', 'hide_if_external' ),
			'priority' => 77,
		);
		return $tabs;
	}

	public static function panel() {
		global $post;
		$id = (int) $post->ID;
		echo '<div id="wes_feeds_panel" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		echo '<p class="wes-panel-intro">' . esc_html__( 'Used by Google Shopping, Facebook & Instagram, Pinterest and other product feeds.', 'woo-etsy-sync' ) . '</p>';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_wes_feed_exclude',
				'label'       => __( 'Hide from feeds', 'woo-etsy-sync' ),
				'description' => __( 'Don\'t send this product to shopping/social platforms.', 'woo-etsy-sync' ),
				'value'       => get_post_meta( $id, '_wes_feed_exclude', true ),
				'cbvalue'     => 'yes',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_wes_feed_title',
				'label'       => __( 'Feed title', 'woo-etsy-sync' ),
				'value'       => get_post_meta( $id, '_wes_feed_title', true ),
				'placeholder' => $post->post_title,
				'desc_tip'    => true,
				'description' => __( 'Optional. Up to 150 characters. Variation options are added automatically.', 'woo-etsy-sync' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_wes_feed_google_category',
				'label'       => __( 'Google product category', 'woo-etsy-sync' ),
				'value'       => get_post_meta( $id, '_wes_feed_google_category', true ),
				'placeholder' => __( 'Use category mapping', 'woo-etsy-sync' ),
				'desc_tip'    => true,
				'description' => __( 'Optional, e.g. "Home & Garden > Decor".', 'woo-etsy-sync' ),
			)
		);
		echo '</div></div>';
	}

	public static function save( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		! empty( $_POST['_wes_feed_exclude'] ) ? update_post_meta( $id, '_wes_feed_exclude', 'yes' ) : delete_post_meta( $id, '_wes_feed_exclude' );
		foreach ( array( '_wes_feed_title', '_wes_feed_google_category' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
			'' === $v ? delete_post_meta( $id, $k ) : update_post_meta( $id, $k, $v );
		}
		// phpcs:enable
	}

	public static function bulk( $a ) {
		$a['wes_feed_hide'] = __( 'Hide from shopping feeds', 'woo-etsy-sync' );
		$a['wes_feed_show'] = __( 'Show in shopping feeds', 'woo-etsy-sync' );
		return $a;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'wes_feed_hide', 'wes_feed_show' ), true ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		foreach ( $ids as $id ) {
			'wes_feed_hide' === $action ? update_post_meta( (int) $id, '_wes_feed_exclude', 'yes' ) : delete_post_meta( (int) $id, '_wes_feed_exclude' );
		}
		Feeds::schedule_build( 30 );
		return add_query_arg( 'wes_done', 1, $redirect );
	}
}
