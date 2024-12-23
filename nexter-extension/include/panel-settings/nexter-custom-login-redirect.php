<?php 
/**
 * Nexter Custom Login Redirect
 *
 * @package Nexter Extensions
 * @since 1.0.7
 */
defined('ABSPATH') or die();
/*
 * Nexter Login Options Disable
 * @since 1.0.11
 */
$nxt_site_security = get_option( 'nexter_site_security' );

/*Redirect Login Url*/
$nexter_custom_login = false;

if(!empty($nxt_site_security['custom_login_url']) && !defined('WP_CLI')) {

	add_action('plugins_loaded', 'nxt_login_plugins_loaded', 2);
	add_action('wp_loaded', 'nxt_wp_loaded');
	add_action('setup_theme', 'nxt_login_customizer_redirect', 1);
	
	add_filter('site_url', 'nxt_login_site_url', 10, 4);
	add_filter('network_site_url', 'nxt_login_netwrok_site_url', 10, 3);
	add_filter('wp_redirect', 'nxt_login_wp_redirect', 10, 2);
	
	add_filter('site_option_welcome_email', 'nxt_login_welcome_email');
	
	remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
	add_filter('admin_url', 'nxt_login_admin_url');
}

//Login Customize.php Redirect Not Login
function nxt_login_customizer_redirect(){
	global $pagenow;

	if(!is_user_logged_in() && $pagenow === 'customize.php') {
		nxt_redirect_login_url();
	}
}

//Admin Url Login
function nxt_login_admin_url( $url ){
	
	if(is_multisite() && ms_is_switched() && is_admin()) {

		global $current_blog;

		$current_blog_id = get_current_blog_id();

		if($current_blog_id != $current_blog->blog_id) {

			$performance_options = get_blog_option($current_blog_id, 'nexter_site_security');

			if(!empty($performance_options['custom_login_url'])) {
				$url = preg_replace('/\/wp-admin\/$/', '/' . $performance_options['custom_login_url'] . '/', $url);
			} 
		}
	}

	return $url;
}

//Site Url
function nxt_login_site_url( $url, $path, $scheme, $blog_id ){
	return nxt_filter_login_php( $url, $scheme );
}

//Nextwork Site Url
function nxt_login_netwrok_site_url( $url, $path, $scheme ){
	return nxt_filter_login_php( $url, $scheme );
}

function nxt_login_wp_redirect( $location, $status ) {
	return nxt_filter_login_php( $location );
}

function nxt_filter_login_php( $url, $scheme = null ){
	
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
			$url = add_query_arg( $url_args, nxt_new_login_url( $scheme ) );
		} else {
			$url = nxt_new_login_url( $scheme );
		}
	}

	return $url;
}

function nxt_custom_login_slug() {
	$nxt_site_security = get_option( 'nexter_site_security' );

	if(!empty($nxt_site_security['custom_login_url'])) {
		return $nxt_site_security['custom_login_url'];
	}
}

function nxt_login_welcome_email( $value ) {

	$nxt_site_security = get_option( 'nexter_site_security' );

	if(!empty($nxt_site_security['custom_login_url'])) {
		$value = str_replace( array('wp-login.php', 'wp-admin'), trailingslashit($nxt_site_security['custom_login_url']), $value);
	}

	return $value;
}

function nxt_user_trailingslashit($string) {
	//Check for Permalink Trailing Slash and Add to String
	if( '/' === substr( get_option( 'permalink_structure' ), -1, 1 ) ) {
		return trailingslashit($string);
	}
	else {
		return untrailingslashit($string);
	}
}

//New Login Url
function nxt_new_login_url( $scheme = null ){
	
	if(get_option('permalink_structure')) {
		return nxt_user_trailingslashit(home_url('/', $scheme) . nxt_custom_login_slug());
	} else {
		return home_url('/', $scheme) . '?' . nxt_custom_login_slug();
	}
}

function nxt_login_plugins_loaded(){
	global $pagenow;
	global $nexter_custom_login;
	
	if (! is_multisite() && ( strpos( $_SERVER['REQUEST_URI'], 'wp-signup' ) !== false || strpos( $_SERVER['REQUEST_URI'], 'wp-activate' ) !== false ) ) {
		wp_die( esc_html__( 'This feature is not enabled.', 'nexter-extension' ) );
	}
	
	$request_URI = parse_url( $_SERVER['REQUEST_URI'] );
	$path = !empty($request_URI['path']) ? untrailingslashit($request_URI['path']) : '';
	
	$login_slug = nxt_custom_login_slug();
	
	if(!is_admin() && (strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-login.php') !== false || $path === site_url('wp-login', 'relative'))) {
		//wp-login.php URL
		$nexter_custom_login = true;

		$_SERVER['REQUEST_URI'] = nxt_user_trailingslashit('/' . str_repeat('-/', 10));
		$pagenow = 'index.php';
		
	} else if(!is_admin() && (strpos(rawurldecode($_SERVER['REQUEST_URI']), 'wp-register.php') !== false || $path === site_url('wp-register', 'relative'))) {
		//wp-register.php
		$nexter_custom_login = true;

		//Prevent Redirect to Hidden Login
		$_SERVER['REQUEST_URI'] = nxt_user_trailingslashit('/' . str_repeat('-/', 10));
		$pagenow = 'index.php';
		
	} else if($path === home_url($login_slug, 'relative') || (!get_option('permalink_structure') && isset($_GET[$login_slug]) && empty($_GET[$login_slug]))) {
		//Hidden Login URL
		$pagenow = 'wp-login.php';
	}
}

/*login wp_loaded*/
function nxt_wp_loaded(){
	global $pagenow;
	global $nexter_custom_login;

	//redirect disable WP-Admin
	if ( is_admin() && ! is_user_logged_in() && ! defined( 'DOING_AJAX' ) && $pagenow !== 'admin-post.php' && (isset($_GET) && empty($_GET['adminhash']) && empty($_GET['newuseremail'])) ) {
		nxt_redirect_login_url();
		//You must log in to access the admin area
	}
	
	$request_URI = parse_url( $_SERVER['REQUEST_URI'] );
	if ( ! is_user_logged_in() && $request_URI['path'] === '/wp-admin/options.php' ) {
		header('Location: ' . nxt_new_login_url() );
		die;
	}
	
	//wp-login Form - Path Mismatch
	if($pagenow === 'wp-login.php' && $request_URI['path'] !== nxt_user_trailingslashit($request_URI['path']) && get_option('permalink_structure')) {

		//Redirect Login New URL
		$redirect_URL = nxt_user_trailingslashit(nxt_new_login_url()) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
		wp_safe_redirect($redirect_URL);
		die();
	} else if($nexter_custom_login) {
		//wp-login.php Directly
		nxt_redirect_login_url();
		
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

//disabling a login url redirect
function nxt_redirect_login_url() {
	$nxt_site_security = get_option( 'nexter_site_security' );
	if( !empty( $nxt_site_security['disable_login_url_behavior'] ) ) {
		if( $nxt_site_security['disable_login_url_behavior'] == 'home_page' ) {
			wp_safe_redirect(home_url());
			die();
		}else if( $nxt_site_security['disable_login_url_behavior'] == '404_page' ) {
			global $wp_query;
	    	$wp_query->set_404();
	    	status_header(404);
	        nocache_headers();
	        include(get_query_template('404'));
	    	die();
		} 
	}

	$message = !empty($nxt_site_security['login_page_message']) ? $nxt_site_security['login_page_message'] : __('This has been disabled.', 'nexter-extension');
	wp_die($message, 403);
}