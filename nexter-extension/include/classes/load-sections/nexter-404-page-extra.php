<?php
/*
 * Nexter Load 404 Page
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

/*
 * Disable Header/Footer in 404 Page
 */
if ( ! function_exists( 'nexter_404_page_disable_header_footer' ) ) {
	
	function nexter_404_page_disable_header_footer() {
		$sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
		
		if(!empty($sections_hook_404) && is_404() ){
			foreach ( $sections_hook_404 as $post_id) {
				if(get_post_meta( $post_id, 'nxt-404-disable-header', 1 )){
					remove_action( 'nexter_header', 'nexter_header_template' );
					remove_action( 'nexter_breadcrumb', 'nexter_breadcrumb_template' );
					//hello theme
					remove_action( 'nexter_header', 'nexter_ext_render_header' );
					remove_action( 'nexter_breadcrumb', 'nexter_ext_render_breadcrumb' );

					//Astra theme
					remove_action( 'astra_header', 'nexter_ext_render_header' );
					//GeneratePress Theme
					remove_action( 'generate_header', 'generate_construct_header' );
					remove_action( 'generate_header', 'nexter_ext_render_header' );
					//OceanWP
					remove_action( 'ocean_top_bar', 'oceanwp_top_bar_template' );
					remove_action( 'ocean_header', 'oceanwp_header_template' );
					remove_action( 'ocean_page_header', 'oceanwp_page_header_template' );
					remove_action( 'ocean_header', 'nexter_ext_render_header' );

					//Blocksy
					add_filter( 'blocksy:builder:header:enabled', function(){ return false; } );
            		remove_action( 'blocksy:header:before', 'nexter_ext_render_header' );

					//Kadence
					if (function_exists('Kadence\header_markup')) {
						remove_action('kadence_header', 'Kadence\header_markup');
					}
					remove_action( 'kadence_header', 'nexter_ext_render_header' );
					//Neve
					remove_all_actions( 'neve_do_top_bar' );
					remove_all_actions( 'hfg_header_render' );
					remove_action( 'neve_do_header', 'nexter_ext_render_header' );
				}
				
				if(get_post_meta( $post_id, 'nxt-404-disable-footer', 1 )){
					remove_action( 'nexter_footer', 'nexter_footer_template' );
					remove_action( 'nexter_footer', 'nexter_ext_render_footer' );
					//Astra theme
					remove_action( 'astra_footer', 'nexter_ext_render_footer' );
					//GeneratePress Theme
					remove_action( 'generate_footer', 'generate_construct_footer_widgets', 5 );
					remove_action( 'generate_footer', 'generate_construct_footer' );
					remove_action( 'generate_footer', 'nexter_ext_render_footer' );
					//OceanWP
					remove_action( 'ocean_footer', 'oceanwp_footer_template' );
					remove_action( 'ocean_footer', 'nexter_ext_render_footer' );
					//Blocksy
					add_filter( 'blocksy:builder:footer:enabled', function(){ return false; } );
            		remove_action( 'blocksy:footer:before', 'nexter_ext_render_footer' );
					//Kadence
					if(function_exists('Kadence\footer_markup')){
						remove_action( 'kadence_footer', 'Kadence\footer_markup' );
					}
					remove_action( 'kadence_footer', 'nexter_ext_render_footer' );
					//Neve
					remove_all_actions( 'hfg_footer_render' );
					remove_action( 'neve_do_footer', 'nexter_ext_render_footer' );
				}
			}
		}
	}
	add_action( 'wp', 'nexter_404_page_disable_header_footer', 11 );	
}

/**
 * Nexter 404 Page Content Load
*/
if ( ! function_exists( 'nexter_ext_404_page_content_load' ) ) {

	function nexter_ext_404_page_content_load() {
		
		$sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
		
		if(!empty($sections_hook_404)){
			foreach ( $sections_hook_404 as $post_id) {				
				Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
			}
		}else{
			get_template_part( 'template-parts/404-page/404-page' );
		}
	}
	
	add_action( 'nexter_404_page_content', 'nexter_ext_404_page_content_load' );
	if(!defined('ASTRA_THEME_VERSION') && !defined('GENERATE_VERSION') && !defined('OCEANWP_THEME_VERSION') && !defined('KADENCE_VERSION') && !function_exists('blocksy_get_wp_theme') && !defined('NEVE_VERSION') && !defined('NXT_VERSION')){
		add_action( 'nexter_pages_hooks_template', 'nexter_ext_404_page_content_load' );
	}
}