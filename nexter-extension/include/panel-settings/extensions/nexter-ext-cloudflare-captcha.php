<?php 
/*
 * Cloudflare Captcha Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Cloudflare_Captcha {
    
	public static $captcha_opt = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->nxt_get_settings();

        add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_turnstile_scripts' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_turnstile_scripts' ] );
        
        add_filter( 'script_loader_tag', function( $tag, $handle ) {
            if ( 'nxt-turnstile' === $handle ) {
                // Add data-cfasync="false" attribute
                $tag = str_replace( "src=", "data-cfasync='false' src=", $tag );
            }
            return $tag;
        }, 10, 2 );
        if ( !empty(self::$captcha_opt['formType']) && in_array( 'login_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'login_form', [ $this, 'render_login_turnstile_field' ] );
            add_filter( 'authenticate', [ $this, 'validate_turnstile_on_login' ], 21, 1 );
        }
        if ( !empty(self::$captcha_opt['formType']) && in_array( 'reset_pwd_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'lostpassword_form', [ $this, 'render_password_reset_form_turnstile_field' ] );
            add_action( 'lostpassword_post', [ $this, 'validate_turnstile_on_password_reset' ], 10, 1 );
        }
       if ( !empty(self::$captcha_opt['formType']) && in_array( 'registration_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'register_form', [ $this, 'render_registration_form_turnstile_field' ] );
            add_action( 'register_post', [ $this, 'validate_turnstile_on_registration' ], 10, 3 );
        }
        if ( !empty(self::$captcha_opt['formType']) && in_array( 'comments_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'comment_form_after_fields', [ $this, 'render_comment_form_turnstile_field' ] );
            add_filter( 'preprocess_comment', [ $this, 'validate_turnstile_on_comment' ], 10, 1 );
        }

        // WooCommerce Forms - Register hooks on init to ensure WooCommerce is loaded
        add_action( 'init', [ $this, 'register_woocommerce_hooks' ], 20 );
    }

    /**
     * Register WooCommerce hooks
     */
    public function register_woocommerce_hooks() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        
        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_checkout', self::$captcha_opt['formType'] ) ) {
            add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render_woo_checkout_turnstile_field' ], 20 );
            add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_woo_checkout_turnstile_field' ], 20 );
            add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'render_woo_checkout_turnstile_field' ], 20 );
            add_action( 'woocommerce_checkout_billing', [ $this, 'render_woo_checkout_turnstile_field' ], 25 );
            add_action( 'woocommerce_checkout_process', [ $this, 'validate_turnstile_on_woo_checkout' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_woo_checkout_styles' ] );
        }

        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_pay_for_order', self::$captcha_opt['formType'] ) ) {
            add_action( 'woocommerce_pay_order_before_submit', [ $this, 'render_woo_pay_for_order_turnstile_field' ], 20 );
            add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_woo_pay_for_order_turnstile_field' ], 20 );
            add_action( 'woocommerce_checkout_process', [ $this, 'validate_turnstile_on_woo_pay_for_order' ] );
        }

        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_login_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'woocommerce_login_form', [ $this, 'render_woo_login_turnstile_field' ] );
            add_filter( 'woocommerce_process_login_errors', [ $this, 'validate_turnstile_on_woo_login' ], 10, 3 );
        }

        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_registration_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'woocommerce_register_form', [ $this, 'render_woo_registration_turnstile_field' ] );
            add_filter( 'woocommerce_registration_errors', [ $this, 'validate_turnstile_on_woo_registration' ], 10, 3 );
        }

        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_reset_pwd_form', self::$captcha_opt['formType'] ) ) {
            add_action( 'woocommerce_lostpassword_form', [ $this, 'render_woo_reset_pwd_turnstile_field' ] );
            add_filter( 'woocommerce_lostpassword_validation', [ $this, 'validate_turnstile_on_woo_reset_pwd' ], 10, 2 );
        }
    }

    private function nxt_get_settings(){

		if(isset(self::$captcha_opt) && !empty(self::$captcha_opt)){
			return self::$captcha_opt;
		}

		$option = get_option( 'nexter_site_security' );
		
		if(!empty($option) && isset($option['captcha-security']) && !empty($option['captcha-security']['switch']) && !empty($option['captcha-security']['values']) ){
			self::$captcha_opt = (array) $option['captcha-security']['values'];
		}

		return self::$captcha_opt;
	}

    public function enqueue_login_turnstile_scripts(){
        
        if (!empty(self::$captcha_opt['formType']) && array_intersect(['login_form', 'registration_form', 'reset_pwd_form'], self::$captcha_opt['formType'])) {
            $this->enqueue_scripts();
        }
    }

    public function nxt_comments_enabled(){

		$extension_option = get_option( 'nexter_site_performance' );

		$data = [
			'disable_comments' => '',
			'disble_custom_post_comments' => []
		];
		
		if(!empty($extension_option) ){
			if(isset($extension_option['disble_custom_post_comments']) && !empty($extension_option['disble_custom_post_comments'])){
				$data['disble_custom_post_comments'] = $extension_option['disble_custom_post_comments'];
			}
			if(isset($extension_option['disable_comments']) && !empty($extension_option['disable_comments'])){
				$data['disable_comments'] = $extension_option['disable_comments'];
			}else if(isset($extension_option['disable-comments']) && !empty($extension_option['disable-comments']) && isset($extension_option['disable-comments']['switch']) && !empty($extension_option['disable-comments']['switch'])){
				if(isset($extension_option['disable-comments']['values']) && !empty($extension_option['disable-comments']['values'])){
					$disable_values = $extension_option['disable-comments']['values'];
					if(isset($disable_values['disable_comments']) && !empty($disable_values['disable_comments'])){
						$data['disable_comments'] = $disable_values['disable_comments'];
					}
					if(isset($disable_values['disble_custom_post_comments']) && !empty($disable_values['disble_custom_post_comments'])){
						$data['disble_custom_post_comments'] = $disable_values['disble_custom_post_comments'];
					}
				}
			}
		}
		
		return $data;
	}

    public function enqueue_frontend_turnstile_scripts() {
        global $post;
        $disabled_for_post_type = false;
        
        // Check for WooCommerce pages
        if ( class_exists( 'WooCommerce' ) ) {
            $is_checkout = is_checkout();
            // Security: Sanitize GET parameter
            $is_pay_for_order = ( isset( $_GET['pay_for_order'] ) && sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) ) ) || is_wc_endpoint_url( 'order-pay' );
            
            if ( $is_checkout || $is_pay_for_order ) {
                if ( !empty(self::$captcha_opt['formType']) && 
                     ( in_array( 'woo_checkout', self::$captcha_opt['formType'] ) || 
                       in_array( 'woo_pay_for_order', self::$captcha_opt['formType'] ) ) ) {
                    $this->enqueue_scripts();
                }
            }
            
            // Check for WooCommerce login/registration/reset password pages
            if ( is_account_page() ) {
                if ( !empty(self::$captcha_opt['formType']) && 
                     ( in_array( 'woo_login_form', self::$captcha_opt['formType'] ) || 
                       in_array( 'woo_registration_form', self::$captcha_opt['formType'] ) || 
                       in_array( 'woo_reset_pwd_form', self::$captcha_opt['formType'] ) ) ) {
                    $this->enqueue_scripts();
                }
            }
        }
        
        if ( is_object( $post ) && property_exists( $post, 'comment_status' ) ) {
            if ( property_exists( $post, 'post_type' ) ) {
                $disable_option = $this->nxt_comments_enabled();
                $post_types = [];
                if(!empty($disable_option['disable_comments']) && $disable_option['disable_comments'] === 'custom'){
                    if(isset($disable_option['disble_custom_post_comments']) && !empty($disable_option['disble_custom_post_comments'])){
                        $post_types = $disable_option['disble_custom_post_comments'];
                        if($post->post_type && in_array($post->post_type, $post_types )){
                            $disabled_for_post_type = true;
                        }
                    }
                }else if(!empty($disable_option['disable_comments']) && $disable_option['disable_comments'] === 'all'){
                    $disabled_for_post_type = true;
                }
            }else{
                $disabled_for_post_type = false;
            }

            if ( 'open' == $post->comment_status && ! $disabled_for_post_type && (!empty(self::$captcha_opt['formType']) && in_array( 'comments_form', self::$captcha_opt['formType'] ) ) ) {
                $this->enqueue_scripts();
            }
        }

        if ( !empty(self::$captcha_opt['formType']) && in_array( 'nexter_block_form', self::$captcha_opt['formType'] ) && has_action( 'nexter_form_integrate' ) ) {
            $this->enqueue_scripts();
        }
    }

    public function enqueue_scripts(){
        $defer = array( 'strategy' => 'defer' );
        wp_enqueue_script( 'nxt-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, $defer );

        $inline_js  = "function nxt_CloudflareWPCallback() {\n";
        $inline_js .= "    document.querySelectorAll('#wp-submit').forEach(function(el) {\n";
        $inline_js .= "        el.style.opacity = '1';\n";
        $inline_js .= "        el.style.pointerEvents = 'auto';\n";
        $inline_js .= "    });\n";
        $inline_js .= "}\n";
        $inline_js .= "function nxt_CloudflareCommentCallback() {\n";
        $inline_js .= "    document.querySelectorAll('.cf-turnstile-comment').forEach(function(el) {\n";
        $inline_js .= "        el.style.opacity = '1';\n";
        $inline_js .= "        el.style.pointerEvents = 'auto';\n";
        $inline_js .= "    });\n";
        $inline_js .= "}\n";

        wp_add_inline_script( 'nxt-turnstile', $inline_js );

    }

    /**
     * Enqueue WooCommerce checkout styles
     */
    public function enqueue_woo_checkout_styles() {
        if ( ! is_checkout() ) {
            return;
        }
        
        if ( !empty(self::$captcha_opt['formType']) && in_array( 'woo_checkout', self::$captcha_opt['formType'] ) ) {
            $css = '
                .woocommerce-checkout .nxt-woo-checkout-turnstile { 
                    margin: 20px 0 !important; 
                    clear: both;
                    display: block;
                }
                .woocommerce-checkout .nxt-woo-checkout-turnstile .cf-turnstile,
                .woocommerce-checkout .cf-turnstile { 
                    margin: 0 !important; 
                    display: block;
                    min-height: 65px;
                }
                .woocommerce-checkout .nxt-turnstile-wrapper {
                    margin: 20px 0 !important;
                    clear: both;
                    display: block;
                }
            ';
            
            // Try to add to WooCommerce styles, fallback to custom handle
            if ( wp_style_is( 'woocommerce-general', 'enqueued' ) || wp_style_is( 'woocommerce-general', 'registered' ) ) {
                wp_add_inline_style( 'woocommerce-general', $css );
            } else {
                // Register and enqueue our own style
                wp_register_style( 'nxt-turnstile-woo-checkout', false );
                wp_enqueue_style( 'nxt-turnstile-woo-checkout' );
                wp_add_inline_style( 'nxt-turnstile-woo-checkout', $css );
            }
        }
    }

    /**
     * Display the Turnstile widget on the WordPress login form.
     */
	public function render_login_turnstile_field() {
        $this->render_turnstile_widget(
            'wordpress-login',            // Action/form name (purpose of widget)
            'nxt_CloudflareWPCallback',   // JS callback function
            '#wp-submit',                 // Button selector to enable after success
            'wp-login',                   // Additional CSS class
            'flexible',                   // Widget size
            true                          // Disable submit button initially
        );
	}

    /**
	 * Display the Turnstile Widget on the password reset form
	 */
	public function render_password_reset_form_turnstile_field() {
        $this->render_turnstile_widget(
            'wordpress-reset',            // Action/form name (purpose of widget)
            'nxt_CloudflareWPCallback',   // JS callback function
            '#wp-submit',                 // Button selector to enable after success
            'wp-reset',                   // Additional CSS class
            'flexible',                   // Widget size
            true                          // Disable submit button initially
        );
	}

    /**
	 * Display the Turnstile Widget on the registration form
	 */
	public function render_registration_form_turnstile_field() {
        $this->render_turnstile_widget(
            'wordpress-register',            // Action/form name (purpose of widget)
            'nxt_CloudflareWPCallback',   // JS callback function
            '#wp-submit',                 // Button selector to enable after success
            'wp-register',                   // Additional CSS class
            'flexible',                   // Widget size
            true                          // Disable submit button initially
        );
	}

    /**
     * Display the Turnstile Widget on the WordPress comment form.
     */
    public function render_comment_form_turnstile_field() {
        $this->render_turnstile_widget(
            'wordpress-comment',   // Action name for Turnstile
            '',                    // No callback required for comment form
            '',                    // No specific button selector
            'wp-comment',          // Additional CSS class
            'normal',              // Widget size: normal (300px)
            false                  // Do not disable the submit button
        );
    }

    /**
     * Display the Turnstile Widget on the Nexter Block Form.
     */
    public function render_nexter_block_form_turnstile(){
        $this->render_turnstile_widget(
            'nexter-block',   // Action name for Turnstile
            '',                    // No callback required for comment form
            '',                    // No specific button selector
            'nxt-block',          // Additional CSS class
            'normal',              // Widget size: normal (300px)
            false                  // Do not disable the submit button
        );
    }

    /**
     * Outputs the Turnstile widget markup.
     */
    public function render_turnstile_widget( $form_action = '', $callback_function = '', $button_selector = '', $css_class = '', $size = 'flexible', $disable_submit_btn = true ) {

        $site_key    = ( isset( self::$captcha_opt['turnSiteKey'] ) && !empty( self::$captcha_opt['turnSiteKey'] ) ) ? sanitize_text_field( self::$captcha_opt['turnSiteKey'] ) : '';
        
        // Return early if site key is empty
        if ( empty( $site_key ) ) {
            return;
        }
        
        $theme       = 'light';
        $unique_id   = '-'.uniqid();
        $widget_id   = 'cf-turnstile' . esc_attr( $unique_id );

        do_action( 'nxt_turnstile_enqueue_scripts' );
        do_action( 'nxt_turnstile_before_field', esc_attr( $unique_id ) );
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>"
            class="cf-turnstile<?php echo $css_class ? ' ' . esc_attr( $css_class ) : ''; ?>"
            <?php if ( $disable_submit_btn ) : ?>
                data-callback="<?php echo esc_attr( $callback_function ); ?>"
            <?php endif; ?>
            data-sitekey="<?php echo esc_attr( $site_key ); ?>"
            data-theme="<?php echo esc_attr( $theme ); ?>"
            data-language="auto"
            data-size="<?php echo esc_attr( $size ); ?>"
            data-retry="auto"
            data-retry-interval="1500"
            data-action="<?php echo esc_attr( $form_action ); ?>"
            data-appearance="always">
        </div>
        <?php if ( $form_action == 'wordpress-login' ) : ?>
        <style>
            #login {
                min-width: 350px !important;
            }
        </style>
        <?php endif; ?>
        <script>
            (function() {
                var widgetId = "<?php echo esc_js( $widget_id ); ?>";
                var siteKey = "<?php echo esc_js( $site_key ); ?>";
                var formAction = "<?php echo esc_js( $form_action ); ?>";
                var theme = "<?php echo esc_js( $theme ); ?>";
                var size = "<?php echo esc_js( $size ); ?>";
                var retryCount = 0;
                var maxRetries = 50; // 5 seconds max wait time
                var elementRetryCount = 0;
                var maxElementRetries = 20; // 2 seconds max wait for element
                
                function initTurnstile() {
                    // Wait for turnstile library
                    if (typeof turnstile === 'undefined') {
                        retryCount++;
                        if (retryCount < maxRetries) {
                            setTimeout(initTurnstile, 100);
                        }
                        return;
                    }
                    
                    // Wait for element to be in DOM
                    var el = document.getElementById(widgetId);
                    if (!el) {
                        elementRetryCount++;
                        if (elementRetryCount < maxElementRetries) {
                            setTimeout(initTurnstile, 100);
                        }
                        return;
                    }
                    
                    // Element found, render widget
                    try {
                        // Remove any existing widget
                        try {
                            turnstile.remove("#" + widgetId);
                        } catch(e) {}
                        
                        // Render the widget
                        var renderOptions = {
                            sitekey: siteKey,
                            action: formAction,
                            theme: theme,
                            size: size,
                            language: "auto",
                            retry: "auto",
                            "retry-interval": 1500,
                            appearance: "always"
                        };
                        <?php if ( !empty( $callback_function ) ) : ?>
                        if (typeof window.<?php echo esc_js( $callback_function ); ?> === 'function') {
                            renderOptions.callback = window.<?php echo esc_js( $callback_function ); ?>;
                        } else if (typeof <?php echo esc_js( $callback_function ); ?> === 'function') {
                            renderOptions.callback = <?php echo esc_js( $callback_function ); ?>;
                        }
                        <?php endif; ?>
                        turnstile.render("#" + widgetId, renderOptions);
                    } catch(error) {
                        console.error('Turnstile render error:', error);
                    }
                }
                
                // Start initialization
                if (document.readyState === 'loading') {
                    document.addEventListener("DOMContentLoaded", function() {
                        setTimeout(initTurnstile, 100);
                    });
                } else {
                    // DOM already loaded, wait a bit for dynamic content
                    setTimeout(initTurnstile, 100);
                }
            })();
        </script>

        <?php if ( $disable_submit_btn ) : ?>
            <style><?php echo esc_html( $button_selector ); ?> { pointer-events: none; opacity: 0.5; }</style>
        <?php endif;

        do_action( 'nxt_turnstile_after_field', esc_attr( $unique_id ), $button_selector );
        echo '<br class="cf-turnstile-br cf-turnstile-br' . esc_attr( $unique_id ) . '">';
    }

    /**
     * Check if WooCommerce is active.
     */
    public function is_woocommerce() {
        return in_array( 'woocommerce/woocommerce.php', get_option( 'active_plugins', array() ) );
    }

    /**
     * Authenticate user login via Turnstile verification.
     */
    public function validate_turnstile_on_login( $user ) {
        // Skip if authentication already failed
        if ( is_wp_error( $user ) || ! isset( $user->ID ) ) {
            return $user;
        }

        // Bypass for XML-RPC and REST requests
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return $user;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return $user;
        }

        // Skip if WooCommerce login form is detected (handled separately)
        if ( $this->is_woocommerce() && isset( $_POST['woocommerce-login-nonce'] ) ) {
            return $user;
        }

        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            return $user;
        }

        // Validate Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            return new WP_Error(
                'turnstile_error',
                esc_html__( 'Please verify that you are human.', 'nexter-extension' )
            );
        }

        return $user;
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA during WordPress password reset.
     */
    public function validate_turnstile_on_password_reset( $errors ) {
        // Skip if WooCommerce is handling the lost password request
        if ( $this->is_woocommerce() && isset( $_POST['woocommerce-lost-password-nonce'] ) ) {
            return $errors;
        }

        // Skip if Turnstile response is missing (i.e. CAPTCHA not shown)
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            return $errors;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            $errors->add(
                'turnstile_error',
                esc_html__( 'Please verify that you are human.', 'nexter-extension' )
            );
        }

        return $errors;
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA during user registration.
     */
    public function validate_turnstile_on_registration( $sanitized_user_login, $user_email, $errors ) {
        // Skip for XML-RPC and REST API requests
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return $errors;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return $errors;
        }

        // Skip if WooCommerce registration is detected (handled separately)
        if ( $this->is_woocommerce() && isset( $_POST['woocommerce-register-nonce'] ) ) {
            return $errors;
        }

        // Skip if no Turnstile response is present (i.e., widget not loaded)
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            return $errors;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            $errors->add(
                'turnstile_error',
                esc_html__( 'Please verify that you are human.', 'nexter-extension' )
            );
        }

        return $errors;
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on comment submission.
     */
    public function validate_turnstile_on_comment( $comment ) {
        // Skip if Turnstile response is not present (i.e., widget not rendered)
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            return $comment;
        }

        // Perform Turnstile validation
        $response = $this->verify_turnstile_response(); // assumes refactored method
        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            wp_die(
                esc_html__( 'Please verify that you are human.', 'nexter-extension' ),
                esc_html__( 'Comment Blocked', 'nexter-extension' ),
                [
                    'response'  => 403,
                    'back_link' => true,
                ]
            );
        }

        return $comment;
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on Nexter Form submission.
     */
    public function validate_turnstile_nexter_form( $default, $captcha_value ) {
        // Skip if Turnstile response is not present (i.e., widget not rendered)
        if ( empty( $captcha_value ) ) {
            return ['success' => false, 'data' => esc_html__( 'Invalid CAPTCHA validation response format.', 'nexter-extension' )];
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response( $captcha_value );

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            return ['success' => false, 'data' => esc_html__( 'Please verify that you are human.', 'nexter-extension' ) ];
        }

        return ['success' => true, 'data' => esc_html__( 'Cloudflare Turnstile validated successfully.', 'nexter-extension' ) ];
    }

    /**
     * Display the Turnstile widget on WooCommerce checkout.
     */
    public function render_woo_checkout_turnstile_field() {
        static $displayed = false;
        if ( $displayed ) {
            return;
        }
        $displayed = true;
        
        // Ensure site key exists
        if ( empty( self::$captcha_opt['turnSiteKey'] ) ) {
            return;
        }
        
        // Ensure scripts are enqueued
        $this->enqueue_scripts();
        
        // Output wrapper with inline styles as fallback
        echo '<div class="nxt-turnstile-wrapper nxt-woo-checkout-turnstile">';
        echo '<style type="text/css">
            .woocommerce-checkout .nxt-woo-checkout-turnstile { 
                margin: 20px 0 !important; 
                clear: both;
                display: block !important;
            }
            .woocommerce-checkout .nxt-woo-checkout-turnstile .cf-turnstile,
            .woocommerce-checkout .cf-turnstile { 
                margin: 0 !important; 
                display: block !important;
                min-height: 65px;
            }
            .woocommerce-checkout .nxt-turnstile-wrapper {
                margin: 20px 0 !important;
                clear: both;
                display: block !important;
            }
        </style>';
        
        // Render the widget
        $this->render_turnstile_widget(
            'woocommerce-checkout',
            '',
            '',
            'woo-checkout',
            'normal',
            false
        );
        
        echo '</div>';
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on WooCommerce checkout.
     */
    public function validate_turnstile_on_woo_checkout() {
        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            wc_add_notice( esc_html__( 'Please verify that you are human.', 'nexter-extension' ), 'error' );
            return;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            wc_add_notice( esc_html__( 'Please verify that you are human.', 'nexter-extension' ), 'error' );
        }
    }

    /**
     * Display the Turnstile widget on WooCommerce pay for order page.
     */
    public function render_woo_pay_for_order_turnstile_field() {
        static $displayed = false;
        if ( $displayed ) {
            return;
        }
        $displayed = true;
        
        // Ensure site key exists
        if ( empty( self::$captcha_opt['turnSiteKey'] ) ) {
            return;
        }
        
        // Ensure scripts are enqueued
        $this->enqueue_scripts();
        
        echo '<style>.woocommerce-order-pay .cf-turnstile { margin: 0 0 20px; }</style>';
        $this->render_turnstile_widget(
            'woocommerce-pay-order',
            '',
            '',
            'woo-pay-order',
            'normal',
            false
        );
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on WooCommerce pay for order.
     */
    public function validate_turnstile_on_woo_pay_for_order() {
        // Security: Sanitize and validate GET parameters
        $pay_for_order = isset( $_GET['pay_for_order'] ) ? sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) ) : '';
        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        
        // Only validate on pay for order page
        if ( empty( $pay_for_order ) || empty( $order_key ) ) {
            return;
        }

        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            wc_add_notice( esc_html__( 'Please verify that you are human.', 'nexter-extension' ), 'error' );
            return;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            wc_add_notice( esc_html__( 'Please verify that you are human.', 'nexter-extension' ), 'error' );
        }
    }

    /**
     * Display the Turnstile widget on WooCommerce login form.
     */
    public function render_woo_login_turnstile_field() {
        echo '<style>.woocommerce-form-login .cf-turnstile { margin: 0 0 15px; }</style>';
        $this->render_turnstile_widget(
            'woocommerce-login',
            '',
            '',
            'woo-login',
            'normal',
            false
        );
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on WooCommerce login.
     */
    public function validate_turnstile_on_woo_login( $validation_error, $username, $password ) {
        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            $validation_error->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
            return $validation_error;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            $validation_error->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
        }

        return $validation_error;
    }

    /**
     * Display the Turnstile widget on WooCommerce registration form.
     */
    public function render_woo_registration_turnstile_field() {
        echo '<style>.woocommerce-form-register .cf-turnstile { margin: 0 0 15px; }</style>';
        $this->render_turnstile_widget(
            'woocommerce-register',
            '',
            '',
            'woo-register',
            'normal',
            false
        );
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on WooCommerce registration.
     */
    public function validate_turnstile_on_woo_registration( $validation_error, $username, $email ) {
        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            $validation_error->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
            return $validation_error;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            $validation_error->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
        }

        return $validation_error;
    }

    /**
     * Display the Turnstile widget on WooCommerce reset password form.
     */
    public function render_woo_reset_pwd_turnstile_field() {
        echo '<style>.woocommerce-ResetPassword .cf-turnstile { margin: 0 0 15px; }</style>';
        $this->render_turnstile_widget(
            'woocommerce-reset-password',
            '',
            '',
            'woo-reset-pwd',
            'normal',
            false
        );
    }

    /**
     * Validates Cloudflare Turnstile CAPTCHA on WooCommerce reset password.
     */
    public function validate_turnstile_on_woo_reset_pwd( $errors, $user_login ) {
        // Skip if Turnstile response is not present
        if ( empty( $_POST['cf-turnstile-response'] ) ) {
            $errors->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
            return $errors;
        }

        // Verify Turnstile
        $response = $this->verify_turnstile_response();

        if ( empty( $response['success'] ) || $response['success'] !== true ) {
            $errors->add( 'turnstile_error', esc_html__( 'Please verify that you are human.', 'nexter-extension' ) );
        }

        return $errors;
    }

    /**
     * Validates the Turnstile CAPTCHA response with Cloudflare's API.
     */
    public function verify_turnstile_response( $token = '' ) {
        // If token not provided explicitly, try from POST
        if ( empty( $token ) && isset( $_POST['cf-turnstile-response'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) );
        }

        // Bail early if token is still empty
        if ( empty( $token ) ) {
            return [ 'success' => false ];
        }

        // Get Turnstile keys from settings
        $secret_key = ( isset( self::$captcha_opt['turnSecretKey'] ) && !empty( self::$captcha_opt['turnSecretKey'] ) ) ? sanitize_text_field( self::$captcha_opt['turnSecretKey'] ) : '';

        // Bail if no secret key
        if ( empty( $secret_key ) ) {
            return [ 'success' => false ];
        }

        // Prepare request to Cloudflare API
        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            [
                'body' => [
                    'secret'   => $secret_key,
                    'response' => $token,
                ],
                'timeout' => 5,
            ]
        );

        // Validate response
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false ];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        return [
            'success' => ! empty( $data->success ) && $data->success === true,
        ];
    }

}

new Nexter_Ext_Cloudflare_Captcha();