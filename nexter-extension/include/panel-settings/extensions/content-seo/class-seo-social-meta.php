<?php
/**
 * Content SEO – Social meta tags (og:image, twitter:image) output on frontend.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Social_Meta
 */
class Nexter_Content_SEO_Social_Meta {

	/** Matches Nexter_Content_SeoRank::META_FB_IMAGE (per-post Open Graph image). */
	const META_FB_IMAGE = '_nxt_seo_fb_image';

	/** Matches Nexter_Content_SeoRank::META_TW_IMAGE (per-post X / Twitter card image). */
	const META_TW_IMAGE = '_nxt_seo_tw_image';

	/** Matches Nexter_Content_SeoRank::META_FB_TITLE / META_FB_DESC / META_TW_TITLE / META_TW_DESC / META_TITLE / META_DESCRIPTION. */
	const META_FB_TITLE    = '_nxt_seo_fb_title';
	const META_FB_DESC     = '_nxt_seo_fb_desc';
	const META_TW_TITLE    = '_nxt_seo_tw_title';
	const META_TW_DESC     = '_nxt_seo_tw_desc';
	const META_TITLE       = '_nxt_seo_title';
	const META_DESCRIPTION = '_nxt_seo_description';
	/** Matches Nexter_Content_SeoRank Twitter creator meta (singular + term). */
	const META_TW_CREATOR = '_nxt_seo_tw_creator';

	/**
	 * CURLOPT_RESOLVE entries ("host:port:ip") pinned around the OG-image fetch. Populated only
	 * for the duration of the single wp_remote_get() call in remote_image_dimensions().
	 *
	 * @var string[]
	 */
	private static $og_probe_curl_resolve = array();

	/**
	 * Social profile URL keys (matches Social.jsx OTHER_ACCOUNTS).
	 *
	 * @var string[]
	 */
	private static $profile_url_keys = array(
		'facebook_page_url',
		'instagram_url',
		'youtube_url',
		'linkedin_url',
		'tiktok_url',
		'pinterest_url',
		'whatsapp_url',
		'telegram_url',
		'yelp_url',
		'bluesky_url',
		'twitter_site',
	);

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_social_meta' ), 5 );
		// Contribute social-profile URLs to the schema module's single Organization node's sameAs.
		// Registered as a filter (not a wp_head emitter) because Nexter_Content_SEO_Schema::print_schema
		// runs at wp_head priority 1 — a filter added inside a priority-6 action would register too
		// late to be read. This replaces the standalone Organization JSON-LD block this class used to
		// print, which produced a second, @id-less Organization entity uncoordinated with the graph.
		add_filter( 'nexter_content_seo_organization_same_as', array( __CLASS__, 'add_profiles_to_organization_same_as' ) );
	}

	/**
	 * Output og:image and twitter:image meta tags when default social image is set.
	 */
	public static function output_social_meta() {
		if ( ! self::should_output_social_meta() ) {
			return;
		}
		list( $og_image_url, $twitter_image_url ) = self::get_og_and_twitter_image_urls();
		// No early return when both image URLs are empty: title/description-only social output is
		// intentionally still emitted for pages that have no social image.
		if ( empty( $og_image_url ) ) {
			$og_image_url = $twitter_image_url;
		}
		if ( empty( $twitter_image_url ) ) {
			$twitter_image_url = $og_image_url;
		}

		$options     = Nexter_Content_SEO::get_options();
		$card_layout = ! empty( $options['twitter_card_layout'] ) ? $options['twitter_card_layout'] : 'summary_large_image';

		// Home Page panel OG image overrides the default for the homepage / blog index.
		if ( ( is_front_page() || is_home() ) && ! empty( $options['home_og_image'] ) ) {
			$home_image        = esc_url_raw( (string) $options['home_og_image'] );
			$og_image_url      = $home_image;
			$twitter_image_url = $home_image;
		}

		// The admin explicitly chose the card layout in settings, so honor it. Previously a small
		// default image silently downgraded "summary_large_image" to "summary"; that override is
		// auto-downgrade when the image is below Twitter/X's 300x157 minimum (default on; return false from the filter to always honor the chosen layout).
		if ( 'summary_large_image' === $card_layout
			&& apply_filters( 'nexter_content_seo_downgrade_small_twitter_card', true, $twitter_image_url )
			&& ! self::image_supports_large_card( $twitter_image_url ) ) {
			$card_layout = 'summary';
		}

		$url = self::get_social_url();
		// Homepage: when the static front page has explicit Nexter SEO meta set
		// via the per-post meta box, honor it. Otherwise — including when no per-
		// post meta has been entered — emit the same site-level title/description
		// fallback as the <title> tag instead of resolving the global template
		// against the static page (which would leak its post title into og:title).
		$front_id = (int) get_option( 'page_on_front' );
		if ( is_front_page() || is_home() ) {
			// Homepage / blog index. Resolve each of the four social outputs INDEPENDENTLY against
			// the real <title> / <meta description> the page actually emits, overridden only by an
			// EXPLICIT per-network social field (Facebook / Twitter tab on the static front page),
			// with og<->twitter cross-fill so setting one network's value matches both cards.
			// Previously a single non-empty field among six (including the main SEO
			// title/description meta) flipped ALL four tags onto a per-post/global-template
			// resolution that diverged from the actual <title>/<meta description> — so a stray
			// value in one field silently corrupted every social tag.
			$real_title = ( class_exists( 'Nexter_Content_SEO_Title' ) && method_exists( 'Nexter_Content_SEO_Title', 'filter_document_title' ) )
				? (string) Nexter_Content_SEO_Title::filter_document_title( '' )
				: self::get_social_title();
			if ( '' === trim( $real_title ) ) {
				$real_title = self::get_social_title();
			}
			$real_desc = self::get_social_description();

			$fb_t = $tw_t = $fb_d = $tw_d = '';
			if ( is_front_page() && $front_id > 0 ) {
				$post_obj = get_post( $front_id );
				if ( $post_obj instanceof WP_Post ) {
					$fb_t = self::resolve_post_meta_title( get_post_meta( $front_id, self::META_FB_TITLE, true ), $post_obj );
					$tw_t = self::resolve_post_meta_title( get_post_meta( $front_id, self::META_TW_TITLE, true ), $post_obj );
					$fb_d = self::resolve_post_meta_description( get_post_meta( $front_id, self::META_FB_DESC, true ), $post_obj );
					$tw_d = self::resolve_post_meta_description( get_post_meta( $front_id, self::META_TW_DESC, true ), $post_obj );
				}
			}
			$og_title = '' !== $fb_t ? $fb_t : ( '' !== $tw_t ? $tw_t : $real_title );
			$tw_title = '' !== $tw_t ? $tw_t : ( '' !== $fb_t ? $fb_t : $real_title );
			$og_desc  = '' !== $fb_d ? $fb_d : ( '' !== $tw_d ? $tw_d : $real_desc );
			$tw_desc  = '' !== $tw_d ? $tw_d : ( '' !== $fb_d ? $fb_d : $real_desc );
		} elseif ( is_singular() ) {
			$post_obj = get_queried_object();
			if ( $post_obj instanceof WP_Post ) {
				$og_title = self::get_open_graph_title_for_post( $post_obj );
				$tw_title = self::get_twitter_title_for_post( $post_obj );
				$og_desc  = self::get_open_graph_description_for_post( $post_obj );
				$tw_desc  = self::get_twitter_description_for_post( $post_obj );
			} else {
				$og_title = $tw_title = self::get_social_title();
				$og_desc  = $tw_desc = self::get_social_description();
			}
		} elseif ( self::is_term_archive() ) {
			$term_obj = get_queried_object();
			if ( $term_obj instanceof WP_Term ) {
				$og_title = self::get_open_graph_title_for_term( $term_obj );
				$tw_title = self::get_twitter_title_for_term( $term_obj );
				$og_desc  = self::get_open_graph_description_for_term( $term_obj );
				$tw_desc  = self::get_twitter_description_for_term( $term_obj );
			} else {
				$og_title = $tw_title = self::get_social_title();
				$og_desc  = $tw_desc = self::get_social_description();
			}
		} else {
			$og_title = $tw_title = self::get_social_title();
			$og_desc  = $tw_desc = self::get_social_description();
		}
		// Home Page panel OG fields override the homepage title/description (falling back to the
		// plain home title/description, then the site-level values resolved above).
		if ( is_front_page() || is_home() ) {
			$home_og_title = self::resolve_home_meta_value( 'home_og_title', 'home_title' );
			if ( '' !== $home_og_title ) {
				$og_title = $home_og_title;
				$tw_title = $home_og_title;
			}
			$home_og_desc = self::resolve_home_meta_value( 'home_og_description', 'home_description' );
			if ( '' !== $home_og_desc ) {
				$og_desc = $home_og_desc;
				$tw_desc = $home_og_desc;
			}
		}

		if ( '' === trim( (string) $og_title ) ) {
			$og_title = get_bloginfo( 'name' );
		}
		if ( '' === trim( (string) $tw_title ) ) {
			$tw_title = $og_title;
		}

		// Collapse dangling/interior empty-segment separators (e.g. a leading " - " when a token
		// resolved empty) the same way the <title> tag does, so social titles aren't malformed.
		if ( class_exists( 'Nexter_Content_SEO_Title' ) && method_exists( 'Nexter_Content_SEO_Title', 'trim_template_separators' ) ) {
			$og_title = Nexter_Content_SEO_Title::trim_template_separators( $og_title );
			$tw_title = Nexter_Content_SEO_Title::trim_template_separators( $tw_title );
		}

		// Open Graph.
		echo '<meta property="og:type" content="' . esc_attr( self::get_type() ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		$og_site_name = (string) get_bloginfo( 'name' );
		if ( '' !== trim( $og_site_name ) ) {
			echo '<meta property="og:site_name" content="' . esc_attr( $og_site_name ) . '" />' . "\n";
		}
		echo '<meta property="og:locale" content="' . esc_attr( self::get_open_graph_locale() ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
		if ( ! empty( $og_desc ) ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";
		}
		if ( ! empty( $og_image_url ) ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image_url ) . '" />' . "\n";
			echo '<meta property="og:image:secure_url" content="' . esc_url( $og_image_url ) . '" />' . "\n";
			
			$dimensions = self::resolve_og_image_dimensions( $og_image_url );
			if ( ! empty( $dimensions['width'] ) && ! empty( $dimensions['height'] ) ) {
				echo '<meta property="og:image:width" content="' . esc_attr( $dimensions['width'] ) . '" />' . "\n";
				echo '<meta property="og:image:height" content="' . esc_attr( $dimensions['height'] ) . '" />' . "\n";
			}
			// og:image:alt (OGP-recommended, used by screen readers / social crawlers).
			$og_image_alt = self::resolve_og_image_alt( $og_image_url, $og_title );
			if ( '' !== $og_image_alt ) {
				echo '<meta property="og:image:alt" content="' . esc_attr( $og_image_alt ) . '" />' . "\n";
			}
		}

		// Article-specific Open Graph metadata for blog posts (published/modified time, author,
		// section, tags) — recommended for og:type=article.
		if ( 'article' === self::get_type() && is_singular() ) {
			self::output_article_meta( get_queried_object() );
		}

		// Twitter Card.
		echo '<meta name="twitter:card" content="' . esc_attr( $card_layout ) . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $tw_title ) . '" />' . "\n";
		if ( ! empty( $tw_desc ) ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $tw_desc ) . '" />' . "\n";
		}
		if ( ! empty( $twitter_image_url ) ) {
			echo '<meta name="twitter:image" content="' . esc_url( $twitter_image_url ) . '" />' . "\n";
			$tw_image_alt = self::resolve_og_image_alt( $twitter_image_url, $tw_title );
			if ( '' !== $tw_image_alt ) {
				echo '<meta name="twitter:image:alt" content="' . esc_attr( $tw_image_alt ) . '" />' . "\n";
			}
		}

		if ( ! empty( $options['twitter_site'] ) ) {
			$twitter_site = self::normalize_twitter_handle( $options['twitter_site'] );
			if ( ! empty( $twitter_site ) ) {
				echo '<meta name="twitter:site" content="' . esc_attr( $twitter_site ) . '" />' . "\n";
			}
		}
		$creator = self::get_twitter_creator();
		if ( ! empty( $creator ) ) {
			$creator = self::normalize_twitter_handle( $creator );
			if ( ! empty( $creator ) ) {
				echo '<meta name="twitter:creator" content="' . esc_attr( $creator ) . '" />' . "\n";
			}
		}

		// WooCommerce product-specific Open Graph tags.
		if ( self::nexter_is_product() ) {
			self::output_product_meta();
		}
	}

	/**
	 * Resolve a Home Page OG value, falling back to a plain home field, with template tokens
	 * (%site_name%, %tagline%, …) expanded against a site-level context.
	 *
	 * @param string $primary_key  Preferred option key (e.g. home_og_title).
	 * @param string $fallback_key Fallback option key (e.g. home_title).
	 * @return string
	 */
	private static function resolve_home_meta_value( $primary_key, $fallback_key ) {
		$options = Nexter_Content_SEO::get_options();
		foreach ( array( $primary_key, $fallback_key ) as $key ) {
			$raw = isset( $options[ $key ] ) ? trim( (string) $options[ $key ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$template = preg_replace( '/@([a-z0-9_]+)/i', '%$1%', $raw );
			$value    = Nexter_Content_SEO_Settings::replace_variables( $template, array() );
			$value    = trim( wp_strip_all_tags( (string) $value ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Feed configured social-profile URLs into the schema module's Organization node `sameAs`, so
	 * they attach to the single, @id-bearing Organization entity in the JSON-LD @graph instead of a
	 * second, standalone Organization script this class used to emit (which produced two
	 * uncoordinated Organization entities on the homepage). Hooked on the
	 * nexter_content_seo_organization_same_as filter from init(). `og:see_also` was previously
	 * emitted here too but was dropped: it is not a valid global Open Graph property, and social
	 * profiles belong in JSON-LD sameAs, which is what Google / consumers actually read.
	 *
	 * @param mixed $existing Existing sameAs value (string, array, or empty) from the schema row.
	 * @return mixed Merged, de-duplicated list of profile URLs, or the original value when none.
	 */
	public static function add_profiles_to_organization_same_as( $existing ) {
		$urls       = self::get_social_profile_urls();
		$valid_urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
		if ( empty( $valid_urls ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$existing_list = $existing;
		} elseif ( is_string( $existing ) && '' !== trim( $existing ) ) {
			$existing_list = array( $existing );
		} else {
			$existing_list = array();
		}
		return array_values( array_unique( array_merge( $existing_list, $valid_urls ) ) );
	}

	/**
	 * Decide whether Nexter should output social meta tags on this request.
	 *
	 * Avoid duplicate OG/Twitter tags when common SEO plugins are active, unless overridden.
	 *
	 * @return bool
	 */
	private static function should_output_social_meta() {
		$enabled = apply_filters( 'nexter_seo_output_social_meta', true );
		if ( ! $enabled ) {
			return false;
		}
		// Never emit social meta on 404s — the context resolves to the homepage, which would
		// otherwise leak home og:title/og:url onto an error page and mislead scrapers.
		if ( is_404() ) {
			return false;
		}
		// Defer to a competing SEO plugin (Yoast/Rank Math/AIOSEO/SEOPress) so OG/Twitter tags
		// are not emitted twice. Mirrors the meta-description coexistence guard.
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active() ) {
			return false;
		}
		return true;
	}

	/**
	 * Get social profile URLs from options.
	 *
	 * @return string[]
	 */
	private static function get_social_profile_urls() {
		$options = Nexter_Content_SEO::get_options();
		$urls    = array();

		foreach ( self::$profile_url_keys as $key ) {
			$val = isset( $options[ $key ] ) ? trim( (string) $options[ $key ] ) : '';
			if ( $val !== '' ) {
				// twitter_site may be @username; convert to URL if needed.
				if ( $key === 'twitter_site' && strpos( $val, '@' ) === 0 ) {
					$val = 'https://x.com/' . ltrim( $val, '@' );
				}
				if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
					$urls[] = $val;
				}
			}
		}

		return $urls;
	}

	/**
	 * Get social title for current context (non-singular archives, etc.).
	 * Singular posts use per-post social meta with global template fallback in get_open_graph_title_for_post / get_twitter_title_for_post.
	 *
	 * @return string
	 */
	private static function get_social_title() {
		$options  = Nexter_Content_SEO::get_options();
		$template = ! empty( $options['meta_title_template'] ) ? $options['meta_title_template'] : ( ! empty( $options['search_title_template'] ) ? $options['search_title_template'] : '%post_title% - %site_name%' );
		$template = self::normalize_template_for_variables( $template );

		// Homepage / blog index: site name (and tagline when set) instead of admin
		// preview data, which would otherwise leak an example post title into
		// og:title on the real front end. The static-front-page case is handled
		// in output_social_meta() and never reaches this fallback.
		if ( is_front_page() || is_home() ) {
			$name    = get_bloginfo( 'name' );
			$tagline = get_bloginfo( 'description' );
			return $tagline ? $name . ' – ' . $tagline : $name;
		}

		$preview = Nexter_Content_SEO_Settings::get_preview_data();
		return str_replace( array_keys( $preview ), array_values( $preview ), $template );
	}

	/**
	 * Convert @variable syntax to %variable% (same as Nexter_Content_SeoRank::normalize_template_for_variables).
	 *
	 * @param string $t Template string.
	 * @return string
	 */
	private static function normalize_template_for_variables( $t ) {
		if ( ! is_string( $t ) ) {
			return '';
		}
		return preg_replace( '/@([a-z0-9_]+)/i', '%$1%', $t );
	}

	/**
	 * Category, tag, or custom taxonomy archive (queried object is a term).
	 *
	 * @return bool
	 */
	private static function is_term_archive() {
		return is_category() || is_tag() || is_tax();
	}

	/**
	 * Resolve global search title/description templates for a term (Content SEO templates + %term_*% / aliases).
	 *
	 * @param WP_Term $term Term.
	 * @return array{0:string,1:string} Title and description.
	 */
	private static function get_resolved_global_title_and_description_for_term( WP_Term $term ) {
		$opts    = Nexter_Content_SEO::get_options();
		$title_t = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$desc_t  = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$title_t = self::normalize_template_for_variables( $title_t );
		$desc_t  = self::normalize_template_for_variables( $desc_t );
		$ctx     = array( 'term' => $term );
		return array(
			Nexter_Content_SEO_Settings::replace_variables( $title_t, $ctx ),
			Nexter_Content_SEO_Settings::replace_variables( $desc_t, $ctx ),
		);
	}

	/**
	 * Per-term meta title: resolve variables, else plain text.
	 *
	 * @param string  $raw  Stored meta.
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function resolve_term_meta_title( $raw, WP_Term $term ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( strpos( $raw, '%' ) !== false || strpos( $raw, '@' ) !== false ) {
			$t = self::normalize_template_for_variables( $raw );
			return trim( sanitize_text_field( Nexter_Content_SEO_Settings::replace_variables( $t, array( 'term' => $term ) ) ) );
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Per-term meta description: resolve variables, else plain (tags stripped).
	 *
	 * @param string  $raw  Stored meta.
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function resolve_term_meta_description( $raw, WP_Term $term ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( strpos( $raw, '%' ) !== false || strpos( $raw, '@' ) !== false ) {
			$t = self::normalize_template_for_variables( $raw );
			return trim( wp_strip_all_tags( Nexter_Content_SEO_Settings::replace_variables( $t, array( 'term' => $term ) ) ) );
		}
		return trim( wp_strip_all_tags( $raw ) );
	}

	/**
	 * Fallback description from term fields (no meta / template).
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function get_term_automatic_description( WP_Term $term ) {
		$d = isset( $term->description ) ? (string) $term->description : '';
		return trim( wp_strip_all_tags( $d ) );
	}

	/**
	 * Open Graph title for a taxonomy term archive (same cascade as singular post).
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function get_open_graph_title_for_term( WP_Term $term ) {
		$tid = (int) $term->term_id;
		$t   = self::resolve_term_meta_title( get_term_meta( $tid, self::META_FB_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		// Cross-fill from the Twitter title so og:title / twitter:title stay in sync (same cascade
		// as singular posts). Only fills when the FB field is empty.
		$t = self::resolve_term_meta_title( get_term_meta( $tid, self::META_TW_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		$t = self::resolve_term_meta_title( get_term_meta( $tid, self::META_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		list( $global_title ) = self::get_resolved_global_title_and_description_for_term( $term );
		return is_string( $global_title ) ? trim( $global_title ) : '';
	}

	/**
	 * Twitter title for a taxonomy term archive.
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function get_twitter_title_for_term( WP_Term $term ) {
		$tid = (int) $term->term_id;
		$t   = self::resolve_term_meta_title( get_term_meta( $tid, self::META_TW_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		// Cross-fill from the Facebook title so twitter:title / og:title stay in sync (same cascade
		// as singular posts). Only fills when the TW field is empty.
		$t = self::resolve_term_meta_title( get_term_meta( $tid, self::META_FB_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		$t = self::resolve_term_meta_title( get_term_meta( $tid, self::META_TITLE, true ), $term );
		if ( '' !== $t ) {
			return $t;
		}
		list( $global_title ) = self::get_resolved_global_title_and_description_for_term( $term );
		return is_string( $global_title ) ? trim( $global_title ) : '';
	}

	/**
	 * Open Graph description for a taxonomy term archive.
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function get_open_graph_description_for_term( WP_Term $term ) {
		$tid = (int) $term->term_id;
		$d   = self::resolve_term_meta_description( get_term_meta( $tid, self::META_FB_DESC, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		// Cross-fill from the Twitter description so og / twitter descriptions stay in sync (same
		// cascade as singular posts). Only fills when the FB field is empty.
		$d = self::resolve_term_meta_description( get_term_meta( $tid, self::META_TW_DESC, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		$d = self::resolve_term_meta_description( get_term_meta( $tid, self::META_DESCRIPTION, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		list( , $global_desc ) = self::get_resolved_global_title_and_description_for_term( $term );
		$global_desc           = is_string( $global_desc ) ? trim( $global_desc ) : '';
		if ( '' !== $global_desc ) {
			return $global_desc;
		}
		return self::get_term_automatic_description( $term );
	}

	/**
	 * Twitter description for a taxonomy term archive.
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function get_twitter_description_for_term( WP_Term $term ) {
		$tid = (int) $term->term_id;
		$d   = self::resolve_term_meta_description( get_term_meta( $tid, self::META_TW_DESC, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		// Cross-fill from the Facebook description so twitter / og descriptions stay in sync (same
		// cascade as singular posts). Only fills when the TW field is empty.
		$d = self::resolve_term_meta_description( get_term_meta( $tid, self::META_FB_DESC, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		$d = self::resolve_term_meta_description( get_term_meta( $tid, self::META_DESCRIPTION, true ), $term );
		if ( '' !== $d ) {
			return $d;
		}
		list( , $global_desc ) = self::get_resolved_global_title_and_description_for_term( $term );
		$global_desc           = is_string( $global_desc ) ? trim( $global_desc ) : '';
		if ( '' !== $global_desc ) {
			return $global_desc;
		}
		return self::get_term_automatic_description( $term );
	}

	/**
	 * Resolve global search title/description for a post (Content SEO templates).
	 *
	 * @param WP_Post $post Post.
	 * @return array{0:string,1:string} Title and description.
	 */
	private static function get_resolved_global_title_and_description_for_post( WP_Post $post ) {
		$opts    = Nexter_Content_SEO::get_options();
		$title_t = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$desc_t  = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$title_t = self::normalize_template_for_variables( $title_t );
		$desc_t  = self::normalize_template_for_variables( $desc_t );
		$ctx     = array( 'post' => $post );
		return array(
			Nexter_Content_SEO_Settings::replace_variables( $title_t, $ctx ),
			Nexter_Content_SEO_Settings::replace_variables( $desc_t, $ctx ),
		);
	}

	/**
	 * Per-post meta title: resolve @/% variables, else plain text.
	 *
	 * @param string   $raw  Stored meta.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function resolve_post_meta_title( $raw, WP_Post $post ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( strpos( $raw, '%' ) !== false || strpos( $raw, '@' ) !== false ) {
			$t = self::normalize_template_for_variables( $raw );
			return trim( sanitize_text_field( Nexter_Content_SEO_Settings::replace_variables( $t, array( 'post' => $post ) ) ) );
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Per-post meta description: resolve @/% variables, else plain text (tags stripped).
	 *
	 * @param string   $raw  Stored meta.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function resolve_post_meta_description( $raw, WP_Post $post ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( strpos( $raw, '%' ) !== false || strpos( $raw, '@' ) !== false ) {
			$t   = self::normalize_template_for_variables( $raw );
			$raw = (string) Nexter_Content_SEO_Settings::replace_variables( $t, array( 'post' => $post ) );
		}
		// Run through the SAME cleanup/truncation as <meta name="description"> (builder-placeholder
		// stripping + word-boundary length cap), so a raw value that renders cleanly in the plain
		// description tag can't leak page-builder placeholder text or an over-long, mid-word-cut
		// string into og:description / twitter:description.
		return self::clean_social_description( $raw );
	}

	/**
	 * Apply the main meta-description cleanup/truncation to a social description string so
	 * og:/twitter: descriptions match what <meta name="description"> emits (shared placeholder
	 * stripping + length cap). Falls back to a local tag-strip + whitespace collapse when the
	 * description module isn't loaded.
	 *
	 * @param string $text Raw description text.
	 * @return string
	 */
	private static function clean_social_description( $text ) {
		$text = (string) $text;
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && method_exists( 'Nexter_Content_SEO_Description', 'cleanup_text' ) ) {
			return Nexter_Content_SEO_Description::cleanup_text( $text );
		}
		$text = trim( wp_strip_all_tags( $text ) );
		return (string) preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Resolve alt text for a social image: the attachment's stored alt when the URL maps to a
	 * local attachment (size suffix tolerated), otherwise the given title fallback.
	 *
	 * @param string $url            Image URL.
	 * @param string $fallback_title Title to use when no attachment alt is available.
	 * @return string
	 */
	private static function resolve_og_image_alt( $url, $fallback_title ) {
		$url = (string) $url;
		if ( '' !== $url ) {
			$lookup = preg_replace( '/-\d+x\d+(\.(?:jpe?g|png|gif|webp|avif))(?:\?.*)?$/i', '$1', $url );
			$id     = attachment_url_to_postid( $lookup );
			if ( $id ) {
				$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
				if ( '' !== $alt ) {
					return $alt;
				}
			}
		}
		return trim( (string) $fallback_title );
	}

	/**
	 * Resolve og:image width/height for a social image URL.
	 *
	 * A resized image URL carries its dimensions in the filename (e.g. name-1200x630.jpg), which
	 * attachment_url_to_postid() can't match against the stored full-size URL. Read the size from
	 * the suffix first (it's the actual served size), then fall back to the attachment metadata
	 * for a full-size URL.
	 *
	 * @param string $url Image URL.
	 * @return array{width?:int,height?:int}
	 */
	private static function resolve_og_image_dimensions( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return array();
		}

		// 1. WP resize suffix in the URL (-WxH.ext) — cheapest, no I/O, no cache needed.
		if ( preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif)(?:\?.*)?$/i', $url, $m ) ) {
			return array(
			'width'  => (int) $m[1],
			'height' => (int) $m[2]
			);
		}

		// 2. Cache for every I/O-bound branch below (including a negative result), keyed on the URL
		//    hash — so a local disk read or remote probe happens at most once per image per TTL.
		$cache_key = 'nxt_oging_dim_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['width'] ) ? array(
			'width'  => (int) $cached['width'],
			'height' => (int) $cached['height']
			) : array();
		}

		$dims = array();

		// 3. Local WP attachment metadata (same-host uploads with a known attachment ID).
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
				$dims = array(
				'width'  => (int) $metadata['width'],
				'height' => (int) $metadata['height']
				);
			}
		}

		// 4. Local file on disk by URL PATH — resolves CDN/rewritten URLs (different host, same
		//    /wp-content/uploads path) and per-post override URLs that still live in uploads.
		if ( empty( $dims ) ) {
			$dims = self::local_image_dimensions( $url );
		}

		// 5. Genuinely remote/off-site image: fetch once with a short timeout and measure from the
		//    bytes. Filterable off. The result (including failure) is cached below.
		if ( empty( $dims ) && preg_match( '#^https?://#i', $url )
			&& self::remote_probe_allowed( $url )
			&& apply_filters( 'nexter_content_seo_og_probe_remote_image', true, $url ) ) {
			$dims = self::remote_image_dimensions( $url );
		}

		set_transient(
			$cache_key,
			! empty( $dims ) ? $dims : array(
			'width'  => 0,
			'height' => 0
			),
			(int) apply_filters( 'nexter_content_seo_og_image_dim_ttl', DAY_IN_SECONDS )
		);

		return ! empty( $dims['width'] ) ? $dims : array();
	}

	/**
	 * Width/height of an image whose URL PATH maps into this site's uploads directory, by reading
	 * the local file. Host-agnostic (so CDN/rewritten URLs resolve), and confined to the uploads
	 * dir via realpath so a crafted path can never read outside it.
	 *
	 * @param string $url Image URL.
	 * @return array{width:int,height:int}|array{} Dimensions, or [] when not a readable local file.
	 */
	private static function local_image_dimensions( $url ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array();
		}
		$base_path = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );
		$url_path  = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( '' === $base_path || '' === $url_path ) {
			return array();
		}
		$pos = strpos( $url_path, $base_path );
		if ( false === $pos ) {
			return array();
		}
		$relative = ltrim( substr( $url_path, $pos + strlen( $base_path ) ), '/\\' );
		if ( '' === $relative ) {
			return array();
		}
		$path      = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . $relative;
		$real_base = realpath( (string) $uploads['basedir'] );
		$real_path = realpath( $path );
		if ( ! $real_base || ! $real_path || 0 !== strpos( $real_path, $real_base ) ) {
			return array(); // Outside the uploads dir — refuse to read.
		}
		$size = @getimagesize( $real_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bad image returns false, handled below.
		if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
			return array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1]
			);
		}
		return array();
	}

	/**
	 * SSRF guard for the remote OG-image probe. The image URL comes from per-post/term social-image
	 * meta (author-controllable), so before the server fetches it we require an http(s) URL whose
	 * host resolves EXCLUSIVELY to public IPs. Private / reserved / loopback / link-local targets
	 * (e.g. 127.0.0.1, 10/8, 192.168/16, 169.254.169.254 cloud metadata) are refused. Fails closed:
	 * an unresolvable or IPv6-only host is not probed.
	 *
	 * @param string $url URL to probe.
	 * @return bool
	 */
	private static function remote_probe_allowed( $url ) {
		return ! empty( self::resolve_validated_public_ips( $url ) );
	}

	/**
	 * Resolve a URL's host to the set of public IPs it points at, or [] if the target is not
	 * safe to fetch. Resolves BOTH A (IPv4) and AAAA (IPv6) records so an IPv6-only host can't
	 * slip past an IPv4-only guard, and fails closed: if the host is unresolvable, or ANY of its
	 * resolved addresses is private / reserved / loopback / link-local (e.g. 127.0.0.1, 10/8,
	 * 169.254.169.254, ::1, fc00::/7, fe80::/10), the whole URL is refused. The returned IPs are
	 * pinned into the connection by remote_image_dimensions() to close the DNS-rebinding window.
	 *
	 * @param string $url URL to resolve.
	 * @return string[] Validated public IPs, or [] when the URL must not be probed.
	 */
	private static function resolve_validated_public_ips( $url ) {
		$parts  = wp_parse_url( (string) $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			return array();
		}
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			if ( function_exists( 'gethostbynamel' ) ) {
				$v4 = gethostbynamel( $host );
				if ( is_array( $v4 ) ) {
					$ips = array_merge( $ips, $v4 );
				}
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- returns false on failure, handled below.
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}
		if ( empty( $ips ) ) {
			return array(); // Unresolvable — do not probe.
		}
		$ips = array_unique( $ips );
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return array(); // Any private / reserved / loopback / link-local address → fail closed.
			}
		}
		return array_values( $ips );
	}

	/**
	 * cURL pin: force the OG-image fetch to connect to exactly the IPs we already validated, so a
	 * DNS record that changes between validation and connection (DNS rebinding / TOCTOU) can't
	 * redirect the request to an internal address. Scoped: only fires while $og_probe_curl_resolve
	 * is populated around the single wp_remote_get() call in remote_image_dimensions().
	 *
	 * @param resource $handle cURL handle.
	 * @return void
	 */
	public static function og_probe_pin_curl( $handle ) {
		if ( ! empty( self::$og_probe_curl_resolve ) && function_exists( 'curl_setopt' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, self::$og_probe_curl_resolve ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pinning validated IPs on the WP HTTP cURL handle.
		}
	}

	/**
	 * Width/height of a remote image, fetched once with a bounded timeout and measured from the
	 * response bytes. Callers cache the result (including failure), so this runs at most once per
	 * image per TTL. Only reached for URLs cleared by remote_probe_allowed().
	 *
	 * @param string $url Absolute http(s) image URL.
	 * @return array{width:int,height:int}|array{} Dimensions, or [] on failure.
	 */
	private static function remote_image_dimensions( $url ) {
		if ( ! function_exists( 'getimagesizefromstring' ) ) {
			return array();
		}
		// Re-resolve + validate here (not just at the earlier gate) and pin the connection to
		// exactly these IPs, so a host that rebinds to an internal address between the check and
		// the fetch can't be reached. Empty means the target is no longer safe — bail.
		$ips = self::resolve_validated_public_ips( $url );
		if ( empty( $ips ) ) {
			return array();
		}
		$parts                       = wp_parse_url( $url );
		$host                        = isset( $parts['host'] ) ? $parts['host'] : '';
		$port                        = isset( $parts['port'] ) ? (int) $parts['port'] : ( ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ) ? 80 : 443 );
		self::$og_probe_curl_resolve = array();
		foreach ( $ips as $ip ) {
			self::$og_probe_curl_resolve[] = $host . ':' . $port . ':' . $ip;
		}
		$timeout = (int) apply_filters( 'nexter_content_seo_og_remote_probe_timeout', 5 );
		// Cap the download: image dimensions live in the first bytes, so we never need a large
		// body. Stops a hostile/oversized target from spilling memory. Default 5 MB, filterable.
		$max_bytes = (int) apply_filters( 'nexter_content_seo_og_remote_probe_max_bytes', 5 * MB_IN_BYTES );
		add_action( 'http_api_curl', array( __CLASS__, 'og_probe_pin_curl' ), 10, 1 );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'             => max( 2, $timeout ),
				// redirection => 0 so a public URL can't 302 to an internal host (SSRF bypass);
				// sslverify => true — never disable TLS verification on a server-side fetch.
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => max( 1, $max_bytes ),
				'user-agent'          => 'Nexter-SEO/1.0',
			)
		);
		remove_action( 'http_api_curl', array( __CLASS__, 'og_probe_pin_curl' ), 10 );
		self::$og_probe_curl_resolve = array();
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return array();
		}
		$size = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-image returns false, handled below.
		if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
			return array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1]
			);
		}
		return array();
	}

	private static function get_singular_post_automatic_description( WP_Post $post ) {
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$wc_product = wc_get_product( $post->ID );
			if ( $wc_product && is_a( $wc_product, 'WC_Product' ) ) {
				$short = $wc_product->get_short_description();
				if ( is_string( $short ) && '' !== trim( $short ) ) {
					return self::clean_social_description( $short );
				}
			}
		}
		// Expand shortcodes in the raw content fallback so a shortcode-built page yields real text
		// (not literal [shortcode] markup), then run the shared cleanup: strips page-builder
		// placeholder copy and applies the same length cap as the main meta description, so none of
		// it leaks into og:/twitter: descriptions.
		if ( has_excerpt( $post ) ) {
			$auto = get_the_excerpt( $post );
		} else {
			$raw  = (string) $post->post_content;
			$raw  = ( false !== strpos( $raw, '[' ) ) ? strip_shortcodes( $raw ) : $raw;
			$auto = wp_trim_words( wp_strip_all_tags( $raw ), 30 );
		}
		return self::clean_social_description( $auto );
	}

	/**
	 * Open Graph title: FB → TW → main SEO title → global meta_title_template.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function get_open_graph_title_for_post( WP_Post $post ) {
		$pid = (int) $post->ID;
		$t   = self::resolve_post_meta_title( get_post_meta( $pid, self::META_FB_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		// Cross-fill from the Twitter title so setting only one network's value keeps og:title and
		// twitter:title in sync (as the docblock promises). Only fills when the FB field is empty.
		$t = self::resolve_post_meta_title( get_post_meta( $pid, self::META_TW_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		$t = self::resolve_post_meta_title( get_post_meta( $pid, self::META_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		list( $global_title ) = self::get_resolved_global_title_and_description_for_post( $post );
		return is_string( $global_title ) ? trim( $global_title ) : '';
	}

	/**
	 * Twitter title: TW → FB → main SEO title → global meta_title_template.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function get_twitter_title_for_post( WP_Post $post ) {
		$pid = (int) $post->ID;
		$t   = self::resolve_post_meta_title( get_post_meta( $pid, self::META_TW_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		// Cross-fill from the Facebook title so a single network value keeps twitter:title and
		// og:title in sync (as the docblock promises). Only fills when the TW field is empty.
		$t = self::resolve_post_meta_title( get_post_meta( $pid, self::META_FB_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		$t = self::resolve_post_meta_title( get_post_meta( $pid, self::META_TITLE, true ), $post );
		if ( '' !== $t ) {
			return $t;
		}
		list( $global_title ) = self::get_resolved_global_title_and_description_for_post( $post );
		return is_string( $global_title ) ? trim( $global_title ) : '';
	}

	/**
	 * Open Graph description: FB → TW → main meta → global template → automatic excerpt/content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function get_open_graph_description_for_post( WP_Post $post ) {
		$pid = (int) $post->ID;
		$d   = self::resolve_post_meta_description( get_post_meta( $pid, self::META_FB_DESC, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		// Cross-fill from the Twitter description so a single network value keeps og:description
		// and twitter:description in sync. Only fills when the FB field is empty.
		$d = self::resolve_post_meta_description( get_post_meta( $pid, self::META_TW_DESC, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		$d = self::resolve_post_meta_description( get_post_meta( $pid, self::META_DESCRIPTION, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		list( , $global_desc ) = self::get_resolved_global_title_and_description_for_post( $post );
		$global_desc           = is_string( $global_desc ) ? trim( $global_desc ) : '';
		if ( '' !== $global_desc ) {
			return $global_desc;
		}
		return self::get_singular_post_automatic_description( $post );
	}

	/**
	 * Twitter description: TW → FB → main meta → global template → automatic excerpt/content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function get_twitter_description_for_post( WP_Post $post ) {
		$pid = (int) $post->ID;
		$d   = self::resolve_post_meta_description( get_post_meta( $pid, self::META_TW_DESC, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		// Cross-fill from the Facebook description so a single network value keeps
		// twitter:description and og:description in sync. Only fills when the TW field is empty.
		$d = self::resolve_post_meta_description( get_post_meta( $pid, self::META_FB_DESC, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		$d = self::resolve_post_meta_description( get_post_meta( $pid, self::META_DESCRIPTION, true ), $post );
		if ( '' !== $d ) {
			return $d;
		}
		list( , $global_desc ) = self::get_resolved_global_title_and_description_for_post( $post );
		$global_desc           = is_string( $global_desc ) ? trim( $global_desc ) : '';
		if ( '' !== $global_desc ) {
			return $global_desc;
		}
		return self::get_singular_post_automatic_description( $post );
	}

	/**
	 * Get type.
	 *
	 * @return string
	 */
	/**
	 * Emit article:* Open Graph tags for a singular post (published/modified time in ISO-8601,
	 * author archive URL, primary section, tags).
	 *
	 * @param mixed $post Queried object (expected WP_Post).
	 */
	private static function output_article_meta( $post ) {
		if ( ! $post instanceof WP_Post || empty( $post->ID ) ) {
			return;
		}
		$published = get_the_date( 'c', $post );
		$modified  = get_the_modified_date( 'c', $post );
		if ( $published ) {
			echo '<meta property="article:published_time" content="' . esc_attr( $published ) . '" />' . "\n";
		}
		if ( $modified ) {
			echo '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '" />' . "\n";
		}
		$author_id = (int) $post->post_author;
		if ( $author_id ) {
			$author_url = get_author_posts_url( $author_id );
			if ( $author_url ) {
				echo '<meta property="article:author" content="' . esc_url( $author_url ) . '" />' . "\n";
			}
		}
		$cats = get_the_terms( $post->ID, 'category' );
		if ( $cats && ! is_wp_error( $cats ) ) {
			echo '<meta property="article:section" content="' . esc_attr( $cats[0]->name ) . '" />' . "\n";
		}
		$tags = get_the_terms( $post->ID, 'post_tag' );
		if ( $tags && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				echo '<meta property="article:tag" content="' . esc_attr( $tag->name ) . '" />' . "\n";
			}
		}
	}

	private static function get_type() {
		if ( is_front_page() || is_home() ) {
			return 'website';
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'website';
		}

		if ( is_author() ) {
			return 'profile';
		}

		if ( self::nexter_is_product() ) {
			return 'product';
		}
		// Only blog posts are "article"; static Pages and other singular content are "website"
		// (og:type=article on a Page is incorrect and was previously emitted for everything).
		// Non-singular contexts (archives/search) also default to website.
		$type = ( is_singular() && is_singular( 'post' ) ) ? 'article' : 'website';
		/**
		 * Filter the resolved og:type. Lets article-like CPTs opt into 'article'.
		 *
		 * @param string $type Resolved og:type.
		 */
		return (string) apply_filters( 'nexter_content_seo_og_type', $type );
	}

	private static function nexter_is_product() {
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Get twitter:creator value (per-post override or global).
	 *
	 * @return string
	 */
	private static function get_twitter_creator() {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$meta    = get_post_meta( $post_id, self::META_TW_CREATOR, true );
			if ( ! empty( $meta ) && is_string( $meta ) ) {
				return trim( $meta );
			}
		} elseif ( self::is_term_archive() ) {
			$t = get_queried_object();
			if ( $t instanceof WP_Term ) {
				$meta = get_term_meta( $t->term_id, self::META_TW_CREATOR, true );
				if ( ! empty( $meta ) && is_string( $meta ) ) {
					return trim( $meta );
				}
			}
		}
		$options = Nexter_Content_SEO::get_options();
		$author  = isset( $options['twitter_author'] ) ? trim( (string) $options['twitter_author'] ) : '';
		return $author;
	}

	/**
	 * Normalize Twitter handle to @username format (extract from URL if needed).
	 * Formats Twitter/X creator handle for meta tags (strip @, validate length).
	 *
	 * @param string $value URL (e.g. https://x.com/username) or @username.
	 * @return string @username or empty.
	 */
	private static function normalize_twitter_handle( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$parsed = wp_parse_url( $value );
		if ( is_array( $parsed ) && ! empty( $parsed['path'] ) ) {
			$path  = trim( (string) $parsed['path'], '/' );
			$parts = array_values( array_filter( explode( '/', $path ) ) );
			$last  = ! empty( $parts ) ? (string) end( $parts ) : '';
		} else {
			$parts = explode( '/', $value );
			$last  = trim( (string) end( $parts ) );
		}

		$last = ltrim( $last, '@' );
		$last = preg_replace( '/[^A-Za-z0-9_]/', '', $last );
		if ( '' === $last ) {
			return '';
		}

		return '@' . $last;
	}

	/**
	 * Output WooCommerce product-specific Open Graph meta tags.
	 * (og:product:price:amount, og:product:price:currency, og:product:availability)
	 */
	private static function output_product_meta() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product_id = get_queried_object_id();
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$price = $product->get_price();
		if ( '' === $price && $product->is_type( 'variable' ) ) {
			$price = $product->get_variation_price( 'min', false );
		}
		if ( '' !== $price && is_numeric( $price ) ) {
			echo '<meta property="og:product:price:amount" content="' . esc_attr( $price ) . '" />' . "\n";
		}

		$currency = get_woocommerce_currency();
		if ( ! empty( $currency ) ) {
			echo '<meta property="og:product:price:currency" content="' . esc_attr( $currency ) . '" />' . "\n";
		}

		$stock_status = method_exists( $product, 'get_stock_status' ) ? $product->get_stock_status() : 'instock';
		if ( 'outofstock' === $stock_status ) {
			$availability = 'outofstock';
		} elseif ( 'onbackorder' === $stock_status ) {
			$availability = 'backorder';
		} else {
			$availability = 'instock';
		}
		echo '<meta property="og:product:availability" content="' . esc_attr( $availability ) . '" />' . "\n";

		// Twitter product card tags (label1/data1, label2/data2).
		$price_display = ( '' !== $price && is_numeric( $price ) ) ? $currency . ' ' . $price : '';
		if ( ! empty( $price_display ) ) {
			echo '<meta name="twitter:label1" content="' . esc_attr( __( 'Price', 'nexter-extension' ) ) . '" />' . "\n";
			echo '<meta name="twitter:data1" content="' . esc_attr( $price_display ) . '" />' . "\n";
		}
		echo '<meta name="twitter:label2" content="' . esc_attr( __( 'Availability', 'nexter-extension' ) ) . '" />' . "\n";
		if ( 'outofstock' === $availability ) {
			$avail_label = __( 'Out of Stock', 'nexter-extension' );
		} elseif ( 'backorder' === $availability ) {
			$avail_label = __( 'On backorder', 'nexter-extension' );
		} else {
			$avail_label = __( 'In Stock', 'nexter-extension' );
		}
		echo '<meta name="twitter:data2" content="' . esc_attr( $avail_label ) . '" />' . "\n";
	}

	/**
	 * Get social URL for current context.
	 *
	 * @return string
	 */
	private static function get_social_url() {
		if ( is_singular() ) {
			return (string) get_permalink();
		}
		if ( class_exists( 'Nexter_Content_SEO_Canonical' ) ) {
			$canonical = Nexter_Content_SEO_Canonical::resolve_canonical_url();
			if ( is_string( $canonical ) && $canonical !== '' ) {
				return $canonical;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Get locale in Open Graph format (e.g. en_US).
	 *
	 * @return string
	 */
	private static function get_open_graph_locale() {
		$locale = get_locale();
		if ( ! is_string( $locale ) || '' === $locale ) {
			return 'en_US';
		}

		$locale = str_replace( '-', '_', $locale );
		if ( strpos( $locale, '_' ) === false ) {
			$locale = strtolower( $locale ) . '_' . strtoupper( $locale );
		} else {
			$parts  = explode( '_', $locale );
			$lang   = strtolower( (string) $parts[0] );
			$reg    = isset( $parts[1] ) ? strtoupper( (string) $parts[1] ) : strtoupper( $lang );
			$locale = $lang . '_' . $reg;
		}

		return $locale;
	}

	/**
	 * Get social description for current context.
	 *
	 * @return string
	 */
	private static function get_social_description() {
		// Share the exact same resolver as <meta name="description"> so og:/twitter: descriptions
		// never go absent while the meta description is present (archives, search, blog index, etc.).
		// The site tagline remains the final fallback (matching resolve_meta_description()'s own tail).
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && method_exists( 'Nexter_Content_SEO_Description', 'get_current_description' ) ) {
			$desc = (string) Nexter_Content_SEO_Description::get_current_description();
			if ( '' !== trim( $desc ) ) {
				return $desc;
			}
		}
		return get_bloginfo( 'description' );
	}

	/**
	 * Whether an image meets Twitter/X's "summary_large_image" minimum (300x157 by default). Uses
	 * the shared resolver, so local attachments, local files, CDN/rewritten URLs and remote images
	 * are all measured (and cached). When dimensions genuinely cannot be determined the card is NOT
	 * claimed as large — Twitter would otherwise render a broken large card for a sub-minimum image
	 * (filterable for sites that prefer the old "assume adequate" behavior).
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private static function image_supports_large_card( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false; // No image at all → no large card.
		}
		$min_width  = (int) apply_filters( 'nexter_content_seo_large_card_min_width', 300 );
		$min_height = (int) apply_filters( 'nexter_content_seo_large_card_min_height', 157 );

		$dims = self::resolve_og_image_dimensions( $url );
		if ( ! empty( $dims['width'] ) && ! empty( $dims['height'] ) ) {
			return (int) $dims['width'] >= $min_width && (int) $dims['height'] >= $min_height;
		}

		// Dimensions could not be determined even after a remote probe. Do not claim a large card
		// by default (avoids a broken large card for an unmeasurable/too-small image).
		return (bool) apply_filters( 'nexter_content_seo_large_card_assume_when_unknown', false, $url );
	}

	/**
	 * Resolve Open Graph and Twitter image URLs (Content SEO panel + fallbacks).
	 *
	 * @return array{0: string, 1: string} [ og:url, twitter:url ]
	 */
	private static function get_og_and_twitter_image_urls() {
		$og      = '';
		$twitter = '';

		// Homepage: per-post Nexter SEO image on the static front page → static
		// front-page featured image → default social image → site logo / icon.
		if ( is_front_page() || is_home() ) {
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 ) {
				$post_og = self::get_post_og_image_url( $front_id );
				if ( $post_og && self::is_supported_social_image_url( $post_og ) ) {
					$og = $post_og;
				}
				$post_tw = self::get_post_twitter_image_url( $front_id );
				if ( $post_tw && self::is_supported_social_image_url( $post_tw ) ) {
					$twitter = $post_tw;
				}
				// Fall back to the static front page's featured image (1200×630 preferred).
				if ( ( empty( $og ) || empty( $twitter ) ) && has_post_thumbnail( $front_id ) ) {
					$thumb_url = get_the_post_thumbnail_url( $front_id, 'full' );
					if ( $thumb_url && self::is_supported_social_image_url( $thumb_url ) ) {
						if ( empty( $og ) ) {
							$og = $thumb_url;
						}
						if ( empty( $twitter ) ) {
							$twitter = $thumb_url;
						}
					}
				}
			}
		} elseif ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$og      = self::get_post_og_image_url( $post_id );
			$twitter = self::get_post_twitter_image_url( $post_id );
		} elseif ( self::is_term_archive() ) {
			$t = get_queried_object();
			if ( $t instanceof WP_Term ) {
				$tid     = (int) $t->term_id;
				$og      = self::get_term_og_image_url( $tid );
				$twitter = self::get_term_twitter_image_url( $tid );
			}
		}

		$options = Nexter_Content_SEO::get_options();
		$default = isset( $options['default_social_image'] ) ? trim( (string) $options['default_social_image'] ) : '';
		if ( self::is_supported_social_image_url( $default ) ) {
			if ( empty( $og ) ) {
				$og = $default;
			}
			if ( empty( $twitter ) ) {
				$twitter = $default;
			}
		} else {
			// Prefer Customizer custom logo, then Site Icon (favicon). Both are
			// common on real sites and prevent blank link-preview cards out of box.
			$fallback = self::get_site_branding_image_url();
			if ( $fallback ) {
				if ( empty( $og ) ) {
					$og = $fallback;
				}
				if ( empty( $twitter ) ) {
					$twitter = $fallback;
				}
			}
		}

		return array( $og, $twitter );
	}

	/**
	 * Resolve the site's branding image — Customizer custom logo first, then
	 * the Site Icon — to serve as the fallback Open Graph / Twitter image when
	 * no default social image has been configured.
	 *
	 * @return string Image URL or empty string.
	 */
	private static function get_site_branding_image_url() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url && self::is_supported_social_image_url( $logo_url ) ) {
				return $logo_url;
			}
		}
		$site_icon = get_site_icon_url();
		if ( $site_icon && self::is_supported_social_image_url( $site_icon ) ) {
			return $site_icon;
		}
		return '';
	}

	/**
	 * Get the social image URL for the current context.
	 * Uses post-specific image (Content SEO Rank meta, featured, legacy meta) or falls back to default.
	 *
	 * @return string Image URL or empty string.
	 */
	public static function get_social_image_url() {
		list( $og, $tw ) = self::get_og_and_twitter_image_urls();
		if ( ! empty( $og ) ) {
			return $og;
		}
		if ( ! empty( $tw ) ) {
			return $tw;
		}
		return '';
	}

	/**
	 * Open Graph image for a singular post: FB image meta, then legacy meta, then featured / gallery.
	 *
	 * @param int $post_id Post ID.
	 * @return string Image URL or empty string.
	 */
	/**
	 * Open Graph image from term meta (Facebook / shared image).
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function get_term_og_image_url( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return '';
		}
		$fb = get_term_meta( $term_id, self::META_FB_IMAGE, true );
		if ( self::is_supported_social_image_url( $fb ) ) {
			return trim( $fb );
		}
		return '';
	}

	/**
	 * Twitter image from term meta, else same as Open Graph.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function get_term_twitter_image_url( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return '';
		}
		$tw = get_term_meta( $term_id, self::META_TW_IMAGE, true );
		if ( self::is_supported_social_image_url( $tw ) ) {
			return trim( $tw );
		}
		return self::get_term_og_image_url( $term_id );
	}

	private static function get_post_og_image_url( $post_id ) {
		return self::get_post_social_image( $post_id );
	}

	/**
	 * Twitter image: per-post X image meta, else same sources as Open Graph (matches editor: X falls back to FB / global).
	 *
	 * @param int $post_id Post ID.
	 * @return string Image URL or empty string.
	 */
	private static function get_post_twitter_image_url( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}
		$tw = get_post_meta( $post_id, self::META_TW_IMAGE, true );
		if ( self::is_supported_social_image_url( $tw ) ) {
			return trim( $tw );
		}
		return self::get_post_social_image( $post_id );
	}

	/**
	 * Get social image URL for a post (Content SEO Rank FB image, legacy meta, featured image, or gallery).
	 *
	 * @param int $post_id Post ID.
	 * @return string Image URL or empty string.
	 */
	public static function get_post_social_image( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}

		// Content SEO sidebar (Nexter_Content_SeoRank): Facebook / Open Graph image.
		$fb = get_post_meta( $post_id, self::META_FB_IMAGE, true );
		if ( self::is_supported_social_image_url( $fb ) ) {
			return trim( $fb );
		}

		// Legacy custom social image meta.
		$meta = get_post_meta( $post_id, '_nexter_social_image', true );
		if ( self::is_supported_social_image_url( $meta ) ) {
			return $meta;
		}

		// Featured image.
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$img = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( ! empty( $img[0] ) ) {
				return $img[0];
			}
		}

		// WooCommerce product gallery fallback (first gallery image).
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product && is_a( $product, 'WC_Product' ) ) {
				$gallery_ids = $product->get_gallery_image_ids();
				if ( ! empty( $gallery_ids ) ) {
					$img = wp_get_attachment_image_src( $gallery_ids[0], 'full' );
					if ( ! empty( $img[0] ) ) {
						return $img[0];
					}
				}
			}
		}

		return '';
	}

	/**
	 * Validate social image URL for platforms that require raster formats.
	 *
	 * @param string $url Candidate image URL.
	 * @return bool
	 */
	private static function is_supported_social_image_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return true;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return true;
		}
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true );
	}
}
