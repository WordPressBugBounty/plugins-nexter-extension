<?php
/**
 * Content SEO – Canonical URL (`<link rel="canonical">`).
 *
 * Mirrors core behavior across contexts; singular posts use `_nxt_seo_canonical` when set.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Canonical
 */
class Nexter_Content_SEO_Canonical {

	/** Matches Nexter_Content_SeoRank::META_CANONICAL. */
	const META_CANONICAL = '_nxt_seo_canonical';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// When a competing SEO plugin is active it owns canonical output; don't unhook core's
		// rel_canonical or add our own, so the page isn't left with two (or conflicting) canonicals.
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active() ) {
			return;
		}
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'index_rel_link' );
		add_action( 'wp_head', array( __CLASS__, 'output_canonical_link' ), 3 );
	}

	/**
	 * Print `<link rel="canonical">` when a URL can be resolved.
	 *
	 * @return void
	 */
	public static function output_canonical_link() {
		if ( is_embed() || is_feed() || is_trackback() ) {
			return;
		}
		$url = self::resolve_canonical_url();
		if ( '' === $url || false === $url ) {
			return;
		}
		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * Resolve canonical URL
	 *
	 * @return string|false Empty string when none (e.g. 404).
	 */
	public static function resolve_canonical_url() {
		if ( is_404() ) {
			return '';
		}

		if ( is_singular() ) {
			return self::resolve_singular_canonical();
		}

		// A manual per-term canonical override must win on every page of the archive, so check it
		// BEFORE the paginated self-reference below (otherwise /category/x/page/2/ would drop the
		// admin's chosen canonical and self-canonical to page 2).
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$custom = get_term_meta( $term->term_id, self::META_CANONICAL, true );
				if ( self::is_valid_absolute_url( $custom ) ) {
					return esc_url_raw( trim( $custom ) );
				}
			}
		}

		// Paginated archives, blog index, etc.
		if ( is_paged() ) {
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 ) {
				$link = get_pagenum_link( $paged, false );
				if ( $link ) {
					return $link;
				}
			}
		}

		if ( is_home() || is_front_page() ) {
			// Respect permalink trailing-slash preference; matches Yoast/RankMath output.
			return user_trailingslashit( home_url( '/' ) );
		}

		if ( is_search() ) {
			return get_search_link();
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$custom = get_term_meta( $term->term_id, self::META_CANONICAL, true );
				if ( self::is_valid_absolute_url( $custom ) ) {
					return esc_url_raw( trim( $custom ) );
				}
				$url = get_term_link( $term );
				return is_wp_error( $url ) ? '' : $url;
			}
		}

		if ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( get_post_type() );
			return $link ? $link : '';
		}

		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof WP_User ) {
				return get_author_posts_url( $user->ID );
			}
		}

		if ( is_date() ) {
			if ( is_day() ) {
				return get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
			}
			if ( is_month() ) {
				return get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
			}
			if ( is_year() ) {
				return get_year_link( (int) get_query_var( 'year' ) );
			}
		}

		if ( is_archive() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Term ) {
				$url = get_term_link( $obj );
				return is_wp_error( $url ) ? '' : $url;
			}
		}

		// (Removed an unreachable second is_paged() block here: is_paged() is only true when
		// paged > 1, which the earlier is_paged() branch above already handles and returns.)

		global $wp;
		if ( isset( $wp->request ) && is_string( $wp->request ) ) {
			return user_trailingslashit( home_url( add_query_arg( array(), $wp->request ) ) );
		}

		return home_url( '/' );
	}

	/**
	 * Canonical for singular: custom meta, else WordPress default.
	 *
	 * @return string|false
	 */
	private static function resolve_singular_canonical() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$custom = get_post_meta( $post->ID, self::META_CANONICAL, true );
		if ( self::is_valid_absolute_url( $custom ) ) {
			return esc_url_raw( trim( $custom ) );
		}

		// A static front page's canonical is the site root, not the page's stored permalink
		// (which can carry the page slug, e.g. /home/). Emit the home URL with the site's
		// trailing-slash preference so the front page never self-canonicals to /home/.
		if ( (int) $post->ID === (int) get_option( 'page_on_front' ) ) {
			return user_trailingslashit( home_url( '/' ) );
		}

		$default = wp_get_canonical_url( $post->ID );
		if ( is_string( $default ) && $default !== '' ) {
			return $default;
		}

		return get_permalink( $post );
	}

	/**
	 * @param mixed $url Candidate URL.
	 * @return bool
	 */
	private static function is_valid_absolute_url( $url ) {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}
}
