<?php
/**
 * Instant navigation through the WordPress core Speculation Rules API (WordPress 6.8+).
 *
 * @package WPAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configures core speculative loading; prints nothing of its own.
 */
final class WPAutopilot_Instant_Navigation {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! WPAutopilot_Settings::get()['instant_navigation'] ) {
			return;
		}
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'exclude_paths' ) );
	}

	/**
	 * Mode and eagerness from the settings. Keeps WordPress' own decision to
	 * disable speculative loading (null), e.g. for logged-in users or when
	 * pretty permalinks are off.
	 *
	 * @param array<string, string>|null $config Core configuration.
	 * @return array<string, string>|null
	 */
	public static function configuration( $config ) {
		if ( null === $config ) {
			return null;
		}
		$s = WPAutopilot_Settings::get();
		return array(
			'mode'      => $s['speculation_mode'],
			'eagerness' => $s['speculation_eagerness'],
		);
	}

	/**
	 * Extra excluded URL path patterns.
	 *
	 * @param string[] $paths Paths already excluded.
	 * @return string[]
	 */
	public static function exclude_paths( $paths ) {
		$extra = array_filter( array_map( 'trim', explode( "\n", WPAutopilot_Settings::get()['speculation_exclude'] ) ) );
		return array_values( array_unique( array_merge( (array) $paths, $extra ) ) );
	}
}
