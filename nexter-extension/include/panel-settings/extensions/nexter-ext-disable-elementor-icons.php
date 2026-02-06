<?php
/*
 * Manage Image Extension
 * @since
 */
defined('ABSPATH') or die();

class Nexter_Ext_Disable_Elementor_Icons {

	public function __construct() {
		if(!is_admin()){
			add_action( 'elementor/frontend/after_register_styles', [ $this, 'nexter_ext_ele_disable_icons'], 20 );
			add_action( 'wp_enqueue_scripts', [ $this,'disable_eicons' ], 11 );
		}
	}

	public function nexter_ext_ele_disable_icons(){
		$get_performance = get_option('nexter_site_performance');
		$disable_icons = [];
        if(!empty($get_performance) && isset($get_performance['disable-elementor-icons']) && isset($get_performance['disable-elementor-icons']['switch']) && isset($get_performance['disable-elementor-icons']['values'])){
            $disable_icons = (array) $get_performance['disable-elementor-icons']['values'];
        }else{
			$disable_icons = get_option('nexter_elementor_icons');
		}
		if(!empty($disable_icons)){
			foreach( [ 'solid', 'regular', 'brands' ] as $icons ) {
				if(in_array($icons, $disable_icons)){
					wp_deregister_style( 'elementor-icons-fa-' . $icons );
				}
			}
		}
	}

	public function disable_eicons(){
		$get_performance = get_option('nexter_site_performance');
		$disable_icons = [];
        if(!empty($get_performance) && isset($get_performance['disable-elementor-icons']) && isset($get_performance['disable-elementor-icons']['switch']) && isset($get_performance['disable-elementor-icons']['values'])){
            $disable_icons = (array) $get_performance['disable-elementor-icons']['values'];
        }else{
			$disable_icons = get_option('nexter_elementor_icons');
		}
		if(!empty($disable_icons) && in_array('eicons', $disable_icons)){
			wp_dequeue_style( 'elementor-icons' );
			wp_deregister_style( 'elementor-icons' );
		}
	}
}

new Nexter_Ext_Disable_Elementor_Icons();