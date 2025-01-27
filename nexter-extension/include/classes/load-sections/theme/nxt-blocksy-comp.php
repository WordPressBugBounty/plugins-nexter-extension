<?php
/**
 * Blocksy Theme Compatibility of Theme Builder
 * 
 * @package Nexter Extensions
 * @since 4.0.4
 */
class Nexter_Blocksy_Compat {

	/**
	 * Instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Nexter_Blocksy_Compat();

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
			add_filter( 'blocksy:builder:header:enabled', function(){ return false; } );
            add_action( 'blocksy:header:before', 'nexter_ext_render_header' );
        }

        $sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		if( !empty( $sections_footer ) ){
			add_filter( 'blocksy:builder:footer:enabled', function(){ return false; } );
            add_action( 'blocksy:footer:before', 'nexter_ext_render_footer' );
        }

        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        if( !empty( $sections_hook_404 ) && is_404() ){
			add_action('template_redirect', function () {
				if (is_404()) {
					include NEXTER_EXT_DIR . 'include/classes/load-pages/template/template.php';
					exit;
				}
			});
            add_action( 'nexter_pages_hooks_template', [ $this, 'nexter_ext_render_404_page' ] );
        }

		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		if(!empty($section_breadcrumb_ids)){
			add_action( 'blocksy:content:before', array( $this, 'display_breadcrumb_section' ) );
		}
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

Nexter_Blocksy_Compat::instance();