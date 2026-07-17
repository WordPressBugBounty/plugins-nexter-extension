<?php
/**
 * Content SEO – Sitemap settings, XML generation, regenerate.
 * Multilingual: WPML and Polylang support via hreflang alternate links.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Sitemap
 */
class Nexter_Content_SEO_Sitemap {

	const REWRITE_SLUG       = 'sitemap.xml';
	const VIDEO_SLUG         = 'sitemap-video.xml';
	const NEWS_SLUG          = 'sitemap-news.xml';
	const HTML_SLUG          = 'sitemap.html';

	/** Bump when the set of registered rewrite rules changes, to force a one-time flush on upgrade. */
	const RULES_VERSION      = 3;

	/**
	 * Initialize rewrite rules and template redirect.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_output_sitemap' ), 1 );
		// [nexter_seo_sitemap] renders the human-readable sitemap list inside any page/post.
		add_shortcode( 'nexter_seo_sitemap', array( __CLASS__, 'render_html_sitemap_shortcode' ) );
		// Run late so other callbacks cannot re-enable WP core sitemaps afterwards.
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'filter_wp_sitemaps_enabled' ), PHP_INT_MAX );
		add_action( 'nxt_seo_warm_vimeo_thumb', array( __CLASS__, 'warm_vimeo_thumbnail' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'disable_wp_core_sitemaps' ), 5 );
		// Late on init (after our rules are added at pri 5 and core sitemaps are disabled),
		// flush rewrite rules once if a sitemap-enable toggle was just saved. This makes
		// /sitemap.xml resolve as XML right away and removes the stale WP-core sitemap rule.
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );

		// Invalidate the cached sitemap files whenever content or settings change. Post-save hooks
		// are guarded so autosaves and revisions don't thrash the cache on every keystroke-save.
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $post_hook ) {
			add_action( $post_hook, array( __CLASS__, 'maybe_bump_cache_version_for_post' ) );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $term_hook ) {
			add_action( $term_hook, array( __CLASS__, 'bump_cache_version' ) );
		}
		if ( class_exists( 'Nexter_Content_SEO' ) && defined( 'Nexter_Content_SEO::OPTION_NAME' ) ) {
			add_action( 'update_option_' . Nexter_Content_SEO::OPTION_NAME, array( __CLASS__, 'bump_cache_version' ) );
		}
	}

	/**
	 * Consume the one-shot flush flag set when a sitemap-enable setting changes.
	 */
	public static function maybe_flush_rewrite_rules() {
		// One-shot flush requested when a sitemap-enable toggle changed.
		if ( get_option( 'nexter_content_seo_flush_rewrite' ) ) {
			delete_option( 'nexter_content_seo_flush_rewrite' );
			flush_rewrite_rules();
		}
		// Auto-flush once after an upgrade that changed the registered rewrite rules (e.g. the
		// new paginated child-sitemap rule), so existing installs don't 404 on child sitemaps.
		if ( (int) get_option( 'nexter_content_seo_sitemap_rules_ver', 0 ) !== self::RULES_VERSION ) {
			update_option( 'nexter_content_seo_sitemap_rules_ver', self::RULES_VERSION, false );
			flush_rewrite_rules();
		}
	}

	/**
	 * Disable WP core sitemaps explicitly.
	 */
	public static function disable_wp_core_sitemaps() {
		$options = Nexter_Content_SEO::get_options();
		if ( ! empty( $options['enable_xml_sitemap'] ) ) {
			remove_action( 'init', 'wp_sitemaps_get_server' );
			add_filter( 'wp_sitemaps_enabled', '__return_false', PHP_INT_MAX );
		}
	}

	/**
	 * Disable WordPress core XML sitemaps when Nexter XML sitemap is enabled.
	 *
	 * Prevents duplicate sitemap systems (`/wp-sitemap.xml` + `/sitemap.xml`) from running together.
	 *
	 * @param bool $enabled Current WordPress core sitemap enabled state.
	 * @return bool
	 */
	public static function filter_wp_sitemaps_enabled( $enabled ) {
		$options = Nexter_Content_SEO::get_options();
		if ( ! empty( $options['enable_xml_sitemap'] ) ) {
			return false;
		}
		return (bool) $enabled;
	}

	/**
	 * Add rewrite rules for sitemaps.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^' . self::REWRITE_SLUG . '$', 'index.php?nxt_sitemap=1', 'top' );
		add_rewrite_rule( '^' . self::VIDEO_SLUG . '$', 'index.php?nxt_sitemap=video', 'top' );
		add_rewrite_rule( '^' . self::NEWS_SLUG . '$', 'index.php?nxt_sitemap=news', 'top' );
		add_rewrite_rule( '^' . self::HTML_SLUG . '$', 'index.php?nxt_sitemap=html', 'top' );
		// Paginated child sitemaps referenced by the sitemap index, e.g. sitemap-post_post-2.xml
		// or sitemap-tax_category-1.xml. The trailing -<digits>.xml distinguishes these from the
		// fixed video/news slugs above (which never carry a -<digits> suffix).
		// Object group is non-greedy and the page group is pinned to the final numeric segment
		// before .xml, so hyphen-digit CPT/taxonomy slugs (e.g. post_event-2) aren't mis-split.
		// output_child_sitemap() additionally validates the resolved object against
		// post_type_exists()/taxonomy_exists(), so an unmatched key 404s rather than misfires.
		add_rewrite_rule( '^sitemap-(.+?)-([0-9]+)\.xml$', 'index.php?nxt_sitemap=child&nxt_sm_obj=$matches[1]&nxt_sm_page=$matches[2]', 'top' );
		// Serve the human-readable XSLT through WordPress so it always carries a valid XSL MIME
		// type — a static .xsl is served as application/octet-stream by some hosts, which makes
		// browsers refuse to apply it.
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?nxt_sitemap=stylesheet', 'top' );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
	}

	/**
	 * Register query var for sitemap.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'nxt_sitemap';
		$vars[] = 'nxt_sm_obj';
		$vars[] = 'nxt_sm_page';
		return $vars;
	}

	/**
	 * Output sitemap when requested (main XML, video, news, or HTML).
	 */
	public static function maybe_output_sitemap() {
		$type = get_query_var( 'nxt_sitemap' );
		if ( ! $type ) {
			return;
		}
		$options = Nexter_Content_SEO::get_options();

		// The XSLT view exists only to render the XML sitemaps. Serve it only when the stylesheet
		// is enabled AND at least one XML-format sitemap is on; otherwise 404 (it was previously
		// returning 200 even with every sitemap disabled — a stray reachable endpoint).
		if ( 'stylesheet' === $type ) {
			$any_xml = ! empty( $options['enable_xml_sitemap'] ) || ! empty( $options['enable_video_sitemap'] ) || ! empty( $options['enable_news_sitemap'] );
			if ( empty( $options['sitemap_stylesheet'] ) || ! $any_xml ) {
				status_header( 404 );
				nocache_headers();
				exit;
			}
			self::output_stylesheet();
			exit;
		}

		switch ( $type ) {
			case 'video':
				if ( empty( $options['enable_video_sitemap'] ) ) {
					status_header( 404 );
					nocache_headers();
					exit;
				}
				self::output_video_sitemap( $options );
				exit;
			case 'news':
				if ( empty( $options['enable_news_sitemap'] ) ) {
					status_header( 404 );
					nocache_headers();
					exit;
				}
				self::output_news_sitemap( $options );
				exit;
			case 'html':
				if ( empty( $options['enable_html_sitemap'] ) ) {
					status_header( 404 );
					nocache_headers();
					exit;
				}
				self::output_html_sitemap( $options );
				exit;
			case 'child':
				if ( empty( $options['enable_xml_sitemap'] ) ) {
					status_header( 404 );
					nocache_headers();
					exit;
				}
				self::output_child_sitemap( (string) get_query_var( 'nxt_sm_obj' ), (int) get_query_var( 'nxt_sm_page' ), $options );
				exit;
			case '1':
			default:
				if ( empty( $options['enable_xml_sitemap'] ) ) {
					status_header( 404 );
					nocache_headers();
					exit;
				}
				self::output_xml_sitemap( $options );
				exit;
		}
	}

	/** Sitemaps-protocol hard limit on URLs per file. */
	const MAX_URLS_PER_SITEMAP = 50000;

	/**
	 * Convert a WordPress *GMT* datetime string (e.g. post_modified_gmt) to an ISO-8601 string.
	 *
	 * The stored value has no timezone, so strtotime() would parse it in the SERVER's local
	 * timezone — on any non-UTC host that shifts the timestamp and gmdate('c') then emits a wrong
	 * lastmod. Appending ' UTC' forces correct UTC interpretation and output.
	 *
	 * @param string $gmt GMT datetime string ("Y-m-d H:i:s").
	 * @return string ISO-8601 datetime, or '' when the input is empty/invalid.
	 */
	private static function gmt_to_iso( $gmt ) {
		$gmt = trim( (string) $gmt );
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ' UTC' );
		return false === $ts ? '' : gmdate( 'c', $ts );
	}

	/**
	 * `<?xml-stylesheet?>` processing instruction pointing at the human-readable XSLT, or '' when
	 * the stylesheet is disabled (option `sitemap_stylesheet` off, or the filter returns empty).
	 *
	 * @return string
	 */
	private static function stylesheet_pi() {
		$options = Nexter_Content_SEO::get_options();
		if ( empty( $options['sitemap_stylesheet'] ) ) {
			return '';
		}
		// Served through WordPress (see output_stylesheet) so the XSL always has a valid MIME type.
		$url = apply_filters( 'nexter_content_seo_sitemap_stylesheet_url', home_url( '/sitemap.xsl' ) );
		if ( empty( $url ) ) {
			return '';
		}
		return '<?xml-stylesheet type="text/xsl" href="' . esc_url( $url ) . '"?>' . "\n";
	}

	/**
	 * Output the bundled XSLT stylesheet with a correct XSL MIME type.
	 */
	public static function output_stylesheet() {
		$file = __DIR__ . '/sitemap.xsl';
		if ( ! is_readable( $file ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/xslt+xml; charset=utf-8' );
			header( 'X-Robots-Tag: noindex', true );
			header( 'Cache-Control: max-age=86400' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- bundled stylesheet file.
		$xsl = file_get_contents( $file );
		if ( false === $xsl ) {
			status_header( 404 );
			exit;
		}
		// White-label: rebrand the "generated by Nexter SEO" attribution shown to humans viewing
		// the sitemap, so a Pro white-labeled site does not leak the vendor name on the front end.
		$brand = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_label() : 'Nexter SEO';
		if ( 'Nexter SEO' !== $brand ) {
			$xsl = str_replace( 'Nexter SEO', esc_html( $brand ), $xsl ); // esc_html → XML-safe text.
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled XSLT markup, brand value substituted from a trusted option.
		echo $xsl;
	}

	/**
	 * Maximum URLs emitted per sitemap file. Defaults to 1000 (fast, small files) and is
	 * clamped to the 50,000 protocol ceiling. Filterable for large catalogs.
	 *
	 * @return int
	 */
	public static function links_per_sitemap() {
		$n = (int) apply_filters( 'nexter_content_seo_sitemap_links_per_page', 1000 );
		return max( 1, min( self::MAX_URLS_PER_SITEMAP, $n ) );
	}

	/**
	 * Cache version; bumping it invalidates every cached sitemap file at once.
	 *
	 * @return int
	 */
	private static function cache_version() {
		$v = (int) get_option( 'nexter_content_seo_sitemap_cache_ver', 0 );
		if ( $v < 1 ) {
			$v = 1;
			update_option( 'nexter_content_seo_sitemap_cache_ver', $v, false );
		}
		return $v;
	}

	/**
	 * Invalidate all cached sitemap output (called on content / settings changes).
	 */
	public static function bump_cache_version() {
		$v = (int) get_option( 'nexter_content_seo_sitemap_cache_ver', 1 );
		update_option( 'nexter_content_seo_sitemap_cache_ver', $v + 1, false );
	}

	/**
	 * Bump the sitemap cache version for a post change, but skip autosaves and revisions so a
	 * post being edited doesn't invalidate the whole sitemap cache on every autosave tick.
	 *
	 * @param int $post_id Post ID from the save/trash hook.
	 */
	public static function maybe_bump_cache_version_for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id && ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) ) {
			return;
		}
		self::bump_cache_version();
	}

	/**
	 * Fingerprint of the options that affect sitemap output, for the cache key.
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function options_fingerprint( $options ) {
		$relevant = array();
		foreach ( array( 'sitemap_include_images', 'sitemap_exclude_post_types', 'sitemap_exclude_taxonomies', 'noindex_post_types' ) as $k ) {
			$relevant[ $k ] = isset( $options[ $k ] ) ? $options[ $k ] : null;
		}
		return md5( wp_json_encode( $relevant ) . '|' . self::links_per_sitemap() );
	}

	/**
	 * Emit XML for a sitemap "file", served from a short-lived transient when available.
	 *
	 * @param string   $cache_id Stable id for this file (e.g. 'main', 'child:post_post:2').
	 * @param array    $options  SEO options.
	 * @param callable $builder  Returns the XML string when a cache miss occurs.
	 */
	private static function emit_cached( $cache_id, $options, $builder ) {
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex', true );

		$ttl = (int) apply_filters( 'nexter_content_seo_sitemap_cache_ttl', 12 * HOUR_IN_SECONDS );
		$key = 'nxt_sm_' . md5( $cache_id . '|' . self::cache_version() . '|' . self::options_fingerprint( $options ) );

		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built escaped XML.
				return;
			}
		}

		$xml = (string) call_user_func( $builder );

		if ( $ttl > 0 ) {
			set_transient( $key, $xml, $ttl );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built escaped XML.
	}

	/**
	 * `<urlset>`/`<sitemapindex>` namespace attribute string.
	 *
	 * @param bool $include_images Whether to add the image namespace.
	 * @param bool $is_multilingual Whether to add the xhtml (hreflang) namespace.
	 * @return string
	 */
	private static function urlset_namespaces( $include_images, $is_multilingual ) {
		$ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $include_images ) {
			$ns .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		if ( $is_multilingual ) {
			$ns .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
		}
		return $ns;
	}

	/**
	 * Output XML sitemap content (cached). Serves a single <urlset> for small sites and a
	 * paginated <sitemapindex> once the URL count exceeds one file's worth.
	 *
	 * @param array $options SEO options.
	 */
	public static function output_xml_sitemap( $options ) {
		self::emit_cached(
			'main',
			$options,
			function () use ( $options ) {
				return self::build_main_sitemap( $options );
			}
		);
	}

	/**
	 * Output one paginated child sitemap (cached).
	 *
	 * @param string $obj     Object key, e.g. 'post_post' or 'tax_category'.
	 * @param int    $page    1-based page number.
	 * @param array  $options SEO options.
	 */
	public static function output_child_sitemap( $obj, $page, $options ) {
		$obj  = (string) $obj;
		$page = max( 1, (int) $page );
		// Arbitrary/nonexistent object keys (e.g. /sitemap-posts-xyz.xml) must 404, not serve a
		// 200 with an empty <urlset> (a soft-404 that confuses crawlers).
		if ( ! self::child_object_has_content( $obj, $options ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		self::emit_cached(
			'child:' . $obj . ':' . $page,
			$options,
			function () use ( $obj, $page, $options ) {
				return self::build_child_sitemap( $obj, $page, $options );
			}
		);
	}

	/**
	 * Whether a child-sitemap object key maps to a real, non-excluded, non-empty post type or
	 * taxonomy. Used to 404 arbitrary/empty child sitemap requests instead of soft-404ing.
	 *
	 * @param string $obj     Object key ('post_{type}' | 'tax_{taxonomy}').
	 * @param array  $options SEO options.
	 * @return bool
	 */
	private static function child_object_has_content( $obj, $options ) {
		$parts = explode( '_', (string) $obj, 2 );
		$kind  = isset( $parts[0] ) ? $parts[0] : '';
		$slug  = isset( $parts[1] ) ? $parts[1] : '';
		if ( '' === $slug ) {
			return false;
		}
		if ( 'post' === $kind ) {
			if ( ! post_type_exists( $slug ) || in_array( $slug, self::excluded_post_types( $options ), true ) ) {
				return false;
			}
			$counts = wp_count_posts( $slug );
			return isset( $counts->publish ) && (int) $counts->publish > 0;
		}
		if ( 'tax' === $kind ) {
			if ( ! taxonomy_exists( $slug ) || in_array( $slug, self::excluded_taxonomies( $options ), true ) ) {
				return false;
			}
			$c = wp_count_terms( array( 'taxonomy' => $slug, 'hide_empty' => true ) );
			return ! is_wp_error( $c ) && (int) $c > 0;
		}
		return false;
	}

	/**
	 * Decide single-file vs. index and build the main sitemap XML.
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function build_main_sitemap( $options ) {
		$per = self::links_per_sitemap();
		if ( self::estimate_total_urls( $options ) <= $per ) {
			return self::build_single_urlset( $options );
		}
		return self::build_sitemap_index( self::get_sitemap_sections( $options, $per ), $options );
	}

	/**
	 * Upper-bound estimate of total sitemap URLs (homepage + published posts + non-empty terms).
	 *
	 * @param array $options SEO options.
	 * @return int
	 */
	private static function estimate_total_urls( $options ) {
		$total         = 1; // Homepage.
		$exclude_types = self::excluded_post_types( $options );
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( in_array( $pt, $exclude_types, true ) ) {
				continue;
			}
			$counts = wp_count_posts( $pt );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		$exclude_tax = self::excluded_taxonomies( $options );
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( in_array( $tax, $exclude_tax, true ) ) {
				continue;
			}
			$c = wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
			$total += is_wp_error( $c ) ? 0 : (int) $c;
		}
		return $total;
	}

	/**
	 * Ordered list of child-sitemap chunks for the index.
	 *
	 * @param array $options SEO options.
	 * @param int   $per     URLs per file.
	 * @return array<int, array{obj: string, page: int}>
	 */
	private static function get_sitemap_sections( $options, $per ) {
		$sections      = array();
		$exclude_types = self::excluded_post_types( $options );
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( in_array( $pt, $exclude_types, true ) ) {
				continue;
			}
			$counts = wp_count_posts( $pt );
			$pub    = isset( $counts->publish ) ? (int) $counts->publish : 0;
			if ( $pub < 1 ) {
				continue;
			}
			// The first included post type also carries the homepage URL (see build_child_sitemap),
			// which consumes one slot — so count it toward the page total.
			$slots = ( self::first_included_post_type( $options ) === $pt ) ? ( $pub + 1 ) : $pub;
			$pages = (int) ceil( $slots / $per );
			for ( $p = 1; $p <= $pages; $p++ ) {
				$sections[] = array(
					'obj'  => 'post_' . $pt,
					'page' => $p,
				);
			}
		}
		$exclude_tax = self::excluded_taxonomies( $options );
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( in_array( $tax, $exclude_tax, true ) ) {
				continue;
			}
			$c = wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
			$c = is_wp_error( $c ) ? 0 : (int) $c;
			if ( $c < 1 ) {
				continue;
			}
			$pages = (int) ceil( $c / $per );
			for ( $p = 1; $p <= $pages; $p++ ) {
				$sections[] = array(
					'obj'  => 'tax_' . $tax,
					'page' => $p,
				);
			}
		}
		return $sections;
	}

	/**
	 * Build the <sitemapindex> XML listing every child file.
	 *
	 * @param array $sections Section descriptors.
	 * @param array $options  SEO options (unused but kept for symmetry/filters).
	 * @return string
	 */
	private static function build_sitemap_index( $sections, $options ) {
		unset( $options );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= self::stylesheet_pi();
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $sections as $s ) {
			$loc  = self::child_sitemap_url( $s['obj'], $s['page'] );
			// Per-section lastmod: the max modified date of THAT section's content, not one global
			// site-wide date stamped on every child (which made untouched sections look freshly
			// updated to crawlers).
			$lastmod = self::section_lastmod_iso( $s['obj'] );
			$xml    .= "\t<sitemap>\n";
			$xml    .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
			$xml    .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
			$xml    .= "\t</sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
	}

	/**
	 * ISO-8601 last-modified date for a single sitemap-index section ("post_<type>" or
	 * "tax_<taxonomy>"), computed as the max published-post modified date scoped to that section.
	 * Memoized per request (a section can span several index pages that share the same date), and
	 * falls back to the global most-recent date, then now.
	 *
	 * @param string $obj Section id.
	 * @return string
	 */
	private static function section_lastmod_iso( $obj ) {
		static $cache = array();
		if ( isset( $cache[ $obj ] ) ) {
			return $cache[ $obj ];
		}
		global $wpdb;
		$obj = (string) $obj;
		$gmt = '';

		if ( 0 === strpos( $obj, 'post_' ) ) {
			$post_type = substr( $obj, 5 );
			$gmt       = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
					$post_type
				)
			);
		} elseif ( 0 === strpos( $obj, 'tax_' ) ) {
			$taxonomy = substr( $obj, 4 );
			$gmt      = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX( p.post_modified_gmt )
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND p.post_status = 'publish'",
					$taxonomy
				)
			);
		}

		$iso = ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) ? self::gmt_to_iso( $gmt ) : '';
		if ( '' === $iso ) {
			$global = get_lastpostmodified( 'GMT' );
			$iso    = $global ? self::gmt_to_iso( $global ) : gmdate( 'c' );
		}

		$cache[ $obj ] = $iso;
		return $iso;
	}

	/**
	 * Build a single full <urlset> with every URL (used for small sites).
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function build_single_urlset( $options ) {
		$include_images  = ! empty( $options['sitemap_include_images'] );
		$is_multilingual = self::is_multilingual();
		$batch_size      = 500;
		$exclude_types   = self::excluded_post_types( $options );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= self::stylesheet_pi();
		$xml .= '<urlset ' . self::urlset_namespaces( $include_images, $is_multilingual ) . '>' . "\n";

		$lastmod = get_lastpostmodified( 'GMT' ) ? self::gmt_to_iso( get_lastpostmodified( 'GMT' ) ) : gmdate( 'c' );

		ob_start();

		// Homepage.
		$home            = home_url( '/' );
		$home_alternates = $is_multilingual ? self::get_home_alternate_urls() : array();
		self::echo_url_entry( $home, $lastmod, 'daily', '1.0', array(), $home_alternates );

		// Public post types.
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( in_array( $pt, $exclude_types, true ) ) {
				continue;
			}
			$page = 1;
			while ( true ) {
				$posts = get_posts( array(
					'post_type'              => $pt,
					'post_status'            => 'publish',
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'has_password'           => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );

				if ( empty( $posts ) ) {
					break;
				}

				foreach ( $posts as $post ) {
					if ( 'publish' !== $post->post_status || self::is_post_excluded_from_sitemap( $post, $options ) ) {
						continue;
					}
					$url = get_permalink( $post );
					if ( ! $url ) {
						continue;
					}
					$mod        = $post->post_modified_gmt ? self::gmt_to_iso( $post->post_modified_gmt ) : $lastmod;
					$alternates = $is_multilingual ? self::get_post_alternate_urls( $post->ID ) : array();
					$images     = $include_images ? self::get_post_images( $post ) : array();
					self::echo_url_entry( $url, $mod, 'weekly', '0.8', $images, $alternates );
				}
				++$page;
			}
		}

		// Taxonomies (archive URLs).
		$exclude_tax = self::excluded_taxonomies( $options );
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( in_array( $tax, $exclude_tax, true ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( class_exists( 'Nexter_Content_SEO_Robots' ) && Nexter_Content_SEO_Robots::is_term_archive_noindex( $term ) ) {
					continue;
				}
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$mod        = self::get_term_lastmod_iso( $term );
				$alternates = $is_multilingual ? self::get_term_alternate_urls( $term->term_id, $tax ) : array();
				self::echo_url_entry( $url, $mod, 'weekly', '0.6', array(), $alternates );
			}
		}

		$xml .= ob_get_clean();
		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * Build one paginated child <urlset> for a single post type or taxonomy chunk.
	 *
	 * @param string $obj     'post_<type>' or 'tax_<taxonomy>'.
	 * @param int    $page    1-based page.
	 * @param array  $options SEO options.
	 * @return string
	 */
	private static function build_child_sitemap( $obj, $page, $options ) {
		$include_images  = ! empty( $options['sitemap_include_images'] );
		$is_multilingual = self::is_multilingual();
		$per             = self::links_per_sitemap();
		$page            = max( 1, (int) $page );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= self::stylesheet_pi();
		$xml .= '<urlset ' . self::urlset_namespaces( $include_images, $is_multilingual ) . '>' . "\n";

		$lastmod = get_lastpostmodified( 'GMT' ) ? self::gmt_to_iso( get_lastpostmodified( 'GMT' ) ) : gmdate( 'c' );

		$parts = explode( '_', $obj, 2 );
		$kind  = isset( $parts[0] ) ? $parts[0] : '';
		$slug  = isset( $parts[1] ) ? $parts[1] : '';

		ob_start();

		if ( 'post' === $kind && post_type_exists( $slug ) && ! in_array( $slug, self::excluded_post_types( $options ), true ) ) {
			// The homepage rides along on the first page of the first included post type.
			$is_first_type = ( self::first_included_post_type( $options ) === $slug );
			$home_rides    = ( 1 === $page && $is_first_type );
			if ( $home_rides ) {
				$home_alternates = $is_multilingual ? self::get_home_alternate_urls() : array();
				self::echo_url_entry( home_url( '/' ), $lastmod, 'daily', '1.0', array(), $home_alternates );
			}
			// The homepage occupies one slot on page 1 of the first post type, so shift that
			// type's post window by one (offset-based) to keep every page at $per URLs — no
			// over-count on page 1 and no skipped/duplicated post on later pages.
			if ( $is_first_type ) {
				$limit  = $home_rides ? max( 1, $per - 1 ) : $per;
				$offset = $home_rides ? 0 : ( ( $per - 1 ) + ( $page - 2 ) * $per );
			} else {
				$limit  = $per;
				$offset = ( $page - 1 ) * $per;
			}
			$posts = get_posts( array(
				'post_type'              => $slug,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'offset'                 => max( 0, (int) $offset ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'has_password'           => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			foreach ( $posts as $post ) {
				if ( 'publish' !== $post->post_status || self::is_post_excluded_from_sitemap( $post, $options ) ) {
					continue;
				}
				$url = get_permalink( $post );
				if ( ! $url ) {
					continue;
				}
				$mod        = $post->post_modified_gmt ? self::gmt_to_iso( $post->post_modified_gmt ) : $lastmod;
				$alternates = $is_multilingual ? self::get_post_alternate_urls( $post->ID ) : array();
				$images     = $include_images ? self::get_post_images( $post ) : array();
				self::echo_url_entry( $url, $mod, 'weekly', '0.8', $images, $alternates );
			}
		} elseif ( 'tax' === $kind && taxonomy_exists( $slug ) && ! in_array( $slug, self::excluded_taxonomies( $options ), true ) ) {
			$terms = get_terms( array(
				'taxonomy'   => $slug,
				'hide_empty' => true,
				'number'     => $per,
				'offset'     => ( $page - 1 ) * $per,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( class_exists( 'Nexter_Content_SEO_Robots' ) && Nexter_Content_SEO_Robots::is_term_archive_noindex( $term ) ) {
						continue;
					}
					$url = get_term_link( $term );
					if ( is_wp_error( $url ) ) {
						continue;
					}
					$mod        = self::get_term_lastmod_iso( $term );
					$alternates = $is_multilingual ? self::get_term_alternate_urls( $term->term_id, $slug ) : array();
					self::echo_url_entry( $url, $mod, 'weekly', '0.6', array(), $alternates );
				}
			}
		}

		$xml .= ob_get_clean();
		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * First public, non-excluded post type that has published content.
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function first_included_post_type( $options ) {
		$exclude = self::excluded_post_types( $options );
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( in_array( $pt, $exclude, true ) ) {
				continue;
			}
			$counts = wp_count_posts( $pt );
			if ( isset( $counts->publish ) && (int) $counts->publish > 0 ) {
				return $pt;
			}
		}
		return 'post';
	}

	/**
	 * Build the public URL of a child sitemap file.
	 *
	 * @param string $obj  Object key.
	 * @param int    $page 1-based page.
	 * @return string
	 */
	public static function child_sitemap_url( $obj, $page ) {
		return home_url( '/sitemap-' . $obj . '-' . max( 1, (int) $page ) . '.xml' );
	}

	/**
	 * Excluded public post types from options.
	 *
	 * @param array $options SEO options.
	 * @return string[]
	 */
	private static function excluded_post_types( $options ) {
		$user = ( isset( $options['sitemap_exclude_post_types'] ) && is_array( $options['sitemap_exclude_post_types'] ) )
			? array_keys( array_filter( $options['sitemap_exclude_post_types'] ) )
			: array();
		$excluded = array_values( array_unique( array_merge( $user, self::builder_utility_post_types() ) ) );
		/**
		 * Filter the full list of post types excluded from every sitemap (main/child/video/HTML).
		 *
		 * @param string[] $excluded Post type slugs.
		 * @param array    $options  SEO options.
		 */
		return (array) apply_filters( 'nexter_content_seo_sitemap_excluded_post_types', $excluded, $options );
	}

	/**
	 * Page-builder / utility post types that hold internal fragments (templates, popups, global
	 * blocks) rather than indexable content. Default-excluded from sitemaps so builder junk URLs
	 * (elementor_library, theplus_*, Oxygen/Beaver/Divi/Brizy templates, FSE wp_template*, …)
	 * never leak. Only currently-registered types are returned; fully filterable upstream via
	 * nexter_content_seo_sitemap_excluded_post_types.
	 *
	 * @return string[]
	 */
	private static function builder_utility_post_types() {
		$deny_slugs = array(
			// Elementor.
			'elementor_library', 'e-landing-page', 'elementor_font', 'elementor_icons', 'e-floating-buttons',
			// Nexter.
			'nxt_builder', 'nexter_builder',
			// Themes / other builders.
			'oceanwp_library', 'ct_template', 'fl-builder-template', 'fl-theme-layout',
			'fusion_template', 'fusion_element', 'fusion_tb_section',
			'brizy_template', 'brizy-global-blocks', 'cornerstone',
			// JetEngine utility (not user CPTs).
			'jet-menu', 'jet-popup', 'jet-woo-builder', 'jet-theme-core', 'jet-smart-filters', 'jet-engine',
			// WordPress FSE / system.
			'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block',
			'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		);
		$deny_prefixes = array( 'theplus_', 'tve_' );

		$out = array();
		foreach ( (array) get_post_types( array(), 'names' ) as $slug ) {
			$slug = (string) $slug;
			if ( in_array( $slug, $deny_slugs, true ) ) {
				$out[] = $slug;
				continue;
			}
			foreach ( $deny_prefixes as $prefix ) {
				if ( 0 === strpos( $slug, $prefix ) ) {
					$out[] = $slug;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Excluded public taxonomies from options.
	 *
	 * @param array $options SEO options.
	 * @return string[]
	 */
	private static function excluded_taxonomies( $options ) {
		return isset( $options['sitemap_exclude_taxonomies'] ) && is_array( $options['sitemap_exclude_taxonomies'] )
			? array_keys( array_filter( $options['sitemap_exclude_taxonomies'] ) )
			: array();
	}

	/**
	 * Output Video Sitemap XML (Google Video Sitemap format).
	 *
	 * @param array $options SEO options.
	 */
	public static function output_video_sitemap( $options ) {
		// Cache the whole document (12h transient, invalidated on any content/settings change via
		// cache_version) so the per-request LIKE '%youtube%' candidate scan runs only on a miss.
		self::emit_cached(
			'video',
			$options,
			function () use ( $options ) {
				ob_start();
				self::render_video_sitemap_body( $options );
				return (string) ob_get_clean();
			}
		);
	}

	/**
	 * Echo the Video Sitemap body (no headers, no flush — captured by output_video_sitemap()).
	 *
	 * @param array $options SEO options.
	 */
	private static function render_video_sitemap_body( $options ) {
		$exclude_types = self::excluded_post_types( $options );
		$batch_size = 200;

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $pt ) {
			if ( in_array( $pt, $exclude_types, true ) ) {
				continue;
			}
			$page = 1;
			while ( true ) {
				$candidate_ids = self::get_video_candidate_post_ids( $pt, $page, $batch_size );
				if ( empty( $candidate_ids ) ) {
					break;
				}

				$posts = get_posts(
					array(
						'post_type'              => $pt,
						'post_status'            => 'publish',
						'posts_per_page'         => count( $candidate_ids ),
						'post__in'               => $candidate_ids,
						'orderby'                => 'post__in',
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $posts as $post ) {
					// Never advertise noindex / password-protected / excluded posts to Google,
					// even if they contain a video embed (same rule as the main sitemap).
					if ( self::is_post_excluded_from_sitemap( $post, $options ) ) {
						continue;
					}
					$videos = self::get_post_videos( $post );
					if ( empty( $videos ) ) {
						continue;
					}
					$url = get_permalink( $post );
					if ( ! $url ) {
						continue;
					}
					$mod = $post->post_modified_gmt ? self::gmt_to_iso( $post->post_modified_gmt ) : gmdate( 'c' );
					echo '<url>';
					echo '<loc>' . esc_url( $url ) . '</loc>';
					echo '<lastmod>' . esc_html( $mod ) . '</lastmod>';
					foreach ( $videos as $video ) {
						self::echo_video_entry( $video, $post );
					}
					echo '</url>' . "\n";
				}

				++$page;
			}
		}

		echo '</urlset>';
	}

	/**
	 * Output News Sitemap XML (Google News format, last 48 hours), cached like the main sitemap.
	 *
	 * @param array $options SEO options.
	 */
	public static function output_news_sitemap( $options ) {
		self::emit_cached(
			'news',
			$options,
			function () use ( $options ) {
				ob_start();
				self::render_news_sitemap_body( $options );
				return (string) ob_get_clean();
			}
		);
	}

	/**
	 * Echo the News Sitemap body (no headers — captured by output_news_sitemap()).
	 *
	 * @param array $options SEO options.
	 */
	private static function render_news_sitemap_body( $options ) {
		$exclude_types = self::excluded_post_types( $options );

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );
		$posts  = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'date_query'     => array(
				array(
					'after'     => $cutoff,
					'inclusive' => true,
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		// Google News requires a non-empty publication name. Fall back to the site host when the
		// blogname is empty so <news:name> is never emitted blank.
		$pub_name = trim( (string) get_bloginfo( 'name' ) );
		if ( '' === $pub_name ) {
			$pub_name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$lang     = substr( get_bloginfo( 'language' ), 0, 2 ) ?: 'en';

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $posts as $post ) {
			if ( in_array( $post->post_type, $exclude_types, true ) ) {
				continue;
			}
			// Respect noindex / password / per-post exclusions so a recent noindex post
			// isn't leaked to Google News (matches the main sitemap's exclusion logic).
			if ( self::is_post_excluded_from_sitemap( $post, $options ) ) {
				continue;
			}
			$url = get_permalink( $post );
			if ( ! $url ) {
				continue;
			}
			$pub_date = $post->post_date_gmt ? self::gmt_to_iso( $post->post_date_gmt ) : gmdate( 'c' );
			echo '<url>';
			echo '<loc>' . esc_url( $url ) . '</loc>';
			echo '<news:news>';
			echo '<news:publication>';
			echo '<news:name>' . esc_html( $pub_name ) . '</news:name>';
			echo '<news:language>' . esc_html( $lang ) . '</news:language>';
			echo '</news:publication>';
			echo '<news:publication_date>' . esc_html( $pub_date ) . '</news:publication_date>';
			echo '<news:title>' . esc_html( $post->post_title ) . '</news:title>';
			echo '</news:news>';
			echo '</url>' . "\n";
		}

		echo '</urlset>';
	}

	/**
	 * Output HTML sitemap page (human-readable).
	 *
	 * @param array $options SEO options.
	 */
	public static function output_html_sitemap( $options ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		// Cache the rendered page (text/html can't use emit_cached, which forces XML) with the same
		// content-aware key so the term/post queries below run only on a cache miss.
		$ttl = (int) apply_filters( 'nexter_content_seo_sitemap_cache_ttl', 12 * HOUR_IN_SECONDS );
		$key = 'nxt_sm_' . md5( 'html|' . self::cache_version() . '|' . self::options_fingerprint( $options ) );
		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built escaped HTML.
				return;
			}
		}
		ob_start();
		self::render_html_sitemap_body( $options );
		$html = (string) ob_get_clean();
		if ( $ttl > 0 ) {
			set_transient( $key, $html, $ttl );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built escaped HTML.
	}

	/**
	 * Render the human-readable HTML sitemap body (no headers — captured by output_html_sitemap()).
	 *
	 * @param array $options SEO options.
	 */
	private static function render_html_sitemap_body( $options ) {
		$exclude_types = self::excluded_post_types( $options );
		$exclude_tax = isset( $options['sitemap_exclude_taxonomies'] ) && is_array( $options['sitemap_exclude_taxonomies'] )
			? array_keys( array_filter( $options['sitemap_exclude_taxonomies'] ) )
			: array();

		$site_name = get_bloginfo( 'name' );
		$home_url  = home_url( '/' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php /* translators: %s: site name */ ?>
	<title><?php echo esc_html( sprintf( /* translators: %s: site name (blog title). */ __( 'Sitemap - %s', 'nexter-extension' ), $site_name ) ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; line-height: 1.6; color: #1a1a1a; }
		h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
		h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; color: #333; }
		ul { list-style: none; margin: 0; padding: 0; }
		li { margin: 0.25rem 0; }
		a { color: #1717cc; text-decoration: none; }
		a:hover { text-decoration: underline; }
		.sitemap-meta { font-size: 0.875rem; color: #666; margin-bottom: 1.5rem; }
	</style>
</head>
<body>
	<h1><?php echo esc_html( sprintf( /* translators: %s: site name (blog title). */ __( 'Sitemap - %s', 'nexter-extension' ), $site_name ) ); ?></h1>
	<p class="sitemap-meta"><?php echo esc_html( __( 'A human-readable list of pages on this site.', 'nexter-extension' ) ); ?></p>

	<h2><?php echo esc_html( __( 'Homepage', 'nexter-extension' ) ); ?></h2>
	<ul>
		<li><a href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html( $site_name ); ?></a></li>
	</ul>

	<?php
		$builtin = array( 'page' => get_post_type_object( 'page' ), 'post' => get_post_type_object( 'post' ) );
		$custom  = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$post_types = array_filter( array_merge( $builtin, $custom ) );
		foreach ( $post_types as $pt ) {
			if ( ! $pt || in_array( $pt->name, $exclude_types, true ) ) {
				continue;
			}
			$posts = get_posts( array(
				'post_type'      => $pt->name,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			if ( empty( $posts ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $pt->labels->name ) . '</h2>';
			echo '<ul>';
			foreach ( $posts as $p ) {
				$url = get_permalink( $p );
				if ( $url ) {
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p->post_title ) . '</a></li>';
				}
			}
			echo '</ul>';
		}
	?>

	<?php
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		foreach ( $taxonomies as $tax ) {
			if ( in_array( $tax->name, $exclude_tax, true ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $tax->labels->name ) . '</h2>';
			echo '<ul>';
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( $url && ! is_wp_error( $url ) ) {
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a></li>';
				}
			}
			echo '</ul>';
		}
	?>
</body>
</html>
		<?php
	}

	/**
	 * Render just the sitemap link sections (homepage, post types, taxonomies) — no page chrome.
	 * Shared by the full HTML sitemap page and the [nexter_seo_sitemap] shortcode.
	 *
	 * @param array $options SEO options.
	 */
	private static function render_html_sitemap_sections( $options ) {
		$exclude_types = self::excluded_post_types( $options );
		$exclude_tax = isset( $options['sitemap_exclude_taxonomies'] ) && is_array( $options['sitemap_exclude_taxonomies'] )
			? array_keys( array_filter( $options['sitemap_exclude_taxonomies'] ) )
			: array();

		$site_name = get_bloginfo( 'name' );

		echo '<h2>' . esc_html__( 'Homepage', 'nexter-extension' ) . '</h2>';
		echo '<ul><li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $site_name ) . '</a></li></ul>';

		$builtin    = array( 'page' => get_post_type_object( 'page' ), 'post' => get_post_type_object( 'post' ) );
		$custom     = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$post_types = array_filter( array_merge( $builtin, $custom ) );
		foreach ( $post_types as $pt ) {
			if ( ! $pt || in_array( $pt->name, $exclude_types, true ) ) {
				continue;
			}
			$posts = get_posts( array(
				'post_type'      => $pt->name,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			if ( empty( $posts ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $pt->labels->name ) . '</h2><ul>';
			foreach ( $posts as $p ) {
				$url = get_permalink( $p );
				if ( $url ) {
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p->post_title ) . '</a></li>';
				}
			}
			echo '</ul>';
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		foreach ( $taxonomies as $tax ) {
			if ( in_array( $tax->name, $exclude_tax, true ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $tax->labels->name ) . '</h2><ul>';
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( $url && ! is_wp_error( $url ) ) {
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a></li>';
				}
			}
			echo '</ul>';
		}
	}

	/**
	 * `[nexter_seo_sitemap]` shortcode — embeds the human-readable sitemap list inside a page/post
	 * (no page chrome; inherits the theme's styles). Wrapper class lets themes target it.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public static function render_html_sitemap_shortcode( $atts = array() ) {
		unset( $atts );
		$options = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		ob_start();
		echo '<div class="nxt-seo-html-sitemap">';
		self::render_html_sitemap_sections( is_array( $options ) ? $options : array() );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Get video embeds from a post (YouTube, Vimeo).
	 *
	 * @param WP_Post $post Post object.
	 * @return array Array of video data: thumbnail_loc, title, description, player_loc.
	 */
	private static function get_post_videos( $post ) {
		$videos = array();
		$content = $post->post_content . ( $post->post_excerpt ? "\n" . $post->post_excerpt : '' );

		// YouTube: youtube.com/watch?v=ID, youtu.be/ID, youtube.com/embed/ID
		if ( preg_match_all( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $content, $yt_matches ) ) {
			$seen = array();
			foreach ( $yt_matches[1] as $vid ) {
				if ( isset( $seen[ $vid ] ) ) {
					continue;
				}
				$seen[ $vid ] = true;
				$desc = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 50 );
				$videos[] = array(
					'thumbnail_loc' => 'https://img.youtube.com/vi/' . $vid . '/hqdefault.jpg',
					'title'        => $post->post_title,
					'description'  => $desc ?: $post->post_title,
					'player_loc'   => 'https://www.youtube.com/embed/' . $vid,
					'publication_date' => $post->post_date_gmt ? self::gmt_to_iso( $post->post_date_gmt ) : '',
				);
			}
		}

		// Vimeo: vimeo.com/ID, player.vimeo.com/video/ID
		if ( preg_match_all( '#(?:vimeo\.com/|player\.vimeo\.com/video/)(\d+)#', $content, $vm_matches ) ) {
			$seen = array();
			foreach ( $vm_matches[1] as $vid ) {
				if ( isset( $seen[ $vid ] ) ) {
					continue;
				}
				$seen[ $vid ] = true;
				$thumb = self::get_vimeo_thumbnail( $vid );
				$desc = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 50 );
				$videos[] = array(
					'thumbnail_loc' => $thumb ?: includes_url( 'images/blank.gif' ),
					'title'        => $post->post_title,
					'description'  => $desc ?: $post->post_title,
					'player_loc'   => 'https://player.vimeo.com/video/' . $vid,
					'publication_date' => $post->post_date_gmt ? self::gmt_to_iso( $post->post_date_gmt ) : '',
				);
			}
		}

		return array_slice( $videos, 0, 1000 );
	}

	/**
	 * Fetch Vimeo thumbnail via oEmbed (cached).
	 *
	 * @param string $vimeo_id Vimeo video ID.
	 * @return string Thumbnail URL or empty.
	 */
	private static function get_vimeo_thumbnail( $vimeo_id ) {
		$cache_key = 'nxt_vimeo_thumb_' . $vimeo_id;
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}
		if ( ! wp_next_scheduled( 'nxt_seo_warm_vimeo_thumb', array( $vimeo_id ) ) ) {
			wp_schedule_single_event( time() + 10, 'nxt_seo_warm_vimeo_thumb', array( $vimeo_id ) );
		}
		return '';
	}

	/**
	 * Async Vimeo thumbnail warmup callback.
	 *
	 * @param string $vimeo_id Vimeo video ID.
	 * @return void
	 */
	public static function warm_vimeo_thumbnail( $vimeo_id ) {
		$vimeo_id  = preg_replace( '/\D+/', '', (string) $vimeo_id );
		if ( '' === $vimeo_id ) {
			return;
		}
		$cache_key = 'nxt_vimeo_thumb_' . $vimeo_id;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return;
		}
		$url = 'https://vimeo.com/api/oembed.json?url=https://vimeo.com/' . $vimeo_id;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 3,
				'sslverify' => true,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $cache_key, '', DAY_IN_SECONDS );
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$thumb = isset( $body['thumbnail_url'] ) ? $body['thumbnail_url'] : '';
		set_transient( $cache_key, $thumb, DAY_IN_SECONDS );
	}

	/**
	 * Fetch post IDs likely to contain video embeds.
	 *
	 * @param string $post_type Post type.
	 * @param int    $page      1-based page.
	 * @param int    $limit     Batch size.
	 * @return int[]
	 */
	private static function get_video_candidate_post_ids( $post_type, $page, $limit ) {
		global $wpdb;
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $limit );
		$like_yt = '%' . $wpdb->esc_like( 'youtube' ) . '%';
		$like_yt_short = '%' . $wpdb->esc_like( 'youtu.be' ) . '%';
		$like_vm = '%' . $wpdb->esc_like( 'vimeo' ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
					AND post_status = 'publish'
					AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
				ORDER BY post_modified_gmt DESC
				LIMIT %d OFFSET %d",
				(string) $post_type,
				$like_yt,
				$like_yt_short,
				$like_vm,
				(int) $limit,
				(int) $offset
			)
		);
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * Echo a single video:video entry.
	 *
	 * @param array   $video Video data.
	 * @param WP_Post $post  Post object.
	 */
	private static function echo_video_entry( $video, $post ) {
		$thumb = ! empty( $video['thumbnail_loc'] ) ? $video['thumbnail_loc'] : '';
		if ( ! $thumb ) {
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id ) {
				$thumb = wp_get_attachment_image_url( $thumb_id, 'medium' );
			}
		}
		if ( ! $thumb ) {
			$thumb = includes_url( 'images/blank.gif' );
		}
		$desc = isset( $video['description'] ) ? substr( $video['description'], 0, 2048 ) : $video['title'];
		echo '<video:video>';
		echo '<video:thumbnail_loc>' . esc_url( $thumb ) . '</video:thumbnail_loc>';
		echo '<video:title>' . esc_html( $video['title'] ) . '</video:title>';
		echo '<video:description>' . esc_html( $desc ) . '</video:description>';
		echo '<video:player_loc>' . esc_url( $video['player_loc'] ) . '</video:player_loc>';
		if ( ! empty( $video['publication_date'] ) ) {
			echo '<video:publication_date>' . esc_html( $video['publication_date'] ) . '</video:publication_date>';
		}
		echo '</video:video>';
	}

	/**
	 * Output a single URL entry with optional images and hreflang alternates.
	 *
	 * @param string $url        URL.
	 * @param string $lastmod    Last modified date (ISO 8601).
	 * @param string $changefreq Change frequency.
	 * @param string $priority   Priority.
	 * @param array  $images     Array of { url, title }.
	 * @param array  $alternates Map of lang_code => url for hreflang.
	 */
	private static function echo_url_entry( $url, $lastmod, $changefreq, $priority, $images = array(), $alternates = array() ) {
		echo '<url>';
		echo '<loc>' . esc_url( $url ) . '</loc>';
		echo '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
		echo '<changefreq>' . esc_html( $changefreq ) . '</changefreq>';
		echo '<priority>' . esc_html( $priority ) . '</priority>';
		foreach ( $alternates as $lang => $alt_url ) {
			if ( $alt_url ) {
				echo '<xhtml:link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $alt_url ) . '" />';
			}
		}
		foreach ( $images as $img ) {
			echo '<image:image><image:loc>' . esc_url( $img['url'] ) . '</image:loc>';
			if ( ! empty( $img['title'] ) ) {
				echo '<image:title>' . esc_html( $img['title'] ) . '</image:title>';
			}
			echo '</image:image>';
		}
		echo '</url>' . "\n";
	}

	/**
	 * Check if a multilingual plugin (WPML or Polylang) is active.
	 *
	 * @return bool
	 */
	private static function is_multilingual() {
		return self::is_wpml_active() || self::is_polylang_active();
	}

	/**
	 * Check if WPML is active.
	 *
	 * @return bool
	 */
	private static function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_get_languages' );
	}

	/**
	 * Check if Polylang is active.
	 *
	 * @return bool
	 */
	private static function is_polylang_active() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post' );
	}

	/**
	 * Get alternate URLs for homepage (all languages).
	 *
	 * @return array Lang code => URL.
	 */
	private static function get_home_alternate_urls() {
		$alternates = array();
		if ( self::is_wpml_active() ) {
			$languages = apply_filters( 'wpml_active_languages', array() );
			foreach ( $languages as $lang => $data ) {
				$url = apply_filters( 'wpml_home_url', home_url( '/' ), $lang );
				if ( $url ) {
					$alternates[ $lang ] = $url;
				}
			}
		} elseif ( self::is_polylang_active() && function_exists( 'pll_home_url' ) ) {
			$langs = pll_languages_list();
			foreach ( $langs as $lang ) {
				$url = pll_home_url( $lang );
				if ( $url ) {
					$alternates[ $lang ] = $url;
				}
			}
		}
		return $alternates;
	}

	/**
	 * Get alternate URLs for a post (all language versions).
	 *
	 * @param int $post_id Post ID.
	 * @return array Lang code => URL.
	 */
	private static function get_post_alternate_urls( $post_id ) {
		$alternates = array();
		if ( self::is_wpml_active() ) {
			$type = apply_filters( 'wpml_element_type', get_post_type( $post_id ) );
			$trid = apply_filters( 'wpml_element_trid', false, $post_id, $type );
			if ( ! $trid ) {
				return $alternates;
			}
			$translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $type );
			foreach ( $translations as $lang => $trans ) {
				if ( ! empty( $trans->element_id ) && $trans->element_id ) {
					$url = get_permalink( $trans->element_id );
					if ( $url ) {
						$alternates[ $lang ] = $url;
					}
				}
			}
		} elseif ( self::is_polylang_active() ) {
			$langs = pll_languages_list();
			foreach ( $langs as $lang ) {
				$trans_id = pll_get_post( $post_id, $lang );
				if ( $trans_id && $trans_id > 0 ) {
					$url = get_permalink( $trans_id );
					if ( $url ) {
						$alternates[ $lang ] = $url;
					}
				}
			}
		}
		return $alternates;
	}

	/**
	 * Get alternate URLs for a term (all language versions).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Lang code => URL.
	 */
	private static function get_term_alternate_urls( $term_id, $taxonomy ) {
		$alternates = array();
		if ( self::is_wpml_active() ) {
			$type = apply_filters( 'wpml_element_type', $taxonomy );
			$trid = apply_filters( 'wpml_element_trid', false, $term_id, $type );
			if ( ! $trid ) {
				return $alternates;
			}
			$translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $type );
			foreach ( $translations as $lang => $trans ) {
				if ( ! empty( $trans->element_id ) && $trans->element_id ) {
					$term = get_term( $trans->element_id, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$url = get_term_link( $term );
						if ( $url && ! is_wp_error( $url ) ) {
							$alternates[ $lang ] = $url;
						}
					}
				}
			}
		} elseif ( self::is_polylang_active() && function_exists( 'pll_get_term' ) ) {
			$langs = pll_languages_list();
			foreach ( $langs as $lang ) {
				$trans_id = pll_get_term( $term_id, $lang );
				if ( $trans_id && $trans_id > 0 ) {
					$term = get_term( $trans_id, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$url = get_term_link( $term );
						if ( $url && ! is_wp_error( $url ) ) {
							$alternates[ $lang ] = $url;
						}
					}
				}
			}
		}
		return $alternates;
	}

	/**
	 * Get images from post content for sitemap.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of { url, title }.
	 */
	private static function get_post_images( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $post ) {
			return array();
		}
		$images = array();
		$seen  = array();

		// Featured image first.
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$thumb_url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $thumb_url && ! isset( $seen[ $thumb_url ] ) ) {
				$seen[ $thumb_url ] = true;
				$images[] = array( 'url' => $thumb_url, 'title' => get_the_title( $thumb_id ) );
			}
		}

		// Images from content.
		if ( $post && ! empty( $post->post_content ) && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$src = trim( $src );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
					// Inline data: URIs aren't crawlable image locations — skip them.
					continue;
				}
				if ( preg_match( '#^https?://#i', $src ) ) {
					$url = $src;
				} elseif ( 0 === strpos( $src, '//' ) ) {
					// Protocol-relative (e.g. //cdn.example.com/x.jpg): adopt the site scheme.
					$url = ( is_ssl() ? 'https:' : 'http:' ) . $src;
				} else {
					// Root- or path-relative: resolve against the site root.
					$url = home_url( '/' . ltrim( $src, '/' ) );
				}
				if ( ! isset( $seen[ $url ] ) ) {
					$seen[ $url ] = true;
					$images[] = array( 'url' => $url, 'title' => '' );
				}
			}
		}

		return array_slice( $images, 0, 1000 );
	}

	/**
	 * Resolve best-effort last modified timestamp for a term archive.
	 *
	 * Uses latest modified published post in this term.
	 *
	 * @param WP_Term $term Term object.
	 * @return string ISO 8601 date.
	 */
	private static function get_term_lastmod_iso( $term ) {
		if ( ! $term || ! isset( $term->taxonomy, $term->term_id ) ) {
			return gmdate( 'c' );
		}

		$q = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => (string) $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => array( (int) $term->term_id ),
					),
				),
			)
		);

		if ( ! empty( $q->posts ) ) {
			$post_id = (int) $q->posts[0];
			$gmt     = get_post_field( 'post_modified_gmt', $post_id );
			$iso = self::gmt_to_iso( $gmt );
			if ( '' !== $iso ) {
				return $iso;
			}
		}

		return gmdate( 'c' );
	}

	/**
	 * Flush buffered output while streaming sitemap response.
	 *
	 * Helps lower peak memory for large sitemap responses.
	 */
	private static function maybe_flush_sitemap_output() {
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Determine whether a post should be excluded from sitemap output.
	 *
	 * Excludes posts flagged as noindex via per-post meta or global post-type settings.
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $options SEO options.
	 * @return bool
	 */
	private static function is_post_excluded_from_sitemap( $post, $options ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $post || empty( $post->ID ) ) {
			return false;
		}

		$post_id = (int) $post->ID;

		// Password-protected posts must never be advertised to crawlers — their content is
		// gated, so exposing the URL in the sitemap leaks otherwise-hidden pages.
		if ( '' !== (string) $post->post_password ) {
			return true;
		}

		// Static front page is already emitted explicitly at the top of the
		// sitemap — exclude its standalone entry here so the homepage URL
		// isn't duplicated with a lower priority (1.0 + 0.8).
		if ( $post_id === (int) get_option( 'page_on_front' ) ) {
			return true;
		}

		// WooCommerce transactional pages (cart, checkout, my-account, terms,
		// etc.) should never be in the sitemap — matches the robots noindex.
		if ( self::is_wc_transactional_page_id( $post_id ) ) {
			return true;
		}

		$meta_key = class_exists( 'Nexter_Content_SEO_Robots' )
			? Nexter_Content_SEO_Robots::META_NOINDEX
			: '_nxt_seo_noindex';
		$meta_val = get_post_meta( $post_id, $meta_key, true );

		// Mirror robots behavior: explicit post meta wins over global defaults.
		if ( $meta_val === '1' || $meta_val === true || $meta_val === 1 ) {
			return true;
		}
		if ( $meta_val === '0' || $meta_val === false || $meta_val === 0 ) {
			return false;
		}

		return ! empty( $options['noindex_post_types'][ $post->post_type ] );
	}

	/**
	 * Check whether a given post ID is a WooCommerce transactional page.
	 * Safe when WC is not installed — uses only `get_option` lookups for the
	 * WC-defined page IDs, no hard dependency on WC functions.
	 *
	 * @param int $post_id Post ID to check.
	 * @return bool
	 */
	private static function is_wc_transactional_page_id( $post_id ) {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array_filter( array_map( 'absint', array(
				(int) get_option( 'woocommerce_cart_page_id' ),
				(int) get_option( 'woocommerce_checkout_page_id' ),
				(int) get_option( 'woocommerce_myaccount_page_id' ),
				(int) get_option( 'woocommerce_terms_page_id' ),
				(int) get_option( 'woocommerce_pay_page_id' ),
				(int) get_option( 'woocommerce_thanks_page_id' ),
				(int) get_option( 'woocommerce_view_order_page_id' ),
				(int) get_option( 'woocommerce_edit_address_page_id' ),
				(int) get_option( 'woocommerce_lost_password_page_id' ),
			) ) );
		}
		return in_array( (int) $post_id, $ids, true );
	}

	/**
	 * Get main XML sitemap URL.
	 *
	 * @return string
	 */
	public static function get_sitemap_url() {
		return home_url( '/' . self::REWRITE_SLUG );
	}

	/**
	 * Get video sitemap URL.
	 *
	 * @return string
	 */
	public static function get_video_sitemap_url() {
		return home_url( '/' . self::VIDEO_SLUG );
	}

	/**
	 * Get news sitemap URL.
	 *
	 * @return string
	 */
	public static function get_news_sitemap_url() {
		return home_url( '/' . self::NEWS_SLUG );
	}

	/**
	 * Get HTML sitemap URL.
	 *
	 * @return string
	 */
	public static function get_html_sitemap_url() {
		return home_url( '/' . self::HTML_SLUG );
	}

	/**
	 * REST: GET sitemap config (from main options).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_get_sitemap( $request ) {
		$options = Nexter_Content_SEO::get_options();
		$sitemap = array(
			'enable_xml_sitemap'         => ! empty( $options['enable_xml_sitemap'] ),
			'sitemap_include_images'     => ! empty( $options['sitemap_include_images'] ),
			'enable_video_sitemap'       => ! empty( $options['enable_video_sitemap'] ),
			'enable_html_sitemap'        => ! empty( $options['enable_html_sitemap'] ),
			'enable_news_sitemap'        => ! empty( $options['enable_news_sitemap'] ),
			'sitemap_exclude_post_types' => isset( $options['sitemap_exclude_post_types'] ) ? $options['sitemap_exclude_post_types'] : array(),
			'sitemap_exclude_taxonomies' => isset( $options['sitemap_exclude_taxonomies'] ) ? $options['sitemap_exclude_taxonomies'] : array(),
			'sitemap_url'                => self::get_sitemap_url(),
			'sitemap_video_url'          => self::get_video_sitemap_url(),
			'sitemap_news_url'           => self::get_news_sitemap_url(),
			'sitemap_html_url'           => self::get_html_sitemap_url(),
		);
		return rest_ensure_response( array( 'data' => $sitemap ) );
	}

	/**
	 * REST: POST regenerate sitemap (flush rewrite rules).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_regenerate_sitemap( $request ) {
		flush_rewrite_rules();
		return rest_ensure_response( array(
			'data' => array(
				'success'     => true,
				'sitemap_url' => self::get_sitemap_url(),
			),
		) );
	}
}
