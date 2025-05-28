<?php 
/*
 * Redirect 404 Page Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Redirect_404_Page {
    
    /**
     * Constructor
     */
    public function __construct() {
		add_filter( 'template_redirect', [$this, 'redirect_404_page'], PHP_INT_MAX );
    }

	public function redirect_404_page() {
        if ( !is_404() || is_admin() || defined( 'DOING_CRON' ) && DOING_CRON || defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return;
        } else if ( is_404() ) {
            $redirect_url = site_url();
            header( 'HTTP/1.1 301 Moved Permanently' );
            header( 'Location: ' . sanitize_url( $redirect_url ) );
            exit;
        }
    }

}

 new Nexter_Ext_Redirect_404_Page();