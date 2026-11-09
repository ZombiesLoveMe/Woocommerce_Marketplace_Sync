<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Cached lookups of shop data (shipping profiles, processing profiles, categories...).
 */
class Cache {

	const KEYS = array( 'shipping', 'readiness', 'sections', 'returns', 'taxonomy' );

	public static function flush() {
		foreach ( self::KEYS as $key ) {
			delete_transient( 'wes_cache_' . $key );
		}
	}

	private static function remember( $key, $ttl, callable $fn, $refresh = false ) {
		$tkey = 'wes_cache_' . $key;
		if ( ! $refresh ) {
			$cached = get_transient( $tkey );
			if ( false !== $cached ) {
				return $cached;
			}
		}
		$value = $fn();
		set_transient( $tkey, $value, $ttl );
		return $value;
	}

	public static function shipping_profiles( $refresh = false ) {
		return self::remember(
			'shipping',
			HOUR_IN_SECONDS * 6,
			function () {
				$res = Etsy_Api::instance()->get_shipping_profiles( Settings::shop_id() );
				$out = array();
				foreach ( $res['results'] ?? array() as $p ) {
					$out[] = array(
						'id'    => (int) $p['shipping_profile_id'],
						'label' => (string) $p['title'],
					);
				}
				return $out;
			},
			$refresh
		);
	}

	public static function readiness_states( $refresh = false ) {
		return self::remember(
			'readiness',
			HOUR_IN_SECONDS * 6,
			function () {
				$res = Etsy_Api::instance()->get_readiness_states( Settings::shop_id() );
				$out = array();
				foreach ( $res['results'] ?? array() as $p ) {
					$type  = ( 'made_to_order' === ( $p['readiness_state'] ?? '' ) ) ? __( 'Made to order', 'woo-etsy-sync' ) : __( 'Ready to ship', 'woo-etsy-sync' );
					$days  = $p['processing_days_display_label'] ?? ( ( $p['min_processing_days'] ?? '?' ) . '–' . ( $p['max_processing_days'] ?? '?' ) . ' days' );
					$out[] = array(
						'id'    => (int) $p['readiness_state_id'],
						'label' => $type . ' · ' . $days,
					);
				}
				return $out;
			},
			$refresh
		);
	}

	public static function sections( $refresh = false ) {
		return self::remember(
			'sections',
			HOUR_IN_SECONDS * 6,
			function () {
				$res = Etsy_Api::instance()->get_sections( Settings::shop_id() );
				$out = array();
				foreach ( $res['results'] ?? array() as $s ) {
					$out[] = array(
						'id'    => (int) $s['shop_section_id'],
						'label' => (string) $s['title'],
					);
				}
				return $out;
			},
			$refresh
		);
	}

	public static function return_policies( $refresh = false ) {
		return self::remember(
			'returns',
			HOUR_IN_SECONDS * 6,
			function () {
				try {
					$res = Etsy_Api::instance()->get_return_policies( Settings::shop_id() );
				} catch ( Api_Error $e ) {
					return array();
				}
				$out = array();
				foreach ( $res['results'] ?? array() as $p ) {
					$label = ! empty( $p['accepts_returns'] ) ? sprintf( __( 'Returns accepted within %d days', 'woo-etsy-sync' ), (int) $p['return_deadline'] ) : __( 'No returns', 'woo-etsy-sync' );
					if ( ! empty( $p['accepts_exchanges'] ) ) {
						$label .= __( ', exchanges accepted', 'woo-etsy-sync' );
					}
					$out[] = array(
						'id'    => (int) $p['return_policy_id'],
						'label' => $label,
					);
				}
				return $out;
			},
			$refresh
		);
	}

	/**
	 * Flattened seller taxonomy: [ { id, path, leaf } ].
	 */
	public static function taxonomy( $refresh = false ) {
		return self::remember(
			'taxonomy',
			WEEK_IN_SECONDS,
			function () {
				$res = Etsy_Api::instance()->get_seller_taxonomy();
				$out = array();
				$walk = function ( $nodes, $prefix ) use ( &$walk, &$out ) {
					foreach ( $nodes as $n ) {
						$path  = $prefix ? $prefix . ' › ' . $n['name'] : $n['name'];
						$kids  = $n['children'] ?? array();
						$out[] = array(
							'id'   => (int) $n['id'],
							'path' => $path,
							'leaf' => empty( $kids ),
						);
						if ( $kids ) {
							$walk( $kids, $path );
						}
					}
				};
				$walk( $res['results'] ?? array(), '' );
				return $out;
			},
			$refresh
		);
	}
}
