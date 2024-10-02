<?php
/*
 * Nexter Breadcrumb Template 
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
if( ! function_exists( 'nexter_breadcrumb_sections' ) ) {

	function nexter_breadcrumb_sections( $sections ) {
	
		$section_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		
		if(!empty($section_ids)){
			$sections = array_merge($sections, $section_ids);
		}
		
		return $sections;
	}
	
	add_filter( 'nexter_breadcrumb_sections_ids', 'nexter_breadcrumb_sections' );
}

/**
 * Override template header breadcrumb
 * 
 * @since 3.2.0
 */
function nexter_ext_render_breadcrumb(){
	do_action( 'nexter_breadcrumb_content' );
}

/**
 * Get Breadcrumb Content Load
 * 
 * @since 1.0.0
 */
if( ! function_exists('nexter_breadcrumb_content_load') ){
	
	function nexter_breadcrumb_content_load(){
		
		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		
		if(!empty($section_breadcrumb_ids)){
		
			foreach ( $section_breadcrumb_ids as $post_id) {
			
				Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
				
			}
		}
	}
	add_action( 'nexter_breadcrumb_content', 'nexter_breadcrumb_content_load' );
}