<?php 
/*
 * Nexter Custom Login Redirect
 * @since 1.1.0
 */

defined( 'ABSPATH' ) or die();

class Nexter_Ext_Custom_Login_Redirect {

	/**
	 * Store Login Option 
	 * @var string
	 */
	public $cusloOption;

	/**
	 * Redirect Login Url
	 * @var Boolean
	 */
	public $nxt_custom_login = false;

	/**
	 * Constructor
	 */

	public function __construct() {
		
		$this->cusloOption = Nxt_Options::security();
		
		if ( isset( $this->cusloOption['custom-login'] ) && ! empty( $this->cusloOption['custom-login'] ) && isset( $this->cusloOption['custom-login']['switch'] ) && ! empty( $this->cusloOption['custom-login']['switch'] ) ) {
			if ( isset( $this->cusloOption['custom-login']['values'] ) && ! empty( $this->cusloOption['custom-login']['values'] ) ) {
				$values            = $this->cusloOption['custom-login']['values'];
				$this->cusloOption = is_array( $values ) ? $values : (array) $values;
			}
		}

		if ( isset( $this->cusloOption['custom_login_url'] ) && ! empty( $this->cusloOption['custom_login_url'] ) && ! defined( 'WP_CLI' ) ) {

			// Hook into 'init' so this class can be safely loaded on 'plugins_loaded'
			// from the main plugin file without missing the current request.
			add_action( 'init', [ $this,'nxt_login_plugins_loaded'], 2 );
			add_action( 'wp_loaded', [ $this,'nxt_wp_loaded'] );
			add_action( 'setup_theme', [ $this , 'nxt_login_customizer_redirect'], 1 );

			add_filter( 'site_url', [ $this ,'nxt_login_site_url'], 10, 4 );
			add_filter( 'network_site_url',  [ $this ,'nxt_login_netwrok_site_url'], 10, 3 );
			add_filter( 'wp_redirect', [ $this ,'nxt_login_wp_redirect'], 10, 2 );
			
			add_filter( 'site_option_welcome_email',  [ $this ,'nxt_login_welcome_email'] );
			
			remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
			add_filter( 'admin_url', [ $this ,'nxt_login_admin_url'] );
		}

	}
	
	/**
	 * Nexter Custom Login Load
	 * @since 1.1.0
	 */

	public function nxt_login_plugins_loaded(){
		global $pagenow;
		
		// Security: Sanitize REQUEST_URI
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
		
		if ( ! is_multisite() && ( strpos( $request_uri, 'wp-signup' ) !== false || strpos( $request_uri, 'wp-activate' ) !== false ) ) {
			// Plain wp_die() answers 500; these are simply not available here, so say 404.
			nocache_headers();
			wp_die( esc_html__( 'This feature is not enabled.', 'nexter-extension' ), esc_html__( 'Not Found', 'nexter-extension' ), array( 'response' => 404 ) );
		}

		// Security: Sanitize REQUEST_URI to prevent XSS
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
		$request_URI = wp_parse_url( $request_uri );
		$path        = ! empty( $request_URI['path'] ) ? untrailingslashit( $request_URI['path'] ) : '';
		
		$login_slug     = $this->nxt_custom_login_slug();
		$get_login_slug = '';
		if ( ! empty( $login_slug ) && isset( $_GET[ $login_slug ] ) ) {
			$get_login_slug = sanitize_text_field( wp_unslash( $_GET[ $login_slug ] ) );
		}

		if ( ! is_admin() && ( strpos( rawurldecode( $request_uri ), 'wp-login.php' ) !== false || $path === site_url( 'wp-login', 'relative' ) ) ) {
			//wp-login.php URL 
			$this->nxt_custom_login = true;
	
			$_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
			$pagenow                = 'index.php';
			
		} else if ( ! is_admin() && ( strpos( rawurldecode( $request_uri ), 'wp-register.php' ) !== false || $path === site_url( 'wp-register', 'relative' ) ) ) {
			//wp-register.php
			$this->nxt_custom_login = true;
	
			//Prevent Redirect to Hidden Login
			$_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
			$pagenow                = 'index.php';
			
		} else if ( $path === home_url( $login_slug, 'relative' ) || ( ! get_option( 'permalink_structure' ) && ! empty( $get_login_slug ) ) ) {
			//Hidden Login URL
			$pagenow = 'wp-login.php';
		}

	}

	/**
	 * True when PHP is executing one of WordPress's standalone login scripts.
	 *
	 * @return bool
	 */
	private function nxt_is_standalone_login_script() {
		// These files never run the main query, so a 404 forced on the 'wp' hook never fires and the
		// login form would render anyway.
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';

		return in_array( $script, array( 'wp-login.php', 'wp-register.php', 'wp-signup.php', 'wp-activate.php' ), true );
	}
	/**
	 * Get Nexter Custom Login Url
	 * @since 1.1.0
	 */
	public function nxt_custom_login_slug() {
		if ( isset( $this->cusloOption['custom_login_url'] ) && ! empty( $this->cusloOption['custom_login_url'] ) ) {
			return $this->cusloOption['custom_login_url'];
		}
	}

	/** 
	 * login wp_loaded
	 * @since 1.1.0
	 */

	public function nxt_wp_loaded(){
		global $pagenow;

		//redirect disable WP-Admin
		// Security: Sanitize GET parameters
		$get_adminhash    = isset( $_GET['adminhash'] ) ? sanitize_text_field( wp_unslash( $_GET['adminhash'] ) ) : '';
		$get_newuseremail = isset( $_GET['newuseremail'] ) ? sanitize_email( wp_unslash( $_GET['newuseremail'] ) ) : '';
		if ( is_admin() && ! is_user_logged_in() && ! defined( 'DOING_AJAX' ) && $pagenow !== 'admin-post.php' && ( empty( $get_adminhash ) && empty( $get_newuseremail ) ) ) {
			$this->nxt_redirect_login_url();
			//You must log in to access the admin area
		}
		
		// Security: Sanitize REQUEST_URI
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
		$request_URI  = wp_parse_url( $request_uri );
		$request_path = isset( $request_URI['path'] ) ? $request_URI['path'] : '';
		
		if ( ! is_user_logged_in() && $request_path === '/wp-admin/options.php' ) {
			wp_safe_redirect( $this->nxt_new_login_url() );
			exit;
		}
		
		//wp-login Form - Path Mismatch
		if ( $pagenow === 'wp-login.php' && $request_path !== $this->nxt_user_trailingslashit( $request_path ) && get_option( 'permalink_structure' ) ) {

			//Redirect Login New URL
			$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
			// Security: Use add_query_arg to properly build URL
			$redirect_URL = $this->nxt_user_trailingslashit( $this->nxt_new_login_url() );
			if ( ! empty( $query_string ) ) {
				parse_str( $query_string, $query_params );
				$redirect_URL = add_query_arg( $query_params, $redirect_URL );
			}
			wp_safe_redirect( esc_url_raw( $redirect_URL ) );
			exit;
		} else if ( $this->nxt_custom_login ) {
			//wp-login.php Directly
			$this->nxt_redirect_login_url();
			
		} else if ( $pagenow === 'wp-login.php' ) {
			//Login Form
			
			global $error, $interim_login, $action, $user_login;
			
			//User Already Logged In
			// Security: Use $_GET instead of $_REQUEST for better security
			$get_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
			if ( is_user_logged_in() && empty( $get_action ) ) {
				wp_safe_redirect( admin_url() );
				die();
			}

			@require_once ABSPATH . 'wp-login.php';
			die();
		}
	}

	/**
	 * disabling a login url redirect
	 * @since 1.1.0
	 */

	public function nxt_redirect_login_url() {
		if ( ! empty( $this->cusloOption['disable_login_url_behavior'] ) ) {
			if ( $this->cusloOption['disable_login_url_behavior'] == 'home_page' ) {
				wp_safe_redirect( esc_url_raw( home_url() ) );
				exit;
			} else if ( $this->cusloOption['disable_login_url_behavior'] == 'custom_url' ) {
				// Send blocked login/admin requests to a front-end page instead (a custom login page
				// built with the site's builder). wp_safe_redirect() keeps this on the site's own
				// host, so the setting can never be turned into an open redirect.
				$target = $this->nxt_resolve_login_redirect_target();
				if ( '' !== $target ) {
					wp_safe_redirect( esc_url_raw( $target ) );
					exit;
				}
				// No usable URL configured (or it would loop) — fall through to the message below
				// rather than silently doing nothing.
			} else if ( $this->cusloOption['disable_login_url_behavior'] == '404_page' ) {
				// Render the 404 through WordPress's OWN template flow rather than loading the
				// theme's 404.php from here.
				//
				// This method runs on 'wp_loaded' — before the main query has run. Calling
				// get_template_part( '404' ) at that point loaded the theme template (and therefore
				// get_header() / wp_head()) outside the context it expects: no queried object, and
				// is_404()/body classes unresolved. The result was a partially rendered page — site
				// title and footer, but no 404 content — with unrelated deprecation notices dumped
				// at the top when WP_DEBUG display is on.
				if ( is_admin() || $this->nxt_is_standalone_login_script() ) {
					// wp-admin and wp-login.php never run the front-end template flow, so a theme 404
					// cannot be rendered here. Answer in place with a real 404 rather than redirecting
					// a made-up path, which put a nonsense URL in the address bar. Returning instead
					// would let core's auth_redirect() expose the login screen.
					nocache_headers();
					$not_found = ! empty( $this->cusloOption['login_page_message'] )
						? wp_kses_post( $this->cusloOption['login_page_message'] )
						: esc_html__( 'This has been disabled.', 'nexter-extension' );
					wp_die( $not_found, esc_html__( 'Not Found', 'nexter-extension' ), array( 'response' => 404 ) );
				}
				// Front-end: nxt_login_plugins_loaded() has already rewritten REQUEST_URI to an
				// unmatchable path, so we only need to force the 404 flags once the query exists
				// (on 'wp'). WordPress then picks 404.php itself, at the right time.
				\add_action(
					'wp',
					static function () {
						global $wp_query;
						if ( $wp_query instanceof WP_Query ) {
							$wp_query->set_404();
						}
						if ( function_exists( 'status_header' ) ) {
							status_header( 404 );
							nocache_headers();
						}
					},
					1
				);
				return;
			}
		}

		// Security: Escape message output
		$message = ! empty( $this->cusloOption['login_page_message'] ) ? wp_kses_post( $this->cusloOption['login_page_message'] ) : esc_html__( 'This has been disabled.', 'nexter-extension' );
		wp_die( $message, esc_html__( 'Forbidden', 'nexter-extension' ), array('response' => 403) );
	}

	/**
	 * Resolve the configured "Custom URL" login redirect target.
	 *
	 * Accepts either a full URL on this site or a relative path/slug ("member-login",
	 * "/member-login/"). Returns '' when nothing usable is configured, when the URL points off-site,
	 * or when redirecting would loop back to the request we are already handling.
	 *
	 * @since 4.7.4
	 * @return string Absolute URL on this site, or '' when unusable.
	 */
	private function nxt_resolve_login_redirect_target() {
		$raw = isset( $this->cusloOption['login_redirect_url'] ) ? trim( (string) $this->cusloOption['login_redirect_url'] ) : '';
		if ( '' === $raw ) {
			return '';
		}

		// Relative path/slug -> absolute URL on this site.
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$target = home_url( '/' . ltrim( $raw, '/' ) );
		} else {
			$target      = $raw;
			$target_host = wp_parse_url( $target, PHP_URL_HOST );
			$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );

			// Recover a slug that an earlier build stored as a bogus host: esc_url_raw('member-login')
			// yields 'http://member-login'. A "host" with no dot that is not this site's host is not
			// a real domain, so treat the whole value as a site-relative path instead of discarding
			// the user's setting.
			if ( $target_host && false === strpos( $target_host, '.' )
				&& ( ! $home_host || strtolower( $target_host ) !== strtolower( $home_host ) ) ) {
				$recovered_path = (string) wp_parse_url( $target, PHP_URL_PATH );
				$target         = home_url( '/' . ltrim( $target_host . $recovered_path, '/' ) );
			} elseif ( $target_host && $home_host && strtolower( $target_host ) !== strtolower( $home_host ) ) {
				// A genuine different host: this is a login redirect, it must stay on-site.
				return '';
			}
		}

		// Loop guard: never redirect to the path we are currently serving, and never to the login
		// endpoints this class itself intercepts (that would bounce forever).
		$target_path  = (string) wp_parse_url( $target, PHP_URL_PATH );
		$current_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$norm         = static function ( $p ) {
			return trailingslashit( strtolower( (string) $p ) );
		};
		if ( '' !== $current_path && $norm( $target_path ) === $norm( $current_path ) ) {
			return '';
		}
		if ( preg_match( '#/(?:wp-login\.php|wp-admin)(?:/|$)#i', $target_path ) ) {
			return '';
		}
		$custom_slug = isset( $this->cusloOption['custom_login_url'] ) ? trim( (string) $this->cusloOption['custom_login_url'], '/' ) : '';
		if ( '' !== $custom_slug && $norm( $target_path ) === $norm( '/' . $custom_slug ) ) {
			return '';
		}

		return $target;
	}

	/**
	 * Login Customize.php Redirect Not Login
	 * @since 1.1.0
	 */

	public function nxt_login_customizer_redirect(){
		global $pagenow;

		if ( ! is_user_logged_in() && $pagenow === 'customize.php' ) {
			$this->nxt_redirect_login_url();
		}
	}

	/**
	 * Site Url
	 * @since 1.1.0
	 */

	public function nxt_login_site_url( $url, $path, $scheme, $blog_id ){
		return $this->nxt_filter_login_php( $url, $scheme );
	}

	/**
	 * Nextwork Site Url
	 * @since 1.1.0
	 */

	public function nxt_login_netwrok_site_url( $url, $path, $scheme ){
		return $this->nxt_filter_login_php( $url, $scheme );
	}
	
	/**
	 * Login Wp Redirect
	 * @since 1.1.0
	 */

	public function nxt_login_wp_redirect( $location, $status ) {
		return $this->nxt_filter_login_php( $location );
	}

	/**
	 * Filter Login
	 * @since 1.1.0
	 */

	public function nxt_filter_login_php( $url, $scheme = null ){
		
		if ( strpos( $url, 'wp-login.php' ) !== false ) {
			
			if ( is_ssl() ) {
				$scheme = 'https';
			}

			$url_args = explode( '?', $url );

			if ( isset( $url_args[1] ) ) {
				parse_str( $url_args[1], $url_args );
				if ( isset( $url_args['login'] ) ) {
					$url_args['login'] = rawurlencode( $url_args['login'] );
				}
				$url = add_query_arg( $url_args, $this->nxt_new_login_url( $scheme ) );
			} else {
				$url = $this->nxt_new_login_url( $scheme );
			}
		}

		return $url;
	}

	/**
	 * Login Welcome Email
	 * @since 1.1.0
	 */

	public function nxt_login_welcome_email( $value ) {

		if ( isset( $this->cusloOption['custom_login_url'] ) && ! empty( $this->cusloOption['custom_login_url'] ) ) {
			$value = str_replace( array('wp-login.php', 'wp-admin'), trailingslashit( $this->cusloOption['custom_login_url'] ), $value );
		}
	
		return $value;
	}

	/**
	 * Admin Url Login
	 * @since 1.1.0
	 */

	public function nxt_login_admin_url( $url ){
	
		if ( is_multisite() && ms_is_switched() && is_admin() ) {
	
			global $current_blog;
			$current_blog_id = get_current_blog_id();
	
			if ( $current_blog_id != $current_blog->blog_id ) {
	
				if ( ! empty( $this->cusloOption['custom_login_url'] ) ) {
					$url = preg_replace( '/\/wp-admin\/$/', '/' . $this->cusloOption['custom_login_url'] . '/', $url );
				} 
			}
		}
	
		return $url;
	}

	/**
	 * Check for Permalink Trailing Slash and Add to String
	 * @since 1.1.0
	 */

	public function nxt_user_trailingslashit($string) {
		if ( '/' === substr( get_option( 'permalink_structure' ), -1, 1 ) ) {
			return trailingslashit( $string );
		} else {
			return untrailingslashit( $string );
		}
	}

	/**
	 * New Login Url
	 * @since 1.1.0
	 */
	
	public function nxt_new_login_url( $scheme = null ){
		if ( get_option( 'permalink_structure' ) ) {
			return $this->nxt_user_trailingslashit( home_url( '/', $scheme ) . $this->nxt_custom_login_slug() );
		} else {
			return home_url( '/', $scheme ) . '?' . $this->nxt_custom_login_slug();
		}
	}
}
