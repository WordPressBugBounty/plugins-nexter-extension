<?php
/**
 * Kadence Theme Compatibility of Theme Builder
 * 
 * @package Nexter Extensions
 * @since 4.0.4
 */
class Nexter_Kadence_Compat {

	/**
	 * Instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Nexter_Kadence_Compat();

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
            add_action( 'template_redirect', [ $this, 'kadence_nxt_ext_header' ], 10 );
            add_action( 'kadence_header', 'nexter_ext_render_header' );
        }

        $sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		if( !empty( $sections_footer ) ){
            add_action( 'template_redirect', [ $this, 'kadence_nxt_ext_footer' ], 10 );
            add_action( 'kadence_footer', 'nexter_ext_render_footer' );
        }

        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        if( !empty( $sections_hook_404 ) && is_404() && function_exists('Kadence\get_404_content')){
			remove_action( 'kadence_404_content', 'Kadence\get_404_content' );
            add_action( 'kadence_404_content', [ $this, 'nexter_ext_render_404_page' ] );
        }

		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		if(!empty($section_breadcrumb_ids)){
			add_action( 'kadence_before_content', array( $this, 'display_breadcrumb_section' ) );
		}
	}

	/**
	 * Disable header.
	 */
	public function kadence_nxt_ext_header() {
		if (function_exists('Kadence\header_markup')) {
			remove_action('kadence_header', 'Kadence\header_markup');
		}
	}

	/**
	 * Disable footer.
	 */
	public function kadence_nxt_ext_footer() {
		if(function_exists('Kadence\footer_markup')){
			remove_action( 'kadence_footer', 'Kadence\footer_markup' );
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

Nexter_Kadence_Compat::instance();