<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package WPAutopilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpautopilot' );
delete_option( 'reika_site_kit' );
delete_site_transient( 'wpautopilot_latest_release' );
delete_site_transient( 'reika_site_kit_latest_release' );
