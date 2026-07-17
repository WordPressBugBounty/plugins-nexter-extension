<?php
/**
 * Content SEO – Archive SEO (author/date disable, redirect to home).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Archives
 */
class Nexter_Content_SEO_Archives {

	/**
	 * Redirect or 404 disabled author/date archives.
	 *
	 * Hooked on `pre_handle_404` (a filter), NOT template_redirect: an EMPTY archive makes
	 * WordPress call WP_Query::set_404(), which runs init_query_flags() and resets every is_*
	 * conditional. By template_redirect time is_date()/is_author() are already false, so the
	 * redirect was silently skipped for empty date archives (author archives with posts still
	 * worked — the inconsistency reported). pre_handle_404 runs before that reset, so the
	 * conditionals are reliable here and both archive types behave identically.
	 *
	 * @param bool          $preempt  Current short-circuit value (false = let core handle 404).
	 * @param \WP_Query|null $wp_query Main query.
	 * @return bool True when we have handled the response (redirect/404); otherwise $preempt.
	 */
	public static function maybe_redirect_archives( $preempt = false, $wp_query = null ) {
		if ( is_admin() || is_robots() || is_feed() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return $preempt;
		}

		$options        = Nexter_Content_SEO::get_options();
		$disable_author = ! empty( $options['disable_author_archives'] ) && is_author();
		$disable_date   = ! empty( $options['disable_date_archives'] ) && is_date();
		// Category and tag archives can be disabled explicitly (not just noindex'd), matching the
		// author/date behavior: 301 to home when redirect_archives_to_home is on, else a clean 404.
		$disable_cat    = ! empty( $options['disable_category_archives'] ) && is_category();
		$disable_tag    = ! empty( $options['disable_tag_archives'] ) && is_tag();

		if ( ! $disable_author && ! $disable_date && ! $disable_cat && ! $disable_tag ) {
			return $preempt;
		}

		// Redirect path is checked first and consistently for BOTH archive types.
		if ( ! empty( $options['redirect_archives_to_home'] ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}

		// No redirect configured → force a clean 404 for the disabled archive.
		if ( ! $wp_query instanceof WP_Query ) {
			$wp_query = $GLOBALS['wp_query'] ?? null;
		}
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		return true;
	}

	/**
	 * Prevent a hard 500 on the site-wide feed when no posts page is assigned
	 * and/or the query returns zero posts (common on brochure sites that use a
	 * static front page with no blog). WordPress core calls `wp_die( 'No feed
	 * available…', 500 )` in that case; we render a minimal but valid empty
	 * RSS feed instead so aggregators don't hard-fail.
	 *
	 * Runs at `template_redirect` priority 0 — before WP's own feed handler.
	 */
	public static function maybe_serve_empty_feed() {
		if ( is_admin() ) {
			return;
		}

		// Match any feed request by URL or query_var — runs early enough that
		// relying solely on is_feed() can miss edge cases where the main query
		// hasn't been fully parsed.
		$is_feed_request = is_feed();
		if ( ! $is_feed_request ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			if ( false !== strpos( $path, '/feed' ) && ( '/feed/' === $path || preg_match( '#/feed/?$#', $path ) ) ) {
				$is_feed_request = true;
			}
		}
		if ( ! $is_feed_request ) {
			return;
		}

		// Only intervene on site-wide feed — skip per-term / per-author / per-post-type feeds.
		$query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
		if ( $query instanceof WP_Query ) {
			if ( $query->is_category() || $query->is_tag() || $query->is_tax() || $query->is_author() || $query->is_post_type_archive() || $query->is_singular() ) {
				return;
			}
			// Posts exist — let WP handle normally.
			if ( ! empty( $query->posts ) ) {
				return;
			}
		}

		$charset  = get_option( 'blog_charset' ) ? get_option( 'blog_charset' ) : 'UTF-8';
		$site_url = home_url( '/' );
		$name     = html_entity_decode( get_bloginfo_rss( 'name' ), ENT_QUOTES, $charset );
		$desc     = html_entity_decode( get_bloginfo_rss( 'description' ), ENT_QUOTES, $charset );

		if ( ! headers_sent() ) {
			status_header( 200 );
			header_remove( 'Content-Type' );
			header( 'Content-Type: application/rss+xml; charset=' . $charset );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- feed XML, each piece individually escaped below.
		echo '<?xml version="1.0" encoding="' . esc_attr( $charset ) . '"?>' . "\n";
		echo '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
		echo "<channel>\n";
		echo '  <title>' . esc_html( $name ) . "</title>\n";
		echo '  <atom:link href="' . esc_url( home_url( '/feed/' ) ) . '" rel="self" type="application/rss+xml" />' . "\n";
		echo '  <link>' . esc_url( $site_url ) . "</link>\n";
		echo '  <description>' . esc_html( $desc ) . "</description>\n";
		echo '  <language>' . esc_html( get_bloginfo_rss( 'language' ) ) . "</language>\n";
		echo '  <lastBuildDate>' . esc_html( mysql2date( 'D, d M Y H:i:s +0000', gmdate( 'Y-m-d H:i:s' ), false ) ) . "</lastBuildDate>\n";
		echo "</channel>\n</rss>\n";
		// phpcs:enable
		exit;
	}
}
