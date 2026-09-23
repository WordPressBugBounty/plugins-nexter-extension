<?php
/**
 * Content SEO – Permalinks: optional /category/ base removal.
 *
 * Strips the category base segment from category archive URLs (e.g.
 * /category/news/ -> /news/) when the `strip_category_base` option is on.
 *
 * Rather than leaving WordPress's own /category/%category%/ rule in place and
 * bolting a second, shorter rule beside it — which would leave both URLs live
 * and serving the same archive, a duplicate-content problem for an SEO
 * feature to cause — this replaces the category taxonomy's rewrite rules
 * outright via the `category_rewrite_rules` filter (the same hook core's own
 * rewrite generator fires while building the ruleset), so the base URL stops
 * resolving as a rewrite match at all. Anything still linking to the old
 * /category/{slug}/ form — an external backlink, a browser bookmark, a
 * search result not yet recrawled — is caught by
 * maybe_redirect_legacy_category_url() and 301'd to the short URL instead of
 * hitting a 404.
 *
 * Known limitation, shared by every implementation of this feature (core has
 * never shipped one): a category slug that collides with a page's slug is
 * ambiguous once the base is gone. The page wins — see
 * favor_page_over_category_on_slug_collision() — but the colliding category
 * becomes unreachable by its shortened URL.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.7.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Permalinks
 */
class Nexter_Content_SEO_Permalinks {

	/** Bump when the rewrite-rule shape below changes, to force a one-time flush on upgrade. */
	const RULES_VERSION = 1;

	/** One-shot flush flag, kept separate from the sitemap module's so neither races to consume the other's. */
	const FLUSH_OPTION = 'nexter_content_seo_category_base_flush_rewrite';

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Always wired so turning the option back OFF also gets its one rewrite flush and the
		// extra rules disappear on the next request, not just when it's turned on.
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );

		if ( empty( Nexter_Content_SEO::get_options( 'strip_category_base' ) ) ) {
			return;
		}

		add_filter( 'category_link', array( __CLASS__, 'strip_base_from_link' ) );
		add_filter( 'category_rewrite_rules', array( __CLASS__, 'replace_category_rewrite_rules' ) );
		add_filter( 'request', array( __CLASS__, 'favor_page_over_category_on_slug_collision' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_legacy_category_url' ), 1 );

		// The replaced ruleset is built from the categories that exist right now, so any category
		// added, renamed or removed afterwards needs a fresh flush to pick up the change — a plain
		// settings save (the only other trigger) may not happen for months.
		foreach ( array( 'created_category', 'edited_category', 'delete_category' ) as $hook ) {
			add_action( $hook, 'flush_rewrite_rules' );
		}
	}

	/**
	 * Remove the category base segment ("category" or a custom one from Settings → Permalinks)
	 * from a generated category link.
	 *
	 * @param string $link Category archive URL.
	 * @return string
	 */
	public static function strip_base_from_link( $link ) {
		if ( ! is_string( $link ) || '' === $link ) {
			return $link;
		}
		return str_ireplace( '/' . self::category_base() . '/', '/', $link );
	}

	/**
	 * The base segment to strip: the custom "Category base" from Settings → Permalinks, or
	 * "category" when that field is left blank.
	 *
	 * @return string
	 */
	private static function category_base() {
		$base = trim( (string) get_option( 'category_base' ), '/' );
		return ( '' !== $base ) ? $base : 'category';
	}

	/**
	 * Full slash-joined slug path for a category, matching what its stripped link looks like —
	 * "parent/child" for a nested category, just "slug" for a top-level one.
	 *
	 * @param WP_Term $category Category term.
	 * @return string
	 */
	private static function slug_path( $category ) {
		if ( 0 === (int) $category->parent || (int) $category->parent === (int) $category->term_id ) {
			return $category->slug;
		}
		$ancestry = get_category_parents( $category->parent, false, '/', true );
		return is_wp_error( $ancestry ) ? $category->slug : trim( $ancestry, '/' ) . '/' . $category->slug;
	}

	/**
	 * Build the base-less rule set for every category and hand it back in place of whatever core
	 * generated — a plain slug match, a paged match, and a feed match per category, each pointing
	 * at the ordinary `category_name` query var so every other part of WordPress's category
	 * handling (pagination, feeds, term meta) keeps working exactly as it does today.
	 *
	 * @return array<string, string> Regex => query string, in the shape core's rewrite rules use.
	 */
	public static function replace_category_rewrite_rules() {
		$categories = get_categories( array( 'hide_empty' => false ) );
		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			return array();
		}

		$rules = array();
		foreach ( $categories as $category ) {
			$slug = self::slug_path( $category );
			if ( '' === $slug ) {
				continue;
			}
			$pattern = preg_quote( $slug, '`' );

			$rules[ '^' . $pattern . '/(feed|rdf|rss|rss2|atom)/?$' ]  = 'index.php?category_name=' . $slug . '&feed=$matches[1]';
			$rules[ '^' . $pattern . '/page/([0-9]+)/?$' ]             = 'index.php?category_name=' . $slug . '&paged=$matches[1]';
			$rules[ '^' . $pattern . '/?$' ]                           = 'index.php?category_name=' . $slug;
		}

		/**
		 * Filter the base-less category rewrite rules Nexter SEO generates.
		 *
		 * @param array<string, string> $rules      Regex => query string.
		 * @param WP_Term[]             $categories Categories the rules were built from.
		 */
		return (array) apply_filters( 'nexter_content_seo_category_rewrite_rules', $rules, $categories );
	}

	/**
	 * When the shortened URL's slug actually belongs to a page rather than a category (a
	 * collision created before or after the feature was turned on), route to that page instead
	 * of letting `category_name` win by default — a page silently losing its own URL would be a
	 * much worse surprise than a category briefly sharing a slug.
	 *
	 * @param array $query_vars Parsed request query vars.
	 * @return array
	 */
	public static function favor_page_over_category_on_slug_collision( $query_vars ) {
		if ( empty( $query_vars['category_name'] ) ) {
			return $query_vars;
		}
		$top_slug = strtok( (string) $query_vars['category_name'], '/' );
		$page     = get_page_by_path( trim( (string) $top_slug, '/' ) );
		return ( $page instanceof WP_Post ) ? array( 'pagename' => $page->post_name ) : $query_vars;
	}

	/**
	 * 301 a still-incoming /category/{slug}/ request to its base-less equivalent instead of
	 * letting it 404 now that the base is no longer part of the rewrite rules. Keeps old
	 * backlinks, bookmarks and not-yet-recrawled search results pointed at a live URL rather
	 * than a dead end, and avoids the two URLs ever both resolving (duplicate content) — this
	 * fires only after core's own routing has already failed to match anything.
	 */
	public static function maybe_redirect_legacy_category_url() {
		if ( ! is_404() ) {
			return;
		}
		global $wp;
		$requested = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( '' === $requested ) {
			return;
		}
		$base = self::category_base();
		if ( 0 !== strpos( $requested, $base . '/' ) ) {
			return;
		}
		$without_base = substr( $requested, strlen( $base ) + 1 );
		$top_slug     = strtok( $without_base, '/' );
		if ( ! term_exists( $top_slug, 'category' ) ) {
			return; // Base-prefixed 404 that isn't actually one of our categories — leave it a 404.
		}
		wp_safe_redirect( home_url( user_trailingslashit( $without_base ) ), 301 );
		exit;
	}

	/**
	 * Consume the one-shot flush flag set when `strip_category_base` is toggled (either
	 * direction), and auto-flush once after an upgrade that changed the rewrite rules this class
	 * registers.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
			return;
		}
		if ( (int) get_option( 'nexter_content_seo_category_base_rules_ver', 0 ) !== self::RULES_VERSION ) {
			update_option( 'nexter_content_seo_category_base_rules_ver', self::RULES_VERSION, false );
			flush_rewrite_rules( false );
		}
	}
}
