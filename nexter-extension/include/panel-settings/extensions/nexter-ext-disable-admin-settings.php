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
		if(is_admin()){
			add_action( 'wp_ajax_nexter_ext_disable_admin_setting_content', [ $this, 'nexter_ext_disable_admin_setting_content_ajax'] );
		}

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

	/*
	 * Nexter Disable Admin Settings
	 * @since 1.1.0
	 */
	public function nexter_ext_disable_admin_setting_content_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$extension_option = get_option( 'nexter_extra_ext_options' );
		if( !empty( $ext ) && $ext == 'disable-admin-setting' ){
			$adminHide = []; $enable_admin = '';
			if(!empty($extension_option) && isset($extension_option['disable-admin-setting']) && !empty($extension_option['disable-admin-setting']['values']) ){
				$adminHide = $extension_option['disable-admin-setting']['values'];
			}
			
			$config_ext = [];
            if(function_exists('nexter_extension_option_config')){
               $config_data = nexter_extension_option_config();
               if( !empty( $config_data ) && isset($config_data[$ext]) ){
                   $config_ext = $config_data[$ext];
               }
            }else if(class_exists('Nexter_Ext_Panel_Settings')){
				$ext_panel = Nexter_Ext_Panel_Settings::get_instance();
				$config_data = $ext_panel->nexter_extension_option_config();
				if( !empty( $config_data ) && isset($config_data[$ext]) ){
					$config_ext = $config_data[$ext];
				}
			}

			$output = '';
			$output .= '<div class="nxt-ext-modal-content">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['title']) : '').'</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-disable-admin-wrap">';
					$disable_admin_options = [
						'disable_fadmin_bar' => [
							'title' => esc_html__( 'Hide Frontend Admin Bar', 'nexter-ext' ),
							'desc' => esc_html__( 'Hide Frontend Admin Bar', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/frontend-admin-bar.svg',
						],
						'disable_theme_up_noti' => [
							'title' => esc_html__( 'Hide Themes Update Notifications', 'nexter-ext' ),
							'desc' => esc_html__( 'Hide Themes Update Notifications', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/theme-update-notify.svg',
						],
						'disable_plugin_up_noti' => [
							'title' => esc_html__( 'Hide Plugin Update Notifications', 'nexter-ext' ),
							'desc' => esc_html__( 'Hide Plugin Update Notifications', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/plugin-update-notify.svg',
						],
						'disable_core_up_noti' => [
							'title' => esc_html__( 'Hide Core Update Notifications', 'nexter-ext' ),
							'desc' => esc_html__( 'Hide Core Update Notifications', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/core-update-notify.svg',
						],
						'disable_admin_notice' => [
							'title' => esc_html__( 'Hide Admin Notice', 'nexter-ext' ),
							'desc' => esc_html__( 'Hide Admin Notice', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/hide-admin-notify.svg',
						],
						'remove_php_up_notice' => [
							'title' => esc_html__( 'Remove ‘PHP Update Required’ Notice', 'nexter-ext' ),
							'desc' => esc_html__( 'Remove "PHP Update Required" Notice', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/php-update-required.svg',
						],
						'remove_admin_panel' => [
							'title' => esc_html__( 'Remove Welcome Panel', 'nexter-ext' ),
							'desc' => esc_html__( 'Remove Welcome Panel', 'nexter-ext' ),
							'icon' => NEXTER_EXT_URL.'assets/images/panel-icon/remove-welcome-panel.svg',
						],
					];
					foreach($disable_admin_options as $option => $data){
						$output .= '<div class="nxt-option-switcher">';
							$output .= '<span class="nxt-extra-icon"><img src="'.esc_url($data['icon']).'" alt="'.esc_attr($option).'" /></span>';
							$output .= '<span class="nxt-option-check-title">'.wp_kses_post($data['title']).'</span>';
							/*$output .= '<span class="nxt-desc-icon">';
								$output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_attr__('description','nexter-ext').'" /> ';
								$output .= '<div class="nxt-tooltip">'.wp_kses_post($data['desc']).'</div>';
							$output .= '</span>';*/
							$output .= '<span class="nxt-option-checkbox-label">';
								$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($option).'" name="nxt-disable-admin[]" value="'.esc_attr($option).'" '.(!empty($adminHide) && in_array($option,$adminHide) ? "checked" : "" ).'/>';
								$output .= '<label for="'.esc_attr($option).'"></label>';
							$output .= '</span>';	
						$output .= '</div>';
					}
				$output .= '</div>';
				$output .= '<button type="button" class="nxt-save-disable-admin"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" ><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';
			
			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
	}

}

 new Nexter_Ext_Disable_Admin_Settings();