<?php
/**
 * Nexter Content SEO – analysis engine (score, keyword density, readability).
 * Nexter Extension analysis engine.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Seo_Analyzer
 */
class Nxt_Seo_Analyzer {

	/** Weights for total score (sum = 100). */
	const W_TITLE_KEYWORD   = 15;
	const W_META_KEYWORD    = 10;
	const W_KEYWORD_DENSITY = 10;
	const W_INTERNAL_LINKS  = 10;
	const W_IMAGE_ALT       = 10;
	const W_HEADING_USAGE   = 15;
	const W_CONTENT_LENGTH  = 10;
	const W_READABILITY     = 10;
	const W_URL_SLUG        = 10;

	const TITLE_MIN     = 50;
	const TITLE_MAX     = 60;
	const META_DESC_MIN = 150;
	const META_DESC_MAX = 160;

	/**
	 * Calculate overall SEO score (0–100).
	 *
	 * @param array $analysis Associative array from analyze_post_content.
	 * @return int
	 */
	public static function calculate_seo_score( $analysis ) {
		if ( ! is_array( $analysis ) ) {
			return 0;
		}
		$s  = 0;
		$s += ( ! empty( $analysis['title_keyword'] ) ? 1 : 0 ) * self::W_TITLE_KEYWORD;
		$s += ( ! empty( $analysis['meta_description_keyword'] ) ? 1 : 0 ) * self::W_META_KEYWORD;
		$s += self::normalize( $analysis['keyword_density'] ?? 0, 0, 3 ) * self::W_KEYWORD_DENSITY;
		$s += self::normalize( $analysis['internal_links_score'] ?? 0, 0, 1 ) * self::W_INTERNAL_LINKS;
		$s += self::normalize( $analysis['image_alt_score'] ?? 0, 0, 1 ) * self::W_IMAGE_ALT;
		$s += self::normalize( $analysis['heading_usage_score'] ?? 0, 0, 1 ) * self::W_HEADING_USAGE;
		$s += self::normalize( $analysis['content_length_score'] ?? 0, 0, 1 ) * self::W_CONTENT_LENGTH;
		$s += self::normalize( ( $analysis['readability_score'] ?? 0 ) / 100, 0, 1 ) * self::W_READABILITY;
		$s += ( ! empty( $analysis['url_slug_ok'] ) ? 1 : 0 ) * self::W_URL_SLUG;

		$score = (int) min( 100, round( $s ) );
		if ( empty( $analysis['focus_keyword_present'] ) ) {
			// Without a focus keyword, the score is intentionally capped (incomplete optimization context).
			$score = min( 40, $score );
		}
		return $score;
	}

	/**
	 * Analyze keyword density (occurrences per 100 words).
	 *
	 * @param string $content Plain text or HTML.
	 * @param string $keyword Focus keyword.
	 * @return float
	 */
	public static function analyze_keyword_density( $content, $keyword ) {
		$text  = wp_strip_all_tags( $content );
		$words = (int) preg_match_all( '/\p{L}[\p{L}\p{M}\p{Nd}\x27-]*/u', (string) $text );
		if ( $words < 1 || empty( trim( $keyword ) ) ) {
			return 0.0;
		}
		$keyword = trim( $keyword );
		// PCRE's \b word boundary is defined in terms of \w, which stays ASCII-only even under the
		// /u modifier, so a non-Latin focus keyword (CJK, Cyrillic, Arabic, …) could score 0.0 even
		// when it is present. Scripts that separate words with spaces (Latin, Cyrillic, Greek,
		// Arabic, …) get Unicode-aware boundary lookarounds so the keyword isn't matched inside a
		// larger word; scripts with no inter-word separators (CJK, Thai, …) have no meaningful word
		// boundary, so count raw occurrences instead — otherwise a present keyword scores 0.
		$has_unspaced_script = (bool) preg_match(
			'/[\x{3000}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}]/u',
			$keyword
		);
		if ( $has_unspaced_script ) {
			$pattern = '/' . preg_quote( $keyword, '/' ) . '/iu';
		} else {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/iu';
		}
		$count = (int) preg_match_all( $pattern, $text );
		return round( ( $count / $words ) * 100, 2 );
	}

	/**
	 * Check if focus keyword appears in title.
	 *
	 * @param string $title   SEO or post title.
	 * @param string $keyword Focus keyword.
	 * @return bool
	 */
	public static function check_title_keyword( $title, $keyword ) {
		if ( empty( $keyword ) || empty( $title ) ) {
			return false;
		}
		return stripos( $title, trim( $keyword ) ) !== false;
	}

	/**
	 * Check if focus keyword appears in meta description.
	 *
	 * @param string $description Meta description.
	 * @param string $keyword    Focus keyword.
	 * @return bool
	 */
	public static function check_meta_description_keyword( $description, $keyword ) {
		if ( empty( $keyword ) || empty( $description ) ) {
			return false;
		}
		return stripos( $description, trim( $keyword ) ) !== false;
	}

	/**
	 * Check image alt tags: ratio of images with alt.
	 *
	 * @param string $content HTML content.
	 * @return float 0–1
	 */
	public static function check_image_alt( $content ) {
		if ( empty( $content ) ) {
			return 0.5;
		}
		preg_match_all( '/<img\s[^>]*>/i', $content, $imgs );
		$total = count( $imgs[0] ?? [] );
		if ( $total === 0 ) {
			return 0.5;
		}
		$with_alt = 0;
		foreach ( (array) $imgs[0] as $img ) {
			if ( preg_match( '/\balt\s*=\s*["\'][^"\']*["\']/', $img ) ) {
				$with_alt++;
			}
		}
		return $with_alt / $total;
	}

	/**
	 * Internal links: count links to same site.
	 *
	 * @param string $content HTML content.
	 * @param string $home    Home URL.
	 * @return array { count, score 0-1 }
	 */
	public static function check_internal_links( $content, $home = '' ) {
		if ( empty( $content ) ) {
			return array(
			'count' => 0,
			'score' => 0.0
			);
		}
		$home = $home ?: home_url( '/' );
		preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
		$urls     = $matches[1] ?? array();
		$internal = 0;
		foreach ( $urls as $url ) {
			if ( strpos( $url, $home ) === 0 || strpos( $url, '/' ) === 0 ) {
				$internal++;
			}
		}
		$score = $internal >= 1 ? min( 1.0, $internal / 3.0 ) : 0.0;
		return array(
		'count' => $internal,
		'score' => $score
		);
	}

	/**
	 * Flesch Reading Ease. 90–100 Very Easy, 60–70 Standard, 30–50 Difficult.
	 *
	 * @param string $text Plain text.
	 * @return int 0–100
	 */
	public static function calculate_readability( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( empty( $text ) ) {
			return 0;
		}
		$words     = (int) preg_match_all( '/\p{L}[\p{L}\p{M}\p{Nd}\x27-]*/u', (string) $text );
		$sentences = max( 1, preg_match_all( '/[.!?]+/', $text ) );
		$syllables = self::count_syllables( $text );
		$asl       = $words / $sentences;
		$asw       = $words > 0 ? $syllables / $words : 0;
		$score     = 206.835 - ( 1.015 * $asl ) - ( 84.6 * $asw );
		return (int) max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Approximate syllable count for English.
	 *
	 * @param string $text
	 * @return int
	 */
	private static function count_syllables( $text ) {
		$words = preg_split( '/\s+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;
		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z]/', '', $word );
			if ( strlen( $word ) <= 3 ) {
				$total += 1;
				continue;
			}
			$word   = preg_replace( '/(?:es|ed|e)$/', '', $word );
			$vowels = preg_match_all( '/[aeiouy]+/', $word );
			$total += max( 1, $vowels );
		}
		return $total;
	}

	/**
	 * Normalize value to 0–1 range.
	 *
	 * @param float $value
	 * @param float $min
	 * @param float $max
	 * @return float
	 */
	private static function normalize( $value, $min, $max ) {
		if ( $max <= $min ) {
			return 0.0;
		}
		$n = ( $value - $min ) / ( $max - $min );
		return max( 0, min( 1, $n ) );
	}

	/**
	 * Multibyte-safe string length for title/description limits.
	 *
	 * @param string $s String.
	 * @return int
	 */
	private static function text_length( $s ) {
		if ( ! is_string( $s ) ) {
			return 0;
		}
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
	}

	/**
	 * @param string $content Post content HTML.
	 * @return bool
	 */
	private static function content_has_media( $content ) {
		return is_string( $content ) && preg_match( '/<(?:img|video)\b/i', $content ) === 1;
	}

	/**
	 * @param string $content Post content HTML.
	 * @return bool
	 */
	private static function content_has_any_link( $content ) {
		return is_string( $content ) && preg_match( '/<a\s[^>]*\bhref\s*=\s*["\'][^"\']+["\']/i', $content ) === 1;
	}

	/**
	 * Subheading: at least one H2–H6.
	 *
	 * @param string $content Post content HTML.
	 * @return bool
	 */
	private static function content_has_subheading( $content ) {
		return is_string( $content ) && preg_match( '/<h[2-6][\s>]/i', $content ) === 1;
	}

	/**
	 * Short slug heuristic for “SEO-friendly” (length only; supports i18n slugs).
	 *
	 * @param string $slug Post slug.
	 * @return bool
	 */
	private static function url_slug_is_short( $slug ) {
		return is_string( $slug ) && $slug !== '' && strlen( $slug ) <= 75;
	}

	/**
	 * Checklist rows for the editor “Page checks” UI and extended SEO rules.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param string               $content   HTML content.
	 * @param string               $title     Resolved SEO title.
	 * @param string               $meta_desc Resolved meta description.
	 * @param string               $focus_kw  Focus keyword.
	 * @param array<string, mixed> $analysis  Metrics from analysis.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_seo_checklist( $post_id, $content, $title, $meta_desc, $focus_kw, $analysis ) {
		$post       = get_post( $post_id );
		$slug       = ( $post && $post->post_name ) ? (string) $post->post_name : '';
		$word_count = isset( $analysis['word_count'] ) ? (int) $analysis['word_count'] : 0;

		$has_media = self::content_has_media( $content );
		$has_link  = self::content_has_any_link( $content );
		$has_sub   = self::content_has_subheading( $content );
		$url_ok    = self::url_slug_is_short( $slug );

		$title_len = self::text_length( (string) $title );
		$title_ok  = $title_len > 0 && $title_len <= self::TITLE_MAX;

		$meta_len = self::text_length( (string) $meta_desc );
		$meta_ok  = $meta_len > 0 && $meta_len <= self::META_DESC_MAX;

		$checklist = array(
			array(
				'id'     => 'page_media',
				'status' => $has_media ? 'pass' : 'warning',
				'text'   => $has_media
					? __( 'This page contains images or videos.', 'nexter-extension' )
					: __( 'No images or videos found on this page.', 'nexter-extension' ),
				'label'  => __( 'Images & video', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
			array(
				'id'     => 'page_links',
				'status' => $has_link ? 'pass' : 'warning',
				'text'   => $has_link
					? __( 'This page contains links.', 'nexter-extension' )
					: __( 'No links found on this page.', 'nexter-extension' ),
				'label'  => __( 'Links', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
			array(
				'id'     => 'page_subheading',
				'status' => $has_sub ? 'pass' : 'warning',
				'text'   => $has_sub
					? __( 'The page contains subheadings.', 'nexter-extension' )
					: __( 'Page does not contain at least one subheading.', 'nexter-extension' ),
				'label'  => __( 'Subheadings', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
			array(
				'id'     => 'page_url',
				'status' => $url_ok ? 'pass' : 'warning',
				'text'   => $url_ok
					? __( 'Page URL is short and SEO-friendly.', 'nexter-extension' )
					: __( 'Page URL is missing, very long, or could be shortened for SEO.', 'nexter-extension' ),
				'label'  => __( 'URL', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
			array(
				'id'     => 'page_title_length',
				'status' => $title_ok ? 'pass' : 'warning',
				'text'   => $title_ok
					? __( 'Search engine title is present and under 60 characters.', 'nexter-extension' )
					: __( 'Search engine title is missing or longer than 60 characters.', 'nexter-extension' ),
				'label'  => __( 'Title length', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
			array(
				'id'     => 'page_meta_length',
				'status' => $meta_ok ? 'pass' : 'warning',
				'text'   => $meta_ok
					? __( 'Search engine description is present and under 160 characters.', 'nexter-extension' )
					: __( 'Search engine description is missing or longer than 160 characters.', 'nexter-extension' ),
				'label'  => __( 'Meta description length', 'nexter-extension' ),
		'fix_section'    => 'general',
			),
		);

		$focus_trim = trim( (string) $focus_kw );
		if ( $focus_trim !== '' ) {
			$title_keyword  = ! empty( $analysis['title_keyword'] );
			$meta_keyword   = ! empty( $analysis['meta_description_keyword'] );
			$keyword_d      = isset( $analysis['keyword_density'] ) ? (float) $analysis['keyword_density'] : 0.0;
			$internal_count = isset( $analysis['internal_links_count'] ) ? (int) $analysis['internal_links_count'] : 0;
			$image_alt      = isset( $analysis['image_alt_score'] ) ? (float) $analysis['image_alt_score'] : 1.0;
			$heading_s      = isset( $analysis['heading_usage_score'] ) ? (float) $analysis['heading_usage_score'] : 0.0;
			$readability    = isset( $analysis['readability_score'] ) ? (int) $analysis['readability_score'] : 0;
			$url_slug_kw    = ! empty( $analysis['url_slug_ok'] );

			$density_status = ( $keyword_d >= 0.5 && $keyword_d <= 2.5 ) ? 'pass' : ( $keyword_d > 0 ? 'warning' : 'error' );
			$image_status   = $image_alt >= 1 ? 'pass' : ( $image_alt > 0 ? 'warning' : 'error' );
			$read_status    = $readability >= 50 ? 'pass' : ( $readability >= 30 ? 'warning' : 'error' );

			$checklist[] = array(
				'id'      => 'focus_title',
				'status'  => $title_keyword ? 'pass' : 'warning',
				'text'    => $title_keyword
					? __( 'Focus keyword appears in the SEO title.', 'nexter-extension' )
					: __( 'Add the focus keyword to the SEO title.', 'nexter-extension' ),
				'label'   => __( 'Title optimization', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'focus_meta',
				'status'  => $meta_keyword ? 'pass' : 'warning',
				'text'    => $meta_keyword
					? __( 'Focus keyword appears in the meta description.', 'nexter-extension' )
					: __( 'Add the focus keyword to the meta description.', 'nexter-extension' ),
				'label'   => __( 'Meta description keyword', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'keyword_density',
				'status'  => $density_status,
				'text'    => sprintf(
					/* translators: %s: keyword density percentage */
					__( 'Keyword density is %s%% (aim for roughly 0.5%%–2.5%%).', 'nexter-extension' ),
					number_format_i18n( $keyword_d, 2 )
				),
				'label'   => __( 'Keyword density', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'internal_links',
				'status'  => $internal_count >= 1 ? 'pass' : 'warning',
				'text'    => $internal_count >= 1
					? sprintf(
						/* translators: %d: internal link count */
						_n(
							'There is %d internal link in the content.',
							'There are %d internal links in the content.',
							$internal_count,
							'nexter-extension'
						),
						$internal_count
					)
					: __( 'Add at least one internal link to other pages on your site.', 'nexter-extension' ),
				'label'   => __( 'Internal links', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'image_alt',
				'status'  => $image_status,
				'text'    => $image_alt >= 1
					? __( 'All images have alt text.', 'nexter-extension' )
					: __( 'Some images are missing alt text.', 'nexter-extension' ),
				'label'   => __( 'Image alt tags', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'headings',
				'status'  => $heading_s > 0 ? 'pass' : 'warning',
				'text'    => $heading_s > 0
					? __( 'The content uses headings.', 'nexter-extension' )
					: __( 'Add headings (H1–H6) to structure the content.', 'nexter-extension' ),
				'label'   => __( 'Heading usage', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'content_length',
				'status'  => $word_count >= 300 ? 'pass' : 'warning',
				'text'    => $word_count >= 300
					? sprintf(
						/* translators: %d: word count */
						__( 'Content length is %d words.', 'nexter-extension' ),
						$word_count
					)
					: sprintf(
						/* translators: %d: current word count */
						__( 'Content is short (%d words). Consider at least 300 words for stronger SEO.', 'nexter-extension' ),
						$word_count
					),
				'label'   => __( 'Content length', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'readability',
				'status'  => $read_status,
				'text'    => sprintf(
					/* translators: %d: readability score */
					__( 'Readability score is %d (higher is easier to read).', 'nexter-extension' ),
					$readability
				),
				'label'   => __( 'Readability', 'nexter-extension' ),
			'fix_section' => 'general',
			);
			$checklist[] = array(
				'id'      => 'url_slug_keyword',
				'status'  => $url_slug_kw ? 'pass' : 'warning',
				'text'    => $url_slug_kw
					? __( 'URL slug looks good for this focus keyword.', 'nexter-extension' )
					: __( 'Consider including the focus keyword in the URL slug.', 'nexter-extension' ),
				'label'   => __( 'URL slug & keyword', 'nexter-extension' ),
			'fix_section' => 'general',
			);
		} else {
			$checklist[] = array(
				'id'      => 'focus_keyword_missing',
				'status'  => 'warning',
				'text'    => __( 'Add a focus keyword on the Optimize tab to run keyword-based checks.', 'nexter-extension' ),
				'label'   => __( 'Focus keyword', 'nexter-extension' ),
			'fix_section' => 'general',
			);
		}

		return $checklist;
	}

	/**
	 * Normalize a focus keyword argument into a list.
	 * Accepts string (single or comma/pipe/newline-separated), array, or null.
	 * First non-empty entry is the primary keyword.
	 *
	 * @param string|array|null $kw Raw input.
	 * @return string[] De-duplicated, trimmed list; primary at index 0.
	 */
	public static function normalize_keywords( $kw ) {
		if ( is_array( $kw ) ) {
			$list = $kw;
		} elseif ( is_string( $kw ) && '' !== $kw ) {
			$list = preg_split( '/[,|\r\n]+/', $kw );
		} else {
			$list = array();
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $k ) {
			$t = trim( (string) $k );
			if ( '' === $t ) {
				continue;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $t;
		}
		return $out;
	}

	/**
	 * Resolve the content to analyze into the markup a visitor actually gets.
	 *
	 * Structural checks (images/video, links, subheadings, headings, word count) run on HTML, but
	 * raw post_content often has none of it: a shortcode-based slider stores its images inside a
	 * `[shortcode]`, and page builders (Elementor, etc.) keep almost nothing in post_content and
	 * generate the real markup at render time. Analyzing the raw content therefore reports "no
	 * images" on an image-full page. Expand shortcodes, and prefer a page builder's rendered
	 * output when the post is built with one, so the analyzer sees the rendered page. Fails safe
	 * to the raw content on any error.
	 *
	 * @param int    $post_id Post ID (0 when analyzing arbitrary/unsaved content, e.g. terms).
	 * @param string $content Raw content HTML.
	 * @return string
	 */
	private static function render_content_for_analysis( $post_id, $content ) {
		$content = is_string( $content ) ? $content : '';
		$post_id = (int) $post_id;

		// Page builder: use the rendered builder output when this post is built with one.
		if ( $post_id > 0 && did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
			try {
				$documents = \Elementor\Plugin::$instance->documents;
				$doc       = $documents ? $documents->get( $post_id ) : null;
				if ( $doc && $doc->is_built_with_elementor() ) {
					$built = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id, false );
					if ( is_string( $built ) && '' !== trim( $built ) ) {
						return $built;
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through to shortcode expansion / raw content.
			}
		}

		// No shortcodes to expand — cheapest path, avoids do_shortcode overhead.
		if ( '' === $content || false === strpos( $content, '[' ) ) {
			return $content;
		}

		// Classic / shortcode content: expand shortcodes ([gallery], sliders, …) so their <img>
		// and <a> markup is present. Set up post context so shortcodes reading the current post
		// resolve, then restore it.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored by wp_reset_postdata() below.
				setup_postdata( $post );
				$rendered = do_shortcode( $content );
				wp_reset_postdata();
				return is_string( $rendered ) && '' !== $rendered ? $rendered : $content;
			}
		}

		$rendered = do_shortcode( $content );
		return is_string( $rendered ) && '' !== $rendered ? $rendered : $content;
	}

	/**
	 * Full analysis for a post (content + meta).
	 *
	 * @param int                $post_id   Post ID.
	 * @param string             $content   Post content HTML.
	 * @param string             $title     SEO or post title.
	 * @param string             $meta_desc Meta description.
	 * @param string|array|null  $focus_kw  Focus keyword (single string) or list of keywords (array, or comma/newline-separated string). First non-empty entry is treated as primary.
	 * @return array
	 */
	public static function analyze_post_content( $post_id, $content, $title, $meta_desc, $focus_kw ) {
		$keywords = self::normalize_keywords( $focus_kw );
		$primary  = isset( $keywords[0] ) ? $keywords[0] : '';

		// Analyze the rendered page (shortcodes expanded / builder output), not raw post_content,
		// so image/link/heading checks match what actually ships to visitors and search engines.
		$content = self::render_content_for_analysis( $post_id, $content );

		$content_plain = wp_strip_all_tags( $content );
		$word_count    = (int) preg_match_all( '/\p{L}[\p{L}\p{M}\p{Nd}\x27-]*/u', (string) $content_plain );

		$keyword_density = $primary !== '' ? self::analyze_keyword_density( $content, $primary ) : 0.0;
		$title_keyword   = $primary !== '' && self::check_title_keyword( $title, $primary );
		$meta_keyword    = $primary !== '' && self::check_meta_description_keyword( $meta_desc, $primary );

		// Per-keyword breakdown (primary + secondary).
		$per_keyword = array();
		foreach ( $keywords as $kw ) {
			$per_keyword[] = array(
				'keyword'  => $kw,
				'density'  => self::analyze_keyword_density( $content, $kw ),
				'in_title' => self::check_title_keyword( $title, $kw ),
				'in_meta'  => self::check_meta_description_keyword( $meta_desc, $kw ),
			);
		}

		$image_alt_score = self::check_image_alt( $content );
		$internal        = self::check_internal_links( $content );
		$readability     = self::calculate_readability( $content_plain );

		$content_length_score = 0;
		if ( $word_count >= 300 ) {
			$content_length_score = min( 1.0, $word_count / 800 );
		}

		$heading_score = 0;
		if ( preg_match_all( '/<h[1-6][^>]*>.*?<\/h[1-6]>/is', $content, $headings ) ) {
			$heading_score = min( 1.0, count( $headings[0] ) / 3 );
		}

		$post = get_post( $post_id );
		$slug = $post && $post->post_name ? $post->post_name : '';
		// Slug passes if the primary keyword (or any secondary) is found in it.
		$slug_has_any_kw = false;
		if ( $slug !== '' ) {
			foreach ( $keywords as $kw ) {
				if ( stripos( $slug, $kw ) !== false ) {
					$slug_has_any_kw = true;
					break;
				}
			}
		}
		$url_slug_ok = ! empty( $slug ) && strlen( $slug ) <= 75 && ( empty( $keywords ) || $slug_has_any_kw );

		$analysis = array(
			'focus_keyword_present'    => $primary !== '',
			'focus_keywords'           => $keywords,
			'per_keyword'              => $per_keyword,
			'title_keyword'            => $title_keyword,
			'meta_description_keyword' => $meta_keyword,
			'keyword_density'          => $keyword_density,
			'internal_links_count'     => $internal['count'],
			'internal_links_score'     => $internal['score'],
			'image_alt_score'          => $image_alt_score,
			'heading_usage_score'      => $heading_score,
			'content_length_score'     => $content_length_score,
			'readability_score'        => $readability,
			'url_slug_ok'              => $url_slug_ok,
			'word_count'               => $word_count,
		);

		$score = self::calculate_seo_score( $analysis );

		$checklist = self::build_seo_checklist( $post_id, $content, $title, $meta_desc, $primary, $analysis );

		$suggestions = array();
		if ( ! $title_keyword && $primary !== '' ) {
			$suggestions[] = __( 'Add focus keyword to title', 'nexter-extension' );
		}
		if ( ! $meta_keyword && $primary !== '' ) {
			$suggestions[] = __( 'Add focus keyword to meta description', 'nexter-extension' );
		}
		// Surface secondary keywords that are not used anywhere.
		foreach ( $per_keyword as $i => $row ) {
			if ( 0 === $i ) {
				continue;
			}
			if ( ! $row['in_title'] && ! $row['in_meta'] && (float) $row['density'] === 0.0 ) {
				$suggestions[] = sprintf(
					/* translators: %s: secondary keyword */
					__( 'Secondary keyword "%s" is not used in the content, title, or description.', 'nexter-extension' ),
					$row['keyword']
				);
			}
		}
		if ( $word_count < 300 ) {
			$suggestions[] = __( 'Increase content length to 300+ words', 'nexter-extension' );
		}
		if ( $internal['count'] < 1 ) {
			$suggestions[] = __( 'Add internal links', 'nexter-extension' );
		}
		if ( $readability < 50 ) {
			$suggestions[] = __( 'Improve readability (shorter sentences, simpler words)', 'nexter-extension' );
		}

		return array(
			'score'           => $score,
			'keyword_density' => $keyword_density,
			'readability'     => $readability,
			'checklist'       => $checklist,
			'suggestions'     => $suggestions,
			'analysis'        => $analysis,
		);
	}
}
