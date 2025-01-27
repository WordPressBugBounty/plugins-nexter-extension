<?php
/**
 * Neve Theme Compatibility of Theme Builder
 * 
 * @package Nexter Extensions
 * @since 4.0.4
 */
class Nexter_Neve_Compat {

	/**
	 * Instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Nexter_Neve_Compat();

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
            add_action( 'template_redirect', [ $this, 'neve_nxt_ext_header' ], 10 );
            add_action( 'neve_do_header', 'nexter_ext_render_header' );
        }

        $sections_footer = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'footer' );
		if( !empty( $sections_footer ) ){
            add_action( 'template_redirect', [ $this, 'neve_nxt_ext_footer' ], 10 );
            add_action( 'neve_do_footer', 'nexter_ext_render_footer' );
        }

        $sections_hook_404 = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'pages', 'page-404' );
        if( !empty( $sections_hook_404 ) && is_404() ){
			if ( class_exists( 'Neve\Views\Content_404' ) ) {
				
				if ( method_exists( 'Neve\Views\Content_404', 'render_404_page' ) ) {
					remove_all_actions( 'neve_do_404' );
				}
			}
            add_action( 'neve_do_404', [ $this, 'nexter_ext_render_404_page' ] );
        }

		$section_breadcrumb_ids = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
		if(!empty($section_breadcrumb_ids)){
			add_action( 'neve_before_primary', array( $this, 'display_breadcrumb_section' ) );
		}
	}

	/**
	 * Disable header.
	 */
	public function neve_nxt_ext_header() {
		remove_all_actions( 'neve_do_top_bar' );
		remove_all_actions( 'hfg_header_render' );
	}

	/**
	 * Disable footer.
	 */
	public function neve_nxt_ext_footer() {
		remove_all_actions( 'hfg_footer_render' );
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

Nexter_Neve_Compat::instance();