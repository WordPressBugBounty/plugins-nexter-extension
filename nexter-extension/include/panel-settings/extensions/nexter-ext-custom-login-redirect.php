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
        
        $this->cusloOption = get_option( 'nexter_site_security' );
        
        if(isset($this->cusloOption['custom-login']) && !empty($this->cusloOption['custom-login']) && isset($this->cusloOption['custom-login']['switch']) && !empty($this->cusloOption['custom-login']['switch'])){
            if(isset($this->cusloOption['custom-login']['values']) && !empty($this->cusloOption['custom-login']['values'])){
                $this->cusloOption = (array) $this->cusloOption['custom-login']['values'];
            }
        }

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
     * Nexter Custom Login Load
     * @since 1.1.0
     */

    public function nxt_login_plugins_loaded(){
        global $pagenow;
        
        // Security: Sanitize REQUEST_URI
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
        
        if ( !is_multisite() && ( strpos( $request_uri, 'wp-signup' ) !== false || strpos( $request_uri, 'wp-activate' ) !== false ) ) {
            wp_die( esc_html__( 'This feature is not enabled.', 'nexter-extension' ) );
        }

        // Security: Sanitize REQUEST_URI to prevent XSS
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
        $request_URI = parse_url( $request_uri );
        $path = !empty($request_URI['path']) ? untrailingslashit($request_URI['path']) : '';
        
        $login_slug = $this->nxt_custom_login_slug();

        if( !is_admin() && ( strpos(rawurldecode($request_uri), 'wp-login.php') !== false || $path === site_url('wp-login', 'relative') ) ) {
            //wp-login.php URL 
            $this->nxt_custom_login = true;
    
            $_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit('/' . str_repeat('-/', 10));
            $pagenow = 'index.php';
            
        } else if( !is_admin() && ( strpos(rawurldecode($request_uri), 'wp-register.php') !== false || $path === site_url('wp-register', 'relative') ) ) {
            //wp-register.php
           $this->nxt_custom_login = true;
    
            //Prevent Redirect to Hidden Login
            $_SERVER['REQUEST_URI'] = $this->nxt_user_trailingslashit('/' . str_repeat('-/', 10));
            $pagenow = 'index.php';
            
        // Security: Sanitize GET parameter
        $get_login_slug = isset( $_GET[ $login_slug ] ) ? sanitize_text_field( wp_unslash( $_GET[ $login_slug ] ) ) : '';
        } else if( $path === home_url( $login_slug, 'relative') || ( !get_option('permalink_structure') && ! empty( $get_login_slug ) && empty( $get_login_slug ) ) ) {
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
        // Security: Sanitize GET parameters
        $get_adminhash = isset( $_GET['adminhash'] ) ? sanitize_text_field( wp_unslash( $_GET['adminhash'] ) ) : '';
        $get_newuseremail = isset( $_GET['newuseremail'] ) ? sanitize_email( wp_unslash( $_GET['newuseremail'] ) ) : '';
        if ( is_admin() && ! is_user_logged_in() && ! defined( 'DOING_AJAX' ) && $pagenow !== 'admin-post.php' && ( empty( $get_adminhash ) && empty( $get_newuseremail ) ) ) {
            $this->nxt_redirect_login_url();
            //You must log in to access the admin area
        }
        
        // Security: Sanitize REQUEST_URI
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
        $request_URI = parse_url( $request_uri );
        $request_path = isset( $request_URI['path'] ) ? $request_URI['path'] : '';
        
        if ( ! is_user_logged_in() && $request_path === '/wp-admin/options.php' ) {
            wp_safe_redirect( $this->nxt_new_login_url() );
            exit;
        }
        
        //wp-login Form - Path Mismatch
        if($pagenow === 'wp-login.php' && $request_path !== $this->nxt_user_trailingslashit($request_path) && get_option('permalink_structure')) {

            //Redirect Login New URL
            $query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
            // Security: Use add_query_arg to properly build URL
            $redirect_URL = $this->nxt_user_trailingslashit($this->nxt_new_login_url());
            if ( ! empty( $query_string ) ) {
                parse_str( $query_string, $query_params );
                $redirect_URL = add_query_arg( $query_params, $redirect_URL );
            }
            wp_safe_redirect( esc_url_raw( $redirect_URL ) );
            exit;
        } else if($this->nxt_custom_login) {
            //wp-login.php Directly
            $this->nxt_redirect_login_url();
            
        }else if($pagenow === 'wp-login.php') {
            //Login Form
            
            global $error, $interim_login, $action, $user_login;
            
            //User Already Logged In
            // Security: Use $_GET instead of $_REQUEST for better security
            $get_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
            if(is_user_logged_in() && empty( $get_action ) ) {
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
                wp_safe_redirect(esc_url_raw(home_url()));
                exit;
            }else if( $this->cusloOption['disable_login_url_behavior'] == '404_page' ) {
                global $wp_query;
                if( function_exists('status_header') ) {
                    status_header(404);
                    nocache_headers();
                }
                if ( $wp_query && is_object( $wp_query ) ) {
                    $wp_query->set_404();
                    get_template_part( '404' );
                }
                exit;
            } 
        }

        // Security: Escape message output
        $message = !empty($this->cusloOption['login_page_message']) ? wp_kses_post($this->cusloOption['login_page_message']) : esc_html__('This has been disabled.', 'nexter-extension');
        wp_die($message, esc_html__('Forbidden', 'nexter-extension'), array('response' => 403));
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
