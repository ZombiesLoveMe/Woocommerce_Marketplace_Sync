<?php
/**
 * Plugin Name:       Marketplace Sync for WooCommerce
 * Description:       Sell everywhere from WooCommerce: two-way sync with Etsy and eBay, plus automatic product feeds for Google Shopping, Facebook & Instagram, Pinterest, TikTok and Bing. Uses your own keys — no third-party servers.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Woo Etsy Sync
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-etsy-sync
 * Domain Path:       /languages
 * Update URI:        https://github.com/__GITHUB_REPO__
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.2
 */

defined( 'ABSPATH' ) || exit;

define( 'WES_VERSION', '1.3.0' );
define( 'WES_FILE', __FILE__ );
// GitHub "owner/repo" that updates come from. Filled in automatically by the release workflow;
// you can also set WES_GITHUB_REPO in wp-config.php.
define( 'WES_GITHUB_REPO_DEFAULT', '__GITHUB_REPO__' );
define( 'WES_DIR', plugin_dir_path( __FILE__ ) );
define( 'WES_URL', plugin_dir_url( __FILE__ ) );

// Tiny fallbacks for hosts without the mbstring extension.
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $s, $e = null ) { return strtolower( $s ); } // phpcs:ignore
}
if ( ! function_exists( 'mb_strpos' ) ) {
	function mb_strpos( $h, $n, $o = 0, $e = null ) { return strpos( $h, $n, $o ); } // phpcs:ignore
}

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WooEtsySync\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$name = substr( $class, strlen( $prefix ) );
		$file = WES_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Declare HPOS + cart/checkout blocks compatibility (we never touch orders tables directly).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'WooEtsySync\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WooEtsySync\\Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>Marketplace Sync</strong> needs WooCommerce to be installed and active.', 'woo-etsy-sync' ) ) . '</p></div>';
				}
			);
			return;
		}
		WooEtsySync\Plugin::instance();
	},
	20
);
