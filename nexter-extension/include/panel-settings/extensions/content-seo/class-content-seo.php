<?php
/**
 * Content SEO – main bootstrap. Registers option, loads sub-modules, REST API.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO
 */
class Nexter_Content_SEO {

	const OPTION_NAME    = 'nexter_content_seo_options';
	const REST_NAMESPACE = 'nexter/v1';

	/**
	 * White-label-aware product label for the SEO module. Returns the Pro white-label brand name
	 * when one is set (white label applies on Pro only), otherwise "Nexter SEO". Used for both
	 * dashboard branding and any frontend attribution so a rebranded site never leaks "Nexter".
	 *
	 * @return string
	 */
	public static function seo_brand_label() {
		if ( ( defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' ) ) && class_exists( 'Nxt_Options' ) ) {
			$wl = Nxt_Options::white_label();
			if ( is_array( $wl ) && ! empty( $wl['brand_name'] ) ) {
				$brand = trim( (string) $wl['brand_name'] );
				if ( '' !== $brand ) {
					// Always keep the word "SEO" so the module stays identifiable as an SEO tool,
					// but don't double it when the brand already contains it (e.g. "Rushit SEO"
					// stays "Rushit SEO"; "Nirmal" becomes "Nirmal SEO").
					return ( false !== stripos( $brand, 'seo' ) ) ? $brand : $brand . ' SEO';
				}
			}
		}
		return __( 'Nexter SEO', 'nexter-extension' );
	}

	/**
	 * White-label brand logo URL for the SEO dashboard (Pro), or '' to use the default Nexter logo.
	 *
	 * @return string
	 */
	public static function seo_brand_logo() {
		if ( ( defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' ) ) && class_exists( 'Nxt_Options' ) ) {
			$wl = Nxt_Options::white_label();
			if ( is_array( $wl ) && ! empty( $wl['theme_logo'] ) ) {
				return (string) $wl['theme_logo'];
			}
		}
		return '';
	}

	/**
	 * Current data-shape version of the stored options. Bump when the options array structure
	 * changes (renamed/removed keys, restructured nesting) and add a matching migration step in
	 * migrate_options() so existing installs upgrade in place instead of silently using stale data.
	 */
	const DATA_VERSION = 2;

	/** Transient key used to serialize concurrent settings writes (see rest_update_settings). */
	const SAVE_LOCK_KEY = 'nxt_seo_settings_save_lock';

	private static $instance = null;

	/** In-memory options cache — cleared on save so every request pays one DB read. */
	private static $options_cache = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'register_settings' ), 5 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 15 );
		// Seed defaults on first load so fresh installs immediately emit title/canonical/OG
		// tags without requiring the user to open settings and click Save.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_seed_default_options' ), 20 );
		$this->load_modules();
	}

	/**
	 * Persist the default options to the DB on first use.
	 *
	 * Fresh installs otherwise have no row for OPTION_NAME; while get_options()
	 * merges defaults in-memory, some code paths short-circuit when the option
	 * literally does not exist, producing empty <title>, canonical and OG output
	 * until the user clicks Save. Seeding here closes that gap.
	 *
	 * Also runs from the plugin activation hook so the autoload row exists on
	 * the very first frontend request after activation.
	 */
	public static function maybe_seed_default_options() {
		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			return;
		}
		update_option( self::OPTION_NAME, self::default_options(), true );
		self::flush_options_cache();
	}

	/**
	 * Load Content SEO sub-modules.
	 */
	private function load_modules() {
		$dir   = dirname( __FILE__ );
		$files = array(
			'class-seo-settings.php',
			'class-seo-title.php',
			'class-seo-description.php',
			'class-seo-schema.php',
			'class-seo-sitemap.php',
			'class-seo-indexing.php',
			'class-seo-image.php',
			'class-seo-archives.php',
			'class-seo-robots.php',
			'class-seo-canonical.php',
			'class-seo-social-meta.php',
			'class-redirection.php',
			'class-404-monitor.php',
			'class-seo-llms.php',
		);
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		if ( class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			Nexter_Content_SEO_Redirection::init();
		}
		if ( class_exists( 'Nexter_Content_SEO_404_Monitor' ) ) {
			Nexter_Content_SEO_404_Monitor::init();
		}
		Nexter_Content_SEO_Image::init();
		Nexter_Content_SEO_Image::register_frontend_content_hooks();
		add_action( 'template_redirect', array( 'Nexter_Content_SEO_Image', 'maybe_redirect_attachment' ) );
		add_filter( 'pre_handle_404', array( 'Nexter_Content_SEO_Archives', 'maybe_redirect_archives' ), 10, 2 );
		// Priority 0 so we short-circuit the empty-feed wp_die() in WP core before
		// any other template_redirect listener can trigger it.
		add_action( 'template_redirect', array( 'Nexter_Content_SEO_Archives', 'maybe_serve_empty_feed' ), 0 );
		Nexter_Content_SEO_Sitemap::init();
		Nexter_Content_SEO_Indexing::init();
		if ( class_exists( 'Nexter_Content_SEO_LLMs' ) ) {
			Nexter_Content_SEO_LLMs::init();
		}
		Nexter_Content_SEO_Robots::init();
		Nexter_Content_SEO_Canonical::init();
		Nexter_Content_SEO_Title::init();
		Nexter_Content_SEO_Description::init();
		Nexter_Content_SEO_Social_Meta::init();
		Nexter_Content_SEO_Schema::init();
		self::register_advanced_crawl_hooks();

		// Run any pending options data-shape migrations early on EVERY request (init, priority 1 —
		// before register_settings at 5 and well before wp_head), not just admin_init. A key rename
		// like search_*_template → meta_*_template is copied old→new here; if this only ran in the
		// admin, a frontend request between the plugin update and the first wp-admin page load would
		// render the DEFAULT template instead of the site's customized one. Cheap early-return once
		// the stored data is already at the current DATA_VERSION.
		add_action( 'init', array( __CLASS__, 'migrate_options' ), 1 );

		$audit_dir = $dir . '/audit';
		if ( \is_dir( $audit_dir ) ) {
			$audit_main = $audit_dir . '/class-audit.php';
			$audit_ajax = $audit_dir . '/class-audit-ajax.php';
			if ( \file_exists( $audit_main ) ) {
				require_once $audit_main;
			}
			if ( \file_exists( $audit_ajax ) ) {
				require_once $audit_ajax;
				\NexterSEO\Audit\Ajax::init();
			}
			if ( \class_exists( '\NexterSEO\Audit\Engine' ) ) {
				\add_filter( 'cron_schedules', array( '\NexterSEO\Audit\Engine', 'filter_cron_schedules' ) );
				\add_action( \NexterSEO\Audit\Engine::CRON_HOOK, array( '\NexterSEO\Audit\Engine', 'cron_run' ) );
				// Fallback for hosts with WP-Cron disabled (DISABLE_WP_CRON): trigger the overdue
				// recurring audit on an admin request behind a transient lock.
				if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
					\add_action( 'admin_init', array( '\NexterSEO\Audit\Engine', 'maybe_run_due_cron' ) );
				}
			}
		}
	}

	/**
	 * Wire the Advanced-tab crawl-optimization toggles that operate on front-end markup
	 * and comments (the robots-meta toggles no_snippet / no_image_index are handled inside
	 * Nexter_Content_SEO_Robots::filter_wp_robots). Each hook is registered unconditionally
	 * and re-reads the option at run time so a settings change takes effect without reload.
	 */
	private static function register_advanced_crawl_hooks() {
		// rel="prev" / rel="next" pagination signals on paged archives and the blog index.
		add_action( 'wp_head', array( __CLASS__, 'print_pagination_rel_links' ), 1 );

		// Google Analytics (gtag.js) — the enable_ga / ga_measurement_id settings previously
		// stored but never emitted any tag.
		add_action( 'wp_head', array( __CLASS__, 'output_google_analytics' ), 20 );

		// Strip the legacy `hentry` microformat class from post_class output.
		add_filter( 'post_class', array( __CLASS__, 'filter_remove_hentry_class' ) );

		// Remove the "Website" URL field from the comment form.
		add_filter( 'comment_form_default_fields', array( __CLASS__, 'filter_comment_form_fields' ) );

		// Drop the linked author URL from comment author output (returns plain name).
		add_filter( 'get_comment_author_url', array( __CLASS__, 'filter_comment_author_url' ), 999 );

		// Add rel="nofollow" to links inside comment bodies and to the comment author link.
		add_filter( 'comment_text', array( __CLASS__, 'filter_nofollow_comment_links' ), 20 );
		add_filter( 'get_comment_author_link', array( __CLASS__, 'filter_nofollow_comment_links' ), 20 );

		// 301 legacy ?replytocom= URLs to the clean comment anchor (avoids crawl-budget waste
		// on the duplicate reply URLs) and keep the query var out of freshly rendered links.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_replytocom' ) );
		add_filter( 'comment_reply_link', array( __CLASS__, 'filter_strip_replytocom' ) );
	}

	/**
	 * Output rel="prev"/rel="next" for paginated archive/blog-index views when enabled.
	 */
	public static function print_pagination_rel_links() {
		if ( empty( self::get_options( 'pagination_signals' ) ) || is_singular() ) {
			return;
		}
		if ( ! ( is_home() || is_archive() || is_search() ) ) {
			return;
		}
		global $wp_query;
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$max   = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;
		if ( $paged > 1 ) {
			$prev = get_pagenum_link( $paged - 1 );
			if ( $prev ) {
				echo '<link rel="prev" href="' . esc_url( $prev ) . '" />' . "\n";
			}
		}
		if ( $max && $paged < $max ) {
			$next = get_pagenum_link( $paged + 1 );
			if ( $next ) {
				echo '<link rel="next" href="' . esc_url( $next ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Remove the `hentry` class from post_class() output when enabled.
	 *
	 * @param string[] $classes Post classes.
	 * @return string[]
	 */
	public static function filter_remove_hentry_class( $classes ) {
		if ( empty( self::get_options( 'remove_hentry' ) ) || ! is_array( $classes ) ) {
			return $classes;
		}
		return array_values( array_diff( $classes, array( 'hentry' ) ) );
	}

	/**
	 * Remove the URL/website field from the comment form when enabled.
	 *
	 * @param array $fields Comment form default fields.
	 * @return array
	 */
	public static function filter_comment_form_fields( $fields ) {
		if ( empty( self::get_options( 'remove_website_field' ) ) || ! is_array( $fields ) ) {
			return $fields;
		}
		unset( $fields['url'] );
		return $fields;
	}

	/**
	 * Emit the Google Analytics gtag.js snippet in the head when enabled and a valid measurement
	 * id is set. Supports GA4 (G-), Google Tag (GT-), Universal (UA-), Ads (AW-), and DoubleClick
	 * (DC-) ids. Skipped in admin/feeds.
	 */
	public static function output_google_analytics() {
		if ( is_admin() || is_feed() ) {
			return;
		}
		$options = self::get_options();
		if ( empty( $options['enable_ga'] ) ) {
			return;
		}
		$id = isset( $options['ga_measurement_id'] ) ? trim( (string) $options['ga_measurement_id'] ) : '';
		if ( '' === $id || ! preg_match( '/^(G|GT|UA|AW|DC)-[A-Z0-9\-]+$/i', $id ) ) {
			return;
		}
		$id = esc_attr( $id );
		echo "\n<!-- " . esc_html( self::seo_brand_label() ) . ': ' . esc_html__( 'Google Analytics', 'nexter-extension' ) . " -->\n";
		echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>' . "\n";
		echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . $id . '");</script>' . "\n";
	}

	/**
	 * Blank out the comment author URL when enabled (WP then renders the name unlinked).
	 *
	 * @param string $url Comment author URL.
	 * @return string
	 */
	public static function filter_comment_author_url( $url ) {
		if ( empty( self::get_options( 'remove_comment_author_url' ) ) ) {
			return $url;
		}
		return '';
	}

	/**
	 * Add rel="nofollow ugc" to any anchor in the given comment HTML when enabled.
	 *
	 * @param string $html Comment HTML (body or author link).
	 * @return string
	 */
	public static function filter_nofollow_comment_links( $html ) {
		if ( empty( self::get_options( 'nofollow_comments' ) ) || ! is_string( $html ) || false === strpos( $html, '<a ' ) ) {
			return $html;
		}
		return preg_replace_callback(
			'/<a\s([^>]+)>/i',
			static function ( $m ) {
				$attrs = $m[1];
				if ( preg_match( '/\brel\s*=\s*("[^"]*"|\'[^\']*\')/i', $attrs, $rm ) ) {
					if ( false !== stripos( $rm[0], 'nofollow' ) ) {
						return $m[0];
					}
					$quote    = substr( $rm[1], 0, 1 );
					$existing = trim( substr( $rm[1], 1, -1 ) );
					$new_rel  = $quote . trim( $existing . ' nofollow ugc' ) . $quote;
					$attrs    = str_replace( $rm[0], 'rel=' . $new_rel, $attrs );
					return '<a ' . $attrs . '>';
				}
				return '<a ' . trim( $attrs ) . ' rel="nofollow ugc">';
			},
			$html
		);
	}

	/**
	 * 301-redirect legacy ?replytocom= URLs to the clean comment anchor when enabled.
	 */
	public static function maybe_redirect_replytocom() {
		if ( empty( self::get_options( 'remove_replytocom' ) ) || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		if ( ! isset( $_GET['replytocom'] ) || ! is_singular() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$comment_id = absint( wp_unslash( $_GET['replytocom'] ) );
		$permalink  = get_permalink();
		if ( ! $permalink ) {
			return;
		}
		$target = $comment_id ? $permalink . '#comment-' . $comment_id : $permalink;
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Strip the ?replytocom query arg from freshly rendered reply links when enabled.
	 *
	 * @param string $link Reply link HTML.
	 * @return string
	 */
	public static function filter_strip_replytocom( $link ) {
		if ( empty( self::get_options( 'remove_replytocom' ) ) || ! is_string( $link ) ) {
			return $link;
		}
		return preg_replace( '/(\?|&#038;|&amp;|&)replytocom=\d+/i', '', $link );
	}

	/**
	 * Register global SEO option.
	 */
	public function register_settings() {
		register_setting(
			'nexter_content_seo',
			self::OPTION_NAME,
			array(
				'type'              => 'object',
				'description'       => __( 'Nexter Content SEO global settings', 'nexter-extension' ),
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize options before save.
	 *
	 * @param array|mixed $value Raw option value.
	 * @return array
	 */
	/**
	 * Upgrade stored options in place when their data-shape version is behind DATA_VERSION.
	 * Provides the migration seam so future structural changes (renamed keys, restructured
	 * nesting) don't leave existing installs on stale data until a manual re-save. No-op once the
	 * stored version matches; safe to call on every admin load.
	 */
	public static function migrate_options() {
		$opts = get_option( self::OPTION_NAME, false );
		if ( false === $opts || ! is_array( $opts ) ) {
			return; // Nothing stored yet — fresh installs are seeded at DATA_VERSION.
		}
		$from = isset( $opts['_data_version'] ) ? (int) $opts['_data_version'] : 0;
		if ( $from >= self::DATA_VERSION ) {
			return;
		}
		/**
		 * Apply versioned migrations. Each block transforms the array from version N-1 to N.
		 * Example for a future v3:
		 *   if ( $from < 3 ) { $opts['new_key'] = $opts['old_key'] ?? ''; unset( $opts['old_key'] ); }
		 */
		// v2: the singular-page SERP title/description templates were renamed
		// search_*_template → meta_*_template. Copy old → new so existing installs keep their
		// customized templates. The old keys are intentionally left in place for safety (readers
		// still fall back to them); do NOT delete them here.
		if ( $from < 2 ) {
			if ( empty( $opts['meta_title_template'] ) && ! empty( $opts['search_title_template'] ) ) {
				$opts['meta_title_template'] = $opts['search_title_template'];
			}
			if ( empty( $opts['meta_description_template'] ) && ! empty( $opts['search_description_template'] ) ) {
				$opts['meta_description_template'] = $opts['search_description_template'];
			}
		}
		$opts = (array) apply_filters( 'nexter_content_seo_migrate_options', $opts, $from, self::DATA_VERSION );

		$opts['_data_version'] = self::DATA_VERSION;
		update_option( self::OPTION_NAME, $opts, true );
		self::flush_options_cache();
	}

	public function sanitize_options( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		// Run the same field-level sanitizers used by the REST save path so options persisted via
		// the Settings API / options.php are validated too (previously this was a no-op passthrough
		// that stored whatever was submitted).
		return self::sanitize_full_options( $value );
	}

	/**
	 * Single source of truth for full-options sanitization. Every persistence path — the Settings
	 * API sanitize_callback (sanitize_options), the REST save (rest_update_settings) and settings
	 * import (apply_global_import) — routes through this, so a sanitizer added here applies
	 * everywhere at once. The three sites previously duplicated the identical 8-step sequence and
	 * could silently diverge if one was updated without the others.
	 *
	 * @param array $value Raw options.
	 * @return array Sanitized options.
	 */
	public static function sanitize_full_options( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$value = self::sanitize_social_settings( $value );
		$value = self::sanitize_homepage_settings( $value );
		$value = self::sanitize_robots_settings( $value );
		$value = self::sanitize_robots_txt_custom( $value );
		$value = self::sanitize_sitemap_settings( $value );
		$value = self::sanitize_image_seo_settings( $value );
		$value = self::sanitize_indexing_settings( $value );
		$value = self::sanitize_llms_settings( $value );
		return $value;
	}

	/**
	 * Get all SEO options (with defaults).
	 *
	 * @return array
	 */
	public static function get_options( $option = '' ) {
		if ( null === self::$options_cache ) {
			$saved               = get_option( self::OPTION_NAME, array() );
			$defaults            = apply_filters( 'nexter_content_seo_default_options', self::default_options() );
			self::$options_cache = wp_parse_args( $saved, $defaults );
		}
		if ( $option ) {
			// Under WP_DEBUG, surface a request for a key that isn't a known option (a typo, or a
			// key renamed/removed in a later version) — otherwise it returns '' indistinguishably
			// from a legitimately-empty value and silently masks the broken caller. Production
			// behavior is unchanged (still returns '').
			if ( ! array_key_exists( $option, self::$options_cache ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Unknown Content SEO option key "%s".', $option ) ), '4.7.2' );
			}
			return isset( self::$options_cache[ $option ] ) ? self::$options_cache[ $option ] : '';
		}
		return self::$options_cache;
	}

	/**
	 * Flush the in-memory options cache (called after every update_option save).
	 */
	public static function flush_options_cache() {
		self::$options_cache = null;
	}

	/**
	 * Default option keys and values.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			// Data-shape version stamp for the migration layer (see migrate_options()).
			'_data_version'                 => self::DATA_VERSION,
			// General. (Renamed from search_*_template in DATA_VERSION 2; the old keys are the
			// singular-page SERP title/description templates, not the on-site search page.)
			'meta_title_template'           => '%post_title% - %site_name%',
			'meta_description_template'     => '%post_excerpt%',
			// Social.
			'default_social_image'          => '',
			'default_social_image_filename' => '',
			'default_social_image_filesize' => '',
			'facebook_page_url'             => '',
			'facebook_author_url'           => '',
			'twitter_card_layout'           => 'summary_large_image',
			'twitter_site'                  => '',
			'twitter_author'                => '',
			'linkedin_url'                  => '',
			'instagram_url'                 => '',
			'youtube_url'                   => '',
			'pinterest_url'                 => '',
			'tiktok_url'                    => '',
			'whatsapp_url'                  => '',
			'telegram_url'                  => '',
			'yelp_url'                      => '',
			'bluesky_url'                   => '',
			// Homepage SEO. Used directly for the blog-index ("Your latest posts") case,
			// where there is no front-page post to attach the Nexter SEO meta box to. For a
			// static front page the per-post meta box still takes precedence; these act as a
			// fallback. Title/description support template tokens (%site_name%, %tagline%, …).
			'home_title'                    => '',
			'home_description'              => '',
			'home_og_title'                 => '',
			'home_og_description'           => '',
			'home_og_image'                 => '',
			'home_og_image_id'              => 0,
			// Archives. Enabled by default — author/date archives duplicate blog content
			// and harm SEO on single-author sites (matches RankMath / Yoast defaults).
			'disable_author_archives'       => true,
			'disable_date_archives'         => true,
			// Category/tag archives are useful navigation by default, so opt-in (false).
			'disable_category_archives'     => false,
			'disable_tag_archives'          => false,
			'redirect_archives_to_home'     => true,
			// Archive title/description templates. Use the Term Title / Term Description tokens
			// (in the variable dropdown). On term archives they resolve to the term name/
			// description; on author/date/post-type archives they fall back to WordPress's own
			// archive title/description — so all archive types get clean, type-appropriate output
			// instead of the post template (which produced artifacts like "Cat Name by | | 2026").
			'archive_title_template'        => '%term_title% - %site_name%',
			'archive_description_template'  => '%term_description%',
			// Sitemaps.
			'enable_xml_sitemap'            => true,
			'sitemap_include_images'        => true,
			// Attach a human-readable XSLT stylesheet to sitemap XML (opt-out).
			'sitemap_stylesheet'            => true,
			'enable_video_sitemap'          => false,
			'enable_html_sitemap'           => false,
			'enable_news_sitemap'           => false,
			'sitemap_exclude_post_types'    => array(),
			'sitemap_exclude_taxonomies'    => array(),
			// Robots (No Index / No Follow / No Archive) – slug => bool.
			'noindex_post_types'            => array(),
			'noindex_taxonomies'            => array(),
			'noindex_archives'              => array(),
			'nofollow_post_types'           => array(),
			'nofollow_taxonomies'           => array(),
			'nofollow_archives'             => array(),
			'noarchive_post_types'          => array(),
			'noarchive_taxonomies'          => array(),
			'noarchive_archives'            => array(),
			// Image SEO.
			'redirect_attachment_pages'     => true,
			'auto_alt_text'                 => false,
			// Instant Indexing (IndexNow).
			'enable_indexnow'               => false,
			'indexnow_exclude_types'        => array(),
			'indexnow_api_key'              => '',
			// Google Indexing API.
			'enable_google_indexing'        => false,
			'google_indexing_key'           => '',
			// Analytics.
			'enable_ga'                     => false,
			'ga_measurement_id'             => '',
			// Crawling.
			'remove_replytocom'             => true,
			'remove_noreferrer'             => false,
			'remove_hentry'                 => false,
			'remove_comment_author_url'     => false,
			'remove_website_field'          => false,
			'nofollow_comments'             => true,
			// Verification.
			'google_verification'           => '',
			'bing_verification'             => '',
			'pinterest_verification'        => '',
			'facebook_verification'         => '',
			// Schema.
			'schema_types'                  => array(),
			'disable_website_schema'        => false,
			// LLMs.txt.
			'enable_llms_txt'               => false,
			'llms_txt_include_homepage'     => true,
			'llms_txt_pages'                => array(),
			'llms_txt_posts_count'          => 20,
			'llms_txt_post_types'           => array( 'post' => true ),
			'llms_txt_taxonomies'           => array(),
			'llms_txt_terms_limit'          => 20,
			'llms_txt_respect_noindex'      => true,
			'llms_txt_cache_ttl'            => 24,
			'llms_txt_freshness_months'     => 0,
			// Advanced.
			'no_image_index'                => false,
			'no_snippet'                    => false,
			'pagination_signals'            => false,
			'noindex_attachments'           => true,
			// Robots.txt (empty = use WordPress default virtual file; non-empty replaces output via filter).
			'robots_txt_custom'             => '',
		);
	}

	/**
	 * Register REST routes for settings, schema, sitemap, indexing.
	 */
	public function register_rest_routes() {
		$permission = array( $this, 'rest_permission' );

		// GET/POST global settings.
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_settings' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_update_settings' ),
				'permission_callback' => $permission,
				'args'                => array(
					'settings' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		// Schema.
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Nexter_Content_SEO_Schema', 'rest_get_schema' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/schema',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Nexter_Content_SEO_Schema', 'rest_save_schema' ),
				'permission_callback' => $permission,
				'args'                => array(
					'schema' => array( 'type' => 'object' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/schema/conditions-markup',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Nexter_Content_SEO_Schema', 'rest_post_schema_conditions_markup' ),
				'permission_callback' => $permission,
			)
		);

		// Sitemap config (uses settings; separate route for sitemap actions if needed).
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/sitemap',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Nexter_Content_SEO_Sitemap', 'rest_get_sitemap' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/sitemap/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Nexter_Content_SEO_Sitemap', 'rest_regenerate_sitemap' ),
				'permission_callback' => $permission,
			)
		);

		// Indexing: GET status/config + POST bulk submit.
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/indexing',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( 'Nexter_Content_SEO_Indexing', 'rest_get_status' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( 'Nexter_Content_SEO_Indexing', 'rest_bulk_index' ),
					'permission_callback' => $permission,
					'args'                => array(
						'urls'   => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' )
					),
						'action' => array(
					'type' => 'string',
					'enum' => array( 'update', 'remove' )
					),
					),
				),
			)
		);

		// Indexing logs (list / clear).
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/indexing/logs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( 'Nexter_Content_SEO_Indexing', 'rest_get_logs' ),
					'permission_callback' => $permission,
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 5,
							'maximum' => 100,
						),
						'orderby'  => array(
							'type'    => 'string',
							'default' => 'time',
							'enum'    => array( 'time', 'url', 'response' ),
						),
						'order'    => array(
							'type'    => 'string',
							'default' => 'desc',
							'enum'    => array( 'asc', 'desc' ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( 'Nexter_Content_SEO_Indexing', 'rest_clear_logs' ),
					'permission_callback' => $permission,
				),
			)
		);

		// Import / Export bundle (JSON file).
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import-export/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_export_seo_bundle' ),
				'permission_callback' => $permission,
				'args'                => array(
					'include' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import-export/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_seo_bundle' ),
				'permission_callback' => $permission,
				'args'                => array(
					'bundle' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		// Site SEO Audit.
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/audit/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_audit_run' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/audit/last',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_audit_last' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/audit/fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_audit_fix' ),
				'permission_callback' => $permission,
				'args'                => array(
					'issue_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/audit/schedule',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_audit_get_schedule' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_audit_save_schedule' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/image/bulk-alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_bulk_image_alt' ),
				'permission_callback' => $permission,
				'args'                => array(
					'limit' => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 50,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_dashboard_summary' ),
				'permission_callback' => $permission,
			)
		);

		// 404 monitoring.
		if ( class_exists( 'Nexter_Content_SEO_404_Monitor' ) ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/seo/404-log',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( 'Nexter_Content_SEO_404_Monitor', 'rest_get' ),
						'permission_callback' => $permission,
						'args'                => array(
							'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1
						),
							// Allow loading the full log in one request (default stays 20). The admin UI
							// fetches everything once and filters/paginates client-side; fetch_rows still
							// clamps to MAX_ROWS, which equals the table's hard row cap.
							'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => Nexter_Content_SEO_404_Monitor::MAX_ROWS
						),
							'orderby'  => array(
						'type'    => 'string',
						'default' => 'last_seen',
						'enum'    => array( 'last_seen', 'first_seen', 'hits', 'url' )
						),
							'order'    => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' )
						),
							'search'   => array(
						'type'    => 'string',
						'default' => ''
						),
						),
					),
					array(
						'methods'             => 'DELETE',
						'callback'            => array( 'Nexter_Content_SEO_404_Monitor', 'rest_clear' ),
						'permission_callback' => $permission,
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/seo/404-log/settings',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( 'Nexter_Content_SEO_404_Monitor', 'rest_get_settings' ),
						'permission_callback' => $permission,
					),
					array(
						'methods'             => 'PATCH',
						'callback'            => array( 'Nexter_Content_SEO_404_Monitor', 'rest_patch_settings' ),
						'permission_callback' => $permission,
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/seo/404-log/(?P<id>\d+)',
				array(
					'methods'             => 'DELETE',
					'callback'            => array( 'Nexter_Content_SEO_404_Monitor', 'rest_delete' ),
					'permission_callback' => $permission,
					'args'                => array(
						'id' => array(
				'type'     => 'integer',
				'required' => true
					),
					),
				)
			);
		}

		// Link redirection (301/302/307/308 rules + auto switch).
		if ( class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/redirection',
				array(
					'methods'             => 'GET',
					'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_get' ),
					'permission_callback' => $permission,
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/redirection/settings',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_get_settings' ),
						'permission_callback' => $permission,
					),
					array(
						'methods'             => 'PATCH',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_patch_settings' ),
						'permission_callback' => $permission,
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/redirection/rules',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_get_rules' ),
						'permission_callback' => $permission,
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_post_rule' ),
						'permission_callback' => $permission,
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/redirection/rules/(?P<id>[a-zA-Z0-9_-]+)',
				array(
					array(
						'methods'             => 'PUT',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_put_rule' ),
						'permission_callback' => $permission,
					),
					array(
						'methods'             => 'PATCH',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_patch_rule' ),
						'permission_callback' => $permission,
					),
					array(
						'methods'             => 'DELETE',
						'callback'            => array( 'Nexter_Content_SEO_Redirection', 'rest_delete_rule' ),
						'permission_callback' => $permission,
					),
				)
			);
		}
	}

	/**
	 * REST permission: manage_options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function rest_permission( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_get_settings( $request ) {
		$options = self::get_options();
		$data    = array_merge(
			$options,
			array(
			'preview_data'           => Nexter_Content_SEO_Settings::get_preview_data(),
			'sitemap_url'            => Nexter_Content_SEO_Sitemap::get_sitemap_url(),
			'sitemap_video_url'      => Nexter_Content_SEO_Sitemap::get_video_sitemap_url(),
			'sitemap_news_url'       => Nexter_Content_SEO_Sitemap::get_news_sitemap_url(),
			'sitemap_html_url'       => Nexter_Content_SEO_Sitemap::get_html_sitemap_url(),
			'content_seo_is_pro'     => self::content_seo_is_pro_active(),
			'robots_txt_placeholder' => Nexter_Content_SEO_Robots::get_robots_txt_placeholder(),
			'robots_txt_url'         => home_url( '/robots.txt' ),
			'blog_public'            => get_option( 'blog_public' ),
			'show_on_front'          => get_option( 'show_on_front' ),
			) 
		);
		return rest_ensure_response( array( 'data' => $data ) );
	}

	/**
	 * POST update settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_update_settings( $request ) {
		$settings = $request->get_param( 'settings' );
		if ( ! is_array( $settings ) ) {
			// Use WP core's canonical validation error code for consistency with the REST API.
			return new WP_Error( 'rest_invalid_param', __( 'Invalid settings.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		unset( $settings['indexnow_api_key'] );
		// Drop per-type template maps if they were accidentally submitted.
		unset( $settings['search_title_templates_by_type'], $settings['search_description_templates_by_type'] );

		// Serialize concurrent saves so two simultaneous submissions can't lost-update each other's
		// unrelated keys (classic read-modify-write race). wp_cache_add() is an atomic add-if-absent
		// (returns false when the key already exists) on a persistent object cache (Redis/Memcached),
		// so it closes the TOCTOU window the previous get_transient()+set_transient() pair had, where
		// two contenders could both read "unlocked" before either wrote. Without a persistent cache
		// it degrades to a best-effort lock; the freshest-read-inside-lock below limits damage either
		// way. Bounded spin, then proceed regardless so a stale lock can never block saving.
		$lock_group = 'nexter_content_seo';
		$have_lock  = false;
		for ( $i = 0; $i < 20; $i++ ) {
			if ( wp_cache_add( self::SAVE_LOCK_KEY, 1, $lock_group, 10 ) ) {
				$have_lock = true;
				break;
			}
			usleep( 100000 ); // 100ms.
		}
		// Read the freshest stored options INSIDE the lock (bypass the in-request cache) so a save
		// that committed while we were waiting isn't clobbered — only the submitted keys change.
		self::flush_options_cache();
		$current = self::get_options();
		$merged  = array_merge( $current, $settings );
		$merged  = array_diff_key(
			$merged,
			array(
				'preview_data'           => true,
				'content_seo_is_pro'     => true,
				'sitemap_url'            => true,
				'sitemap_video_url'      => true,
				'sitemap_news_url'       => true,
				'sitemap_html_url'       => true,
				'robots_txt_url'         => true,
				'robots_txt_placeholder' => true,
				'blog_public'            => true,
				'show_on_front'          => true,
			)
		);
		$merged  = self::sanitize_full_options( $merged );
		update_option( self::OPTION_NAME, $merged );
		self::flush_options_cache();
		if ( $have_lock ) {
			wp_cache_delete( self::SAVE_LOCK_KEY, $lock_group );
		}
		if ( class_exists( 'Nexter_Content_SEO_LLMs' ) ) {
			Nexter_Content_SEO_LLMs::clear_cache();
		}

		// If any sitemap-enable toggle changed, schedule a rewrite-rules flush on the next
		// init — by then our sitemap rules are registered and WP-core sitemaps are disabled
		// with the new value, so /sitemap.xml resolves as XML immediately and the stale
		// WP-core "sitemap=index" rewrite rule is dropped. Avoids the manual "Regenerate" step.
		$sitemap_enable_keys = array( 'enable_xml_sitemap', 'enable_video_sitemap', 'enable_news_sitemap', 'enable_html_sitemap' );
		foreach ( $sitemap_enable_keys as $sk ) {
			if ( ( ! empty( $current[ $sk ] ) ) !== ( ! empty( $merged[ $sk ] ) ) ) {
				update_option( 'nexter_content_seo_flush_rewrite', 1, false );
				break;
			}
		}

		return rest_ensure_response( array( 'data' => array( 'saved' => true ) ) );
	}

	/**
	 * REST: bulk-generate alt text for existing images that have none. Processes one batch per
	 * request; the client loops until `done` is true.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_bulk_image_alt( $request ) {
		if ( ! class_exists( 'Nexter_Content_SEO_Image' ) ) {
			// 501 Not Implemented — the feature isn't wired up in this build (not a server error/500).
			// Image SEO is a free module, so name the requirement rather than a bare "unavailable".
			return new WP_Error(
				'nexter_seo_image_unavailable',
				__( 'The Image SEO module is not available. Make sure the Nexter Extension SEO module is enabled and updated to a version that includes Image SEO.', 'nexter-extension' ),
				array( 'status' => 501 )
			);
		}
		$limit  = (int) $request->get_param( 'limit' );
		$result = Nexter_Content_SEO_Image::bulk_fill_missing_alt( $limit ?: 50 );
		return rest_ensure_response( array( 'data' => $result ) );
	}

	/**
	 * Keys stored in main SEO options that are grouped as “Required resources” for export/import (API keys, verification tags).
	 *
	 * @return string[]
	 */
	public static function get_required_resource_option_keys() {
		return array(
			'indexnow_api_key',
			'google_indexing_key',
			'google_verification',
			'bing_verification',
			'pinterest_verification',
			'facebook_verification',
		);
	}

	/**
	 * Required-resource keys that are secret credentials, not public verification tags.
	 * These are withheld from exports unless the caller explicitly opts in — and the Google
	 * service-account private key is never exported under any option (see export slice).
	 *
	 * @return string[]
	 */
	public static function get_secret_resource_keys() {
		return array(
			'indexnow_api_key',
			'google_indexing_key',
		);
	}

	/**
	 * Keys merged into REST GET settings that must never be written from import/export global slice.
	 *
	 * @return array<string, bool>
	 */
	private static function get_settings_virtual_keys_map() {
		return array(
			'preview_data'           => true,
			'content_seo_is_pro'     => true,
			'sitemap_url'            => true,
			'sitemap_video_url'      => true,
			'sitemap_news_url'       => true,
			'sitemap_html_url'       => true,
			'robots_txt_url'         => true,
			'robots_txt_placeholder' => true,
		);
	}

	/**
	 * Global settings slice for export (full options minus required-resource keys).
	 *
	 * @return array
	 */
	private function build_global_export_slice() {
		$full = self::get_options();
		foreach ( self::get_required_resource_option_keys() as $key ) {
			unset( $full[ $key ] );
		}
		return $full;
	}

	/**
	 * Required-resource slice for export.
	 *
	 * @return array
	 */
	private function build_required_resources_export_slice( $include_secrets = false ) {
		$full    = self::get_options();
		$secrets = array_flip( self::get_secret_resource_keys() );
		$out     = array();
		foreach ( self::get_required_resource_option_keys() as $key ) {
			if ( ! array_key_exists( $key, $full ) ) {
				continue;
			}
			// The Google service-account private key grants Indexing API access to the whole
			// domain — it is NEVER written to a portable export, regardless of opt-in.
			if ( 'google_indexing_key' === $key ) {
				continue;
			}
			// Remaining secret credentials (e.g. IndexNow key) are withheld unless the caller
			// explicitly opted in. Public verification meta tags export normally.
			if ( isset( $secrets[ $key ] ) && ! $include_secrets ) {
				continue;
			}
			$out[ $key ] = $full[ $key ];
		}
		return $out;
	}

	/**
	 * POST export JSON bundle (subset controlled by include toggles).
	 *
	 * @param WP_REST_Request $request Request with JSON { include: { global, schema, required_resources } }.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_export_seo_bundle( $request ) {
		$params  = $request->get_json_params();
		$include = ( is_array( $params ) && isset( $params['include'] ) && is_array( $params['include'] ) )
			? $params['include']
			: array();

		$global_on   = ! empty( $include['global'] );
		$schema_on   = ! empty( $include['schema'] );
		$required_on = ! empty( $include['required_resources'] );
		// Off-by-default opt-in: secret credentials (e.g. IndexNow key) are only exported when
		// the caller explicitly sets include.credentials. The Google private key is never
		// exported even then. A filter lets advanced setups force-disable secret export.
		$include_secrets = (bool) apply_filters( 'nexter_content_seo_export_include_secrets', ! empty( $include['credentials'] ) );

		if ( ! $global_on && ! $schema_on && ! $required_on ) {
			return new WP_Error(
				'nothing_to_export',
				__( 'Select at least one group to export.', 'nexter-extension' ),
				array( 'status' => 400 )
			);
		}

		$bundle = array(
			'version'     => 1,
			'source'      => 'nexter-content-seo',
			'exported_at' => gmdate( 'c' ),
		);

		if ( $global_on ) {
			$bundle['global'] = $this->build_global_export_slice();
		}
		if ( $schema_on ) {
			$schema           = get_option( Nexter_Content_SEO_Schema::OPTION_SCHEMA, array() );
			$bundle['schema'] = is_array( $schema ) ? $schema : array();
		}
		if ( $required_on ) {
			$bundle['required_resources'] = $this->build_required_resources_export_slice( $include_secrets );
			if ( ! $include_secrets ) {
				$bundle['required_resources_note'] = __( 'Secret credentials (IndexNow API key, Google Indexing private key) were omitted from this export for security. Re-enter them on the destination site.', 'nexter-extension' );
			}
		}

		return rest_ensure_response( array( 'data' => $bundle ) );
	}

	/**
	 * Merge global settings from import (no required-resource keys).
	 *
	 * @param array $settings Incoming global slice.
	 */
	private function apply_global_import( $settings ) {
		if ( ! is_array( $settings ) ) {
			return;
		}
		foreach ( self::get_required_resource_option_keys() as $key ) {
			unset( $settings[ $key ] );
		}
		$settings = array_diff_key( $settings, self::get_settings_virtual_keys_map() );

		$current = self::get_options();
		$merged  = array_merge( $current, $settings );
		$merged  = array_diff_key( $merged, self::get_settings_virtual_keys_map() );
		$merged  = self::sanitize_full_options( $merged );

		update_option( self::OPTION_NAME, $merged );
		self::flush_options_cache();
	}

	/**
	 * Merge required-resource keys from import.
	 *
	 * @param array $resources Incoming slice.
	 */
	private function apply_required_resources_import( $resources ) {
		if ( ! is_array( $resources ) ) {
			return array(
				'applied' => 0,
				'skipped' => 0,
			);
		}
		$allowed = array_flip( self::get_required_resource_option_keys() );
		$current = self::get_options();
		$applied = 0;
		$skipped = 0;
		foreach ( $resources as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$clean = self::validate_resource_value( $key, $value );
			if ( null === $clean || '' === $clean ) {
				++$skipped; // Malformed or empty value — never blindly stored.
				continue;
			}
			$current[ $key ] = $clean;
			++$applied;
		}
		$current = self::sanitize_indexing_settings( $current );

		update_option( self::OPTION_NAME, $current );
		self::flush_options_cache();

		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}

	/**
	 * Validate/sanitize one imported required-resource value by key. Returns the sanitized value,
	 * or null when the value is malformed (and must be skipped rather than stored).
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Incoming value.
	 * @return string|null
	 */
	public static function validate_resource_value( $key, $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		switch ( $key ) {
			case 'indexnow_api_key':
				$v = sanitize_text_field( $value );
				// Must match the canonical lowercase-hex format enforced by the serve/submit path
				// (Nexter_Content_SEO_Indexing::get_stored_valid_key). Previously this accepted
				// uppercase/hyphens, so an imported key passed validation but was then silently
				// discarded (and overwritten with a freshly generated key) at serve time.
				return preg_match( '/^[a-f0-9]{8,128}$/', $v ) ? $v : null;

			case 'google_indexing_key':
				$v          = sanitize_textarea_field( $value );
				$looks_json = ( false !== strpos( $v, 'private_key' ) && false !== strpos( $v, 'client_email' ) );
				$looks_pem  = ( false !== strpos( $v, 'BEGIN PRIVATE KEY' ) || false !== strpos( $v, 'BEGIN RSA PRIVATE KEY' ) );
				return ( $looks_json || $looks_pem ) ? $v : null;

			case 'google_verification':
			case 'bing_verification':
			case 'pinterest_verification':
			case 'facebook_verification':
				$v = sanitize_text_field( $value );
				// Verification tokens are short opaque strings: letters, digits, _-.= only.
				return preg_match( '/^[A-Za-z0-9_\-.=]{1,256}$/', $v ) ? $v : null;
		}
		return null;
	}

	/**
	 * POST import JSON bundle from another site.
	 *
	 * @param WP_REST_Request $request Request with JSON { bundle: { ... } } or bundle fields at root.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_import_seo_bundle( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$bundle = isset( $params['bundle'] ) && is_array( $params['bundle'] ) ? $params['bundle'] : $params;

		if ( ! is_array( $bundle ) || empty( $bundle ) ) {
			return new WP_Error(
				'invalid_bundle',
				__( 'Invalid or empty import data.', 'nexter-extension' ),
				array( 'status' => 400 )
			);
		}

		$has_payload = isset( $bundle['global'] ) || isset( $bundle['schema'] ) || isset( $bundle['required_resources'] );
		if ( ! $has_payload ) {
			return new WP_Error(
				'invalid_bundle',
				__( 'The file does not contain any importable SEO data.', 'nexter-extension' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $bundle['version'] ) && (int) $bundle['version'] !== 1 ) {
			return new WP_Error(
				'invalid_bundle',
				__( 'Unsupported export file version.', 'nexter-extension' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $bundle['source'] ) && is_string( $bundle['source'] ) && $bundle['source'] !== '' && $bundle['source'] !== 'nexter-content-seo' ) {
			return new WP_Error(
				'invalid_bundle',
				__( 'This JSON file is not a Nexter Content SEO export.', 'nexter-extension' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $bundle['global'] ) && is_array( $bundle['global'] ) ) {
			$this->apply_global_import( $bundle['global'] );
		}
		if ( isset( $bundle['schema'] ) && is_array( $bundle['schema'] ) ) {
			$clean = Nexter_Content_SEO_Schema::sanitize_schema_payload( $bundle['schema'] );
			update_option( Nexter_Content_SEO_Schema::OPTION_SCHEMA, $clean );
		}

		// Verification meta tags and API/indexing keys are security-sensitive: a crafted file
		// could hand domain-verification or indexing control to an attacker. They are applied
		// ONLY when the caller explicitly opts in (off by default), and each value is
		// format-validated before storage.
		$credentials_note = null;
		if ( isset( $bundle['required_resources'] ) && is_array( $bundle['required_resources'] ) ) {
			$apply_credentials = (bool) apply_filters(
				'nexter_content_seo_import_apply_credentials',
				( ! empty( $params['apply_credentials'] ) || ! empty( $bundle['apply_credentials'] ) )
			);
			if ( $apply_credentials ) {
				$res              = $this->apply_required_resources_import( $bundle['required_resources'] );
				$credentials_note = sprintf(
					/* translators: 1: applied count, 2: skipped count */
					__( 'Imported %1$d credential/verification value(s); skipped %2$d invalid.', 'nexter-extension' ),
					(int) $res['applied'],
					(int) $res['skipped']
				);
			} else {
				$credentials_note = __( 'This file includes verification/API-key values. They were NOT applied. Re-run the import with the “Import credentials” option enabled to review and apply them.', 'nexter-extension' );
			}
		}

		return rest_ensure_response(
			array(
				'data' => array(
					'imported'         => true,
					'credentials_note' => $credentials_note,
				),
			)
		);
	}

	/**
	 * Sanitize Home Page SEO fields (matches Homepage.jsx field keys). Title/description allow
	 * template tokens; OG image is a URL + attachment id.
	 *
	 * @param array $options Raw options array.
	 * @return array Sanitized options.
	 */
	public static function sanitize_homepage_settings( $options ) {
		foreach ( array( 'home_title', 'home_og_title' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = sanitize_text_field( (string) $options[ $key ] );
			}
		}
		foreach ( array( 'home_description', 'home_og_description' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = sanitize_textarea_field( (string) $options[ $key ] );
			}
		}
		if ( isset( $options['home_og_image'] ) ) {
			$val                      = trim( (string) $options['home_og_image'] );
			$options['home_og_image'] = $val ? esc_url_raw( $val ) : '';
		}
		if ( isset( $options['home_og_image_id'] ) ) {
			$options['home_og_image_id'] = absint( $options['home_og_image_id'] );
		}
		return $options;
	}

	/**
	 * Sanitize Social SEO fields (matches Social.jsx field keys).
	 *
	 * @param array $options Raw options array.
	 * @return array Sanitized options.
	 */
	public static function sanitize_social_settings( $options ) {
		$social_url_keys = array(
			'default_social_image',
			'facebook_page_url',
			'facebook_author_url',
			'linkedin_url',
			'instagram_url',
			'youtube_url',
			'pinterest_url',
			'tiktok_url',
			'whatsapp_url',
			'telegram_url',
			'yelp_url',
			'bluesky_url',
		);
		foreach ( $social_url_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$val = trim( (string) $options[ $key ] );
				// Reject protocol-relative ("//evil.com") and non-http(s) schemes before storing —
				// esc_url_raw() alone keeps "//host", which becomes an off-site link/open-redirect
				// vector when the value is later emitted as a sameAs / profile URL.
				if ( '' === $val || 0 === strpos( $val, '//' ) ) {
					$options[ $key ] = '';
					continue;
				}
				$options[ $key ] = esc_url_raw( $val, array( 'http', 'https' ) );
			}
		}

		$social_text_keys = array(
			'default_social_image_filename',
			'default_social_image_filesize',
			'twitter_site',
			'twitter_author',
		);
		foreach ( $social_text_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = sanitize_text_field( (string) $options[ $key ] );
			}
		}

		if ( isset( $options['twitter_card_layout'] ) ) {
			$allowed                        = array( 'summary', 'summary_large_image' );
			$options['twitter_card_layout'] = in_array( $options['twitter_card_layout'], $allowed, true )
				? $options['twitter_card_layout']
				: 'summary_large_image';
		}

		return $options;
	}

	/**
	 * Sanitize Robots (No Index / No Follow / No Archive) settings.
	 * Ensures slug => bool structure; unknown slugs are stripped.
	 *
	 * @param array $options Raw options array.
	 * @return array Sanitized options.
	 */
	public static function sanitize_robots_settings( $options ) {
		$keys             = array(
			'noindex_post_types',
		'noindex_taxonomies',
		'noindex_archives',
			'nofollow_post_types',
		'nofollow_taxonomies',
		'nofollow_archives',
			'noarchive_post_types',
		'noarchive_taxonomies',
		'noarchive_archives',
		);
		$valid_post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$valid_taxonomies = array_keys( get_taxonomies( array( 'public' => true ), 'names' ) );
		// Blanket archive keys plus the two blog-index contexts and CPT-archive blanket.
		$valid_archives = array( 'search', 'author', 'date', 'front', 'blog', 'post_type_archive' );
		// Per-CPT archive override keys: post_type_archive_{public_post_type}.
		foreach ( $valid_post_types as $pt ) {
			$valid_archives[] = 'post_type_archive_' . $pt;
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $options[ $key ] ) || ! is_array( $options[ $key ] ) ) {
				continue;
			}
			$sanitized = array();
			if ( strpos( $key, '_post_types' ) !== false ) {
				$valid = $valid_post_types;
			} elseif ( strpos( $key, '_taxonomies' ) !== false ) {
				$valid = $valid_taxonomies;
			} else {
				$valid = $valid_archives;
			}
			foreach ( $options[ $key ] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options[ $key ] = $sanitized;
		}

		return $options;
	}

	/**
	 * Sanitize custom robots.txt body (empty string = no override; WordPress virtual robots.txt is used).
	 *
	 * @param array $options Options array.
	 * @return array
	 */
	public static function sanitize_robots_txt_custom( $options ) {
		if ( ! is_array( $options ) || ! isset( $options['robots_txt_custom'] ) ) {
			return $options;
		}
		$raw = $options['robots_txt_custom'];
		if ( ! is_string( $raw ) ) {
			$options['robots_txt_custom'] = '';
			return $options;
		}
		$raw = str_replace( "\0", '', $raw );
		if ( strlen( $raw ) > 102400 ) {
			$raw = substr( $raw, 0, 102400 );
		}
		$normalized                   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$normalized                   = str_replace(
			array(
				'https://yourdomain.com/sitemap_index.xml',
				'http://yourdomain.com/sitemap_index.xml',
				'https://example.com/sitemap_index.xml',
				'http://example.com/sitemap_index.xml',
			),
			Nexter_Content_SEO_Sitemap::get_sitemap_url(),
			$normalized
		);
		$options['robots_txt_custom'] = ( trim( $normalized ) === '' ) ? '' : $normalized;
		return $options;
	}

	/**
	 * Sanitize Sitemap settings.
	 *
	 * @param array $options Raw options array.
	 * @return array Sanitized options.
	 */
	public static function sanitize_sitemap_settings( $options ) {
		$bool_keys = array(
			'enable_xml_sitemap',
			'sitemap_include_images',
			'sitemap_stylesheet',
			'enable_video_sitemap',
			'enable_html_sitemap',
			'enable_news_sitemap',
		);
		foreach ( $bool_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = ! empty( $options[ $key ] );
			}
		}

		$valid_post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$valid_taxonomies = array_keys( get_taxonomies( array( 'public' => true ), 'names' ) );

		if ( isset( $options['sitemap_exclude_post_types'] ) && is_array( $options['sitemap_exclude_post_types'] ) ) {
			$sanitized = array();
			foreach ( $options['sitemap_exclude_post_types'] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid_post_types, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options['sitemap_exclude_post_types'] = $sanitized;
		}

		if ( isset( $options['sitemap_exclude_taxonomies'] ) && is_array( $options['sitemap_exclude_taxonomies'] ) ) {
			$sanitized = array();
			foreach ( $options['sitemap_exclude_taxonomies'] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid_taxonomies, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options['sitemap_exclude_taxonomies'] = $sanitized;
		}

		return $options;
	}

	/**
	 * Sanitize Instant Indexing / Google Indexing fields.
	 *
	 * @param array $options Options array.
	 * @return array
	 */
	public static function sanitize_indexing_settings( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		foreach ( array( 'enable_indexnow', 'enable_google_indexing' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = ! empty( $options[ $key ] );
			}
		}
		foreach ( array( 'google_verification', 'bing_verification', 'pinterest_verification', 'facebook_verification' ) as $vk ) {
			if ( isset( $options[ $vk ] ) ) {
				$options[ $vk ] = self::sanitize_verification_meta_content( $options[ $vk ] );
			}
		}
		if ( isset( $options['indexnow_api_key'] ) ) {
			$key                         = sanitize_text_field( (string) $options['indexnow_api_key'] );
			$options['indexnow_api_key'] = strlen( $key ) > 128 ? substr( $key, 0, 128 ) : $key;
		}
		if ( isset( $options['google_indexing_key'] ) ) {
			$options['google_indexing_key'] = sanitize_textarea_field( (string) $options['google_indexing_key'] );
		}
		$valid_post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		if ( isset( $options['indexnow_exclude_types'] ) && is_array( $options['indexnow_exclude_types'] ) ) {
			$sanitized = array();
			foreach ( $options['indexnow_exclude_types'] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid_post_types, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options['indexnow_exclude_types'] = $sanitized;
		}
		return $options;
	}

	/**
	 * Sanitize meta-tag `content` values for search-engine site verification (stored tokens only).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sanitize_verification_meta_content( $raw ) {
		$raw = wp_unslash( (string) $raw );
		// Accept a full <meta ... content="TOKEN"> snippet — this is exactly what Meta Business
		// Manager, Google Search Console, Bing, and Pinterest hand you to copy. Extract the content
		// value BEFORE sanitize_text_field() strips the tag (which would otherwise leave an empty
		// token and silently break verification).
		if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
			$raw = $m[1];
		}
		$s = sanitize_text_field( $raw );
		if ( '' === $s ) {
			return '';
		}
		// Prevent misconfigured URLs from being printed as verification "tokens".
		if ( filter_var( $s, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		// Accept provider=value formats and keep only the token.
		if ( strpos( $s, '=' ) !== false && preg_match( '/^[a-z0-9_.-]+\s*=\s*(.+)$/i', $s, $m ) ) {
			$s = isset( $m[1] ) ? sanitize_text_field( (string) $m[1] ) : '';
		}
		if ( filter_var( $s, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		if ( strlen( $s ) > 512 ) {
			$s = substr( $s, 0, 512 );
		}
		return $s;
	}

	/**
	 * POST – run Site SEO Audit checks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_audit_run( $request ) {
		if ( ! class_exists( '\NexterSEO\Audit\Engine' ) ) {
			return new \WP_Error( 'audit_missing', __( 'Audit module not loaded.', 'nexter-extension' ), array( 'status' => 500 ) );
		}
		// Route through the same single-flight/debounce guard the AJAX handler uses
		// (NexterSEO\Audit\Ajax::run()) instead of calling $engine->run(true) synchronously. run()
		// performs many outbound HTTP probes and samples site content with no internal lock or
		// re-run interval, so repeated POSTs would each execute a full, expensive, network-bound
		// audit on a PHP worker — a self-inflicted DoS. request_async_run() queues the run in the
		// background and we return the last snapshot, keeping REST and AJAX behavior identical.
		try {
			$async = \NexterSEO\Audit\Engine::request_async_run();
			$last  = \NexterSEO\Audit\Engine::get_last_result();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
				error_log( 'Nexter SEO audit REST: ' . $e->getMessage() );
			}
			$detail = \wp_strip_all_tags( $e->getMessage() );
			return new \WP_Error(
				'audit_run_failed',
				__( 'Could not complete the SEO audit.', 'nexter-extension' ) . ( $detail ? ' ' . $detail : '' ),
				array( 'status' => 500 )
			);
		}
		return rest_ensure_response(
			array(
				'data'       => $last,
				'async'      => $async,
				'from_cache' => true,
				'message'    => __( 'Audit queued in background. Showing last available snapshot until the run completes.', 'nexter-extension' ),
			)
		);
	}

	/**
	 * GET – last stored audit snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_audit_last( $request ) {
		if ( ! class_exists( '\NexterSEO\Audit\Engine' ) ) {
			return new \WP_Error( 'audit_missing', __( 'Audit module not loaded.', 'nexter-extension' ), array( 'status' => 500 ) );
		}
		$last                 = \NexterSEO\Audit\Engine::get_last_result();
		$schedule             = \NexterSEO\Audit\Engine::get_schedule();
		$history              = \NexterSEO\Audit\Engine::get_history();
		$payload              = is_array( $last ) ? $last : array();
		$payload['scan_info'] = array(
			'audits_count' => count( $history ),
			'schedule'     => $schedule,
			'next_run_at'  => \NexterSEO\Audit\Engine::next_run_timestamp( $schedule ),
		);
		return rest_ensure_response( array( 'data' => $payload ) );
	}

	/**
	 * GET – auto-scan schedule + computed next-run timestamp.
	 */
	public function rest_audit_get_schedule( $request ) {
		if ( ! class_exists( '\NexterSEO\Audit\Engine' ) ) {
			return new \WP_Error( 'audit_missing', __( 'Audit module not loaded.', 'nexter-extension' ), array( 'status' => 500 ) );
		}
		$schedule = \NexterSEO\Audit\Engine::get_schedule();
		return rest_ensure_response(
			array(
			'data' => array(
				'schedule'    => $schedule,
				'next_run_at' => \NexterSEO\Audit\Engine::next_run_timestamp( $schedule ),
			 ),
			) 
		);
	}

	/**
	 * POST – save auto-scan schedule and reschedule cron.
	 */
	public function rest_audit_save_schedule( $request ) {
		if ( ! class_exists( '\NexterSEO\Audit\Engine' ) ) {
			return new \WP_Error( 'audit_missing', __( 'Audit module not loaded.', 'nexter-extension' ), array( 'status' => 500 ) );
		}
		$input    = (array) $request->get_json_params();
		$schedule = \NexterSEO\Audit\Engine::save_schedule( $input );
		return rest_ensure_response(
			array(
			'data'     => array(
				'schedule'    => $schedule,
				'next_run_at' => \NexterSEO\Audit\Engine::next_run_timestamp( $schedule ),
			 ),
			 'message' => __( 'Schedule saved.', 'nexter-extension' ),
			) 
		);
	}

	/**
	 * GET – aggregated Dashboard payload (audit + scan info + module statuses).
	 *
	 * @return WP_REST_Response
	 */
	public function rest_dashboard_summary( $request ) {
		$audit    = class_exists( '\NexterSEO\Audit\Engine' ) ? \NexterSEO\Audit\Engine::get_last_result() : null;
		$schedule = class_exists( '\NexterSEO\Audit\Engine' ) ? \NexterSEO\Audit\Engine::get_schedule() : array();
		$history  = class_exists( '\NexterSEO\Audit\Engine' ) ? \NexterSEO\Audit\Engine::get_history() : array();
		$next_run = class_exists( '\NexterSEO\Audit\Engine' ) ? \NexterSEO\Audit\Engine::next_run_timestamp( $schedule ) : null;

		return rest_ensure_response(
			array(
			'data' => array(
				'audit'           => is_array( $audit ) ? $audit : null,
				'scan_info'       => array(
					'audits_count' => count( $history ),
					'schedule'     => $schedule,
					'next_run_at'  => $next_run,
				),
				'module_statuses' => $this->collect_module_statuses( is_array( $audit ) ? $audit : array() ),
			 ),
			) 
		);
	}

	/**
	 * Build module status map for the rich dashboard cards.
	 *
	 * @param array $audit Last audit payload (may be empty).
	 * @return array<string, array>
	 */
	private function collect_module_statuses( array $audit ) {
		$opts = wp_parse_args( get_option( self::OPTION_NAME, array() ), self::default_options() );

		$out = array();

		// On-page: meta template, social, schema, image-seo, home, archives.
		$mt_tpl = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$md_tpl = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$out['meta-template'] = array(
			'state' => ( $mt_tpl !== '%post_title% - %site_name%' || $md_tpl !== '%post_excerpt%' ) ? 'active' : 'setup',
		);
		$social_set           = ! empty( $opts['default_social_image'] )
			|| ! empty( $opts['facebook_page_url'] ) || ! empty( $opts['twitter_site'] )
			|| ! empty( $opts['linkedin_url'] ) || ! empty( $opts['instagram_url'] )
			|| ! empty( $opts['youtube_url'] );
		$out['social']        = array( 'state' => $social_set ? 'active' : 'setup' );
		// Schema is "active" whenever it is actually emitting output — which it always is unless the
		// user explicitly disabled the site-identity schema. schema_types only holds CUSTOM builder
		// templates (default empty), so checking it alone wrongly showed "Setup" on sites that emit
		// the default Article/Product/WebSite JSON-LD.
		$out['schema'] = array(
			'state' => ( ! empty( $opts['schema_types'] ) || empty( $opts['disable_website_schema'] ) ) ? 'active' : 'setup',
		);

		$image_alt_check  = $this->find_audit_check( $audit, 'image_alt' );
		$alt_issues       = ( $image_alt_check && ( $image_alt_check['status'] ?? '' ) !== 'passed' )
			? (int) ( $image_alt_check['count'] ?? 0 )
			: 0;
		$out['image-seo'] = array(
			'state' => $alt_issues > 0 ? 'issues' : 'active',
			'count' => $alt_issues,
		);
		// Home Page: "active" once any home-specific field has been filled in (all default empty).
		$home_set         = ! empty( $opts['home_title'] )
			|| ! empty( $opts['home_description'] )
			|| ! empty( $opts['home_og_image'] );
		$out['home-page'] = array( 'state' => $home_set ? 'active' : 'setup' );

		// Archive Pages: "active" once either archive template differs from its default. NOTE: the
		// description template defaults to '%term_description%' (not empty), so we compare against
		// that default rather than using ! empty() — otherwise it would always read as configured.
		$archive_configured   = $opts['archive_title_template'] !== '%term_title% - %site_name%'
			|| $opts['archive_description_template'] !== '%term_description%';
		$out['archive-pages'] = array( 'state' => $archive_configured ? 'active' : 'setup' );

		// Technical.
		$out['sitemaps']            = array(
			'state' => ! empty( $opts['enable_xml_sitemap'] ) ? 'active' : 'off',
		);
		$robots_set                 = ! empty( $opts['noindex_post_types'] ) || ! empty( $opts['noindex_taxonomies'] )
			|| ! empty( $opts['nofollow_post_types'] ) || ! empty( $opts['nofollow_taxonomies'] )
			|| ! empty( $opts['noarchive_post_types'] ) || ! empty( $opts['noarchive_taxonomies'] );
		$out['robots-instructions'] = array( 'state' => $robots_set ? 'active' : 'default' );
		$out['robots-txt-editor']   = array( 'state' => ! empty( $opts['robots_txt_custom'] ) ? 'active' : 'default' );

		$indexing_on             = ! empty( $opts['enable_indexnow'] ) || ! empty( $opts['enable_google_indexing'] );
		$out['instant-indexing'] = array( 'state' => $indexing_on ? 'active' : 'setup' );
		$out['llms']             = array( 'state' => ! empty( $opts['enable_llms_txt'] ) ? 'active' : 'setup' );
		$out['validation']       = array( 'state' => 'ready' );

		// Redirects.
		$rule_rows                  = get_option( 'nexter_content_seo_redirect_rules', array() );
		$rule_count                 = is_array( $rule_rows ) ? count( $rule_rows ) : 0;
		$out['redirection-manager'] = array(
			'state' => $rule_count > 0 ? 'active' : 'setup',
			'count' => $rule_count,
		);

		$hits_404           = $this->count_404_hits();
		$out['404-monitor'] = array(
			'state' => $hits_404 > 0 ? 'active' : 'ready',
			'count' => $hits_404,
		);

		return $out;
	}

	/**
	 * @param array $audit Last audit payload.
	 * @param string $id    Check id to find.
	 * @return array|null
	 */
	private function find_audit_check( array $audit, $id ) {
		if ( empty( $audit['checks'] ) || ! is_array( $audit['checks'] ) ) {
			return null;
		}
		foreach ( $audit['checks'] as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Sum the hits column of the 404 log table, if it exists.
	 *
	 * @return int
	 */
	private function count_404_hits() {
		global $wpdb;
		if ( ! class_exists( 'Nexter_Content_SEO_404_Monitor' ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'nexter_seo_404_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$table}" );
		return (int) $total;
	}

	/**
	 * POST – apply automated fix for an audit issue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_audit_fix( $request ) {
		if ( ! class_exists( '\NexterSEO\Audit\Engine' ) ) {
			return new \WP_Error( 'audit_missing', __( 'Audit module not loaded.', 'nexter-extension' ), array( 'status' => 500 ) );
		}
		$issue  = sanitize_key( (string) $request->get_param( 'issue_id' ) );
		$engine = new \NexterSEO\Audit\Engine();
		$result = $engine->apply_fix( $issue );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'data' => $result ) );
	}

	/**
	 * Whether Nexter Pro is active (used for AI image features).
	 *
	 * @return bool
	 */
	public static function content_seo_is_pro_active() {
		// Accept BOTH Pro activation constants — mirrors every other Pro gate in the plugin
		// (seo_brand_label/logo, notices, dashboard data, bulk images, import/export). Without
		// TPGBP_VERSION, a site on the legacy white-label Pro path reported is_pro:false only in
		// Content SEO and hid Pro-only SEO features while being branded Pro everywhere else.
		return defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' );
	}

	/**
	 * Sanitize Image SEO toggles; enforce mutual exclusion and Pro rules.
	 *
	 * @param array $options Options array.
	 * @return array
	 */
	public static function sanitize_image_seo_settings( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		$legacy = array( 'redirect_attachment_to_file', 'auto_image_title', 'update_alt_existing' );
		foreach ( $legacy as $k ) {
			unset( $options[ $k ] );
		}

		$bool_keys = apply_filters(
			'nexter_content_seo_image_seo_bool_keys',
			array(
			'redirect_attachment_pages',
			'auto_alt_text',
			) 
		);
		foreach ( $bool_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = ! empty( $options[ $key ] );
			}
		}



		return $options;
	}

	/**
	 * Sanitize LLMs.txt settings.
	 *
	 * @param array $options Options array.
	 * @return array
	 */
	public static function sanitize_llms_settings( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		// Boolean toggles.
		foreach ( array( 'enable_llms_txt', 'llms_txt_include_homepage', 'llms_txt_respect_noindex' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ] = ! empty( $options[ $key ] );
			}
		}

		// Posts count: integer 1–100.
		if ( isset( $options['llms_txt_posts_count'] ) ) {
			$count                           = (int) $options['llms_txt_posts_count'];
			$options['llms_txt_posts_count'] = max( 1, min( 100, $count ) );
		}

		// Terms-per-taxonomy limit: 1–100.
		if ( isset( $options['llms_txt_terms_limit'] ) ) {
			$options['llms_txt_terms_limit'] = max( 1, min( 100, (int) $options['llms_txt_terms_limit'] ) );
		}

		// Cache TTL: 0–168 hours (0 = no cache).
		if ( isset( $options['llms_txt_cache_ttl'] ) ) {
			$options['llms_txt_cache_ttl'] = max( 0, min( 168, (int) $options['llms_txt_cache_ttl'] ) );
		}

		// Freshness window: 0–120 months (0 = no filter).
		if ( isset( $options['llms_txt_freshness_months'] ) ) {
			$options['llms_txt_freshness_months'] = max( 0, min( 120, (int) $options['llms_txt_freshness_months'] ) );
		}

		// Pages list: array of positive integers.
		if ( isset( $options['llms_txt_pages'] ) ) {
			if ( is_array( $options['llms_txt_pages'] ) ) {
				$options['llms_txt_pages'] = array_values(
					array_unique(
						array_filter(
							array_map( 'absint', $options['llms_txt_pages'] )
						)
					)
				);
			} else {
				$options['llms_txt_pages'] = array();
			}
		}

		// Post-types map: slug => bool, restricted to public post types.
		if ( isset( $options['llms_txt_post_types'] ) && is_array( $options['llms_txt_post_types'] ) ) {
			$valid_post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
			$sanitized        = array();
			foreach ( $options['llms_txt_post_types'] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid_post_types, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options['llms_txt_post_types'] = $sanitized;
		}

		// Taxonomies map: slug => bool, restricted to public taxonomies.
		if ( isset( $options['llms_txt_taxonomies'] ) && is_array( $options['llms_txt_taxonomies'] ) ) {
			$valid_taxonomies = array_keys( get_taxonomies( array( 'public' => true ), 'names' ) );
			$sanitized        = array();
			foreach ( $options['llms_txt_taxonomies'] as $slug => $val ) {
				if ( in_array( (string) $slug, $valid_taxonomies, true ) ) {
					$sanitized[ (string) $slug ] = ! empty( $val );
				}
			}
			$options['llms_txt_taxonomies'] = $sanitized;
		}

		return $options;
	}
}
