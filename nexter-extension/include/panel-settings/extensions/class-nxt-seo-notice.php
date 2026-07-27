<?php
/**
 * SEO Promo Notice
 * Admin notice promoting the built-in Nexter SEO module while it is disabled.
 * Uses the shared dismiss mechanism (data-notice-id -> nexter_ext_dismiss_notice).
 *
 * @package Nexter Extension
 * @since 4.6.17
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nxt_Seo_Notice' ) ) {

	class Nxt_Seo_Notice {

		public function __construct() {
			add_action( 'admin_notices', array( $this, 'render_seo_notice' ) );
			// Register this notice ID with the shared dismiss handler allowlist.
			add_filter( 'nexter_allowed_dismiss_notice_ids', array( $this, 'allow_dismiss_id' ) );
		}

		/**
		 * Whitelist the SEO notice ID for the shared AJAX dismiss handler.
		 *
		 * @param string[] $ids Allowed notice IDs.
		 * @return string[]
		 */
		public function allow_dismiss_id( $ids ) {
			$ids[] = 'nexter_seo_notice';
			return $ids;
		}

		/**
		 * Output the SEO promo notice on relevant admin screens.
		 */
		public function render_seo_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// Per-user dismissal (this is a promo notice): one admin hiding it must not hide it for
			// every other admin. The shared dismiss handler stores this in user meta for the
			// per-user notice allowlist (see nexter_ext_dismiss_notice_data / the
			// nexter_per_user_dismiss_notice_ids filter).
			if ( get_user_meta( get_current_user_id(), 'nexter_seo_notice_dismissed', true ) ) {
				return;
			}

			// White-label (Pro): never show a Nexter-branded promo (with a nexterwp.com "Learn
			// More" link) on a rebranded install. Suppress when a brand name is set or help links
			// are hidden — both strong signals the site is white-labeled.
			if ( ( defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' ) ) && class_exists( 'Nxt_Options' ) ) {
				$wl = Nxt_Options::white_label();
				if ( is_array( $wl ) && ( ! empty( $wl['brand_name'] ) || ( ! empty( $wl['nxt_help_link'] ) && 'on' === $wl['nxt_help_link'] ) ) ) {
					return;
				}
			}

			// Show only on high-visibility admin screens, not on every page.
			global $pagenow;
			$allowed_screens = array( 'index.php', 'plugins.php', 'edit.php' );
			if ( ! in_array( $pagenow, $allowed_screens, true ) ) {
				return;
			}

			$enable_url = admin_url( 'admin.php?page=nexter_welcome#/seo' );
			// Include UTM tracking on the outbound "Learn More" link, matching the campaign format
			// used by Nexter's other admin CTAs (utm_source=wpbackend&utm_medium=admin&utm_campaign).
			$learn_url = 'https://nexterwp.com/nexter-extension/?utm_source=wpbackend&utm_medium=admin&utm_campaign=seonotice';

			echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="nexter_seo_notice">';
				echo '<div class="nexter-license-activate">';
					echo '<div class="nexter-license-icon"><svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" fill="none" viewBox="0 0 44 44"><rect width="44" height="44" fill="#f5f7fe" rx="8.676"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.897" d="M13.2 24.2a1.1 1.1 0 0 1-.858-1.792l10.89-11.22a.55.55 0 0 1 .946.506l-2.112 6.622A1.102 1.102 0 0 0 23.1 19.8h7.7a1.1 1.1 0 0 1 .858 1.793l-10.89 11.22a.55.55 0 0 1-.946-.506l2.112-6.622A1.1 1.1 0 0 0 20.9 24.2z"/></svg></div>';
					echo '<div class="nexter-license-content">';
						echo '<h2>' . esc_html__( 'Introducing Nexter SEO - replace your SEO plugins with Nexter.', 'nexter-extension' ) . '</h2>';
						echo '<p>' . esc_html__( 'Content optimization, schema, sitemaps, redirects, robots.txt, and image SEO - all built into Nexter. One plugin instead of four.', 'nexter-extension' ) . '</p>';
						echo '<a href="' . esc_url( $enable_url ) . '" class="nxt-nobtn-primary">' . esc_html__( 'Enable SEO', 'nexter-extension' ) . '</a> ';
						echo '<a href="' . esc_url( $learn_url ) . '" target="_blank" rel="noopener noreferrer" class="nxt-nobtn-secondary">' . esc_html__( 'Learn More', 'nexter-extension' ) . '</a>';
					echo '</div>';
				echo '</div>';
			echo '</div>';
		}
	}
}
