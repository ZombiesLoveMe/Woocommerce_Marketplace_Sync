<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Activation / deactivation / DB schema.
 */
class Install {

	const DB_VERSION     = '1';
	const DB_VERSION_KEY = 'wes_db_version';

	public static function activate() {
		self::create_tables();
		add_option( Settings::OPTION, Settings::defaults(), '', false );
	}

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::HOOK_POLL_ORDERS );
			as_unschedule_all_actions( Scheduler::HOOK_RECONCILE );
			as_unschedule_all_actions( Scheduler::HOOK_DAILY );
			as_unschedule_all_actions( Feeds::HOOK_BUILD );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = Logger::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			context varchar(30) NOT NULL DEFAULT 'general',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY product_id (product_id),
			KEY level (level)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}
}
