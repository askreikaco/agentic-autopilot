<?php
/**
 * Plugin Name:       Agentic Autopilot
 * Plugin URI:        https://github.com/askreikaco/agentic-autopilot
 * Description:       Make WordPress feel instant and ready for AI agents: llms.txt for AI search, speculative prerender on hover, Jetpack Monitor-only mode, MCP Adapter installation, and GitHub auto-updates.
 * Version:           1.3.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            REIKA
 * Author URI:        https://reika.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentic-autopilot
 * Update URI:        https://github.com/askreikaco/agentic-autopilot
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENTIC_AUTOPILOT_VERSION', '1.3.0' );
define( 'AGENTIC_AUTOPILOT_FILE', __FILE__ );
define( 'AGENTIC_AUTOPILOT_DIR', plugin_dir_path( __FILE__ ) );

require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-settings.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-instant-navigation.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-jetpack-monitor-only.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-github-updater.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-llms-txt.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-mcp-adapter.php';

add_action(
	'plugins_loaded',
	static function () {
		// Each module boots on its own: an unexpected error in one is logged and the others (and the site) keep working.
		$modules = array(
			array( 'Agentic_Autopilot_Settings', 'maybe_migrate' ),
			array( 'Agentic_Autopilot_Settings', 'init' ),
			array( 'Agentic_Autopilot_Instant_Navigation', 'init' ),
			array( 'Agentic_Autopilot_Jetpack_Monitor_Only', 'init' ),
			array( 'Agentic_Autopilot_GitHub_Updater', 'init' ),
			array( 'Agentic_Autopilot_Llms_Txt', 'init' ),
			array( 'Agentic_Autopilot_Mcp_Adapter', 'init' ),
		);
		foreach ( $modules as $module ) {
			try {
				call_user_func( $module );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Agentic Autopilot: ' . implode( '::', $module ) . ' failed: ' . $e->getMessage() );
			}
		}
	},
	1
);
