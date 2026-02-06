<?php
/**
 * Nexter Google Recaptcha
 *
 * @package Nexter_Extension
 * @since 1.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize Google reCAPTCHA integration
 * Uses init hook for better performance and WordPress standards
 */
function nxt_recaptcha_init() {
	// Get and cache options once
	$option = get_option( 'nexter_site_security', array() );
	
	// Check if captcha-security exists and is enabled
	if ( empty( $option['captcha-security'] ) || ! is_array( $option['captcha-security'] ) ) {
		return;
	}
	
	if ( empty( $option['captcha-security']['switch'] ) ) {
		return;
	}
	
	// Get reoption values safely
	$reoption_local = isset( $option['captcha-security']['values'] ) && is_array( $option['captcha-security']['values'] ) 
		? $option['captcha-security']['values'] 
		: array();
	
	// Early exit if missing required data
	if ( empty( $reoption_local['siteKey'] ) || empty( $reoption_local['secretKey'] ) ) {
		return;
	}
	
	// Validate formType exists and is array
	if ( ! isset( $reoption_local['formType'] ) || ! is_array( $reoption_local['formType'] ) || empty( $reoption_local['formType'] ) ) {
		return;
	}
	
	// Set global for backward compatibility
	global $reoption;
	$reoption = $reoption_local;
	
	$form_types = $reoption['formType'];
	
	// Register hooks based on enabled form types
	nxt_recaptcha_register_hooks( $form_types );
}
add_action( 'init', 'nxt_recaptcha_init', 10 );

/**
 * Register all hooks based on enabled form types
 * Optimized to reduce hook registration overhead
 *
 * @param array $form_types Enabled form types
 */
function nxt_recaptcha_register_hooks( $form_types ) {
	// WordPress core forms
	if ( in_array( 'login_form', $form_types, true ) ) {
		add_action( 'login_form', 'nxt_login_display' );
		add_action( 'authenticate', 'nxt_login_check', 21, 1 );
	}
	
	if ( in_array( 'registration_form', $form_types, true ) ) {
		if ( ! is_multisite() ) {
			add_action( 'register_form', 'nxt_login_display', 99 );
			add_action( 'registration_errors', 'nxt_register_check', 10, 1 );
		} else {
			add_action( 'signup_extra_fields', 'nxt_signup_display' );
			add_action( 'signup_blogform', 'nxt_signup_display' );
			add_filter( 'wpmu_validate_user_signup', 'nxt_signup_check', 10, 3 );
		}
	}
	
	if ( in_array( 'reset_pwd_form', $form_types, true ) ) {
		add_action( 'lostpassword_form', 'nxt_login_display' );
		add_action( 'allow_password_reset', 'nxt_lostpassword_check' );
	}
	
	if ( in_array( 'comments_form', $form_types, true ) ) {
		add_action( 'comment_form_after_fields', 'nxt_commentform_display' );
		add_action( 'comment_form_logged_in_after', 'nxt_commentform_display' );
		add_action( 'pre_comment_on_post', 'nxt_comment_check' );
	}
	
	// WooCommerce forms
	if ( class_exists( 'WooCommerce' ) ) {
		nxt_recaptcha_register_woocommerce_hooks( $form_types );
	}
	
	// Admin hooks
	add_action( 'admin_footer', 'nxt_admin_footer' );
}

/**
 * Register WooCommerce specific hooks
 *
 * @param array $form_types Enabled form types
 */
function nxt_recaptcha_register_woocommerce_hooks( $form_types ) {
	if ( in_array( 'woo_checkout', $form_types, true ) ) {
		add_action( 'woocommerce_checkout_before_order_review', 'nxt_woo_checkout_display', 20 );
		add_action( 'woocommerce_review_order_before_payment', 'nxt_woo_checkout_display', 20 );
		add_action( 'woocommerce_checkout_process', 'nxt_woo_checkout_check' );
		add_action( 'wp_enqueue_scripts', 'nxt_woo_enqueue_captcha_scripts' );
	}
	
	if ( in_array( 'woo_pay_for_order', $form_types, true ) ) {
		add_action( 'woocommerce_pay_order_before_submit', 'nxt_woo_pay_for_order_display', 20 );
		add_action( 'woocommerce_review_order_before_submit', 'nxt_woo_pay_for_order_display', 20 );
		add_action( 'woocommerce_checkout_process', 'nxt_woo_pay_for_order_check' );
		add_action( 'wp_enqueue_scripts', 'nxt_woo_enqueue_captcha_scripts' );
	}
	
	if ( in_array( 'woo_login_form', $form_types, true ) ) {
		add_action( 'woocommerce_login_form', 'nxt_woo_login_display' );
		add_filter( 'woocommerce_process_login_errors', 'nxt_woo_login_check', 10, 3 );
	}
	
	if ( in_array( 'woo_registration_form', $form_types, true ) ) {
		add_action( 'woocommerce_register_form', 'nxt_woo_registration_display' );
		add_filter( 'woocommerce_registration_errors', 'nxt_woo_registration_check', 10, 3 );
	}
	
	if ( in_array( 'woo_reset_pwd_form', $form_types, true ) ) {
		add_action( 'woocommerce_lostpassword_form', 'nxt_woo_reset_pwd_display' );
		add_filter( 'woocommerce_lostpassword_validation', 'nxt_woo_reset_pwd_check', 10, 2 );
	}
}


/**
 * Get cached reoption data
 * Uses static variable for memory efficiency
 *
 * @return array|false Reoption data or false
 */
function nxt_get_reoption() {
	static $cached = null;
	if ( null === $cached ) {
		global $reoption;
		$cached = isset( $reoption ) ? $reoption : false;
	}
	return $cached;
}

/**
 * Generate and cache CSS for captcha display
 * Reduces redundant CSS generation
 *
 * @param string $selector CSS selector
 * @param string $margin Margin value
 * @return string Cached CSS
 */
function nxt_get_captcha_css( $selector, $margin = '15px' ) {
	static $css_cache = array();
	$cache_key = $selector . '_' . $margin;
	
	if ( ! isset( $css_cache[ $cache_key ] ) ) {
		$reoption = nxt_get_reoption();
		$css = $selector . ' .nxtcaptch { margin: 0 0 ' . esc_html( $margin ) . ';}';
		
		if ( is_array( $reoption ) && ! empty( $reoption['invisi'] ) ) {
			$css .= '.grecaptcha-badge { visibility: hidden;}';
		}
		
		$css_cache[ $cache_key ] = '<style>' . esc_html( $css ) . '</style>';
	}
	
	return $css_cache[ $cache_key ];
}

/**
 * Display recaptcha widget
 * Optimized with caching
 *
 * @param array|null $reData Optional reoption data override
 * @return string HTML output
 */
function nxt_recaptch_render( $reData = null ) {
	static $api_url_cache = array();
	static $scripts_added = false;
	
	$reoption = $reData ?: nxt_get_reoption();
	
	// Validate reoption is array and has required keys
	if ( ! is_array( $reoption ) || empty( $reoption['siteKey'] ) ) {
		return '';
	}
	
	$site_key = $reoption['siteKey'];
	
	// Cache API URL per site key
	if ( ! isset( $api_url_cache[ $site_key ] ) ) {
		$api_url_cache[ $site_key ] = sprintf( 'https://www.google.com/recaptcha/api.js?render=%s', esc_attr( $site_key ) );
	}
	
	$id = wp_generate_password( 8, false );
	$output = '<div class="nxtcaptch nexter-recaptcha-v3" data-id="nexter-recaptcha-' . esc_attr( $id ) . '">';
	$output .= '<input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" />';
	$output .= '</div>';
	
	// Register script once per site key
	if ( ! wp_script_is( 'nexter_recaptcha_api', 'registered' ) ) {
		wp_register_script( 'nexter_recaptcha_api', $api_url_cache[ $site_key ], array(), NEXTER_EXT_VER, false );
	}
	
	// Add footer action once
	if ( ! $scripts_added && ! has_action( 'wp_footer', 'nxtcptch_add_scripts' ) ) {
		add_action( 'wp_footer', 'nxtcptch_add_scripts' );
		$scripts_added = true;
	}
	
	// Add login footer action if needed
	$form_types = isset( $reoption['formType'] ) ? (array) $reoption['formType'] : array();
	$login_forms = array( 'login_form', 'registration_form', 'reset_pwd_form' );
	if ( array_intersect( $login_forms, $form_types ) && ! has_action( 'login_footer', 'nxtcptch_add_scripts' ) ) {
		add_action( 'login_footer', 'nxtcptch_add_scripts' );
	}
	
	return $output;
}

/**
 * Add recaptcha scripts
 * Optimized to prevent duplicate enqueuing
 */
function nxtcptch_add_scripts() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;
	
	$reoption = nxt_get_reoption();
	if ( ! is_array( $reoption ) || empty( $reoption['siteKey'] ) ) {
		return;
	}
	
	nxt_remove_scripts();
	
	$options = array(
		'version' => 'v3',
		'sitekey' => isset( $reoption['siteKey'] ) ? $reoption['siteKey'] : '',
		'theme'   => 'light',
	);
	
	wp_enqueue_script(
		'nxtcptch_script',
		NEXTER_EXT_URL . 'assets/js/main/nexter-recaptcha.min.js',
		array( 'jquery', 'nexter_recaptcha_api' ),
		NEXTER_EXT_VER,
		true
	);
	
	wp_localize_script(
		'nxtcptch_script',
		'nxtcptch',
		array(
			'options' => $options,
			'vars'    => array(
				'visibility' => ( 'login_footer' === current_filter() ),
			),
		)
	);
	
	if ( ! empty( $reoption['invisi'] ) ) {
		echo '<style>.grecaptcha-badge{visibility:hidden}</style>';
	}
}

/**
 * Enqueue captcha scripts for WooCommerce pages
 */
function nxt_woo_enqueue_captcha_scripts() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	
	// Security: Sanitize input
	$get_pay_for_order = isset( $_GET['pay_for_order'] ) ? sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) ) : '';
	if ( ! is_checkout() && empty( $get_pay_for_order ) && ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}
	
	$reoption = nxt_get_reoption();
	if ( ! is_array( $reoption ) || empty( $reoption['siteKey'] ) ) {
		return;
	}
	
	$api_url = sprintf( 'https://www.google.com/recaptcha/api.js?render=%s', esc_attr( $reoption['siteKey'] ) );
	
	if ( ! wp_script_is( 'nexter_recaptcha_api', 'registered' ) ) {
		wp_register_script( 'nexter_recaptcha_api', $api_url, array(), NEXTER_EXT_VER, false );
	}
	
	if ( ! has_action( 'wp_footer', 'nxtcptch_add_scripts' ) ) {
		add_action( 'wp_footer', 'nxtcptch_add_scripts' );
	}
}

/**
 * Check if current page is WooCommerce related
 * Optimized with limited backtrace
 *
 * @return bool
 */
function nxt_is_woocommerce_page() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	
	$traces = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
	foreach ( $traces as $trace ) {
		if ( isset( $trace['file'] ) && false !== strpos( $trace['file'], 'woocommerce' ) ) {
			$cache = true;
			return true;
		}
	}
	$cache = false;
	return false;
}

/**
 * Check Google reCAPTCHA validation
 * Optimized with early exits and better error handling
 *
 * @param string $form Form identifier
 * @param bool   $debug Debug mode
 * @return array Validation result
 */
function nxt_recaptch_check( $form = 'general', $debug = false ) {
	$reoption = nxt_get_reoption();
	
	if ( ! is_array( $reoption ) || empty( $reoption['siteKey'] ) || empty( $reoption['secretKey'] ) ) {
		$errors = new WP_Error();
		$errors->add( 'nxtcptch_error', nxttch_get_error_message() );
		return array(
			'response' => false,
			'reason'  => 'ERROR_NO_KEYS',
			'errors'  => $errors,
		);
	}
	
	$recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) ) : '';
	
	if ( empty( $recaptcha_response ) ) {
		$result = array(
			'response' => false,
			'reason'   => isset( $_POST['g-recaptcha-response'] ) ? 'RECAPTCHA_EMPTY_RESPONSE' : 'RECAPTCHA_NO_RESPONSE',
		);
	} else {
		// Security: Properly sanitize and validate IP address
		$server_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$server_ip = filter_var( $server_ip, FILTER_VALIDATE_IP ) ?: '';
		$response  = nxt_get_recpt_respo( $reoption['secretKey'], $server_ip );
		
		if ( empty( $response ) || ! is_array( $response ) ) {
			$result = array(
				'response' => false,
				'reason'   => $debug ? __( 'Response is empty', 'nexter-extension' ) : 'VERIFICATION_FAILED',
			);
		} elseif ( ! empty( $response['success'] ) && true === $response['success'] ) {
			$result = array(
				'response' => true,
				'reason'   => '',
			);
		} else {
			$error_codes = isset( $response['error-codes'] ) ? (array) $response['error-codes'] : array();
			$secret_errors = array( 'missing-input-secret', 'invalid-input-secret' );
			
			if ( ! $debug && array_intersect( $secret_errors, $error_codes ) ) {
				$result = array(
					'response' => false,
					'reason'   => 'ERROR_WRONG_SECRET',
				);
			} else {
				$result = array(
					'response' => false,
					'reason'   => $debug ? $error_codes : 'VERIFICATION_FAILED',
				);
			}
		}
	}
	
	if ( ! $result['response'] ) {
		$result['errors'] = new WP_Error();
		if ( ! $debug ) {
			$result['errors']->add( 'nxtcptch_error', nxttch_get_error_message( $result['reason'] ) );
		}
	}
	
	return $result;
}

/**
 * Get error message
 * Cached for performance
 *
 * @param string $message_code Error code
 * @param bool   $display Display or return
 * @return string Error message
 */
function nxttch_get_error_message( $message_code = 'incorrect', $display = false ) {
	static $error_messages = null;
	
	if ( null === $error_messages ) {
		$error_messages = array(
			'missing-input-secret'     => __( 'Secret Key is missing.', 'nexter-extension' ),
			'invalid-input-secret'     => sprintf(
				'<strong>%s</strong> <a target="_blank" href="https://www.google.com/recaptcha/admin#list" rel="noopener noreferrer">%s</a> %s.',
				__( 'Secret Key is invalid.', 'nexter-extension' ),
				__( 'Check your domain configurations', 'nexter-extension' ),
				__( 'and enter it again', 'nexter-extension' )
			),
			'incorrect'                => __( 'You have entered an incorrect reCAPTCHA value.', 'nexter-extension' ),
			'multiple_blocks'          => __( 'More than one reCAPTCHA has been found in the current form. Please remove all unnecessary reCAPTCHA fields to make it work properly.', 'nexter-extension' ),
			'incorrect-captcha-sol'    => __( 'User response is invalid', 'nexter-extension' ),
			'RECAPTCHA_SMALL_SCORE'    => __( 'reCaptcha v3 test failed', 'nexter-extension' ),
			'RECAPTCHA_EMPTY_RESPONSE' => __( 'User response is missing.', 'nexter-extension' ),
			'ERROR_WRONG_SECRET'       => __( 'You have entered incorrect secret key.', 'nexter-extension' ),
		);
	}
	
	$errormsg = isset( $error_messages[ $message_code ] ) ? $error_messages[ $message_code ] : $error_messages['incorrect'];
	
	if ( $display ) {
		echo wp_kses_post( $errormsg );
	}
	
	return $errormsg;
}

/**
 * Get response from reCAPTCHA API
 * Optimized with timeout and error handling
 *
 * @param string $key Secret key
 * @param string $server_ip Server IP
 * @return array|false API response or false on error
 */
function nxt_get_recpt_respo( $key, $server_ip ) {
	$recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
	
	$response = wp_remote_post(
		'https://www.google.com/recaptcha/api/siteverify',
		array(
			'body'      => array(
				'secret'   => $key,
				'response' => $recaptcha_response,
				'remoteip' => $server_ip,
			),
			'sslverify' => true, // Security: Enable SSL verification
			'timeout'   => 5,
		)
	);
	
	if ( is_wp_error( $response ) ) {
		return array( 'success' => false );
	}
	
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	return is_array( $data ) ? $data : array( 'success' => false );
}

/**
 * Remove duplicate reCAPTCHA scripts
 * Optimized iteration
 */
function nxt_remove_scripts() {
	global $wp_scripts;
	if ( ! is_object( $wp_scripts ) || empty( $wp_scripts->registered ) ) {
		return;
	}
	
	foreach ( $wp_scripts->registered as $script_name => $args ) {
		if ( 'nexter_recaptcha_api' !== $script_name && isset( $args->src ) && false !== strpos( $args->src, 'google.com/recaptcha/api.js' ) ) {
			wp_dequeue_script( $script_name );
		}
	}
}

/**
 * Display recaptcha on login form
 */
function nxt_login_display() {
	echo nxt_recaptch_render();
}

/**
 * Check reCAPTCHA on login
 *
 * @param WP_User|WP_Error $user User object or error
 * @return WP_User|WP_Error
 */
function nxt_login_check( $user ) {
	// Early exits
	if ( nxt_is_woocommerce_page() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return $user;
	}
	
	if ( is_wp_error( $user ) && isset( $user->errors['empty_username'], $user->errors['empty_password'] ) ) {
		return $user;
	}
	
	$nxrecap_check = nxt_recaptch_check( 'login_form' );
	
	if ( ! $nxrecap_check['response'] ) {
		if ( 'VERIFICATION_FAILED' === $nxrecap_check['reason'] ) {
			wp_clear_auth_cookie();
		}
		
		$error_code = is_wp_error( $user ) ? $user->get_error_code() : 'incorrect_password';
		$errors     = new WP_Error( $error_code, __( 'Authentication failed.', 'nexter-extension' ) );
		
		if ( isset( $nxrecap_check['errors'] ) && is_wp_error( $nxrecap_check['errors'] ) ) {
			foreach ( $nxrecap_check['errors']->get_error_codes() as $code ) {
				foreach ( $nxrecap_check['errors']->get_error_messages( $code ) as $message ) {
					$errors->add( $code, $message );
				}
			}
		}
		return $errors;
	}
	
	return $user;
}

/**
 * Check reCAPTCHA on registration
 *
 * @param WP_Error $errors Registration errors
 * @return WP_Error
 */
function nxt_register_check( $errors ) {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return $errors;
	}
	
	$nxrecap_check = nxt_recaptch_check( 'registration_form' );
	if ( empty( $nxrecap_check['response'] ) ) {
		return isset( $nxrecap_check['errors'] ) ? $nxrecap_check['errors'] : $errors;
	}
	
	$_POST['g-recaptcha-response-check'] = true;
	return $errors;
}

/**
 * Display signup recaptcha
 *
 * @param WP_Error $errors Signup errors
 */
function nxt_signup_display( $errors ) {
	$error_message = $errors->get_error_message( 'nxtcptch_error' );
	if ( $error_message ) {
		printf( '<p class="error nxtcptch_error">%s</p>', wp_kses_post( $error_message ) );
	}
	echo nxt_recaptch_render();
}

/**
 * Check signup form
 *
 * @param array $result Signup result
 * @return array
 */
function nxt_signup_check( $result ) {
	global $current_user;
	if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! empty( $current_user->data->ID ) ) {
		return $result;
	}
	
	$nxtcptch_check = nxt_recaptch_check( 'registration_form' );
	if ( empty( $nxtcptch_check['response'] ) && isset( $nxtcptch_check['errors'] ) ) {
		$result['errors']->add( 'nxtcptch_error', $nxtcptch_check['errors'] );
	}
	return $result;
}

/**
 * Check lost password form
 *
 * @param WP_Error|bool $allow Allow password reset
 * @return WP_Error|bool
 */
function nxt_lostpassword_check( $allow ) {
	// If recaptcha was already validated, it won't be checked again
	static $recaptcha_validated = false;
	if ( $recaptcha_validated ) {
		return $allow;
	}
	
	$nxtcptch_check = nxt_recaptch_check( 'reset_pwd_form' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		return isset( $nxtcptch_check['errors'] ) ? $nxtcptch_check['errors'] : $allow;
	}
	return $allow;
}

/**
 * Display recaptcha in comment form
 */
function nxt_commentform_display() {
	echo nxt_get_captcha_css( '#commentform', '10px' );
	echo nxt_recaptch_render();
}

/**
 * Check recaptcha for comment form
 */
function nxt_comment_check() {
	$nxtcptch_check = nxt_recaptch_check( 'comments_form' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		$message       = nxttch_get_error_message( $nxtcptch_check['reason'] ) . '<br />';
		$error_message = sprintf(
			'<strong>%s</strong>:&nbsp;%s&nbsp;%s',
			__( 'Error', 'nexter-extension' ),
			$message,
			__( 'Click the BACK button on your browser and try again.', 'nexter-extension' )
		);
		wp_die( wp_kses_post( $error_message ) );
	}
}

/**
 * WooCommerce Checkout - Display recaptcha
 */
function nxt_woo_checkout_display() {
	static $displayed = false;
	if ( $displayed ) {
		return;
	}
	$displayed = true;
	
	echo nxt_get_captcha_css( '.woocommerce-checkout', '20px' );
	echo nxt_recaptch_render();
	
	if ( ! has_action( 'wp_footer', 'nxtcptch_add_scripts' ) ) {
		add_action( 'wp_footer', 'nxtcptch_add_scripts' );
	}
}

/**
 * WooCommerce Checkout - Check recaptcha
 */
function nxt_woo_checkout_check() {
	$nxtcptch_check = nxt_recaptch_check( 'woo_checkout' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		wc_add_notice( nxttch_get_error_message( $nxtcptch_check['reason'] ), 'error' );
	}
}

/**
 * WooCommerce Pay For Order - Display recaptcha
 */
function nxt_woo_pay_for_order_display() {
	static $displayed = false;
	if ( $displayed ) {
		return;
	}
	$displayed = true;
	
	echo nxt_get_captcha_css( '.woocommerce-order-pay', '20px' );
	echo nxt_recaptch_render();
	
	if ( ! has_action( 'wp_footer', 'nxtcptch_add_scripts' ) ) {
		add_action( 'wp_footer', 'nxtcptch_add_scripts' );
	}
}

/**
 * WooCommerce Pay For Order - Check recaptcha
 */
function nxt_woo_pay_for_order_check() {
	// Security: Sanitize input
	$get_pay_for_order = isset( $_GET['pay_for_order'] ) ? sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) ) : '';
	$get_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( empty( $get_pay_for_order ) || empty( $get_key ) ) {
		return;
	}
	
	$nxtcptch_check = nxt_recaptch_check( 'woo_pay_for_order' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		wc_add_notice( nxttch_get_error_message( $nxtcptch_check['reason'] ), 'error' );
	}
}

/**
 * WooCommerce Login Form - Display recaptcha
 */
function nxt_woo_login_display() {
	echo nxt_get_captcha_css( '.woocommerce-form-login', '15px' );
	echo nxt_recaptch_render();
}

/**
 * WooCommerce Login Form - Check recaptcha
 *
 * @param WP_Error $validation_error Validation errors
 * @param string   $username Username
 * @param string   $password Password
 * @return WP_Error
 */
function nxt_woo_login_check( $validation_error, $username, $password ) {
	$nxtcptch_check = nxt_recaptch_check( 'woo_login_form' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		$validation_error->add( 'nxtcptch_error', nxttch_get_error_message( $nxtcptch_check['reason'] ) );
	}
	return $validation_error;
}

/**
 * WooCommerce Registration Form - Display recaptcha
 */
function nxt_woo_registration_display() {
	echo nxt_get_captcha_css( '.woocommerce-form-register', '15px' );
	echo nxt_recaptch_render();
}

/**
 * WooCommerce Registration Form - Check recaptcha
 *
 * @param WP_Error $validation_error Validation errors
 * @param string   $username Username
 * @param string   $email Email
 * @return WP_Error
 */
function nxt_woo_registration_check( $validation_error, $username, $email ) {
	$nxtcptch_check = nxt_recaptch_check( 'woo_registration_form' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		$validation_error->add( 'nxtcptch_error', nxttch_get_error_message( $nxtcptch_check['reason'] ) );
	}
	return $validation_error;
}

/**
 * WooCommerce Reset Password Form - Display recaptcha
 */
function nxt_woo_reset_pwd_display() {
	echo nxt_get_captcha_css( '.woocommerce-ResetPassword', '15px' );
	echo nxt_recaptch_render();
}

/**
 * WooCommerce Reset Password Form - Check recaptcha
 *
 * @param WP_Error $errors Errors
 * @param string   $user_login User login
 * @return WP_Error
 */
function nxt_woo_reset_pwd_check( $errors, $user_login ) {
	$nxtcptch_check = nxt_recaptch_check( 'woo_reset_pwd_form' );
	if ( empty( $nxtcptch_check['response'] ) ) {
		$errors->add( 'nxtcptch_error', nxttch_get_error_message( $nxtcptch_check['reason'] ) );
	}
	return $errors;
}

/**
 * Admin footer - Optimized
 */
function nxt_admin_footer() {
	$option = get_option( 'nexter_extra_ext_options', array() );
	if ( empty( $option['captcha-security'] ) || ! is_array( $option['captcha-security'] ) || empty( $option['captcha-security']['switch'] ) ) {
		return;
	}
	
	$reoption = nxt_get_reoption();
	if ( ! is_array( $reoption ) || empty( $reoption['siteKey'] ) ) {
		return;
	}
	
	$api_url = sprintf( 'https://www.google.com/recaptcha/api.js?render=%s', esc_attr( $reoption['siteKey'] ) );
	if ( ! wp_script_is( 'nexter_recaptcha_api', 'registered' ) ) {
		wp_register_script( 'nexter_recaptcha_api', $api_url, array(), NEXTER_EXT_VER, false );
		add_action( 'wp_footer', 'nxtcptch_add_scripts' );
	}
	nxtcptch_add_scripts();
}
