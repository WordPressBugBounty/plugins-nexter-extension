<?php
/**
 * Security Headers Module
 * Handles: X-Frame-Options, meta generator removal, file editor disable, secure cookies, XSS protection
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Security_Headers {

	/**
	 * @var array Advance security values array.
	 */
	private $adv_sec_opt;

	/**
	 * @param array $adv_sec_opt Advance security values.
	 */
	public function __construct( $adv_sec_opt ) {
		$this->adv_sec_opt = $adv_sec_opt;

		add_action( 'init', [ $this, 'add_security_header' ] );

		if ( ! empty( $adv_sec_opt['iframe_security'] ) ) {
			add_action( 'send_headers', [ $this, 'add_x_frame_options_header' ] );
		}

		if ( is_array( $adv_sec_opt ) && in_array( 'remove_meta_generator', $adv_sec_opt, true ) ) {
			add_action( 'init', [ $this, 'remove_meta_generator' ] );
		}

		// XSS Protection
		if ( is_array( $adv_sec_opt ) && in_array( 'xss_protection', $adv_sec_opt, true ) ) {
			add_action( 'send_headers', function() {
				header( 'X-XSS-Protection: 1; mode=block' );
			}, 99 );
		}
	}

	public function add_security_header() {
		if ( is_array( $this->adv_sec_opt ) && in_array( 'disable_file_editor', $this->adv_sec_opt, true ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// HTTP Secure Flag
		if ( is_array( $this->adv_sec_opt ) && in_array( 'secure_cookies', $this->adv_sec_opt, true ) ) {
			@ini_set( 'session.cookie_httponly', true );
			@ini_set( 'session.cookie_secure', true );
			@ini_set( 'session.use_only_cookies', true );
		}
	}

	public function add_x_frame_options_header() {
		// Use the advance-security values captured in the constructor. Previously this re-read
		// Nxt_Options::security() and looked for ['iframe_security'] at the TOP level, but that
		// key actually lives at ['advance-security']['values']['iframe_security'] — so the lookup
		// always failed and the X-Frame-Options (clickjacking protection) header was never sent.
		$mode = ( is_array( $this->adv_sec_opt ) && ! empty( $this->adv_sec_opt['iframe_security'] ) )
			? $this->adv_sec_opt['iframe_security'] : '';
		switch ( $mode ) {
			case 'sameorigin':
				if ( ! defined( 'DOING_CRON' ) ) {
					header( 'X-Frame-Options: sameorigin' );
				}
				break;
			case 'deny':
				header( 'X-Frame-Options: deny' );
				break;
		}
	}

	public function remove_meta_generator() {
		if ( ! headers_sent() ) {
			add_action( 'get_header', [ $this, 'clean_generated_header' ], 50 );
			add_action( 'wp_footer', function() {
				if ( ob_get_level() > 0 ) {
					ob_end_flush();
				}
			}, 100 );
		}
	}

	public function clean_generated_header( $generated_html ) {
		if ( ob_get_level() === 0 ) {
			ob_start( 'nxt_remove_meta_tags' );
		}
	}

	/**
	 * Toggle wp-includes folder visibility.
	 *
	 * @param bool $state
	 * @return bool
	 */
	public static function toggle_wp_includes_folder_visiblity( $state ) {
		if ( ! function_exists( 'wp_filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		if ( WP_Filesystem() ) {
			$file_path = ABSPATH . 'wp-includes/index.php';

			if ( ! $wp_filesystem->is_writable( $file_path ) ) {
				return false;
			}

			if ( $state ) {
				return (bool) $wp_filesystem->put_contents( $file_path, '', FS_CHMOD_FILE );
			} else {
				return (bool) $wp_filesystem->delete( $file_path );
			}
		}

		return false;
	}
}
