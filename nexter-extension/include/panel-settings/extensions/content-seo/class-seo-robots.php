<?php
/**
 * Content SEO – Robots meta (noindex, nofollow, noarchive) output.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Robots
 */
class Nexter_Content_SEO_Robots {

	/** Matches Nexter_Content_SeoRank::META_NOINDEX / NOFOLLOW / NOARCHIVE (per-post robots). */
	const META_NOINDEX   = '_nxt_seo_noindex';
	const META_NOFOLLOW  = '_nxt_seo_nofollow';
	const META_NOARCHIVE = '_nxt_seo_noarchive';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// The robots.txt editor is a standalone feature and stays active regardless of other
		// SEO plugins. The <meta name="robots"> tag, however, is deferred to a competing SEO
		// plugin when one is present so the page never carries two robots meta tags.
		$defer_meta = class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active();

		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 2 );
		if ( ! $defer_meta ) {
			add_filter( 'wp_robots', array( __CLASS__, 'filter_wp_robots' ), 999 );
			// Own the <meta name="robots"> rendering: unhook WP core's wp_robots
			// action and emit a single Nexter-controlled tag. Prevents the duplicate
			// tag scenario where core emits partial directives (e.g. max-image-preview
			// only) and something else emits index/follow separately.
			add_action( 'template_redirect', array( __CLASS__, 'take_over_robots_output' ), 20 );
		}
		// Bulletproof Content-Type header for robots.txt — WP core's do_robots()
		// sets `text/plain` but it can be overridden or skipped when the /robots.txt
		// rewrite rule doesn't short-circuit in WP::main(). We set it at priority 0
		// of `do_robotstxt` action (runs before WP core's output) and also detect
		// robots.txt by REQUEST_URI at `template_redirect` as a second safety net.
		add_action( 'do_robotstxt', array( __CLASS__, 'force_robots_txt_content_type' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_robots_txt_fallback' ), 0 );

		// Warn admins when a physical robots.txt on disk shadows the dynamic one (WordPress never
		// runs the robots_txt filter when a real file exists), silently dropping Nexter's rules
		// and the Sitemap: directive.
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_physical_robots_txt' ) );
		}
	}

	/**
	 * Admin notice: a physical robots.txt is overriding Nexter's dynamic output.
	 */
	public static function maybe_notice_physical_robots_txt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! file_exists( ABSPATH . 'robots.txt' ) ) {
			return;
		}
		// Only nag when Nexter is actually trying to output robots content the file would shadow.
		$options = Nexter_Content_SEO::get_options();
		$wants_output = ! empty( $options['robots_txt_custom'] ) || ! empty( $options['enable_xml_sitemap'] );
		if ( ! $wants_output ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Nexter SEO: a physical robots.txt file exists in your site root (e.g. created by your host/HestiaCP), so WordPress cannot serve a dynamic robots.txt. Your custom robots.txt rules and the Sitemap: directive are being ignored. Remove or edit the physical robots.txt file to let Nexter manage it.', 'nexter-extension' );
		echo '</p></div>';
	}

	/**
	 * Ensure the Content-Type header is `text/plain` on robots.txt responses.
	 *
	 * Fires on `do_robotstxt` action at priority 0 — before WP core's `_do_robots()`
	 * default handler (priority 10) so if any earlier plugin output has not yet
	 * flushed the buffer, the right header is what reaches the client.
	 */
	public static function force_robots_txt_content_type() {
		if ( ! headers_sent() ) {
			// Remove any previously-set Content-Type (WP may have queued text/html).
			header_remove( 'Content-Type' );
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
	}

	/**
	 * Fallback robots.txt handler for environments where WP's `/robots.txt`
	 * rewrite rule isn't short-circuiting to `do_robots()` (e.g. stale rewrite
	 * cache, certain nginx configs, or permalink structure edge cases).
	 *
	 * Detects the URL path explicitly, applies the `robots_txt` filter chain
	 * manually, and outputs the content with the correct `text/plain` header.
	 */
	public static function serve_robots_txt_fallback() {
		if ( is_admin() || is_feed() || is_trackback() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '/robots.txt' !== $path ) {
			return;
		}
		// If WP's do_robots() already fired and exited, we'd never reach here.
		// Build default output, apply filter (picks up custom editor + sitemap line).
		if ( ! get_option( 'blog_public' ) ) {
			$output = "User-agent: *\nDisallow: /\n";
			$public = '0';
		} else {
			$output  = "User-agent: *\n";
			$output .= "Disallow: /wp-admin/\n";
			$output .= "Allow: /wp-admin/admin-ajax.php\n";
			$public  = '1';
		}
		/** This filter is documented in wp-includes/functions.php */
		$filtered = apply_filters( 'robots_txt', $output, $public );
		self::force_robots_txt_content_type();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, no HTML context.
		echo $filtered;
		exit;
	}

	/**
	 * Replace WP core's wp_robots() action with Nexter's own renderer, so there is
	 * exactly one <meta name="robots"> tag and it always contains the merged
	 * directives from the `wp_robots` filter (including core defaults and any
	 * third-party additions).
	 */
	public static function take_over_robots_output() {
		if ( is_admin() || is_feed() || is_trackback() ) {
			return;
		}
		if ( function_exists( 'wp_robots' ) ) {
			remove_action( 'wp_head', 'wp_robots', 1 );
		}
		add_action( 'wp_head', array( __CLASS__, 'print_robots_meta' ), 1 );
	}

	/**
	 * Print a single merged <meta name="robots"> tag.
	 */
	public static function print_robots_meta() {
		/** This filter is documented in wp-includes/robots-template.php */
		$robots = apply_filters( 'wp_robots', array() );
		$robots = array_filter( (array) $robots );
		if ( empty( $robots ) ) {
			return;
		}
		$parts = array();
		foreach ( $robots as $directive => $value ) {
			if ( true === $value ) {
				$parts[] = $directive;
			} elseif ( false !== $value ) {
				$parts[] = $directive . ':' . $value;
			}
		}
		if ( empty( $parts ) ) {
			return;
		}
		echo "<meta name='robots' content='" . esc_attr( implode( ', ', $parts ) ) . "' />\n";
	}

	public static function filter_wp_robots( $robots ) {
		$directives = self::get_robots_directives();

		// Auto-noindex WooCommerce transactional pages (cart, checkout, account,
		// order-received, etc.) — these are shopper-only, contain thin/empty or
		// private data, and should never be indexed. Opt-out via the
		// `nexter_content_seo_noindex_wc_transactional` filter.
		if ( self::is_noindex_wc_transactional_page() ) {
			if ( ! in_array( 'noindex', $directives, true ) ) {
				$directives[] = 'noindex';
			}
			if ( ! in_array( 'nofollow', $directives, true ) ) {
				$directives[] = 'nofollow';
			}
		}

		// Default to 'index' and 'follow' if not already set.
		if ( ! in_array( 'noindex', $directives, true ) ) {
			$directives[] = 'index';
		}
		if ( ! in_array( 'nofollow', $directives, true ) ) {
			$directives[] = 'follow';
		}

		// If blog is discouraged from search engines, force noindex/nofollow.
		if ( ! get_option( 'blog_public' ) ) {
			$directives = array_diff( $directives, array( 'index', 'follow' ) );
			$directives[] = 'noindex';
			$directives[] = 'nofollow';
		}

		foreach ( $directives as $d ) {
			$robots[ $d ] = true;
		}

		// Global Advanced-tab crawl directives (site-wide). `nosnippet` tells engines
		// not to show a text snippet; `noimageindex` prevents images on the page from
		// being indexed. Applied on top of the per-page index/noindex resolution above.
		$options = Nexter_Content_SEO::get_options();
		if ( ! empty( $options['no_snippet'] ) ) {
			$robots['nosnippet'] = true;
		}
		if ( ! empty( $options['no_image_index'] ) ) {
			$robots['noimageindex'] = true;
		}

		// Remove conflicting core directives if noindex is present.
		if ( isset( $robots['noindex'] ) && $robots['noindex'] ) {
			unset( $robots['index'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * Replace virtual robots.txt when a custom ruleset is saved in Content SEO.
	 *
	 * @param string $output Robots.txt body.
	 * @param string $public Public blog flag ('1' or '0').
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		unset( $public );
		$options = Nexter_Content_SEO::get_options();
		$custom  = isset( $options['robots_txt_custom'] ) ? $options['robots_txt_custom'] : '';
		if ( is_string( $custom ) && $custom !== '' ) {
			$custom = str_replace( array( "\r\n", "\r" ), "\n", $custom );

			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $domain ) {
				$custom = str_replace( 'yourdomain.com', $domain, $custom );
			}

			$out = rtrim( $custom ) . "\n";
			// Even in custom mode, append the Sitemap directive so sitemap discovery isn't lost —
			// unless the user already put a Sitemap line in their custom body.
			if ( get_option( 'blog_public' ) && ! empty( $options['enable_xml_sitemap'] )
				&& class_exists( 'Nexter_Content_SEO_Sitemap' ) && false === stripos( $out, 'sitemap:' ) ) {
				$sitemap_url = Nexter_Content_SEO_Sitemap::get_sitemap_url();
				if ( $sitemap_url ) {
					$out .= "\nSitemap: " . $sitemap_url . "\n";
				}
			}
			return $out;
		}
		// No custom file: keep WordPress output and append sitemap when XML sitemap is enabled.
		if ( ! get_option( 'blog_public' ) || empty( $options['enable_xml_sitemap'] ) || ! class_exists( 'Nexter_Content_SEO_Sitemap' ) ) {
			return $output;
		}
		$url = Nexter_Content_SEO_Sitemap::get_sitemap_url();
		if ( ! $url ) {
			return $output;
		}
		$out = is_string( $output ) ? $output : '';
		if ( strpos( $out, $url ) !== false ) {
			return $output;
		}
		return rtrim( $out ) . "\n\nSitemap: " . $url . "\n";
	}

	/**
	 * Placeholder text for the robots.txt editor only (not saved; stored default is empty).
	 *
	 * @return string
	 */
	public static function get_robots_txt_placeholder() {
		if ( ! get_option( 'blog_public' ) ) {
			return "User-agent: *\nDisallow: /";
		}
		$options = Nexter_Content_SEO::get_options();
		$lines   = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
		);
		if ( ! empty( $options['enable_xml_sitemap'] ) && class_exists( 'Nexter_Content_SEO_Sitemap' ) ) {
			$sitemap = Nexter_Content_SEO_Sitemap::get_sitemap_url();
			if ( $sitemap ) {
				$lines[] = '';
				$lines[] = 'Sitemap: ' . $sitemap;
			}
		}
		return implode( "\n", $lines );
	}

	// Function removed in favor of WP Core wp_robots hook.

	/**
	 * Get robots directives for current page.
	 *
	 * @return string[] Array of directive names (noindex, nofollow, noarchive).
	 */
	public static function get_robots_directives() {
		$directives = array();

		// 404 error pages must never be indexed — WordPress core doesn't noindex them, so an
		// unhandled 404 would otherwise inherit the default index,follow.
		if ( is_404() ) {
			return array( 'noindex' );
		}

		// Singular post/page: check post meta first, then global post type setting.
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$post    = get_post( $post_id );
			if ( $post ) {
				$noindex   = self::get_singular_directive( $post_id, self::META_NOINDEX, 'noindex_post_types', $post->post_type );
				$nofollow  = self::get_singular_directive( $post_id, self::META_NOFOLLOW, 'nofollow_post_types', $post->post_type );
				$noarchive = self::get_singular_directive( $post_id, self::META_NOARCHIVE, 'noarchive_post_types', $post->post_type );
				if ( $noindex ) {
					$directives[] = 'noindex';
				}
				if ( $nofollow ) {
					$directives[] = 'nofollow';
				}
				if ( $noarchive ) {
					$directives[] = 'noarchive';
				}
				return $directives;
			}
		}

		// Taxonomy archive: term meta first (matches singular behavior), then global per-taxonomy flags.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && isset( $term->taxonomy ) ) {
				$tid = (int) $term->term_id;
				$tax = (string) $term->taxonomy;
				if ( self::get_term_directive( $tid, $tax, self::META_NOINDEX, 'noindex_taxonomies' ) ) {
					$directives[] = 'noindex';
				}
				if ( self::get_term_directive( $tid, $tax, self::META_NOFOLLOW, 'nofollow_taxonomies' ) ) {
					$directives[] = 'nofollow';
				}
				if ( self::get_term_directive( $tid, $tax, self::META_NOARCHIVE, 'noarchive_taxonomies' ) ) {
					$directives[] = 'noarchive';
				}
				return $directives;
			}
		}

		// Search archive.
		if ( is_search() ) {
			$options = Nexter_Content_SEO::get_options();
			$directives[] = 'noindex'; // Always noindex search results.
			if ( ! empty( $options['nofollow_archives']['search'] ) ) {
				$directives[] = 'nofollow';
			}
			if ( ! empty( $options['noarchive_archives']['search'] ) ) {
				$directives[] = 'noarchive';
			}
			return $directives;
		}

		// Author archive.
		if ( is_author() ) {
			$options = Nexter_Content_SEO::get_options();
			if ( ! empty( $options['noindex_archives']['author'] ) ) {
				$directives[] = 'noindex';
			}
			if ( ! empty( $options['nofollow_archives']['author'] ) ) {
				$directives[] = 'nofollow';
			}
			if ( ! empty( $options['noarchive_archives']['author'] ) ) {
				$directives[] = 'noarchive';
			}
			return $directives;
		}

		// Date archive.
		if ( is_date() ) {
			$options = Nexter_Content_SEO::get_options();
			if ( ! empty( $options['noindex_archives']['date'] ) ) {
				$directives[] = 'noindex';
			}
			if ( ! empty( $options['nofollow_archives']['date'] ) ) {
				$directives[] = 'nofollow';
			}
			if ( ! empty( $options['noarchive_archives']['date'] ) ) {
				$directives[] = 'noarchive';
			}
			return $directives;
		}

		// Blog posts index. `front` when the posts page IS the front page (posts-mode home),
		// `blog` when it's a separate posts page on a static-front-page site. (A *static* front
		// page is a singular page and is handled by the is_singular() branch above.)
		if ( is_home() ) {
			$directives = self::apply_archive_flags( is_front_page() ? 'front' : 'blog', $directives );
			return self::filter_directives( $directives );
		}

		// Custom post-type archive (e.g. /books/). Blanket `post_type_archive` key, then a
		// per-type override key (`post_type_archive_{type}`) so individual CPTs can differ.
		if ( is_post_type_archive() ) {
			$directives = self::apply_archive_flags( 'post_type_archive', $directives );
			$pt = get_query_var( 'post_type' );
			$pt = is_array( $pt ) ? reset( $pt ) : $pt;
			if ( $pt ) {
				$directives = self::apply_archive_flags( 'post_type_archive_' . $pt, $directives );
			}
			return self::filter_directives( $directives );
		}

		return self::filter_directives( $directives );
	}

	/**
	 * Merge the global noindex/nofollow/noarchive archive flags for a given archive key into
	 * the running directive list (deduped).
	 *
	 * @param string   $key        Archive key (search/author/date/front/blog/post_type_archive[_type]).
	 * @param string[] $directives Current directives.
	 * @return string[]
	 */
	private static function apply_archive_flags( $key, $directives ) {
		$options = Nexter_Content_SEO::get_options();
		$map = array(
			'noindex'   => 'noindex_archives',
			'nofollow'  => 'nofollow_archives',
			'noarchive' => 'noarchive_archives',
		);
		foreach ( $map as $directive => $opt_key ) {
			if ( ! empty( $options[ $opt_key ][ $key ] ) && ! in_array( $directive, $directives, true ) ) {
				$directives[] = $directive;
			}
		}
		return $directives;
	}

	/**
	 * Allow programmatic control of the resolved robots directives for any context
	 * (covers cases the settings UI doesn't expose).
	 *
	 * @param string[] $directives Resolved directives.
	 * @return string[]
	 */
	private static function filter_directives( $directives ) {
		$filtered = apply_filters( 'nexter_content_seo_robots_directives', $directives );
		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $directives;
	}

	/**
	 * Get directive for singular post: saved post meta first, then global (homepage flags on front page, else per post type).
	 *
	 * @param int         $post_id       Post ID.
	 * @param string      $meta_key      Post meta key (e.g. self::META_NOINDEX).
	 * @param string      $opt_key       Option key (e.g. noindex_post_types).
	 * @param string      $post_type     Post type slug.
	 * @return bool
	 */
	private static function get_singular_directive( $post_id, $meta_key, $opt_key, $post_type ) {
		$meta_val = get_post_meta( $post_id, $meta_key, true );
		if ( $meta_val === '1' || $meta_val === true || $meta_val === 1 ) {
			return true;
		}
		if ( $meta_val === '0' || $meta_val === false || $meta_val === 0 ) {
			return false;
		}

		$options = Nexter_Content_SEO::get_options();

		return ! empty( $options[ $opt_key ][ $post_type ] );
	}

	/**
	 * Term archive directive: term meta overrides global taxonomy option (same semantics as singular post meta).
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param string $meta_key   Term meta key.
	 * @param string $opt_tax_key Options key, e.g. noindex_taxonomies.
	 * @return bool
	 */
	private static function get_term_directive( $term_id, $taxonomy, $meta_key, $opt_tax_key ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || '' === $taxonomy ) {
			return false;
		}
		$meta_val = get_term_meta( $term_id, $meta_key, true );
		if ( $meta_val === '1' || $meta_val === true || $meta_val === 1 ) {
			return true;
		}
		if ( $meta_val === '0' || $meta_val === false || $meta_val === 0 ) {
			return false;
		}
		$options = Nexter_Content_SEO::get_options();
		return ! empty( $options[ $opt_tax_key ][ $taxonomy ] );
	}

	/**
	 * Whether a taxonomy term's archive should be treated as noindex (robots + sitemap alignment).
	 *
	 * @param WP_Term $term Term.
	 * @return bool
	 */
	public static function is_term_archive_noindex( WP_Term $term ) {
		return self::get_term_directive( (int) $term->term_id, (string) $term->taxonomy, self::META_NOINDEX, 'noindex_taxonomies' );
	}

	/**
	 * Whether the current request is a WooCommerce transactional page that
	 * should be auto-noindex'd (cart, checkout, my-account, order-received).
	 * Safe when WooCommerce is not active — all calls are gated by `function_exists()`.
	 *
	 * @return bool
	 */
	public static function is_noindex_wc_transactional_page() {
		$is_wc_page = false;
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$is_wc_page = true;
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$is_wc_page = true;
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$is_wc_page = true;
		} elseif ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			// Covers /my-account/orders/, /order-received/, /lost-password/, etc.
			$is_wc_page = true;
		}
		/**
		 * Allow disabling the WooCommerce transactional-page auto-noindex.
		 *
		 * @param bool $is_wc_page Whether to apply noindex.
		 */
		return (bool) apply_filters( 'nexter_content_seo_noindex_wc_transactional', $is_wc_page );
	}
}
