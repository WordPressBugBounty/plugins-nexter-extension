<?php
/**
 * Content SEO – "Migrate from your other SEO plugin" admin notice.
 *
 * Nexter SEO's importer already supports Yoast, Rank Math, SureRank and All in One SEO. This
 * notice only speaks up when both are true: Nexter SEO is switched on (this file only ever
 * loads from inside that module's bootstrap) AND one of those four plugins is actually active —
 * so a site running, say, Yoast alone with Nexter SEO off never sees it, and a site with no
 * migratable data lying around never sees it either.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.7.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Migrate_Notice
 */
class Nexter_Content_SEO_Migrate_Notice {

	/** Per-user dismissal meta key — one blanket "not now", not per source. */
	const DISMISS_META_KEY = 'nexter_seo_migrate_notice_dismissed';

	/** Shared dismiss handler's notice id (see nexter_ext_dismiss_notice_data). */
	const NOTICE_ID = 'nexter_seo_migrate_notice';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_filter( 'nexter_allowed_dismiss_notice_ids', array( __CLASS__, 'allow_dismiss_id' ) );
		// Per-USER dismissal, not site-wide — this is a per-admin nudge, so one admin clicking
		// the "x" must not also hide it for every other admin (see the shared handler's own
		// nexter_per_user_dismiss_notice_ids filter for why this is a separate opt-in list).
		add_filter( 'nexter_per_user_dismiss_notice_ids', array( __CLASS__, 'allow_dismiss_id' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_dismiss_js' ) );
	}

	/**
	 * Whitelist this notice's id for the shared AJAX dismiss handler (used for both the id
	 * allowlist and the per-user-storage opt-in — same id, same list shape).
	 *
	 * @param string[] $ids Allowed/opted-in notice ids.
	 * @return string[]
	 */
	public static function allow_dismiss_id( $ids ) {
		$ids[] = self::NOTICE_ID;
		return $ids;
	}

	/**
	 * Which of the four supported SEO plugins is active right now, if any. Cheap on purpose
	 * (constant checks only, no DB query) since this runs on every admin page load.
	 *
	 * @return array{slug: string, label: string}|null
	 */
	private static function active_competitor() {
		$plugins = array(
			'yoast'    => array( 'Yoast SEO', defined( 'WPSEO_VERSION' ) ),
			'rankmath' => array( 'Rank Math', defined( 'RANK_MATH_VERSION' ) ),
			'surerank' => array( 'SureRank', defined( 'SURERANK_VERSION' ) ),
			'aioseo'   => array( 'All in One SEO', defined( 'AIOSEO_VERSION' ) ),
		);
		foreach ( $plugins as $slug => $info ) {
			if ( $info[1] ) {
				return array(
					'slug'  => $slug,
					'label' => $info[0],
				);
			}
		}
		return null;
	}

	/**
	 * Whether the notice should show for the current user/request.
	 *
	 * @return array{slug: string, label: string}|null The detected source, or null to render nothing.
	 */
	private static function should_render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true ) ) {
			return null;
		}
		$competitor = self::active_competitor();
		if ( ! $competitor ) {
			return null;
		}
		// Already migrated this source's settings — nagging further would just be noise.
		if ( class_exists( 'Nexter_Content_SEO_Importer' ) ) {
			$state = Nexter_Content_SEO_Importer::get_state( $competitor['slug'] );
			if ( ! empty( $state['settings_done'] ) ) {
				return null;
			}
		}
		return $competitor;
	}

	/**
	 * Enqueue the shared dismiss JS wherever this notice actually renders.
	 */
	public static function maybe_enqueue_dismiss_js() {
		if ( ! self::should_render() ) {
			return;
		}
		$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'nexter-ext-builder-js', NEXTER_EXT_URL . 'assets/js/admin/nexter-ext-admin' . $minified . '.js', array(), NEXTER_EXT_VER, true );
		// Same three-key fallback as Nxt_Seo_Notice — only when the dashboard hasn't already
		// localized the full object on this screen.
		if ( ! wp_script_is( 'nexter-ext-dashscript', 'enqueued' ) ) {
			wp_localize_script(
				'nexter-ext-builder-js',
				'nxtext_ajax_object',
				array(
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'ajax_nonce' => wp_create_nonce( 'nexter_admin_nonce' ),
					'adminUrl'   => admin_url(),
				)
			);
		}
	}

	/**
	 * Output the notice.
	 */
	public static function render() {
		$competitor = self::should_render();
		if ( ! $competitor ) {
			return;
		}

		$migrate_url = admin_url( 'admin.php?page=nxt_content_seo#/tools/seo-import' );

		echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="' . esc_attr( self::NOTICE_ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'nexter_admin_nonce' ) ) . '">';
			echo '<div class="nexter-license-activate">';
				echo '<div class="nexter-license-icon"><svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" fill="none" viewBox="0 0 44 44"><rect width="44" height="44" fill="#f5f7fe" rx="8.676"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.897" d="M13.2 24.2a1.1 1.1 0 0 1-.858-1.792l10.89-11.22a.55.55 0 0 1 .946.506l-2.112 6.622A1.102 1.102 0 0 0 23.1 19.8h7.7a1.1 1.1 0 0 1 .858 1.793l-10.89 11.22a.55.55 0 0 1-.946-.506l2.112-6.622A1.1 1.1 0 0 0 20.9 24.2z"/></svg></div>';
				echo '<div class="nexter-license-content">';
					/* translators: %s: detected plugin name, e.g. "Yoast SEO". */
					echo '<h2>' . esc_html( sprintf( __( 'We found %s on this site — bring its SEO data into Nexter in one click.', 'nexter-extension' ), $competitor['label'] ) ) . '</h2>';
					echo '<p>' . esc_html__( 'Titles, meta descriptions, per-post robots settings and redirects import in resumable batches. Nothing already set in Nexter is overwritten, and the source plugin\'s own data is left untouched.', 'nexter-extension' ) . '</p>';
					echo '<a href="' . esc_url( $migrate_url ) . '" class="nxt-nobtn-primary">' . esc_html__( 'Migrate Now', 'nexter-extension' ) . '</a>';
				echo '</div>';
			echo '</div>';
		echo '</div>';
	}
}
