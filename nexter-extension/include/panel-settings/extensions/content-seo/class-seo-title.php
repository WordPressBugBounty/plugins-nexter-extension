<?php
/**
 * Content SEO – frontend document title (<title>) output.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Title
 */
class Nexter_Content_SEO_Title {

	/** Matches Nexter_Content_SeoRank::META_TITLE. */
	const META_TITLE = '_nxt_seo_title';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Primary: short-circuit wp_get_document_title() entirely when Nexter resolves a title.
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_pre_document_title' ), PHP_INT_MAX );
		// Fallback for themes that still rely on wp_title().
		add_filter( 'wp_title', array( __CLASS__, 'filter_document_title' ), PHP_INT_MAX, 1 );
		// Some themes/plugins build title from parts; override at the same late priority.
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title_parts' ), PHP_INT_MAX );
	}

	/**
	 * Short-circuit `wp_get_document_title()` when Nexter resolves a non-empty title.
	 * Returning a non-empty string here bypasses the parts-array assembly entirely.
	 *
	 * @param string $title Existing pre-filter title (usually empty).
	 * @return string
	 */
	public static function filter_pre_document_title( $title ) {
		if ( is_admin() || is_feed() || is_trackback() || wp_doing_ajax() ) {
			return $title;
		}
		// Defer to a competing SEO plugin so the <title> isn't fought over (matches the
		// description/social coexistence guard).
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active() ) {
			return $title;
		}
		$resolved = self::filter_document_title( '' );
		// Returning a non-empty value here short-circuits wp_get_document_title() BEFORE core's
		// own esc_html() runs, and the result is echoed raw into <title>. Escape it once here so
		// "&" → "&amp;" and "<Tips>" → "&lt;Tips&gt;" (the document_title_parts path below stays
		// raw because core escapes the assembled parts itself).
		return '' !== $resolved ? esc_html( $resolved ) : $title;
	}

	/**
	 * Filter frontend document title.
	 *
	 * @param string $title Existing title.
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		if ( is_admin() || is_feed() || is_trackback() || wp_doing_ajax() ) {
			return $title;
		}
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active() ) {
			return $title;
		}

		$options = Nexter_Content_SEO::get_options();

		// Homepage: when a static front page is set, prefer the per-post Nexter SEO
		// title saved on that page (Edit page → Nexter SEO meta box). Otherwise
		// (blog index, or static page with no SEO meta) fall back to site name +
		// tagline rather than the post title.
		if ( is_front_page() || is_home() ) {
			if ( is_front_page() ) {
				$front_id = (int) get_option( 'page_on_front' );
				if ( $front_id > 0 ) {
					$front_post = get_post( $front_id );
					if ( $front_post instanceof WP_Post ) {
						$front_meta = get_post_meta( $front_id, self::META_TITLE, true );
						$resolved   = self::resolve_string_with_context( $front_meta, array( 'post' => $front_post ) );
						if ( '' !== $resolved ) {
							return $resolved;
						}
					}
				}
			}
			// Home Page panel title. Covers the blog-index ("Your latest posts") case — where
			// there is no front-page post — and serves as a fallback for a static front page
			// with no per-post title set.
			$home_title = isset( $options['home_title'] ) ? (string) $options['home_title'] : '';
			$resolved   = self::resolve_string_with_context( $home_title, array() );
			if ( '' !== $resolved ) {
				return $resolved;
			}
			$site_name = (string) get_bloginfo( 'name' );
			$tagline   = (string) get_bloginfo( 'description' );
			$built     = '' !== $tagline ? $site_name . ' – ' . $tagline : $site_name;
			// Trim separators too: when the blogname is empty this fallback yields "– Tagline",
			// so strip the leading dangling separator (the user-template path already does this).
			$cleaned   = self::trim_template_separators( self::cleanup_title( $built ) );
			return '' !== $cleaned ? $cleaned : $title;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$meta = get_post_meta( $post->ID, self::META_TITLE, true );
				$resolved = self::resolve_string_with_context( $meta, array( 'post' => $post ) );
				if ( '' !== $resolved ) {
					return $resolved;
				}
				$tpl = isset( $options['search_title_template'] ) ? (string) $options['search_title_template'] : '%post_title% - %site_name%';
				$resolved = self::resolve_string_with_context( $tpl, array( 'post' => $post ) );
				if ( '' !== $resolved ) {
					return $resolved;
				}
			}
			return $title;
		}

		// Archives (category / tag / custom taxonomy / author / date / post-type). These use a
		// dedicated archive template — NOT the post-title template — so empty post variables can
		// no longer leave artifacts like "Name by | | 2026".
		if ( is_archive() || is_post_type_archive() ) {
			$term_ctx = array();
			if ( is_tax() || is_category() || is_tag() ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term ) {
					$term_ctx = array( 'term' => $term );
					// Per-term Nexter SEO title still wins.
					$meta     = get_term_meta( (int) $term->term_id, self::META_TITLE, true );
					$resolved = self::resolve_string_with_context( $meta, $term_ctx );
					if ( '' !== $resolved ) {
						return $resolved;
					}
				}
			}
			$tpl = ( isset( $options['archive_title_template'] ) && '' !== $options['archive_title_template'] )
				? (string) $options['archive_title_template']
				: '%term_title% - %site_name%';
			$resolved = self::resolve_string_with_context( $tpl, $term_ctx );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		return $title;
	}

	/**
	 * Filter document title parts to keep Nexter title authoritative.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public static function filter_document_title_parts( $parts ) {
		if ( is_admin() || is_feed() || is_trackback() || wp_doing_ajax() || ! is_array( $parts ) ) {
			return $parts;
		}

		$resolved = self::filter_document_title( '' );
		if ( '' === $resolved ) {
			return $parts;
		}

		$parts['title'] = $resolved;
		if ( isset( $parts['site'] ) ) {
			unset( $parts['site'] );
		}
		if ( isset( $parts['tagline'] ) ) {
			unset( $parts['tagline'] );
		}

		return $parts;
	}

	/**
	 * Normalize @var tokens, resolve with template variables, and sanitize.
	 *
	 * @param mixed $value   Raw template/meta.
	 * @param array $context Template context.
	 * @return string
	 */
	private static function resolve_string_with_context( $value, $context = array() ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$template = preg_replace( '/@([a-z0-9_]+)/i', '%$1%', $value );
		$resolved = Nexter_Content_SEO_Settings::replace_variables( $template, is_array( $context ) ? $context : array() );
		$resolved = self::cleanup_title( $resolved );
		return self::trim_template_separators( $resolved );
	}

	/**
	 * Basic title cleanup.
	 *
	 * @param string $title Title string.
	 * @return string
	 */
	private static function cleanup_title( $title ) {
		if ( ! is_string( $title ) ) {
			return '';
		}
		// Decode HTML entities to work with literal characters, but do NOT strip "<...>"
		// segments — a title like "QA <Tips>" is plain text, not markup, and wp_strip_all_tags
		// would silently delete the "<Tips>" part. The value is escaped once at the point it is
		// emitted into the <title> element (see filter_pre_document_title), or by core when it
		// flows through the document_title_parts filter.
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
		$title = preg_replace( '/\s+/u', ' ', $title );
		$title = is_string( $title ) ? trim( $title ) : '';

		// Hard cap so a template that pulls in body text (e.g. %post_content%) can't emit a
		// runaway <title> (a 1,999-char title was observed). Truncate on a word boundary. The
		// limit is generous by default (normal titles are untouched) and filterable.
		$max = (int) apply_filters( 'nexter_content_seo_max_title_length', 120 );
		if ( $max > 0 ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
			if ( $len > $max ) {
				$cut  = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, $max ) : substr( $title, 0, $max );
				$sp   = strrpos( $cut, ' ' );
				$title = ( false !== $sp && $sp > 0 ) ? rtrim( substr( $cut, 0, $sp ) ) : rtrim( $cut );
			}
		}
		return $title;
	}

	/**
	 * Drop separators left dangling when a template variable resolves to an empty string,
	 * e.g. "%post_title% - %site_name%" with no site name → "Title -" → "Title".
	 *
	 * @param string $title Resolved title (variables already substituted).
	 * @return string
	 */
	public static function trim_template_separators( $title ) {
		if ( ! is_string( $title ) || '' === $title ) {
			return '';
		}
		// Common SEO title separators: - – — | • · »  (colon/tilde excluded — too common in prose).
		$sep     = '\-\x{2013}\x{2014}\|\x{2022}\x{00B7}\x{00BB}';
		$pattern = '/([' . $sep . '])\s*[' . $sep . '](\s|$)/u';
		// Collapse separators made adjacent by empty variables, repeating until stable so any
		// number in a row (not just two) is reduced to one. Bounded to avoid a pathological loop.
		$guard = 0;
		do {
			$before = $title;
			$title  = preg_replace( $pattern, '$1$2', $title );
			++$guard;
		} while ( $before !== $title && $guard < 6 );
		// Drop a leading/trailing separator left by an empty first/last variable.
		$title = preg_replace( '/^\s*[' . $sep . ']\s*/u', '', $title );
		$title = preg_replace( '/\s*[' . $sep . ']\s*$/u', '', $title );
		$title = preg_replace( '/\s+/u', ' ', (string) $title );
		return trim( (string) $title );
	}
}

