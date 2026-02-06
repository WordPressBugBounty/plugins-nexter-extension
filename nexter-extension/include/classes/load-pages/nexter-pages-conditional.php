<?php
/**
 * Nexter Builder Pages Conditional
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Builder_Pages_Conditional' ) ) {

	class Nexter_Builder_Pages_Conditional {


		/**
		 * Member Variable
		 */
		 private static $instance;
		
		/**
		 * Get Locations Singluar/Archives
		 */
		public static $location = [];
		
		/**
		 * Load Documents IDs
		 */
		public static $templates_ids = [];

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
			add_action( 'wp', [ $this, 'nexter_load_templates_ids' ], 1 ); //Load Documents IDs
			add_action( 'wp', [ $this, 'nexter_builder_template' ], 1 ); //Load Documents IDs
			add_filter( 'nexter_pages_hooks_template', [ $this, 'nexter_pages_hooks_template_content' ] );
			add_filter( 'template_include', [ $this, 'load_template_include' ], 15 ); 
			add_action( 'wp_enqueue_scripts', array( $this, 'load_pages_enqueue_styles' ) );
			add_filter( 'single_template', array( $this, 'load_nxt_builder_template' ) );
			add_filter('rank_math/frontend/robots', function ($robots) {
				if ( is_singular( NXT_BUILD_POST ) ) {
					$robots['index']  = 'noindex';
					$robots['follow'] = 'nofollow';
				}
		
				return $robots;
			});
		}
		
		public function nexter_load_templates_ids(){
		
			$singular_archives = '';
			if ( function_exists( 'is_shop' ) && is_shop() ) {
				$singular_archives = 'archives';
			} elseif ( is_archive() || is_tax() || is_home() || is_search() ) {
				$singular_archives = 'archives';
			} elseif ( is_singular() || is_404() ) {
				$singular_archives = 'singular';
			}
			self::$location = $singular_archives;
			$pages_loader = new Nexter_Builder_Pages_Loader();
			
			self::$templates_ids = $pages_loader->get_templates_ids_for_location($singular_archives);
		}
		
		/* Template Build Load Content
		 *
		 */
		public function nexter_pages_hooks_template_content(){
			if(!empty(self::$templates_ids)){
				$i=0;
				foreach( self::$templates_ids as $id => $priority ){
					if($i==0){
						if( self::$location == 'singular' ){
							echo '<div class="nxt-content-page-template ' . esc_attr(implode(' ', get_post_class('', get_the_ID()))) . '" data-post-id="' . esc_attr(get_the_ID()) . '">';
						}
						
						nexter_content_load($id);
						
						if( self::$location == 'singular' ){
							echo '</div>';
						}
					}
					$i++;
				}
			}else{
				$this->get_the_hook_content();
			}
		}

		public function nexter_builder_template(){
			if ( is_singular( NXT_BUILD_POST ) ) {
				$post_id  = get_the_id();
				$nxt_hooks_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
				$nxt_hooks_section = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
				if ( 'sections' === $nxt_hooks_layout ||  !empty($nxt_hooks_section)){
					if( 'header' === $nxt_hooks_section ){
						remove_action( 'nexter_header', 'nexter_header_template' );
						remove_filter( 'nexter_pages_hooks_template', array( $this, 'nexter_pages_hooks_template_content' ) );
						//add_action( 'nexter_pages_hooks_template', 'the_content' );
						add_action(
							'nexter_header',
							function() use ( $post_id ) {
								echo '<header itemscope="itemscope" id="nxt-header" class="site-header" role="banner">';
									$this->get_the_hook_content();
								echo '</header>';
							},
							10
						);
					}else if( 'footer' === $nxt_hooks_section ){
						remove_action( 'nexter_footer', 'nexter_footer_template' );
						remove_filter( 'nexter_pages_hooks_template', array( $this, 'nexter_pages_hooks_template_content' ) );
						add_action(
							'nexter_footer',
							function() use ( $post_id ) {
								echo '<footer class="site-footer">';
									$this->get_the_hook_content();
								echo '</footer>';
							},
							10
						);
					}
				} 
			}
		}
		
		public function get_template_path() {
			$template_path = NEXTER_EXT_DIR . 'include/classes/load-pages/template/template.php';
			return $template_path;
		}
		
		/**
		 * Custom template for Advanced Hook post type.
		 *
		 * @param  string $template Single Post template path.
		 * @return string
		 */
		public function load_nxt_builder_template( $template ) {
			global $post;

			$post_id = get_the_id();
			// $nxt_hooks_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
			$nxt_hooks_section = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
			
			if ( NXT_BUILD_POST == $post->post_type ) {
				
				//if ( ( 'header' === $nxt_hooks_section || 'footer' === $nxt_hooks_section) ) {
					//Nexter 
					$template = $this->get_template_path();

					//All Theme Elementor 
					if(!defined('NXT_VERSION') && defined('ELEMENTOR_PATH')){
						$ele_2_0_canvas = ELEMENTOR_PATH . '/modules/page-templates/templates/canvas.php';
		
						if ( file_exists( $ele_2_0_canvas ) ) {
							return $ele_2_0_canvas;
						} else {
							return ELEMENTOR_PATH . '/includes/page-templates/canvas.php';
						}
					}
				//}

				
			}
			
			return $template;
		}

		/**
		 * Get the content of the hook
		 */
		public function get_the_hook_content() {
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
		}

		/**
		 * Load Pages Enqueue Styles
		 */
		public function load_pages_enqueue_styles() {
			if( !empty(self::$templates_ids) ){
				foreach ( self::$templates_ids as $post_id => $post_data ) {

					$nxt_hooks_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
					$nxt_hooks_section = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
					if ( ((!empty($nxt_hooks_layout) && $nxt_hooks_layout!='none') || !empty($nxt_hooks_section)) && class_exists( 'Nexter_Builder_Compatibility' ) ) {
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
		
		/* Check Document Ids and Include Template
		 *
		 */
		public function load_template_include( $template ) {
			$sec_ids = [];
			if(class_exists('Nexter_Builder_Sections_Conditional')){
				$section_ids = Nexter_Builder_Sections_Conditional::get_instance();
				$sec_ids = $section_ids::load_sections_id();
			}
			
			if(!empty($sec_ids)){
				$found = array_filter($sec_ids, fn($item) => in_array('page-404', [$item['location']]));
			}else{
				$found = false;
			}
			
			if ( !defined('ASTRA_THEME_VERSION') && !defined('GENERATE_VERSION') && !defined('OCEANWP_THEME_VERSION') && !defined('KADENCE_VERSION') && !function_exists('blocksy_get_wp_theme') && !defined('NEVE_VERSION') && !defined('NXT_VERSION') && self::$location === 'singular' && is_404() && !empty($found)){
				return $this->get_template_path();
			}
			
			//is empty documents default
			if( empty(self::$templates_ids) ){
				return $template; //default template
			}
			
			if ( self::$location === 'singular' || self::$location === 'archives' ) {
				if(!empty(self::$templates_ids)){
					//Astra theme
					if(defined('ASTRA_THEME_VERSION')){
						remove_action( 'astra_template_parts_content', array( \Astra_Loop::get_instance(), 'template_parts_post' ) );
					}
					
					$template = $this->get_template_path();
				}
			}
			
			return $template;
		}
		
		/*
		 * Nexter Section Hooks Load
		 */
		public static function nexter_get_pages_singular_archive( $nxt_layout='', $sections_pages='' ) {
			if($sections_pages == 'singular'){
				$option = array(
					'singular_group' => 'nxt-singular-group',
					array(
						'inc_exc'  => 'nxt-singular-include-exclude',
						'singular_rules' => 'nxt-singular-conditional-rule',
						'singular_type'	=> 'nxt-singular-conditional-type',
					),
				);
				$result = Nexter_Builder_Display_Conditional_Rules::get_instance()->get_templates_by_singular_conditions( NXT_BUILD_POST, $option );
			}
			
			if($sections_pages == 'archives'){
				$option = array(
					'archive_group' => 'nxt-archive-group',
					array(
						'inc_exc'  => 'nxt-archive-include-exclude',
						'archive_rules' => 'nxt-archive-conditional-rule',
						'archive_type'	=> 'nxt-archive-conditional-type',
					),
				);
				$result = Nexter_Builder_Display_Conditional_Rules::get_instance()->get_templates_by_archives_conditions( NXT_BUILD_POST, $option );
			}
			$get_result=array();
			global $pagenow;
			if( !empty($result) ) {
				foreach ( $result as $post_id => $post_data ) {
					$post_type = get_post_type();
					if ( ($pagenow=='edit.php' && $post_type === NXT_BUILD_POST) || $post_type != NXT_BUILD_POST ) {
						$nxt_hooks_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
						$pages = get_post_meta( $post_id, 'nxt-hooks-layout-pages', false );
						$sections = get_post_meta( $post_id, 'nxt-hooks-layout-sections', false );						
						if( ( (!empty( $nxt_layout ) && $nxt_hooks_layout == $nxt_layout ) && ( !empty( $sections_pages ) && !empty($pages) && isset($pages[0]) && $pages[0] == $sections_pages ) ) || ( !empty($sections) && !empty( $sections_pages )  && isset($sections[0]) && $sections[0] == $sections_pages )){
							if(function_exists('pll_get_post')){
								if(pll_get_post( $post_id ) != $post_id){
									continue;
								}
							}
							$get_result[$post_id] = $post_data;
						}
					}
				}
			}
			
			return $get_result;
		}
		
		/*Get Query args Singular/Archive Data*/
		public static function get_query_singular_archive_data( $data ) {
			
			if ( empty( $data['rules'] ) || empty( $data['object'] ) ) {
				return new \WP_Error( 'empty_data', 'Empty data' );
			}

			$cond_rule = $data;
			
			if ( in_array( $cond_rule['object'], ['post', 'tax', 'author', 'user', 'attachment'], true ) ) {
				$function_name = 'nexter_query_for_' . $cond_rule['object'];
				
				$query = self::$function_name( $data );
				if ( is_wp_error( $query ) ) {
					return $query;
				}
				$cond_rule['query'] = $query;
			}else{
				$cond_rule['query'] = '';
			}

			return $cond_rule;
		}
		
		//Taxonomy Query
		private static function nexter_query_for_tax( $data ) {
			$field_name = empty( $data['get_titles']['field_name'] ) ? 'term_taxonomy_id' : $data['get_titles']['field_name'];			
			return [
				$field_name => '',
				'hide_empty' => false,
			];
		}
		
		//Posts Query
		private static function nexter_query_for_post( $data ) {
			if ( ! isset( $data['query'] ) ) {
				return new \WP_Error( 'empty_data', 'Missing data' );
			}

			$query = $data['query'];
			if ( empty( $query['post_type'] ) ) {
				$query['post_type'] = 'any';
			}
			$query['posts_per_page'] = -1;	// phpcs:ignore WPThemeReview.CoreFunctionality.PostsPerPage.posts_per_page_posts_per_page

			return $query;
		}
		
		//Attachment Query
		private static function nexter_query_for_attachment( $data ) {
			$query = self::nexter_query_for_post( $data );
			if ( is_wp_error( $query ) ) {
				return $query;
			}
			$query['post_type'] = 'attachment';
			$query['post_status'] = 'inherit';

			return $query;
		}
		
		//Author Query
		private static function nexter_query_for_author( $data ) {
			$query = self::nexter_query_for_user( $data );
			if ( is_wp_error( $query ) ) {
				return $query;
			}
			global $GLOBALS;
			// Capability queries were only introduced in WP 5.9.
			if ( version_compare( $GLOBALS['wp_version'], '5.9-alpha', '<' ) ) {
				$query['who'] = 'authors';
				unset( $query['capability'] );
			}
			$query['has_published_posts'] = true;
			
			return $query;
		}
		
		//User Query
		private static function nexter_query_for_user( $data ) {
			$query = $data['query'];
			if ( ! empty( $query ) ) {
				return $query;
			}

			$query = [
				'fields' => [
					'ID',
					'display_name',
				],				
				'search_columns' => [
					'user_login',
					'user_nicename',
				],
			];
			
			return $query;
		}
		
	}
}

Nexter_Builder_Pages_Conditional::get_instance();