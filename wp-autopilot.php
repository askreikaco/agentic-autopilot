<?php
/**
 * Plugin Name:       WP Autopilot
 * Plugin URI:        https://github.com/askreikaco/wp-autopilot
 * Description:       Puts routine site speed and housekeeping on autopilot: instant navigation (prerender on hover) and Jetpack "Monitor only". Every feature is opt-in.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            REIKA
 * Author URI:        https://reika.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-autopilot
 * Update URI:        https://github.com/askreikaco/wp-autopilot
 *
 * @package WPAutopilot
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAUTOPILOT_VERSION', '1.1.0' );
define( 'WPAUTOPILOT_FILE', __FILE__ );
define( 'WPAUTOPILOT_DIR', plugin_dir_path( __FILE__ ) );

require_once WPAUTOPILOT_DIR . 'includes/class-settings.php';
require_once WPAUTOPILOT_DIR . 'includes/class-instant-navigation.php';
require_once WPAUTOPILOT_DIR . 'includes/class-jetpack-monitor-only.php';
require_once WPAUTOPILOT_DIR . 'includes/class-github-updater.php';

add_action(
	'plugins_loaded',
	static function () {
		WPAutopilot_Settings::maybe_migrate();
		WPAutopilot_Settings::init();
		WPAutopilot_Instant_Navigation::init();
		WPAutopilot_Jetpack_Monitor_Only::init();
		WPAutopilot_GitHub_Updater::init();
	},
	1
);
