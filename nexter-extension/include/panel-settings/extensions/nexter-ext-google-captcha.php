<?php
/*
* Nexter Google Recaptcha
* @since 1.1.0
*/
global $reoption;
add_action( 'admin_footer', 'nxt_admin_footer' );
$option = get_option( 'nexter_site_security' );
$reoption = ( isset( $option['captcha-security'] ) && !empty($option['captcha-security']) && isset( $option['captcha-security']['values'] ) &&  !empty( $option['captcha-security']['values'] ) ) ? $option['captcha-security']['values'] : '';

if( ( isset( $option['captcha-security']['switch'] ) && !empty( $option['captcha-security']['switch'] ) ) && ( isset($reoption['siteKey']) && !empty($reoption['siteKey'] ) ) && ( isset($reoption['secretKey']) && !empty($reoption['secretKey'] ) ) && ( ( isset($reoption['formType']) && !empty($reoption['formType']) ) ) ){
    
    if( in_array( 'login_form' , $reoption['formType'] ) ){
        add_action( 'login_form', 'nxt_login_display' );
        add_action( 'authenticate', 'nxt_login_check', 21, 1 );
    }

    if( in_array( 'registration_form' , $reoption['formType'] ) ){
        if ( ! is_multisite() ) {
            add_action( 'register_form', 'nxt_login_display', 99 );
			add_action( 'registration_errors', 'nxt_register_check', 10, 1 );
        }else{
            add_action( 'signup_extra_fields', 'nxt_signup_display' );
            add_action( 'signup_blogform', 'nxt_signup_display' );
            add_filter( 'wpmu_validate_user_signup', 'nxt_signup_check', 10, 3 );
        }
    }

    if( in_array( 'reset_pwd_form' , $reoption['formType'] ) ){
        add_action( 'lostpassword_form', 'nxt_login_display' );
        add_action( 'allow_password_reset', 'nxt_lostpassword_check' );
    }

    if( in_array( 'comments_form' , $reoption['formType'] ) ){
        add_action( 'comment_form_after_fields', 'nxt_commentform_display' );
        add_action( 'comment_form_logged_in_after', 'nxt_commentform_display' );
        add_action( 'pre_comment_on_post', 'nxt_comment_check' );
    }
}
/**
 * display recaptcha 
 */

if ( ! function_exists( 'nxt_login_display' ) ) {
    function nxt_login_display(){
        global $reoption;
        echo nxt_recaptch_render($reoption);
        
        return true;
    }
}

/**
 * display recaptcha 
 */

if ( ! function_exists( 'nxt_recaptch_render' ) ) {
    function nxt_recaptch_render($reData){
        $output = '';
        global $reoption;
        $api_url = sprintf( 'https://www.google.com/recaptcha/api.js?render=%s', $reoption['siteKey'] );

        //  Google Recaptcha Html
        $id = mt_rand();
        $output .= '<div class="nxtcaptch nexter-recaptcha-v3" data-id="nexter-recaptcha-' . esc_attr($id) . '" >';
            $output .= '<input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response" />';
        $output .= '</div>';

        /* register reCAPTCHA script */
        if ( ! wp_script_is( 'nexter_recaptcha_api', 'registered' ) ) {
            wp_register_script( 'nexter_recaptcha_api', $api_url, false, NEXTER_EXT_VER, false );
            add_action( 'wp_footer', 'nxtcptch_add_scripts' );
            if ( ( in_array( 'login_form' , $reoption['formType']) ) || ( in_array( 'registration_form' , $reoption['formType']) ) || ( in_array( 'reset_pwd_form' , $reoption['formType']) ) ) {
                add_action( 'login_footer', 'nxtcptch_add_scripts' );
            }
        }

        return $output;
    }
}

/**
 * Add recaptch scripts
 */

if ( ! function_exists( 'nxtcptch_add_scripts' ) ) {
    function nxtcptch_add_scripts(){
        global $reoption;
        nxt_remove_scripts();
        $options = array(
            'version'	=> 'v3',
            'sitekey'	=> ( isset( $reoption['siteKey']) && !empty( $reoption['siteKey']) ) ?  $reoption['siteKey'] : '' ,
            'theme'     => 'light' ,
        );

        wp_enqueue_script( 'nxtcptch_script', NEXTER_EXT_URL . 'assets/js/main/nexter-recaptcha.min.js', array( 'jquery','nexter_recaptcha_api'), NEXTER_EXT_VER  , true );

        wp_localize_script( 'nxtcptch_script', 'nxtcptch', array(
            'options' => $options,
            'vars' => array(
                'visibility' => ( 'login_footer' == current_filter() )
            )
        ) );

        if( isset($reoption['invisi']) && !empty($reoption['invisi']) ){
            echo '<style>.grecaptcha-badge{visibility:hidden}</style>';
        }
    }
}

/**
 * Check for woocommerce plugin
 */

if ( ! function_exists( 'nxt_is_woocommerce_page' ) ) {
    function nxt_is_woocommerce_page(){
        $traces = debug_backtrace();

		foreach( $traces as $trace ) {
			if ( isset( $trace['file'] ) && false !== strpos( $trace['file'], 'woocommerce' ) ) {
				return true;
			}
		}
		return false;
    }
}

/** 
 * Check google recaptcha in login form
 */

if ( ! function_exists( 'nxt_login_check' ) ) {
    function nxt_login_check($user){
        global $reoption;

		if ( nxt_is_woocommerce_page() ){
			return $user;
        }
		if ( is_wp_error( $user ) && isset( $user->errors["empty_username"] ) && isset( $user->errors["empty_password"] ) ){
			return $user;
        }
		/* Skip check if connecting to XMLRPC */
		if ( defined( 'XMLRPC_REQUEST' ) ){
			return $user;
        }

        $nxrecap_check = nxt_recaptch_check( 'login_form' );
        
        if ( ! $nxrecap_check['response'] ) {
			if ( $nxrecap_check['reason'] == 'VERIFICATION_FAILED' ) {
				wp_clear_auth_cookie();
			}
            $error_code = ( is_wp_error( $user ) ) ? $user->get_error_code() : 'incorrect_password';
			$errors = new WP_Error( $error_code, __( 'Authentication failed.', 'nexter-extension' ) );
            $nxtcptch_errors = $nxrecap_check['errors']->errors;
			foreach ( $nxtcptch_errors as $code => $msg ) {
				foreach ( $msg as $message ) {
					$errors->add( $code, $message );
				}
			}
			$nxrecap_check['errors'] = $errors;
			return $nxrecap_check['errors'];
        }
        return $user;
    }
}

/**
 * Check google captcha
 */
if ( ! function_exists( 'nxt_recaptch_check' ) ) {
    function nxt_recaptch_check($form = 'general', $debug = false){
        global $reoption;

        if( !isset($reoption['siteKey']) && empty($reoption['siteKey']) && !isset($reoption['secretKey']) && empty($reoption['secretKey']) ){
            $errors = new WP_Error;
			$errors->add( 'nxtcptch_error', nxttch_get_error_message() );
			return array(
				'response'	=> false,
				'reason'	=> 'ERROR_NO_KEYS',
				'errors'	=> $errors
			);
        }

        if ( ! isset( $_POST["g-recaptcha-response"] ) ) {
            $result = array(
                'response' => false,
                'reason' => 'RECAPTCHA_NO_RESPONSE'
            );
        } elseif ( empty( $_POST["g-recaptcha-response"] ) ) {
            $result = array(
                'response' => false,
                'reason' => 'RECAPTCHA_EMPTY_RESPONSE'
            );
        } else {
            $server_ip = ( isset( $_SERVER['REMOTE_ADDR'] ) ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
            $sereip = filter_var( $server_ip , FILTER_VALIDATE_IP );
            $response = nxt_get_recpt_respo( $reoption['secretKey'] , $sereip );
            if ( empty( $response ) ) {
                $result = array(
                        'response' => false,
                        'reason' => $debug ? __( 'Response is empty', 'nexter-extension' ) : 'VERIFICATION_FAILED'
                    );
            } elseif ( isset( $response['success'] ) && !! $response['success'] ) {
                    $result = array(
                        'response' => true,
                        'reason' => ''
                    );
            } else {
                if ( ! $debug && ( in_array( 'missing-input-secret', $response['error-codes'] ) || in_array('invalid-input-secret', $response['error-codes'] )) ) {
                    $result = array(
                        'response' => false,
                        'reason' => 'ERROR_WRONG_SECRET'
                    );
                } else {
                    $result = array(
                        'response' => false,
                        'reason' => $debug ? $response['error-codes'] : 'VERIFICATION_FAILED'
                    );
                }
            }
        }

        if ( ! $result['response'] ) {
			$result['errors'] = new WP_Error;
			if ( ! $debug ) {
				$result['errors']->add( 'nxtcptch_error', nxttch_get_error_message( $result['reason'] ) );
			}
		}

        return $result;
    }
}

/** 
 * Get Error Message
 */ 

if ( ! function_exists( 'nxttch_get_error_message' ) ) {
    function nxttch_get_error_message( $message_code = 'incorrect', $display = false ){
        $errormsg = '';

		$errormsg = array(
			'missing-input-secret' 		=> __( 'Secret Key is missing.', 'nexter-extension' ),
			'invalid-input-secret' 		=> sprintf( '<strong>%s</strong> <a target="_blank" href="https://www.google.com/recaptcha/admin#list" rel="noopener noreferrer" >%s</a> %s.', __( 'Secret Key is invalid.', 'nexter-extension' ),__( 'Check your domain configurations', 'nexter-extension' ),__( 'and enter it again', 'nexter-extension' )
			),
			'incorrect'					=> __( 'You have entered an incorrect reCAPTCHA value.', 'nexter-extension' ),
			'multiple_blocks'			=> __( 'More than one reCAPTCHA has been found in the current form. Please remove all unnecessary reCAPTCHA fields to make it work properly.', 'nexter-extension' ),
            'incorrect-captcha-sol'		=> __( 'User response is invalid', 'nexter-extension' ),
             'RECAPTCHA_SMALL_SCORE'     => __( 'reCaptcha v3 test failed', 'nexter-extension' ),
			'RECAPTCHA_EMPTY_RESPONSE'	=> __( 'User response is missing.', 'nexter-extension' ),
            'ERROR_WRONG_SECRET'		=> __( 'You have entered incorrect  secret key.', 'nexter-extension' ),
		);

		if ( isset( $errormsg[ $message_code ] ) ) {
			$errormsg = $errormsg[ $message_code ];
		} else {
			$errormsg = $errormsg['incorrect'];
		}

		if ( $display ) {
			echo wp_kses_post($errormsg);
		}

		return $errormsg;
    }
}

/**
 * get response from recaptcha api
 */

if ( ! function_exists( 'nxt_get_recpt_respo' ) ) {
	function nxt_get_recpt_respo( $key, $serip ) {
		$args = array(
			'body' => array(
				'secret'   => $key,
				'response' => isset( $_POST["g-recaptcha-response"] ) ? stripslashes( sanitize_text_field( $_POST["g-recaptcha-response"] ) ) : '',
				'remoteip' => $serip,
			),
			'sslverify' => false
		);
		$getres = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', $args );
		return json_decode( wp_remote_retrieve_body( $getres ), true );
	}
}

/**
 * Remove dublicate scripts
 */

if ( ! function_exists( 'nxt_remove_scripts' ) ) {
	function nxt_remove_scripts() {
		global $wp_scripts;

		if ( ! is_object( $wp_scripts ) || empty( $wp_scripts ) ) {
			return false;
		}
        
		foreach ( $wp_scripts->registered as $script_name => $args ) {
			if ( preg_match( "|google\.com/recaptcha/api\.js|", $args->src ) && 'nexter_recaptcha_api' != $script_name ) {
				/* remove a previously enqueued script */
				wp_dequeue_script( $script_name );
			}
		}
	}
}

/** 
 * Check google captcha in Register form
 */ 

if( ! function_exists( 'nxt_register_check' ) ){
    
    function nxt_register_check( $allow ){
        // if ( nxt_is_woocommerce_page() ){
		// 	return $allow;
        // }
        if ( defined( 'XMLRPC_REQUEST' ) ){
			return $allow;
        }
        
        $nxrecap_check = nxt_recaptch_check( 'registration_form' );
        if ( empty($nxrecap_check['response']) ) {
            
			return $nxrecap_check['errors'];
		}
		$_POST['g-recaptcha-response-check'] = true;
        
		return $allow;
    }
}

/** 
 * Display Signup Recaptch
 */ 

if( ! function_exists( 'nxt_signup_display' )  ){
    function nxt_signup_display($errors){
        global $reoption;
        if ( $error_message = $errors->get_error_message( 'nxtcptch_error' ) ) {
			printf( '<p class="error nxtcptch_error">%s</p>', wp_kses_post($error_message->get_error_message( 'nxtcptch_error' )) );
		}
		echo nxt_recaptch_render($reoption);
    }
}

/**
 * check signup form
 */

if( ! function_exists('nxt_signup_check') ){    
    function nxt_signup_check($result){
        global $current_user;
		if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! empty( $current_user->data->ID ) ){
			return $result;
        }

		$nxtcptch_check = nxt_recaptch_check( 'registration_form' );
        
		if ( empty($nxrecap_check['response']) && isset($nxtcptch_check['errors']) ) {
			$result['errors']->add( 'nxtcptch_error' , $nxtcptch_check['errors']  );
			return $result;
		}
		return $result;
    }
}

/**
 * check lost password form
 */

if( ! function_exists( 'nxt_lostpassword_check' ) ){
    function nxt_lostpassword_check($allow){
        if ( ( isset( $_POST['g-recaptcha-response-check'] ) && true === $_POST['g-recaptcha-response-check'] ) ){
            return $allow;
        }
        $nxtcptch_check = nxt_recaptch_check( 'reset_pwd_form' );
        if ( empty ($nxtcptch_check['response']) ) {
            return $nxtcptch_check['errors'];
        }
        return $allow;
    }
}

/**
 * display recaptch in comment form
 */

if( ! function_exists( 'nxt_commentform_display' ) ){
    function nxt_commentform_display(){
        global $reoption;
        $commCss = '';

        $commCss .= '#commentform .nxtcaptch { margin: 0 0 10px;}';
        if( isset($reoption['invisi']) && !empty($reoption['invisi']) ){
            $commCss .= '.grecaptcha-badge { visibility: hidden;}';
        }
        if(!empty($commCss)){
            echo '<style> '.esc_html($commCss).' </style>';
        }
        echo nxt_recaptch_render($reoption);
		return true;
    }
}

/**
 * check recaptch for comment form
 */

if( ! function_exists( 'nxt_comment_check' ) ){
    function nxt_comment_check() {
        $nxtcptch_check = nxt_recaptch_check( 'comments_form' );
        if ( empty($nxtcptch_check['response']) ) {
            $message = nxttch_get_error_message($nxtcptch_check['reason']) . "<br />";
            $error_message = sprintf( '<strong>%s</strong>:&nbsp;%s&nbsp;%s', __( 'Error', 'nexter-extension' ), $message, __( 'Click the BACK button on your browser and try again.', 'nexter-extension' )
            );
            wp_die( wp_kses_post($error_message) );
        }
    }
}

if( ! function_exists('nxt_admin_footer') ){
    function nxt_admin_footer(){
        global $reoption;
        //if ( isset( $_REQUEST['page'] ) && 'nexter_extra_options' == $_REQUEST['page'] ) {
            $option = get_option( 'nexter_extra_ext_options' );

            if( isset( $option['captcha-security']['switch'] ) && !empty( $option['captcha-security']['switch'] ) ){
                $api_url = sprintf( 'https://www.google.com/recaptcha/api.js?render=%s', $reoption['siteKey'] );
                if ( ! wp_script_is( 'nexter_recaptcha_api', 'registered' ) ) {
                    wp_register_script( 'nexter_recaptcha_api', $api_url, false, NEXTER_EXT_VER, false );
                    add_action( 'wp_footer', 'nxtcptch_add_scripts' );
                }
                nxtcptch_add_scripts();
            }
       // }
    }
}