<?php
/**
 * Settings: one option, one screen under Settings → Agentic Autopilot.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders the plugin settings.
 */
final class Agentic_Autopilot_Settings {

	const OPTION = 'agentic_autopilot';
	const PAGE   = 'agentic-autopilot';

	/**
	 * Default values. Every feature is off until an admin turns it on.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'instant_navigation'     => false,
			'speculation_mode'       => 'prerender',
			'speculation_eagerness'  => 'moderate',
			'speculation_exclude'    => "/*.pdf\n/*.zip",
			'jetpack_monitor_only'   => false,
			'llms_txt'               => false,
			'llms_txt_intro'         => '',
		);
	}

	/**
	 * Migrate settings from the old option names if needed.
	 */
	public static function maybe_migrate() {
		if ( false === get_option( self::OPTION ) ) {
			// Try wpautopilot first.
			if ( is_array( get_option( 'wpautopilot' ) ) ) {
				update_option( self::OPTION, get_option( 'wpautopilot' ) );
			} elseif ( is_array( get_option( 'reika_site_kit' ) ) ) {
				update_option( self::OPTION, get_option( 'reika_site_kit' ) );
			}
		}
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AGENTIC_AUTOPILOT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$out['instant_navigation']   = ! empty( $input['instant_navigation'] );
		$out['jetpack_monitor_only'] = ! empty( $input['jetpack_monitor_only'] );
		$out['llms_txt']             = ! empty( $input['llms_txt'] );

		if ( isset( $input['speculation_mode'] ) && in_array( $input['speculation_mode'], array( 'prefetch', 'prerender' ), true ) ) {
			$out['speculation_mode'] = $input['speculation_mode'];
		}
		if ( isset( $input['speculation_eagerness'] ) && in_array( $input['speculation_eagerness'], array( 'conservative', 'moderate', 'eager' ), true ) ) {
			$out['speculation_eagerness'] = $input['speculation_eagerness'];
		}

		if ( isset( $input['llms_txt_intro'] ) ) {
			$out['llms_txt_intro'] = sanitize_textarea_field( $input['llms_txt_intro'] );
		}

		$paths = array();
		if ( isset( $input['speculation_exclude'] ) ) {
			foreach ( preg_split( '/\R/', (string) $input['speculation_exclude'] ) as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( '' !== $line && '/' === $line[0] ) {
					$paths[] = $line;
				}
			}
		}
		$out['speculation_exclude'] = implode( "\n", array_unique( $paths ) );

		return $out;
	}

	/**
	 * Admin menu entry.
	 */
	public static function menu() {
		add_options_page(
			__( 'Agentic Autopilot', 'agentic-autopilot' ),
			__( 'Agentic Autopilot', 'agentic-autopilot' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'agentic-autopilot' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::get();
		$name = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agentic Autopilot', 'agentic-autopilot' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<h2><?php esc_html_e( 'Instant navigation', 'agentic-autopilot' ); ?></h2>
				<p><?php esc_html_e( 'Uses the Speculation Rules built into WordPress: when a visitor hovers or presses a link, the next page is loaded in the background so it opens instantly. Admin, login, query-string and nofollow links are always excluded by WordPress. Browsers without support simply ignore it.', 'agentic-autopilot' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'agentic-autopilot' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[instant_navigation]" value="1" <?php checked( $s['instant_navigation'] ); ?>> <?php esc_html_e( 'Load the next page before the click', 'agentic-autopilot' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-mode"><?php esc_html_e( 'Mode', 'agentic-autopilot' ); ?></label></th>
						<td>
							<select id="rsk-mode" name="<?php echo esc_attr( $name ); ?>[speculation_mode]">
								<option value="prerender" <?php selected( $s['speculation_mode'], 'prerender' ); ?>><?php esc_html_e( 'Prerender (fastest: the whole page is rendered)', 'agentic-autopilot' ); ?></option>
								<option value="prefetch" <?php selected( $s['speculation_mode'], 'prefetch' ); ?>><?php esc_html_e( 'Prefetch (lighter: only the HTML is downloaded)', 'agentic-autopilot' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-eager"><?php esc_html_e( 'When', 'agentic-autopilot' ); ?></label></th>
						<td>
							<select id="rsk-eager" name="<?php echo esc_attr( $name ); ?>[speculation_eagerness]">
								<option value="moderate" <?php selected( $s['speculation_eagerness'], 'moderate' ); ?>><?php esc_html_e( 'Moderate: on hover (recommended)', 'agentic-autopilot' ); ?></option>
								<option value="conservative" <?php selected( $s['speculation_eagerness'], 'conservative' ); ?>><?php esc_html_e( 'Conservative: on click/tap', 'agentic-autopilot' ); ?></option>
								<option value="eager" <?php selected( $s['speculation_eagerness'], 'eager' ); ?>><?php esc_html_e( 'Eager: as soon as possible', 'agentic-autopilot' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-exclude"><?php esc_html_e( 'Also exclude', 'agentic-autopilot' ); ?></label></th>
						<td>
							<textarea id="rsk-exclude" class="large-text code" rows="4" name="<?php echo esc_attr( $name ); ?>[speculation_exclude]"><?php echo esc_textarea( $s['speculation_exclude'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One path pattern per line, starting with "/" (for example /*.pdf or /checkout/*). Add the class no-prerender to any link or container to exclude it.', 'agentic-autopilot' ); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Jetpack', 'agentic-autopilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Monitor only', 'agentic-autopilot' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[jetpack_monitor_only]" value="1" <?php checked( $s['jetpack_monitor_only'] ); ?>> <?php esc_html_e( 'Keep only the Downtime Monitor module; every other Jetpack module (stats, forms, subscriptions…) is unavailable and cannot be switched on.', 'agentic-autopilot' ); ?></label>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'AI agents (llms.txt)', 'agentic-autopilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve /llms.txt', 'agentic-autopilot' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[llms_txt]" value="1" <?php checked( $s['llms_txt'] ); ?>> <?php esc_html_e( 'Publish a site index for AI agents and AI search at /llms.txt.', 'agentic-autopilot' ); ?></label>
							<p class="description"><a href="https://llmstxt.org" target="_blank" rel="noopener">llmstxt.org</a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aa-llms-intro"><?php esc_html_e( 'Intro (optional)', 'agentic-autopilot' ); ?></label></th>
						<td>
							<textarea id="aa-llms-intro" class="large-text" rows="4" name="<?php echo esc_attr( $name ); ?>[llms_txt_intro]"><?php echo esc_textarea( $s['llms_txt_intro'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Custom introductory text to include in the llms.txt file before the pages and posts.', 'agentic-autopilot' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
