<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Product feeds for shopping & social platforms (Google, Facebook/Instagram, Pinterest,
 * TikTok, Microsoft, and any other site that accepts a Google-format feed).
 *
 * Connecting = copy a private feed URL into the platform. Buyers check out on your own
 * website, so WooCommerce stays the single source of stock – no two-way sync needed.
 */
class Feeds {

	const OPTION     = 'wes_feeds';
	const HOOK_BUILD = 'wes_feeds_build';
	const DIR        = 'wes-feeds';

	/** Platform catalogue: what the user sees in "Add a platform". */
	public static function platforms() {
		$cur     = get_woocommerce_currency();
		$country = Plugin::store_country_name();
		return array(
			'google'    => array(
				'label'   => __( 'Google Shopping', 'woo-etsy-sync' ),
				'short'   => __( 'Free listings & Shopping ads on Google Search, Images and YouTube.', 'woo-etsy-sync' ),
				'color'   => '#4285f4',
				'open'    => 'https://merchants.google.com/',
				'ua'      => '/google|feedfetcher/i',
				'refresh' => __( 'Daily', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'Open Google Merchant Center and sign in (it\'s free). If it asks, verify your website – the Site Kit or Google Listings & Ads plugin can do this in one click.', 'woo-etsy-sync' ),
					__( 'Go to <strong>Products → Add products → Add products from a file</strong>.', 'woo-etsy-sync' ),
					__( 'Choose <strong>Add a file link</strong>, paste your feed link, and set it to fetch <strong>daily</strong>.', 'woo-etsy-sync' ),
					__( 'Click <strong>Continue</strong>. Google checks the feed within a few hours – this page shows a ✓ once Google has collected it.', 'woo-etsy-sync' ),
				),
				'conflict' => array( 'google-listings-and-ads', __( 'Google Listings & Ads', 'woo-etsy-sync' ), __( 'already sends products to Google. Use one or the other, or Google will show each product twice.', 'woo-etsy-sync' ) ),
			),
			'meta'      => array(
				'label'   => __( 'Facebook & Instagram', 'woo-etsy-sync' ),
				'short'   => __( 'Shop tabs, product tags and catalogue ads on Facebook and Instagram.', 'woo-etsy-sync' ),
				'color'   => '#0866ff',
				'open'    => 'https://business.facebook.com/commerce/',
				'ua'      => '/facebook|meta-external/i',
				'refresh' => __( 'Hourly', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'Open <strong>Meta Commerce Manager</strong> and pick (or create) your catalogue.', 'woo-etsy-sync' ),
					__( 'Go to <strong>Catalogue → Data sources → Add items → Data feed</strong>.', 'woo-etsy-sync' ),
					/* translators: %s: currency code */
					sprintf( __( 'Choose <strong>Use a URL – scheduled feed</strong>, paste your feed link, and set it to update <strong>every hour</strong>. Currency: %s.', 'woo-etsy-sync' ), $cur ),
					__( 'Click <strong>Upload</strong>. Products appear in your Facebook and Instagram shop once Meta has reviewed them.', 'woo-etsy-sync' ),
				),
				'conflict' => array( 'facebook-for-woocommerce', __( 'Facebook for WooCommerce', 'woo-etsy-sync' ), __( 'already sends products to Meta. Turn off its product sync (or don\'t use this feed) so your catalogue doesn\'t get duplicates.', 'woo-etsy-sync' ) ),
			),
			'pinterest' => array(
				'label'   => __( 'Pinterest', 'woo-etsy-sync' ),
				'short'   => __( 'Product Pins and shopping ads on Pinterest.', 'woo-etsy-sync' ),
				'color'   => '#e60023',
				'open'    => 'https://www.pinterest.com/business/catalogs/',
				'ua'      => '/pinterest/i',
				'refresh' => __( 'Daily', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'Use a Pinterest <strong>business</strong> account and claim your website (Settings → Claimed accounts).', 'woo-etsy-sync' ),
					__( 'Open <strong>Catalogues → Create catalogue → Data source</strong>.', 'woo-etsy-sync' ),
					/* translators: 1: currency code, 2: country name */
					sprintf( __( 'Paste your feed link, choose format <strong>XML</strong>, currency <strong>%1$s</strong> and country <strong>%2$s</strong>.', 'woo-etsy-sync' ), $cur, $country ),
					__( 'Pinterest checks the feed and turns your products into shoppable Pins (usually within 24 hours).', 'woo-etsy-sync' ),
				),
				'conflict' => array( 'pinterest-for-woocommerce', __( 'Pinterest for WooCommerce', 'woo-etsy-sync' ), __( 'already sends products to Pinterest.', 'woo-etsy-sync' ) ),
			),
			'tiktok'    => array(
				'label'   => __( 'TikTok catalogue', 'woo-etsy-sync' ),
				'short'   => __( 'Product catalogue for TikTok shopping ads. (TikTok Shop itself uses its own seller centre.)', 'woo-etsy-sync' ),
				'color'   => '#111111',
				'open'    => 'https://ads.tiktok.com/i18n/catalog',
				'ua'      => '/tiktok|bytespider|bytedance/i',
				'refresh' => __( 'Daily', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'Open <strong>TikTok Ads Manager → Assets → Catalogues</strong> and create a catalogue (E-commerce).', 'woo-etsy-sync' ),
					__( 'Choose <strong>Add products → Data feed → Scheduled feed</strong>.', 'woo-etsy-sync' ),
					__( 'Paste your feed link and set it to update <strong>daily</strong>.', 'woo-etsy-sync' ),
					__( 'TikTok reviews the products; approved ones can be used in Shopping ads.', 'woo-etsy-sync' ),
				),
				'conflict' => array( 'tiktok', __( 'the TikTok plugin', 'woo-etsy-sync' ), __( 'may already send products to TikTok.', 'woo-etsy-sync' ) ),
			),
			'microsoft' => array(
				'label'   => __( 'Microsoft Shopping (Bing)', 'woo-etsy-sync' ),
				'short'   => __( 'Free listings and Shopping ads on Bing, Edge and Copilot.', 'woo-etsy-sync' ),
				'color'   => '#00a4ef',
				'open'    => 'https://ads.microsoft.com/',
				'ua'      => '/bing|microsoft|msnbot/i',
				'refresh' => __( 'Daily', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'In Microsoft Advertising, open <strong>Tools → Microsoft Merchant Center</strong> and create a store (verify your website).', 'woo-etsy-sync' ),
					/* translators: %s: country name */
					sprintf( __( 'Go to <strong>Catalogues → Feeds → Create feed</strong> and choose country %s.', 'woo-etsy-sync' ), $country ),
					__( 'Choose <strong>Scheduled download</strong>, paste your feed link, daily.', 'woo-etsy-sync' ),
					__( 'Tip: if you already use Google Merchant Center, Microsoft can import from it instead.', 'woo-etsy-sync' ),
				),
				'conflict' => null,
			),
			'other'     => array(
				'label'   => __( 'Any other site', 'woo-etsy-sync' ),
				'short'   => __( 'Comparison sites, marketplaces and apps that accept a Google-format product feed (XML or CSV).', 'woo-etsy-sync' ),
				'color'   => '#6b7280',
				'open'    => '',
				'ua'      => '/./',
				'refresh' => __( 'As often as the site allows', 'woo-etsy-sync' ),
				'steps'   => array(
					__( 'Look in the other site\'s seller settings for “product feed”, “data feed”, “catalogue URL” or “import from URL”.', 'woo-etsy-sync' ),
					__( 'Paste the XML link below. If it wants a spreadsheet instead, use the CSV link.', 'woo-etsy-sync' ),
					__( 'If it asks which format: choose <strong>Google Shopping / Google Merchant</strong>.', 'woo-etsy-sync' ),
				),
				'conflict' => null,
			),
		);
	}

	public static function defaults() {
		return array(
			'platforms'         => array(),   // key => [ enabled, token, created, last_fetch, fetches, price_adjust ].
			'brand'             => '',
			'condition'         => 'new',
			'variations'        => 'each',    // each | parent.
			'include_out_of_stock' => true,
			'google_category'   => '',
			'category_map'      => array(),   // wc term id => google category text.
			'description_source' => 'description',
			'exclude_categories' => array(),
		);
	}

	public static function all() {
		$o = get_option( self::OPTION, array() );
		$o = array_merge( self::defaults(), is_array( $o ) ? $o : array() );
		foreach ( $o['platforms'] as $k => $p ) {
			if ( ! isset( self::platforms()[ $k ] ) ) {
				unset( $o['platforms'][ $k ] );
			}
		}
		return $o;
	}

	public static function save( array $o ) {
		update_option( self::OPTION, $o, false );
	}

	public static function update_settings( array $in ) {
		$o = self::all();
		foreach ( array( 'brand', 'google_category' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$o[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $in['condition'] ) ) {
			$o['condition'] = in_array( $in['condition'], array( 'new', 'refurbished', 'used' ), true ) ? $in['condition'] : 'new';
		}
		if ( isset( $in['variations'] ) ) {
			$o['variations'] = 'parent' === $in['variations'] ? 'parent' : 'each';
		}
		if ( isset( $in['description_source'] ) ) {
			$o['description_source'] = in_array( $in['description_source'], array( 'description', 'short' ), true ) ? $in['description_source'] : 'description';
		}
		if ( isset( $in['include_out_of_stock'] ) ) {
			$o['include_out_of_stock'] = filter_var( $in['include_out_of_stock'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $in['category_map'] ) ) {
			$o['category_map'] = array();
			foreach ( (array) $in['category_map'] as $tid => $txt ) {
				$txt = sanitize_text_field( (string) $txt );
				if ( absint( $tid ) && '' !== $txt ) {
					$o['category_map'][ absint( $tid ) ] = $txt;
				}
			}
		}
		if ( isset( $in['exclude_categories'] ) ) {
			$o['exclude_categories'] = array_values( array_filter( array_map( 'absint', (array) $in['exclude_categories'] ) ) );
		}
		if ( isset( $in['platform_options'] ) && is_array( $in['platform_options'] ) ) {
			foreach ( $in['platform_options'] as $k => $opts ) {
				if ( isset( $o['platforms'][ $k ] ) ) {
					$o['platforms'][ $k ]['price_adjust'] = (float) ( $opts['price_adjust'] ?? 0 );
				}
			}
		}
		self::save( $o );
		self::schedule_build( 5 );
		return $o;
	}

	public static function enable( $key ) {
		if ( ! isset( self::platforms()[ $key ] ) ) {
			throw new Api_Error( __( 'Unknown platform.', 'woo-etsy-sync' ) );
		}
		$o = self::all();
		if ( empty( $o['platforms'][ $key ] ) ) {
			$o['platforms'][ $key ] = array(
				'enabled'      => true,
				'token'        => wp_generate_password( 24, false ),
				'created'      => time(),
				'last_fetch'   => 0,
				'last_agent'   => '',
				'fetches'      => 0,
				'price_adjust' => 0,
			);
		} else {
			$o['platforms'][ $key ]['enabled'] = true;
		}
		if ( '' === $o['brand'] ) {
			$o['brand'] = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
		}
		self::save( $o );
		Logger::info( sprintf( __( 'Turned on the %s feed.', 'woo-etsy-sync' ), self::platforms()[ $key ]['label'] ), 'feeds' );
		if ( ! file_exists( self::file_path( 'xml', $key ) ) ) {
			self::build();
		}
		Scheduler::enqueue( self::HOOK_BUILD, array() );
		return $o;
	}

	public static function disable( $key ) {
		$o = self::all();
		if ( isset( $o['platforms'][ $key ] ) ) {
			$o['platforms'][ $key ]['enabled'] = false;
			self::save( $o );
			Logger::info( sprintf( __( 'Turned off the %s feed.', 'woo-etsy-sync' ), self::platforms()[ $key ]['label'] ), 'feeds' );
		}
		return $o;
	}

	public static function new_token( $key ) {
		$o = self::all();
		if ( isset( $o['platforms'][ $key ] ) ) {
			$o['platforms'][ $key ]['token'] = wp_generate_password( 24, false );
			unset( $o['platforms'][ $key ]['platform_fetch'] );
			self::save( $o );
		}
		return $o;
	}

	public static function url( $key, $format = 'xml' ) {
		$o = self::all();
		if ( empty( $o['platforms'][ $key ]['token'] ) ) {
			return '';
		}
		$args = array(
			'wes_feed' => $key,
			'key'      => $o['platforms'][ $key ]['token'],
		);
		if ( 'csv' === $format ) {
			$args['format'] = 'csv';
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function any_enabled() {
		foreach ( self::all()['platforms'] as $p ) {
			if ( ! empty( $p['enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Hooks.
	 * ------------------------------------------------------------------ */

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_serve' ), 1 ); // After WooCommerce registers products/categories.
		add_action( self::HOOK_BUILD, array( __CLASS__, 'build' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 22 );
		foreach ( array( 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'trashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_change' ), 30 );
		}
	}

	public static function ensure_schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! self::any_enabled() ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK_BUILD, array(), Scheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::HOOK_BUILD, array(), Scheduler::GROUP );
		}
	}

	public static function on_change() {
		if ( self::any_enabled() ) {
			self::schedule_build( 120 );
		}
	}

	/** Rebuild soon, collapsing bursts of edits into one rebuild. */
	public static function schedule_build( $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( self::HOOK_BUILD, array(), Scheduler::GROUP );
		if ( false === $next || ( is_int( $next ) && $next > time() + $delay + 60 ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_BUILD, array(), Scheduler::GROUP );
		}
	}

	/* ---------------------------------------------------------------------
	 * Serving.
	 * ------------------------------------------------------------------ */

	public static function maybe_serve() {
		if ( empty( $_GET['wes_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key   = sanitize_key( wp_unslash( $_GET['wes_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$csv   = isset( $_GET['format'] ) && 'csv' === $_GET['format']; // phpcs:ignore WordPress.Security.NonceVerification
		$o     = self::all();
		$p     = $o['platforms'][ $key ] ?? null;
		if ( ! $p || empty( $p['enabled'] ) || ! hash_equals( (string) $p['token'], $token ) ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'Feed not found.', 'woo-etsy-sync' );
			exit;
		}
		$format = $csv ? 'csv' : 'xml';
		$file   = self::file_path( $format, $key );
		if ( ! file_exists( $file ) || filemtime( $file ) < time() - 3 * HOUR_IN_SECONDS ) {
			self::build();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Stop page-cache plugins from caching the feed.
		}
		// Record that the platform has collected the feed.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$o['platforms'][ $key ]['last_fetch'] = time();
		$o['platforms'][ $key ]['last_agent'] = substr( $ua, 0, 160 );
		$o['platforms'][ $key ]['fetches']    = (int) $p['fetches'] + 1;
		// A fetch counts as "collected by the platform" when it comes from that platform's robot, or from
		// any non-browser client. Opening the link yourself in a browser doesn't count.
		$plats = self::platforms();
		$is_browser = (bool) preg_match( '/^Mozilla\//', $ua ) && ! preg_match( '/bot|crawl|fetch|spider|externalhit|google|facebook|meta-|pinterest|bing|tiktok|bytedance/i', $ua );
		$robot      = '' !== $ua && ! $is_browser && ! preg_match( '/^(curl|wget)/i', $ua );
		if ( $robot || ( 'other' !== $key && preg_match( $plats[ $key ]['ua'], $ua ) ) ) {
			$o['platforms'][ $key ]['platform_fetch'] = time();
		}
		self::save( $o );
		nocache_headers();
		header( 'Content-Type: ' . ( $csv ? 'text/csv' : 'application/xml' ) . '; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		if ( $csv ) {
			header( 'Content-Disposition: inline; filename="products.csv"' );
		}
		if ( file_exists( $file ) ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}
		// Uploads folder isn't writable: build the feed straight into the response.
		$items = array_map(
			function ( $i ) use ( $p ) {
				return self::adjust( $i, (float) ( $p['price_adjust'] ?? 0 ) );
			},
			self::items()['items']
		);
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		$csv ? self::stream_csv( $out, $items ) : self::stream_xml( $out, $items, self::platforms()[ $key ]['label'] );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Building.
	 * ------------------------------------------------------------------ */

	private static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** Each platform gets its own file (price adjustments can differ). */
	public static function file_path( $format = 'xml', $key = 'base' ) {
		$secret = substr( wp_hash( 'wes-feed-' . $key ), 0, 12 );
		return self::dir() . '/' . $key . '-' . $secret . '.' . $format;
	}

	private static function text( $html, $max ) {
		$t = Mapper::html_to_text( (string) $html );
		$t = preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $t );
		$t = trim( preg_replace( "/[ \t]+/", ' ', $t ) );
		return mb_strlen( $t ) > $max ? rtrim( mb_substr( $t, 0, $max - 1 ) ) . '…' : $t;
	}

	private static function category_path( $term_id ) {
		$names = array();
		foreach ( array_reverse( get_ancestors( $term_id, 'product_cat' ) ) as $a ) {
			$t = get_term( $a, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		$t = get_term( $term_id, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
		}
		return implode( ' > ', $names );
	}

	private static function price_str( $amount ) {
		$dp = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 4, (int) wc_get_price_decimals() ) ) : 2;
		return number_format( (float) $amount, $dp, '.', '' ) . ' ' . get_woocommerce_currency();
	}

	/** WooCommerce weight unit in the form shopping sites expect (kg, g, lb, oz). */
	private static function weight_unit() {
		$u = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
		return 'lbs' === $u ? 'lb' : $u;
	}

	/**
	 * Build every feed item once. Returns [ items, warnings, counts ].
	 */
	public static function items() {
		$o        = self::all();
		$excluded = array_flip( $o['exclude_categories'] );
		$items    = array();
		$warn     = array();
		$skipped  = 0;
		$ids      = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p || $p->is_type( array( 'grouped', 'external' ) ) || 'yes' === get_post_meta( $pid, '_wes_feed_exclude', true ) ) {
				$skipped++;
				continue;
			}
			if ( method_exists( $p, 'get_catalog_visibility' ) && 'hidden' === $p->get_catalog_visibility() ) {
				$skipped++;
				continue;
			}
			$cats = (array) $p->get_category_ids();
			if ( array_intersect_key( array_flip( $cats ), $excluded ) ) {
				$skipped++;
				continue;
			}
			$base = self::base_fields( $p, $cats, $o );
			if ( ! $base['image_link'] ) {
				$warn[] = array( 'id' => $pid, 'name' => $p->get_name(), 'msg' => __( 'No product photo – most platforms reject products without one.', 'woo-etsy-sync' ) );
			}
			if ( $p->is_type( 'variable' ) && 'each' === $o['variations'] ) {
				$attrs = Mapper::variation_attributes( $p );
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v || 'publish' !== $v->get_status() || '' === $v->get_price() ) {
						continue;
					}
					$item = self::variation_fields( $base, $p, $v, $attrs );
					if ( ! $o['include_out_of_stock'] && 'out_of_stock' === $item['availability'] ) {
						continue;
					}
					$items[] = $item;
				}
			} else {
				$item = $base;
				$item['id'] = $p->get_sku() ? $p->get_sku() : 'wc-' . $pid;
				self::stock_price( $item, $p );
				if ( '' === $p->get_price() ) {
					$warn[] = array( 'id' => $pid, 'name' => $p->get_name(), 'msg' => __( 'No price, so it was left out.', 'woo-etsy-sync' ) );
					continue;
				}
				if ( ! $o['include_out_of_stock'] && 'out_of_stock' === $item['availability'] ) {
					continue;
				}
				$items[] = $item;
			}
		}
		return array(
			'items'    => apply_filters( 'wes_feed_items', $items ),
			'warnings' => $warn,
			'products' => count( $ids ) - $skipped,
			'skipped'  => $skipped,
		);
	}

	private static function base_fields( \WC_Product $p, array $cats, array $o ) {
		$pid    = $p->get_id();
		$title  = (string) get_post_meta( $pid, '_wes_feed_title', true );
		$title  = '' !== $title ? $title : $p->get_name();
		$desc   = 'short' === $o['description_source'] ? $p->get_short_description() : $p->get_description();
		$desc   = '' !== trim( wp_strip_all_tags( (string) $desc ) ) ? $desc : ( $p->get_short_description() ? $p->get_short_description() : $p->get_name() );
		$images = array();
		if ( $p->get_image_id() ) {
			$images[] = wp_get_attachment_url( $p->get_image_id() );
		}
		foreach ( $p->get_gallery_image_ids() as $g ) {
			$images[] = wp_get_attachment_url( $g );
		}
		$images  = array_values( array_unique( array_filter( $images ) ) );
		$gcat    = (string) get_post_meta( $pid, '_wes_feed_google_category', true );
		$ptype   = '';
		$deepest = -1;
		foreach ( $cats as $c ) {
			$d = count( get_ancestors( $c, 'product_cat' ) );
			if ( $d > $deepest ) {
				$deepest = $d;
				$ptype   = self::category_path( $c );
				if ( '' === $gcat ) {
					foreach ( array_merge( array( $c ), get_ancestors( $c, 'product_cat' ) ) as $t ) {
						if ( ! empty( $o['category_map'][ $t ] ) ) {
							$gcat = $o['category_map'][ $t ];
							break;
						}
					}
				}
			}
		}
		$gtin   = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
		$weight = (float) $p->get_weight();
		return array(
			'id'                      => '',
			'item_group_id'           => '',
			'title'                   => mb_substr( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ), 0, 150 ),
			'description'             => self::text( $desc, 5000 ),
			'link'                    => get_permalink( $pid ),
			'image_link'              => $images ? set_url_scheme( $images[0], 'https' ) : '',
			'additional_image_link'   => array_map(
				function ( $u ) {
					return set_url_scheme( $u, 'https' );
				},
				array_slice( $images, 1, 10 )
			),
			'availability'            => 'in_stock',
			'price'                   => '',
			'sale_price'              => '',
			'brand'                   => $o['brand'],
			'condition'               => $o['condition'],
			'gtin'                    => $gtin,
			'identifier_exists'       => $gtin ? '' : 'no',
			'google_product_category' => '' !== $gcat ? $gcat : $o['google_category'],
			'product_type'            => $ptype,
			'shipping_weight'         => $weight > 0 ? $weight . ' ' . self::weight_unit() : '',
			'color'                   => '',
			'size'                    => '',
			'quantity'                => '',
		);
	}

	private static function stock_price( array &$item, \WC_Product $p ) {
		$status = $p->get_stock_status();
		$item['availability'] = 'outofstock' === $status ? 'out_of_stock' : ( 'onbackorder' === $status ? 'backorder' : 'in_stock' );
		if ( $p->managing_stock() && null !== $p->get_stock_quantity() ) {
			$item['quantity'] = max( 0, (int) $p->get_stock_quantity() );
			if ( $item['quantity'] <= 0 && ! $p->backorders_allowed() ) {
				$item['availability'] = 'out_of_stock';
			}
		}
		// Shopping sites in the US and Canada show prices before tax; everywhere else prices include tax.
		$fn      = in_array( Plugin::store_country(), array( 'US', 'CA' ), true ) && function_exists( 'wc_get_price_excluding_tax' ) ? 'wc_get_price_excluding_tax' : 'wc_get_price_including_tax';
		$fn      = apply_filters( 'wes_feed_price_function', $fn, $p );
		$regular = (float) call_user_func( $fn, $p, array( 'price' => $p->get_regular_price() ) );
		$active  = (float) call_user_func( $fn, $p, array( 'price' => $p->get_price() ) );
		if ( $regular <= 0 ) {
			$regular = $active;
		}
		$item['price']      = $regular > 0 ? self::price_str( $regular ) : '';
		$item['sale_price'] = ( $active > 0 && $active < $regular ) ? self::price_str( $active ) : '';
	}

	private static function variation_fields( array $base, \WC_Product $p, \WC_Product $v, array $attrs ) {
		$item                  = $base;
		$item['id']            = $v->get_sku() ? $v->get_sku() : 'wc-' . $v->get_id();
		$item['item_group_id'] = $p->get_sku() ? $p->get_sku() : 'wc-' . $p->get_id();
		$item['link']          = $v->get_permalink();
		$img                   = (int) $v->get_image_id( 'edit' );
		if ( $img ) {
			$u = wp_get_attachment_url( $img );
			if ( $u ) {
				$item['image_link'] = set_url_scheme( $u, 'https' );
			}
		}
		$raw     = $v->get_attributes();
		$colours = array();
		$labels  = array();
		foreach ( $attrs as $k => $a ) {
			$slug = $raw[ str_replace( 'attribute_', '', $k ) ] ?? '';
			if ( '' === $slug ) {
				continue;
			}
			$val = Mapper::option_name( $a, $slug );
			$lab = strtolower( $a['label'] );
			if ( false !== strpos( $lab, 'colour' ) || false !== strpos( $lab, 'color' ) ) {
				$colours[] = $val;
			} elseif ( false !== strpos( $lab, 'size' ) ) {
				$item['size'] = $val;
			}
			$labels[] = ( count( $attrs ) > 1 ? $a['label'] . ': ' : '' ) . $val;
		}
		if ( $colours ) {
			$item['color'] = implode( '/', array_slice( $colours, 0, 3 ) );
		}
		if ( $labels ) {
			$item['title'] = mb_substr( $base['title'] . ' - ' . implode( ', ', $labels ), 0, 150 );
		}
		$gtin = method_exists( $v, 'get_global_unique_id' ) ? (string) $v->get_global_unique_id() : '';
		if ( $gtin ) {
			$item['gtin']              = $gtin;
			$item['identifier_exists'] = '';
		}
		$w = (float) $v->get_weight();
		if ( $w > 0 ) {
			$item['shipping_weight'] = $w . ' ' . self::weight_unit();
		}
		self::stock_price( $item, $v );
		return $item;
	}

	private static function adjust( array $item, $pct ) {
		if ( ! $pct ) {
			return $item;
		}
		foreach ( array( 'price', 'sale_price' ) as $k ) {
			if ( '' !== $item[ $k ] ) {
				$amount     = (float) $item[ $k ] * ( 1 + $pct / 100 );
				$item[ $k ] = self::price_str( $amount );
			}
		}
		return $item;
	}

	public static function build() {
		$o = self::all();
		if ( ! self::any_enabled() ) {
			return null;
		}
		$t0 = microtime( true );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$data  = self::items();
		$error = '';
		$keys = array_keys( array_filter( $o['platforms'], function ( $p ) { return ! empty( $p['enabled'] ); } ) );
		foreach ( $keys as $key ) {
			$pct   = (float) ( $o['platforms'][ $key ]['price_adjust'] ?? 0 );
			$items = array_map(
				function ( $i ) use ( $pct ) {
					return self::adjust( $i, $pct );
				},
				$data['items']
			);
			$ok = self::write_file( self::file_path( 'xml', $key ), 'xml', $items, self::platforms()[ $key ]['label'] )
				&& self::write_file( self::file_path( 'csv', $key ), 'csv', $items, '' );
			if ( ! $ok ) {
				/* translators: %s: folder path */
				$error = sprintf( __( 'The feed files couldn\'t be saved in %s, so feeds are built each time a platform collects them (slower, but they still work). Ask your host to make the uploads folder writable.', 'woo-etsy-sync' ), self::dir() );
			}
		}
		$summary = array(
			'at'       => time(),
			'items'    => count( $data['items'] ),
			'products' => $data['products'],
			'skipped'  => $data['skipped'],
			'warnings' => array_slice( $data['warnings'], 0, 50 ),
			'seconds'  => round( microtime( true ) - $t0, 1 ),
			'error'    => $error,
		);
		update_option( 'wes_feeds_summary', $summary, false );
		return $summary;
	}

	private static function x( $s ) {
		// Remove characters that aren't allowed in XML 1.0 (they make the whole feed unreadable).
		$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s );
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** Write a feed to disk via a temp file (so platforms never see a half-written feed). */
	private static function write_file( $path, $format, array $items, $label ) {
		if ( ! wp_mkdir_p( dirname( $path ) ) || ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		$tmp = $path . '.tmp';
		$fh  = @fopen( $tmp, 'w' ); // phpcs:ignore
		if ( ! $fh ) {
			return false;
		}
		'csv' === $format ? self::stream_csv( $fh, $items ) : self::stream_xml( $fh, $items, $label );
		fclose( $fh ); // phpcs:ignore
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@copy( $tmp, $path ); // phpcs:ignore -- Some Windows hosts can't rename over an existing file.
			@unlink( $tmp ); // phpcs:ignore
		}
		return file_exists( $path );
	}

	private static function stream_xml( $fh, array $items, $label ) {
		fwrite( $fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' ); // phpcs:ignore
		fwrite( $fh, '<title>' . self::x( get_bloginfo( 'name' ) ) . '</title><link>' . self::x( home_url( '/' ) ) . '</link><description>' . self::x( $label ) . "</description>\n" ); // phpcs:ignore
		foreach ( $items as $i ) {
			$out = '<item>';
			foreach ( $i as $k => $v ) {
				if ( 'quantity' === $k ) {
					continue; // Internal only (not a Google attribute).
				}
				$tag = 'g:' . $k;
				if ( is_array( $v ) ) {
					foreach ( $v as $vv ) {
						$out .= "<{$tag}>" . self::x( $vv ) . "</{$tag}>";
					}
				} elseif ( '' !== (string) $v ) {
					$out .= "<{$tag}>" . self::x( $v ) . "</{$tag}>";
				}
			}
			fwrite( $fh, $out . "</item>\n" ); // phpcs:ignore
		}
		fwrite( $fh, '</channel></rss>' ); // phpcs:ignore
	}

	private static function stream_csv( $fh, array $items ) {
		$cols = array( 'id', 'item_group_id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability', 'price', 'sale_price', 'brand', 'condition', 'gtin', 'identifier_exists', 'google_product_category', 'product_type', 'shipping_weight', 'color', 'size' );
		fputcsv( $fh, $cols );
		foreach ( $items as $i ) {
			$row = array();
			foreach ( $cols as $c ) {
				$row[] = is_array( $i[ $c ] ?? '' ) ? implode( ',', $i[ $c ] ) : ( $i[ $c ] ?? '' );
			}
			fputcsv( $fh, $row );
		}
	}

	/* ---------------------------------------------------------------------
	 * State for the admin screens.
	 * ------------------------------------------------------------------ */

	public static function installed_conflicts() {
		$active = implode( ' ', (array) get_option( 'active_plugins', array() ) );
		$out    = array();
		foreach ( self::platforms() as $k => $p ) {
			if ( $p['conflict'] && false !== stripos( $active, $p['conflict'][0] ) ) {
				$out[ $k ] = $p['conflict'][1] . ' ' . $p['conflict'][2];
			}
		}
		return $out;
	}

	public static function state() {
		$o   = self::all();
		$out = array();
		foreach ( self::platforms() as $k => $p ) {
			$mine    = $o['platforms'][ $k ] ?? null;
			$fetched = $mine && ! empty( $mine['platform_fetch'] );
			$out[]   = array(
				'key'        => $k,
				'label'      => $p['label'],
				'short'      => $p['short'],
				'color'      => $p['color'],
				'open'       => $p['open'],
				'refresh'    => $p['refresh'],
				'steps'      => $p['steps'],
				'enabled'    => (bool) ( $mine['enabled'] ?? false ),
				'url'        => $mine ? self::url( $k ) : '',
				'csv_url'    => $mine ? self::url( $k, 'csv' ) : '',
				'last_fetch' => (int) ( ! empty( $mine['platform_fetch'] ) ? $mine['platform_fetch'] : ( $mine['last_fetch'] ?? 0 ) ),
				'fetched_by_platform' => (bool) $fetched,
				'fetches'    => (int) ( $mine['fetches'] ?? 0 ),
				'price_adjust' => (float) ( $mine['price_adjust'] ?? 0 ),
			);
		}
		return array(
			'platforms' => $out,
			'settings'  => array_diff_key( $o, array( 'platforms' => 1 ) ),
			'summary'   => get_option( 'wes_feeds_summary', null ),
			'conflicts' => self::installed_conflicts(),
			'currency'  => get_woocommerce_currency(),
			'https'     => 0 === strpos( home_url(), 'https://' ),
		);
	}

	/** First few items, for the "what will they see" preview. */
	public static function preview( $n = 12 ) {
		$d = self::items();
		return array(
			'items'    => array_slice( $d['items'], 0, $n ),
			'total'    => count( $d['items'] ),
			'warnings' => array_slice( $d['warnings'], 0, 50 ),
		);
	}
}
