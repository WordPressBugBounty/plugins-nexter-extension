<?php
/**
 * Nexter Builder Sections Conditional
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Builder_Sections_Conditional' ) ) {

	class Nexter_Builder_Sections_Conditional {

		/**
		 * Member Variable
		 */
		private static $instance;
		
		/**
		 * Conditional Sections
		 */
		 public static $sections_ids =array();

		 public static $section_get_type = [];
		
		/**
		 *  Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 *  Constructor
		 */
		public function __construct() {
			if(!is_admin()){
				add_action( 'wp', array( $this, 'get_sections_ids' ), 1 );
				if(!defined('ASTRA_THEME_VERSION') && !defined('GENERATE_VERSION') && !defined('OCEANWP_THEME_VERSION') && !defined('KADENCE_VERSION') && !function_exists('blocksy_get_wp_theme') && !defined('NEVE_VERSION') && !defined('NXT_VERSION')){
					add_action( 'wp', array( $this, 'theme_hooks' ) );
				}
			}

			add_action( 'template_redirect', array( $this, 'nexter_builder_template_frontend' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'load_sections_enqueue_styles' ) );
		}
		
		public function get_sections_ids(){
			
			$options = [
				'location'  => 'nxt-add-display-rule',
				'exclusion' => 'nxt-exclude-display-rule',
			];

			self::$sections_ids = Nexter_Builder_Display_Conditional_Rules::get_instance()->get_templates_by_sections_conditions( NXT_BUILD_POST, $options );
			
		}
		
		public static function load_sections_id(){
			if(isset(self::$sections_ids) && !empty(self::$sections_ids)){
				return self::$sections_ids;
			}
		}

		/**
		 * Load Hooks Enqueue Styles
		 */
		public function load_sections_enqueue_styles() {
			if(!empty(self::$sections_ids)){
				foreach ( self::$sections_ids as $post_id => $post_data ) {
					$nxt_hooks_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
					$hook_layout_sections = get_post_meta(  $post_id, 'nxt-hooks-layout-sections', true );
					$pages = [];
					if(!empty($nxt_hooks_layout) && $nxt_hooks_layout=='pages'){
						$pages = get_post_meta( $post_id, 'nxt-hooks-layout-pages', false );
						if(!empty($pages) && in_array('page-404',$pages) && !is_404()){
							continue;
						}
					}else if($hook_layout_sections=='page-404' && !is_404()){
						continue;
					}
					if ( ((!empty($nxt_hooks_layout) && $nxt_hooks_layout!='none') || !empty($hook_layout_sections)) && class_exists( 'Nexter_Builder_Compatibility' ) ) {
						$page_base_instance = Nexter_Builder_Compatibility::get_instance();
						$post_id = apply_filters( 'wpml_object_id', $post_id, NXT_BUILD_POST, TRUE  );
						$page_builder_instance = $page_base_instance->get_active_page_builder( $post_id );

						if ( is_callable( array( $page_builder_instance, 'enqueue_scripts' ) ) ) {
							$page_builder_instance->enqueue_scripts( $post_id );
						}
					}
				}
			}
		}

		/**
		 * Don't display the templates on the frontend for non edit_posts
		 */
		public function nexter_builder_template_frontend() {
			if ( is_singular( NXT_BUILD_POST ) && ! current_user_can( 'edit_posts' ) ) {
				wp_redirect( home_url(), 301 );
				die;
			}
		}
		
		/**
		 * Overriding the header in the theme
		 *
		 * @since 3.2.0
		 */
		public function theme_hooks(){
			$header_id = self::nexter_sections_condition_hooks( 'sections', 'header' );
			$breadcrumb_ids = self::nexter_sections_condition_hooks( 'sections', 'breadcrumb' );
			
			if(!empty($header_id) || !empty($breadcrumb_ids)){
				
				// Replace header.php template.
				add_action( 'get_header', [ $this, 'header_template_override' ], 9 );
				if(!empty($header_id)){
					// Display header template.
					add_action( 'nexter_header', 'nexter_ext_render_header' );
				}
				if(!empty($breadcrumb_ids)){
					// Display Breadcrumb
					add_action( 'nexter_breadcrumb', 'nexter_ext_render_breadcrumb' );
				}
			}

			$footer_id = self::nexter_sections_condition_hooks( 'sections', 'footer' );
			if(!empty($footer_id)){
				// Replace footer.php template.
				add_action( 'get_footer', [ $this, 'footer_template_override' ] );
				//Display Footer template
				add_action( 'nexter_footer', 'nexter_ext_render_footer' );
			}
		}

		/**
		 * Overriding the header in the theme
		 *
		 * @since 3.2.0
		 */
		public function header_template_override() {
			require NEXTER_EXT_DIR . 'include/classes/load-pages/template/nxt-header.php';
			$templates   = [];
			$templates[] = 'header.php';
			// Avoid running wp_head hooks again.
			remove_all_actions( 'wp_head' );
			ob_start();
			locate_template( $templates, true );
			ob_get_clean();
		}

		/**
		 * Overriding the footer in the theme
		 *
		 * @since 3.2.0
		 */
		public function footer_template_override() {
			require NEXTER_EXT_DIR . 'include/classes/load-pages/template/nxt-footer.php';
			$templates   = [];
			$templates[] = 'footer.php';
			// Avoid running wp_footer hooks again.
			remove_all_actions( 'wp_footer' );
			ob_start();
			locate_template( $templates, true );
			ob_get_clean();
		}
		
		/*
		 * Load Sections Condition Hooks
		 * @since 1.0.4
		 */
		public static function nexter_sections_condition_hooks($nxt_layout='', $sections_pages='' ) {
			
			if(!empty($sections_pages) && isset(self::$section_get_type[$sections_pages])){
				return self::$section_get_type[$sections_pages];
			}
			
			$get_result=array();
			if( !empty(self::$sections_ids) ) {
				foreach ( self::$sections_ids as $post_id => $post_data ) {
					$post_type = get_post_type();					
					if ( NXT_BUILD_POST != $post_type ) {
						$nxt_hooks_layout   = get_post_meta( $post_id, 'nxt-hooks-layout', true );
						$sections   = get_post_meta( $post_id, 'nxt-hooks-layout-sections', false );

						if( (!empty( $nxt_layout ) && !empty($nxt_hooks_layout) && $nxt_hooks_layout == $nxt_layout && !empty( $sections_pages )) || !empty($sections)){
							if(('sections' === $nxt_hooks_layout) || (!empty($sections) && empty($nxt_hooks_layout) && $nxt_hooks_layout != 'page' )){
								if(!empty($sections) && $sections[0] == $sections_pages){
									$get_result[] = $post_id;
								}
							}else if('pages' === $nxt_hooks_layout){
								$pages = get_post_meta( $post_id, 'nxt-hooks-layout-pages', false );
								if(!empty($pages) && $pages[0] == $sections_pages){
									$get_result[] = $post_id;
								}
							}else if('code_snippet' === $nxt_hooks_layout){
								$codes_snippet   = get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', false );
								if(!empty($codes_snippet) && $codes_snippet[0] == $sections_pages){
									$get_result[] = $post_id;
								}
							}
						}
						
					}
				}
			}

			if(!empty($sections_pages) && !isset(self::$section_get_type[$sections_pages])){
				self::$section_get_type[$sections_pages] = $get_result;
			}
			
			return $get_result;
		}
		
		/**
		 * Nexter Builder Conditional get template content
		 */
		public function get_action_content( $post_id ) {
			if(function_exists('pll_get_post')){	
				$translated_post_id = pll_get_post($post_id, pll_current_language());
				if($post_id != $translated_post_id){
					return;
				}
			}
			$action = get_post_meta( $post_id, 'nxt-display-hooks-action', true );
			
			// Exclude div wrapper if selected hook is from below list.
			$exclude_hooks = array( 'nxt_html_before', 'nxt_body_top', 'nxt_head_top', 'wp_head', 'nxt_head_bottom',  'nxt_body_bottom', 'wp_footer' );
			$nxt_hook_wrapper	= ! in_array( $action, $exclude_hooks );
			if ( $nxt_hook_wrapper ) {
				echo '<div class="nxt-template-load nxt-load-hook-' . esc_attr($post_id) . '" data-id="' . esc_attr($post_id) . '">';
			}
			
			if ( function_exists('nexter_content_load') ) {
				nexter_content_load( $post_id );
			}
			
			if ( $nxt_hook_wrapper ) {
				echo '</div>';
			}
		}

	}
}

Nexter_Builder_Sections_Conditional::get_instance();