<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package AgenticAutopilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// New names (v1.2.0+).
delete_option( 'agentic_autopilot' );
delete_site_transient( 'agentic_autopilot_latest_release' );
delete_transient( 'agentic_autopilot_llms_txt' );

// New names (v1.3.0+).
delete_site_transient( 'agentic_autopilot_mcp_release' );

// New names (v1.4.0+).
delete_option( 'agentic_autopilot_blueprint' );
delete_option( 'agentic_autopilot_bp_token' );

// Delete Blueprint catalog cache transients.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE %s OR meta_key LIKE %s",
		'_site_transient_agentic_autopilot_bp_%',
		'_site_transient_timeout_agentic_autopilot_bp_%'
	)
);

// Clear Blueprint cron hook.
wp_unschedule_event( wp_next_scheduled( 'agentic_autopilot_blueprint_daily' ), 'agentic_autopilot_blueprint_daily' );

// Old names (migration compatibility).
delete_option( 'wpautopilot' );
delete_option( 'reika_site_kit' );
delete_site_transient( 'wpautopilot_latest_release' );
delete_site_transient( 'reika_site_kit_latest_release' );
