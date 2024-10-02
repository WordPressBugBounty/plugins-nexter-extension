<?php 
/*
 * Nexter Custom Login Redirect
 * @since 1.1.0
 */

defined('ABSPATH') or die();

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
        
        // Ajax To Load Content
        if( is_admin() ){
	    	add_action( 'wp_ajax_nexter_ext_custom_login_redirect', [ $this, 'nexter_ext_custom_login_redirect_ajax'] );
        }

        $this->cusloOption = get_option( 'nexter_site_security' );
        
        if( isset($this->cusloOption['custom_login_url']) && !empty($this->cusloOption['custom_login_url']) && !defined('WP_CLI') ){

            add_action('plugins_loaded', [ $this,'nxt_login_plugins_loaded'], 2 );
            add_action('wp_loaded', [ $this,'nxt_wp_loaded'] );
            add_action('setup_theme', [ $this , 'nxt_login_customizer_redirect'], 1);

            add_filter('site_url', [ $this ,'nxt_login_site_url'], 10, 4);
            add_filter('network_site_url',  [ $this ,'nxt_login_netwrok_site_url'], 10, 3);
            add_filter('wp_redirect', [ $this ,'nxt_login_wp_redirect'], 10, 2);
            
            add_filter('site_option_welcome_email',  [ $this ,'nxt_login_welcome_email']);
            
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
            add_filter('admin_url', [ $this ,'nxt_login_admin_url']);
        }

    }
    
    /**
     * Nexter Custom Login Setting
     * @since 1.1.0
     */

    public function nexter_ext_custom_login_redirect_ajax(){
        check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$exte_option = get_option( 'nexter_site_security' );

        if( !empty( $ext ) && $ext == 'custom-login' ){
            $secu_ext = [];
            if( has_filter('nexter-extension-security-option-config') ){
                $sec_data = apply_filters('nexter-extension-security-option-config' , $secu_ext);
                if( !empty( $sec_data ) && isset($sec_data[$ext]) ){
                    $secu_ext = $sec_data[$ext];
                }
            }

            $loginbeha = [
                'message' => esc_html__('Message (Default)' , 'nexter-ext'),
                'home_page' => esc_html__('Home Page' , 'nexter-ext'),
                '404_page' => esc_html__('404 Page Template' , 'nexter-ext'),
            ];

            $output = '';
            $output .= '<div class="nxt-ext-modal-content">';
                $output .= '<div class="nxt-modal-title-wrap">';
                    $output .= '<div class="nxt-modal-title">'.(isset($secu_ext['title']) ? wp_kses_post($secu_ext['title']) : '').'</div>';
                    //$output .= '<div class="nxt-modal-desc">'.(isset($secu_ext['title']) ? wp_kses_post($secu_ext['description']) : '').'</div>';
                $output .= '</div>';
                $output .= '<div class="nxt-custom-login-wrap">';
                    $output .= '<div class="nxt-ctmlo-inner">';
                        $output .= '<div class="nxt-recaptch-field">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'Change WP Admin Login Path', 'nexter-ext' );
                                $output .= '<span class="nxt-desc-icon" >';
                                    $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_html__( 'Change WP Admin Login Path', 'nexter-ext' ).'" /> ';
                                    $output .= '<div class="nxt-tooltip">'.esc_html__( 'Hide your WordPress default WP-Admin URL path from public access.' , 'nexter-ext' ).'</div>';
                                $output .= '</span>';
                            $output .= '</label>';
                            $output .= '<input type="text" class="nxt-recap-input" value="'.( isset($exte_option['custom_login_url']) && !empty($exte_option['custom_login_url']) ? $exte_option['custom_login_url'] : '' ).'" name="nxt-redirect-url" placeholder="'.esc_html('Please enter your custom path e.g. new-login-page','nexter-ext').'" required />';
                        $output .= '</div>';
                        
                        $output .= '<div class="nxt-recaptch-field">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'Login URL Behaviour', 'nexter-ext' );
                                $output .= '<span class="nxt-desc-icon" >';
                                    $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_html__( 'Redirect Login URL', 'nexter-ext' ).'" /> ';
                                    $output .= '<div class="nxt-tooltip">'.esc_html__( 'Redirect old WP-Admin path to different pages, after changing to your new URL.' , 'nexter-ext' ).'</div>';
                                $output .= '</span>';
                            $output .= '</label>';
                            $output .=  '<select class="nxt-select-opt nxt-login-select">';
									foreach($loginbeha as $key => $val){
										$output .= '<option '.(isset($exte_option['disable_login_url_behavior']) && $exte_option['disable_login_url_behavior'] == $key ? 'selected' : '' ).' value="'.esc_attr($key).'" >'.$val.'</option>';
									}
							$output .= '</select>';
                        $output .= '</div>';
                        
                      
                        $output .= '<div class="nxt-recaptch-field '.(isset($exte_option['disable_login_url_behavior']) && !empty($exte_option['disable_login_url_behavior']) && $exte_option['disable_login_url_behavior'] == 'message' ? '' : (!isset($exte_option['disable_login_url_behavior']) && empty($exte_option['disable_login_url_behavior']) ? '' : ' nxt-hide' ) ).' ">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'Custom Message', 'nexter-ext' );
                                $output .= '<span class="nxt-desc-icon" >';
                                    $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_html__( 'Redirect Login URL', 'nexter-ext' ).'" /> ';
                                    $output .= '<div class="nxt-tooltip">'.esc_html__( 'Add your custom message here, which will be shown when someone visits old WP-Admin path for login.' , 'nexter-ext' ).'</div>';
                                $output .= '</span>';
                            $output .= '</label>';
                            $output .= '<textarea name="nxt-login-msg" class="nxt-text-area" placeholder="'.esc_html('Please Write Something Here...','nexter-ext').'" name="nxt-ctm-msg" rows="5">'.(isset($exte_option['login_page_message']) && !empty($exte_option['login_page_message']) ? $exte_option['login_page_message'] : 'Sorry the page you were looking for does not exist or is not available' ).'</textarea>';
                        $output .= '</div>';
                        
                    $output .= '</div>';
                $output .= '</div>';
                $output .= '<button type="button" class="nxt-ctm-login" ><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
            $output .= '</div>';

            wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
        }
        wp_send_json_error();
    }
    
    /**
     * Nexter Custom Login Load
     * @since 1.1.0
     */

    public function nxt_login_plugins_loaded(){
        global $pagenow;
        
        if ( !is_multisite() && ( strpos( $_SERVER['REQUEST_URI'], 'wp-signup' ) !== false || strpos( $_SERVER['REQUEST_URI'], 'wp-activate' ) !== false ) ) {
            wp_die( esc_html__( 'This feature is not enabled.', 'nexter-ext' ) );
        }

        $request_URI = parse_url( $_SERVER['REQUEST_URI'] );
        $path = !empty($request_URI['path']) ? untrailingslashit($request_URI['path']) : '';
        
        $login_slug = $this->nxt_custom_login_slug();

        if( !is_admin() && ( strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-login.php') !== false || $path === site_url('wp-login', 'relative') ) ) {
            //wp-login.php URL 
            $this->nxt_custom_login = true;
    
            $_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit('/' . str_repeat('-/', 10));
            $pagenow = 'index.php';
            
        } else if( !is_admin() && ( strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-register.php') !== false || $path === site_url('wp-register', 'relative') ) ) {
            //wp-register.php
           $this->nxt_custom_login = true;
    
            //Prevent Redirect to Hidden Login
            $_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit('/' . str_repeat('-/', 10));
            $pagenow = 'index.php';
            
        } else if( $path === home_url( $login_slug, 'relative') || ( !get_option('permalink_structure') && isset($_GET[$login_slug]) && empty($_GET[$login_slug]) ) ) {
            //Hidden Login URL
            $pagenow = 'wp-login.php';
        }

    }

    /**
     * Get Nexter Custom Login Url
     * @since 1.1.0
     */
    public function nxt_custom_login_slug() {
        if(isset($this->cusloOption['custom_login_url']) && !empty($this->cusloOption['custom_login_url'])) {
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
        if ( is_admin() && ! is_user_logged_in() && ! defined( 'DOING_AJAX' ) && $pagenow !== 'admin-post.php' && (isset($_GET) && empty($_GET['adminhash']) && empty($_GET['newuseremail'])) ) {
            $this->nxt_redirect_login_url();
            //You must log in to access the admin area
        }
        
        $request_URI = parse_url( $_SERVER['REQUEST_URI'] );
        if ( ! is_user_logged_in() && $request_URI['path'] === '/wp-admin/options.php' ) {
            header('Location: ' . $this->nxt_new_login_url() );
            die;
        }
        
        //wp-login Form - Path Mismatch
        if($pagenow === 'wp-login.php' && $request_URI['path'] !== $this->nxt_user_trailingslashit($request_URI['path']) && get_option('permalink_structure')) {

            //Redirect Login New URL
            $redirect_URL = $this->nxt_user_trailingslashit($this->nxt_new_login_url()) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
            wp_safe_redirect($redirect_URL);
            die();
        } else if($this->nxt_custom_login) {
            //wp-login.php Directly
            $this->nxt_redirect_login_url();
            
        }else if($pagenow === 'wp-login.php') {
            //Login Form
            
            global $error, $interim_login, $action, $user_login;
            
            //User Already Logged In
            if(is_user_logged_in() && !isset($_REQUEST['action'])) {
                wp_safe_redirect(admin_url());
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
        if( !empty( $this->cusloOption['disable_login_url_behavior'] ) ) {
            if( $this->cusloOption['disable_login_url_behavior'] == 'home_page' ) {
                wp_safe_redirect(home_url());
                die();
            }else if( $this->cusloOption['disable_login_url_behavior'] == '404_page' ) {
                global $wp_query;
                if( function_exists('status_header') ) {
                    status_header('404');
                    nocache_headers();
                }
                if ( $wp_query && is_object( $wp_query ) ) {
                    $wp_query->set_404();
                    get_template_part( '404' );
                }
                exit();
            } 
        }

        $message = !empty($this->cusloOption['login_page_message']) ? $this->cusloOption['login_page_message'] : esc_html__('This has been disabled.', 'nexter-ext');
        wp_die($message, 403);
    }

    /**
     * Login Customize.php Redirect Not Login
     * @since 1.1.0
     */

    public function nxt_login_customizer_redirect(){
        global $pagenow;

        if(!is_user_logged_in() && $pagenow === 'customize.php') {
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
        
        if(strpos($url, 'wp-login.php') !== false) {
            
            if ( is_ssl() ) {
                $scheme = 'https';
            }

            $url_args = explode( '?', $url );

            if ( isset( $url_args[1] ) ) {
                parse_str( $url_args[1], $url_args );
                if(isset($url_args['login'])) {
                    $url_args['login'] = rawurlencode($url_args['login']);
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

        if( isset($this->cusloOption['custom_login_url']) && !empty($this->cusloOption['custom_login_url']) ) {
            $value = str_replace( array('wp-login.php', 'wp-admin'), trailingslashit($this->cusloOption['custom_login_url']), $value);
        }
    
        return $value;
    }

    /**
     * Admin Url Login
     * @since 1.1.0
     */

    public function nxt_login_admin_url( $url ){
	
        if(is_multisite() && ms_is_switched() && is_admin()) {
    
            global $current_blog;
            $current_blog_id = get_current_blog_id();
    
            if($current_blog_id != $current_blog->blog_id) {
    
                if(!empty($this->cusloOption['custom_login_url'])) {
                    $url = preg_replace('/\/wp-admin\/$/', '/' . $this->cusloOption['custom_login_url'] . '/', $url);
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
        if( '/' === substr( get_option( 'permalink_structure' ), -1, 1 ) ) {
            return trailingslashit($string);
        }
        else {
            return untrailingslashit($string);
        }
    }

    /**
     * New Login Url
     * @since 1.1.0
     */
    
    public function nxt_new_login_url( $scheme = null ){
        if(get_option('permalink_structure')) {
            return $this->nxt_user_trailingslashit(home_url('/', $scheme) . $this->nxt_custom_login_slug());
        } else {
            return home_url('/', $scheme) . '?' . $this->nxt_custom_login_slug();
        }
    }
}
new Nexter_Ext_Custom_Login_Redirect();
