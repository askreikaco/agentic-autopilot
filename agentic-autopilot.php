<?php
/**
 * Plugin Name:       Agentic Autopilot
 * Plugin URI:        https://github.com/askreikaco/agentic-autopilot
 * Description:       Make WordPress feel instant and ready for AI agents: llms.txt for AI search, speculative prerender on hover, Jetpack Monitor-only mode, and GitHub auto-updates.
 * Version:           1.2.0
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

define( 'AGENTIC_AUTOPILOT_VERSION', '1.2.0' );
define( 'AGENTIC_AUTOPILOT_FILE', __FILE__ );
define( 'AGENTIC_AUTOPILOT_DIR', plugin_dir_path( __FILE__ ) );

require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-settings.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-instant-navigation.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-jetpack-monitor-only.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-github-updater.php';
require_once AGENTIC_AUTOPILOT_DIR . 'includes/class-llms-txt.php';

add_action(
	'plugins_loaded',
	static function () {
		Agentic_Autopilot_Settings::maybe_migrate();
		Agentic_Autopilot_Settings::init();
		Agentic_Autopilot_Instant_Navigation::init();
		Agentic_Autopilot_Jetpack_Monitor_Only::init();
		Agentic_Autopilot_GitHub_Updater::init();
		Agentic_Autopilot_Llms_Txt::init();
	},
	1
);
