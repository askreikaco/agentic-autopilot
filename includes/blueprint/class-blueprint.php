<?php
/**
 * Blueprint: Connect any GitHub repo to install and update plugins and themes.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blueprint gateway: settings, catalog, installation, and updates from a GitHub repo.
 */
final class Agentic_Autopilot_Blueprint {

	const OPTION              = 'agentic_autopilot_blueprint';
	const TOKEN_OPTION        = 'agentic_autopilot_bp_token';
	const PAGE                = 'agentic-autopilot-blueprint';
	const CRON_HOOK           = 'agentic_autopilot_blueprint_daily';
	const TRANSIENT_PREFIX    = 'agentic_autopilot_bp_';
	const MARKER_PREFIX       = 'https://agentic-autopilot.invalid/blueprint/';
	const CATALOG_CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'repo'                    => '',
			'ref'                     => '',
			'path'                    => 'catalog.json',
			'auto_install'            => false,
			'activate_after_install'  => true,
			'auto_update'             => false,
			'excluded'                => array(),
			'expose_to_agents'        => false,
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Save settings with sanitizing.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return bool
	 */
	public static function save( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		// Sanitize repo: must pass valid_repo or be empty.
		if ( isset( $input['repo'] ) && is_string( $input['repo'] ) ) {
			$repo = trim( $input['repo'] );
			if ( '' === $repo || Agentic_Autopilot_Blueprint_GitHub::valid_repo( $repo ) ) {
				$out['repo'] = $repo;
			}
		}

		// Sanitize ref: alphanumeric, dots, dashes, slashes, max 100 chars.
		if ( isset( $input['ref'] ) && is_string( $input['ref'] ) ) {
			$ref = trim( $input['ref'] );
			if ( preg_match( '/^[A-Za-z0-9._\/-]{0,100}$/', $ref ) ) {
				$out['ref'] = $ref;
			}
		}

		// Sanitize path: relative, no '..', no leading '/'.
		if ( isset( $input['path'] ) && is_string( $input['path'] ) ) {
			$path = trim( $input['path'] );
			if ( $path && false === strpos( $path, '..' ) && 0 !== strpos( $path, '/' ) ) {
				$out['path'] = $path;
			}
		}

		// Booleans.
		$out['auto_install']           = ! empty( $input['auto_install'] );
		$out['activate_after_install'] = ! empty( $input['activate_after_install'] );
		$out['auto_update']            = ! empty( $input['auto_update'] );
		$out['expose_to_agents']       = ! empty( $input['expose_to_agents'] );

		// Excluded: array of "type:slug".
		if ( isset( $input['excluded'] ) && is_array( $input['excluded'] ) ) {
			$excluded = array_filter( array_map( 'strval', $input['excluded'] ) );
			$out['excluded'] = array_values( $excluded );
		}

		$result = update_option( self::OPTION, $out );
		if ( $result ) {
			self::clear_catalog_cache();
		}
		return $result;
	}

	/**
	 * Check if paused by legacy REIKA Blueprint plugin.
	 *
	 * @return bool
	 */
	public static function paused() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'reika-blueprint/reika-blueprint.php' ) || class_exists( 'REIKA_Blueprint_Manager' );
	}

	/**
	 * Fetch and cache the catalog from the repo.
	 *
	 * @param bool $force Bypass cache.
	 * @return array{items: array, config: array, warnings: array}|WP_Error
	 */
	public static function catalog( $force = false ) {
		$settings = self::get();
		if ( ! $settings['repo'] ) {
			return new WP_Error( 'no_repo', 'No repository configured' );
		}

		$cache_key = self::cache_key( $settings );

		if ( ! $force ) {
			$cached = get_site_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$raw = Agentic_Autopilot_Blueprint_GitHub::get_contents(
			$settings['repo'],
			$settings['path'],
			$settings['ref']
		);

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', 'Catalog is not valid JSON' );
		}

		// Validate and separate items.
		$items    = array();
		$config   = array();
		$warnings = array();

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = isset( $item['type'] ) ? (string) $item['type'] : '';
			if ( ! in_array( $type, array( 'plugins', 'themes', 'config' ), true ) ) {
				$warnings[] = 'Invalid type: ' . esc_attr( $type );
				continue;
			}

			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $slug ) ) {
				$warnings[] = 'Invalid slug: ' . esc_attr( $slug );
				continue;
			}

			$location = isset( $item['location'] ) ? (string) $item['location'] : '';
			if ( ! $location || false !== strpos( $location, '..' ) || 0 === strpos( $location, '/' ) ) {
				$warnings[] = 'Invalid location for ' . esc_attr( $slug );
				continue;
			}

			if ( 'config' === $type ) {
				$config[] = $item;
				continue;
			}

			// Plugin or theme.
			$version = isset( $item['version'] ) ? (string) $item['version'] : '';
			if ( ! $version ) {
				$warnings[] = 'Missing version for ' . esc_attr( $slug );
				continue;
			}

			if ( 'plugins' === $type ) {
				$main_file = isset( $item['main_file'] ) ? (string) $item['main_file'] : '';
				if ( ! preg_match( '/^[A-Za-z0-9._-]+\.php$/', $main_file ) ) {
					$warnings[] = 'Invalid main_file for plugin ' . esc_attr( $slug );
					continue;
				}
				if ( '.zip' !== substr( $location, -4 ) ) {
					$warnings[] = 'Plugin location must be .zip for ' . esc_attr( $slug );
					continue;
				}
			} elseif ( 'themes' === $type ) {
				if ( '.zip' !== substr( $location, -4 ) ) {
					$warnings[] = 'Theme location must be .zip for ' . esc_attr( $slug );
					continue;
				}
			}

			$items[] = $item;
		}

		$result = array(
			'items'    => $items,
			'config'   => $config,
			'warnings' => $warnings,
		);

		set_site_transient( $cache_key, $result, self::CATALOG_CACHE_TTL );
		return $result;
	}

	/**
	 * Clear the catalog cache.
	 */
	public static function clear_catalog_cache() {
		// delete_site_transient() works on single sites, multisite and with an object cache (Redis).
		delete_site_transient( self::cache_key( self::get() ) );
	}

	/**
	 * Cache key for the catalog of the current repo, ref and path.
	 *
	 * @param array $settings Blueprint settings.
	 * @return string
	 */
	private static function cache_key( $settings ) {
		return self::TRANSIENT_PREFIX . md5( $settings['repo'] . '|' . $settings['ref'] . '|' . $settings['path'] );
	}

	/**
	 * Get the status of all items in the catalog.
	 *
	 * @return array<string, array>|WP_Error Items keyed by slug, with keys:
	 *                                        installed, local_version, remote_version, state, file, active.
	 */
	public static function items_status() {
		$catalog_data = self::catalog();
		if ( is_wp_error( $catalog_data ) ) {
			return array();
		}

		$settings = self::get();
		$excluded = array_flip( $settings['excluded'] );
		$statuses = array();

		foreach ( $catalog_data['items'] as $item ) {
			$type     = $item['type'];
			$slug     = $item['slug'];
			$version  = $item['version'];
			$excluded_key = $type . ':' . $slug;

			if ( 'plugins' === $type ) {
				$main_file = $item['main_file'];
				$file_path = WP_PLUGIN_DIR . '/' . $slug . '/' . $main_file;
				$file      = $slug . '/' . $main_file;
				$installed = file_exists( $file_path );

				$local_version = 'unknown';
				if ( $installed ) {
					$data = get_file_data( $file_path, array( 'Version' => 'Version' ) );
					if ( isset( $data['Version'] ) && $data['Version'] ) {
						$local_version = $data['Version'];
					}
				}

				$active = $installed && is_plugin_active( $file );
			} else {
				// Theme.
				$file_path      = get_theme_root( $slug ) . '/' . $slug . '/style.css';
				$file           = $slug;
				$installed      = file_exists( $file_path );
				$local_version  = 'unknown';

				if ( $installed ) {
					$data = get_file_data( $file_path, array( 'Version' => 'Version' ) );
					if ( isset( $data['Version'] ) && $data['Version'] ) {
						$local_version = $data['Version'];
					}
				}

				$active = $installed && wp_get_theme( $slug )->exists();
			}

			if ( isset( $excluded[ $excluded_key ] ) ) {
				$state = 'excluded';
			} elseif ( ! $installed ) {
				$state = 'missing';
			} elseif ( $installed && version_compare( $version, $local_version, '>' ) ) {
				$state = 'update';
			} else {
				$state = 'current';
			}

			$statuses[ $slug ] = array(
				'installed'       => $installed,
				'local_version'   => $local_version,
				'remote_version'  => $version,
				'state'           => $state,
				'file'            => $file,
				'active'          => $active,
				'type'            => $type,
			);
		}

		return $statuses;
	}

	/**
	 * Install a plugin or theme by slug.
	 *
	 * @param string $type 'plugins' or 'themes'.
	 * @param string $slug Item slug.
	 * @return true|WP_Error
	 */
	public static function install( $type, $slug, $trusted = false ) {
		if ( ! in_array( $type, array( 'plugins', 'themes' ), true ) ) {
			return new WP_Error( 'invalid_type', 'Invalid type' );
		}

		// $trusted is only passed by the daily cron (no logged-in user). Admin screens and agents always check the capability.
		$cap = 'plugins' === $type ? 'install_plugins' : 'install_themes';
		if ( ! $trusted && ! current_user_can( $cap ) ) {
			return new WP_Error( 'permission_denied', 'Permission denied' );
		}

		// Verify the item exists in the catalog and is not excluded.
		$statuses = self::items_status();
		if ( ! isset( $statuses[ $slug ] ) ) {
			return new WP_Error( 'not_in_catalog', 'Item not found in catalog' );
		}

		if ( 'excluded' === $statuses[ $slug ]['state'] ) {
			return new WP_Error( 'excluded', 'Item is excluded' );
		}

		if ( $statuses[ $slug ]['installed'] ) {
			return new WP_Error( 'already_installed', 'Item is already installed' );
		}

		// Require upgrader files.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$package = self::MARKER_PREFIX . $type . '/' . rawurlencode( $slug );

		if ( 'plugins' === $type ) {
			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		} else {
			$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		}
		$result = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The skin always holds a WP_Error object; only a non-empty one means failure.
		$skin_errors = method_exists( $upgrader->skin, 'get_errors' ) ? $upgrader->skin->get_errors() : null;
		if ( is_wp_error( $skin_errors ) && $skin_errors->has_errors() ) {
			return $skin_errors;
		}

		if ( true !== $result ) {
			$detail = method_exists( $upgrader->skin, 'get_error_messages' ) ? $upgrader->skin->get_error_messages() : '';
			return new WP_Error( 'install_failed', trim( 'Install failed. ' . $detail ) );
		}

		if ( 'plugins' === $type && self::get()['activate_after_install'] ) {
			$activated = activate_plugin( $statuses[ $slug ]['file'] );
			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
		}

		return true;
	}

	/**
	 * Install all missing, non-excluded items.
	 *
	 * @return array<string, bool|string> slug => true or error message.
	 */
	public static function install_missing( $trusted = false ) {
		$statuses = self::items_status();
		$results  = array();

		foreach ( $statuses as $slug => $status ) {
			if ( 'missing' === $status['state'] ) {
				$type   = $status['type'];
				$result = self::install( $type, $slug, $trusted );
				$results[ $slug ] = is_wp_error( $result ) ? $result->get_error_message() : true;
			}
		}

		return $results;
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		Agentic_Autopilot_Blueprint_GitHub::init();

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_updates' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'inject_theme_updates' ) );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update_plugin' ), 10, 2 );
		add_filter( 'auto_update_theme', array( __CLASS__, 'auto_update_theme' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_from_blueprint' ), 10, 4 );

		$settings = self::get();
		if ( $settings['auto_install'] && ! self::paused() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::CRON_HOOK );
			}
		} else {
			wp_unschedule_event( wp_next_scheduled( self::CRON_HOOK ), self::CRON_HOOK );
		}

		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_install_missing' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_agentic_autopilot_bp_settings', array( __CLASS__, 'handle_settings' ) );
			add_action( 'admin_post_agentic_autopilot_bp_install', array( __CLASS__, 'handle_install' ) );
			add_action( 'admin_post_agentic_autopilot_bp_exclude', array( __CLASS__, 'handle_exclude' ) );
			add_action( 'admin_post_agentic_autopilot_bp_install_all', array( __CLASS__, 'handle_install_all' ) );
			add_action( 'admin_post_agentic_autopilot_bp_refresh_catalog', array( __CLASS__, 'handle_refresh_catalog' ) );
		}

		if ( function_exists( 'wp_register_ability' ) && $settings['expose_to_agents'] && ! self::paused() ) {
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_abilities_category' ) );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		}
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'save' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Add admin menu.
	 */
	public static function menu() {
		add_options_page(
			__( 'Blueprint', 'agentic-autopilot' ),
			__( 'Blueprint', 'agentic-autopilot' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}

		$settings = self::get();
		$paused   = self::paused();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Blueprint', 'agentic-autopilot' ); ?></h1>

			<?php
			// Show notices from query args.
			if ( isset( $_GET['aa_bp'] ) ) {
				$code = sanitize_text_field( wp_unslash( $_GET['aa_bp'] ) );
				$msg  = isset( $_GET['aa_bp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['aa_bp_msg'] ) ) : '';
				if ( 'error' === $code ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
				} elseif ( 'success' === $code ) {
					echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
				}
			}

			// Paused notice.
			if ( $paused ) {
				echo '<div class="notice notice-warning"><p>';
				esc_html_e( 'Paused: the REIKA Blueprint plugin is active on this site and manages these items. Deactivate it to let Blueprint take over.', 'agentic-autopilot' );
				echo '</p></div>';
			}
			?>

			<?php Agentic_Autopilot_Blueprint_GitHub::render_connection_box(); ?>

			<h2><?php esc_html_e( 'Repository Settings', 'agentic-autopilot' ); ?></h2>
			<form method="post" action="admin-post.php">
				<?php wp_nonce_field( 'agentic_autopilot_bp_settings' ); ?>
				<input type="hidden" name="action" value="agentic_autopilot_bp_settings">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="bp_repo"><?php esc_html_e( 'Repository', 'agentic-autopilot' ); ?></label>
						</th>
						<td>
							<input type="text" id="bp_repo" name="<?php echo esc_attr( self::OPTION ); ?>[repo]"
								value="<?php echo esc_attr( $settings['repo'] ); ?>" class="regular-text"
								list="bp_repos" />
							<?php
							$token_source = Agentic_Autopilot_Blueprint_GitHub::token_source();
							if ( 'none' !== $token_source ) {
								$repos = Agentic_Autopilot_Blueprint_GitHub::repos();
								if ( ! is_wp_error( $repos ) ) {
									echo '<datalist id="bp_repos">';
									foreach ( $repos as $repo_data ) {
										echo '<option value="' . esc_attr( $repo_data['full_name'] ) . '">';
									}
									echo '</datalist>';
								}
							}
							?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="bp_ref"><?php esc_html_e( 'Branch/Ref', 'agentic-autopilot' ); ?></label>
						</th>
						<td>
							<input type="text" id="bp_ref" name="<?php echo esc_attr( self::OPTION ); ?>[ref]"
								value="<?php echo esc_attr( $settings['ref'] ); ?>" class="regular-text"
								placeholder="<?php esc_attr_e( 'Leave empty for default branch', 'agentic-autopilot' ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Catalog Path', 'agentic-autopilot' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[path]"
								value="<?php echo esc_attr( $settings['path'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Path to catalog.json in the repository', 'agentic-autopilot' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_install]"
									<?php checked( $settings['auto_install'] ); ?> />
								<?php esc_html_e( 'Automatically install missing items (daily)', 'agentic-autopilot' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[activate_after_install]"
									<?php checked( $settings['activate_after_install'] ); ?> />
								<?php esc_html_e( 'Activate plugins after install', 'agentic-autopilot' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_update]"
									<?php checked( $settings['auto_update'] ); ?> />
								<?php esc_html_e( 'Automatically update managed items', 'agentic-autopilot' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[expose_to_agents]"
									<?php checked( $settings['expose_to_agents'] ); ?> />
								<?php esc_html_e( 'Let AI agents use Blueprint (MCP abilities)', 'agentic-autopilot' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'agentic-autopilot' ) ); ?>
			</form>

			<?php
			if ( $settings['repo'] ) {
				$catalog_data = self::catalog();
				if ( is_wp_error( $catalog_data ) ) {
					echo '<div class="notice notice-error"><p>';
					echo esc_html( $catalog_data->get_error_message() );
					echo '</p></div>';
				} else {
					self::render_catalog_table( $catalog_data );
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the catalog table.
	 *
	 * @param array{items: array, config: array, warnings: array} $catalog_data Catalog data.
	 */
	public static function render_catalog_table( $catalog_data ) {
		$statuses = self::items_status();
		$settings = self::get();

		?>
		<h2><?php esc_html_e( 'Catalog', 'agentic-autopilot' ); ?></h2>

		<form method="post" action="admin-post.php" style="display: inline;">
			<?php wp_nonce_field( 'agentic_autopilot_bp_install_all' ); ?>
			<input type="hidden" name="action" value="agentic_autopilot_bp_install_all">
			<?php submit_button( __( 'Install All Missing', 'agentic-autopilot' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="admin-post.php" style="display: inline; margin-left: 10px;">
			<?php wp_nonce_field( 'agentic_autopilot_bp_refresh_catalog' ); ?>
			<input type="hidden" name="action" value="agentic_autopilot_bp_refresh_catalog">
			<?php submit_button( __( 'Refresh Catalog', 'agentic-autopilot' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php
		if ( ! empty( $catalog_data['warnings'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'Catalog warnings:', 'agentic-autopilot' );
			echo '<ul style="margin: 5px 0 0 20px;">';
			foreach ( $catalog_data['warnings'] as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></p></div>';
		}
		?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'agentic-autopilot' ); ?></th>
					<th><?php esc_html_e( 'Type', 'agentic-autopilot' ); ?></th>
					<th><?php esc_html_e( 'Installed', 'agentic-autopilot' ); ?></th>
					<th><?php esc_html_e( 'Available', 'agentic-autopilot' ); ?></th>
					<th><?php esc_html_e( 'Status', 'agentic-autopilot' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'agentic-autopilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $catalog_data['items'] as $item ) {
					$slug = $item['slug'];
					if ( ! isset( $statuses[ $slug ] ) ) {
						continue;
					}
					$status = $statuses[ $slug ];
					$type   = $status['type'];

					echo '<tr>';
					echo '<td><strong>' . esc_html( $item['name'] ) . '</strong></td>';
					echo '<td>' . esc_html( $type ) . '</td>';
					echo '<td>' . ( $status['installed'] ? esc_html__( 'Yes', 'agentic-autopilot' ) : esc_html__( 'No', 'agentic-autopilot' ) ) . '</td>';
					echo '<td>' . esc_html( $status['remote_version'] ) . '</td>';

					// Status badge.
					$state_labels = array(
						'missing'   => __( 'Missing', 'agentic-autopilot' ),
						'current'   => __( 'Current', 'agentic-autopilot' ),
						'update'    => __( 'Update', 'agentic-autopilot' ),
						'excluded'  => __( 'Excluded', 'agentic-autopilot' ),
					);
					$state_class  = array(
						'missing'  => 'error',
						'current'  => 'success',
						'update'   => 'warning',
						'excluded' => 'info',
					);
					echo '<td><span class="components-notice__content" style="color: #' . esc_attr( array_search( $state_class[ $status['state'] ], array( 'error' => 'd63638', 'success' => '008000', 'warning' => 'f0ad4e', 'info' => '0066cc' ), true ) ) . ';">';
					echo esc_html( $state_labels[ $status['state'] ] );
					echo '</span></td>';

					echo '<td>';
					if ( 'missing' === $status['state'] && current_user_can( 'install_plugins' ) ) {
						echo '<form method="post" action="admin-post.php" style="display: inline;">';
						wp_nonce_field( 'agentic_autopilot_bp_install_' . $slug );
						echo '<input type="hidden" name="action" value="agentic_autopilot_bp_install">';
						echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
						echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
						submit_button( __( 'Install', 'agentic-autopilot' ), 'small', 'submit', false );
						echo '</form> ';
					}

					// Exclude/Include toggle.
					$excluded_key = $type . ':' . $slug;
					$is_excluded  = in_array( $excluded_key, $settings['excluded'], true );
					echo '<form method="post" action="admin-post.php" style="display: inline;">';
					wp_nonce_field( 'agentic_autopilot_bp_exclude_' . $slug );
					echo '<input type="hidden" name="action" value="agentic_autopilot_bp_exclude">';
					echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
					echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
					submit_button(
						$is_excluded ? __( 'Include', 'agentic-autopilot' ) : __( 'Exclude', 'agentic-autopilot' ),
						'small',
						'submit',
						false
					);
					echo '</form>';
					echo '</td>';
					echo '</tr>';
				}
				?>
			</tbody>
		</table>

		<?php
		// Config files list.
		if ( ! empty( $catalog_data['config'] ) ) {
			echo '<h2>' . esc_html__( 'Config Files', 'agentic-autopilot' ) . '</h2>';
			echo '<p>' . esc_html__( 'Blueprint does not import settings. Use each plugin\'s own import tool, a script, or an AI agent.', 'agentic-autopilot' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'agentic-autopilot' ) . '</th><th>' . esc_html__( 'For', 'agentic-autopilot' ) . '</th><th>' . esc_html__( 'Notes', 'agentic-autopilot' ) . '</th><th>' . esc_html__( 'Link', 'agentic-autopilot' ) . '</th></tr></thead><tbody>';
			foreach ( $catalog_data['config'] as $config ) {
				$slug = $config['slug'];
				$name = isset( $config['name'] ) ? $config['name'] : $slug;
				$for  = isset( $config['for'] ) ? $config['for'] : '';
				$notes = isset( $config['notes'] ) ? $config['notes'] : '';
				$location = $config['location'];

				$url = 'https://github.com/' . $settings['repo'] . '/blob/' . ( $settings['ref'] ? $settings['ref'] : 'HEAD' ) . '/' . $location;

				echo '<tr>';
				echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
				echo '<td>' . esc_html( $for ) . '</td>';
				echo '<td>' . esc_html( $notes ) . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'View', 'agentic-autopilot' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Handle settings form submission.
	 */
	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_autopilot_bp_settings' ) ) {
			wp_die( esc_html__( 'Security check failed', 'agentic-autopilot' ) );
		}

		if ( ! isset( $_POST[ self::OPTION ] ) || ! is_array( $_POST[ self::OPTION ] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => 'error', 'aa_bp_msg' => 'Missing data' ), admin_url( 'options-general.php' ) ) );
			exit;
		}

		$input = wp_unslash( $_POST[ self::OPTION ] );
		$input = array_map( 'sanitize_text_field', $input );

		// Sanitize excluded array if present.
		if ( isset( $input['excluded'] ) && is_array( $input['excluded'] ) ) {
			$input['excluded'] = array_map( 'sanitize_text_field', $input['excluded'] );
		}

		self::save( $input );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => 'success', 'aa_bp_msg' => 'Settings saved' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Handle install action.
	 */
	public static function handle_install() {
		if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'install_themes' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		if ( ! isset( $_POST['_wpnonce'] ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
			exit;
		}

		$nonce_action = 'agentic_autopilot_bp_install_' . $slug;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed', 'agentic-autopilot' ) );
		}

		$cap = 'plugins' === $type ? 'install_plugins' : 'install_themes';
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}

		$result = self::install( $type, $slug );
		$msg    = is_wp_error( $result ) ? $result->get_error_message() : 'Installed';

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => is_wp_error( $result ) ? 'error' : 'success', 'aa_bp_msg' => $msg ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Handle exclude/include toggle.
	 */
	public static function handle_exclude() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		if ( ! isset( $_POST['_wpnonce'] ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
			exit;
		}

		$nonce_action = 'agentic_autopilot_bp_exclude_' . $slug;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed', 'agentic-autopilot' ) );
		}

		$settings = self::get();
		$excluded_key = $type . ':' . $slug;

		if ( in_array( $excluded_key, $settings['excluded'], true ) ) {
			$settings['excluded'] = array_diff( $settings['excluded'], array( $excluded_key ) );
		} else {
			$settings['excluded'][] = $excluded_key;
		}

		update_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => 'success', 'aa_bp_msg' => 'Updated' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Admin-post: install every missing, non-excluded item.
	 */
	public static function handle_install_all() {
		check_admin_referer( 'agentic_autopilot_bp_install_all' );
		if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'install_themes' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}
		$failed = array();
		$done   = 0;
		foreach ( self::install_missing() as $slug => $result ) {
			if ( true === $result ) {
				++$done;
			} else {
				$failed[] = $slug . ' (' . $result . ')';
			}
		}
		/* translators: %d: number of items installed. */
		$msg = sprintf( __( 'Installed %d item(s).', 'agentic-autopilot' ), $done );
		if ( $failed ) {
			$msg .= ' ' . __( 'Failed:', 'agentic-autopilot' ) . ' ' . implode( ', ', $failed );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => $failed ? 'error' : 'success', 'aa_bp_msg' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Admin-post: drop the cached catalog so the next view reads it fresh from GitHub.
	 */
	public static function handle_refresh_catalog() {
		check_admin_referer( 'agentic_autopilot_bp_refresh_catalog' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'agentic-autopilot' ) );
		}
		self::clear_catalog_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'aa_bp' => 'success', 'aa_bp_msg' => rawurlencode( __( 'Catalog refreshed.', 'agentic-autopilot' ) ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Inject plugin update offers.
	 *
	 * @param object|array $transient Transient data.
	 * @return object|array
	 */
	public static function inject_plugin_updates( $transient ) {
		if ( self::paused() || ! is_object( $transient ) ) {
			return $transient;
		}

		$settings = self::get();
		if ( ! $settings['repo'] ) {
			return $transient;
		}

		$catalog_data = self::catalog();
		if ( is_wp_error( $catalog_data ) ) {
			return $transient;
		}

		$statuses = self::items_status();
		$excluded = array_flip( $settings['excluded'] );

		foreach ( $catalog_data['items'] as $item ) {
			if ( 'plugins' !== $item['type'] ) {
				continue;
			}

			$slug = $item['slug'];
			if ( ! isset( $statuses[ $slug ] ) || ! $statuses[ $slug ]['installed'] ) {
				continue;
			}

			$excluded_key = 'plugins:' . $slug;
			if ( isset( $excluded[ $excluded_key ] ) ) {
				continue;
			}

			$local_version  = $statuses[ $slug ]['local_version'];
			$remote_version = $item['version'];

			if ( version_compare( $remote_version, $local_version, '>' ) ) {
				$file = $statuses[ $slug ]['file'];

				// Check if another source already set a response with a version >= ours.
				if ( isset( $transient->response[ $file ] ) ) {
					$existing = $transient->response[ $file ];
					if ( isset( $existing->new_version ) && version_compare( $existing->new_version, $remote_version, '>=' ) ) {
						continue;
					}
				}

				$package = self::MARKER_PREFIX . 'plugins/' . rawurlencode( $slug );

				$transient->response[ $file ] = (object) array(
					'slug'        => $slug,
					'plugin'      => $file,
					'new_version' => $remote_version,
					'package'     => $package,
					'url'         => 'https://github.com/' . $settings['repo'],
					'id'          => 'agentic-autopilot-blueprint/' . $slug,
				);
			}
		}

		return $transient;
	}

	/**
	 * Inject theme update offers.
	 *
	 * @param object|array $transient Transient data.
	 * @return object|array
	 */
	public static function inject_theme_updates( $transient ) {
		if ( self::paused() || ! is_array( $transient ) ) {
			return $transient;
		}

		$settings = self::get();
		if ( ! $settings['repo'] ) {
			return $transient;
		}

		$catalog_data = self::catalog();
		if ( is_wp_error( $catalog_data ) ) {
			return $transient;
		}

		$statuses = self::items_status();
		$excluded = array_flip( $settings['excluded'] );

		foreach ( $catalog_data['items'] as $item ) {
			if ( 'themes' !== $item['type'] ) {
				continue;
			}

			$slug = $item['slug'];
			if ( ! isset( $statuses[ $slug ] ) || ! $statuses[ $slug ]['installed'] ) {
				continue;
			}

			$excluded_key = 'themes:' . $slug;
			if ( isset( $excluded[ $excluded_key ] ) ) {
				continue;
			}

			$local_version  = $statuses[ $slug ]['local_version'];
			$remote_version = $item['version'];

			if ( version_compare( $remote_version, $local_version, '>' ) ) {
				// Check if another source already set a response with a version >= ours.
				if ( isset( $transient[ $slug ] ) ) {
					$existing = $transient[ $slug ];
					if ( isset( $existing['new_version'] ) && version_compare( $existing['new_version'], $remote_version, '>=' ) ) {
						continue;
					}
				}

				$package = self::MARKER_PREFIX . 'themes/' . rawurlencode( $slug );

				$transient[ $slug ] = array(
					'theme'       => $slug,
					'new_version' => $remote_version,
					'package'     => $package,
					'url'         => 'https://github.com/' . $settings['repo'],
				);
			}
		}

		return $transient;
	}

	/**
	 * Auto-update plugin filter.
	 *
	 * @param bool|null $value Original value.
	 * @param object    $item Item object with slug property.
	 * @return bool|null
	 */
	public static function auto_update_plugin( $value, $item ) {
		if ( self::paused() ) {
			return $value;
		}

		$settings = self::get();
		if ( ! $settings['auto_update'] || ! $settings['repo'] ) {
			return $value;
		}

		// Extract slug from file path (e.g., "slug/main.php" -> "slug").
		$slug = isset( $item->slug ) ? $item->slug : '';
		if ( ! $slug ) {
			return $value;
		}

		$excluded = array_flip( $settings['excluded'] );
		if ( isset( $excluded[ 'plugins:' . $slug ] ) ) {
			return $value;
		}

		// Check if in catalog.
		$statuses = self::items_status();
		if ( isset( $statuses[ $slug ] ) && 'update' === $statuses[ $slug ]['state'] ) {
			return true;
		}

		return $value;
	}

	/**
	 * Auto-update theme filter.
	 *
	 * @param bool|null $value Original value.
	 * @param object    $item Item object with stylesheet property.
	 * @return bool|null
	 */
	public static function auto_update_theme( $value, $item ) {
		if ( self::paused() ) {
			return $value;
		}

		$settings = self::get();
		if ( ! $settings['auto_update'] || ! $settings['repo'] ) {
			return $value;
		}

		// Extract slug from stylesheet (e.g., "slug" or path).
		$slug = isset( $item->stylesheet ) ? $item->stylesheet : '';
		if ( ! $slug ) {
			return $value;
		}

		$excluded = array_flip( $settings['excluded'] );
		if ( isset( $excluded[ 'themes:' . $slug ] ) ) {
			return $value;
		}

		// Check if in catalog.
		$statuses = self::items_status();
		if ( isset( $statuses[ $slug ] ) && 'update' === $statuses[ $slug ]['state'] ) {
			return true;
		}

		return $value;
	}

	/**
	 * Download hook: intercept Blueprint marker packages and download from GitHub.
	 *
	 * @param false|string|WP_Error $reply The original reply.
	 * @param string                $package Package URL/path.
	 * @param WP_Upgrader           $upgrader Upgrader instance.
	 * @param array                 $hook_extra Hook extra data.
	 * @return false|string|WP_Error
	 */
	public static function download_from_blueprint( $reply, $package, $upgrader, $hook_extra ) {
		if ( 0 !== strpos( $package, self::MARKER_PREFIX ) ) {
			return $reply;
		}

		$settings = self::get();
		if ( ! $settings['repo'] ) {
			return new WP_Error( 'no_repo', 'No Blueprint repository configured' );
		}

		// Parse the marker: MARKER_PREFIX . type / slug.
		$path = substr( $package, strlen( self::MARKER_PREFIX ) );
		$parts = explode( '/', $path );
		if ( count( $parts ) < 2 ) {
			return new WP_Error( 'invalid_marker', 'Invalid Blueprint marker' );
		}

		$type = $parts[0];
		$slug = rawurldecode( $parts[1] );

		// Find the item in the catalog.
		$catalog_data = self::catalog();
		if ( is_wp_error( $catalog_data ) ) {
			return $catalog_data;
		}

		$item = null;
		foreach ( $catalog_data['items'] as $candidate ) {
			if ( $candidate['slug'] === $slug && $candidate['type'] === $type ) {
				$item = $candidate;
				break;
			}
		}

		if ( ! $item ) {
			return new WP_Error( 'item_not_found', 'Item not found in Blueprint catalog' );
		}

		// Check if excluded.
		$excluded_key = $type . ':' . $slug;
		if ( in_array( $excluded_key, $settings['excluded'], true ) ) {
			return new WP_Error( 'excluded', 'Item is excluded' );
		}

		// Download from GitHub.
		return Agentic_Autopilot_Blueprint_GitHub::download( $settings['repo'], $item['location'], $settings['ref'] );
	}

	/**
	 * Cron callback: install missing items.
	 */
	public static function cron_install_missing() {
		if ( ! wp_is_file_mod_allowed( 'agentic_autopilot_blueprint' ) ) {
			return;
		}

		self::install_missing( true );
	}

	/**
	 * Deactivation hook: unschedule cron.
	 */
	public static function deactivate() {
		wp_unschedule_event( wp_next_scheduled( self::CRON_HOOK ), self::CRON_HOOK );
	}

	/**
	 * Register abilities category.
	 */
	public static function register_abilities_category() {
		wp_register_ability_category(
			'agentic-autopilot',
			array(
				'label'       => __( 'Agentic Autopilot', 'agentic-autopilot' ),
				'description' => __( 'Blueprint and site management', 'agentic-autopilot' ),
			)
		);
	}

	/**
	 * Register abilities for agents.
	 */
	public static function register_abilities() {
		wp_register_ability(
			'agentic-autopilot/blueprint-list',
			array(
				'label'               => __( 'List Blueprint Items', 'agentic-autopilot' ),
				'description'         => __( 'Get the status of all Blueprint items and config files', 'agentic-autopilot' ),
				'category'            => 'agentic-autopilot',
				'execute_callback'    => array( __CLASS__, 'ability_list' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' );
				},
				'output_schema'       => array(
					'type'  => 'object',
					'properties' => array(
						'items' => array(
							'type' => 'array',
							'description' => __( 'Installed and available items', 'agentic-autopilot' ),
						),
						'config' => array(
							'type' => 'array',
							'description' => __( 'Config files available for import', 'agentic-autopilot' ),
						),
					),
				),
				'meta'                => array(
					'public' => true,
					'mcp'    => array( 'public' => true ),
				),
			)
		);

		wp_register_ability(
			'agentic-autopilot/blueprint-install',
			array(
				'label'               => __( 'Install Blueprint Item', 'agentic-autopilot' ),
				'description'         => __( 'Install a plugin or theme from Blueprint', 'agentic-autopilot' ),
				'category'            => 'agentic-autopilot',
				'execute_callback'    => array( __CLASS__, 'ability_install' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' ) || current_user_can( 'install_themes' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array(
							'type'        => 'string',
							'enum'        => array( 'plugins', 'themes' ),
							'description' => __( 'Plugin or theme', 'agentic-autopilot' ),
						),
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'Item slug', 'agentic-autopilot' ),
						),
					),
					'required'   => array( 'type', 'slug' ),
				),
				'output_schema'       => array(
					'type'  => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Installation successful', 'agentic-autopilot' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Status or error message', 'agentic-autopilot' ),
						),
					),
				),
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'public' => true ),
					'destructive' => true,
				),
			)
		);

		wp_register_ability(
			'agentic-autopilot/blueprint-get-config',
			array(
				'label'               => __( 'Get Blueprint Config File', 'agentic-autopilot' ),
				'description'         => __( 'Get the content of a Blueprint config file for import', 'agentic-autopilot' ),
				'category'            => 'agentic-autopilot',
				'execute_callback'    => array( __CLASS__, 'ability_get_config' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'Config file slug', 'agentic-autopilot' ),
						),
					),
					'required'   => array( 'slug' ),
				),
				'output_schema'       => array(
					'type'  => 'object',
					'properties' => array(
						'content' => array(
							'type'        => 'string',
							'description' => __( 'File content (max 1 MB)', 'agentic-autopilot' ),
						),
						'error' => array(
							'type'        => 'string',
							'description' => __( 'Error message if not found', 'agentic-autopilot' ),
						),
					),
				),
				'meta'                => array(
					'public' => true,
					'mcp'    => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Ability: list items and config.
	 *
	 * @return array{items: array, config: array}|WP_Error
	 */
	public static function ability_list() {
		$statuses = self::items_status();
		$catalog_data = self::catalog();

		if ( is_wp_error( $catalog_data ) ) {
			return $catalog_data;
		}

		return array(
			'items'  => $statuses,
			'config' => $catalog_data['config'],
		);
	}

	/**
	 * Ability: install an item.
	 *
	 * @param array{type: string, slug: string} $input Input.
	 * @return array{success: bool, message: string}|WP_Error
	 */
	public static function ability_install( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'invalid_input', 'Invalid input' );
		}

		$type = isset( $input['type'] ) ? $input['type'] : '';
		$slug = isset( $input['slug'] ) ? $input['slug'] : '';

		$result = self::install( $type, $slug );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => 'Installed',
		);
	}

	/**
	 * Ability: get config file content.
	 *
	 * @param array{slug: string} $input Input.
	 * @return array{content: string}|array{error: string}|WP_Error
	 */
	public static function ability_get_config( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'invalid_input', 'Invalid input' );
		}

		$slug = isset( $input['slug'] ) ? $input['slug'] : '';

		$catalog_data = self::catalog();
		if ( is_wp_error( $catalog_data ) ) {
			return $catalog_data;
		}

		$config_item = null;
		foreach ( $catalog_data['config'] as $item ) {
			if ( $item['slug'] === $slug ) {
				$config_item = $item;
				break;
			}
		}

		if ( ! $config_item ) {
			return array( 'error' => 'Config file not found' );
		}

		$settings = self::get();
		$content  = Agentic_Autopilot_Blueprint_GitHub::get_contents(
			$settings['repo'],
			$config_item['location'],
			$settings['ref']
		);

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// Limit to 1 MB.
		if ( strlen( $content ) > 1048576 ) {
			return array( 'error' => 'Config file too large (max 1 MB)' );
		}

		return array( 'content' => $content );
	}
}
