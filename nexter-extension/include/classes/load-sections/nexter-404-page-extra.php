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
		
		if(!empty($sections_hook_404)){
			foreach ( $sections_hook_404 as $post_id) {
				if(get_post_meta( $post_id, 'nxt-404-disable-header', 1 )){
					remove_action( 'nexter_header', 'nexter_header_template' );
					remove_action( 'nexter_breadcrumb', 'nexter_breadcrumb_template' );
					//hello theme
					remove_action( 'nexter_header', 'nexter_ext_render_header' );
					remove_action( 'nexter_breadcrumb', 'nexter_ext_render_breadcrumb' );
				}
				
				if(get_post_meta( $post_id, 'nxt-404-disable-footer', 1 )){
					remove_action( 'nexter_footer', 'nexter_footer_template' );
					remove_action( 'nexter_footer', 'nexter_ext_render_footer' );
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
	if(defined('HELLO_ELEMENTOR_VERSION')){
		add_action( 'nexter_pages_hooks_template', 'nexter_ext_404_page_content_load' );
	}
}