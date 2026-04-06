<?php
/**
 * Image Optimize Notice Module
 * Handles: Admin notice prompting users to enable image optimization
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Image_Optimize_Notice {

	public function __construct() {
		add_action( 'admin_notices', [ $this, 'nexter_image_optimize_notice' ] );
	}

	public function nexter_image_optimize_notice() {
		if ( get_option( 'nexter_image_optimize_notice_dismissed' ) ) {
			return;
		}

		global $pagenow;

		// Only show on Media page (upload.php)
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		$this->render_image_optimize_notice_html();
	}

	private function render_image_optimize_notice_html() {
		$notice_title = esc_html__( 'Free Image Compression Is Now Available In Nexter - No Recurring Cost or APIs Required', 'nexter-extension' );
		$notice_desc  = esc_html__( 'Enable image compression to reduce image size, convert to WebP, AVIF, save storage space, and improve website performance. without any third-party plugins, APIs, or recurring costs.', 'nexter-extension' );
		$settings_url = admin_url( 'admin.php?page=nexter_welcome#/performance' );

		echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="nexter_image_optimize_notice">';
			echo '<div class="nexter-license-activate">';
				echo '<div class="nexter-license-icon"><svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" fill="none" viewBox="0 0 44 44"><rect width="44" height="44" fill="#f5f7fe" rx="8.676"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.897" d="M13.2 24.2a1.1 1.1 0 0 1-.858-1.792l10.89-11.22a.55.55 0 0 1 .946.506l-2.112 6.622A1.102 1.102 0 0 0 23.1 19.8h7.7a1.1 1.1 0 0 1 .858 1.793l-10.89 11.22a.55.55 0 0 1-.946-.506l2.112-6.622A1.1 1.1 0 0 0 20.9 24.2z"/></svg></div>';
				echo '<div class="nexter-license-content">';
					echo '<h2>' . esc_html( $notice_title ) . '</h2>';
					echo '<p>' . esc_html( $notice_desc ) . '</p>';
					echo '<a href="' . esc_url( $settings_url ) . '" class="nxt-nobtn-primary">' . esc_html__( 'Optimise image', 'nexter-extension' ) . '</a>';
				echo '</div>';
			echo '</div>';
		echo '</div>';
	}

	/**
	 * Get the image optimise notice HTML as string.
	 */
	public function get_image_optimize_notice_html() {
		$notice_title = esc_html__( 'Free Image Compression Is Now Available In Nexter - No Recurring Cost or APIs Required', 'nexter-extension' );
		$notice_desc  = esc_html__( 'Enable image compression to reduce image size, convert to WebP, AVIF, save storage space, and improve website performance. without any third-party plugins, APIs, or recurring costs.', 'nexter-extension' );
		$settings_url = admin_url( 'admin.php?page=nexter-site-performance&tab=performance' );

		ob_start();
		echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="nexter_image_optimize_notice">';
			echo '<div class="nexter-license-activate">';
				echo '<div class="nexter-license-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"><rect width="24" height="24" fill="#1717CC" rx="5"/><path fill="#fff" d="M1.052 6.827a.525.525 0 0 1-.41-.856L5.84.616a.263.263 0 0 1 .451.242l-1.008 3.16a.525.525 0 0 0 .494.709h3.675a.525.525 0 0 1 .41.856l-5.198 5.355a.262.262 0 0 1-.452-.242l1.008-3.16a.525.525 0 0 0-.493-.71z"/></svg></div>';
				echo '<div class="nexter-license-content">';
					echo '<h2>' . esc_html( $notice_title ) . '</h2>';
					echo '<p>' . esc_html( $notice_desc ) . '</p>';
					echo '<a href="' . esc_url( $settings_url ) . '" class="nxt-nobtn-primary">' . esc_html__( 'Optimise image', 'nexter-extension' ) . '</a>';
				echo '</div>';
			echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}
}
