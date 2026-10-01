<?php
/**
 * AI agents: llms.txt endpoint for AI search and LLM access.
 *
 * @package AgenticAutopilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates and serves a machine-readable site index at /llms.txt.
 */
final class Agentic_Autopilot_Llms_Txt {

	const CACHE_KEY = 'agentic_autopilot_llms_txt';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'invalidate_cache' ), 999 );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_cache' ), 999 );
		add_action( 'update_option_' . Agentic_Autopilot_Settings::OPTION, array( __CLASS__, 'invalidate_cache' ), 10 );
		if ( Agentic_Autopilot_Settings::get()['llms_txt'] ) {
			add_action( 'parse_request', array( __CLASS__, 'handle_request' ), 0 );
		}
	}

	/**
	 * Intercept requests for /llms.txt and serve the file.
	 */
	public static function handle_request() {
		// Get the request path relative to home_url.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = $home_path ? trim( $home_path, '/' ) : '';
		$uri       = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$req_path  = trim( (string) wp_parse_url( 'http://x' . $uri, PHP_URL_PATH ), '/' );

		// Remove home_path prefix if present.
		if ( $home_path && strpos( $req_path, $home_path ) === 0 ) {
			$req_path = substr( $req_path, strlen( $home_path ) );
		}
		$req_path = trim( $req_path, '/' );

		// Check if this is a request for llms.txt.
		if ( 'llms.txt' !== $req_path ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo self::generate(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Generate the llms.txt content.
	 *
	 * @return string
	 */
	private static function generate() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = Agentic_Autopilot_Settings::get();
		$lines    = array();

		// Title (site name).
		$title = get_bloginfo( 'name' );
		$lines[] = '# ' . $title;
		$lines[] = '';

		// Tagline (description).
		$tagline = get_bloginfo( 'description' );
		if ( $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		// Intro (from settings).
		if ( ! empty( $settings['llms_txt_intro'] ) ) {
			$lines[] = $settings['llms_txt_intro'];
			$lines[] = '';
		}

		// Pages section.
		$pages = self::get_pages();
		if ( ! empty( $pages ) ) {
			$lines[] = '## Pages';
			foreach ( $pages as $page ) {
				$lines[] = self::format_item( $page['title'], $page['permalink'], $page['description'] );
			}
			$lines[] = '';
		}

		// Posts section.
		$posts = self::get_posts();
		if ( ! empty( $posts ) ) {
			$lines[] = '## Posts';
			foreach ( $posts as $post ) {
				$lines[] = self::format_item( $post['title'], $post['permalink'], $post['description'] );
			}
		}

		$text = trim( implode( "\n", $lines ) ) . "\n";

		/**
		 * Filter the llms.txt content.
		 *
		 * @param string $text The llms.txt content.
		 */
		$text = apply_filters( 'agentic_autopilot_llms_txt', $text );

		set_transient( self::CACHE_KEY, $text, self::CACHE_TTL );
		return $text;
	}

	/**
	 * Get published pages (not password-protected), ordered by menu_order then title, max 100.
	 *
	 * @return array<int, array{title: string, permalink: string, description: string}>
	 */
	private static function get_pages() {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'has_password'   => false,
			'fields'         => 'ids',
		);

		$page_ids = get_posts( $args );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$items = array();
		foreach ( $page_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			// Skip if marked with noindex meta.
			if ( get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) === '1' ) {
				continue;
			}

			$permalink = self::permalink( $id );
			if ( ! $permalink ) {
				continue;
			}

			$items[] = array(
				'title'       => self::clean_text( $post->post_title ),
				'permalink'   => $permalink,
				'description' => self::get_description( $id ),
			);
		}

		return $items;
	}

	/**
	 * Get the latest 30 published posts (not password-protected, not noindex).
	 *
	 * @return array<int, array{title: string, permalink: string, description: string}>
	 */
	private static function get_posts() {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'has_password'   => false,
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $args );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$items = array();
		foreach ( $post_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			// Skip if marked with noindex meta.
			if ( get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) === '1' ) {
				continue;
			}

			$permalink = self::permalink( $id );
			if ( ! $permalink ) {
				continue;
			}

			$items[] = array(
				'title'       => self::clean_text( $post->post_title ),
				'permalink'   => $permalink,
				'description' => self::get_description( $id ),
			);
		}

		return $items;
	}

	/**
	 * Absolute URL of a post in its own language (WPML-aware), or '' if it has none.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function permalink( $post_id ) {
		$lang = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $lang ) && ! empty( $lang['language_code'] ) ) {
			// Build the URL in the post's own language (slug and /xx/ prefix).
			$current = apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $lang['language_code'] );
			$url = (string) get_permalink( $post_id );
			do_action( 'wpml_switch_language', $current );
		} else {
			$url = (string) get_permalink( $post_id );
		}
		return 0 === strpos( $url, 'http' ) ? $url : '';
	}

	/**
	 * Get description for a post: Yoast meta desc, else post excerpt, else empty.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_description( $post_id ) {
		$yoast_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( is_string( $yoast_desc ) && '' !== trim( $yoast_desc ) ) {
			return self::clean_text( $yoast_desc );
		}

		$post = get_post( $post_id );
		if ( $post && is_string( $post->post_excerpt ) && '' !== trim( $post->post_excerpt ) ) {
			return self::clean_text( $post->post_excerpt );
		}

		return '';
	}

	/**
	 * Format a single item as "- [title](url): description" or "- [title](url)".
	 *
	 * @param string $title       Page/post title.
	 * @param string $permalink   Permalink.
	 * @param string $description Description.
	 * @return string
	 */
	private static function format_item( $title, $permalink, $description ) {
		$title = self::escape_markdown( $title );
		$line  = '- [' . $title . '](' . esc_url( $permalink ) . ')';
		if ( '' !== $description ) {
			$line .= ': ' . $description;
		}
		return $line;
	}

	/**
	 * Clean title/description: strip tags, collapse whitespace.
	 *
	 * @param string $text Text to clean.
	 * @return string
	 */
	private static function clean_text( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Escape special Markdown characters: [ and ].
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	private static function escape_markdown( $text ) {
		return str_replace( array( '[', ']' ), array( '\[', '\]' ), $text );
	}

	/**
	 * Invalidate the cache.
	 */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
