<?php
/**
 * Performance Asset Tweaks
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Performance_Asset_Tweaks {
	/**
	 * Dequeue password strength scripts except for required auth/account contexts.
	 *
	 * @return void
	 */
	public function disable_password_strength_meter() {
		if ( is_admin() ) {
			return;
		}

		$get_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$allowed_actions = array( 'register', 'rp', 'lostpassword' );
		if ( ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' )
			|| ( ! empty( $get_action ) && in_array( $get_action, $allowed_actions, true ) ) ) {
			return;
		}

		if ( class_exists( 'WooCommerce' ) && ( is_account_page() || is_checkout() ) ) {
			return;
		}

		wp_dequeue_script( 'password-strength-meter' );
		wp_deregister_script( 'password-strength-meter' );
		wp_dequeue_script( 'wc-password-strength-meter' );
		wp_deregister_script( 'wc-password-strength-meter' );
		wp_dequeue_script( 'zxcvbn-async' );
		wp_deregister_script( 'zxcvbn-async' );
	}

	/**
	 * Add defer attribute to selected script handles.
	 *
	 * @param string $html   Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function onload_defer_js( $html, $handle ) {
		$handles = array( 'nexter-frontend-js' );
		if ( in_array( $handle, $handles, true ) ) {
			$html = str_replace( '></script>', ' defer></script>', $html );
		}
		return $html;
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
	public function onload_style_css( $html, $handle, $href, $media ) {
		$handles = array( 'dashicons', 'wp-block-library' );
		if ( in_array( $handle, $handles, true ) ) {
			$html = '<link rel="preload" href="' . $href . '" as="style" id="' . $handle . '" media="' . $media . '" onload="this.onload=null;this.rel=\'stylesheet\'">'
				. '<noscript>' . $html . '</noscript>';
		}
		return $html;
	}
}
