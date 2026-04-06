<?php
/**
 * Security Network Module
 * Handles: XML-RPC disable, WP version hide, REST API links remove, REST API restrict, SVG upload
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Security_Network {

	/**
	 * @param array $adv_sec_opt       Advance security values.
	 * @param array $nxt_security_raw  Raw nexter_site_security option (for SVG).
	 */
	public function __construct( $adv_sec_opt, $nxt_security_raw ) {

		// Disable XML-RPC
		if ( is_array( $adv_sec_opt ) && in_array( 'disable_xml_rpc', $adv_sec_opt, true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', [ $this, 'nxt_remove_x_pingback' ] );
			add_filter( 'pings_open', '__return_false', 9999 );
			add_filter( 'pre_update_option_enable_xmlrpc', '__return_false' );
			add_filter( 'pre_option_enable_xmlrpc', '__return_zero' );
			add_action( 'init', [ $this, 'nxt_xmlrpc_header' ] );
		}

		// Disable WP Version
		if ( is_array( $adv_sec_opt ) && in_array( 'disable_wp_version', $adv_sec_opt, true ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', [ $this, 'remove_wp_version_from_src' ], 9999 );
			add_filter( 'script_loader_src', [ $this, 'remove_wp_version_from_src' ], 9999 );
		}

		// Disable REST API Links
		if ( is_array( $adv_sec_opt ) && in_array( 'disable_rest_api_links', $adv_sec_opt, true ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11, 0 );
		}

		// Disable REST API
		if ( isset( $adv_sec_opt['disable_rest_api'] ) && ! empty( $adv_sec_opt['disable_rest_api'] ) ) {
			$rest_api_mode = $adv_sec_opt['disable_rest_api'];
			add_filter( 'rest_authentication_errors', function( $result ) use ( $rest_api_mode ) {
				if ( ! empty( $result ) ) {
					return $result;
				}

				$check_disabled = false;
				$rest_route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';

				if ( ! empty( $rest_route ) && strpos( $rest_route, 'contact-form-7' ) !== false ) {
					return $result;
				}

				if ( $rest_api_mode === 'non_admin' && ! current_user_can( 'manage_options' ) ) {
					$check_disabled = true;
				} elseif ( $rest_api_mode === 'logged_out' && ! is_user_logged_in() ) {
					$check_disabled = true;
				}

				if ( $check_disabled ) {
					return new WP_Error(
						'rest_authentication_error',
						__( 'Sorry, you do not have permission for REST API requests.', 'nexter-extension' ),
						array( 'status' => 401 )
					);
				}

				return $result;
			}, 20 );
		}

		// SVG Upload
		if ( ! empty( $nxt_security_raw['svg-upload']['switch'] ) && ! empty( $nxt_security_raw['svg-upload']['values'] ) ) {
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-svg-upload.php';
		}
	}

	public function remove_wp_version_from_src( $src ) {
		if ( strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	public function nxt_remove_x_pingback( $headers ) {
		unset( $headers['X-Pingback'], $headers['x-pingback'] );
		return $headers;
	}

	public function nxt_xmlrpc_header() {
		$script_filename = isset( $_SERVER['SCRIPT_FILENAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : '';

		if ( empty( $script_filename ) ) {
			return;
		}

		if ( 'xmlrpc.php' !== basename( $script_filename ) ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		wp_die(
			esc_html__( 'XML-RPC is disabled.', 'nexter-extension' ),
			esc_html__( 'Forbidden', 'nexter-extension' ),
			array( 'response' => 403 )
		);
	}
}
