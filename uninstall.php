<?php
/**
 * Removes Woo Etsy Sync data when the plugin is deleted from the Plugins screen.
 * Product ↔ listing links are kept unless WES_REMOVE_ALL_DATA is defined, so reinstalling
 * doesn't lose them. Nothing on Etsy is touched.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'wes_settings', 'wes_auth', 'wes_db_version', 'wes_link_report', 'wes_processed_transactions', 'wes_last_receipt_poll', 'wes_last_poll_summary', 'wes_needs_reconnect', 'wes_ebay_settings', 'wes_ebay_auth', 'wes_ebay_link_report', 'wes_ebay_processed_lines', 'wes_ebay_last_order_poll', 'wes_ebay_last_poll_summary', 'wes_ebay_needs_reconnect', 'wes_ebay_oos_control', 'wes_ebay_wpl_imported', 'wes_migrated_platy', 'wes_feeds', 'wes_feeds_summary' ) as $opt ) {
	delete_option( $opt );
}
foreach ( array( 'shipping', 'readiness', 'sections', 'returns', 'taxonomy' ) as $k ) {
	delete_transient( 'wes_cache_' . $k );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wes_log" ); // phpcs:ignore WordPress.DB

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'woo-etsy-sync' );
}

$wes_up = wp_upload_dir( null, false );
$wes_dir = trailingslashit( $wes_up['basedir'] ) . 'wes-feeds';
if ( is_dir( $wes_dir ) ) {
	foreach ( (array) glob( $wes_dir . '/{,.}*', GLOB_BRACE ) as $wes_f ) {
		if ( is_file( $wes_f ) ) {
			@unlink( $wes_f ); // phpcs:ignore
		}
	}
	@rmdir( $wes_dir ); // phpcs:ignore
}

if ( defined( 'WES_REMOVE_ALL_DATA' ) && WES_REMOVE_ALL_DATA ) {
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wes\\_%'" ); // phpcs:ignore WordPress.DB
}
