<?php
/**
 * Performance Tweaks Module
 * Handles: Disable emojis, embeds, dashicons, RSD link, wlwmanifest, shortlink,
 *          RSS feeds/links, self-pingbacks, password strength meter, defer CSS/JS,
 *          media infinite scroll
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-nxt-performance-head-tweaks.php';
require_once __DIR__ . '/class-nxt-performance-feed-tweaks.php';
require_once __DIR__ . '/class-nxt-performance-asset-tweaks.php';

class Nxt_Performance_Tweaks {
	/**
	 * Head and embed related tweaks.
	 *
	 * @var Nxt_Performance_Head_Tweaks
	 */
	private $head_tweaks;

	/**
	 * Feed and ping related tweaks.
	 *
	 * @var Nxt_Performance_Feed_Tweaks
	 */
	private $feed_tweaks;

	/**
	 * Frontend asset related tweaks.
	 *
	 * @var Nxt_Performance_Asset_Tweaks
	 */
	private $asset_tweaks;

	/**
	 * @param array $perf_option   nexter_site_performance option value.
	 * @param array $adv_perfor    advance-performance values array.
	 */
	public function __construct( $perf_option, $adv_perfor ) {
		$this->head_tweaks  = new Nxt_Performance_Head_Tweaks();
		$this->feed_tweaks  = new Nxt_Performance_Feed_Tweaks();
		$this->asset_tweaks = new Nxt_Performance_Asset_Tweaks();

		// Helper: check if a key is active (legacy flat array OR new advance-performance values).
		$is_active = function( $key ) use ( $perf_option, $adv_perfor ) {
			return in_array( $key, $perf_option, true )
				|| ( ! empty( $adv_perfor ) && in_array( $key, $adv_perfor, true ) );
		};

		/**
		 * Head/markup cleanup tweaks.
		 */

		/* Disable Emojis Scripts */
		if ( $is_active( 'disable_emoji_scripts' ) ) {
			$this->head_tweaks->disable_emojis();
		}

		/* Disable Embeds */
		if ( $is_active( 'disable_embeds' ) ) {
			add_action( 'init', [ $this->head_tweaks, 'disable_embeds' ], 9999 );
		}

		/* Media Infinite Scroll */
		if ( $is_active( 'media_infinite_scroll' ) ) {
			add_filter( 'media_library_infinite_scrolling', '__return_true' );
		}

		/**
		 * Frontend asset toggles.
		 */

		/* Disable DashIcons */
		if ( $is_active( 'disable_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', function() {
				if ( ! is_user_logged_in() ) {
					wp_dequeue_style( 'dashicons' );
					wp_deregister_style( 'dashicons' );
				}
			} );
		}

		/**
		 * WP discovery/link cleanup.
		 */

		/* Remove RSD Link */
		if ( $is_active( 'disable_rsd_link' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		/* Remove wlwmanifest Link */
		if ( $is_active( 'disable_wlwmanifest_link' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		/* Remove Shortlink Link */
		if ( $is_active( 'disable_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11, 0 );
		}

		/**
		 * Feed/ping behavior.
		 */

		/* Remove RSS Feeds */
		if ( $is_active( 'disable_rss_feeds' ) ) {
			add_action( 'template_redirect', [ $this->feed_tweaks, 'disable_rss_feeds' ], 1 );
		}

		/* Remove RSS Feed Links */
		if ( $is_active( 'disable_rss_feed_link' ) ) {
			remove_action( 'wp_head', 'feed_links_extra', 3 );
			remove_action( 'wp_head', 'feed_links', 2 );
		}

		/* Disable Self Pingbacks */
		if ( $is_active( 'disable_self_pingbacks' ) ) {
			add_action( 'pre_ping', [ $this->feed_tweaks, 'disable_self_pingbacks' ] );
		}

		/**
		 * Password meter and defer optimization.
		 */

		/* Disable Password Strength Meter */
		if ( $is_active( 'disable_pw_strength_meter' ) ) {
			add_action( 'wp_print_scripts', [ $this->asset_tweaks, 'disable_password_strength_meter' ], 100 );
		}

		/* Defer CSS/JS */
		if ( ! is_admin() && $is_active( 'defer_css_js' ) ) {
			add_filter( 'style_loader_tag', [ $this->asset_tweaks, 'onload_style_css' ], 10, 4 );
			add_filter( 'script_loader_tag', [ $this->asset_tweaks, 'onload_defer_js' ], 10, 2 );
		}
	}

	/**
	 * Backward-compatible proxy to head tweaks.
	 *
	 * @return void
	 */
	private function disable_emojis() {
		$this->head_tweaks->disable_emojis();
	}

	/**
	 * Backward-compatible proxy to head tweaks.
	 *
	 * @return void
	 */
	public function nxt_disable_embeds() {
		$this->head_tweaks->disable_embeds();
	}

	/**
	 * Backward-compatible proxy to feed tweaks.
	 *
	 * @return void
	 */
	public function nxt_disable_rss_feeds() {
		$this->feed_tweaks->disable_rss_feeds();
	}

	/**
	 * Remove self-referencing pingback URLs.
	 *
	 * @param array $links Pingback links array (by reference).
	 * @return void
	 */
	public function nxt_disable_self_pingbacks( &$links ) {
		$this->feed_tweaks->disable_self_pingbacks( $links );
	}

	/**
	 * Dequeue password strength scripts except for required auth/account contexts.
	 *
	 * @return void
	 */
	public function disable_password_strength_meter() {
		$this->asset_tweaks->disable_password_strength_meter();
	}

	/**
	 * Add defer attribute to selected script handles.
	 *
	 * @param string $html   Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function nxt_onload_defer_js( $html, $handle ) {
		return $this->asset_tweaks->onload_defer_js( $html, $handle );
	}

	/**
	 * Preload selected styles and apply stylesheet relation on load.
	 *
	 * @param string $html   Link tag HTML.
	 * @param string $handle Style handle.
	 * @param string $href   Style URL.
	 * @param string $media  Media attribute value.
	 * @return string
	 */
	public function nxt_onload_style_css( $html, $handle, $href, $media ) {
		return $this->asset_tweaks->onload_style_css( $html, $handle, $href, $media );
	}
}
