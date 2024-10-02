<?php 
/**
 * Nexter Footer Template
 * 
 * @package Nexter Extensions
 * @since 1.0.0
 */
if( ! function_exists('nexter_footer_sections') ) {
	
	function nexter_footer_sections( $sections ) {
		
		$sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		
		if(!empty($sections_footer)){
			$sections = array_merge($sections, $sections_footer);
		}
		return $sections;
	}
	add_filter( 'nexter_footer_sections_ids', 'nexter_footer_sections' );
}

/**
 * Override template Footer
 * 
 * @since 3.2.0
 */
function nexter_ext_render_footer() {
	?>
		<footer itemtype="https://schema.org/WPFooter" itemscope="itemscope" id="nxt-ext-footer" role="contentinfo">
			<?php do_action('nexter_footer_content'); ?>
		</footer>
	<?php
}

/**
 * Nexter Footer Content Load
 * 
 * @since 1.0.0
 */
if( ! function_exists('nexter_footer_content_load') ){
	
	function nexter_footer_content_load(){
		
		$sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		
		if( !empty( $sections_footer ) ){
		
			foreach ( $sections_footer as $post_id) {
				Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
			}
		}
	}
	add_action( 'nexter_footer_content', 'nexter_footer_content_load' );
}

/*
 * Nexter Footer Classes
 *
 * @since 1.0.0
 */
if( ! function_exists( 'nexter_footer_class_style' ) ) {

	function nexter_footer_class_style( $classes ) {
	
		$sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		
		if( !empty($sections_footer) ){
		
			$sections_style='';
			foreach ( $sections_footer as $post_id ) {
				$sections_style= get_post_meta( $post_id, 'nxt-hooks-footer-style', true );
			}
			if(!empty($sections_style)){
				$classes[] = 'w-'.esc_attr($sections_style);
			}
		}
		
		return $classes;
	}
	add_filter( 'nexter_footer_class', 'nexter_footer_class_style', 10, 1 );
}

/*
 * Nexter Footer Style Css
 *
 * @since 1.0.0
*/
if( ! function_exists( 'nexter_footer_render_style' ) ) {
	function nexter_footer_render_style( $theme_css ) {
	
		$sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		$style	 = [];
		if( !empty($sections_footer) ){
		
			$sections_style='';
			foreach ( $sections_footer as $post_id ) {
				$sections_style= get_post_meta( $post_id, 'nxt-hooks-footer-style', true );
				$smart_bgcolor= get_post_meta( $post_id, 'nxt-hooks-footer-smart-bgcolor', true );
				if(!empty($smart_bgcolor) && !empty($sections_style) && $sections_style=='smart'){
					$style['.smart-footer.off-preview']  = [
						'background' => esc_attr($smart_bgcolor)
					];
				}
			}
			
		}
		if( !empty($style)){
			$theme_css[]= $style;
		}
		return $theme_css;
	}
	add_filter( 'nxt_render_theme_css', 'nexter_footer_render_style' );
 }