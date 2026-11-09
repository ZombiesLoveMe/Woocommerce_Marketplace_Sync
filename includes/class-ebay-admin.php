<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * eBay hooks + wp-admin bits: products list column, bulk actions, product "eBay" tab.
 */
class Ebay_Admin {

	public static function init() {
		// Stock + auto sync (front end too: orders reduce stock there).
		add_action( 'woocommerce_product_set_stock', array( Ebay_Sync::class, 'on_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( Ebay_Sync::class, 'on_stock_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 21, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_saved' ), 21 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );

		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 21 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ), 11 );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_panel' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ---------------------------------------------------------------------
	 * Hooks.
	 * ------------------------------------------------------------------ */

	public static function on_stock_status( $id, $status = '', $product = null ) {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $id );
		if ( $product && ! $product->managing_stock() ) {
			Ebay_Sync::on_stock_change( $product );
		}
	}

	public static function on_product_saved( $id ) {
		if ( ! Ebay_Settings::get( 'auto_sync_on_save' ) || ! Ebay_Settings::is_connected() || 'publish' !== get_post_status( $id ) ) {
			return;
		}
		if ( Ebay_Mapper::is_excluded( $id ) || ! Ebay_Sync::item_id( $id ) ) {
			return;
		}
		Ebay_Scheduler::queue_sync( array( $id ) );
	}

	public static function on_variation_saved( $vid ) {
		$parent = (int) wp_get_post_parent_id( $vid );
		if ( $parent ) {
			self::on_product_saved( $parent );
		}
	}

	public static function on_trash( $id ) {
		if ( 'product' !== get_post_type( $id ) || 'end' !== Ebay_Settings::get( 'on_trash' ) || ! Ebay_Settings::is_connected() || ! Ebay_Sync::item_id( $id ) ) {
			return;
		}
		if ( 'active' !== get_post_meta( $id, '_wes_ebay_state', true ) ) {
			return;
		}
		try {
			Ebay_Sync::end( $id );
		} catch ( \Exception $e ) {
			Logger::warning( __( 'Product was trashed but the eBay listing could not be ended: ', 'woo-etsy-sync' ) . $e->getMessage(), 'ebay-sync', $id );
		}
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( get_option( 'wes_ebay_needs_reconnect' ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>' . esc_html__( 'Marketplace Sync:', 'woo-etsy-sync' ) . '</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'eBay needs you to reconnect your account before syncing can continue.', 'woo-etsy-sync' ),
				esc_url( admin_url( 'admin.php?page=woo-etsy-sync#/ebay/settings/connection' ) ),
				esc_html__( 'Reconnect eBay', 'woo-etsy-sync' )
			);
		}
		$screen = get_current_screen();
		if ( $screen && 'edit-product' === $screen->id && isset( $_GET['wes_ebay_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( '%d product(s) are syncing to eBay in the background.', 'woo-etsy-sync' ), absint( $_GET['wes_ebay_queued'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	/* ---------------------------------------------------------------------
	 * Products list.
	 * ------------------------------------------------------------------ */

	public static function add_column( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wes_etsy' === $k ) {
				$out['wes_ebay'] = '<span class="wes-col-head">' . esc_html__( 'eBay', 'woo-etsy-sync' ) . '</span>';
			}
		}
		if ( ! isset( $out['wes_ebay'] ) ) {
			$out['wes_ebay'] = '<span class="wes-col-head">' . esc_html__( 'eBay', 'woo-etsy-sync' ) . '</span>';
		}
		return $out;
	}

	public static function badge( $id ) {
		$item = Ebay_Sync::item_id( $id );
		$st   = (string) get_post_meta( $id, '_wes_ebay_status', true );
		if ( Ebay_Mapper::is_excluded( $id ) ) {
			return '<span class="wes-pill wes-pill--muted">' . esc_html__( 'Excluded', 'woo-etsy-sync' ) . '</span>';
		}
		if ( 'error' === $st ) {
			return '<span class="wes-pill wes-pill--error" title="' . esc_attr( (string) get_post_meta( $id, '_wes_ebay_error', true ) ) . '">' . esc_html__( 'Error', 'woo-etsy-sync' ) . '</span>';
		}
		if ( 'queued' === $st && ! $item ) {
			return '<span class="wes-pill wes-pill--info">' . esc_html__( 'Queued', 'woo-etsy-sync' ) . '</span>';
		}
		if ( ! $item ) {
			return '<span class="wes-pill wes-pill--muted">' . esc_html__( 'Not on eBay', 'woo-etsy-sync' ) . '</span>';
		}
		$state = (string) get_post_meta( $id, '_wes_ebay_state', true );
		$l     = 'ended' === $state || 'completed' === $state ? array( __( 'Ended', 'woo-etsy-sync' ), 'warn' ) : array( __( 'Live', 'woo-etsy-sync' ), 'ok' );
		return sprintf( '<a class="wes-pill wes-pill--%1$s" href="%2$s" target="_blank" rel="noopener">%3$s ↗</a>', esc_attr( $l[1] ), esc_url( Ebay_Sync::item_url( $item ) ), esc_html( $l[0] ) );
	}

	public static function render_column( $col, $id ) {
		if ( 'wes_ebay' === $col ) {
			echo self::badge( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function bulk_actions( $a ) {
		$a['wes_ebay_sync']    = __( 'Sync to eBay', 'woo-etsy-sync' );
		$a['wes_ebay_unlink']  = __( 'Unlink from eBay', 'woo-etsy-sync' );
		$a['wes_ebay_exclude'] = __( "Don't sync to eBay", 'woo-etsy-sync' );
		return $a;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		switch ( $action ) {
			case 'wes_ebay_sync':
				return add_query_arg( 'wes_ebay_queued', Ebay_Scheduler::queue_sync( $ids ), $redirect );
			case 'wes_ebay_unlink':
				foreach ( $ids as $id ) {
					Ebay_Sync::unlink( (int) $id );
				}
				return add_query_arg( 'wes_done', 1, $redirect );
			case 'wes_ebay_exclude':
				foreach ( $ids as $id ) {
					update_post_meta( (int) $id, '_wes_ebay_exclude', 'yes' );
				}
				return add_query_arg( 'wes_done', 1, $redirect );
		}
		return $redirect;
	}

	/* ---------------------------------------------------------------------
	 * Product editor tab.
	 * ------------------------------------------------------------------ */

	public static function product_tab( $tabs ) {
		$tabs['wes_ebay'] = array(
			'label'    => __( 'eBay', 'woo-etsy-sync' ),
			'target'   => 'wes_ebay_panel',
			'class'    => array( 'hide_if_grouped', 'hide_if_external' ),
			'priority' => 76,
		);
		return $tabs;
	}

	public static function product_panel() {
		global $post;
		$id   = (int) $post->ID;
		$item = Ebay_Sync::item_id( $id );
		$err  = (string) get_post_meta( $id, '_wes_ebay_error', true );
		$m    = function ( $k ) use ( $id ) {
			return (string) get_post_meta( $id, '_wes_ebay_' . $k, true );
		};
		$wpl_title = (string) get_post_meta( $id, '_ebay_title', true );
		?>
		<div id="wes_ebay_panel" class="panel woocommerce_options_panel hidden" data-product="<?php echo esc_attr( $id ); ?>" data-channel="ebay">
			<div class="wes-card">
				<div class="wes-card__row">
					<div>
						<div class="wes-card__title"><?php esc_html_e( 'eBay status', 'woo-etsy-sync' ); ?></div>
						<div class="wes-card__status"><?php echo self::badge( $id ); // phpcs:ignore ?>
							<?php if ( $item ) : ?><span class="wes-muted"><?php echo esc_html( sprintf( /* translators: %s: eBay item number */ __( 'Item %s', 'woo-etsy-sync' ), $item ) ); ?></span><?php endif; ?>
						</div>
					</div>
					<div class="wes-card__actions">
						<?php if ( Ebay_Settings::is_connected() ) : ?>
							<button type="button" class="button button-primary" data-wes-action="sync"><?php echo $item ? esc_html__( 'Update on eBay now', 'woo-etsy-sync' ) : esc_html__( 'List on eBay now', 'woo-etsy-sync' ); ?></button>
							<button type="button" class="button" data-wes-action="preview"><?php esc_html_e( 'Check with eBay', 'woo-etsy-sync' ); ?></button>
							<?php if ( $item ) : ?>
								<button type="button" class="button-link wes-danger-link" data-wes-action="unlink"><?php esc_html_e( 'Unlink', 'woo-etsy-sync' ); ?></button>
							<?php endif; ?>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=woo-etsy-sync#/ebay' ) ); ?>"><?php esc_html_e( 'Connect eBay', 'woo-etsy-sync' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<div class="wes-card__error" data-wes-error <?php echo $err ? '' : 'hidden'; ?>><?php echo esc_html( $err ); ?></div>
				<div class="wes-card__msg" data-wes-msg hidden></div>
				<pre class="wes-card__preview" data-wes-preview hidden></pre>
				<?php if ( ! $item && Ebay_Settings::is_connected() ) : ?>
					<div class="wes-card__link">
						<label><?php esc_html_e( 'Already on eBay? Link it instead:', 'woo-etsy-sync' ); ?></label>
						<input type="text" data-wes-link-input placeholder="https://<?php echo esc_attr( \WooEtsySync\Ebay_Settings::site()[4] ); ?>/itm/123456789012" />
						<button type="button" class="button" data-wes-action="link"><?php esc_html_e( 'Link', 'woo-etsy-sync' ); ?></button>
					</div>
				<?php endif; ?>
			</div>
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_wes_ebay_exclude',
						'label'       => __( "Don't sync to eBay", 'woo-etsy-sync' ),
						'description' => __( 'Keep this product off eBay.', 'woo-etsy-sync' ),
						'value'       => $m( 'exclude' ),
						'cbvalue'     => 'yes',
					)
				);
				?>
			</div>
			<div class="options_group">
				<p class="wes-panel-intro"><?php esc_html_e( 'Optional overrides — leave blank to use your eBay defaults.', 'woo-etsy-sync' ); ?></p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_wes_ebay_title',
						'label'             => __( 'eBay title', 'woo-etsy-sync' ),
						'value'             => $m( 'title' ),
						'placeholder'       => Ebay_Mapper::clean_title( $wpl_title ? $wpl_title : $post->post_title ),
						'custom_attributes' => array( 'maxlength' => 80 ),
						'desc_tip'          => true,
						'description'       => __( 'Up to 80 characters.', 'woo-etsy-sync' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_wes_ebay_category',
						'label'       => __( 'eBay category ID', 'woo-etsy-sync' ),
						'value'       => $m( 'category' ),
						'placeholder' => (string) get_post_meta( $id, '_ebay_category_1_id', true ),
						'desc_tip'    => true,
						'description' => __( 'Leave blank to use your category mapping. Find IDs in Marketplaces → eBay → Settings → Categories.', 'woo-etsy-sync' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_wes_ebay_condition',
						'label'   => __( 'Condition', 'woo-etsy-sync' ),
						'value'   => $m( 'condition' ),
						'options' => array(
							''     => __( 'Use default', 'woo-etsy-sync' ),
							'1000' => __( 'New', 'woo-etsy-sync' ),
							'1500' => __( 'New other (see details)', 'woo-etsy-sync' ),
							'1750' => __( 'New with defects', 'woo-etsy-sync' ),
							'2750' => __( 'Like new', 'woo-etsy-sync' ),
							'3000' => __( 'Used', 'woo-etsy-sync' ),
						),
					)
				);
				woocommerce_wp_textarea_input(
					array(
						'id'          => '_wes_ebay_specifics',
						'label'       => __( 'Item specifics', 'woo-etsy-sync' ),
						'value'       => $m( 'specifics' ),
						'placeholder' => __( 'Material: Cotton
Type: T-Shirt', 'woo-etsy-sync' ),
						'desc_tip'    => true,
						'description' => __( 'One per line as Name: Value. Use | for several values.', 'woo-etsy-sync' ),
						'rows'        => 4,
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	public static function save_panel( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'title', 'condition' ) as $k ) {
			$v = isset( $_POST[ '_wes_ebay_' . $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ '_wes_ebay_' . $k ] ) ) : '';
			'' === $v ? delete_post_meta( $id, '_wes_ebay_' . $k ) : update_post_meta( $id, '_wes_ebay_' . $k, $v );
		}
		$cat = isset( $_POST['_wes_ebay_category'] ) ? absint( $_POST['_wes_ebay_category'] ) : 0;
		$cat ? update_post_meta( $id, '_wes_ebay_category', $cat ) : delete_post_meta( $id, '_wes_ebay_category' );
		$sp = isset( $_POST['_wes_ebay_specifics'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_wes_ebay_specifics'] ) ) : '';
		'' === trim( $sp ) ? delete_post_meta( $id, '_wes_ebay_specifics' ) : update_post_meta( $id, '_wes_ebay_specifics', $sp );
		! empty( $_POST['_wes_ebay_exclude'] ) ? update_post_meta( $id, '_wes_ebay_exclude', 'yes' ) : delete_post_meta( $id, '_wes_ebay_exclude' );
		// phpcs:enable
	}
}
