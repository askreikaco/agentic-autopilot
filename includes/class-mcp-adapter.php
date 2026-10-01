<?php
/**
 * Downloads, activates, and updates the official WordPress MCP Adapter plugin.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the installation and updates of the WordPress MCP Adapter from GitHub.
 */
final class Agentic_Autopilot_Mcp_Adapter {

	const PLUGIN    = 'mcp-adapter/mcp-adapter.php';
	const REPO      = 'WordPress/mcp-adapter';
	const ZIP       = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';
	const TRANSIENT = 'agentic_autopilot_mcp_release';
	const ACTION    = 'agentic_autopilot_install_mcp';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_install' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	/**
	 * Current status of the MCP Adapter plugin.
	 *
	 * Possible returns:
	 * - 'unsupported': WordPress version too old (requires 6.9+ for Abilities API)
	 * - 'builtin': MCP is provided by WordPress core or another plugin
	 * - 'active': MCP Adapter is installed and activated
	 * - 'inactive': MCP Adapter is installed but not activated
	 * - 'missing': MCP Adapter is not installed
	 *
	 * @return string
	 */
	public static function status() {
		/**
		 * Filters the MCP Adapter status.
		 *
		 * Lets a later release (or a site) report 'builtin' once WordPress core ships MCP itself.
		 *
		 * @param string $status 'builtin', 'active', 'inactive', 'missing' or 'unsupported'.
		 */
		return (string) apply_filters( 'agentic_autopilot_mcp_status', self::detect() );
	}

	/**
	 * Detect the MCP Adapter status without filters.
	 *
	 * @return string
	 */
	private static function detect() {
		// Load plugin.php if is_plugin_active doesn't exist yet.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check WordPress version.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return 'unsupported';
		}

		// Check if another source provides MCP already.
		if ( ! is_plugin_active( self::PLUGIN ) && ( class_exists( 'WP\\MCP\\Core\\McpAdapter' ) || defined( 'WP_MCP_VERSION' ) ) ) {
			return 'builtin';
		}

		// Check if the plugin is installed and active.
		if ( is_plugin_active( self::PLUGIN ) ) {
			return 'active';
		}

		// Check if the plugin is installed but inactive.
		if ( file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
			return 'inactive';
		}

		return 'missing';
	}

	/**
	 * Get the installed version of the MCP Adapter plugin.
	 *
	 * @return string Version string, or empty string if not installed.
	 */
	public static function installed_version() {
		$plugin_file = WP_PLUGIN_DIR . '/' . self::PLUGIN;
		if ( ! file_exists( $plugin_file ) ) {
			return '';
		}
		$headers = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
		return $headers['Version'] ?? '';
	}

	/**
	 * Handle the install/activate request from the admin page.
	 */
	public static function handle_install() {
		// Verify nonce.
		check_admin_referer( self::ACTION );

		// Check capabilities.
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'agentic-autopilot' ), 403 );
		}

		// Check if file modifications are allowed.
		if ( ! wp_is_file_mod_allowed( 'agentic_autopilot_mcp' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'aa_mcp'     => 'error',
						'aa_mcp_msg' => urlencode( __( 'File modifications are not allowed.', 'agentic-autopilot' ) ),
					),
					admin_url( 'options-general.php?page=' . Agentic_Autopilot_Settings::PAGE )
				)
			);
			exit;
		}

		$current_status = self::status();

		// If the plugin is missing, download and install it.
		if ( 'missing' === $current_status ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( self::ZIP );

			// Check if installation was successful.
			if ( is_wp_error( $result ) || false === $result || ! file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
				$error_msg = __( 'Failed to download the MCP Adapter.', 'agentic-autopilot' );

				// Try to get more details from the upgrader.
				if ( method_exists( $upgrader->skin, 'get_errors' ) ) {
					$errors = $upgrader->skin->get_errors();
					if ( is_wp_error( $errors ) && $errors->has_errors() ) {
						$error_msg = $errors->get_error_message();
					}
				}

				wp_safe_redirect(
					add_query_arg(
						array(
							'aa_mcp'     => 'error',
							'aa_mcp_msg' => urlencode( $error_msg ),
						),
						admin_url( 'options-general.php?page=' . Agentic_Autopilot_Settings::PAGE )
					)
				);
				exit;
			}
		}

		// Activate the plugin if it's not active.
		if ( 'active' !== self::status() ) {
			$activate_result = activate_plugin( self::PLUGIN );
			if ( is_wp_error( $activate_result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'aa_mcp'     => 'error',
							'aa_mcp_msg' => urlencode( $activate_result->get_error_message() ),
						),
						admin_url( 'options-general.php?page=' . Agentic_Autopilot_Settings::PAGE )
					)
				);
				exit;
			}
		}

		// Success.
		wp_safe_redirect(
			add_query_arg(
				'aa_mcp',
				'ok',
				admin_url( 'options-general.php?page=' . Agentic_Autopilot_Settings::PAGE )
			)
		);
		exit;
	}

	/**
	 * Inject update info for the MCP Adapter plugin into the transient.
	 *
	 * @param mixed $transient The update transient.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		// Return unchanged if it's not an object or if WordPress.org already has it.
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Don't override if WordPress.org is already providing updates.
		if ( isset( $transient->response[ self::PLUGIN ] ) || isset( $transient->no_update[ self::PLUGIN ] ) ) {
			return $transient;
		}

		// Don't offer updates if the plugin is not installed.
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ) {
			return $transient;
		}

		$release = self::latest();

		if ( $release && version_compare( $release['version'], self::installed_version(), '>' ) ) {
			$transient->response[ self::PLUGIN ] = (object) array(
				'id'           => 'github.com/WordPress/mcp-adapter',
				'slug'         => 'mcp-adapter',
				'plugin'       => self::PLUGIN,
				'new_version'  => $release['version'],
				'package'      => $release['package'],
				'url'          => $release['url'],
				// The release's own minimums, so WordPress refuses an update this site cannot run.
				'requires'     => ! empty( $release['requires'] ) ? $release['requires'] : '6.9',
				'requires_php' => ! empty( $release['requires_php'] ) ? $release['requires_php'] : '7.4',
			);
		}

		return $transient;
	}

	/**
	 * Auto-update the MCP Adapter for bug-fix releases only (e.g. 0.6.1 to 0.6.2).
	 *
	 * Larger updates (0.6 to 0.7) can change behaviour, so they wait on the Plugins
	 * screen for an admin. WordPress still checks "Requires PHP/at least" and rolls
	 * back an automatic update that causes a fatal error.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( ! isset( $item->plugin ) || self::PLUGIN !== $item->plugin || empty( $item->new_version ) ) {
			return $update;
		}
		if ( empty( Agentic_Autopilot_Settings::get()['mcp_auto_update'] ) ) {
			return $update;
		}
		return self::same_minor( self::installed_version(), (string) $item->new_version ) ? true : $update;
	}

	/**
	 * Whether two versions share major.minor (so the newer one is a bug-fix release).
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 * @return bool
	 */
	public static function same_minor( $a, $b ) {
		$a = explode( '.', $a );
		$b = explode( '.', $b );
		return '' !== $a[0] && isset( $a[1], $b[1] ) && (int) $a[0] === (int) $b[0] && (int) $a[1] === (int) $b[1];
	}

	/**
	 * Get the latest release from the GitHub API, cached for 6 hours (1 hour on error).
	 *
	 * @return array{version:string, package:string, url:string}|false
	 */
	private static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : false;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
				$package = null;

				// Look for the mcp-adapter.zip asset.
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'mcp-adapter.zip' === $asset['name'] ) {
						$package = (string) $asset['browser_download_url'];
						break;
					}
				}

				// Only consider this a valid release if we found the mcp-adapter.zip asset.
				if ( $package ) {
					$release = array_merge(
						array(
							'version' => ltrim( (string) $body['tag_name'], 'vV' ),
							'package' => $package,
							'url'     => (string) $body['html_url'],
						),
						Agentic_Autopilot_GitHub_Updater::requirements( self::REPO, (string) $body['tag_name'], 'mcp-adapter.php' )
					);
				}
			}
		}

		set_site_transient( self::TRANSIENT, $release ? $release : array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}
}
