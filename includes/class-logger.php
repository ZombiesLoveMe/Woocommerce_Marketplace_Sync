<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log stored in a small custom table (auto-pruned to 30 days).
 */
class Logger {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wes_log';
	}

	public static function log( $level, $message, $context = 'general', $product_id = 0, $listing_id = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => $level,
				'context'    => substr( $context, 0, 30 ),
				'product_id' => (int) $product_id,
				'listing_id' => (int) $listing_id,
				'message'    => wp_strip_all_tags( (string) $message ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	public static function info( $message, $context = 'general', $product_id = 0, $listing_id = 0 ) {
		self::log( 'info', $message, $context, $product_id, $listing_id );
	}

	public static function success( $message, $context = 'general', $product_id = 0, $listing_id = 0 ) {
		self::log( 'success', $message, $context, $product_id, $listing_id );
	}

	public static function warning( $message, $context = 'general', $product_id = 0, $listing_id = 0 ) {
		self::log( 'warning', $message, $context, $product_id, $listing_id );
	}

	public static function error( $message, $context = 'general', $product_id = 0, $listing_id = 0 ) {
		self::log( 'error', $message, $context, $product_id, $listing_id );
	}

	/**
	 * Query log rows.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'level'      => '',
				'product_id' => 0,
				'search'     => '',
				'channel'    => '',
				'page'       => 1,
				'per_page'   => 50,
			)
		);
		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['level'] ) {
			$where[] = 'level = %s';
			$vals[]  = $args['level'];
		}
		if ( $args['product_id'] ) {
			$where[] = 'product_id = %d';
			$vals[]  = (int) $args['product_id'];
		}
		if ( 'ebay' === $args['channel'] ) {
			$where[] = "context LIKE 'ebay%'";
		} elseif ( 'feeds' === $args['channel'] ) {
			$where[] = "context LIKE 'feeds%'";
		} elseif ( 'etsy' === $args['channel'] ) {
			$where[] = "context NOT LIKE 'ebay%' AND context NOT LIKE 'feeds%'";
		}
		if ( $args['search'] ) {
			$where[] = 'message LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$per       = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per;

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $vals ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $vals ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A );
		// phpcs:enable

		foreach ( $rows as &$row ) {
			$row['id']         = (int) $row['id'];
			$row['product_id'] = (int) $row['product_id'];
			$row['listing_id'] = (int) $row['listing_id'];
			$row['product']    = $row['product_id'] ? html_entity_decode( get_the_title( $row['product_id'] ), ENT_QUOTES, 'UTF-8' ) : '';
			$row['created_at'] = mysql2date( 'c', $row['created_at'] . ' +00:00', false );
		}
		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	public static function counts_since( $seconds, $channel = 'etsy' ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$ch    = 'ebay' === $channel ? "context LIKE 'ebay%'" : ( 'feeds' === $channel ? "context LIKE 'feeds%'" : "context NOT LIKE 'ebay%' AND context NOT LIKE 'feeds%'" );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT level, COUNT(*) AS c FROM {$table} WHERE created_at >= %s AND {$ch} GROUP BY level", $since ), ARRAY_A );
		$out  = array( 'info' => 0, 'success' => 0, 'warning' => 0, 'error' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['level'] ] = (int) $r['c'];
		}
		return $out;
	}

	public static function prune( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
