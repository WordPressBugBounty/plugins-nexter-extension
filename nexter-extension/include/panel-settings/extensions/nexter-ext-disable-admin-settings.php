<?php 
/*
 * Disable Admin Settings Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Disable_Admin_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
		$extension_option = get_option( 'nexter_extra_ext_options' );

		if(!empty($extension_option) && isset($extension_option['disable-admin-setting']) && !empty($extension_option['disable-admin-setting']['switch']) && !empty($extension_option['disable-admin-setting']['values']) ){
			$disable_values = $extension_option['disable-admin-setting']['values'];
			
			if( is_admin() && !empty($disable_values) ){
				if( in_array("disable_theme_up_noti",$disable_values) ){
					remove_action( 'load-update-core.php', 'wp_update_themes' );
					add_filter( 'pre_site_transient_update_themes', '__return_null' );
					add_filter( 'auto_theme_update_send_email', '__return_false' );
				}
				if( in_array("disable_plugin_up_noti",$disable_values) ){
					remove_action( 'load-update-core.php', 'wp_update_plugins' );
					add_filter( 'pre_site_transient_update_plugins', '__return_null' );
					add_filter( 'auto_plugin_update_send_email', '__return_false' );
				}
				if( in_array("disable_admin_notice",$disable_values) ){
					add_action('in_admin_header', function () {
						remove_all_actions('admin_notices');
						remove_all_actions('all_admin_notices');
					}, 1000);
				}
				if(in_array("disable_core_up_noti",$disable_values)){
					add_filter('update_footer', '__return_false');
					add_filter('pre_site_transient_update_core','__return_false');
					//add_filter('site_transient_update_core','__return_false');

					function remove_core_updates () {
						global $wp_version;
						return(object) array(
							 'last_checked'=> time(),
							 'version_checked'=> $wp_version,
							 'updates' => array()
						);
				   }
				   add_filter('pre_site_transient_update_core','remove_core_updates');
				}
				if(in_array("remove_admin_panel",$disable_values)){
					add_action( 'admin_init', function(){
						remove_action('welcome_panel', 'wp_welcome_panel');
					});
				}
				if(in_array("remove_php_up_notice",$disable_values)){
					remove_action( 'admin_notices', 'update_nag', 3 );

					function nxt_remove_php_update_notice() {
						remove_meta_box( 'dashboard_php_nag', 'dashboard', 'normal' );
					}
					add_action( 'wp_dashboard_setup', 'nxt_remove_php_update_notice' );
				}
			}else if(!empty($disable_values) && in_array("disable_fadmin_bar",$disable_values)){
				add_filter( 'show_admin_bar', '__return_false' );
			}

		}
    }

}

 new Nexter_Ext_Disable_Admin_Settings();