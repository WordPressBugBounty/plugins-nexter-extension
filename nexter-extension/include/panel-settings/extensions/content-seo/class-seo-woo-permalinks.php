<?php
/**
 * Content SEO – Permalinks: optional WooCommerce base removal.
 *
 * Strips WooCommerce's URL bases when the matching option is on:
 * /product/widget/ -> /widget/, /product-category/x/ -> /x/, /product-tag/x/ -> /x/.
 *
 * WooCommerce deliberately refuses to do this itself: wc_get_permalink_structure()
 * array_filter()s the saved option and reapplies its defaults, the admin screen rewrites an
 * empty base back to "product", and an empty rewrite slug makes it register the post type with
 * rewrite => false, which kills pretty product URLs outright. So the bases stay registered and
 * are removed on top.
 *
 * Unlike the /category/ feature next door, WooCommerce's own rules are KEPT rather than replaced,
 * and the short URL is layered on in addition. That is what makes a slug collision survivable: a
 * product whose slug is already owned by a page or post keeps its based URL and stays reachable,
 * instead of being redirected onto a URL that serves something else. Duplicate content is avoided
 * by the other half of the rule — whenever the short URL IS free, the based URL 301s to it, so
 * only ever one of the two answers 200.
 *
 * Products cannot be enumerated into explicit rules (a store may hold thousands, and one rule per
 * product would bloat the rewrite_rules option), so they get a single root catch-all. Pages are
 * protected from it by verbose page rules — the same switch WooCommerce flips when a shop page is
 * used as the product base — and posts by resolve_root_slug_collision(). Product taxonomies are
 * small enough to enumerate, so they get explicit rules and never touch the root catch-all path.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.7.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Woo_Permalinks
 */
class Nexter_Content_SEO_Woo_Permalinks {

	/** Bump when the rewrite-rule shape below changes, to force a one-time flush on upgrade. */
	const RULES_VERSION = 1;

	/** One-shot flush flag, kept apart from the category-base and sitemap flags. */
	const FLUSH_OPTION = 'nexter_content_seo_woo_base_flush_rewrite';

	/** Option key holding the rules version this site last flushed for. */
	const VERSION_OPTION = 'nexter_content_seo_woo_base_rules_ver';

	/** The three targets this class can strip, in the order the legacy redirect tries them. */
	const TARGETS = array( 'product', 'product_cat', 'product_tag' );

	/**
	 * Hook registration. Everything here is a no-op without WooCommerce.
	 */
	public static function init() {
		// Always wired, so turning an option back OFF also gets its one flush.
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );

		// WooCommerce registers the product post type on init, which is after this class is
		// wired up, so everything that depends on it waits for that — but still lands before the
		// rewrite flush on init:99.
		add_action( 'init', array( __CLASS__, 'register_hooks' ), 20 );
	}

	/**
	 * Attach the filters, once WooCommerce has registered its post type and taxonomies.
	 */
	public static function register_hooks() {
		if ( ! self::woo_active() ) {
			return;
		}

		self::register_root_rules();
		add_filter( 'request', array( __CLASS__, 'resolve_root_request' ) );

		if ( self::enabled( 'product' ) ) {
			add_filter( 'post_type_link', array( __CLASS__, 'strip_base_from_product_link' ), 20, 2 );
		}

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( ! self::enabled( $taxonomy ) ) {
				continue;
			}
			foreach ( array( 'created_', 'edited_', 'delete_' ) as $event ) {
				// The added ruleset is built from the terms that exist right now.
				add_action( $event . $taxonomy, 'flush_rewrite_rules' );
			}
		}

		if ( self::enabled( 'product_cat' ) || self::enabled( 'product_tag' ) ) {
			add_filter( 'term_link', array( __CLASS__, 'strip_base_from_term_link' ), 20, 3 );
		}

		if ( self::any_enabled() ) {
			add_action( 'template_redirect', array( __CLASS__, 'redirect_based_url_to_short' ), 1 );
		}
	}

	/*
	---------------------------------------------------------------------
	 * Option plumbing
	 * -------------------------------------------------------------------
	 */

	/**
	 * Is WooCommerce providing the permalink structure this class rewrites?
	 *
	 * @return bool
	 */
	public static function woo_active() {
		return function_exists( 'wc_get_permalink_structure' ) && post_type_exists( 'product' );
	}

	/**
	 * Nexter option key for one target.
	 *
	 * @param string $target One of self::TARGETS.
	 * @return string
	 */
	private static function option_key( $target ) {
		$map = array(
			'product'     => 'strip_woo_product_base',
			'product_cat' => 'strip_woo_category_base',
			'product_tag' => 'strip_woo_tag_base',
		);

		return isset( $map[ $target ] ) ? $map[ $target ] : '';
	}

	/**
	 * Is base removal switched on for one target?
	 *
	 * @param string $target One of self::TARGETS.
	 * @return bool
	 */
	public static function enabled( $target ) {
		$key = self::option_key( $target );

		return ( '' !== $key ) && ! empty( Nexter_Content_SEO::get_options( $key ) );
	}

	/**
	 * Any of the three switched on?
	 *
	 * @return bool
	 */
	private static function any_enabled() {
		foreach ( self::TARGETS as $target ) {
			if ( self::enabled( $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The base segment WooCommerce currently uses for one target, without slashes.
	 *
	 * Read through wc_get_permalink_structure() so a store that customised its bases in
	 * Settings → Permalinks gets its own value stripped, not the English default.
	 *
	 * @param string $target One of self::TARGETS.
	 * @return string Empty when WooCommerce is absent or the base is blank.
	 */
	public static function base( $target ) {
		if ( ! self::woo_active() ) {
			return '';
		}
		$permalinks = wc_get_permalink_structure();
		$map        = array(
			'product'     => 'product_rewrite_slug',
			'product_cat' => 'category_rewrite_slug',
			'product_tag' => 'tag_rewrite_slug',
		);
		if ( ! isset( $map[ $target ], $permalinks[ $map[ $target ] ] ) ) {
			return '';
		}

		return trim( (string) $permalinks[ $map[ $target ] ], '/' );
	}

	/**
	 * Is the root-level slug unclaimed by a page or a post?
	 *
	 * The whole design hinges on this: only an unclaimed slug gets the short URL, so a product or
	 * term can never take a URL that already belongs to published content, and never gets
	 * redirected onto one.
	 *
	 * @param string $slug Single slug or slash-joined path.
	 * @return bool
	 */
	public static function slug_is_free( $slug ) {
		$slug = trim( (string) $slug, '/' );
		if ( '' === $slug ) {
			return false;
		}
		if ( get_page_by_path( $slug ) instanceof WP_Post ) {
			return false;
		}
		$top = strtok( $slug, '/' );

		return ! ( get_page_by_path( $top, OBJECT, 'post' ) instanceof WP_Post );
	}

	/*
	---------------------------------------------------------------------
	 * Link output
	 * -------------------------------------------------------------------
	 */

	/**
	 * Remove the product base from a product permalink, unless a page or post already owns that
	 * slug — in which case the product keeps its based URL and stays reachable.
	 *
	 * @param string  $link Permalink.
	 * @param WP_Post $post Post the link belongs to.
	 * @return string
	 */
	public static function strip_base_from_product_link( $link, $post ) {
		if ( ! is_string( $link ) || ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type ) {
			return $link;
		}
		$base = self::base( 'product' );
		if ( '' === $base || ! self::slug_is_free( $post->post_name ) ) {
			return $link;
		}

		return self::remove_segment( $link, $base );
	}

	/**
	 * Remove a product taxonomy base from a term link, unless the slug is already claimed.
	 *
	 * @param string  $link     Term URL.
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy name.
	 * @return string
	 */
	public static function strip_base_from_term_link( $link, $term, $taxonomy ) {
		if ( ! is_string( $link ) || ! in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			return $link;
		}
		if ( ! self::enabled( $taxonomy ) || ! ( $term instanceof WP_Term ) ) {
			return $link;
		}
		$base = self::base( $taxonomy );
		if ( '' === $base || ! self::slug_is_free( self::term_slug_path( $term, $taxonomy ) ) ) {
			return $link;
		}

		return self::remove_segment( $link, $base );
	}

	/**
	 * Drop one path segment from a URL, matching only a whole segment so a base of "product"
	 * never eats part of a slug such as "product-care-guide".
	 *
	 * @param string $link    URL.
	 * @param string $segment Segment to remove (no slashes).
	 * @return string
	 */
	private static function remove_segment( $link, $segment ) {
		$out = preg_replace( '#/' . preg_quote( $segment, '#' ) . '/#i', '/', (string) $link, 1 );

		return ( null === $out ) ? $link : $out;
	}

	/*
	---------------------------------------------------------------------
	 * Rewrite rules — WooCommerce's own are kept, short ones are added
	 * -------------------------------------------------------------------
	 */

	/**
	 * Register the short URLs at the BOTTOM of the rewrite ruleset.
	 *
	 * Position is the whole design. Rules returned from a `{post_type}_rewrite_rules` filter land
	 * near the TOP of the ruleset, where a root catch-all would shadow pages, posts, category
	 * archives — everything with a single-segment URL. Adding with the 'bottom' position instead
	 * means every existing rule is tried first and only a slug nothing else claims falls through
	 * to a product, which removes the need for verbose page rules and their per-request cost.
	 *
	 * Products get one catch-all (a catalogue can hold thousands, and a rule each would bloat the
	 * rewrite_rules option); taxonomies are small enough to enumerate, and are skipped when their
	 * slug is already claimed.
	 */
	public static function register_root_rules() {
		if ( self::enabled( 'product' ) ) {
			$product_rules = array(
				'^([^/]+)/(feed|rdf|rss|rss2|atom)/?$' => 'index.php?product=$matches[1]&feed=$matches[2]',
				'^([^/]+)/comment-page-([0-9]+)/?$'    => 'index.php?product=$matches[1]&cpage=$matches[2]',
				'^([^/]+)/?$'                          => 'index.php?product=$matches[1]',
			);

			/**
			 * Filter the root-level product rewrite rules Nexter SEO adds.
			 *
			 * @param array<string, string> $product_rules Regex => query string.
			 */
			$product_rules = (array) apply_filters( 'nexter_content_seo_woo_product_rewrite_rules', $product_rules );
			foreach ( $product_rules as $regex => $query ) {
				add_rewrite_rule( $regex, $query, 'bottom' );
			}
		}

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( ! self::enabled( $taxonomy ) ) {
				continue;
			}
			foreach ( self::term_root_rules( $taxonomy ) as $regex => $query ) {
				add_rewrite_rule( $regex, $query, 'bottom' );
			}
		}
	}

	/**
	 * Explicit base-less rules for every term in a product taxonomy whose slug is unclaimed,
	 * mirroring the shape core generates so pagination and feeds keep working.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, string>
	 */
	private static function term_root_rules( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$rules = array();
		foreach ( $terms as $term ) {
			$slug = self::term_slug_path( $term, $taxonomy );
			if ( '' === $slug || ! self::slug_is_free( $slug ) ) {
				continue;
			}
			$pattern = preg_quote( $slug, '`' );

			$rules[ '^' . $pattern . '/(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?' . $taxonomy . '=' . $slug . '&feed=$matches[1]';
			$rules[ '^' . $pattern . '/page/([0-9]+)/?$' ]            = 'index.php?' . $taxonomy . '=' . $slug . '&paged=$matches[1]';
			$rules[ '^' . $pattern . '/?$' ]                          = 'index.php?' . $taxonomy . '=' . $slug;
		}

		/**
		 * Filter the root-level product taxonomy rewrite rules Nexter SEO adds.
		 *
		 * @param array<string, string> $rules    Regex => query string.
		 * @param string                $taxonomy Taxonomy the rules were built for.
		 * @param WP_Term[]             $terms    Terms the rules were built from.
		 */
		return (array) apply_filters( 'nexter_content_seo_woo_term_rewrite_rules', $rules, $taxonomy, $terms );
	}

	/**
	 * Slash-joined slug path for a term, so a nested product category keeps its "parent/child"
	 * URL shape once the base is gone.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy name.
	 * @return string
	 */
	private static function term_slug_path( $term, $taxonomy ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return '';
		}
		if ( empty( $term->parent ) ) {
			return (string) $term->slug;
		}
		$path      = array( $term->slug );
		$parent_id = (int) $term->parent;
		$guard     = 0;
		while ( $parent_id > 0 && $guard < 10 ) {
			$parent = get_term( $parent_id, $taxonomy );
			if ( ! ( $parent instanceof WP_Term ) ) {
				break;
			}
			array_unshift( $path, $parent->slug );
			$parent_id = (int) $parent->parent;
			++$guard;
		}

		return implode( '/', $path );
	}

	/**
	 * Hand a root-level slug to the product or product term that owns it.
	 *
	 * Rewrite-rule order cannot carry this on its own. On the common /%postname%/ permalink
	 * structure WordPress generates a post rule that matches every single-segment URL and does
	 * not verify the post exists, so a rule added at the bottom is never reached — and a rule
	 * added at the top would shadow pages and posts instead. Resolving here works whatever the
	 * permalink structure is, and in a fixed order of precedence: a real post or page always
	 * keeps its own URL, and only a slug neither of them claims is handed on.
	 *
	 * @param array $query_vars Parsed request query vars.
	 * @return array
	 */
	public static function resolve_root_request( $query_vars ) {
		// Only a bare root request is in play: no explicit post type, no page match, no archive.
		if ( empty( $query_vars['name'] ) || ! is_string( $query_vars['name'] ) ) {
			return $query_vars;
		}
		if ( ! empty( $query_vars['post_type'] ) || ! empty( $query_vars['pagename'] ) ) {
			return $query_vars;
		}
		$slug = trim( $query_vars['name'], '/' );
		if ( '' === $slug ) {
			return $query_vars;
		}

		// Published content that already owns the slug wins, every time.
		if ( get_page_by_path( $slug, OBJECT, 'post' ) instanceof WP_Post ) {
			return $query_vars;
		}
		if ( get_page_by_path( $slug ) instanceof WP_Post ) {
			return $query_vars;
		}

		if ( self::enabled( 'product' ) && get_page_by_path( $slug, OBJECT, 'product' ) instanceof WP_Post ) {
			// name + post_type, not the `product` query var: setting both makes WordPress read the
			// request as the product archive (the Shop page) instead of a single product.
			$query_vars['post_type'] = 'product';

			return $query_vars;
		}

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( ! self::enabled( $taxonomy ) ) {
				continue;
			}
			if ( term_exists( $slug, $taxonomy ) ) {
				unset( $query_vars['name'] );
				$query_vars[ $taxonomy ] = $slug;

				return $query_vars;
			}
		}

		return $query_vars;
	}

	/*
	---------------------------------------------------------------------
	 * Canonical: the based URL 301s to the short one
	 * -------------------------------------------------------------------
	 */

	/**
	 * When a product or product term is reached through its based URL and the short URL is free,
	 * 301 to the short form. This is what keeps the two URLs from both answering 200 once the
	 * based rules are left in place, and it catches old backlinks and bookmarks at the same time.
	 */
	public static function redirect_based_url_to_short() {
		global $wp;
		$requested = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( '' === $requested || is_admin() || wp_doing_ajax() ) {
			return;
		}

		foreach ( self::TARGETS as $target ) {
			if ( ! self::enabled( $target ) ) {
				continue;
			}
			$base = self::base( $target );
			if ( '' === $base || 0 !== stripos( $requested, $base . '/' ) ) {
				continue;
			}
			$without_base = trim( substr( $requested, strlen( $base ) + 1 ), '/' );
			if ( '' === $without_base || ! self::target_exists( $target, $without_base ) ) {
				continue; // Based URL that is not one of ours — leave it alone.
			}
			if ( ! self::slug_is_free( $without_base ) ) {
				continue; // A page or post owns the short URL; this item keeps its based one.
			}
			wp_safe_redirect( home_url( user_trailingslashit( $without_base ) ), 301 );
			exit;
		}
	}

	/**
	 * Does the path behind a stripped base actually name a product or product term? Keeps the
	 * redirect from inventing a destination for an unrelated URL that starts with the same segment.
	 *
	 * @param string $target One of self::TARGETS.
	 * @param string $path   Request path with the base already removed.
	 * @return bool
	 */
	private static function target_exists( $target, $path ) {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return false;
		}
		if ( 'product' === $target ) {
			return get_page_by_path( strtok( $path, '/' ), OBJECT, 'product' ) instanceof WP_Post;
		}

		$leaf = $path;
		if ( false !== strpos( $leaf, '/' ) ) {
			$parts = array_values( array_filter( explode( '/', $leaf ) ) );
			$leaf  = (string) end( $parts );
		}

		return (bool) term_exists( $leaf, $target );
	}

	/**
	 * Consume the one-shot flush flag set when any of the three options is toggled, and auto-flush
	 * once after an upgrade that changed the rules this class registers.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
			return;
		}
		if ( (int) get_option( self::VERSION_OPTION, 0 ) !== self::RULES_VERSION ) {
			update_option( self::VERSION_OPTION, self::RULES_VERSION, false );
			flush_rewrite_rules( false );
		}
	}
}
