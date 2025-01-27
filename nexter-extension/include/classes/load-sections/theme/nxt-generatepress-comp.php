<?php
/**
 * GeneratePress Theme Compatibility of Theme Builder
 * 
 * @package Nexter Extensions
 * @since 4.0.4
 */
class Nexter_GeneratePress_Compat {

	/**
	 * Instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Nexter_GeneratePress_Compat();

			add_action( 'wp', [ self::$instance, 'hooks' ] );
		}

		return self::$instance;
	}

	/**
	 * Actions / Filters.
	 */
	public function hooks() {
        $section_normal_header_id = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'header' );
		if(!empty($section_normal_header_id)){
            add_action( 'template_redirect', [ $this, 'generatepress_nxt_ext_header' ] );
			add_action( 'generate_header', 'nexter_ext_render_header' );
        }

		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		if(!empty($section_breadcrumb_ids)){
			add_action( 'generate_after_header', array( $this, 'display_breadcrumb_section' ),9 );
		}

        $sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		if( !empty( $sections_footer ) ){
			add_action( 'template_redirect', [ $this, 'generatepress_nxt_ext_footer' ] );
			add_action( 'generate_footer', 'nexter_ext_render_footer' );
        }

        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        if( !empty( $sections_hook_404 ) && is_404() ){
			add_filter( 'generate_do_template_part', function ( $do_template_part, $template ) {
				if ( '404' === $template ) {
					return false;
				}
				return true;
			}, 10, 2 );
			add_action( 'generate_before_do_template_part', [ $this, 'nexter_ext_render_404_page' ] );
        }

	}

	/**
	 * Disable header.
	 */
	public function generatepress_nxt_ext_header() {
		remove_action( 'generate_header', 'generate_construct_header' );
	}

	/**
	 * Disable footer.
	 */
	public function generatepress_nxt_ext_footer() {
		remove_action( 'generate_footer', 'generate_construct_footer_widgets', 5 );
		remove_action( 'generate_footer', 'generate_construct_footer' );
	}

    /**
	 * Display 404 Page Template.
	 */
    public function nexter_ext_render_404_page(){
        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        
        if(!empty($sections_hook_404)){
            foreach ( $sections_hook_404 as $post_id) {				
                Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
            }
        }
    }

	public function display_breadcrumb_section(){
		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		
		if(!empty($section_breadcrumb_ids)){
		
			foreach ( $section_breadcrumb_ids as $post_id) {
			
				Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );
				
			}
		}
	}
}

Nexter_GeneratePress_Compat::instance();