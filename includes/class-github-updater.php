<?php
/**
 * Updates from GitHub releases through the core "Update URI" mechanism (WordPress 5.8+).
 *
 * WordPress asks `update_plugins_{hostname}` for plugins whose header declares an
 * Update URI on that host. If this plugin is ever served from WordPress.org,
 * remove the Update URI header and this file (WordPress.org forbids self-updaters).
 *
 * @package WPAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the latest GitHub release and offers it as an update.
 */
final class WPAutopilot_GitHub_Updater {

	const REPO      = 'askreikaco/wp-autopilot';
	const SLUG      = 'wp-autopilot';
	const TRANSIENT = 'wpautopilot_latest_release';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * Latest release from the GitHub API, cached for 6 hours (1 hour after an error).
	 *
	 * @return array{version:string, package:string, url:string, notes:string}|null
	 */
	private static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
				$package = (string) $body['zipball_url'];
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::SLUG . '.zip' === $asset['name'] ) {
						$package = (string) $asset['browser_download_url'];
					}
				}
				$release = array(
					'version' => ltrim( (string) $body['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => (string) $body['html_url'],
					'notes'   => (string) ( $body['body'] ?? '' ),
				);
			}
		}

		set_site_transient( self::TRANSIENT, $release ? $release : array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Offer the release when it is newer than the installed version.
	 *
	 * @param array|false $update      Update data from another source, if any.
	 * @param array       $plugin_data Installed plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( WPAUTOPILOT_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release || version_compare( $release['version'], $plugin_data['Version'], '<=' ) ) {
			return $update;
		}
		return array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.8',
			'requires_php' => '7.4',
		);
	}

	/**
	 * GitHub source archives unpack to "askreikaco-wp-autopilot-<sha>/";
	 * rename that to the plugin folder so the update replaces the plugin in place.
	 *
	 * @param string      $source        Unpacked source path.
	 * @param string      $remote_source Parent directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra data; holds the plugin basename during updates.
	 * @return string|WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( WPAUTOPILOT_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'wpautopilot_rename', __( 'Could not rename the downloaded WP Autopilot folder.', 'wp-autopilot' ) );
	}

	/**
	 * "View details" modal on the Plugins screen.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		return (object) array(
			'name'          => 'WP Autopilot',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : WPAUTOPILOT_VERSION,
			'author'        => '<a href="https://reika.co">REIKA</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.8',
			'requires_php'  => '7.4',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => esc_html__( 'Puts routine site speed and housekeeping on autopilot: instant navigation (prerender on hover) and Jetpack "Monitor only". Every feature is opt-in.', 'wp-autopilot' ),
				'changelog'   => $release ? wp_kses_post( wpautop( $release['notes'] ) ) : '',
			),
		);
	}
}
