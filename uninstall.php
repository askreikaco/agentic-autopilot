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

// Delete Blueprint catalog cache transients (stored in sitemeta on multisite, options otherwise).
global $wpdb;
$aa_table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
$aa_col   = is_multisite() ? 'meta_key' : 'option_name';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$aa_table} WHERE {$aa_col} LIKE %s OR {$aa_col} LIKE %s",
		$wpdb->esc_like( '_site_transient_agentic_autopilot_bp_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_agentic_autopilot_bp_' ) . '%'
	)
);

// Clear Blueprint cron hook.
wp_clear_scheduled_hook( 'agentic_autopilot_blueprint_daily' );

// Old names (migration compatibility).
delete_option( 'wpautopilot' );
delete_option( 'reika_site_kit' );
delete_site_transient( 'wpautopilot_latest_release' );
delete_site_transient( 'reika_site_kit_latest_release' );
