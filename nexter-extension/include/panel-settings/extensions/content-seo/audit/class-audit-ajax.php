<?php
/**
 * Nexter SEO – Legacy admin-ajax handlers for Site Audit (mirrors REST).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO\Audit
 */

namespace NexterSEO\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ajax
 */
class Ajax {

	const NONCE_ACTION = 'nexter_seo_audit';

	/**
	 * Register hooks.
	 */
	public static function init() {
		\add_action( 'wp_ajax_nexter_run_audit', array( __CLASS__, 'run' ) );
		\add_action( 'wp_ajax_nexter_fix_issue', array( __CLASS__, 'fix' ) );
	}

	/**
	 * POST: run full audit.
	 */
	public static function run() {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Forbidden.', 'nexter-extension' ) ), 403 );
		}
		$async = Engine::request_async_run();
		$last  = Engine::get_last_result();
		\wp_send_json_success(
			array(
				'data'       => $last,
				'async'      => $async,
				'from_cache' => true,
				'message'    => \__( 'Audit queued in background. Showing last available snapshot until the run completes.', 'nexter-extension' ),
			)
		);
	}

	/**
	 * POST: apply fix.
	 */
	public static function fix() {
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Forbidden.', 'nexter-extension' ) ), 403 );
		}
		$issue = isset( $_POST['issue_id'] ) ? \sanitize_key( \wp_unslash( $_POST['issue_id'] ) ) : '';
		if ( '' === $issue ) {
			\wp_send_json_error( array( 'message' => \__( 'Missing issue id.', 'nexter-extension' ) ), 400 );
		}
		$engine = new Engine();
		$result = $engine->apply_fix( $issue );
		if ( \is_wp_error( $result ) ) {
			$err    = $result;
			$status = $err->get_error_data( 'status' ) ? (int) $err->get_error_data( 'status' ) : 400;
			\wp_send_json_error(
				array( 'message' => $err->get_error_message() ),
				$status
			);
		}
		\wp_send_json_success( $result );
	}

	/**
	 * Localized nonce for classic scripts.
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return \wp_create_nonce( self::NONCE_ACTION );
	}
}
