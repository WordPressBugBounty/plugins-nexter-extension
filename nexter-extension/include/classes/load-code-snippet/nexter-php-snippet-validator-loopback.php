<?php
/**
 * Nexter Snippet Validator Loopback
 *
 * This file handles loopback-based PHP snippet validation.
 * It is meant to be called via admin-side AJAX or form submission.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nexter_Snippet_Validator_Loopback {

	/**
	 * Run loopback validation of a PHP code snippet.
	 *
	 * @param int $post_id
	 */
	public static function validate_snippet( $post_id = 0 ) {

		$user = wp_get_current_user();
		if ( empty( $user ) || ! in_array( 'administrator', (array) $user->roles, true ) ) {
			wp_send_json_error([
				'code'    => 'php_error',
				'message' => __( 'Only Admin can run this.', 'nexter-extension' ),
			]);
		}

		$post_id = ! empty( $post_id ) ? sanitize_key( intval( wp_unslash( $post_id ) ) ) : '';
		if ( empty( $post_id ) ) {
			wp_send_json_error([
				'code'    => 'php_error',
				'message' => __( 'Undefined Content ID', 'nexter-extension' ),
			]);
		}

		update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'no' );

		$scrape_key   = md5( wp_rand() );
		$transient    = 'scrape_key_' . $scrape_key;
		$scrape_nonce = (string) wp_rand();
		set_transient( $transient, $scrape_nonce, 5 );

		$cookies = wp_unslash( $_COOKIE );
		$scrape_params = [
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
			'test_code'       => 'code_test',
			'code_id'         => intval( $post_id ),
		];

		$headers = [
			'Cache-Control' => 'no-cache',
		];

		$sslverify = apply_filters( 'https_local_ssl_verify', false );

		$auth_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ) : '';
		if ( $auth_user && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $auth_user . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		$timeout = 100;
		$needle_start = "###### wp_scraping_result_start:$scrape_key ######";
		$needle_end   = "###### wp_scraping_result_end:$scrape_key ######";

		ob_start();
		$response = wp_remote_get( $url = add_query_arg( $scrape_params, home_url( '/' ) ), compact( 'cookies', 'headers', 'timeout', 'sslverify' ) );
		$body     = wp_remote_retrieve_body( $response );
		ob_end_clean();

		$scrape_result_position = strpos( $body, $needle_start );
		$result = null;

		if ( false === $scrape_result_position ) {
			$result = [
				'code'    => 'loopback_request_failed',
				'message' => __( 'Unable to communicate back with site to check for fatal errors, so the PHP change was reverted. You will need to upload your PHP file change by some other means, such as by using SFTP.', 'nexter-extension' ),
			];
		} else {
			$error_output = substr( $body, $scrape_result_position + strlen( $needle_start ) );
			$error_output = substr( $error_output, 0, strpos( $error_output, $needle_end ) );
			$result       = json_decode( trim( $error_output ), true );

			if ( empty( $result ) ) {
				$result = [ 'code' => 'json_parse_error' ];
			}
		}
		
		delete_transient( $transient );

		if ( true !== $result ) {
			$message = isset( $result['message'] ) ? $result['message'] : __( 'Something went wrong.', 'nexter-extension' );
			$error_code = new WP_Error( 'php_error', $message, $result );

			// Set status to inactive when loopback validation fails
			update_post_meta( $post_id, 'nxt-code-status', 0 );

			wp_send_json_error( array_merge([
				'id'      => $post_id,
				'code'    => $error_code->get_error_code(),
				'message' => $error_code->get_error_message(),
			], (array) $error_code->get_error_data() ) );
		} else {
			update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'yes' );
		}
	}
}
