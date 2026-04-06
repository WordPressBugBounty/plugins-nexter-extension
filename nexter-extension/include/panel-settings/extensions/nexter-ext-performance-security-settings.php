<?php
/**
 * Performance & Security Settings — Orchestrator
 *
 * Thin loader that reads options once, then instantiates only the modules whose
 * features are actually enabled.  Each concern lives in its own class under
 * the perf-security/ directory so it can be tested, read, and toggled independently.
 *
 * Modules:
 *   Nxt_Security_Headers      — X-Frame-Options, meta-generator, file-editor, cookies, XSS
 *   Nxt_User_Columns          — Registration-date & last-login columns on Users screen
 *   Nxt_Performance_Tweaks    — Emoji, embeds, dashicons, RSD, shortlink, RSS, defer, etc.
 *   Nxt_Disable_Comments      — Per-post-type or site-wide comment removal
 *   Nxt_Security_Network      — XML-RPC, WP version, REST API, SVG upload
 *   Nxt_Image_Optimize_Notice — Media-page notice when image optimization is off
 *
 * @package Nexter Extension
 * @since 1.1.0
 */
defined( 'ABSPATH' ) || exit;

class Nexter_Ext_Performance_Security_Settings {

	/**
	 * Module base path.
	 */
	private $module_dir;

	/**
	 * Constructor — reads options once, delegates to per-feature modules.
	 */
	public function __construct() {

		$this->module_dir = __DIR__ . '/perf-security/';

		// ── Read options once ───────────────────────────────────────
		$perf_option     = Nxt_Options::performance();
		$security_option = Nxt_Options::security();

		// Resolve advance-security values
		$adv_sec_opt = $security_option;
		if ( ! empty( $adv_sec_opt['advance-security']['switch'] ) && ! empty( $adv_sec_opt['advance-security']['values'] ) ) {
			$adv_sec_opt = $adv_sec_opt['advance-security']['values'];
		}

		// Resolve advance-performance values
		$adv_perfor = [];
		if ( ! empty( $perf_option['advance-performance']['switch'] ) && ! empty( $perf_option['advance-performance']['values'] ) ) {
			$adv_perfor = $perf_option['advance-performance']['values'];
		}

		// ── Security Headers ────────────────────────────────────────
		if ( ! empty( $adv_sec_opt ) && is_array( $adv_sec_opt ) ) {
			require_once $this->module_dir . 'class-nxt-security-headers.php';
			new Nxt_Security_Headers( $adv_sec_opt );
		}

		// ── User Columns (admin-only UI) ────────────────────────────
		if ( ! empty( $adv_sec_opt ) && is_array( $adv_sec_opt ) ) {
			$needs_columns = in_array( 'user_register_date_time', $adv_sec_opt, true )
				|| in_array( 'user_last_login_display', $adv_sec_opt, true );
			if ( $needs_columns ) {
				require_once $this->module_dir . 'class-nxt-user-columns.php';
				new Nxt_User_Columns( $adv_sec_opt );
			}
		}

		// ── Performance Tweaks ──────────────────────────────────────
		if ( ! empty( $perf_option ) ) {
			require_once $this->module_dir . 'class-nxt-performance-tweaks.php';
			new Nxt_Performance_Tweaks( $perf_option, $adv_perfor );

			// ── Disable Comments ────────────────────────────────────
			require_once $this->module_dir . 'class-nxt-disable-comments.php';
			$comments_module = new Nxt_Disable_Comments( $perf_option, $adv_perfor );

			// ── Sub-module loading (revision, heartbeat, image optimisation)
			// Revision Control
			if ( ! empty( $perf_option['post-revision-control']['switch'] ) ) {
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-post-revision-control.php';
			}

			// Heartbeat Control
			if ( ! empty( $perf_option['heartbeat-control']['switch'] ) ) {
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-heartbeat-control.php';
			}

			// Image Upload Optimisation
			if ( ! empty( $perf_option['image-upload-optimize']['switch'] ) ) {
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-image-upload-optimize.php';
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-bulk-images.php';
				Nexter_Ext_Bulk_Images::get_instance();
			} else {
				require_once $this->module_dir . 'class-nxt-image-optimize-notice.php';
				new Nxt_Image_Optimize_Notice();
			}
		}

		// ── Security Network (XML-RPC, WP version, REST API, SVG) ───
		if ( ! empty( $security_option ) ) {
			require_once $this->module_dir . 'class-nxt-security-network.php';
			new Nxt_Security_Network( $adv_sec_opt, $security_option );
		}
	}

	// ── Backward-compatible static methods ──────────────────────────

	/**
	 * Toggle wp-includes folder visibility.
	 * Kept here for backward compat — delegates to Security Headers module.
	 *
	 * @param bool $state
	 * @return bool
	 */
	public static function toggle_wp_includes_folder_visiblity( $state ) {
		require_once __DIR__ . '/perf-security/class-nxt-security-headers.php';
		return Nxt_Security_Headers::toggle_wp_includes_folder_visiblity( $state );
	}
}

new Nexter_Ext_Performance_Security_Settings();


/**
 * Global callback for output-buffer meta-tag removal.
 * Must remain in global scope as it's passed to ob_start() by name.
 */
if ( ! function_exists( 'nxt_remove_meta_tags' ) ) {
	function nxt_remove_meta_tags( $generated_html ) {
		$regex = '/<meta\s+name\s*=\s*["\']generator["\']\s+content\s*=\s*["\'][^"\']*["\']\s*\/?>/i';
		$generated_html = preg_replace( $regex, '', $generated_html );
		return $generated_html;
	}
}

/**
 * Legacy alias — old name used prior to refactor.
 */
if ( ! function_exists( 'remove_meta_tags' ) ) {
	function remove_meta_tags( $generated_html ) {
		return nxt_remove_meta_tags( $generated_html );
	}
}
