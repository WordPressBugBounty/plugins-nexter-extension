<?php
/**
 * Nexter Header Template
 * 
 * @package Nexter Extensions
 * @since 1.0.0
 */
if( ! function_exists('get_nexter_header_sections') ){
	
	function get_nexter_header_sections( $sections ){
		//Normal Header
		$section_normal_header_id = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'header' );
		if(!empty($section_normal_header_id)){
			$sections = array_merge($sections, $section_normal_header_id);
		}
		return $sections;
	}
	add_filter( 'nexter_header_sections_ids', 'get_nexter_header_sections' );
}

/**
 * Override template header
 * 
 * @since 3.2.0
 */
function nexter_ext_render_header() {
	?>
		<header id="masthead" itemscope="itemscope" itemtype="https://schema.org/WPHeader">
			<p class="main-title hide" itemprop="headline" style="display:none"><a href="<?php echo bloginfo( 'url' ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
			<?php do_action('nexter_normal_header_content'); ?>
		</header>
	<?php
}

/**
 * Get Normal Header Content
 * 
 * @since 1.0.6
 */
if( ! function_exists('nexter_normal_header_content_load') ){
	
	function nexter_normal_header_content_load(){
		
		$section_normal_header_id = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'header' );
		
		if(!empty($section_normal_header_id)){
			foreach ( $section_normal_header_id as $post_id) {
				$header_type = get_post_meta( $post_id, 'nxt-normal-sticky-header', true );
				if( empty($header_type) || (!empty($header_type) && $header_type!= 'sticky')){
					Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
				}
			}
		}
	}
	add_action( 'nexter_normal_header_content', 'nexter_normal_header_content_load' );
}

/**
 * Get Sticky Header Content
 * 
 * @since 1.0.5
 */
if( ! function_exists('nexter_sticky_header_content_load') ){
	
	function nexter_sticky_header_content_load(){
		$sticky_header_display = false;
		$section_sticky_header_id = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'header' );
		
		if(!empty($section_sticky_header_id)){
			foreach ( $section_sticky_header_id as $post_id) {
				
				$header_type = get_post_meta( $post_id, 'nxt-normal-sticky-header', true );
				if(!empty($header_type) && $header_type== 'sticky'){
					Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
				}
			}
		}
	}
	add_action( 'nexter_sticky_header_content', 'nexter_sticky_header_content_load' );
}

/*
 * Transparent Header & Sticky Header Classes
 *
 * @since 1.0.0
 */
if ( ! function_exists( 'nexter_header_transparent_sticky_classes' ) ) {
	function nexter_header_transparent_sticky_classes( $classes ) {

		$sections = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'header' );
		
		$transparent_display = false;
		$sticky_display      = false;

		if ( ! empty( $sections ) ) {
			foreach ( $sections as $post_id ) {
				// Check for Transparent Header
				$transparent = get_post_meta( $post_id, 'nxt-transparent-header', true );
				if ( ! empty( $transparent ) && $transparent == 'on' ) {
					$transparent_display = true;
				}

				// Check for Sticky Header
				$sticky = get_post_meta( $post_id, 'nxt-normal-sticky-header', true );
				if ( ! empty( $sticky ) && ( $sticky == 'sticky' || $sticky == 'both' ) ) {
					$sticky_display = true;
				}
			}
		}

		// Add appropriate classes
		if ( $transparent_display ) {
			$classes[] = 'nxt-trans-overlay';
		}
		if ( $sticky_display ) {
			$classes[] = 'nxt-sticky';
		}

		return $classes;
	}
	add_filter( 'nexter_header_class', 'nexter_header_transparent_sticky_classes', 10, 1 );
}