<?php
/**
 * AJAX Request Verification Helper
 *
 * Centralizes the nonce + capability check pattern that appears 51+ times
 * across the plugin. Ensures consistent security enforcement for all
 * admin AJAX handlers.
 *
 * Usage:
 *   public function my_ajax_handler() {
 *       if ( ! Nxt_Ajax_Guard::verify() ) {
 *           return; // Response already sent.
 *       }
 *       // ... handler logic ...
 *   }
 *
 *   // With custom capability / nonce:
 *   Nxt_Ajax_Guard::verify( 'edit_posts', 'my_custom_nonce', 'nonce_field' );
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Ajax_Guard {

	/**
	 * Verify an AJAX request's nonce and user capability.
	 *
	 * On failure, sends a JSON error response with HTTP 403 and returns false.
	 * On success, returns true so the caller can proceed.
	 *
	 * @param string $capability   WordPress capability to check. Default 'manage_options'.
	 * @param string $nonce_action Expected nonce action name.       Default 'nexter_admin_nonce'.
	 * @param string $nonce_key    $_REQUEST key holding the nonce.  Default 'nexter_nonce'.
	 *
	 * @return bool True if the request is valid, false otherwise (response already sent).
	 */
	public static function verify( $capability = 'manage_options', $nonce_action = 'nexter_admin_nonce', $nonce_key = 'nexter_nonce' ) {
		// check_ajax_referer with `false` prevents it from wp_die()-ing on failure,
		// letting us return a structured JSON error instead.
		if ( ! check_ajax_referer( $nonce_action, $nonce_key, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'nexter-extension' ) ),
				403
			);
			return false;
		}

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'nexter-extension' ) ),
				403
			);
			return false;
		}

		return true;
	}
}
