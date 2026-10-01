<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package ReikaSiteKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'reika_site_kit' );
delete_site_transient( 'reika_site_kit_latest_release' );
