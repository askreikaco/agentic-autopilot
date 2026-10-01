<?php
/**
 * Settings: one option, one screen under Settings → REIKA Site Kit.
 *
 * @package ReikaSiteKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and renders the plugin settings.
 */
final class Reika_Site_Kit_Settings {

	const OPTION = 'reika_site_kit';
	const PAGE   = 'reika-site-kit';

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
		);
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
		add_filter( 'plugin_action_links_' . plugin_basename( REIKA_SITE_KIT_FILE ), array( __CLASS__, 'action_links' ) );
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

		if ( isset( $input['speculation_mode'] ) && in_array( $input['speculation_mode'], array( 'prefetch', 'prerender' ), true ) ) {
			$out['speculation_mode'] = $input['speculation_mode'];
		}
		if ( isset( $input['speculation_eagerness'] ) && in_array( $input['speculation_eagerness'], array( 'conservative', 'moderate', 'eager' ), true ) ) {
			$out['speculation_eagerness'] = $input['speculation_eagerness'];
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
			__( 'REIKA Site Kit', 'reika-site-kit' ),
			__( 'REIKA Site Kit', 'reika-site-kit' ),
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
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'reika-site-kit' ) . '</a>' );
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
			<h1><?php esc_html_e( 'REIKA Site Kit', 'reika-site-kit' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<h2><?php esc_html_e( 'Instant navigation', 'reika-site-kit' ); ?></h2>
				<p><?php esc_html_e( 'Uses the Speculation Rules built into WordPress: when a visitor hovers or presses a link, the next page is loaded in the background so it opens instantly. Admin, login, query-string and nofollow links are always excluded by WordPress. Browsers without support simply ignore it.', 'reika-site-kit' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'reika-site-kit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[instant_navigation]" value="1" <?php checked( $s['instant_navigation'] ); ?>> <?php esc_html_e( 'Load the next page before the click', 'reika-site-kit' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-mode"><?php esc_html_e( 'Mode', 'reika-site-kit' ); ?></label></th>
						<td>
							<select id="rsk-mode" name="<?php echo esc_attr( $name ); ?>[speculation_mode]">
								<option value="prerender" <?php selected( $s['speculation_mode'], 'prerender' ); ?>><?php esc_html_e( 'Prerender (fastest: the whole page is rendered)', 'reika-site-kit' ); ?></option>
								<option value="prefetch" <?php selected( $s['speculation_mode'], 'prefetch' ); ?>><?php esc_html_e( 'Prefetch (lighter: only the HTML is downloaded)', 'reika-site-kit' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-eager"><?php esc_html_e( 'When', 'reika-site-kit' ); ?></label></th>
						<td>
							<select id="rsk-eager" name="<?php echo esc_attr( $name ); ?>[speculation_eagerness]">
								<option value="moderate" <?php selected( $s['speculation_eagerness'], 'moderate' ); ?>><?php esc_html_e( 'Moderate: on hover (recommended)', 'reika-site-kit' ); ?></option>
								<option value="conservative" <?php selected( $s['speculation_eagerness'], 'conservative' ); ?>><?php esc_html_e( 'Conservative: on click/tap', 'reika-site-kit' ); ?></option>
								<option value="eager" <?php selected( $s['speculation_eagerness'], 'eager' ); ?>><?php esc_html_e( 'Eager: as soon as possible', 'reika-site-kit' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rsk-exclude"><?php esc_html_e( 'Also exclude', 'reika-site-kit' ); ?></label></th>
						<td>
							<textarea id="rsk-exclude" class="large-text code" rows="4" name="<?php echo esc_attr( $name ); ?>[speculation_exclude]"><?php echo esc_textarea( $s['speculation_exclude'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One path pattern per line, starting with "/" (for example /*.pdf or /checkout/*). Add the class no-prerender to any link or container to exclude it.', 'reika-site-kit' ); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Jetpack', 'reika-site-kit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Monitor only', 'reika-site-kit' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[jetpack_monitor_only]" value="1" <?php checked( $s['jetpack_monitor_only'] ); ?>> <?php esc_html_e( 'Keep only the Downtime Monitor module; every other Jetpack module (stats, forms, subscriptions…) is unavailable and cannot be switched on.', 'reika-site-kit' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
