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
			add_action( 'init', [ $this, 'theme_after_setup' ] );
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
			
			// Check if code snippets are enabled before including related files
			$get_opt = get_option('nexter_extra_ext_options');
			$code_snippets_enabled = true;

			if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
				$code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
			}
			
			if ( $code_snippets_enabled ) {
				if ( get_option( 'nexter_snippets_imported' ) === false ) {
					require_once $include_uri . 'load-code-snippet/nexter-import-code-snippets.php';
				}
				require_once $include_uri . 'load-code-snippet/nexter-php-code-handling.php';
				require_once $include_uri . 'load-code-snippet/nexter-code-snippet-render.php';
			}
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
			$wp_admin_bar->add_node( [
				'id'	=> 'nxt_edit_snippets',
				'meta'	=> array(
					'class' => 'nxt_edit_snippets',
				),
				'title' => esc_html__( 'Snippets List', 'nexter-extension' ),
				
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
			$post_ids = $current_post_id ? [ $current_post_id ] : [];
			if(has_filter('nexter_template_load_ids')) {
				$post_ids = apply_filters('nexter_template_load_ids', $post_ids);
			}

			$snippets_ids = [];
			$get_opt = get_option('nexter_extra_ext_options');
			$adminbar_enabled = false;
			
			// Check if code snippets are enabled
			$code_snippets_enabled = true;

			if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
				$code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
			}
			
			if($code_snippets_enabled && !empty($get_opt) && !empty($get_opt['code-snippets']) && !empty($get_opt['code-snippets']['values'])){
				$cs_options = $get_opt['code-snippets']['values'];
				$adminbar_enabled = !empty($cs_options['adminbar']);
				if(has_filter('nexter_loaded_snippet_ids') && $adminbar_enabled){
					$raw_snippets_ids = apply_filters('nexter_loaded_snippet_ids', $snippets_ids);
					
					// Filter snippets based on current page context
					$snippets_ids = $this->filter_snippets_by_page_context($raw_snippets_ids);
				}
			}

			// Only process Pro snippets if code snippets are enabled
			if($code_snippets_enabled && defined('NXT_PRO_EXT') && class_exists('Nexter_Builder_Code_Snippets_Render_Pro')) {
				if (!empty(Nexter_Builder_Code_Snippets_Render_Pro::$snippet_loaded_ids_pro)) {
					$pro_snippets = $this->filter_snippets_by_page_context(Nexter_Builder_Code_Snippets_Render_Pro::$snippet_loaded_ids_pro);
					$snippets_ids = $this->nxt_deep_merge_snippet_ids($snippets_ids, $pro_snippets);
				}
			}
			
			/*The Plus Template Blocks load*/
			if(class_exists('Tpgb_Library')){
				$tpgb_libraby = Tpgb_Library::get_instance();
				if(isset($tpgb_libraby->plus_template_blocks)){
					$post_ids = array_unique(array_merge($post_ids, $tpgb_libraby->plus_template_blocks));
				}
			}
			
			// Don't return early if post_ids is empty - we still want to show snippets on homepage
			
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

			$snippets_lists = array(
				'css' => [],
				'javascript'  => [],
				'php' => [],
				'htmlmixed'=> [],
			);
			
			// Use snippets_ids as-is - Pro snippets are now integrated via the nexter_loaded_snippet_ids filter
			$all_snippets_ids = $snippets_ids;
			foreach (['css', 'javascript', 'php', 'htmlmixed'] as $type) {
				if (!empty($all_snippets_ids[$type])) {
					foreach ($all_snippets_ids[$type] as $post_id) {
						if (is_numeric($post_id) && !isset($snippets_lists[$type][$post_id])) {
							$post = get_post($post_id);

							if ($post) {
								$snippets_lists[$type][$post_id] = [
									'id' => $post_id,
									'title' => $post->post_title,
									'edit_url' => admin_url('admin.php?page=nxt_code_snippets#/edit/' . $post_id),
								];
							}
						}else if(!empty($post_id) && is_string($post_id) && !isset($snippets_lists[$type][$post_id])) {
							if(class_exists('Nexter_Code_Snippets_File_Based')){
								$file_based = new Nexter_Code_Snippets_File_Based();
								$snippet_data = $file_based->getSnippetData($post_id);
								if(!empty($snippet_data)){
									$snippets_lists[$type][$post_id] = [
										'id' => $post_id,
										'title' => isset($snippet_data['name']) ? $snippet_data['name'] : $post_id,
										'edit_url' => admin_url('admin.php?page=nxt_code_snippets#/edit/' . $post_id),
									];
								}
							}
						}
					}
				}
			}
			$template_list1 = array_column($template_list, 'post_type');
			array_multisort($template_list1, SORT_DESC, $template_list);
			$nxt_template = [
				'nxt_edit_template' => $template_list,
			];
			
			// Only add snippets to admin bar if Admin Bar Info toggle is enabled
			if($adminbar_enabled){
				$nxt_template['nxt_edit_snippet'] = $snippets_lists;
			}
			$scripts = 'var NexterAdminBar = '. wp_json_encode($nxt_template);

			wp_add_inline_script( 'nxt-admin-bar', $scripts, 'before' );
		}

		/**
		 * Filter snippets based on current page context
		 * This ensures snippets only appear in admin bar when they would actually execute
		 */
		private function filter_snippets_by_page_context($snippets_ids) {
			if (empty($snippets_ids)) {
				return $snippets_ids;
			}

			$filtered_snippets = [];
			
			foreach (['css', 'javascript', 'php', 'htmlmixed'] as $type) {
				$filtered_snippets[$type] = [];
				
				if (!empty($snippets_ids[$type])) {
					foreach ($snippets_ids[$type] as $snippet_id) {
						if ($this->should_snippet_show_in_admin_bar($snippet_id)) {
							$filtered_snippets[$type][] = $snippet_id;
						}
					}
				}
			}
			
			return $filtered_snippets;
		}

		/**
		 * Check if a snippet should show in admin bar for current page context
		 */
		private function should_snippet_show_in_admin_bar($snippet_id) {
			$location = get_post_meta($snippet_id, 'nxt-code-location', true);
			
			if (empty($location)) {
				return true; // Old system snippets - always show
			}
			
			// Check basic snippet status first
			$is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
			if ($is_active != '1') {
				return false; // Snippet is inactive
			}
			
			// Check schedule restrictions
			if (defined('NXT_PRO_EXT') && function_exists('apply_filters')) {
				$should_skip_schedule = apply_filters('nexter_check_pro_schedule_restrictions', false, $snippet_id);
				if ($should_skip_schedule) {
					return false; // Skip due to schedule restrictions
				}
			}
			
			// Check Smart Conditional Logic
			$smart_conditions = get_post_meta($snippet_id, 'nxt-smart-conditional-logic', true);
			if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
				if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
					return false; // Skip this snippet, Smart Conditional Logic not met
				}
			}
			
			// Check if this is a Pro location - validate with page context
			if (defined('NXT_PRO_EXT')) {
				$pro_locations = [
					// CSS Selector locations
					'before_html_element',
					'after_html_element', 
					'start_html_element',
					'end_html_element',
					'replace_html_element',
					// Content-based locations
					'insert_after_words',
					'insert_every_words',
					'insert_middle_content',
					'insert_after_25',
					'insert_after_75',
					'insert_after_33',
					'insert_after_66',
					'insert_after_80'
				];
				
				if (in_array($location, $pro_locations)) {
					return $this->validate_pro_location_context($location);
				}
			}
			
			// Use specialized handlers to determine location types
			// Global locations should always show
			if (Nexter_Global_Code_Handler::is_global_location($location)) {
				return true;
			}
			
			// eCommerce locations - validate page context
			if (Nexter_ECommerce_Code_Handler::is_ecommerce_location($location)) {
				return $this->validate_ecommerce_location_context($location);
			}
			
			// Page-specific locations - check page context
			if (Nexter_Page_Specific_Code_Handler::is_page_specific_location($location)) {
				return $this->validate_page_specific_location_context($location);
			}
			
			return true; // Default - show if we can't determine the type
		}

		/**
		 * Validate Pro location against current page context
		 */
		private function validate_pro_location_context($location) {
			// Content-based locations should only show on singular pages
			$content_based_locations = [
				'insert_after_words',
				'insert_every_words',
				'insert_middle_content',
				'insert_after_25',
				'insert_after_33',
				'insert_after_66',
				'insert_after_75',
				'insert_after_80'
			];
			
			if (in_array($location, $content_based_locations)) {
				return is_singular();
			}
			
			// CSS Selector locations can show anywhere
			return true;
		}

		/**
		 * Validate eCommerce location against current page context
		 */
		private function validate_ecommerce_location_context($location) {
			// Check if the required plugin is active
			if (Nexter_ECommerce_Code_Handler::is_woocommerce_location($location)) {
				if (!Nexter_ECommerce_Code_Handler::is_woocommerce_active()) {
					return false; // WooCommerce not active
				}
				return $this->validate_woocommerce_location_context($location);
			}
			
			if (Nexter_ECommerce_Code_Handler::is_edd_location($location)) {
				if (!Nexter_ECommerce_Code_Handler::is_edd_active()) {
					return false; // EDD not active
				}
				return $this->validate_edd_location_context($location);
			}
			
			if (Nexter_ECommerce_Code_Handler::is_memberpress_location($location)) {
				if (!Nexter_ECommerce_Code_Handler::is_memberpress_active()) {
					return false; // MemberPress not active
				}
				return $this->validate_memberpress_location_context($location);
			}
			
			return true;
		}

		/**
		 * Validate WooCommerce location against current page context
		 */
		private function validate_woocommerce_location_context($location) {
			// Shop/product listing locations
			if (strpos($location, 'shop') !== false || strpos($location, 'list_products') !== false) {
				return (function_exists('is_shop') && is_shop()) || 
				       (function_exists('is_product_category') && is_product_category()) || 
				       (function_exists('is_product_tag') && is_product_tag());
			}
			
			// Single product locations
			if (strpos($location, 'single_product') !== false) {
				return function_exists('is_product') && is_product();
			}
			
			// Cart locations
			if (strpos($location, 'cart') !== false) {
				return function_exists('is_cart') && is_cart();
			}
			
			// Checkout locations
			if (strpos($location, 'checkout') !== false) {
				return function_exists('is_checkout') && is_checkout();
			}
			
			// Thank you page locations
			if (strpos($location, 'thank_you') !== false) {
				return function_exists('is_order_received_page') && is_order_received_page();
			}
			
			return true; // Default for unknown WooCommerce locations
		}

		/**
		 * Validate EDD location against current page context
		 */
		private function validate_edd_location_context($location) {
			// Download locations
			if (strpos($location, 'download') !== false) {
				return function_exists('is_singular') && is_singular('download');
			}
			
			// Cart/checkout locations
			if (strpos($location, 'cart') !== false || strpos($location, 'checkout') !== false) {
				return function_exists('edd_is_checkout') && edd_is_checkout();
			}
			
			return true; // Default for unknown EDD locations
		}

		/**
		 * Validate MemberPress location against current page context
		 */
		private function validate_memberpress_location_context($location) {
			// Checkout locations
			if (strpos($location, 'checkout') !== false) {
				return function_exists('is_page') && function_exists('get_query_var') && 
				       is_page() && get_query_var('action') === 'checkout';
			}
			
			// Account locations
			if (strpos($location, 'account') !== false) {
				return function_exists('is_page') && function_exists('get_query_var') && 
				       is_page() && get_query_var('action') === 'account';
			}
			
			// Login locations
			if (strpos($location, 'login') !== false) {
				return function_exists('is_page') && function_exists('get_query_var') && 
				       is_page() && get_query_var('action') === 'login';
			}
			
			// Unauthorized message locations - always load as they're applied via filter
			if (strpos($location, 'unauthorized') !== false) {
				return true;
			}
			
			return true; // Default for unknown MemberPress locations
		}

		/**
		 * Validate page-specific location against current page context
		 */
		private function validate_page_specific_location_context($location) {
			// Define archive-only locations
			$archive_only_locations = [
				'insert_before_excerpt',
				'insert_after_excerpt',
				'between_posts',
				'before_post',
				'after_post'
			];
			
			// Define singular-only locations (including advanced content insertions)
			$singular_only_locations = array_merge([
				'insert_before_content',
				'insert_after_content',
				'insert_before_paragraph',
				'insert_after_paragraph',
				'insert_before_post',
				'insert_after_post'
			], Nexter_Page_Specific_Code_Handler::get_advanced_content_locations());
			
			// Check page context
			if (in_array($location, $archive_only_locations)) {
				return !is_singular(); // Show only on archive pages
			}
			
			if (in_array($location, $singular_only_locations)) {
				return is_singular(); // Show only on singular pages
			}
			
			return true; // Default for other page-specific locations
		}

		public function nxt_deep_merge_snippet_ids($base, $append) {
			$merged = [];

			$keys = array_unique(array_merge(array_keys($base), array_keys($append)));

			foreach ($keys as $key) {
				$base_items = $base[$key] ?? [];
				$append_items = $append[$key] ?? [];

				$merged[$key] = array_values(array_unique(array_merge($base_items, $append_items)));
			}

			return $merged;
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