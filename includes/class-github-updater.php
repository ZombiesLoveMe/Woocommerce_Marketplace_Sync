<?php
namespace WooEtsySync;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the plugin from GitHub releases, through WordPress's normal "Update available" screen.
 *
 * The repository comes from (first match wins):
 *   1. define( 'WES_GITHUB_REPO', 'owner/repo' ); in wp-config.php
 *   2. the WES_GITHUB_REPO_DEFAULT constant in the main plugin file, which the GitHub
 *      release workflow fills in automatically when it builds the release zip.
 *
 * Each release should have a "woo-etsy-sync.zip" asset (the release workflow adds it).
 * If it doesn't, GitHub's own source zip is used and the folder is renamed on install.
 */
class GitHub_Updater {

	const CACHE = 'wes_github_release';
	const ASSET = 'woo-etsy-sync.zip';

	public static function init() {
		if ( ! self::repo() ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( WES_FILE ), array( __CLASS__, 'action_link' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/** "owner/repo", or '' when updates from GitHub aren't set up. */
	public static function repo() {
		$repo = defined( 'WES_GITHUB_REPO' ) ? WES_GITHUB_REPO : ( defined( 'WES_GITHUB_REPO_DEFAULT' ) ? WES_GITHUB_REPO_DEFAULT : '' );
		$repo = trim( (string) $repo, " /\t\n" );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/** Latest published release (cached for 6 hours). */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; woo-etsy-sync',
				),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$body = 200 === $code ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			// Remember failures briefly so a missing repo/no releases doesn't slow every admin page.
			set_site_transient( self::CACHE, array(), 404 === $code ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
			return null;
		}
		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $a ) {
			if ( isset( $a['name'], $a['browser_download_url'] ) && self::ASSET === $a['name'] ) {
				$package = $a['browser_download_url'];
			}
		}
		$release = array(
			'version' => ltrim( (string) $body['tag_name'], 'vV' ),
			'package' => $package ? $package : (string) ( $body['zipball_url'] ?? '' ),
			'url'     => (string) ( $body['html_url'] ?? 'https://github.com/' . self::repo() ),
			'notes'   => (string) ( $body['body'] ?? '' ),
			'date'    => (string) ( $body['published_at'] ?? '' ),
		);
		set_site_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** WordPress asks every plugin with "Update URI: https://github.com/..." for update info. */
	public static function check( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( plugin_basename( WES_FILE ) !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( ! $r || ! $r['package'] ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => dirname( plugin_basename( WES_FILE ) ),
			'version'      => $r['version'],
			'new_version'  => $r['version'],
			'url'          => $r['url'],
			'package'      => version_compare( $r['version'], WES_VERSION, '>' ) ? $r['package'] : '',
			'requires'     => '6.2',
			'requires_php' => '7.4',
		);
	}

	/** "View details" popup on the Plugins / Updates screens. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( WES_FILE ) ) !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( ! $r ) {
			return $result;
		}
		$notes = esc_html( $r['notes'] );
		$notes = preg_replace( '/^#{1,6}\s*(.+)$/m', '<h4>$1</h4>', $notes );
		$notes = preg_replace( '/^\s*[-*]\s+(.+)$/m', '<li>$1</li>', $notes );
		$notes = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $notes );
		return (object) array(
			'name'          => __( 'Marketplace Sync for WooCommerce', 'woo-etsy-sync' ),
			'slug'          => $args->slug,
			'version'       => $r['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( self::repo() ) . '">' . esc_html( strtok( self::repo(), '/' ) ) . '</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'last_updated'  => $r['date'],
			'download_link' => $r['package'],
			'sections'      => array(
				'changelog'   => $notes ? wpautop( $notes ) : esc_html__( 'See the release on GitHub.', 'woo-etsy-sync' ),
				'description' => esc_html__( 'Sell everywhere from WooCommerce: Etsy and eBay two-way sync, plus product feeds for Google, Facebook & Instagram, Pinterest, TikTok and Bing.', 'woo-etsy-sync' ),
			),
		);
	}

	/**
	 * GitHub's source zips unpack to "repo-name-tag/". Rename to our folder so the update replaces the
	 * plugin in place instead of installing a second copy.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;
		$ours = isset( $hook_extra['plugin'] ) && plugin_basename( WES_FILE ) === $hook_extra['plugin'];
		if ( ! $ours || ! $wp_filesystem ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( plugin_basename( WES_FILE ) ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $want ), true ) ) {
			return $want;
		}
		return $source;
	}

	public static function action_link( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( add_query_arg( 'wes_check_update', '1', self_admin_url( 'plugins.php' ) ), 'wes_check_update' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'woo-etsy-sync' ) . '</a>';
		}
		return $links;
	}

	public static function maybe_force_check() {
		if ( empty( $_GET['wes_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'wes_check_update' );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( self_admin_url( 'plugins.php?plugin_status=upgrade' ) );
		exit;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}
}
