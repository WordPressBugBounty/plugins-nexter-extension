<?php
/**
 * Content SEO – frontend meta description output.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Description
 */
class Nexter_Content_SEO_Description {

	/** Matches Nexter_Content_SeoRank::META_DESCRIPTION. */
	const META_DESCRIPTION = '_nxt_seo_description';

	/** @var bool Whether the wp_head description-dedup buffer is active this request. */
	private static $buffering = false;

	/** @var int|null ob nesting level captured when the head buffer started. */
	private static $head_ob_level = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Remove Hello Elementor's description meta tag to prevent a duplicate. Done both now
		// and again at the very start of wp_head (priority 0) so it works regardless of when
		// the theme registered its hook relative to our init.
		remove_action( 'wp_head', 'hello_elementor_add_description_meta_tag' );
		add_action( 'wp_head', array( __CLASS__, 'unhook_theme_description' ), 0 );

		add_action( 'wp_head', array( __CLASS__, 'output_meta_description' ), 1 );

		// Deduplicate <meta name="description"> across the ENTIRE <head>. The known-callbacks
		// unhook above only removes description tags whose emitting callback we recognize; an
		// unknown theme/plugin would still add a second one. Buffering wp_head and stripping every
		// description meta except Nexter's (marked with a sentinel) covers those unknown sources —
		// the "output-buffering dedup" the previous known-list approach couldn't do. Filterable off.
		add_action( 'wp_head', array( __CLASS__, 'start_head_buffer' ), -PHP_INT_MAX );
		add_action( 'wp_head', array( __CLASS__, 'flush_head_buffer' ), PHP_INT_MAX );

		// First-party page-builder description source: Elementor stores widget content as JSON in
		// _elementor_data (post_content is empty/stub), so do_blocks/do_shortcode can't recover it.
		// This handler parses that JSON so Elementor pages get a real, page-specific description
		// instead of falling back to the site tagline. Low priority so a user/third-party handler
		// on the same filter can still override it.
		add_filter( 'nexter_content_seo_builder_description', array( __CLASS__, 'elementor_builder_description' ), 20, 2 );
	}

	/**
	 * Build a description from an Elementor page's stored _elementor_data when no earlier source
	 * (meta/excerpt) supplied one. Parses the JSON and concatenates visible text from text-bearing
	 * widget settings, in document order.
	 *
	 * @param string  $desc Description accumulated so far ('' if none).
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	public static function elementor_builder_description( $desc, $post ) {
		if ( is_string( $desc ) && '' !== trim( $desc ) ) {
			return $desc; // An earlier/other handler already supplied text — respect it.
		}
		if ( ! $post instanceof WP_Post ) {
			return $desc;
		}
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $data ) ) {
			return $desc; // Not an Elementor page.
		}
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) ) {
			return $desc;
		}
		$parts = array();
		self::collect_elementor_text( $data, $parts );
		if ( empty( $parts ) ) {
			return $desc;
		}
		$text = wp_strip_all_tags( implode( ' ', $parts ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return '' !== $text ? $text : $desc;
	}

	/**
	 * Recursively collect visible text from Elementor element settings into $parts (document
	 * order). Reads only known text-bearing keys, so button labels / structural values are skipped.
	 *
	 * @param array               $elements Elementor elements tree.
	 * @param array<int,string> $parts    Accumulator (by reference).
	 * @return void
	 */
	private static function collect_elementor_text( $elements, &$parts ) {
		if ( ! is_array( $elements ) ) {
			return;
		}
		$text_keys = array( 'editor', 'text', 'title', 'title_text', 'description_text', 'description', 'testimonial_content', 'sub_title', 'heading_title' );
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $text_keys as $k ) {
					if ( ! empty( $el['settings'][ $k ] ) && is_string( $el['settings'][ $k ] ) ) {
						$parts[] = $el['settings'][ $k ];
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::collect_elementor_text( $el['elements'], $parts );
			}
		}
	}

	/**
	 * Late removal of known theme description hooks (runs at the top of wp_head).
	 */
	public static function unhook_theme_description() {
		// Remove known theme/plugin description-meta callbacks so the page doesn't end up with two
		// <meta name="description"> tags. WordPress can't enumerate-and-remove callbacks by intent,
		// only by exact name+priority, so this is a known-callbacks list. Themes/plugins not listed
		// can register their callback here to be unhooked (documented limitation).
		$callbacks = apply_filters(
			'nexter_content_seo_theme_description_callbacks',
			array(
				'hello_elementor_add_description_meta_tag' => 1,
			)
		);
		if ( ! is_array( $callbacks ) ) {
			return;
		}
		foreach ( $callbacks as $callback => $priority ) {
			remove_action( 'wp_head', $callback, is_numeric( $priority ) ? (int) $priority : 10 );
		}
	}

	/**
	 * Whether another full SEO plugin is active and already emitting meta tags. When one is,
	 * Nexter defers its description output so the page doesn't get two <meta name="description">.
	 *
	 * @return bool
	 */
	public static function other_seo_plugin_active() {
		$active = (
			defined( 'WPSEO_VERSION' )            // Yoast SEO.
			|| class_exists( 'RankMath', false )  // Rank Math.
			|| function_exists( 'aioseo' )        // All in One SEO.
			|| function_exists( 'seopress_init' ) // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' ) // The SEO Framework (TSF).
			|| defined( 'SLIM_SEO_VER' )          // Slim SEO.
			|| defined( 'SQ_VERSION' )            // Squirrly SEO.
			// NOTE: RankReady (AI/LLM SEO) is intentionally NOT listed. It emits no <title>,
			// meta description, canonical, robots, schema or sitemap, so deferring to it would
			// suppress Nexter's own output and leave the page with no SEO metadata at all. It
			// defined no stable public version constant either — the previous RANK_READY_VERSION
			// guard was dead code (never true). Nexter and RankReady coexist without duplication.
		);

		/**
		 * Filter whether Nexter should defer to another SEO plugin's meta output.
		 *
		 * @param bool $active Whether a competing SEO plugin was detected.
		 */
		return (bool) apply_filters( 'nexter_content_seo_defer_to_other_seo', $active );
	}

	/**
	 * Output frontend meta description for the current query.
	 *
	 * @return void
	 */
	public static function output_meta_description() {
		if ( is_admin() || is_feed() || is_trackback() || is_404() ) {
			return;
		}
		if ( ! apply_filters( 'nexter_content_seo_output_description_meta', true ) ) {
			return;
		}
		
		if ( self::other_seo_plugin_active() ) {
			return;
		}
		$description = self::resolve_meta_description();
		if ( '' === $description ) {
			return;
		}
		// Sentinel so flush_head_buffer() can keep THIS description and drop any others; it is
		// stripped from the final output and only emitted while the dedup buffer is active.
		if ( self::$buffering ) {
			echo '<!--nxt-seo-desc-->';
		}
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	/**
	 * Start buffering <head> so duplicate description metas can be removed before output.
	 *
	 * @return void
	 */
	public static function start_head_buffer() {
		if ( is_admin() ) {
			return;
		}
		if ( ! apply_filters( 'nexter_content_seo_dedupe_head_description', true ) ) {
			return;
		}
		ob_start();
		self::$head_ob_level = ob_get_level();
		self::$buffering     = true;
	}

	/**
	 * Flush the <head> buffer, keeping a single <meta name="description">.
	 *
	 * @return void
	 */
	public static function flush_head_buffer() {
		if ( ! self::$buffering ) {
			return;
		}
		self::$buffering = false;
		// Only unwind OUR buffer. If another plugin left an unbalanced output buffer open on top
		// of ours, ob_get_clean() would capture their content instead — so bail and let PHP flush
		// normally (no dedup this request, but no corruption either).
		if ( null === self::$head_ob_level || ob_get_level() !== self::$head_ob_level ) {
			self::$head_ob_level = null;
			return;
		}
		self::$head_ob_level = null;
		$html                = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Re-emitting the already-built <head> markup verbatim except for removing duplicate description metas.
		echo self::dedupe_description_meta( $html );
	}

	/**
	 * Remove all but one <meta name="description"> from a chunk of head HTML. Keeps the tag marked
	 * with Nexter's sentinel (its own), else the first one, then strips the sentinel.
	 *
	 * @param string $html Captured <head> markup.
	 * @return string
	 */
	private static function dedupe_description_meta( $html ) {
		$marker = '<!--nxt-seo-desc-->';
		if ( ! preg_match_all( '/<meta\b[^>]*\bname=(["\'])description\1[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return str_replace( $marker, '', $html );
		}
		$tags = $m[0];
		if ( count( $tags ) < 2 ) {
			return str_replace( $marker, '', $html ); // 0 or 1 description meta — nothing to dedup.
		}
		// Keep the Nexter meta (immediately preceded by the sentinel); otherwise keep the first.
		$keep     = 0;
		$mark_len = strlen( $marker );
		foreach ( $tags as $i => $t ) {
			$start = (int) $t[1];
			if ( $marker === substr( $html, max( 0, $start - $mark_len ), $mark_len ) ) {
				$keep = $i;
				break;
			}
		}
		// Delete the non-kept tags back-to-front so the earlier match offsets stay valid.
		for ( $i = count( $tags ) - 1; $i >= 0; $i-- ) {
			if ( $i === $keep ) {
				continue;
			}
			$start = (int) $tags[ $i ][1];
			$len   = strlen( $tags[ $i ][0] );
			$html  = substr( $html, 0, $start ) . substr( $html, $start + $len );
		}
		return str_replace( $marker, '', $html );
	}

	/**
	 * Public accessor for the fully-resolved meta description of the current context. Lets other
	 * modules (e.g. social meta og:/twitter: descriptions) share the exact same fallback chain as
	 * the <meta name="description"> tag, so they can't diverge (one present while the other absent).
	 *
	 * @return string
	 */
	public static function get_current_description() {
		return self::resolve_meta_description();
	}

	/**
	 * Strip default placeholder copy that page builders (Elementor et al.) ship in fresh widgets,
	 * so it never leaks into an auto-generated meta/social description (e.g. a homepage built with
	 * Elementor exposing "Add Your Heading Text Here"). Public so the social-meta module shares it.
	 *
	 * @param string $text Raw text (already tag-stripped by the caller when needed).
	 * @return string
	 */
	public static function strip_builder_placeholders( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		$placeholders = array(
			'Add Your Heading Text Here',
			'Add Your Text Here',
			'Click edit button to change this text.',
			'Click edit button to change this text',
			'This is the heading',
			'Type your text here',
			'Insert your content here',
			'Your Content Goes Here',
		);
		$text         = str_ireplace( $placeholders, ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Resolve description with per-object overrides and global template fallbacks.
	 *
	 * @return string
	 */
	private static function resolve_meta_description() {
		$options = Nexter_Content_SEO::get_options();

		// Static front page resolves via the is_singular() branch below using its
		// per-post Nexter SEO description (Edit page → Nexter SEO meta box). A
		// blog index without a static page falls through to the site tagline at
		// the end of this function.
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$meta = get_post_meta( $post->ID, self::META_DESCRIPTION, true );
				$out  = self::resolve_string_with_context( $meta, array( 'post' => $post ) );
				if ( '' !== $out ) {
					return $out;
				}
				// Static front page with no per-post description: prefer the Home Page panel's
				// description before falling back to the page excerpt/content.
				if ( $post->ID === (int) get_option( 'page_on_front' ) ) {
					$home_desc = isset( $options['home_description'] ) ? (string) $options['home_description'] : '';
					$out       = self::resolve_string_with_context( $home_desc, array() );
					if ( '' !== $out ) {
						return $out;
					}
				}
				$tpl = isset( $options['search_description_template'] ) ? $options['search_description_template'] : '%post_excerpt%';
				$out = self::resolve_string_with_context( $tpl, array( 'post' => $post ) );
				if ( '' !== $out ) {
					return $out;
				}
				if ( has_excerpt( $post ) ) {
					$excerpt = self::cleanup_text( self::strip_builder_placeholders( get_the_excerpt( $post ) ) );
					if ( '' !== $excerpt ) {
						return $excerpt;
					}
				}
				// Let a page-builder integration supply description text. Elementor (and similar)
				// render from post meta, not post_content, so post_content is often empty — this
				// filter lets an integration return the rendered text before the raw fallback.
				$builder = self::cleanup_text( self::strip_builder_placeholders( (string) apply_filters( 'nexter_content_seo_builder_description', '', $post ) ) );
				if ( '' !== $builder ) {
					return $builder;
				}
				// Render blocks and shortcodes so Gutenberg-block / shortcode-built pages yield real
				// text — their content is stored in post_content as block/shortcode markup, which
				// wp_strip_all_tags alone would reduce to little or nothing.
				$raw = (string) $post->post_content;
				if ( function_exists( 'has_blocks' ) && has_blocks( $raw ) ) {
					$raw = do_blocks( $raw );
				}
				if ( false !== strpos( $raw, '[' ) ) {
					$raw = do_shortcode( $raw );
				}
				$content = self::strip_builder_placeholders( wp_strip_all_tags( $raw ) );
				$content = self::cleanup_text( wp_trim_words( $content, 30 ) );
				if ( '' !== $content ) {
					return $content;
				}
				// Content was empty/builder-only (e.g. an Elementor page with no post_content and no
				// builder-description filter) — fall through to the site tagline rather than
				// emitting an empty description.
			}
		}

		// Archives (category / tag / custom taxonomy / author / date / post-type) use a dedicated
		// archive description template rather than the post-excerpt template.
		if ( is_archive() || is_post_type_archive() ) {
			$archive_tpl = ( isset( $options['archive_description_template'] ) && '' !== $options['archive_description_template'] )
				? (string) $options['archive_description_template']
				: '%term_description%';

			if ( is_tax() || is_category() || is_tag() ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term ) {
					// Per-term Nexter SEO description wins.
					$meta = get_term_meta( (int) $term->term_id, self::META_DESCRIPTION, true );
					$out  = self::resolve_string_with_context( $meta, array( 'term' => $term ) );
					if ( '' !== $out ) {
						return $out;
					}
					$out = self::resolve_string_with_context( $archive_tpl, array( 'term' => $term ) );
					if ( '' !== $out ) {
						return $out;
					}
					return self::cleanup_text( $term->description );
				}
			}

			// Author / date / post-type archives.
			$out = self::resolve_string_with_context( $archive_tpl, array() );
			if ( '' !== $out ) {
				return $out;
			}
		}

		if ( is_search() ) {
			/* translators: %s: the search query */
			return self::cleanup_text( sprintf( __( 'Search results for "%s"', 'nexter-extension' ), get_search_query() ) );
		}

		// Blog index ("Your latest posts"): no front-page post to attach meta to, so use the
		// Home Page panel description before the site-tagline fallback.
		if ( is_home() || is_front_page() ) {
			$home_desc = isset( $options['home_description'] ) ? (string) $options['home_description'] : '';
			$out       = self::resolve_string_with_context( $home_desc, array() );
			if ( '' !== $out ) {
				return $out;
			}
		}

		return self::cleanup_text( get_bloginfo( 'description' ) );
	}

	/**
	 * Normalize @var tokens, resolve variables, and sanitize result.
	 *
	 * @param mixed $value   Raw template/meta value.
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
		return self::cleanup_text( $resolved );
	}

	/**
	 * Description cleanup for safe one-line output: strip tags, drop page-builder placeholder copy,
	 * collapse whitespace, and truncate to the target length on a word boundary. Public so the
	 * social-meta module can run og:/twitter: descriptions through the exact same cleanup/truncation
	 * as the main <meta name="description"> tag (they must not diverge).
	 *
	 * @param string $text Description value.
	 * @return string
	 */
	public static function cleanup_text( $text ) {
		$text = is_string( $text ) ? wp_strip_all_tags( $text ) : '';
		// Every description path funnels through here, so strip page-builder placeholder copy once
		// (covers the %post_excerpt% template path as well as the raw excerpt/content fallbacks).
		$text = self::strip_builder_placeholders( $text );
		// do_shortcode() only expands REGISTERED shortcodes; an unregistered or late-registered one
		// (e.g. a slider plugin that hooks in after the description is resolved) is left verbatim
		// and would leak "[rev_slider …]" into the meta/og/twitter description. Strip any remaining
		// shortcode-shaped tokens (a bracketed tag that starts with a letter, opening or closing) so
		// raw brackets never reach the description. Numeric refs like "[1]" are intentionally kept.
		$text = preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:[^\]]*)?\]/i', '', (string) $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( '' === $text ) {
			return '';
		}
		// Filterable max length with word-boundary truncation (mirrors the title cap): cut on the
		// last space within the cap instead of mid-word, and let the ceiling be configured/removed.
		// Default aligns with the SEO analyzer's "ideal" target (Nxt_Seo_Analyzer::META_DESC_MAX =
		// 160) so the plugin never emits a description its own SEO Checks panel would flag as too
		// long. Raise via this filter for a higher hard ceiling; set 0 to disable truncation.
		$max = (int) apply_filters( 'nexter_content_seo_max_description_length', 160 );
		if ( $max > 0 ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			if ( $len > $max ) {
				$cut  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
				$sp   = strrpos( $cut, ' ' );
				$text = ( false !== $sp && $sp > 0 ) ? rtrim( substr( $cut, 0, $sp ) ) : rtrim( $cut );
			}
		}
		return $text;
	}
}
