<?php
/**
 * Content SEO – /llms.txt dynamic file.
 *
 * Generates a llms.txt index of important content for LLM/AI crawlers when
 * the "enable_llms_txt" option is on. The llms.txt format:
 *   https://llmstxt.org/ — H1 site name, quote-blockquote description,
 *   then H2 sections listing links.
 *
 * Supports post-type selection, taxonomy-term selection, noindex-aware
 * exclusion, transient caching, and a freshness-window filter.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_LLMs
 */
class Nexter_Content_SEO_LLMs {

	const CACHE_KEY = 'nxt_llms_txt_body';

	/** Option holding the cache-version salt; bumped by clear_cache() to invalidate all variants. */
	const CACHE_VERSION_OPTION = 'nxt_llms_txt_cache_ver';

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Priority 1 so we short-circuit before any other template_redirect output.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_llms_txt' ), 1 );
		// Skip autosaves/revisions so editing a post doesn't rebuild the llms.txt cache each tick.
		add_action( 'save_post', array( __CLASS__, 'maybe_clear_cache_for_post' ) );
		add_action( 'deleted_post', array( __CLASS__, 'maybe_clear_cache_for_post' ) );
		add_action( 'edited_term', array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_term', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Clear the llms.txt cache for a post change, skipping autosaves and revisions.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_clear_cache_for_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id && ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) ) {
			return;
		}
		self::clear_cache();
	}

	/**
	 * Detect /llms.txt requests and output the file. Responds 404 when the
	 * feature is disabled so the URL doesn't serve stale content.
	 */
	public static function maybe_serve_llms_txt() {
		if ( is_admin() || is_feed() || is_trackback() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '/llms.txt' !== $path ) {
			return;
		}

		$options = Nexter_Content_SEO::get_options();
		if ( empty( $options['enable_llms_txt'] ) ) {
			// Feature disabled — return 404 so the URL isn't silently empty.
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "404 Not Found\n";
			exit;
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header_remove( 'Content-Type' );
			header( 'Content-Type: text/plain; charset=utf-8' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, no HTML context.
		echo self::get_body( $options );
		exit;
	}

	/**
	 * Get the llms.txt body, using transient cache when TTL > 0.
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function get_body( array $options ) {
		$ttl = isset( $options['llms_txt_cache_ttl'] ) ? (int) $options['llms_txt_cache_ttl'] : 24;
		$ttl = max( 0, min( 168, $ttl ) );

		// Key the cache on the options that shape the output (picked pages, post types, etc.).
		// Any settings change yields a new key, so selections always reflect immediately even if
		// a stale transient lingers under a persistent object cache.
		$key = self::CACHE_KEY . '_v' . self::cache_version() . '_' . self::cache_fingerprint( $options );

		if ( $ttl > 0 ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$body = self::build_llms_txt( $options );

		if ( $ttl > 0 ) {
			set_transient( $key, $body, $ttl * HOUR_IN_SECONDS );
		}
		return $body;
	}

	/**
	 * Short hash of the options that affect llms.txt output (for the cache key).
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function cache_fingerprint( array $options ) {
		$keys = array(
			'llms_txt_include_homepage',
			'llms_txt_pages',
			'llms_txt_posts_count',
			'llms_txt_post_types',
			'llms_txt_taxonomies',
			'llms_txt_terms_limit',
			'llms_txt_respect_noindex',
			'llms_txt_freshness_months',
		);
		$relevant = array();
		foreach ( $keys as $k ) {
			$relevant[ $k ] = isset( $options[ $k ] ) ? $options[ $k ] : null;
		}
		return substr( md5( (string) wp_json_encode( $relevant ) ), 0, 12 );
	}

	/**
	 * Monotonic cache-version salt embedded in the transient key. Bumping it (see clear_cache)
	 * orphans every previously-stored fingerprint variant at once — they expire on their own TTL.
	 *
	 * @return int
	 */
	private static function cache_version() {
		return (int) get_option( self::CACHE_VERSION_OPTION, 0 );
	}

	/**
	 * Invalidate the cached llms.txt body across all fingerprint variants.
	 *
	 * The body is stored under a key that includes both a version salt and an options
	 * fingerprint, so a bare delete_transient( CACHE_KEY ) never matched. Bumping the version
	 * salt invalidates the current entry (and any stale variants) on the next request.
	 */
	public static function clear_cache() {
		update_option( self::CACHE_VERSION_OPTION, self::cache_version() + 1, false );
	}

	/**
	 * Build the llms.txt body.
	 *
	 * @param array $options SEO options.
	 * @return string
	 */
	private static function build_llms_txt( array $options ) {
		$site_name   = (string) get_bloginfo( 'name' );
		$description = (string) get_bloginfo( 'description' );

		$posts_count = isset( $options['llms_txt_posts_count'] )
			? (int) $options['llms_txt_posts_count']
			: 20;
		$posts_count = max( 1, min( 100, $posts_count ) );

		$terms_limit = isset( $options['llms_txt_terms_limit'] )
			? (int) $options['llms_txt_terms_limit']
			: 20;
		$terms_limit = max( 1, min( 100, $terms_limit ) );

		$include_homepage = ! isset( $options['llms_txt_include_homepage'] ) || ! empty( $options['llms_txt_include_homepage'] );
		$respect_noindex  = ! isset( $options['llms_txt_respect_noindex'] ) || ! empty( $options['llms_txt_respect_noindex'] );

		$freshness_months = isset( $options['llms_txt_freshness_months'] ) ? (int) $options['llms_txt_freshness_months'] : 0;
		$freshness_months = max( 0, min( 120, $freshness_months ) );

		$selected_post_types = isset( $options['llms_txt_post_types'] ) && is_array( $options['llms_txt_post_types'] )
			? array_keys( array_filter( $options['llms_txt_post_types'] ) )
			: array( 'post' );
		$selected_taxonomies = isset( $options['llms_txt_taxonomies'] ) && is_array( $options['llms_txt_taxonomies'] )
			? array_keys( array_filter( $options['llms_txt_taxonomies'] ) )
			: array();

		$lines   = array();
		$lines[] = '# ' . $site_name;
		$lines[] = '';
		if ( '' !== $description ) {
			$lines[] = '> ' . $description;
			$lines[] = '';
		}

		// Homepage entry.
		if ( $include_homepage ) {
			$home_title = '' !== $description ? $site_name . ' – ' . $description : $site_name;
			$lines[]    = '## ' . __( 'Homepage', 'nexter-extension' );
			$lines[]    = '';
			$lines[]    = '- [' . self::clean_inline( $home_title ) . '](' . esc_url_raw( home_url( '/' ) ) . ')';
			$lines[]    = '';
		}

		// Admin-selected pages.
		$picked_ids = isset( $options['llms_txt_pages'] ) && is_array( $options['llms_txt_pages'] )
			? array_map( 'absint', $options['llms_txt_pages'] )
			: array();
		$picked_ids = array_values( array_unique( array_filter( $picked_ids ) ) );

		if ( ! empty( $picked_ids ) ) {
			$lines[] = '## ' . __( 'Important Pages', 'nexter-extension' );
			$lines[] = '';
			foreach ( $picked_ids as $pid ) {
				$p = get_post( (int) $pid );
				if ( ! $p || 'publish' !== $p->post_status ) {
					continue;
				}
				// Never advertise password-protected pages to AI crawlers.
				if ( '' !== (string) $p->post_password ) {
					continue;
				}
				// NOTE: "Important Pages" are explicitly chosen by the admin, so they are included
				// even if marked noindex — the deliberate selection overrides the generic noindex
				// filter (which still applies to the auto-listed post-type sections below).
				$url = get_permalink( $p );
				if ( ! $url ) {
					continue;
				}
				$title   = self::clean_inline( $p->post_title );
				$excerpt = self::clean_inline( wp_strip_all_tags( $p->post_excerpt ) );
				$entry   = '- [' . $title . '](' . esc_url_raw( $url ) . ')';
				if ( '' !== $excerpt ) {
					$entry .= ': ' . $excerpt;
				}
				$lines[] = $entry;
			}
			$lines[] = '';
		}

		// One section per selected post type.
		foreach ( $selected_post_types as $pt_slug ) {
			if ( ! post_type_exists( $pt_slug ) ) {
				continue;
			}
			$pt_obj = get_post_type_object( $pt_slug );
			if ( ! $pt_obj ) {
				continue;
			}
			$picked_set = array_flip( $picked_ids );
			$query_args = array(
				'post_type'      => $pt_slug,
				'post_status'    => 'publish',
				'posts_per_page' => $posts_count,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'has_password'   => false,
				'post__not_in'   => array_keys( $picked_set ),
			);
			if ( $freshness_months > 0 ) {
				$query_args['date_query'] = array(
					array(
						'after'     => '-' . $freshness_months . ' months',
						'inclusive' => true,
					),
				);
			}
			$posts = get_posts( $query_args );
			if ( empty( $posts ) ) {
				continue;
			}
			$entries = array();
			foreach ( $posts as $p ) {
				if ( '' !== (string) $p->post_password ) {
					continue; // Password-protected — keep out of llms.txt.
				}
				if ( $respect_noindex && self::is_post_noindex( $p ) ) {
					continue;
				}
				$url = get_permalink( $p );
				if ( ! $url ) {
					continue;
				}
				$entries[] = '- [' . self::clean_inline( $p->post_title ) . '](' . esc_url_raw( $url ) . ')';
			}
			if ( empty( $entries ) ) {
				continue;
			}
			$label   = $pt_obj->labels->name ?: $pt_obj->label;
			$lines[] = '## ' . self::clean_inline( (string) $label );
			$lines[] = '';
			foreach ( $entries as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		// One section per selected taxonomy.
		foreach ( $selected_taxonomies as $tax_slug ) {
			if ( ! taxonomy_exists( $tax_slug ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $tax_slug );
			if ( ! $tax_obj ) {
				continue;
			}
			$terms = get_terms( array(
				'taxonomy'   => $tax_slug,
				'number'     => $terms_limit,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => true,
			) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$entries = array();
			foreach ( $terms as $term ) {
				if ( ! ( $term instanceof WP_Term ) ) {
					continue;
				}
				if ( $respect_noindex && self::is_term_noindex( $term ) ) {
					continue;
				}
				$term_link = get_term_link( $term );
				if ( is_wp_error( $term_link ) || ! $term_link ) {
					continue;
				}
				$entries[] = '- [' . self::clean_inline( $term->name ) . '](' . esc_url_raw( $term_link ) . ')';
			}
			if ( empty( $entries ) ) {
				continue;
			}
			$label   = $tax_obj->labels->name ?: $tax_obj->label;
			$lines[] = '## ' . self::clean_inline( (string) $label );
			$lines[] = '';
			foreach ( $entries as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		/**
		 * Filter the generated llms.txt body.
		 *
		 * @param string $body    Final llms.txt body.
		 * @param array  $options SEO options.
		 */
		$body = (string) apply_filters( 'nexter_content_seo_llms_txt_body', implode( "\n", $lines ) . "\n", $options );
		return $body;
	}

	/**
	 * Is a post marked noindex? Honors per-post meta, global post-type option,
	 * homepage option (when static front page), and the Discourage-search-engines setting.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private static function is_post_noindex( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( ! get_option( 'blog_public' ) ) {
			return true;
		}
		if ( class_exists( 'Nexter_Content_SEO_Robots' ) ) {
			$meta_key = Nexter_Content_SEO_Robots::META_NOINDEX;
		} else {
			$meta_key = '_nxt_seo_noindex';
		}
		$meta_val = get_post_meta( $post->ID, $meta_key, true );
		if ( '1' === $meta_val || 1 === $meta_val || true === $meta_val ) {
			return true;
		}
		if ( '0' === $meta_val || 0 === $meta_val || false === $meta_val ) {
			return false;
		}
		$options = Nexter_Content_SEO::get_options();
		return ! empty( $options['noindex_post_types'][ $post->post_type ] );
	}

	/**
	 * Is a term marked noindex? Term meta overrides global taxonomy flag.
	 *
	 * @param WP_Term $term Term object.
	 * @return bool
	 */
	private static function is_term_noindex( WP_Term $term ) {
		if ( ! get_option( 'blog_public' ) ) {
			return true;
		}
		if ( class_exists( 'Nexter_Content_SEO_Robots' ) ) {
			$meta_key = Nexter_Content_SEO_Robots::META_NOINDEX;
		} else {
			$meta_key = '_nxt_seo_noindex';
		}
		$meta_val = get_term_meta( $term->term_id, $meta_key, true );
		if ( '1' === $meta_val || 1 === $meta_val || true === $meta_val ) {
			return true;
		}
		if ( '0' === $meta_val || 0 === $meta_val || false === $meta_val ) {
			return false;
		}
		$options = Nexter_Content_SEO::get_options();
		return ! empty( $options['noindex_taxonomies'][ $term->taxonomy ] );
	}

	/**
	 * Trim + collapse whitespace + strip markdown-breaking chars for inline use.
	 *
	 * @param string $s Raw string.
	 * @return string
	 */
	private static function clean_inline( $s ) {
		$s = is_string( $s ) ? trim( $s ) : '';
		$s = preg_replace( '/\s+/', ' ', $s );
		// Backslash-escape square brackets so they cannot break the [label](url) Markdown link
		// syntax. Parentheses are left untouched — swapping them corrupted titles such as
		// "Guide (2024)" into "Guide [2024]".
		$s = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), (string) $s );
		return is_string( $s ) ? $s : '';
	}
}
