<?php
/**
 * OceanWP Theme Compatibility of Theme Builder
 * 
 * @package Nexter Extensions
 * @since 4.0.4
 */
class Nexter_Oceanwp_Compat {

	/**
	 * Instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Nexter_Oceanwp_Compat();

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
            add_action( 'template_redirect', [ $this, 'oceanwp_nxt_ext_header' ], 10 );
            add_action( 'ocean_header', 'nexter_ext_render_header' );
        }

        $sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		if( !empty( $sections_footer ) ){
            add_action( 'template_redirect', [ $this, 'oceanwp_nxt_ext_footer' ], 10 );
            add_action( 'ocean_footer', 'nexter_ext_render_footer' );
        }

        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        if( !empty( $sections_hook_404 ) && is_404() ){
            add_action( 'ocean_before_content_wrap', [ $this, 'nexter_ext_render_404_page' ] );
        }

		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		if(!empty($section_breadcrumb_ids)){
			add_action( 'ocean_page_header', array( $this, 'display_breadcrumb_section' ) );
		}
	}

	/**
	 * Disable header.
	 */
	public function oceanwp_nxt_ext_header() {
		remove_action( 'ocean_top_bar', 'oceanwp_top_bar_template' );
		remove_action( 'ocean_header', 'oceanwp_header_template' );
		remove_action( 'ocean_page_header', 'oceanwp_page_header_template' );
	}

	/**
	 * Disable footer.
	 */
	public function oceanwp_nxt_ext_footer() {
		remove_action( 'ocean_footer', 'oceanwp_footer_template' );
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

Nexter_Oceanwp_Compat::instance();