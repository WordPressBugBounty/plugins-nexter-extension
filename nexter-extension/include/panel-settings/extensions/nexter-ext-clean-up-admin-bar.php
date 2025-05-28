<?php 
/*
 * Clean Up Admin Bar Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_CleanUp_Admin_Bar {
    
	public static $clean_up_opt = [];

    /**
     * Constructor
     */
    public function __construct() {
		$this->nxt_get_post_order_settings();

		add_filter( 'admin_bar_menu', [$this, 'modify_admin_bar_menu'], 5 );
		add_filter( 'admin_bar_menu', [$this, 'remove_howdy'], PHP_INT_MAX - 100 );
		if ( !empty(self::$clean_up_opt) && in_array("adminbar-help-tab",self::$clean_up_opt) ) {
			add_action( 'admin_head', [$this, 'hide_help_drawer'] );
		}
    }

	private function nxt_get_post_order_settings(){

		if(isset(self::$clean_up_opt) && !empty(self::$clean_up_opt)){
			return self::$clean_up_opt;
		}

		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['clean-up-admin-bar']) && !empty($option['clean-up-admin-bar']['switch']) && !empty($option['clean-up-admin-bar']['values']) ){
			self::$clean_up_opt = $option['clean-up-admin-bar']['values'];
		}

		return self::$clean_up_opt;
	}

	public function modify_admin_bar_menu( $wp_admin_bar ) {
        if(!empty(self::$clean_up_opt)){
			// Hide WP Logo Menu
			if(in_array("adminbar-wp-logo",self::$clean_up_opt)){
				remove_action( 'admin_bar_menu', 'wp_admin_bar_wp_menu', 10 );
			}
			// Hide home icon and site name
			if ( in_array("adminbar-site-name",self::$clean_up_opt) ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_site_menu', 30 );
			}
			// Hide Customize Menu
			if ( in_array("adminbar-customize-menu",self::$clean_up_opt) ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_customize_menu', 40 );
			}
			// Hide Updates Counter/Link
			if ( in_array("adminbar-updates-link",self::$clean_up_opt) ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_updates_menu', 50 );
			}
			// Hide Comments Counter/Link
			if ( in_array("adminbar-comments-link",self::$clean_up_opt) ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
			}
			// Hide New Content Menu
			if ( in_array("adminbar-new-content",self::$clean_up_opt) ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_new_content_menu', 70 );
			}
		}
    }

	public function remove_howdy( $wp_admin_bar ) {
        // Hide 'Howdy' text
        if ( !empty(self::$clean_up_opt) && in_array("adminbar-howdy",self::$clean_up_opt) ) {
            remove_action( 'admin_bar_menu', 'wp_admin_bar_my_account_item', 7 );
            // Up to WP v6.5.5
            remove_action( 'admin_bar_menu', 'wp_admin_bar_my_account_item', 9991 );
            // Since WP v6.6
            $current_user = wp_get_current_user();
            $user_id = get_current_user_id();
            $profile_url = get_edit_profile_url( $user_id );
            $avatar = get_avatar( $user_id, 26 );
            // size 26x26 pixels
            $display_name = $current_user->display_name;
            $class = ( $avatar ? 'with-avatar' : 'no-avatar' );
            $wp_admin_bar->add_menu( array(
                'id'     => 'my-account',
                'parent' => 'top-secondary',
                'title'  => $display_name . $avatar,
                'href'   => $profile_url,
                'meta'   => array(
                    'class' => $class,
                ),
            ) );
        }
    }

	public function hide_help_drawer() {
        if ( is_admin() ) {
            $screen = get_current_screen();
            $screen->remove_help_tabs();
        }
    }

}

 new Nexter_Ext_CleanUp_Admin_Bar();