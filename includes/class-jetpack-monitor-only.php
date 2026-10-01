<?php
/**
 * Jetpack: Downtime Monitor only.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hides every Jetpack module except "monitor". Jetpack only loads modules that
 * are available, so this also switches off any module that was active.
 */
final class Agentic_Autopilot_Jetpack_Monitor_Only {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Agentic_Autopilot_Settings::get()['jetpack_monitor_only'] ) {
			return;
		}
		add_filter( 'jetpack_get_available_modules', array( __CLASS__, 'available_modules' ), 99 );
	}

	/**
	 * Keep only the Monitor module.
	 *
	 * @param array<string, mixed> $modules Available modules keyed by slug.
	 * @return array<string, mixed>
	 */
	public static function available_modules( $modules ) {
		return array_intersect_key( (array) $modules, array( 'monitor' => true ) );
	}
}
