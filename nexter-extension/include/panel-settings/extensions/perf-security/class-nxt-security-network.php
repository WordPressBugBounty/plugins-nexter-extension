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
	 * Deep-convert options to plain arrays (handles stdClass trees and arrays with nested objects).
	 *
	 * @param mixed $data Raw option value.
	 * @return array
	 */
	private static function nxt_options_to_array( $data ) {
		if ( null === $data || false === $data || '' === $data ) {
			return array();
		}
		$json = wp_json_encode( $data );
		if ( false === $json || 'null' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array $adv_sec_opt       Advance security values.
	 * @param array $nxt_security_raw  Raw nexter_site_security option (for SVG).
	 */
	public function __construct( $adv_sec_opt, $nxt_security_raw ) {

		$adv_sec_opt      = self::nxt_options_to_array( $adv_sec_opt );
		$nxt_security_raw = self::nxt_options_to_array( $nxt_security_raw );

		// Disable XML-RPC.
		// The advance-security values may store this toggle either as a checked value in a flat
		// list ( [ 'disable_xml_rpc', … ] ) or as an associative key ( [ 'disable_xml_rpc' => 1 ] ).
		// The old check only did in_array() (value form); when the option is stored in key form —
		// which is how the security report and the sibling headers module read it — the gate never
		// matched, so NONE of the filters registered and XML-RPC stayed fully live (system.listMethods
		// 200, pingback + auth pipeline reachable) even though the UI reported it disabled. Accept
		// both shapes so enforcement matches what the report advertises.
		$xmlrpc_disabled = is_array( $adv_sec_opt )
			&& ( in_array( 'disable_xml_rpc', $adv_sec_opt, true ) || ! empty( $adv_sec_opt['disable_xml_rpc'] ) );
		if ( $xmlrpc_disabled ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			// Strip every XML-RPC method (pingback.ping, pingback.extensions.getPingbacks,
			// system.multicall, system.listMethods, wp.getUsersBlogs, …) so the endpoint exposes no
			// methods and the auth pipeline cannot be probed even if the hard block below is bypassed
			// on an unusual server config. Defense-in-depth alongside the 403 on the endpoint.
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
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
		$svg_upload = isset( $nxt_security_raw['svg-upload'] ) && is_array( $nxt_security_raw['svg-upload'] ) ? $nxt_security_raw['svg-upload'] : array();
		if ( ! empty( $svg_upload['switch'] ) && ! empty( $svg_upload['values'] ) ) {
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
		// Canonical detection: xmlrpc.php defines XMLRPC_REQUEST before wp-load, so it is reliable
		// across servers/proxies. basename( SCRIPT_FILENAME ) can be rewritten to index.php behind
		// FastCGI / reverse proxies, which is why the endpoint block could silently no-op before.
		$is_xmlrpc = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
		if ( ! $is_xmlrpc ) {
			$script_filename = isset( $_SERVER['SCRIPT_FILENAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : '';
			$is_xmlrpc       = ( '' !== $script_filename && 'xmlrpc.php' === basename( $script_filename ) );
		}

		if ( ! $is_xmlrpc ) {
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
