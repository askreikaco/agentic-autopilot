<?php
/**
 * Plugin Name:       REIKA Site Kit
 * Plugin URI:        https://github.com/askreikaco/reika-site-kit
 * Description:       Small, opt-in site-wide features: instant navigation (prerender on hover) and Jetpack "Monitor only".
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            REIKA
 * Author URI:        https://reika.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reika-site-kit
 * Update URI:        https://github.com/askreikaco/reika-site-kit
 *
 * @package ReikaSiteKit
 */

defined( 'ABSPATH' ) || exit;

define( 'REIKA_SITE_KIT_VERSION', '1.0.0' );
define( 'REIKA_SITE_KIT_FILE', __FILE__ );
define( 'REIKA_SITE_KIT_DIR', plugin_dir_path( __FILE__ ) );

require_once REIKA_SITE_KIT_DIR . 'includes/class-settings.php';
require_once REIKA_SITE_KIT_DIR . 'includes/class-instant-navigation.php';
require_once REIKA_SITE_KIT_DIR . 'includes/class-jetpack-monitor-only.php';
require_once REIKA_SITE_KIT_DIR . 'includes/class-github-updater.php';

add_action(
	'plugins_loaded',
	static function () {
		Reika_Site_Kit_Settings::init();
		Reika_Site_Kit_Instant_Navigation::init();
		Reika_Site_Kit_Jetpack_Monitor_Only::init();
		Reika_Site_Kit_GitHub_Updater::init();
	},
	1
);
