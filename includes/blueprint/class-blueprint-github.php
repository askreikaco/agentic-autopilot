<?php
/**
 * Blueprint GitHub gateway: authentication, repo listing, and file operations.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages GitHub authentication and API calls for the Blueprint module.
 *
 * Handles token management (sign-in, personal token, OAuth device flow),
 * repo listing, and file operations with proper authentication.
 */
final class Agentic_Autopilot_Blueprint_GitHub {

	const TOKEN_OPTION = 'agentic_autopilot_bp_token';

	/**
	 * Registers action handlers.
	 */
	public static function init() {
		// AJAX handlers for OAuth device flow and token save.
		add_action( 'wp_ajax_agentic_autopilot_bp_device_start', array( __CLASS__, 'handle_device_start' ) );
		add_action( 'wp_ajax_agentic_autopilot_bp_device_poll', array( __CLASS__, 'handle_device_poll' ) );
		// Admin-post handlers for personal token and disconnect.
		add_action( 'admin_post_agentic_autopilot_bp_token', array( __CLASS__, 'handle_token_save' ) );
		add_action( 'admin_post_agentic_autopilot_bp_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Determines the current token source.
	 *
	 * @return string 'constant' | 'saved' | 'none'
	 */
	public static function token_source() {
		if ( defined( 'AGENTIC_AUTOPILOT_BLUEPRINT_TOKEN' ) && ! empty( AGENTIC_AUTOPILOT_BLUEPRINT_TOKEN ) ) {
			return 'constant';
		}
		if ( self::get_saved_token() ) {
			return 'saved';
		}
		return 'none';
	}

	/**
	 * Checks whether a token is available from any source.
	 *
	 * @return bool
	 */
	public static function has_token() {
		return 'none' !== self::token_source();
	}

	/**
	 * Validates a token with GitHub and saves it encrypted.
	 *
	 * @param string $token GitHub token to validate and save.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function save_token( $token ) {
		// Validate the token by calling the GitHub API.
		$response = wp_safe_remote_get(
			'https://api.github.com/user',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'    => 'Agentic-Autopilot',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_token_request_failed', __( 'Failed to validate token with GitHub.', 'agentic-autopilot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'bp_token_invalid', __( 'Token is invalid or does not have sufficient permissions.', 'agentic-autopilot' ) );
		}

		// Token is valid. Encrypt and save it.
		if ( ! self::encrypt_and_save_token( $token ) ) {
			return new WP_Error( 'bp_token_save_failed', __( 'Failed to save token.', 'agentic-autopilot' ) );
		}

		// Clear cached user and repos.
		delete_transient( 'agentic_autopilot_bp_user_' . get_current_user_id() );
		delete_transient( 'agentic_autopilot_bp_repos_' . get_current_user_id() );

		return true;
	}

	/**
	 * Forgets the saved token and clears caches.
	 */
	public static function forget_token() {
		delete_option( self::TOKEN_OPTION );
		delete_transient( 'agentic_autopilot_bp_user_' . get_current_user_id() );
		delete_transient( 'agentic_autopilot_bp_repos_' . get_current_user_id() );
		delete_transient( 'agentic_autopilot_bp_device_code_' . get_current_user_id() );
	}

	/**
	 * Gets the GitHub OAuth App client ID.
	 *
	 * @return string Client ID, or empty if not configured.
	 */
	public static function client_id() {
		if ( defined( 'AGENTIC_AUTOPILOT_GITHUB_CLIENT_ID' ) ) {
			return (string) AGENTIC_AUTOPILOT_GITHUB_CLIENT_ID;
		}
		/**
		 * Filters the GitHub OAuth App client ID for "Sign in with GitHub".
		 *
		 * @param string $client_id Empty by default.
		 */
		return (string) apply_filters( 'agentic_autopilot_github_client_id', '' );
	}

	/**
	 * Fetches the authenticated GitHub user info.
	 *
	 * Cached for 1 hour.
	 *
	 * @return array<string, mixed>|WP_Error Array with 'login' and 'avatar_url', or WP_Error.
	 */
	public static function user() {
		$transient_key = 'agentic_autopilot_bp_user_' . get_current_user_id();
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = self::get_effective_token();
		if ( ! $token ) {
			return new WP_Error( 'bp_no_token', __( 'No GitHub token available.', 'agentic-autopilot' ) );
		}

		$response = wp_safe_remote_get(
			'https://api.github.com/user',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'    => 'Agentic-Autopilot',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_api_error', __( 'Failed to fetch GitHub user info.', 'agentic-autopilot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'bp_api_error', __( 'Failed to fetch GitHub user info.', 'agentic-autopilot' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['login'] ) ) {
			return new WP_Error( 'bp_invalid_response', __( 'Invalid response from GitHub.', 'agentic-autopilot' ) );
		}

		$user_info = array(
			'login'      => sanitize_text_field( $body['login'] ),
			'avatar_url' => sanitize_url( $body['avatar_url'] ?? '' ),
		);

		set_transient( $transient_key, $user_info, HOUR_IN_SECONDS );
		return $user_info;
	}

	/**
	 * Fetches the list of repos the authenticated user has access to.
	 *
	 * Cached for 5 minutes. Returns empty array if no token.
	 *
	 * @return array<int, array{full_name: string, private: bool}>|WP_Error
	 */
	public static function repos() {
		$transient_key = 'agentic_autopilot_bp_repos_' . get_current_user_id();
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = self::get_effective_token();
		if ( ! $token ) {
			return array();
		}

		$response = wp_safe_remote_get(
			'https://api.github.com/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator,organization_member',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'    => 'Agentic-Autopilot',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_api_error', __( 'Failed to fetch repositories.', 'agentic-autopilot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'bp_api_error', __( 'Failed to fetch repositories.', 'agentic-autopilot' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$repos = array();
		foreach ( $body as $repo ) {
			if ( isset( $repo['full_name'], $repo['private'] ) ) {
				$repos[] = array(
					'full_name' => sanitize_text_field( $repo['full_name'] ),
					'private'   => (bool) $repo['private'],
				);
			}
		}

		set_transient( $transient_key, $repos, 5 * MINUTE_IN_SECONDS );
		return $repos;
	}

	/**
	 * Fetches the raw file contents from a GitHub repo.
	 *
	 * @param string $repo Repository "owner/name".
	 * @param string $path File path within the repo.
	 * @param string $ref  Optional Git ref (branch/tag/commit). Defaults to default branch.
	 * @return string|WP_Error Raw file contents, or WP_Error.
	 */
	public static function get_contents( $repo, $path, $ref = '' ) {
		if ( ! self::valid_repo( $repo ) ) {
			return new WP_Error( 'bp_invalid_repo', __( 'Invalid repository name.', 'agentic-autopilot' ) );
		}
		if ( ! self::valid_path( $path ) ) {
			return new WP_Error( 'bp_invalid_path', __( 'Invalid file path.', 'agentic-autopilot' ) );
		}

		$url = 'https://api.github.com/repos/' . $repo . '/contents/' . rawurlencode( $path );
		if ( $ref ) {
			$url .= '?ref=' . rawurlencode( $ref );
		}

		$token = self::get_effective_token();
		$headers = array(
			'Accept'        => 'application/vnd.github.raw',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'    => 'Agentic-Autopilot',
		);
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_api_error', __( 'Failed to fetch file contents.', 'agentic-autopilot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'bp_github_error',
				sprintf( 'GitHub returned %d for %s', (int) $code, sanitize_text_field( $path ) )
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Downloads a file from GitHub to a temporary location.
	 *
	 * @param string $repo Repository "owner/name".
	 * @param string $path File path within the repo.
	 * @param string $ref  Optional Git ref (branch/tag/commit). Defaults to default branch.
	 * @return string|WP_Error Temporary file path, or WP_Error on failure.
	 */
	public static function download( $repo, $path, $ref = '' ) {
		if ( ! self::valid_repo( $repo ) ) {
			return new WP_Error( 'bp_invalid_repo', __( 'Invalid repository name.', 'agentic-autopilot' ) );
		}
		if ( ! self::valid_path( $path ) ) {
			return new WP_Error( 'bp_invalid_path', __( 'Invalid file path.', 'agentic-autopilot' ) );
		}

		$url = 'https://api.github.com/repos/' . $repo . '/contents/' . rawurlencode( $path );
		if ( $ref ) {
			$url .= '?ref=' . rawurlencode( $ref );
		}

		$tmp_file = wp_tempnam( basename( $path ) );
		if ( ! $tmp_file ) {
			return new WP_Error( 'bp_tempfile_failed', __( 'Failed to create temporary file.', 'agentic-autopilot' ) );
		}

		$token = self::get_effective_token();
		$headers = array(
			'Accept'        => 'application/vnd.github.raw',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'    => 'Agentic-Autopilot',
		);
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp_file,
				'headers'  => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file );
			}
			return new WP_Error( 'bp_download_failed', __( 'Failed to download file from GitHub.', 'agentic-autopilot' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file );
			}
			return new WP_Error(
				'bp_github_error',
				sprintf( 'GitHub returned %d for %s', (int) $code, sanitize_text_field( $path ) )
			);
		}

		return $tmp_file;
	}

	/**
	 * Validates a repository name format.
	 *
	 * @param string $repo Repository "owner/name".
	 * @return bool True if valid, false otherwise.
	 */
	public static function valid_repo( $repo ) {
		return (bool) preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo );
	}

	/**
	 * Renders the GitHub connection UI.
	 */
	public static function render_connection_box() {
		$source = self::token_source();
		$user   = ( 'constant' === $source ) ? self::user() : null;

		echo '<div class="agentic-autopilot-bp-connection">';

		// Show current connection state.
		if ( 'constant' === $source ) {
			echo '<p><strong>' . esc_html__( 'Connected', 'agentic-autopilot' ) . ':</strong> ' . esc_html__( 'Token from wp-config.php', 'agentic-autopilot' );
			if ( is_array( $user ) && isset( $user['login'] ) ) {
				echo ' <em>(@' . esc_html( $user['login'] ) . ')</em>';
			}
			echo '</p>';
		} elseif ( 'saved' === $source ) {
			$user_info = self::user();
			if ( is_array( $user_info ) && isset( $user_info['login'] ) ) {
				echo '<p><strong>' . esc_html__( 'Connected as', 'agentic-autopilot' ) . ':</strong> @' . esc_html( $user_info['login'] ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( 'Connected', 'agentic-autopilot' ) . '</strong></p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Not connected. Public repositories work without signing in.', 'agentic-autopilot' ) . '</p>';
		}

		// If not connected via constant, show connection options.
		if ( 'constant' !== $source ) {
			echo '<div class="agentic-autopilot-bp-options">';

			// OAuth device flow button.
			if ( self::client_id() ) {
				echo '<div class="agentic-autopilot-bp-device-flow">';
				wp_nonce_field( 'agentic_autopilot_bp', 'agentic_autopilot_bp_nonce' );
				echo '<button type="button" class="button button-primary" id="agentic-autopilot-bp-device-button">';
				esc_html_e( 'Sign in with GitHub', 'agentic-autopilot' );
				echo '</button>';
				echo '<div id="agentic-autopilot-bp-device-status" style="display:none; margin-top:1em;"></div>';
				echo '</div>';
			}

			// Personal access token form.
			echo '<div class="agentic-autopilot-bp-token-form">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'agentic_autopilot_bp' );
			echo '<input type="hidden" name="action" value="agentic_autopilot_bp_token">';
			echo '<label for="agentic-autopilot-bp-token">' . esc_html__( 'Personal access token', 'agentic-autopilot' ) . '</label><br>';
			echo '<input type="password" id="agentic-autopilot-bp-token" name="token" autocomplete="off" style="width:300px; max-width:100%;">';
			echo '<p class="description">' . esc_html__( 'Fine-grained token with read-only Contents access to the repo.', 'agentic-autopilot' ) . '</p>';
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Save Token', 'agentic-autopilot' ) . '</button>';
			echo '</form>';
			echo '</div>';

			echo '</div>';
		}

		// Disconnect button (for saved and constant tokens).
		if ( 'none' !== $source ) {
			echo '<div class="agentic-autopilot-bp-disconnect" style="margin-top:1em;">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'agentic_autopilot_bp' );
			echo '<input type="hidden" name="action" value="agentic_autopilot_bp_disconnect">';
			echo '<button type="submit" class="button button-tertiary" onclick="return confirm(\'' . esc_attr__( 'Disconnect this GitHub account?', 'agentic-autopilot' ) . '\');">';
			esc_html_e( 'Disconnect', 'agentic-autopilot' );
			echo '</button>';
			echo '</form>';
			echo '</div>';
		}

		echo '</div>';

		// Inline JavaScript for OAuth device flow.
		if ( 'constant' !== $source && self::client_id() ) {
			self::render_device_flow_js();
		}
	}

	/**
	 * Renders inline JavaScript for OAuth device flow.
	 */
	private static function render_device_flow_js() {
		$nonce = wp_create_nonce( 'agentic_autopilot_bp' );
		?>
		<script type="text/javascript">
		(function() {
			const button = document.getElementById('agentic-autopilot-bp-device-button');
			const status = document.getElementById('agentic-autopilot-bp-device-status');
			if (!button) return;

			button.addEventListener('click', async function() {
				button.disabled = true;
				status.style.display = 'block';
				status.innerHTML = '<p><?php esc_html_e('Starting authentication...', 'agentic-autopilot'); ?></p>';

				try {
					const formData = new FormData();
					formData.append('action', 'agentic_autopilot_bp_device_start');
					formData.append('_ajax_nonce', '<?php echo esc_attr($nonce); ?>');

					const startResponse = await fetch(ajaxurl, {
						method: 'POST',
						body: formData
					});
					const startData = await startResponse.json();

					if (!startData.success) {
						status.textContent = (startData.data && startData.data.message) || '<?php echo esc_js( __( 'Failed to start authentication', 'agentic-autopilot' ) ); ?>';
						button.disabled = false;
						return;
					}

					const { user_code, verification_uri, interval } = startData.data;
					const pollInterval = (interval || 5) * 1000;

					// Build with textContent so nothing from the network is parsed as HTML.
					status.textContent = '';
					const box = document.createElement('div');
					box.style.cssText = 'padding:1em; border:1px solid #ddd; border-radius:4px;';
					const p1 = document.createElement('p');
					p1.textContent = '<?php echo esc_js( __( 'Enter this code on GitHub:', 'agentic-autopilot' ) ); ?>';
					const p2 = document.createElement('p');
					p2.style.cssText = 'font-size:24px; font-weight:bold; font-family:monospace; letter-spacing:2px;';
					p2.textContent = user_code;
					const p3 = document.createElement('p');
					const a = document.createElement('a');
					a.href = verification_uri;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = '<?php echo esc_js( __( 'Authorize on GitHub', 'agentic-autopilot' ) ); ?>';
					p3.appendChild(a);
					const p4 = document.createElement('p');
					p4.textContent = '<?php echo esc_js( __( 'Waiting for confirmation...', 'agentic-autopilot' ) ); ?>';
					box.append(p1, p2, p3, p4);
					status.appendChild(box);

					let currentInterval = pollInterval;
					const pollTimer = setInterval(async () => {
						try {
							const pollForm = new FormData();
							pollForm.append('action', 'agentic_autopilot_bp_device_poll');
							pollForm.append('_ajax_nonce', '<?php echo esc_attr($nonce); ?>');

							const pollResponse = await fetch(ajaxurl, {
								method: 'POST',
								body: pollForm
							});
							const pollData = await pollResponse.json();

							if (pollData.data?.interval) {
								currentInterval = pollData.data.interval * 1000;
							}

							if (pollData.success) {
								clearInterval(pollTimer);
								status.innerHTML = '<p style="color:green;"><?php esc_html_e('✓ Successfully connected! Reloading...', 'agentic-autopilot'); ?></p>';
								setTimeout(() => location.reload(), 1500);
								return;
							}

							if (pollData.data?.error === 'access_denied') {
								clearInterval(pollTimer);
								status.innerHTML = '<p class="error"><?php esc_html_e('Authorization denied.', 'agentic-autopilot'); ?></p>';
								button.disabled = false;
								return;
							}

							if (pollData.data?.error === 'expired_token') {
								clearInterval(pollTimer);
								status.innerHTML = '<p class="error"><?php esc_html_e('Code expired. Please try again.', 'agentic-autopilot'); ?></p>';
								button.disabled = false;
								return;
							}
						} catch (err) {
							console.error('Poll error:', err);
						}
					}, currentInterval);
				} catch (err) {
					console.error('Device flow error:', err);
					status.innerHTML = '<p class="error"><?php esc_html_e('An error occurred.', 'agentic-autopilot'); ?></p>';
					button.disabled = false;
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handles the OAuth device flow start request (AJAX).
	 */
	public static function handle_device_start() {
		check_ajax_referer( 'agentic_autopilot_bp' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'agentic-autopilot' ) ) );
		}

		$client_id = self::client_id();
		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'GitHub Client ID not configured.', 'agentic-autopilot' ) ) );
		}

		$response = wp_safe_remote_post(
			'https://github.com/login/device/code',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => array(
					'client_id' => $client_id,
					'scope'     => 'repo read:user',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to initiate device flow.', 'agentic-autopilot' ) ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['device_code'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from GitHub.', 'agentic-autopilot' ) ) );
		}

		// Store device_code in transient (10 minutes, keyed by user ID).
		$transient_key = 'agentic_autopilot_bp_device_code_' . get_current_user_id();
		set_transient( $transient_key, $body['device_code'], 10 * MINUTE_IN_SECONDS );

		// The device_code stays server-side (transient above); only the short user code goes to the browser.
		$verify = isset( $body['verification_uri'] ) ? (string) $body['verification_uri'] : '';
		if ( 0 !== strpos( $verify, 'https://github.com/' ) ) {
			$verify = 'https://github.com/login/device';
		}
		wp_send_json_success( array(
			'user_code'        => isset( $body['user_code'] ) ? sanitize_text_field( $body['user_code'] ) : '',
			'verification_uri' => $verify,
			'interval'         => isset( $body['interval'] ) ? (int) $body['interval'] : 5,
		) );
	}

	/**
	 * Handles the OAuth device flow polling (AJAX).
	 */
	public static function handle_device_poll() {
		check_ajax_referer( 'agentic_autopilot_bp' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'agentic-autopilot' ) ) );
		}

		$device_code = (string) get_transient( 'agentic_autopilot_bp_device_code_' . get_current_user_id() );
		if ( '' === $device_code ) {
			wp_send_json_error( array( 'message' => __( 'Sign-in expired. Please start again.', 'agentic-autopilot' ) ) );
		}

		$client_id = self::client_id();
		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'GitHub Client ID not configured.', 'agentic-autopilot' ) ) );
		}

		$response = wp_safe_remote_post(
			'https://github.com/login/oauth/access_token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => array(
					'client_id'   => $client_id,
					'device_code' => $device_code,
					'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to poll GitHub.', 'agentic-autopilot' ) ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from GitHub.', 'agentic-autopilot' ) ) );
		}

		// Handle error responses.
		if ( ! empty( $body['error'] ) ) {
			$error = $body['error'];
			if ( 'authorization_pending' === $error || 'slow_down' === $error ) {
				$interval = isset( $body['interval'] ) ? (int) $body['interval'] : 5;
				wp_send_json_error( array(
					'error'    => $error,
					'interval' => $interval,
				) );
			} elseif ( 'access_denied' === $error || 'expired_token' === $error ) {
				wp_send_json_error( array( 'error' => $error ) );
			}
		}

		// Success: access_token received.
		if ( ! empty( $body['access_token'] ) ) {
			$token = $body['access_token'];
			delete_transient( 'agentic_autopilot_bp_device_code_' . get_current_user_id() );
			$result = self::save_token( $token );
			if ( true === $result ) {
				wp_send_json_success();
			} else {
				$msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to save token.', 'agentic-autopilot' );
				wp_send_json_error( array( 'message' => $msg ) );
			}
		}

		wp_send_json_error( array( 'message' => __( 'No access token in response.', 'agentic-autopilot' ) ) );
	}

	/**
	 * Handles personal access token save (admin-post).
	 */
	public static function handle_token_save() {
		check_admin_referer( 'agentic_autopilot_bp' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=agentic-autopilot-blueprint' ) );
			exit;
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( empty( $token ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'aa_bp'     => 'error',
						'aa_bp_msg' => urlencode( __( 'Token cannot be empty.', 'agentic-autopilot' ) ),
					),
					wp_get_referer() ?: admin_url( 'options-general.php?page=agentic-autopilot-blueprint' )
				)
			);
			exit;
		}

		$result = self::save_token( $token );
		$status = true === $result ? 'saved' : 'error';
		$msg    = true === $result ?
			__( 'Token saved successfully.', 'agentic-autopilot' ) :
			( is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to save token.', 'agentic-autopilot' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'aa_bp'     => $status,
					'aa_bp_msg' => urlencode( $msg ),
				),
				wp_get_referer() ?: admin_url( 'options-general.php?page=agentic-autopilot-blueprint' )
			)
		);
		exit;
	}

	/**
	 * Handles disconnect (admin-post).
	 */
	public static function handle_disconnect() {
		check_admin_referer( 'agentic_autopilot_bp' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=agentic-autopilot-blueprint' ) );
			exit;
		}

		self::forget_token();
		wp_safe_redirect(
			add_query_arg(
				array(
					'aa_bp'     => 'disconnected',
					'aa_bp_msg' => urlencode( __( 'Disconnected from GitHub.', 'agentic-autopilot' ) ),
				),
				wp_get_referer() ?: admin_url( 'options-general.php?page=agentic-autopilot-blueprint' )
			)
		);
		exit;
	}

	/**
	 * Gets the effective token from constant or saved storage.
	 *
	 * @return string|null Token, or null if unavailable.
	 */
	private static function get_effective_token() {
		if ( defined( 'AGENTIC_AUTOPILOT_BLUEPRINT_TOKEN' ) && ! empty( AGENTIC_AUTOPILOT_BLUEPRINT_TOKEN ) ) {
			return AGENTIC_AUTOPILOT_BLUEPRINT_TOKEN;
		}
		return self::get_saved_token();
	}

	/**
	 * Retrieves and decrypts the saved token.
	 *
	 * @return string|null Decrypted token, or null if not saved or decryption fails.
	 */
	private static function get_saved_token() {
		$encrypted = get_option( self::TOKEN_OPTION );
		if ( ! is_string( $encrypted ) || empty( $encrypted ) ) {
			return null;
		}

		try {
			return self::decrypt_token( $encrypted );
		} catch ( \Throwable $e ) {
			// Decryption failed; treat as no token.
			return null;
		}
	}

	/**
	 * Encrypts and saves a token.
	 *
	 * @param string $token Token to encrypt and save.
	 * @return bool True on success, false on failure.
	 */
	private static function encrypt_and_save_token( $token ) {
		try {
			$encrypted = self::encrypt_token( $token );
			update_option( self::TOKEN_OPTION, $encrypted, false );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Encrypts a token using sodium_crypto_secretbox.
	 *
	 * @param string $token Token to encrypt.
	 * @return string base64-encoded (nonce . cipher).
	 * @throws \Exception On encryption failure.
	 */
	private static function encrypt_token( $token ) {
		$key   = self::get_encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $token, $nonce, $key );
		return base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypts a token using sodium_crypto_secretbox.
	 *
	 * @param string $encrypted base64-encoded (nonce . cipher).
	 * @return string Decrypted token.
	 * @throws \Exception On decryption failure.
	 */
	private static function decrypt_token( $encrypted ) {
		$decoded = base64_decode( $encrypted, true );
		if ( ! $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new \Exception( 'Invalid encrypted token format' );
		}

		$key   = self::get_encryption_key();
		$nonce = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$token = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		if ( false === $token ) {
			throw new \Exception( 'Decryption failed' );
		}
		return $token;
	}

	/**
	 * Derives the encryption key from WordPress salts.
	 *
	 * @return string Encryption key suitable for sodium_crypto_secretbox.
	 */
	private static function get_encryption_key() {
		$raw = sodium_crypto_generichash( wp_salt( 'auth' ) . '|agentic-autopilot-blueprint', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		return $raw;
	}

	/**
	 * Validates a file path for GitHub API calls.
	 *
	 * @param string $path File path to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function valid_path( $path ) {
		// No leading slash, no '..', only safe characters.
		if ( strpos( $path, '..' ) !== false || strpos( $path, '//' ) !== false ) {
			return false;
		}
		if ( 0 === strpos( $path, '/' ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_.\/ -]+$/', $path );
	}
}
