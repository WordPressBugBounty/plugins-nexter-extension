<?php 
/**
 * Nexter Extensions Sections/Pages Load Functionality
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
if ( ! class_exists( 'Nexter_Class_Load' ) ) {

	class Nexter_Class_Load {

		/**
		 * Member Variable
		 */
		private static $instance;
		
		/**
		 *  Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		public function __construct() {
			add_action( 'after_setup_theme', array( $this, 'theme_after_setup' ) );
			if(!is_admin()){
				add_action( 'admin_bar_menu', [ $this, 'add_edit_template_admin_bar' ], 300 );
				//admin bar enqueue scripts
				add_action( 'wp_footer', [ $this, 'admin_bar_enqueue_scripts' ] );
			}
			add_action('init', function() {
				if (has_action('wp_footer', 'wp_print_speculation_rules')) {
					remove_action('wp_footer', 'wp_print_speculation_rules');
				}
			});
		}
		
		/**
		 * After Theme Setup
		 * @since 1.0.4
		 */
		function theme_after_setup() {
			$include_uri = NEXTER_EXT_DIR . 'include/classes/';
			//pages load
			//if(defined('NXT_VERSION') || defined('HELLO_ELEMENTOR_VERSION') || defined('ASTRA_THEME_VERSION') || defined('GENERATE_VERSION') || defined('OCEANWP_THEME_VERSION') || defined('KADENCE_VERSION') || function_exists('blocksy_get_wp_theme') || defined('NEVE_VERSION')){
				
				require_once $include_uri . 'nexter-class-singular-archives.php';
			
				//sections load
				if(!is_admin()){
					if(defined('ASTRA_THEME_VERSION')){
						require_once $include_uri . 'load-sections/theme/nxt-astra-comp.php';	
					}else if(defined('GENERATE_VERSION')){
						require_once $include_uri . 'load-sections/theme/nxt-generatepress-comp.php';
					}else if(defined('OCEANWP_THEME_VERSION')){
						require_once $include_uri . 'load-sections/theme/nxt-oceanwp-comp.php';
					}else if(defined('KADENCE_VERSION')){
						require_once $include_uri . 'load-sections/theme/nxt-kadence-comp.php';
					}else if(function_exists('blocksy_get_wp_theme')){
						require_once $include_uri . 'load-sections/theme/nxt-blocksy-comp.php';
					}else if( defined('NEVE_VERSION') ){
						require_once $include_uri . 'load-sections/theme/nxt-neve-comp.php';
					}

					require_once $include_uri . 'load-sections/nexter-header-extra.php';
					require_once $include_uri . 'load-sections/nexter-breadcrumb-extra.php';
					require_once $include_uri . 'load-sections/nexter-footer-extra.php';
					require_once $include_uri . 'load-sections/nexter-404-page-extra.php';
				}else{
					require_once $include_uri . 'load-sections/nexter-sections-loader.php';
				}
				
			//}
			require_once $include_uri . 'load-sections/nexter-sections-conditional.php';
			
			if ( get_option( 'nexter_snippets_imported' ) === false ) {
				require_once $include_uri . 'load-code-snippet/nexter-import-code-snippets.php';
			}
			require_once $include_uri . 'load-code-snippet/nexter-code-snippet-render.php';
		}
		
		/*
		 * Add Admin Bar menu Load Templates
		 * @since 1.0.7
		 */
		public function add_edit_template_admin_bar(  \WP_Admin_Bar $wp_admin_bar ){
			global $wp_admin_bar;

			if ( ! is_super_admin()
				 || ! is_object( $wp_admin_bar ) 
				 || ! function_exists( 'is_admin_bar_showing' ) 
				 || ! is_admin_bar_showing() ) {
				return;
			}
			$wp_admin_bar->add_node( [
				'id'	=> 'nxt_edit_template',
				'meta'	=> array(
					'class' => 'nxt_edit_template',
				),
				'title' => esc_html__( 'Template List', 'nexter-extension' ),
				
			] );
		}
		
		/*
		 * Admin Bar Enqueue Scripts
		 * @since 1.0.8
		 */
		public function admin_bar_enqueue_scripts(){
			global $wp_admin_bar;
		
			if ( ! is_super_admin()
				 || ! is_object( $wp_admin_bar ) 
				 || ! function_exists( 'is_admin_bar_showing' ) 
				 || ! is_admin_bar_showing() ) {
				return;
			}
			$current_post_id = get_the_ID();
			$post_ids = [ $current_post_id ];
			if(has_filter('nexter_template_load_ids')) {
				$post_ids = apply_filters('nexter_template_load_ids', $post_ids);
			}

			/*The Plus Template Blocks load*/
			if(class_exists('Tpgb_Library')){
				$tpgb_libraby = Tpgb_Library::get_instance();
				if(isset($tpgb_libraby->plus_template_blocks)){
					$post_ids = array_unique(array_merge($post_ids, $tpgb_libraby->plus_template_blocks));
				}
			}
			
			if( empty( $post_ids ) ){
				return;
			}
			
			if( !empty($post_ids) ){
				$post_ids = $this->find_reusable_block($post_ids);
				if (($key = array_search($current_post_id, $post_ids)) !== false) {
					unset($post_ids[$key]);
				}
			}
			
			// Load js 'nxt-admin-bar' before 'admin-bar'
			wp_dequeue_script( 'admin-bar' );

			wp_enqueue_style(
				'nxt-admin-bar',
				NEXTER_EXT_URL."assets/css/main/nxt-admin-bar.css",
				['admin-bar'],
				NEXTER_EXT_VER
			);
			wp_enqueue_script(
				'nxt-admin-bar',
				NEXTER_EXT_URL."assets/js/main/nxt-admin-bar.min.js",
				[],
				NEXTER_EXT_VER,
				true
			);

			wp_enqueue_script( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
				'admin-bar',
				null,
				[ 'nxt-admin-bar' ],
				NEXTER_EXT_VER,
				true
			);
			
			$template_list = [];
			if(!empty($post_ids)){
				foreach($post_ids as $key => $post_id){
					if(!isset($template_list[$post_id])){
						$posts = get_post($post_id);
						if(isset($posts->post_title)){
							$template_list[$post_id]['id'] = $post_id;
							$template_list[$post_id]['title'] = $posts->post_title;
							$template_list[$post_id]['edit_url'] = esc_url( get_edit_post_link( $post_id ) );
						}
						if(isset($posts->post_type)){
							$template_list[$post_id]['post_type'] = $posts->post_type;
							$post_type_obj = get_post_type_object( $posts->post_type );
							$template_list[$post_id]['post_type_name'] = ($post_type_obj && isset($post_type_obj->labels) && isset($post_type_obj->labels->singular_name)) ? $post_type_obj->labels->singular_name : '';
							
							if($posts->post_type==='nxt_builder'){
								if ( get_post_meta( $post_id, 'nxt-hooks-layout', true ) ){
									$layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
									$type = '';
									if(!empty($layout) && $layout==='sections'){
										$type = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
									}else if(!empty($layout) && $layout==='pages'){
										$type = get_post_meta( $post_id, 'nxt-hooks-layout-pages', true );
									}else if(!empty($layout) && $layout==='code_snippet'){
										$type = get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', true );
									}else if(!empty($layout) && $layout==='none'){
										unset($template_list[$post_id]);
									}
									if(isset($template_list[$post_id])){
										$template_list[$post_id]['nexter_layout'] = $layout;
										$template_list[$post_id]['nexter_type'] = $type;
									}
								}else if(get_post_meta( $post_id, 'nxt-hooks-layout-sections', true )){
									$type = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
									if(isset($template_list[$post_id])){
										$template_list[$post_id]['nexter_type'] = $type;
									}
								}
							}
						}
					}
				}
			}
			
			$template_list1 = array_column($template_list, 'post_type');
			array_multisort($template_list1, SORT_DESC, $template_list);
			$nxt_template = ['nxt_edit_template' => $template_list ];
			$scripts = 'var NexterAdminBar = '. wp_json_encode($nxt_template);

			wp_add_inline_script( 'nxt-admin-bar', $scripts, 'before' );
		}
		
		/*
		 * Admin Bar List Reusable Block
		 * @since 1.0.7
		 */
		public function find_reusable_block( $post_ids ) {
			if ( !empty($post_ids) ) {
				foreach($post_ids as $key => $post_id){
					$post_content = get_post( $post_id );
					if ( isset( $post_content->post_content ) ) {
						$content = $post_content->post_content;
						if ( has_blocks( $content ) ) {
							$parse_blocks = parse_blocks( $content );
							$res_id = $this->block_reference_id( $parse_blocks );
							if ( is_array( $res_id ) && ! empty( $res_id )) {
								$post_ids = array_unique( array_merge($res_id, $post_ids) );
							}
						}
					}
				}
			}
			
			return $post_ids;
		}
		 
		/**
		 * Get Reference ID
		 * @since 1.0.7
		 */
		public function block_reference_id( $res_blocks ) {
			$ref_id = array();
			if ( ! empty( $res_blocks ) ) {
				foreach ( $res_blocks as $key => $block ) {
					if ( $block['blockName'] == 'core/block' ) {
						$ref_id[] = $block['attrs']['ref'];
					}
					if ( count( $block['innerBlocks'] ) > 0 ) {
						$ref_id = array_merge( $this->block_reference_id( $block['innerBlocks'] ), $ref_id );
					}
				}
			}
			return $ref_id;
		} 
	}
}

Nexter_Class_Load::get_instance();