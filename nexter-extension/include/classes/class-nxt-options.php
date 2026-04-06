<?php
/**
 * Centralized Settings Cache
 *
 * Single point of truth for frequently-read Nexter options.  Each option is
 * fetched from the database at most once per request and held in a static
 * property so that the 20+ scattered get_option() calls across the plugin
 * never pay the unserialization / filter cost more than once.
 *
 * Usage:
 *   $ext = Nxt_Options::extra_ext();      // nexter_extra_ext_options
 *   $perf = Nxt_Options::performance();   // nexter_site_performance
 *   $sec = Nxt_Options::security();       // nexter_site_security
 *   $wl  = Nxt_Options::white_label();    // nexter_white_label
 *
 * After any option is saved (update_option), call Nxt_Options::flush()
 * or Nxt_Options::flush( 'nexter_site_performance' ) to invalidate.
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Options {

	/** @var array<string, mixed|null> */
	private static $cache = [];

	/** @var bool Whether auto-flush hooks have been registered. */
	private static $hooks_registered = false;

	/**
	 * Option keys managed by this cache.
	 * Maps a short alias to the real wp_options option_name.
	 */
	private static $keys = [
		'extra_ext'        => 'nexter_extra_ext_options',
		'performance'      => 'nexter_site_performance',
		'security'         => 'nexter_site_security',
		'white_label'      => 'nexter_white_label',
		'disabled_images'  => 'nexter_disabled_images',
		'custom_img_sizes' => 'nexter_custom_image_sizes',
		'elementor_icons'  => 'nexter_elementor_icons',
		'google_fonts'     => 'nexter_google_fonts',
		'activate'         => 'nexter_activate',
		'settings_opts'    => 'nexter_settings_opts',
		'builder_switcher' => 'nxt_builder_switcher',
		'tpgb_white_label' => 'tpgb_white_label',
		'notice_count'     => 'nxt_ext_menu_notice_count',
	];

	// ── Public getters ─────────────────────────────────────────────

	/**
	 * @return array|false  nexter_extra_ext_options value.
	 */
	public static function extra_ext() {
		return self::get( 'extra_ext' );
	}

	/**
	 * @return array|false  nexter_site_performance value.
	 */
	public static function performance() {
		return self::get( 'performance' );
	}

	/**
	 * @return array|false  nexter_site_security value.
	 */
	public static function security() {
		return self::get( 'security' );
	}

	/**
	 * @return array|false  nexter_white_label value.
	 */
	public static function white_label() {
		return self::get( 'white_label' );
	}

	/**
	 * @return array|false  nexter_disabled_images value.
	 */
	public static function disabled_images() {
		return self::get( 'disabled_images' );
	}

	/**
	 * @return array|false  nexter_custom_image_sizes value.
	 */
	public static function custom_img_sizes() {
		return self::get( 'custom_img_sizes' );
	}

	/**
	 * @return array|false  nexter_elementor_icons value.
	 */
	public static function elementor_icons() {
		return self::get( 'elementor_icons' );
	}

	/**
	 * @return array|false  nexter_google_fonts value.
	 */
	public static function google_fonts() {
		return self::get( 'google_fonts' );
	}

	/**
	 * @return mixed  nexter_activate value.
	 */
	public static function activate() {
		return self::get( 'activate' );
	}

	/**
	 * @return array|false  nexter_settings_opts value.
	 */
	public static function settings_opts() {
		return self::get( 'settings_opts' );
	}

	/**
	 * @return mixed  nxt_builder_switcher value.
	 */
	public static function builder_switcher() {
		return self::get( 'builder_switcher' );
	}

	/**
	 * @return array|false  tpgb_white_label value.
	 */
	public static function tpgb_white_label() {
		return self::get( 'tpgb_white_label' );
	}

	/**
	 * @return array|false  nxt_ext_menu_notice_count value.
	 */
	public static function notice_count() {
		return self::get( 'notice_count' );
	}

	// ── Cache management ───────────────────────────────────────────

	/**
	 * Invalidate one or all cached options.
	 *
	 * @param string|null $option_name  Full option_name (e.g. 'nexter_site_performance')
	 *                                  or null to flush everything.
	 */
	public static function flush( $option_name = null ) {
		if ( null === $option_name ) {
			self::$cache = [];
			return;
		}

		// Accept either the alias or the full option name.
		$alias = array_search( $option_name, self::$keys, true );
		if ( false !== $alias ) {
			unset( self::$cache[ $alias ] );
		}
	}

	// ── Internal ───────────────────────────────────────────────────

	/**
	 * Register auto-flush hooks so that update_option() on any managed
	 * key automatically invalidates the in-memory cache.
	 */
	private static function register_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		foreach ( self::$keys as $option_name ) {
			$name = $option_name; // capture for closure
			$flush_cb = function() use ( $name ) {
				self::flush( $name );
			};
			add_action( "update_option_{$option_name}", $flush_cb );
			add_action( "delete_option_{$option_name}", $flush_cb );
		}
	}

	/**
	 * Lazy-load an option by alias.
	 *
	 * @param string $alias  One of the keys in self::$keys.
	 * @return mixed
	 */
	private static function get( $alias ) {
		self::register_hooks();

		if ( ! array_key_exists( $alias, self::$cache ) ) {
			self::$cache[ $alias ] = get_option( self::$keys[ $alias ] );
		}
		return self::$cache[ $alias ];
	}
}
