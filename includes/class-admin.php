<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin integration: main app screen, products list column/bulk actions, product "Etsy" tab.
 */
class Admin {

	const SLUG = 'woo-etsy-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WES_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		// Products list.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_action( 'admin_post_wes_sync_one', array( __CLASS__, 'sync_one' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_filter' ) );

		// Product editor.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_panel' ) );
	}

	public static function icon_svg() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M4 5.5A2.5 2.5 0 0 1 6.5 3h5.6a.9.9 0 0 1 0 1.8H6.5a.7.7 0 0 0-.7.7v5.1h3.4l-1.1-1.1a.9.9 0 1 1 1.3-1.3l2.6 2.6a.9.9 0 0 1 0 1.3l-2.6 2.6a.9.9 0 1 1-1.3-1.3l1.1-1.1H5.8V14.5a.7.7 0 0 0 .7.7h1.3a.9.9 0 1 1 0 1.8H6.5A2.5 2.5 0 0 1 4 14.5zM16 14.5a2.5 2.5 0 0 1-2.5 2.5h-1.3a.9.9 0 0 1 0-1.8h1.3a.7.7 0 0 0 .7-.7v-9a.7.7 0 0 0-.7-.7h-.4a.9.9 0 0 1 0-1.8h.4A2.5 2.5 0 0 1 16 5.5z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function menu() {
		add_menu_page(
			__( 'Marketplace Sync', 'woo-etsy-sync' ),
			__( 'Marketplaces', 'woo-etsy-sync' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render_app' ),
			self::icon_svg(),
			56
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open', 'woo-etsy-sync' ) . '</a>' );
		return $links;
	}

	public static function render_app() {
		echo '<div id="wes-app" class="wes-root"><div class="wes-boot"><span class="wes-spinner"></span></div></div>';
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$ver    = WES_VERSION . '.' . filemtime( WES_DIR . 'assets/admin.js' );

		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'wes-admin', WES_URL . 'assets/admin.css', array(), $ver );
			wp_enqueue_script( 'wes-admin', WES_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), $ver, true );
			wp_enqueue_script( 'wes-ebay', WES_URL . 'assets/ebay.js', array( 'wes-admin' ), $ver, true );
			wp_enqueue_script( 'wes-feeds', WES_URL . 'assets/feeds.js', array( 'wes-admin', 'wes-ebay' ), $ver, true );
			foreach ( array( 'wes-admin', 'wes-ebay', 'wes-feeds' ) as $handle ) {
				wp_set_script_translations( $handle, 'woo-etsy-sync', WES_DIR . 'languages' );
			}
			wp_localize_script( 'wes-feeds', 'WES_FEEDS_BOOT', array( 'state' => Feeds::state() ) );
			wp_localize_script(
				'wes-ebay',
				'WES_EBAY_BOOT',
				array(
					'state' => Ebay_Rest::state(),
					'flash' => array(
						'connected' => isset( $_GET['wes_ebay_connected'] ), // phpcs:ignore WordPress.Security.NonceVerification
						'error'     => isset( $_GET['wes_ebay_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wes_ebay_error'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
					),
				)
			);
			wp_localize_script(
				'wes-admin',
				'WES_BOOT',
				array(
					'state'      => Rest::state(),
					'adminUrl'   => admin_url(),
					'siteName'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'productsUrl' => admin_url( 'edit.php?post_type=product' ),
					'logoUrl'    => WES_URL . 'assets/logo.svg',
					'version'    => WES_VERSION,
					'flash'      => array(
						'connected' => isset( $_GET['wes_connected'] ), // phpcs:ignore WordPress.Security.NonceVerification
						'error'     => isset( $_GET['wes_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wes_error'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
					),
				)
			);
			return;
		}

		if ( $screen && 'product' === $screen->post_type ) {
			wp_enqueue_style( 'wes-product', WES_URL . 'assets/product.css', array(), $ver );
			if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
				wp_enqueue_script( 'wes-product', WES_URL . 'assets/product.js', array( 'wp-api-fetch', 'jquery', 'wp-i18n' ), $ver, true );
				wp_set_script_translations( 'wes-product', 'woo-etsy-sync', WES_DIR . 'languages' );
				wp_localize_script(
					'wes-product',
					'WES_PRODUCT',
					array(
						'appUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
					)
				);
			}
		}
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( get_option( 'wes_needs_reconnect' ) && current_user_can( 'manage_woocommerce' ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>' . esc_html__( 'Etsy Sync:', 'woo-etsy-sync' ) . '</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Etsy needs you to reconnect your shop before syncing can continue.', 'woo-etsy-sync' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#/connection' ) ),
				esc_html__( 'Reconnect now', 'woo-etsy-sync' )
			);
		}
		if ( $screen && 'edit-product' === $screen->id && isset( $_GET['wes_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = absint( $_GET['wes_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( _n( '%d product is syncing to Etsy in the background.', '%d products are syncing to Etsy in the background.', $n, 'woo-etsy-sync' ), $n ) ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#/products' ) ),
				esc_html__( 'Watch progress', 'woo-etsy-sync' )
			);
		}
		if ( $screen && 'edit-product' === $screen->id && isset( $_GET['wes_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Etsy Sync: done.', 'woo-etsy-sync' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Products list.
	 * ------------------------------------------------------------------ */

	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'price' === $k ) {
				$out['wes_etsy'] = '<span class="wes-col-head">' . esc_html__( 'Etsy', 'woo-etsy-sync' ) . '</span>';
			}
		}
		if ( ! isset( $out['wes_etsy'] ) ) {
			$out['wes_etsy'] = '<span class="wes-col-head">' . esc_html__( 'Etsy', 'woo-etsy-sync' ) . '</span>';
		}
		return $out;
	}

	public static function status_badge( $product_id ) {
		$listing_id = Product_Sync::listing_id( $product_id );
		$status     = (string) get_post_meta( $product_id, '_wes_status', true );
		$state      = (string) get_post_meta( $product_id, '_wes_listing_state', true );
		if ( Mapper::is_excluded( $product_id ) ) {
			return '<span class="wes-pill wes-pill--muted" title="' . esc_attr__( 'Set to not sync', 'woo-etsy-sync' ) . '">' . esc_html__( 'Excluded', 'woo-etsy-sync' ) . '</span>';
		}
		if ( 'error' === $status ) {
			$err = (string) get_post_meta( $product_id, '_wes_error', true );
			return '<span class="wes-pill wes-pill--error" title="' . esc_attr( $err ) . '">' . esc_html__( 'Error', 'woo-etsy-sync' ) . '</span>';
		}
		if ( 'queued' === $status && ! $listing_id ) {
			return '<span class="wes-pill wes-pill--info">' . esc_html__( 'Queued', 'woo-etsy-sync' ) . '</span>';
		}
		if ( ! $listing_id ) {
			return '<span class="wes-pill wes-pill--muted">' . esc_html__( 'Not on Etsy', 'woo-etsy-sync' ) . '</span>';
		}
		$labels = array(
			'active'   => array( __( 'Live', 'woo-etsy-sync' ), 'ok' ),
			'draft'    => array( __( 'Draft', 'woo-etsy-sync' ), 'info' ),
			'inactive' => array( __( 'Inactive', 'woo-etsy-sync' ), 'warn' ),
			'sold_out' => array( __( 'Sold out', 'woo-etsy-sync' ), 'warn' ),
			'expired'  => array( __( 'Expired', 'woo-etsy-sync' ), 'warn' ),
		);
		$l = $labels[ $state ] ?? array( __( 'Linked', 'woo-etsy-sync' ), 'ok' );
		return sprintf(
			'<a class="wes-pill wes-pill--%1$s" href="%2$s" target="_blank" rel="noopener" title="' . esc_attr__( 'Open on Etsy', 'woo-etsy-sync' ) . '">%3$s ↗</a>',
			esc_attr( $l[1] ),
			esc_url( Product_Sync::listing_url( $listing_id ) ),
			esc_html( $l[0] )
		);
	}

	public static function render_column( $column, $post_id ) {
		if ( 'wes_etsy' === $column ) {
			echo self::status_badge( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function bulk_actions( $actions ) {
		$actions['wes_sync']    = __( 'Sync to Etsy', 'woo-etsy-sync' );
		$actions['wes_unlink']  = __( 'Unlink from Etsy', 'woo-etsy-sync' );
		$actions['wes_exclude'] = __( "Don't sync to Etsy", 'woo-etsy-sync' );
		$actions['wes_include'] = __( 'Allow syncing to Etsy', 'woo-etsy-sync' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		switch ( $action ) {
			case 'wes_sync':
				$ids = array_filter(
					$ids,
					function ( $id ) {
						return ! Mapper::is_excluded( $id );
					}
				);
				$n = Scheduler::queue_sync( $ids );
				return add_query_arg( 'wes_queued', $n, $redirect );
			case 'wes_unlink':
				foreach ( $ids as $id ) {
					Product_Sync::unlink( (int) $id );
				}
				return add_query_arg( 'wes_done', 1, $redirect );
			case 'wes_exclude':
				foreach ( $ids as $id ) {
					update_post_meta( (int) $id, '_wes_exclude', 'yes' );
				}
				return add_query_arg( 'wes_done', 1, $redirect );
			case 'wes_include':
				foreach ( $ids as $id ) {
					delete_post_meta( (int) $id, '_wes_exclude' );
				}
				return add_query_arg( 'wes_done', 1, $redirect );
		}
		return $redirect;
	}

	public static function row_actions( $actions, $post ) {
		if ( 'product' !== $post->post_type || ! current_user_can( 'manage_woocommerce' ) || Mapper::is_excluded( $post->ID ) ) {
			return $actions;
		}
		$url                 = wp_nonce_url( admin_url( 'admin-post.php?action=wes_sync_one&id=' . $post->ID ), 'wes_sync_one_' . $post->ID );
		$actions['wes_sync'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Sync to Etsy', 'woo-etsy-sync' ) . '</a>';
		return $actions;
	}

	public static function sync_one() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'wes_sync_one_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Not allowed', 'woo-etsy-sync' ) );
		}
		$n = Scheduler::queue_sync( array( $id ) );
		wp_safe_redirect( add_query_arg( 'wes_queued', $n, wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	public static function list_filter( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['wes_status'] ) ? sanitize_key( $_GET['wes_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$opts    = array(
			''           => __( 'Etsy: any', 'woo-etsy-sync' ),
			'linked'     => __( 'On Etsy', 'woo-etsy-sync' ),
			'not_synced' => __( 'Not on Etsy', 'woo-etsy-sync' ),
			'error'      => __( 'Etsy errors', 'woo-etsy-sync' ),
			'excluded'   => __( 'Excluded from Etsy', 'woo-etsy-sync' ),
		);
		echo '<select name="wes_status">';
		foreach ( $opts as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function apply_list_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) || empty( $_GET['wes_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$status = sanitize_key( $_GET['wes_status'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$meta   = (array) $query->get( 'meta_query' );
		switch ( $status ) {
			case 'linked':
				$meta[] = array( 'key' => '_wes_listing_id', 'compare' => 'EXISTS' );
				break;
			case 'not_synced':
				$meta[] = array( 'key' => '_wes_listing_id', 'compare' => 'NOT EXISTS' );
				break;
			case 'error':
				$meta[] = array( 'key' => '_wes_status', 'value' => 'error' );
				break;
			case 'excluded':
				$meta[] = array( 'key' => '_wes_exclude', 'value' => 'yes' );
				break;
		}
		$query->set( 'meta_query', $meta );
	}

	/* ---------------------------------------------------------------------
	 * Product editor tab.
	 * ------------------------------------------------------------------ */

	public static function product_tab( $tabs ) {
		$tabs['wes_etsy'] = array(
			'label'    => __( 'Etsy', 'woo-etsy-sync' ),
			'target'   => 'wes_etsy_panel',
			'class'    => array( 'hide_if_grouped', 'hide_if_external' ),
			'priority' => 75,
		);
		return $tabs;
	}

	private static function safe_options( callable $fn ) {
		if ( ! Settings::is_connected() ) {
			return array();
		}
		try {
			return $fn();
		} catch ( \Exception $e ) {
			return array();
		}
	}

	public static function product_panel() {
		global $post;
		$id         = (int) $post->ID;
		$listing_id = Product_Sync::listing_id( $id );
		$synced_at  = (int) get_post_meta( $id, '_wes_synced_at', true );
		$error      = (string) get_post_meta( $id, '_wes_error', true );
		$shipping   = self::safe_options( array( Cache::class, 'shipping_profiles' ) );
		$readiness  = self::safe_options( array( Cache::class, 'readiness_states' ) );
		$sections   = self::safe_options( array( Cache::class, 'sections' ) );
		$m          = function ( $k ) use ( $id ) {
			return (string) get_post_meta( $id, '_wes_' . $k, true );
		};
		$select     = function ( $name, $label, $options, $value, $help = '' ) {
			echo '<p class="form-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
			echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="select short">';
			echo '<option value="">' . esc_html__( 'Use default', 'woo-etsy-sync' ) . '</option>';
			foreach ( $options as $o ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $o['id'] ), selected( (string) $value, (string) $o['id'], false ), esc_html( $o['label'] ) );
			}
			echo '</select>';
			if ( $help ) {
				echo wc_help_tip( $help ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</p>';
		};
		?>
		<div id="wes_etsy_panel" class="panel woocommerce_options_panel hidden" data-product="<?php echo esc_attr( $id ); ?>">
			<div class="wes-card">
				<div class="wes-card__row">
					<div>
						<div class="wes-card__title"><?php esc_html_e( 'Etsy status', 'woo-etsy-sync' ); ?></div>
						<div class="wes-card__status" data-wes-status><?php echo self::status_badge( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( $synced_at ) : ?>
								<span class="wes-muted"><?php echo esc_html( sprintf( __( 'Last synced %s ago', 'woo-etsy-sync' ), human_time_diff( $synced_at ) ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="wes-card__actions">
						<?php if ( Settings::is_connected() ) : ?>
							<button type="button" class="button button-primary" data-wes-action="sync"><?php echo $listing_id ? esc_html__( 'Update on Etsy now', 'woo-etsy-sync' ) : esc_html__( 'List on Etsy now', 'woo-etsy-sync' ); ?></button>
							<button type="button" class="button" data-wes-action="preview"><?php esc_html_e( 'Preview', 'woo-etsy-sync' ); ?></button>
							<?php if ( $listing_id ) : ?>
								<button type="button" class="button-link wes-danger-link" data-wes-action="unlink"><?php esc_html_e( 'Unlink', 'woo-etsy-sync' ); ?></button>
							<?php endif; ?>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Connect Etsy', 'woo-etsy-sync' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<div class="wes-card__error" data-wes-error <?php echo $error ? '' : 'hidden'; ?>><?php echo esc_html( $error ); ?></div>
				<div class="wes-card__msg" data-wes-msg hidden></div>
				<pre class="wes-card__preview" data-wes-preview hidden></pre>
				<?php if ( ! $listing_id && Settings::is_connected() ) : ?>
					<div class="wes-card__link">
						<label><?php esc_html_e( 'Already on Etsy? Link it instead:', 'woo-etsy-sync' ); ?></label>
						<input type="text" data-wes-link-input placeholder="https://www.etsy.com/listing/123456789/..." />
						<button type="button" class="button" data-wes-action="link"><?php esc_html_e( 'Link', 'woo-etsy-sync' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_wes_exclude',
						'label'       => __( "Don't sync to Etsy", 'woo-etsy-sync' ),
						'description' => __( 'Keep this product off Etsy (ignored by bulk syncs and stock updates).', 'woo-etsy-sync' ),
						'value'       => $m( 'exclude' ),
						'cbvalue'     => 'yes',
					)
				);
				?>
			</div>
			<div class="options_group">
				<p class="wes-panel-intro"><?php esc_html_e( 'Optional overrides — leave blank to use your defaults from Marketplaces → Etsy → Settings.', 'woo-etsy-sync' ); ?></p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_wes_title',
						'label'             => __( 'Etsy title', 'woo-etsy-sync' ),
						'value'             => $m( 'title' ),
						'placeholder'       => Mapper::clean_title( $post->post_title ),
						'custom_attributes' => array( 'maxlength' => 140 ),
						'desc_tip'          => true,
						'description'       => __( 'Up to 140 characters. Leave blank to use the product name / title template.', 'woo-etsy-sync' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_wes_tags',
						'label'       => __( 'Etsy tags', 'woo-etsy-sync' ),
						'value'       => $m( 'tags' ),
						'placeholder' => __( 'comma, separated, up to 13', 'woo-etsy-sync' ),
						'desc_tip'    => true,
						'description' => __( 'Up to 13 tags, 20 characters each. Leave blank to use product tags.', 'woo-etsy-sync' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_wes_materials',
						'label'       => __( 'Materials', 'woo-etsy-sync' ),
						'value'       => $m( 'materials' ),
						'placeholder' => __( 'e.g. cotton, silver', 'woo-etsy-sync' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_wes_who_made',
						'label'   => __( 'Who made it?', 'woo-etsy-sync' ),
						'value'   => $m( 'who_made' ),
						'options' => array(
							''             => __( 'Use default', 'woo-etsy-sync' ),
							'i_did'        => __( 'I did', 'woo-etsy-sync' ),
							'collective'   => __( 'A member of my shop', 'woo-etsy-sync' ),
							'someone_else' => __( 'Another company or person', 'woo-etsy-sync' ),
						),
					)
				);
				$when = array( '' => __( 'Use default', 'woo-etsy-sync' ) );
				foreach ( Mapper::when_made_options() as $w ) {
					$when[ $w ] = ucwords( str_replace( '_', ' ', $w ) );
				}
				woocommerce_wp_select(
					array(
						'id'      => '_wes_when_made',
						'label'   => __( 'When was it made?', 'woo-etsy-sync' ),
						'value'   => $m( 'when_made' ),
						'options' => $when,
					)
				);
				?>
				<p class="form-field">
					<label for="_wes_taxonomy_id"><?php esc_html_e( 'Etsy category', 'woo-etsy-sync' ); ?></label>
					<input type="hidden" id="_wes_taxonomy_id" name="_wes_taxonomy_id" value="<?php echo esc_attr( $m( 'taxonomy_id' ) ); ?>" />
					<input type="hidden" id="_wes_taxonomy_label" name="_wes_taxonomy_label" value="<?php echo esc_attr( $m( 'taxonomy_label' ) ); ?>" />
					<span class="wes-tax-picker">
						<input type="search" class="short" data-wes-tax-search placeholder="<?php echo esc_attr( $m( 'taxonomy_label' ) ? $m( 'taxonomy_label' ) : __( 'Use default (type to search Etsy categories…)', 'woo-etsy-sync' ) ); ?>" autocomplete="off" />
						<?php if ( $m( 'taxonomy_id' ) ) : ?>
							<button type="button" class="button-link" data-wes-tax-clear><?php esc_html_e( 'Clear', 'woo-etsy-sync' ); ?></button>
						<?php endif; ?>
						<span class="wes-tax-results" data-wes-tax-results hidden></span>
					</span>
				</p>
				<?php
				$select( '_wes_shipping_profile', __( 'Shipping profile', 'woo-etsy-sync' ), $shipping, $m( 'shipping_profile' ) );
				$select( '_wes_readiness_state', __( 'Processing profile', 'woo-etsy-sync' ), $readiness, $m( 'readiness_state' ) );
				$select( '_wes_section', __( 'Shop section', 'woo-etsy-sync' ), $sections, $m( 'section' ) );
				?>
			</div>
		</div>
		<?php
	}

	public static function save_product_panel( $post_id ) {
		// Nonce already verified by WooCommerce (woocommerce_meta_nonce) before this action fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$text = array( 'title', 'tags', 'materials', 'who_made', 'when_made', 'taxonomy_label' );
		foreach ( $text as $k ) {
			$key = '_wes_' . $k;
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( '' === $val ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $val );
			}
		}
		foreach ( array( 'taxonomy_id', 'shipping_profile', 'readiness_state', 'section' ) as $k ) {
			$key = '_wes_' . $k;
			$val = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
			if ( $val ) {
				update_post_meta( $post_id, $key, $val );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
		if ( ! empty( $_POST['_wes_exclude'] ) ) {
			update_post_meta( $post_id, '_wes_exclude', 'yes' );
		} else {
			delete_post_meta( $post_id, '_wes_exclude' );
		}
		// phpcs:enable
	}
}
