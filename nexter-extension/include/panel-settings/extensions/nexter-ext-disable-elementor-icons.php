<?php
/*
 * Manage Image Extension
 * @since
 */
defined('ABSPATH') or die();

class Nexter_Ext_Disable_Elementor_Icons {

	public function __construct() {
		if(is_admin()){
        	add_action( 'wp_ajax_nexter_ext_elementor_icons', [ $this, 'nexter_ext_disable_elementor_icons'] );
		}
		if(!is_admin()){
			add_action( 'elementor/frontend/after_register_styles', [ $this, 'nexter_ext_ele_disable_icons'], 20 );
			add_action( 'wp_enqueue_scripts', [ $this,'disable_eicons' ], 11 );
		}
	}

    public function nexter_ext_disable_elementor_icons(){
        check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
        $ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
        if( !empty($ext) && $ext == 'disable-elementor-icons'){
            $output = '';
            $output .= '<div class="nxt-ext-modal-content">';

				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.esc_html__('Disable Elementor Icons','nexter-ext').'</div>';
				$output .= '</div>';
				$enabled_is = get_option('nexter_elementor_icons');
				$output .= '<div class="nxt-panel-row nxt-disable-ele-icon-sec">';
					$output .= '<div class="nxt-panel-col nxt-panel-col-50">';
						$output .= '<h3 class="nxt-disable-admin-wrap">'.esc_html__('Font Awesome Icons Disable','nexter-ext').'</h3>';
						$output .= '<div class="nxt-disable-admin-wrap">';
							foreach ([ 'Solid' => 'solid', 'Regular' => 'regular', 'Brands' => 'brands'] as $label => $icons ){
								$output .= '<div class="nxt-option-switcher">';
									$output .= '<span class="nxt-option-check-title">'.esc_html($label).' '.esc_html__('Icons','nexter-ext').'</span>';
									$output .= '<span class="nxt-option-checkbox-label">';
										$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($icons).'" name="nxt-disable-elementor-icon[]" value="'.esc_attr($icons).'" '.((!empty($enabled_is) && in_array($icons,$enabled_is)) ? "checked" : "" ).'/>';
										$output .= '<label for="'.esc_attr($icons).'"></label>';
									$output .= '</span>';
								$output .= '</div>';
							}
						$output .= '</div>';
						$output .= '</div>';
						$output .= '<div class="nxt-panel-col nxt-panel-col-50">';
							$output .= '<h3 class="nxt-disable-admin-wrap">'.esc_html__('E-icons Disable','nexter-ext').'</h3>';
							$output .= '<div class="nxt-disable-admin-wrap">';
								$output .= '<div class="nxt-option-switcher">';
									$output .= '<span class="nxt-option-check-title">'.esc_html__('E-icons', 'nexter-ext').'</span>';
									$output .= '<span class="nxt-option-checkbox-label">';
										$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="nxt-ele-eicons" name="nxt-disable-elementor-icon[]" value="eicons" '.((!empty($enabled_is) && in_array('eicons',$enabled_is)) ? "checked" : "" ).'/>';
										$output .= '<label for="nxt-ele-eicons"></label>';
									$output .= '</span>';
								$output .= '</div>';
							$output .= '</div>';
						$output .= '</div>';
				$output .= '</div>';

				$output .= '<button type="button" class="nxt-save-elementor-icons"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
        }
        wp_send_json_error();
    }

	public function nexter_ext_ele_disable_icons(){
		$disable_icons = get_option('nexter_elementor_icons');
		if(!empty($disable_icons)){
			foreach( [ 'solid', 'regular', 'brands' ] as $icons ) {
				if(in_array($icons, $disable_icons)){
					wp_deregister_style( 'elementor-icons-fa-' . $icons );
				}
			}
		}
	}

	public function disable_eicons(){
		$disable_icons = get_option('nexter_elementor_icons');
		if(!empty($disable_icons) && in_array('eicons', $disable_icons)){
			wp_dequeue_style( 'elementor-icons' );
			wp_deregister_style( 'elementor-icons' );
		}
	}
}

new Nexter_Ext_Disable_Elementor_Icons();