<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * One-click import of links and mappings left behind by the discontinued
 * "Platy Syncer for Etsy" plugin (its database tables survive after it is removed).
 */
class Migrate {

	private static function table( $name ) {
		global $wpdb;
		$t = $wpdb->prefix . $name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
		return $found === $t ? $t : '';
	}

	private static function shop_filter( $table ) {
		$shop = Settings::shop_id();
		return $shop ? array( ' AND shop_id = %s', (string) $shop ) : array( '', null );
	}

	/**
	 * What could be imported (null when there's nothing from the old plugin).
	 */
	public static function detect() {
		global $wpdb;
		$products = self::table( 'plty_etsy_products' );
		if ( ! $products ) {
			return null;
		}
		list( $where, $arg ) = self::shop_filter( $products );
		$sql = "SELECT COUNT(DISTINCT p.post_id) FROM {$products} p JOIN {$wpdb->posts} wp ON wp.ID = p.post_id
			WHERE wp.post_type = 'product' AND p.etsy_id <> '' AND p.etsy_id IS NOT NULL" . $where;
		// phpcs:ignore WordPress.DB
		$links = (int) ( $arg ? $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ) : $wpdb->get_var( $sql ) );

		$maps  = 0;
		$conns = self::table( 'plty_etsy_connections' );
		if ( $conns ) {
			list( $where, $arg ) = self::shop_filter( $conns );
			$sql  = "SELECT COUNT(*) FROM {$conns} WHERE target_id <> ''" . $where;
			$maps = (int) ( $arg ? $wpdb->get_var( $wpdb->prepare( $sql, $arg ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! $links && ! $maps ) {
			return null;
		}
		return array(
			'links'    => $links,
			'mappings' => $maps,
			'done'     => (bool) get_option( 'wes_migrated_platy' ),
		);
	}

	public static function run() {
		global $wpdb;
		$out = array( 'linked' => 0, 'skipped' => 0, 'mappings' => 0 );

		$products = self::table( 'plty_etsy_products' );
		if ( $products ) {
			list( $where, $arg ) = self::shop_filter( $products );
			$sql  = "SELECT p.post_id, p.etsy_id FROM {$products} p JOIN {$wpdb->posts} wp ON wp.ID = p.post_id
				WHERE wp.post_type = 'product' AND wp.post_status <> 'trash' AND p.etsy_id <> '' AND p.etsy_id IS NOT NULL" . $where . ' ORDER BY p.id ASC';
			$rows = $arg ? $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$pid = (int) $row['post_id'];
				$lid = (int) $row['etsy_id'];
				if ( ! $pid || ! $lid || Product_Sync::listing_id( $pid ) || Product_Sync::product_for_listing( $lid ) ) {
					$out['skipped']++;
					continue;
				}
				Product_Sync::link( $pid, $lid, 'platy-import' );
				$out['linked']++;
			}
		}

		$conns = self::table( 'plty_etsy_connections' );
		if ( $conns ) {
			list( $where, $arg ) = self::shop_filter( $conns );
			$sql  = "SELECT source_type, target_type, source_id, target_id, target_name FROM {$conns} WHERE target_id <> ''" . $where;
			$rows = $arg ? $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			$cat_map  = (array) Settings::get( 'category_map', array() );
			$sec_map  = (array) Settings::get( 'section_map', array() );
			$ship_map = (array) Settings::get( 'shipping_class_map', array() );
			foreach ( $rows as $r ) {
				$src = absint( $r['source_id'] );
				$tgt = absint( $r['target_id'] );
				if ( ! $src || ! $tgt ) {
					continue;
				}
				$type = strtolower( $r['target_type'] );
				if ( 'product_cat' === $r['source_type'] && false !== strpos( $type, 'taxonomy' ) && empty( $cat_map[ $src ] ) ) {
					$cat_map[ $src ] = array( 'id' => $tgt, 'label' => (string) $r['target_name'] );
					$out['mappings']++;
				} elseif ( 'product_cat' === $r['source_type'] && false !== strpos( $type, 'section' ) && empty( $sec_map[ $src ] ) ) {
					$sec_map[ $src ] = $tgt;
					$out['mappings']++;
				} elseif ( false !== strpos( $type, 'shipping' ) && 'product_cat' !== $r['source_type'] && empty( $ship_map[ $src ] ) ) {
					$ship_map[ $src ] = $tgt; // Etsy shipping template ids are the same as v3 shipping profile ids.
					$out['mappings']++;
				}
			}
			Settings::update(
				array(
					'category_map'       => $cat_map,
					'section_map'        => $sec_map,
					'shipping_class_map' => $ship_map,
				)
			);
		}

		update_option( 'wes_migrated_platy', time(), false );
		Logger::success( sprintf( __( 'Imported from Platy Syncer: %1$d product links, %2$d category/shipping mappings (%3$d skipped).', 'woo-etsy-sync' ), $out['linked'], $out['mappings'], $out['skipped'] ), 'link' );
		return $out;
	}
}
