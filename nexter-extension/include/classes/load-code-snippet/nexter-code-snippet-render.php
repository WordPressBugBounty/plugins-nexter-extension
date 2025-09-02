<?php
/**
 * Nexter Builder Code Snippets Management
 *
 * @package Nexter Extensions
 * @since 1.0.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include specialized handlers
require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/handlers/nexter-global-code-handler.php';
require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/handlers/nexter-page-specific-code-handler.php';
require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/handlers/nexter-ecommerce-code-handler.php';
require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/handlers/nexter-memberpress-hook-handler.php';
// require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/nexter-php-code-handling.php';

if ( ! class_exists( 'Nexter_Builder_Code_Snippets_Render' ) ) {

	class Nexter_Builder_Code_Snippets_Render {

		/**
		 * Member Variable
		 */
		private static $instance;

		private static $snippet_type = 'nxt-code-snippet';

		public static $snippet_ids = array();

		public static $snippet_loaded_ids = array(
			'css' => [],
			'javascript'  => [],
			'php' => [],
			'htmlmixed'=> [],
		);

		public static $snippet_output = array();


		public $nxt_shortcode_dynamic_attrs = array();

		/**
		 * Check if code snippets functionality is enabled
		 */
		private function is_code_snippets_enabled() {
			$get_opt = get_option('nexter_extra_ext_options');
			$code_snippets_enabled = true;

			if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
				$code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
			}

			return $code_snippets_enabled;
		}

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
			// Only proceed if code snippets are enabled
			if (!$this->is_code_snippets_enabled()) {
				return;
			}

			if(!is_admin()){
				add_action( 'wp', array( $this, 'get_snippet_ids' ), 1 );
			}
				
			// Separate actions for each code type
			add_action( 'wp', array( $this, 'nexter_code_html_hooks_actions' ), 2 );

			add_action('wp', array($this, 'nexter_register_snippet_ids_filter'), 3);

					// Enqueue CSS/JS for frontend
		if(!is_admin()){
			add_action( 'wp_enqueue_scripts', array( $this, 'nexter_code_snippets_css_js' ),2 );
		}
		
		// Enqueue CSS/JS for admin area (for admin_header and admin_footer locations)
		if(is_admin()){
			add_action( 'admin_enqueue_scripts', array( $this, 'nexter_code_snippets_css_js_admin' ),2 );
			add_action( 'admin_init', array( $this, 'nexter_code_html_hooks_actions_admin' ), 2 );
		}
			// Execute PHP snippets immediately like the old version for proper hook timing
			if((!isset($_GET['test_code']) || empty($_GET['test_code']))){ // phpcs:ignore WordPress.Security.NonceVerification.Recommended, handled by the core method already.
				$this->nexter_code_php_snippets_actions();
			}

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ) );
			add_action('wp_ajax_create_code_snippets', array( $this, 'create_new_snippet') );
			add_action('wp_ajax_update_edit_code_snippets', array( $this, 'update_edit_snippet') );
			add_action('wp_ajax_fetch_code_snippet_list', array( $this, 'fetch_code_list') );
			add_action('wp_ajax_fetch_code_snippet_delete', array( $this, 'fetch_code_snippet_delete') );
			add_action('wp_ajax_fetch_code_snippet_export', array( $this, 'fetch_code_snippet_export') );
			add_action('wp_ajax_fetch_code_snippet_import', array( $this, 'fetch_code_snippet_import') );
			add_action('wp_ajax_fetch_code_snippet_status', array( $this, 'fetch_code_snippet_status') );
			add_action('wp_ajax_get_edit_snippet_data', array( $this, 'get_edit_snippet_data') );
			add_action('wp_ajax_nexter_get_taxonomy_terms', array( $this, 'get_taxonomy_terms_ajax') );
			add_action('wp_ajax_nexter_get_authors', array( $this, 'get_authors_ajax') );
			add_action('wp_ajax_fetch_snippet_list_for_conditions', array( $this, 'fetch_snippet_list_for_conditions') );
			add_action( 'init', array( $this, 'home_page_code_execute' ) );
			
			// Initialize CSS Selector functionality
			add_action( 'wp', array( $this, 'init_css_selector_functionality' ), 4 );
		}

		/**
		 * Initialize snippet execution tracking for conditional logic
		 */
		public function init_snippet_tracking() {
			global $nexter_executed_snippets;
			if (!isset($nexter_executed_snippets)) {
				$nexter_executed_snippets = array();
			}
			
			// Hook into snippet execution to track when they run
			add_filter('nexter_php_snippets_executed', array($this, 'track_snippet_execution'), 10, 2);
			add_filter('nexter_html_snippets_executed', array($this, 'track_snippet_execution'), 10, 2);
			add_filter('nexter_css_selector_snippet_output', array($this, 'track_snippet_execution'), 10, 3);
			
			// Add debugging system for live site issues
			if (defined('WP_DEBUG') && WP_DEBUG) {
				add_action('wp_footer', array($this, 'debug_snippet_execution'), 999);
				add_action('admin_footer', array($this, 'debug_snippet_execution'), 999);
			}
		}

		/**
		 * Track snippet execution for conditional logic
		 */
		public function track_snippet_execution($output, $snippet_id, $type = '') {
			global $nexter_executed_snippets;
			if (!isset($nexter_executed_snippets)) {
				$nexter_executed_snippets = array();
			}
			$nexter_executed_snippets[] = $snippet_id;
			return $output;
		}

		/**
		 * Debug snippet execution for troubleshooting live site issues
		 */
		public function debug_snippet_execution() {
			global $nexter_executed_snippets;
			
			if (current_user_can('manage_options')) {
				echo "\n<!-- Nexter Extension Debug Info -->\n";
				echo "<!-- Executed Snippets: " . (is_array($nexter_executed_snippets) ? count($nexter_executed_snippets) : 0) . " -->\n";
				
				// Check if important hooks are available
				$hooks_to_check = array('wp_head', 'wp_footer', 'wp_body_open', 'the_content', 'the_excerpt', 'loop_start', 'loop_end');
				foreach ($hooks_to_check as $hook) {
					$has_hook = has_action($hook);
					echo "<!-- Hook '{$hook}': " . ($has_hook ? 'Available' : 'Missing') . " -->\n";
				}
				
				// Check current page type
				$page_type = '';
				if (is_front_page()) $page_type .= 'front_page ';
				if (is_home()) $page_type .= 'home ';
				if (is_single()) $page_type .= 'single ';
				if (is_page()) $page_type .= 'page ';
				if (is_archive()) $page_type .= 'archive ';
				if (is_category()) $page_type .= 'category ';
				if (is_tag()) $page_type .= 'tag ';
				if (is_search()) $page_type .= 'search ';
				if (is_404()) $page_type .= '404 ';
				
				echo "<!-- Page Type: " . trim($page_type) . " -->\n";
				echo "<!-- Theme: " . get_template() . " -->\n";
				echo "<!-- Active Plugins: " . count(get_option('active_plugins', array())) . " -->\n";
				echo "<!-- WordPress Version: " . get_bloginfo('version') . " -->\n";
				echo "<!-- PHP Version: " . PHP_VERSION . " -->\n";
				echo "<!-- End Nexter Debug Info -->\n\n";
			}
		}

		/**
		 * Log snippet execution issues for troubleshooting
		 */
		public function log_snippet_issue($snippet_id, $issue_type, $location, $details = '') {
			if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				$log_message = sprintf(
					'[Nexter Extension] Snippet ID: %d, Issue: %s, Location: %s, Details: %s',
					$snippet_id,
					$issue_type,
					$location,
					$details
				);
				error_log($log_message);
			}
		}

		/**
		 * Initialize custom hook triggers for theme compatibility
		 * This allows themes to optionally support the nexter custom hooks
		 */
		public function init_custom_hook_triggers() {
			// Only add these if not in admin and hooks don't already exist
			if (!is_admin()) {
				// Add theme hook detection and fallback system
				add_action('wp', array($this, 'register_theme_hook_fallbacks'), 1);
				
				// Add FSE theme compatibility detection
				add_action('wp', array($this, 'detect_fse_theme_compatibility'), 2);
				
				// Trigger nexter_before_post hook during loop
				add_action('loop_start', function() {
					do_action('nexter_before_post');
				}, 5);
				
				// Note: nexter_after_post hook removed since after_post location is deprecated
				// Use before_x_post and after_x_post locations instead
				
				// Enhanced content hooks with better reliability
				add_filter('the_content', function($content) {
					// Only process on singular pages in main query
					if (is_singular() && in_the_loop() && is_main_query()) {
					ob_start();
					do_action('nexter_before_content');
					$before = ob_get_clean();
					
					ob_start();
					do_action('nexter_after_content');
					$after = ob_get_clean();
					
					return $before . $content . $after;
					}
					return $content;
				}, 5);
				
				// Enhanced excerpt hooks with archive detection
				add_filter('the_excerpt', function($excerpt) {
					// Only process on archive pages
					if (is_archive() || is_home()) {
					ob_start();
					do_action('nexter_before_excerpt');
					$before = ob_get_clean();
					
					ob_start();
					do_action('nexter_after_excerpt');
					$after = ob_get_clean();
					
					return $before . $excerpt . $after;
					}
					return $excerpt;
				}, 5);
			}
		}

		/**
		 * Register theme hook fallbacks for better live site compatibility
		 */
		public function register_theme_hook_fallbacks() {
			// Check if theme supports wp_body_open (WordPress 5.2+)
			if (!current_theme_supports('wp_body_open')) {
				add_action('wp_head', function() {
					echo "<!-- Note: Theme doesn't support wp_body_open hook -->\n";
				}, 999);
			}
			
			// Add fallback for themes without proper loop hooks
			if (!has_action('loop_start')) {
				add_action('wp_head', function() {
					if (is_archive() || is_home()) {
						add_action('the_post', function() {
							static $first_post = true;
							if ($first_post) {
								do_action('nexter_loop_start');
								$first_post = false;
							}
						}, 1);
					}
				});
			}
		}

		/**
		 * Detect FSE theme compatibility and add appropriate hooks
		 */
		public function detect_fse_theme_compatibility() {
			// Check if we're using an FSE theme
			if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
				// Add FSE-specific compatibility hooks
				add_action('init', function() {
					// Register custom block patterns for better FSE integration
					if (function_exists('register_block_pattern_category')) {
						register_block_pattern_category(
							'nexter-snippets',
							array('label' => __('Nexter Snippets', 'nexter-extension'))
						);
					}
				});
				
				// Add FSE theme debugging info
				add_action('wp_footer', function() {
					if (current_user_can('manage_options') && defined('WP_DEBUG') && WP_DEBUG) {
						echo "<!-- Nexter Extension: FSE Theme Detected -->\n";
						echo "<!-- Theme: " . get_template() . " -->\n";
						echo "<!-- FSE Compatibility: Active -->\n";
					}
				}, 999);
			}
		}

		public function nexter_register_snippet_ids_filter() {
			add_filter('nexter_loaded_snippet_ids', function($all) {
				return array_merge($all, self::$snippet_loaded_ids);
			});
		}

	
		
		public function check_and_recover_html($html) {
			$html = stripslashes($html);
			libxml_use_internal_errors(true);
			$dom = new DOMDocument();
	
			if ($dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
				$errors = libxml_get_errors();
				libxml_clear_errors();
	
				if (empty($errors)) {
					return '';
				} else {
					$error_messages = array_map(function($error) {
						return [
							'line' => $error->line,
							'message' => trim($error->message)
						];
					}, $errors);
	
					return ['error' => $error_messages];
				}
			} else {
				return ['error' => esc_html__('Failed to load HTML. Check syntax.','nexter-extension')];
			}
			return '';
		}

		public function home_page_code_execute(){
			if(isset($_GET['test_code']) && $_GET['test_code']=='code_test' && isset($_GET['code_id']) && !empty($_GET['code_id'])){ // phpcs:ignore WordPress.Security.NonceVerification.Recommended, handled by the core method already.
				$code_id = isset($_GET['code_id']) ? sanitize_text_field(wp_unslash($_GET['code_id'])) : '';
				$this->nexter_code_test_php_snippets($code_id);
			}
		}

		/*
		 * Get Code Snippets Php Execute
		 * @since 1.0.4
		 */
		public function nexter_code_test_php_snippets( $post_id = null){
			
			if(empty($post_id)){
				return false;
			}
			if ( current_user_can('administrator') ) {
				if(!empty($post_id)){
					$php_code = get_post_meta( $post_id, 'nxt-php-code', true );
					if(!empty($php_code) ){
						// Start output buffering to prevent interference with AJAX responses
						if (defined('DOING_AJAX') && DOING_AJAX) {
							ob_start();
						}
						
						// Apply filter to allow Pro version to pass shortcode attributes
						$attributes = apply_filters('nexter_php_snippet_attributes', array(), $post_id, $php_code);
						$result = Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id, true, $attributes);
						
						// Clean buffer for AJAX requests to prevent output interference
						if (defined('DOING_AJAX') && DOING_AJAX) {
							ob_end_clean();
							
							// If there's an error, return it for AJAX handling
							if (is_wp_error($result)) {
								return $result;
							}
							return true;
						} else {
							// For non-AJAX requests, allow normal output
							// Apply filter to allow Pro version to pass shortcode attributes
							$attributes = apply_filters('nexter_php_snippet_attributes', array(), $post_id, $php_code);
							Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id, false, $attributes);
						}
					}
				}
			}
		}
		
		
		/**
		 * List of Data Get Load Snippets
		 */
		public function get_snippet_ids(){
			$options = [
				'location'  => 'nxt-add-display-rule',
				'exclusion' => 'nxt-exclude-display-rule',
			];

			$check_posts = get_posts([
				'post_type'      => self::$snippet_type,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]);
			
			if (!empty($check_posts)) {
				self::$snippet_ids = Nexter_Builder_Display_Conditional_Rules::get_instance()->get_templates_by_sections_conditions( self::$snippet_type, $options );
			}
		}

		/**
		 * Load Snippets get IDs
		 */
		public static function get_snippets_ids_list( $type='' ){
			$get_result=array();
			if(self::$snippet_ids && !empty( $type )){
				foreach ( self::$snippet_ids as $post_id => $post_data ) {
					
					$codes_snippet   = get_post_meta( $post_id, 'nxt-code-type', false );
					$codes_status   = get_post_meta( $post_id, 'nxt-code-status', false );
					if(!empty($codes_snippet) && $codes_snippet[0]== $type && !empty($codes_status[0]) && $codes_status[0]==1){
						$get_result[] = $post_id;
					}
				}
			}
			
			// Enhanced fallback: If we got results from display rules but they seem incomplete,
			// merge with direct database query to ensure all active snippets are included
			if (!empty($get_result) && !empty($type)) {
				$fallback_results = self::get_snippets_fallback($type);
				if (!empty($fallback_results)) {
					// Merge and remove duplicates
					$get_result = array_unique(array_merge($get_result, $fallback_results));
				}
			}
			
			return $get_result;
		}

		/**
		 * Enqueue script admin area.
		 *
		 * @since 2.0.0
		 */
		public function enqueue_scripts_admin( $hook_suffix ) {
			// Check if code snippets are enabled
			if (!$this->is_code_snippets_enabled()) {
				return;
			}
			
			// Code Snippet Dashboard enquque
			if ( strpos( $hook_suffix, 'nxt_code_snippets' ) === false ) {
				return;
			}else if ( ! str_contains( $hook_suffix, 'nxt_code_snippets' ) ) {
				return;
			}

			wp_enqueue_style( 'nxt-code-snippet-style', NEXTER_EXT_URL . 'assets/css/admin/nxt-code-snippet.min.css', array(), NEXTER_EXT_VER, 'all' );
			// wp_enqueue_style( 'nxt-code-snippet-style', NEXTER_EXT_URL . 'code-snippets/build/index.css', array(), NEXTER_EXT_VER, 'all' );

			wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'assets/js/admin/index.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash', 'wp-i18n' ), NEXTER_EXT_VER, true );
			
			// Attach JavaScript translations
            wp_set_script_translations(
                'nxt-code-snippet',  // Handle must match enqueue
                'nexter-extension',	// Your text domain
                NEXTER_EXT_DIR . 'languages'
            );
			// wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'code-snippets/build/index.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash' ), NEXTER_EXT_VER, true );

			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			
			// Get dynamic post types and taxonomies
			$post_types_data = $this->get_dynamic_post_types();
			$taxonomies_data = $this->get_dynamic_taxonomies();
			$page_templates_data = $this->get_dynamic_page_templates();

			wp_localize_script(
				'nxt-code-snippet',
				'nxt_code_snippet_data',
				array(
					'adminUrl' => admin_url(),
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'nxt-code-snippet' ),
					'nxt_url' => NEXTER_EXT_URL.'code-snippets/',
					// 'pro' => defined('TPGBP_VERSION'),
					'htmlHooks' => class_exists('Nexter_Builder_Display_Conditional_Rules') ? Nexter_Builder_Display_Conditional_Rules::get_sections_hooks_options() : [],
					'in_ex_option' => class_exists('Nexter_Builder_Display_Conditional_Rules') ? Nexter_Builder_Display_Conditional_Rules::get_location_rules_options() : [],
					'user_role' => class_exists('Nexter_Builder_Display_Conditional_Rules') ?Nexter_Builder_Display_Conditional_Rules::get_others_location_sub_options('user-roles') : [],
					'post_types' => $post_types_data,
					'taxonomies' => $taxonomies_data,
					'page_templates' => $page_templates_data,
					'isactivate' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::get_instance()->nexter_activate_status() : '',
					'is_pro' => (defined('NXT_PRO_EXT')) ? true : false,
					'ecommerce_plugins' => array(
						'woocommerce' => class_exists('WooCommerce'),
						'edd' => class_exists('Easy_Digital_Downloads'),
						'memberpress' => class_exists('MeprAppCtrl')
					),
					'memberpress_memberships' => $this->get_memberpress_memberships(),
					'cs_pro_svg' => NEXTER_EXT_URL . 'assets/images/cs_pro.svg',
					'cs_premium_icon' => NEXTER_EXT_URL . 'dashboard/assets/svg/premium_icon.svg',
					'cs_ec_required_icon' => NEXTER_EXT_URL . 'assets/images/cs_ec_require.svg'
				)
			);
		}

		/**
         * AJAX endpoint to fetch taxonomy terms for Dynamic Conditional Logic
		 */
		public function get_taxonomy_terms_ajax() {
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			
			check_ajax_referer('nxt-code-snippet', 'nonce');
			
			$search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
			
			if (strlen($search_query) < 2) {
				wp_send_json([]);
				return;
			}
			
			$response = array();
			
			// Get all public taxonomies
			$taxonomies = get_taxonomies(array('public' => true), 'objects');
			
			foreach ($taxonomies as $taxonomy) {
				// Skip post format taxonomy
				if ($taxonomy->name === 'post_format') {
					continue;
				}
				
				// Search for terms in this taxonomy
				$terms = get_terms(array(
					'taxonomy' => $taxonomy->name,
					'hide_empty' => false,
					'name__like' => $search_query,
					'number' => 20 // Limit results per taxonomy
				));
				
				if (!is_wp_error($terms) && !empty($terms)) {
					$children = array();
					
					foreach ($terms as $term) {
						$children[] = array(
							'id' => 'term-' . $term->term_id,
							'text' => $term->name . ' (' . $taxonomy->label . ')'
						);
					}
					
					if (!empty($children)) {
						$response[] = array(
							'text' => $taxonomy->label,
							'children' => $children
						);
					}
				}
			}
			
		wp_send_json($response);
	}

	/**
     * AJAX endpoint to fetch authors for Dynamic Conditional Logic
	 */
	public function get_authors_ajax() {
		if(!$this->check_permission_user()){
			wp_send_json_error('Insufficient permissions.');
		}
		
		check_ajax_referer('nxt-code-snippet', 'nonce');
		
		$search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
		
		if (strlen($search_query) < 2) {
			wp_send_json([]);
			return;
		}
		
		// Search for users/authors
		$users = get_users(array(
			'search' => '*' . $search_query . '*',
			'search_columns' => array('user_login', 'user_nicename', 'display_name', 'user_email'),
			'number' => 50, // Limit results
			'orderby' => 'display_name',
			'order' => 'ASC'
		));
		
		$response = array();
		
		if (!empty($users)) {
			$children = array();
			
			foreach ($users as $user) {
				$children[] = array(
					'id' => 'user-' . $user->ID,
					'text' => $user->display_name . ' (' . $user->user_login . ')'
				);
			}
			
			if (!empty($children)) {
				$response[] = array(
					'text' => __('Authors', 'nexter-extension'),
					'children' => $children
				);
			}
		}
		
		wp_send_json($response);
	}

	/**
     * AJAX endpoint to fetch snippet list for Dynamic Conditional Logic
	 */
	public function fetch_snippet_list_for_conditions() {
		if(!$this->check_permission_user()){
			wp_send_json_error('Insufficient permissions.');
		}
		
		check_ajax_referer('nxt-code-snippet', 'nonce');
		
		$search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
		
		$args = array(
			'post_type'      => self::$snippet_type,
			'post_status'    => 'publish',
			'posts_per_page' => 50, // Limit results for performance
			'orderby'        => 'title',
			'order'          => 'ASC'
		);
		
		// Add search query if provided
		if (!empty($search_query)) {
			$args['s'] = $search_query;
		}
		
		$query = new WP_Query($args);
		$snippet_list = [];

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$type = get_post_meta(get_the_ID(), 'nxt-code-type', true);
				$status = get_post_meta(get_the_ID(), 'nxt-code-status', true);
				
				$snippet_list[] = [
					'id' => get_the_ID(),
					'name' => get_the_title(),
					'type' => $type ?: 'unknown',
					'status' => $status ? 'active' : 'inactive'
				];
			}
			wp_reset_postdata();
		}
	
		wp_send_json_success($snippet_list);
	}

	/**
     * Get dynamic post types for Dynamic Conditional Logic
	 */
		private function get_dynamic_post_types() {
			$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
			$formatted_post_types = array();
			
			foreach ( $post_types as $post_type ) {
				// Skip the builder post type
				if ( $post_type->name === self::$snippet_type || $post_type->name === 'nxt_builder' ) {
					continue;
				}
				
				$formatted_post_types[] = array(
					'value' => $post_type->name,
					'label' => $post_type->label
				);
			}
			
			return $formatted_post_types;
		}

		/**
         * Get dynamic taxonomies for Dynamic Conditional Logic
		 */
		private function get_dynamic_taxonomies() {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
			$formatted_taxonomies = array();
			
			foreach ( $taxonomies as $taxonomy ) {
				// Skip post format taxonomy
				if ( $taxonomy->name === 'post_format' ) {
					continue;
				}
				
				$formatted_taxonomies[] = array(
					'value' => $taxonomy->name,
					'label' => $taxonomy->label
				);
			}
			
			return $formatted_taxonomies;
		}

		/**
         * Get dynamic page templates for Dynamic Conditional Logic
		 */
		private function get_dynamic_page_templates() {
			$templates = wp_get_theme()->get_page_templates();
			$formatted_templates = array(
				array(
					'value' => 'default',
					'label' => __( 'Default Template', 'nexter-extension' )
				)
			);
			
			foreach ( $templates as $template_file => $template_name ) {
				$formatted_templates[] = array(
					'value' => $template_file,
					'label' => $template_name
				);
			}
			
			return $formatted_templates;
		}

		/**
		 * Verify CSS Selector database functionality
		 * This function can be called to test CSS selector database operations
		 */
		public function verify_css_selector_database() {
			// Test CSS selector metadata storage and retrieval
			$test_post_id = wp_insert_post([
				'post_title' => 'Test CSS Selector Snippet',
				'post_type' => self::$snippet_type,
				'post_status' => 'draft'
			]);
			
			if (!$test_post_id || is_wp_error($test_post_id)) {
				return ['success' => false, 'message' => 'Failed to create test post'];
			}
			
			// Test CSS selector data
			$test_css_selector = '.test-class, #test-id';
			$test_element_index = 2;
			
			// Store test data
			update_post_meta($test_post_id, 'nxt-css-selector', $test_css_selector);
			update_post_meta($test_post_id, 'nxt-element-index', $test_element_index);
			update_post_meta($test_post_id, 'nxt-code-location', 'before_html_element');
			update_post_meta($test_post_id, 'nxt-code-type', 'htmlmixed');
			
			// Retrieve test data
			$retrieved_css_selector = get_post_meta($test_post_id, 'nxt-css-selector', true);
			$retrieved_element_index = get_post_meta($test_post_id, 'nxt-element-index', true);
			
			// Clean up test post
			wp_delete_post($test_post_id, true);
			
			// Verify data integrity
			$verification_results = [
				'css_selector_match' => ($retrieved_css_selector === $test_css_selector),
				'element_index_match' => (intval($retrieved_element_index) === $test_element_index),
				'css_selector_value' => $retrieved_css_selector,
				'element_index_value' => $retrieved_element_index
			];
			
			$all_passed = $verification_results['css_selector_match'] && $verification_results['element_index_match'];
			
			return [
				'success' => $all_passed,
				'message' => $all_passed ? 'CSS Selector database functionality verified successfully' : 'CSS Selector database verification failed',
				'details' => $verification_results
			];
		}

		/**
		 * Check User Permission Ajax
		 */
		public function check_permission_user(){
			
			if ( ! is_user_logged_in() ) {
                return false;
            }
			
			$user = wp_get_current_user();
			if ( empty( $user ) ) {
				return false;
			}
			$allowed_roles = array( 'administrator' );
			if ( !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Create New Snippet Data
		 */
		public function create_new_snippet(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			
			check_ajax_referer('nxt-code-snippet', 'nonce');

			if ( isset($_POST['title']) ) {
				$title = sanitize_text_field(wp_unslash($_POST['title']));
				if(empty($title)){
					wp_send_json_error('Enter Title Snippet');
				}

				$new_post = array(
					'post_title' => $title,
					'post_status' => 'publish',
					'post_type' => self::$snippet_type,
				);
		
				$post_id = wp_insert_post($new_post);
		
				if ($post_id) {
					$this->add_update_metadata($post_id);
					wp_send_json_success(['id' => $post_id, 'message' => 'Snippet Created Successfully.']);
				} else {
					wp_send_json_error('Failed to Create Snippet.');
				}
			} else {
				wp_send_json_error('Missing required fields.');
			}
		}

		/**
		 * Validate eCommerce location based on plugin availability
		 *
		 * @param string $location The location to validate
		 * @return bool Whether the location is valid
		 */
		private function validate_ecommerce_location($location) {
			if (empty($location)) {
				return true; // Empty location is valid
			}
			
			// Include the eCommerce handler if it exists
			$ecommerce_handler_file = NEXTER_EXT_DIR . 'include/classes/load-code-snippet/handlers/nexter-ecommerce-code-handler.php';
			if (file_exists($ecommerce_handler_file)) {
				include_once $ecommerce_handler_file;
			}
			
			// Check if location is eCommerce-related
			if (class_exists('Nexter_ECommerce_Code_Handler')) {
				if (Nexter_ECommerce_Code_Handler::is_ecommerce_location($location)) {
					// Check specific plugin requirements
					if (Nexter_ECommerce_Code_Handler::is_woocommerce_location($location) && !Nexter_ECommerce_Code_Handler::is_woocommerce_active()) {
						return false; // WooCommerce location but WooCommerce not active
					}
					if (Nexter_ECommerce_Code_Handler::is_edd_location($location) && !Nexter_ECommerce_Code_Handler::is_edd_active()) {
						return false; // EDD location but EDD not active
					}
					if (Nexter_ECommerce_Code_Handler::is_memberpress_location($location) && !Nexter_ECommerce_Code_Handler::is_memberpress_active()) {
						return false; // MemberPress location but MemberPress not active
					}
				}
			}
			
			return true; // Valid location
		}

		/**
		 * Add Update Metadata Post
		 */
		public function add_update_metadata($post_id = ''){
			check_ajax_referer('nxt-code-snippet', 'nonce');
			if($post_id){

				$cache_option = 'nxt-build-get-data';

				$get_data = get_option($cache_option);
				if( $get_data === false ){
					$value = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => '','code_updated' => ''];
					add_option( $cache_option, $value );
				}else if(!empty($get_data)){
					$get_data['saved'] = strtotime('now');
					update_option( $cache_option, $get_data, false );
				}
				
				$type = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
				if(!empty($type) && in_array($type, ['php','htmlmixed','css','javascript'])){
					update_post_meta( $post_id , 'nxt-code-type', $type );
				}

							$insertion = (isset($_POST['insertion']) && !empty($_POST['insertion'])) ? sanitize_text_field(wp_unslash($_POST['insertion'])) : 'auto';
			if( !empty($insertion) ){
				update_post_meta( $post_id , 'nxt-code-insertion', $insertion );
			}

			$location = (isset($_POST['location']) && !empty($_POST['location'])) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
			
			// CSS Selector specific settings (always save these when they're provided)
			$css_selector = (isset($_POST['css_selector'])) ? sanitize_text_field(wp_unslash($_POST['css_selector'])) : '';
			update_post_meta( $post_id , 'nxt-css-selector', $css_selector );

			$element_index = (isset($_POST['element_index'])) ? absint($_POST['element_index']) : 0;
			update_post_meta( $post_id , 'nxt-element-index', $element_index );

				// If no location is provided, set default based on code type
				if (empty($location) && !empty($type)) {
					$location = $this->get_default_location_for_type($type);
				}
				
				if( !empty($location) ){
					// Validate eCommerce location before saving
					if ($this->validate_ecommerce_location($location)) {
						update_post_meta( $post_id , 'nxt-code-location', $location );
					} else {
						// Reset to default if invalid eCommerce location
						$default_location = $this->get_default_location_for_type($type);
						update_post_meta( $post_id , 'nxt-code-location', $default_location );
					}
				}

				$hooks_priority = (isset($_POST['hooks_priority']) && !empty($_POST['hooks_priority'])) ? absint($_POST['hooks_priority']) : 10;
				if(isset($hooks_priority)){
					update_post_meta( $post_id ,'nxt-code-hooks-priority', $hooks_priority);
				}

				// Save word-based insertion settings
				$word_count = (isset($_POST['word_count']) && !empty($_POST['word_count'])) ? absint($_POST['word_count']) : 100;
				update_post_meta( $post_id , 'nxt-insert-word-count', $word_count );

				$word_interval = (isset($_POST['word_interval']) && !empty($_POST['word_interval'])) ? absint($_POST['word_interval']) : 200;
				update_post_meta( $post_id , 'nxt-insert-word-interval', $word_interval );

				// Save post number for Before X Post and After X Post locations
				$post_number = (isset($_POST['post_number']) && !empty($_POST['post_number'])) ? absint($_POST['post_number']) : 1;
				update_post_meta( $post_id , 'nxt-post-number', $post_number );

				$customname = (isset($_POST['customname']) && !empty($_POST['customname']) ) ? sanitize_text_field(wp_unslash($_POST['customname'])) : '';
				update_post_meta( $post_id , 'nxt-code-customname', $customname );

				$compresscode = isset($_POST['compresscode']) ? rest_sanitize_boolean(wp_unslash($_POST['compresscode'])) : false;
				update_post_meta( $post_id , 'nxt-code-compresscode', $compresscode );

				$startDate = (isset($_POST['startDate']) && !empty($_POST['startDate'])) ? sanitize_text_field(wp_unslash($_POST['startDate'])) : '';
				update_post_meta( $post_id , 'nxt-code-startdate', $startDate );

				$endDate = (isset($_POST['endDate']) && !empty($_POST['endDate'])) ? sanitize_text_field(wp_unslash($_POST['endDate'])) : '';
				update_post_meta( $post_id , 'nxt-code-enddate', $endDate );

				$shortcodeattr = (isset($_POST['shortcodeattr']) && !empty($_POST['shortcodeattr'])) ? array_map('sanitize_text_field', explode(',', $_POST['shortcodeattr'])) : [];
				if(isset($shortcodeattr) ){
					if (is_array($shortcodeattr) && !empty($shortcodeattr)) {
						update_post_meta($post_id, 'nxt-code-shortcodeattr', $shortcodeattr);
					}else{
						update_post_meta($post_id, 'nxt-code-shortcodeattr', []);
					}
				}


				$submit_error_log = [];
				if (isset($_POST['lang-code']) && !empty($type)) {
					$lang_code = '';
					if($type==='css'){
						$lang_code = wp_strip_all_tags(wp_unslash($_POST['lang-code']));
						update_post_meta( $post_id ,'nxt-css-code', $lang_code);
					}else if($type=='javascript'){
						$lang_code = sanitize_textarea_field(wp_unslash($_POST['lang-code']));
						update_post_meta( $post_id ,'nxt-javascript-code', $lang_code);
					}else if($type=='htmlmixed'){
						$html_code = (isset($_POST['lang-code']) && !empty($_POST['lang-code'])) ? wp_unslash(stripslashes($_POST['lang-code'])) : '';
						update_post_meta( $post_id ,'nxt-htmlmixed-code', $html_code);

						if(!empty($html_code)){
							$error_log = $this->check_and_recover_html($html_code);
							if(!empty($error_log) && isset($error_log['error'])){
								$submit_error_log = $error_log['error'];
							}
						}

						$html_hooks = (isset($_POST['html_hooks']) && !empty($_POST['html_hooks'])) ? sanitize_text_field(wp_unslash($_POST['html_hooks'])) : '';
						if(isset($html_hooks)){
							update_post_meta( $post_id ,'nxt-code-html-hooks', $html_hooks);
						}
					}else if($type=='php'){
						// Set PHP execution permission based on location and user role
						$current_user = wp_get_current_user();
						$is_admin = in_array('administrator', $current_user->roles);
						
						// Only allow PHP execution if user is admin
						if ($is_admin) {
							$location = (isset($_POST['location']) && !empty($_POST['location'])) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
							$code_execute = (isset($_POST['code-execute']) && !empty($_POST['code-execute'])) ? sanitize_text_field(wp_unslash($_POST['code-execute'])) : '';
							
							// Enable PHP execution if either new or old location system indicates it should run
							if (
								// New location system
								(!empty($location) && in_array($location, ['run_everywhere', 'admin_only', 'frontend_only'])) ||
								// Old location system
								(!empty($code_execute) && in_array($code_execute, ['global', 'admin', 'front-end']))
							) {
								update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'yes');
							} else {
								update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
							}
						} else {
							update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
						}

						$lang_code = wp_unslash($_POST['lang-code']);
						update_post_meta($post_id, 'nxt-php-code', $lang_code);

						$code_execute = (isset($_POST['code-execute']) && !empty($_POST['code-execute'])) ? sanitize_text_field(wp_unslash($_POST['code-execute'])) : 'global';
						if(!empty($code_execute) && in_array($code_execute, ['global','admin','front-end'])){
							update_post_meta($post_id, 'nxt-code-execute', $code_execute);
						}
						
						// For PHP snippets, validate before saving
						$executor = Nexter_Builder_Code_Snippets_Executor::get_instance();
						$validation_result = $executor->validate_php_snippet_on_save($post_id, $lang_code);
						
						if (is_wp_error($validation_result)) {
							// Return detailed error message with post_id so frontend can navigate to edit page
							wp_send_json_error([
								'id' => $post_id,
								'message' => $validation_result->get_error_message(),
								'code' => $validation_result->get_error_code(),
								'line_info' => 'Check the line numbers and suggestions above.'
							]);
							return;
						}
					}

					if($type==='css' || $type=='javascript' || $type=='htmlmixed'){
						$include_exclude = (isset($_POST['include_exclude']) && !empty($_POST['include_exclude'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['include_exclude'])), true)) : [];

						if(isset($include_exclude['include']) && is_array($include_exclude['include'])){
							update_post_meta( $post_id ,'nxt-add-display-rule', $include_exclude['include']);
						}
						if(isset($include_exclude['exclude']) && is_array($include_exclude['exclude'])){
							update_post_meta( $post_id ,'nxt-exclude-display-rule', $include_exclude['exclude']);
						}

						$in_sub_field = (isset($_POST['in_sub_field']) && !empty($_POST['in_sub_field'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['in_sub_field'])), true)) : [];
						if(isset($in_sub_field) && is_array($in_sub_field)){
							update_post_meta( $post_id ,'nxt-in-sub-rule', $in_sub_field);
						}

						$ex_sub_field = (isset($_POST['ex_sub_field']) && !empty($_POST['ex_sub_field'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['ex_sub_field'])), true)) : [];
						if(isset($ex_sub_field) && is_array($ex_sub_field)){
							update_post_meta( $post_id ,'nxt-ex-sub-rule', $ex_sub_field);
						}
					}
				}

				$snippet_note = (isset($_POST['snippet_note']) && !empty($_POST['snippet_note'])) ? sanitize_text_field(wp_unslash($_POST['snippet_note'])) : '';
				if(isset($snippet_note)){
					update_post_meta( $post_id , 'nxt-code-note', $snippet_note );
				}

				$tags = (isset($_POST['tags']) && !empty($_POST['tags'])) ? array_map('sanitize_text_field', explode(',', $_POST['tags'])) : [];
				if(isset($tags) ){
					if (is_array($tags) && !empty($tags)) {
						update_post_meta($post_id, 'nxt-code-tags', $tags);
					}else{
						update_post_meta($post_id, 'nxt-code-tags', []);
					}
				}

				$status = isset($_POST['status']) ? rest_sanitize_boolean(wp_unslash($_POST['status'])) : false;
				if(isset($status)){
					$status = !empty($submit_error_log) ? 0 : $status;
					update_post_meta( $post_id , 'nxt-code-status', $status ? 1 : 0 );
				}

				// Save Dynamic Conditional Logic data with silent migration
				$smart_conditional_logic = (isset($_POST['smart_conditional_logic']) && !empty($_POST['smart_conditional_logic'])) ? 
					$this->sanitize_custom_array(json_decode(wp_unslash($_POST['smart_conditional_logic']), true)) : [];
				
				if(isset($_POST['smart_conditional_logic'])){
					// If Dynamic Conditional Logic is enabled, clear old display rules
					if (!empty($smart_conditional_logic) && 
						isset($smart_conditional_logic['enabled']) && 
						$smart_conditional_logic['enabled']) {
						
						// Clear old display rules when Dynamic Conditional Logic is enabled
						delete_post_meta( $post_id, 'nxt-add-display-rule' );
						delete_post_meta( $post_id, 'nxt-exclude-display-rule' );
						delete_post_meta( $post_id, 'nxt-in-sub-rule' );
						delete_post_meta( $post_id, 'nxt-ex-sub-rule' );
					}
					
					// Save the Dynamic Conditional Logic data
					update_post_meta( $post_id , 'nxt-smart-conditional-logic', $smart_conditional_logic );
				}

				if(!empty($submit_error_log)){
					wp_send_json_error([
						'id' => $post_id,
						'errors' => $submit_error_log
					]);
				}
			}
		}

		/**
		 * Sanitize Array 
		 */
		public function sanitize_custom_array($data) {
			if (!is_array($data)) {
				return [];
			}
		
			$sanitized_data = [];
		
			foreach ($data as $key => $value) {
				if (is_array($value)) {
					$sanitized_data[$key] = $this->sanitize_custom_array($value);
				} else {
					$sanitized_data[$key] = sanitize_text_field(wp_unslash($value));
				}
			}
		
			return $sanitized_data;
		}

		/**
		 * Update Snippet Data by ID
		 */
		public function update_edit_snippet(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}

			check_ajax_referer('nxt-code-snippet', 'nonce');
			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {
					if ( isset($_POST['title']) ) {
						$title = sanitize_text_field(wp_unslash($_POST['title']));
						if(empty($title)){
							wp_send_json_error('Enter Title Snippet');
						}
						$post_data = array(
							'ID'         => $post_id,
							'post_title' => $title,
						);
						wp_update_post($post_data);
					}

					$this->add_update_metadata($post_id);
					wp_send_json_success('Snippet Updated Successfully.');
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			} else {
				wp_send_json_error(['message' => 'Invalid Snippet ID']);
			}
		}

		/*
		 * Fetch nxt-code-Snippet List
		 * 
		 * */
		public function fetch_code_list(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$args = array(
				'post_type'      => self::$snippet_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			);
		
			$query = new WP_Query($args);
			$code_list = [];

			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();
					$type = get_post_meta(get_the_ID(), 'nxt-code-type', true);
					$code_list[] = [
						'id' => get_the_ID(),
						// 'name'        => get_the_title(),
						'name'        => get_post_field('post_title', get_the_ID(), 'raw'),
						'description'	=> get_post_meta(get_the_ID(), 'nxt-code-note', true),
						'type'	=> $type,
						'tags'	=> get_post_meta(get_the_ID(), 'nxt-code-tags', true),
						'code-execute'	=> get_post_meta(get_the_ID(), 'nxt-code-execute', true),
						'status'	=> get_post_meta(get_the_ID(), 'nxt-code-status', true),
						'priority' => get_post_meta(get_the_ID(), 'nxt-code-hooks-priority', true),
						'last_updated' => get_the_modified_time('F j, Y'),
					];
					
				}
				wp_reset_postdata();
			}else{
				wp_send_json_error('No List Found.');
			}
		
			wp_send_json_success($code_list);
		}

		/**
		 * Export Snippet
		 */
		public function fetch_code_snippet_export(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
			
			if ( ! $post_id ) {
				wp_send_json_error( 'Invalid snippet ID.' );
			}
		
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== self::$snippet_type ) {
				wp_send_json_error( 'Snippet not found or invalid post type.' );
			}
		
			$type = get_post_meta( $post->ID, 'nxt-code-type', true );
		
			$data = [
				'id' => $post->ID,
				'name'        => $post->post_title,
				'description'	=> get_post_meta($post->ID, 'nxt-code-note', true),
				'type'	=> $type,
				'post_type'    => self::$snippet_type,
				'tags'	=> get_post_meta($post->ID, 'nxt-code-tags', true),
				'codeExecute'	=> get_post_meta($post->ID, 'nxt-code-execute', true),
				'status'	=> get_post_meta($post->ID, 'nxt-code-status', true),
				'langCode' => get_post_meta( $post->ID, 'nxt-'.$type.'-code', true ),
				'htmlHooks' => get_post_meta( $post->ID, 'nxt-code-html-hooks', true ),
				'hooksPriority' => get_post_meta( $post->ID, 'nxt-code-hooks-priority', true ),
				'include_data' => get_post_meta( $post->ID, 'nxt-add-display-rule', true ),
				'exclude_data' => get_post_meta( $post->ID, 'nxt-exclude-display-rule', true ),
				'in_sub_data' => get_post_meta( $post->ID, 'nxt-in-sub-rule', true ),
				'ex_sub_data' => get_post_meta( $post->ID, 'nxt-ex-sub-rule', true ),
				// Word-based insertion settings
				'word_count' => get_post_meta( $post->ID, 'nxt-insert-word-count', true ) ?: 100,
				'word_interval' => get_post_meta( $post->ID, 'nxt-insert-word-interval', true ) ?: 200,
				'post_number' => get_post_meta( $post->ID, 'nxt-post-number', true ) ?: 1,
				// CSS Selector settings
				'css_selector' => get_post_meta( $post->ID, 'nxt-css-selector', true ),
				'element_index' => get_post_meta( $post->ID, 'nxt-element-index', true ) ?: 0,
				// Missing fields that should be exported
				'insertion' => get_post_meta( $post->ID, 'nxt-code-insertion', true ),
				'location' => get_post_meta( $post->ID, 'nxt-code-location', true ),
				'customname' => get_post_meta( $post->ID, 'nxt-code-customname', true ),
				'compresscode' => get_post_meta( $post->ID, 'nxt-code-compresscode', true ),
				'startDate' => get_post_meta( $post->ID, 'nxt-code-startdate', true ),
				'endDate' => get_post_meta( $post->ID, 'nxt-code-enddate', true ),
				'shortcodeattr' => get_post_meta( $post->ID, 'nxt-code-shortcodeattr', true ),
				'smart_conditional_logic' => get_post_meta( $post->ID, 'nxt-smart-conditional-logic', true ),
				'php_hidden_execute' => get_post_meta( $post->ID, 'nxt-code-php-hidden-execute', true ),
			];
		
			// Normalize code line endings
			if ( is_string( $data['langCode'] ) ) {
				$data['langCode'] = str_replace( "\r\n", "\n", $data['langCode'] );
			}
		
			$export_object = [
				'generator'    => 'Nexter Snippet Export v'.NEXTER_EXT_VER,
				'date_created' => gmdate( 'Y-m-d H:i' ),
				'snippets'     => [ $data ],
			];

			$title = sanitize_title( $post->post_title );
			$parts = explode( '-', $title );
			$first_part = $parts[0];
			$filename_prefix = substr( $first_part, 0, 7 );
			$filename_prefix = ucfirst( $filename_prefix );
			
			// Send export as file
			nocache_headers();
			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename="' . $filename_prefix . '-nxt-snippet-' . $post->ID . '.json"' );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
			ob_clean();
			// echo wp_json_encode( $export_object, JSON_PRETTY_PRINT ); uncomment this for pretty JSOn
			echo wp_json_encode( $export_object );
			exit;
		}	

		/**
		 * Import Snippet
		 */
		public function fetch_code_snippet_import() {
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');
		
			if ( empty( $_FILES['snippet_file'] ) || $_FILES['snippet_file']['error'] !== UPLOAD_ERR_OK ) {
				wp_send_json_error( 'No file uploaded or file upload error.' );
			}
		
			$uploaded_file = $_FILES['snippet_file']['tmp_name'];
			$content = file_get_contents( $uploaded_file );

			if ( ! $content ) {
				wp_send_json_error( 'Empty or unreadable file.' );
			}
		
			$json = json_decode( $content, true );
		
			if ( ! $json || empty( $json['snippets'] ) || ! is_array( $json['snippets'] ) ) {
				wp_send_json_error( 'Invalid snippet file.' );
			}
		
			$snippet = $json['snippets'][0];
			if ( empty( $snippet['post_type'] ) || $snippet['post_type'] !== self::$snippet_type ) {
				wp_send_json_error( 'Invalid snippet type.' );
			}
			$post_id = wp_insert_post( [
				'post_title'   => sanitize_text_field( $snippet['name'] ),
				'post_type'    => self::$snippet_type,
				'post_status'  => 'publish'
			] );
		
			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error( 'Failed to insert snippet.' );
			}

			$tags = ( isset( $snippet['tags'] ) && !empty( $snippet['tags'] ) ) 
				? array_map( 'sanitize_text_field', is_array( $snippet['tags'] ) ? $snippet['tags'] : explode( ',', $snippet['tags'] ) ) : [];
		
			// Save meta fields
			update_post_meta( $post_id, 'nxt-code-type', sanitize_text_field( $snippet['type'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-note', sanitize_text_field( $snippet['description'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-tags', $tags );
			update_post_meta( $post_id, 'nxt-code-execute', sanitize_text_field( $snippet['codeExecute'] ?? '' ) );
			// update_post_meta( $post_id, 'nxt-code-status', sanitize_text_field( $snippet['status'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-status', 0 );

			update_post_meta( $post_id, 'nxt-'.$snippet['type'].'-code', $snippet['langCode'] ?? '' );
			update_post_meta( $post_id, 'nxt-code-html-hooks', $snippet['htmlHooks'] ?? '' );
			update_post_meta( $post_id, 'nxt-code-hooks-priority', $snippet['hooksPriority'] ?? '' );
			update_post_meta( $post_id, 'nxt-add-display-rule', $snippet['include_data'] ?? '' );
			update_post_meta( $post_id, 'nxt-exclude-display-rule', $snippet['exclude_data'] ?? '' );
			update_post_meta( $post_id, 'nxt-in-sub-rule', $snippet['in_sub_data'] ?? '' );
			update_post_meta( $post_id, 'nxt-ex-sub-rule', $snippet['ex_sub_data'] ?? '' );
			
			// Import CSS Selector settings
			update_post_meta( $post_id, 'nxt-css-selector', $snippet['css_selector'] ?? '' );
			update_post_meta( $post_id, 'nxt-element-index', $snippet['element_index'] ?? 0 );

			// Import word-based insertion settings
			update_post_meta( $post_id, 'nxt-insert-word-count', $snippet['word_count'] ?? 100 );
			update_post_meta( $post_id, 'nxt-insert-word-interval', $snippet['word_interval'] ?? 200 );
			update_post_meta( $post_id, 'nxt-post-number', $snippet['post_number'] ?? 1 );

			// Import missing fields that are now exported
			update_post_meta( $post_id, 'nxt-code-insertion', sanitize_text_field( $snippet['insertion'] ?? 'auto' ) );
			update_post_meta( $post_id, 'nxt-code-location', sanitize_text_field( $snippet['location'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-customname', sanitize_text_field( $snippet['customname'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-compresscode', rest_sanitize_boolean( $snippet['compresscode'] ?? false ) );
			update_post_meta( $post_id, 'nxt-code-startdate', sanitize_text_field( $snippet['startDate'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-enddate', sanitize_text_field( $snippet['endDate'] ?? '' ) );
			update_post_meta( $post_id, 'nxt-code-shortcodeattr', $snippet['shortcodeattr'] ?? [] );
			update_post_meta( $post_id, 'nxt-smart-conditional-logic', $snippet['smart_conditional_logic'] ?? [] );

			if (($snippet['type'] ?? '') === 'php') {
				// Import PHP execution permission if available, otherwise default to 'yes' for backward compatibility
				update_post_meta($post_id, 'nxt-code-php-hidden-execute', $snippet['php_hidden_execute'] ?? 'yes');
			}
		
			wp_send_json_success( [
				'message' => 'Snippet imported.',
				'post_id' => $post_id,
			] );
		}
		

		/**
		 * Delete Snippet 
		 */
		public function fetch_code_snippet_delete(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {

					if (current_user_can('delete_post', $post_id)) {
					$deleted = wp_delete_post($post_id, true);

						if ($deleted) {
							wp_send_json_success(['message' => 'Snippet deleted successfully']);
						} else {
							wp_send_json_error(['message' => 'Failed to delete Snippet']);
						}
					} else {
						wp_send_json_error(['message' => 'You do not have permission to delete this snippet']);
					}
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			} else {
				wp_send_json_error(['message' => 'Invalid Snippet ID']);
			}
		}

		/*
		 * Snippet Status Change
		 */
		public function fetch_code_snippet_status(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {
					$get_status = get_post_meta($post_id, 'nxt-code-status', true);
					update_post_meta($post_id, 'nxt-code-status', !$get_status);
					
					$cache_option = 'nxt-build-get-data';
					$get_data = get_option($cache_option);
					if(!empty($get_data)){
						$get_data['saved'] = strtotime('now');
						update_option( $cache_option, $get_data, false );
					}

					wp_send_json_success(['status' => !$get_status, 'message' => 'Updated Status Successfully']);
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			} else {
				wp_send_json_error(['message' => 'Invalid post ID']);
			}
		}

		/*
		 * Edit Snippet Get Data
		 */
		public function get_edit_snippet_data(){
			if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {
					$type = get_post_meta($post->ID, 'nxt-code-type', true);
					$current_location = get_post_meta($post->ID, 'nxt-code-location', true);
					
					// Perform location migration if needed
					$migrated_location = $this->migrate_location_if_needed($post->ID, $type, $current_location);
					


					$get_data = [
						'id' => $post->ID,
						'name'        => $post->post_title,
						'description'	=> get_post_meta($post->ID, 'nxt-code-note', true),
						'type'	=> $type,
						'insertion' => get_post_meta($post->ID, 'nxt-code-insertion', true),
						'location' => $migrated_location,
						'customname' => get_post_meta($post->ID, 'nxt-code-customname', true),
						'compresscode' => get_post_meta($post->ID, 'nxt-code-compresscode', true),
						'startDate' => get_post_meta($post->ID, 'nxt-code-startdate', true),
						'endDate' => get_post_meta($post->ID, 'nxt-code-enddate', true),
						'shortcodeattr' => get_post_meta($post->ID, 'nxt-code-shortcodeattr', true),
						'tags'	=> get_post_meta($post->ID, 'nxt-code-tags', true),
						'codeExecute'	=> get_post_meta($post->ID, 'nxt-code-execute', true),
						'status'	=> get_post_meta($post->ID, 'nxt-code-status', true),
						'langCode' => get_post_meta( $post->ID, 'nxt-'.$type.'-code', true ),
						'htmlHooks' => get_post_meta( $post->ID, 'nxt-code-html-hooks', true ),
						'hooksPriority' => get_post_meta( $post->ID, 'nxt-code-hooks-priority', true ),
						'include_data' => get_post_meta( $post->ID, 'nxt-add-display-rule', true ),
						'exclude_data' => get_post_meta( $post->ID, 'nxt-exclude-display-rule', true ),
						'in_sub_data' => get_post_meta( $post->ID, 'nxt-in-sub-rule', true ),
						'ex_sub_data' => get_post_meta( $post->ID, 'nxt-ex-sub-rule', true ),
						// Word-based insertion settings
						'word_count' => get_post_meta( $post->ID, 'nxt-insert-word-count', true ) ?: 100,
						'word_interval' => get_post_meta( $post->ID, 'nxt-insert-word-interval', true ) ?: 200,
						'post_number' => get_post_meta( $post->ID, 'nxt-post-number', true ) ?: 1,
						// Dynamic Conditional Logic data
						'smart_conditional_logic' => get_post_meta( $post->ID, 'nxt-smart-conditional-logic', true ) ?: [],
						// CSS Selector settings
						'css_selector' => get_post_meta( $post->ID, 'nxt-css-selector', true ),
						'element_index' => get_post_meta( $post->ID, 'nxt-element-index', true ) ?: 0,
					];
					wp_send_json_success($get_data);
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			} else {
				wp_send_json_error(['message' => 'Invalid post ID']);
			}
		}

		/**
		 * Migrate location field from old system to new system
		 * 
		 * @param int $post_id The snippet post ID
		 * @param string $type The snippet type (php, css, javascript, htmlmixed)
		 * @param string $current_location Current location value
		 * @return string The migrated or default location value
		 */
		private function migrate_location_if_needed($post_id, $type, $current_location) {
			// If location is already set, no migration needed
			if (!empty($current_location)) {
				return $current_location;
			}
			
			$migrated_location = '';
			
			// Handle migration based on snippet type
			switch ($type) {
				case 'htmlmixed':
					// For HTML snippets, check if there was a hook set
					$html_hooks = get_post_meta($post_id, 'nxt-code-html-hooks', true);
					if (!empty($html_hooks)) {
						// Map hook to new location system
						$migrated_location = $this->map_hook_to_location($html_hooks);
					} else {
						// Default for HTML
						$migrated_location = 'site_header';
					}
					break;
					
				case 'php':
					// For PHP snippets, check "Run Code On" setting
					$code_execute = get_post_meta($post_id, 'nxt-code-execute', true);
					$migrated_location = $this->map_php_execute_to_location($code_execute);
					break;
					
				case 'css':
				case 'javascript':
					// Default for CSS/JS
					$migrated_location = 'site_header';
					break;
					
				default:
					$migrated_location = 'site_header';
					break;
			}
			
			// Save the migrated location to avoid repeated migration
			if (!empty($migrated_location)) {
				update_post_meta($post_id, 'nxt-code-location', $migrated_location);
			}
			
			return $migrated_location;
		}

		/**
		 * Map old hook values to new location system
		 * 
		 * @param string $hook The old hook value
		 * @return string The new location value
		 */
		private function map_hook_to_location($hook) {
			// Mapping from old hooks to new locations
			$hook_mapping = [
				'wp_head' => 'site_header',
				'wp_body_open' => 'site_body',
				'wp_footer' => 'site_footer',
				'admin_head' => 'admin_header',
				'admin_footer' => 'admin_footer',
				'the_content' => 'before_content',
				'loop_start' => 'before_post',
				// Note: 'loop_end' => 'after_post' removed - replaced with before_x_post and after_x_post
			];
			
			// Return mapped location or default to site_header
			return isset($hook_mapping[$hook]) ? $hook_mapping[$hook] : 'site_header';
		}

		/**
		 * Map old PHP "Run Code On" values to new location system
		 * 
		 * @param string $code_execute The old code execute value
		 * @return string The new location value
		 */
		private function map_php_execute_to_location($code_execute) {
			// Mapping from old "Run Code On" to new locations
			$execute_mapping = [
				'global' => 'run_everywhere',
				'admin' => 'admin_only',
				'front-end' => 'frontend_only',
			];
			
			// Return mapped location or default to run_everywhere
			return isset($execute_mapping[$code_execute]) ? $execute_mapping[$code_execute] : 'run_everywhere';
		}

		/**
		 * Get default location for a given snippet type
		 * 
		 * @param string $type The snippet type (php, css, javascript, htmlmixed)
		 * @return string The default location value
		 */
		private function get_default_location_for_type($type) {
			// Default locations by snippet type
			$default_locations = [
				'htmlmixed' => 'site_header',  // HTML → Site Wide Header
				'css' => 'site_header',        // CSS → Site Wide Header  
				'javascript' => 'site_header', // JavaScript → Site Wide Header
				'php' => 'run_everywhere'      // PHP → Run Code Everywhere
			];
			
			return isset($default_locations[$type]) ? $default_locations[$type] : 'site_header';
		}

		/*
		 * Nexter Builder Code Snippets Css/Js Enqueue
		 * Enhanced to support location-based execution
		 */
		public static function nexter_code_snippets_css_js() {
			
			wp_register_script( 'nxt-snippet-js', false );
            wp_enqueue_script( 'nxt-snippet-js' );
			
			// CSS Snippets
			$css_actions = self::get_snippets_ids_list( 'css' );
			// Enhanced fallback: Always ensure we have all active CSS snippets
			if (empty($css_actions)) {
				$css_actions = self::get_snippets_fallback('css');
			}
			
			if( !empty( $css_actions ) ){
				foreach ( $css_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {

						$insertion_type   = get_post_meta($post_id, 'nxt-code-insertion', true);
						if( !empty($insertion_type) && $insertion_type == 'shortcode'){
							continue;
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Conditional logic check
						if (class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::should_display_snippet($post_id)) {
								continue; // Skip this snippet, conditional logic not met
							}
						}

						$css_code = get_post_meta( $post_id, 'nxt-css-code', true );
						if(!empty($css_code) ){
							self::$snippet_loaded_ids['css'][] = $post_id;
							
							// Check for new location system
							$location = get_post_meta($post_id, 'nxt-code-location', true);
							if (!empty($location)) {
								// Use new location-based system
								self::enqueue_css_at_location($post_id, $css_code, $location);
							} else {
								// Use old system (default to wp_head)
								wp_register_style( 'nxt-snippet-css', false );
								wp_enqueue_style( 'nxt-snippet-css' );
								wp_add_inline_style( 'nxt-snippet-css', wp_specialchars_decode($css_code) );
							}
						}
					}
				}
			}
			
			// JavaScript Snippets
			$javascript_actions = self::get_snippets_ids_list( 'javascript' );
			// Enhanced fallback: Always ensure we have all active JavaScript snippets
			if (empty($javascript_actions)) {
				$javascript_actions = self::get_snippets_fallback('javascript');
			}
			
			if( !empty( $javascript_actions ) ){
				foreach ( $javascript_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {
						
						$insertion_type   = get_post_meta($post_id, 'nxt-code-insertion', true);
						if( !empty($insertion_type) && $insertion_type == 'shortcode'){
							continue;
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Conditional logic check
						if (class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::should_display_snippet($post_id)) {
								continue; // Skip this snippet, conditional logic not met
							}
						}

						$javascript_code = get_post_meta( $post_id, 'nxt-javascript-code', true );
						if(!empty($javascript_code) ){
							self::$snippet_loaded_ids['javascript'][] = $post_id;
							
							// Check for new location system
							$location = get_post_meta($post_id, 'nxt-code-location', true);
							if (!empty($location)) {
								// Use new location-based system
								self::enqueue_js_at_location($post_id, $javascript_code, $location);
							} else {
								// Use old system (default to footer)
								wp_add_inline_script( 'nxt-snippet-js', html_entity_decode($javascript_code, ENT_QUOTES) );
							}
						}
					}
				}
			}
				}

		/*
		 * Nexter Builder Code Snippets Css/Js Enqueue for Admin Area
		 * Enhanced to support admin location-based execution
		 */
		public static function nexter_code_snippets_css_js_admin() {
			// Only process admin-specific locations
			$admin_locations = ['admin_header', 'admin_footer'];
			
			// CSS Snippets for Admin
			$css_actions = self::get_snippets_ids_list( 'css' );
			// Enhanced fallback: Always ensure we have all active CSS snippets
			if (empty($css_actions)) {
				$css_actions = self::get_snippets_fallback('css');
			}
			
			if( !empty( $css_actions ) ){
				foreach ( $css_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {

						$insertion_type   = get_post_meta($post_id, 'nxt-code-insertion', true);
						if( !empty($insertion_type) && $insertion_type == 'shortcode'){
							continue;
						}

						// Only process admin locations
						$location = get_post_meta($post_id, 'nxt-code-location', true);
						if (!in_array($location, $admin_locations)) {
							continue;
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Conditional logic check
						if (class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::should_display_snippet($post_id)) {
								continue; // Skip this snippet, conditional logic not met
							}
						}

						$css_code = get_post_meta( $post_id, 'nxt-css-code', true );
						if(!empty($css_code) ){
							self::$snippet_loaded_ids['css'][] = $post_id;
							
							// Use location-based system for admin locations
							self::enqueue_css_at_location($post_id, $css_code, $location);
						}
					}
				}
			}
			
			// JavaScript Snippets for Admin
			$javascript_actions = self::get_snippets_ids_list( 'javascript' );
			// Enhanced fallback: Always ensure we have all active JavaScript snippets
			if (empty($javascript_actions)) {
				$javascript_actions = self::get_snippets_fallback('javascript');
			}
			
			if( !empty( $javascript_actions ) ){
				foreach ( $javascript_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {
						
						$insertion_type   = get_post_meta($post_id, 'nxt-code-insertion', true);
						if( !empty($insertion_type) && $insertion_type == 'shortcode'){
							continue;
						}

						// Only process admin locations
						$location = get_post_meta($post_id, 'nxt-code-location', true);
						if (!in_array($location, $admin_locations)) {
							continue;
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Conditional logic check
						if (class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::should_display_snippet($post_id)) {
								continue; // Skip this snippet, conditional logic not met
							}
						}

						$javascript_code = get_post_meta( $post_id, 'nxt-javascript-code', true );
						if(!empty($javascript_code) ){
							self::$snippet_loaded_ids['javascript'][] = $post_id;
							
							// Use location-based system for admin locations
							self::enqueue_js_at_location($post_id, $javascript_code, $location);
						}
					}
				}
			}
		}

		/**
		 * PHP snippets hooks actions with location support
		 * Enhanced to support location-based execution for PHP snippets
		 */
		public static function nexter_code_php_hooks_actions() {
			$php_snippets = self::get_snippets_ids_list('php');
			// Enhanced fallback: Always ensure we have all active PHP snippets
			if (empty($php_snippets)) {
				$php_snippets = self::get_snippets_fallback('php');
			}
			
			if (!empty($php_snippets)) {
				foreach ($php_snippets as $post_id) {
					$post_type = get_post_type();

					if (self::$snippet_type != $post_type) {
						// Skip shortcode insertion type for auto execution
						$insertion_type = get_post_meta($post_id, 'nxt-code-insertion', true);
						if (!empty($insertion_type) && $insertion_type == 'shortcode') {
							continue;
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Smart Conditional Logic check
						$smart_conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
						if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
								continue; // Skip this snippet, Smart Conditional Logic not met
							}
						}

						self::$snippet_loaded_ids['php'][] = $post_id;
						
						// Get PHP code
						$php_code = get_post_meta($post_id, 'nxt-php-code', true);
						if (!empty($php_code)) {
							// Check for new location system
							$location = get_post_meta($post_id, 'nxt-code-location', true);
							if (!empty($location)) {
								// Use new location-based system
								self::execute_php_at_location($post_id, $php_code, $location);
								return;
							} else {
								// Use old system - check execution context
								$code_execute = get_post_meta($post_id, 'nxt-code-execute', true);
								$execute_location = !empty($code_execute) ? $code_execute : 'global';
								
								if ($execute_location === 'global' || 
									($execute_location === 'admin' && is_admin()) || 
									($execute_location === 'front-end' && !is_admin() && !self::is_elementor_edit_or_preview_mode())) {
									
									add_action('init', function() use ($php_code, $post_id) {
										$is_active = get_post_meta($post_id, 'nxt-code-status', true);
										if ($is_active == '1') {
											// Apply filter to allow Pro version to pass shortcode attributes
											$attributes = apply_filters('nexter_php_snippet_attributes', array(), $post_id, $php_code);
											Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id, true, $attributes);
										}
									}, 10);
								}
							}
						}
					}
				}
			}
		}

		/**
		 * HTML snippets hooks actions with location support for admin area
		 * Enhanced to support admin location-based execution for HTML snippets  
		 */
		public static function nexter_code_html_hooks_actions_admin() {
			$admin_locations = ['admin_header', 'admin_footer'];
			$html_snippets = self::get_snippets_ids_list('htmlmixed');
			// Enhanced fallback: Always ensure we have all active HTML snippets
			if (empty($html_snippets)) {
				$html_snippets = self::get_snippets_fallback('htmlmixed');
			}

			if (!empty($html_snippets)) {
				foreach ($html_snippets as $post_id) {
					$post_type = get_post($post_id);

					// Skip shortcode insertion type for auto execution
					$insertion_type = get_post_meta($post_id, 'nxt-code-insertion', true);
					if (!empty($insertion_type) && $insertion_type == 'shortcode') {
						continue;
					}

					// Only process admin locations
					$location = get_post_meta($post_id, 'nxt-code-location', true);
					if (!in_array($location, $admin_locations)) {
						continue;
					}

					// Check Pro restrictions (device and scheduling)
					if (self::should_skip_due_to_pro_restrictions($post_id)) {
						continue; // Skip this snippet due to Pro restrictions
					}

					// Smart Conditional Logic check
					$smart_conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
					if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
						if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
							continue; // Skip this snippet, Smart Conditional Logic not met
						}
					}

					self::$snippet_loaded_ids['htmlmixed'][] = $post_id;
					
					// Get HTML code
					$html_code = get_post_meta($post_id, 'nxt-htmlmixed-code', true);
					if (!empty($html_code)) {
						// Use location-based system for admin locations
						self::output_html_at_location($post_id, $html_code, $location);
					}
				}
			}
		}

		/**
		 * HTML snippets hooks actions with location support
		 * Enhanced to support location-based execution for HTML snippets
		 */
		public static function nexter_code_html_hooks_actions() {
			$html_snippets = self::get_snippets_ids_list('htmlmixed');
			// Enhanced fallback: Always ensure we have all active HTML snippets
			if (empty($html_snippets)) {
				$html_snippets = self::get_snippets_fallback('htmlmixed');
			}

			// CSS Selector location types - these are handled separately by the CSS selector system
			$css_selector_locations = apply_filters('nexter_get_css_selector_locations', ['before_html_element', 'after_html_element', 'start_html_element', 'end_html_element', 'replace_html_element']);
			
			if (!empty($html_snippets)) {
				foreach ($html_snippets as $post_id) {
					$post_type = get_post($post_id);

					// if (self::$snippet_type != $post_type->post_type) {
						// Skip shortcode insertion type for auto execution
						$insertion_type = get_post_meta($post_id, 'nxt-code-insertion', true);
						if (!empty($insertion_type) && $insertion_type == 'shortcode') {
							continue;
						}

						// Check location - skip CSS selector locations here as they're handled by CSS selector system
						$location = get_post_meta($post_id, 'nxt-code-location', true);
						if (in_array($location, $css_selector_locations)) {
							continue; // CSS selector locations are handled by the CSS selector system
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Smart Conditional Logic check
						$smart_conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
						if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
								continue; // Skip this snippet, Smart Conditional Logic not met
							}
						}

						self::$snippet_loaded_ids['htmlmixed'][] = $post_id;
						
						// Get HTML code
						$html_code = get_post_meta($post_id, 'nxt-htmlmixed-code', true);
						if (!empty($html_code)) {
							// Check for new location system (excluding CSS selector locations)
							if (!empty($location)) {
								// Use new location-based system
								self::output_html_at_location($post_id, $html_code, $location);
							} else {
								// Use old hook system if available, otherwise wp_footer
								$hook_action = get_post_meta($post_id, 'nxt-code-html-hooks', true);
								$hook_priority = get_post_meta($post_id, 'nxt-code-hooks-priority', true);
								
								if (!empty($hook_action)) {
									$hook_priority = !empty($hook_priority) ? intval($hook_priority) : 10;
									add_action(
										$hook_action,
										function() use ($post_id) {
											$is_active = get_post_meta($post_id, 'nxt-code-status', true);
											if ($is_active == '1') {
												$html_code = get_post_meta($post_id, 'nxt-htmlmixed-code', true);
												echo apply_filters('nexter_html_snippets_executed', $html_code, $post_id);
											}
										},
										$hook_priority
									);
								} else {
									// Default to wp_footer if no hook specified
									add_action('wp_footer', function() use ($html_code, $post_id) {
										$is_active = get_post_meta($post_id, 'nxt-code-status', true);
										if ($is_active == '1') {
											echo apply_filters('nexter_html_snippets_executed', $html_code, $post_id);
										}
									}, 10);
								}
							}
						}
					// }
				}
			}
			
		}

		/**
		 * Fallback method to get snippets when display rules don't work
		 */
		private static function get_snippets_fallback($code_type) {
			$args = array(
				'post_type' => self::$snippet_type,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => 'nxt-code-type',
						'value' => $code_type,
						'compare' => '='
					),
					array(
						'key' => 'nxt-code-status',
						'value' => '1',
						'compare' => '='
					)
				)
			);
			
			return get_posts($args);
		}

		/**
		 * Execute PHP code at specified location using specialized handlers
		 */
		private static function execute_php_at_location($snippet_id, $code, $location) {
			// Try Global Code Handler first
			if (Nexter_Global_Code_Handler::execute_global_php($snippet_id, $code, $location)) {
				return;
			}

			// Try Page-Specific Code Handler
			if (Nexter_Page_Specific_Code_Handler::execute_page_specific_php($snippet_id, $code, $location)) {
				return;
			}

			// Try eCommerce Code Handler
			if (Nexter_ECommerce_Code_Handler::execute_ecommerce_php($snippet_id, $code, $location)) {
				return;
			}
		}

		/**
		 * Enqueue CSS at specified location using specialized handlers
		 */
		private static function enqueue_css_at_location($snippet_id, $css, $location) {
			// Try Global Code Handler first
			if (Nexter_Global_Code_Handler::enqueue_global_css($snippet_id, $css, $location)) {
				return;
			}

			// Try Page-Specific Code Handler
			if (Nexter_Page_Specific_Code_Handler::enqueue_page_specific_css($snippet_id, $css, $location)) {
				return;
			}

			// Try eCommerce Code Handler
			if (Nexter_ECommerce_Code_Handler::enqueue_ecommerce_css($snippet_id, $css, $location)) {
				return;
			}
		}

		/**
		 * Enqueue JavaScript at specified location using specialized handlers
		 */
		private static function enqueue_js_at_location($snippet_id, $js, $location) {
			// Try Global Code Handler first
			if (Nexter_Global_Code_Handler::enqueue_global_js($snippet_id, $js, $location)) {
				return;
			}

			// Try Page-Specific Code Handler
			if (Nexter_Page_Specific_Code_Handler::enqueue_page_specific_js($snippet_id, $js, $location)) {
				return;
			}

			// Try eCommerce Code Handler
			if (Nexter_ECommerce_Code_Handler::enqueue_ecommerce_js($snippet_id, $js, $location)) {
				return;
			}
		}

		/**
		 * Output HTML at specified location using specialized handlers
		 */
		private static function output_html_at_location($snippet_id, $html, $location) {
			// Try Global Code Handler first
			if (Nexter_Global_Code_Handler::output_global_html($snippet_id, $html, $location)) {
				return;
			}

			// Try Page-Specific Code Handler
			if (Nexter_Page_Specific_Code_Handler::output_page_specific_html($snippet_id, $html, $location)) {
				return;
			}

			// Try eCommerce Code Handler
			if (Nexter_ECommerce_Code_Handler::output_ecommerce_html($snippet_id, $html, $location)) {
				return;
			}
		}

		/**
		 * Check if location is an advanced content insertion type
		 */
		private static function is_advanced_content_location($location) {
			$advanced_locations = [
				'insert_after_words',
				'insert_every_words', 
				'insert_middle_content',
				'insert_after_25',
				'insert_after_75',
				'insert_after_33', 
				'insert_after_66',
				'insert_after_80'
			];
			
			return in_array($location, $advanced_locations);
		}

		/**
		 * Insert content at advanced locations (word-based, percentage-based, etc.)
		 */
		private static function insert_content_at_advanced_location($content, $insert_content, $location, $snippet_id) {
			// All advanced content locations are Pro features
			// Route them through the Pro plugin filter system
			return apply_filters('nexter_process_pro_content_insertion', $content, $location, $insert_content, $snippet_id);
		}

		/**
		 * Insert content at a specific percentage of the total content
		 * This is a Pro feature - should only be called by Pro plugin
		 */
		private static function insert_at_content_percentage($content, $insert_content, $percentage) {
			// This method should only be used by the Pro plugin
			// If we reach here without Pro plugin, return original content
			return $content;
		}

		/**
		 * Map location values to WordPress hooks - Delegated to handlers
		 * @deprecated Use specialized handlers instead
		 */
		private static function get_hook_from_location($location) {
			// Check handlers for hook mapping
			$global_hooks = Nexter_Global_Code_Handler::get_global_location_hooks();
			if (isset($global_hooks[$location])) {
				return $global_hooks[$location];
			}

			$page_hooks = Nexter_Page_Specific_Code_Handler::get_page_specific_location_hooks();
			if (isset($page_hooks[$location])) {
				return $page_hooks[$location];
			}

			$ecommerce_hooks = Nexter_ECommerce_Code_Handler::get_ecommerce_location_hooks();
			if (isset($ecommerce_hooks[$location])) {
				return $ecommerce_hooks[$location];
			}

			return '';
		}

		/**
		 * Get appropriate CSS/JS enqueue location - Delegated to handlers
		 * @deprecated Use specialized handlers instead
		 */
		private static function get_enqueue_hook_for_location($location) {
			// Try handlers in order
			if (Nexter_Global_Code_Handler::is_global_location($location)) {
				return Nexter_Global_Code_Handler::get_global_enqueue_hook($location);
			}

			if (Nexter_Page_Specific_Code_Handler::is_page_specific_location($location)) {
				return Nexter_Page_Specific_Code_Handler::get_page_specific_enqueue_hook($location);
			}

			if (Nexter_ECommerce_Code_Handler::is_ecommerce_location($location)) {
				return Nexter_ECommerce_Code_Handler::get_ecommerce_enqueue_hook($location);
			}

			// Default fallback
			return 'wp_head';
		}

		public static function ob_callback( $output ) {
			// Early return for empty output
			if (empty($output) || strlen(trim($output)) === 0) {
				return $output;
			}
			
			// Route to Pro plugin for CSS selector processing if available
			$pro_processed_output = apply_filters('nexter_process_pro_css_selector_output', $output);
			if ($pro_processed_output !== $output) {
				return $pro_processed_output;
			}
			
			// Free plugin fallback - no CSS selector processing in Free version
				return $output;
		}

		public static function insert_output_by_location( $location, $output, &$element ) {
			// This method is now handled by the Pro plugin for CSS selector locations
			// Free plugin no longer performs DOM manipulation for Pro CSS selector locations
		}

		
		/*
		 * Get Code Snippets Php Execute
		 * @since 1.0.4
		 */
		public function nexter_code_php_snippets_actions(){
			global $wpdb;
			
			$code_snippet = 'nxt-code-type';
			
			$join_meta = "pm.meta_value = 'php'";
			
			$nxt_option = 'nxt-build-get-data';
			$get_data = get_option( $nxt_option );
			
			if( $get_data === false ){
				$get_data = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => '','code_updated' => ''];
				add_option( $nxt_option, $get_data );
			}

			$posts = [];
			if(!empty($get_data) && isset($get_data['saved']) && ((isset($get_data['code_updated']) && $get_data['saved'] !== $get_data['code_updated'])) || !isset($get_data['code_updated'])){
				
				$sqlquery = "SELECT p.ID, pm.meta_value FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = %s) AND p.post_type = %s AND p.post_status = 'publish' AND ( {$join_meta} ) ORDER BY p.post_date DESC";
				
				$sql3 = $wpdb->prepare( $sqlquery , [ $code_snippet, self::$snippet_type] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				
				$posts  = $wpdb->get_results( $sql3 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				$get_data['code_updated'] = $get_data['saved'];
				$get_data[ 'code_snippet' ] = $posts;
				update_option( $nxt_option, $get_data );

			}else if( isset($get_data[ 'code_snippet' ]) && !empty($get_data[ 'code_snippet' ])){
				$posts = $get_data[ 'code_snippet' ];
			}
			
			$php_snippet_filter = apply_filters('nexter_php_codesnippet_execute',true);
			if( !empty($posts) && !empty($php_snippet_filter)){
				foreach ( $posts as $post_data ) {
					
					$get_layout_type = get_post_meta( $post_data->ID , $code_snippet, false );
					
					if(!empty($get_layout_type) && !empty($get_layout_type[0]) && 'php' == $get_layout_type[0]){
						$post_id = isset($post_data->ID) ? $post_data->ID : '';
						
						if(!empty($post_id)){
							// Skip shortcode insertion type for auto execution
							$insertion_type = get_post_meta($post_id, 'nxt-code-insertion', true);
							if (!empty($insertion_type) && $insertion_type == 'shortcode') {
								continue;
							}

							// Validate eCommerce location before proceeding
							$location = get_post_meta($post_id, 'nxt-code-location', true);
							if (!$this->validate_ecommerce_location($location)) {
								continue; // Skip this snippet if invalid eCommerce location
							}

							// Check Pro restrictions (device and scheduling)
							if (self::should_skip_due_to_pro_restrictions($post_id)) {
								continue; // Skip this snippet due to Pro restrictions
							}

							// Smart Conditional Logic check
							$smart_conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
							if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
								if(!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
									continue; // Skip this snippet, Smart Conditional Logic not met
								}
							}

							$code_status = get_post_meta( $post_id, 'nxt-code-status', true );
							
							$authorID = get_post_field( 'post_author', $post_id );
							$theAuthorDataRoles = get_userdata($authorID);
							$theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
							
							if ( in_array( 'administrator', $theRolesAuthor ) && !empty($code_status)) {
								$php_code = get_post_meta( $post_id, 'nxt-php-code', true );
								$code_execute = get_post_meta( $post_id, 'nxt-code-execute', true );
								$code_hidden_execute = get_post_meta( $post_id, 'nxt-code-php-hidden-execute', true );

								// Security check: Only proceed if PHP execution is explicitly enabled
								if(!empty($code_hidden_execute) && $code_hidden_execute === 'yes' && !empty($php_code)){
									self::$snippet_loaded_ids['php'][] = $post_id;
									
									// Check if using new location system (frontend_only, admin_only, run_everywhere, etc.)
									if (!empty($location)) {
										// Use new location-based system with specialized handlers
										// This will handle run_everywhere, frontend_only, admin_only with immediate execution
										self::execute_php_at_location($post_id, $php_code, $location);
									} 
									// Use old system with direct execution like the original implementation
									else if (!empty($code_execute)) {
										if($code_execute=='global'){
											// Execute immediately for global
											Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id);
										}else if(is_admin() && $code_execute=='admin'){
											// Execute immediately for admin
											Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id);
										}else if(! is_admin() && $code_execute=='front-end'){
											// Execute immediately for front-end
											Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($php_code, $post_id);
										}
									}
								}
							}
						}
					}
					
				}
			}
		}
		
		/**
		 * Enhanced HTML hooks actions supporting both old system and new location system
		 */
		public function nexter_ext_code_execute( $post_id = 0 ){
			
			$is_active = get_post_meta($post_id, 'nxt-code-status', true);
			if($is_active != '1'){
				return;
			}
			
			// Check Pro restrictions (device and scheduling)
			if (self::should_skip_due_to_pro_restrictions($post_id)) {
				return; // Skip this snippet due to Pro restrictions
			}

			// Smart Conditional Logic check
			$smart_conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
			if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
				if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
					return; // Skip this snippet, Smart Conditional Logic not met
				}
			}
			
			$location = get_post_meta($post_id, 'nxt-code-location', true);
			
			// Validate eCommerce location before proceeding
			if (!$this->validate_ecommerce_location($location)) {
				return; // Skip execution if invalid eCommerce location
			}

			$type = get_post_meta($post_id, 'nxt-code-type', true);
			if (empty($type)) {
				return;
			}

			// Get code content based on type
			$code = '';
			switch ($type) {
				case 'php':
					$code = get_post_meta($post_id, 'nxt-php-code', true);
					break;
				case 'css':
					$code = get_post_meta($post_id, 'nxt-css-code', true);
					break;
				case 'javascript':
					$code = get_post_meta($post_id, 'nxt-javascript-code', true);
					break;
				case 'htmlmixed':
					$code = get_post_meta($post_id, 'nxt-htmlmixed-code', true);
					break;
			}

			if (empty($code)) {
				return;
			}

			// Execute based on location and type
			if (!empty($location)) {
				// New location-based system
				switch ($type) {
					case 'php':
						self::execute_php_at_location($post_id, $code, $location);
						break;
					case 'css':
						self::enqueue_css_at_location($post_id, $code, $location);
						break;
					case 'javascript':
						self::enqueue_js_at_location($post_id, $code, $location);
						break;
					case 'htmlmixed':
						self::output_html_at_location($post_id, $code, $location);
						break;
				}
			}
		}

					/**
		 * Initialize CSS Selector functionality (Enhanced based on reference implementation)
		 */
		public function init_css_selector_functionality() {
			// Only on frontend
			if (is_admin()) {
				return;
			}

			// Get snippets that use CSS selector targeting
			$css_selector_snippets = self::get_css_selector_snippets();
			
			if (!empty($css_selector_snippets)) {
				
				// Populate the snippet output array early
				self::populate_snippet_output($css_selector_snippets);
				
				// Enhanced output buffering approach that works with existing buffers
				add_action('template_redirect', function() {
					if (!headers_sent()) {
						$current_level = ob_get_level();
						
						// Start our output buffer regardless of existing levels
						ob_start(array('Nexter_Builder_Code_Snippets_Render', 'ob_callback'));
						
						// Store the level we started at
						if (!defined('NEXTER_CSS_OB_LEVEL')) {
							define('NEXTER_CSS_OB_LEVEL', ob_get_level());
						}
					}
				}, 1);
				
				// Ensure proper cleanup only for our buffer
				add_action('wp_footer', function() {
					// Only end our specific buffer level
					if (defined('NEXTER_CSS_OB_LEVEL') && ob_get_level() >= NEXTER_CSS_OB_LEVEL) {
						// End only our buffer, leave others intact
						while (ob_get_level() >= NEXTER_CSS_OB_LEVEL) {
							ob_end_flush();
						}
					}
				}, 999);
				
			}
		}

		/**
		 * Get snippets configured for CSS selector targeting
		 * Routes Pro CSS selector locations to Pro plugin
		 */
		private static function get_css_selector_snippets() {
			// Route to Pro plugin if available
			$pro_snippets = apply_filters('nexter_get_pro_css_selector_snippets', array(), self::$snippet_type);
			if (!empty($pro_snippets)) {
				return $pro_snippets;
			}
			
			// Free plugin fallback - only handle non-Pro locations
			$css_selector_snippets = array();
				
			// Note: Pro CSS selector locations are handled by Pro plugin
			// Free plugin only handles basic locations if any exist in the future
			
			return $css_selector_snippets;
		}

		/**
		 * Map CSS selector location to DOM manipulation position
		 * Routes Pro CSS selector locations to Pro plugin
		 */
		private static function map_css_location_to_position($location) {
			// Route to Pro plugin if available
			$pro_position = apply_filters('nexter_map_pro_css_location_to_position', null, $location);
			if ($pro_position !== null) {
				return $pro_position;
			}
			
			// Free plugin fallback for basic locations (if any exist in the future)
			$mapping = array();
			
			return isset($mapping[$location]) ? $mapping[$location] : 'after';
		}

		/**
		 * Check if a snippet should execute (device, schedule, conditional logic checks)
		 * Pro restrictions are handled by Pro plugin via filters
		 */
		private static function should_snippet_execute($snippet_id) {
			// Check Pro restrictions via filters (device and scheduling)
			if (self::should_skip_due_to_pro_restrictions($snippet_id)) {
				return false;
			}

			// Smart Conditional Logic check
			$smart_conditions = get_post_meta($snippet_id, 'nxt-smart-conditional-logic', true);
			if (!empty($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
				if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
					return false; // Skip this snippet, Dynamic Conditional Logic not met
				}
			}

			// Status check
			$is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
			if ($is_active != '1') {
				return false;
			}

			return true;
		}

		/**
		 * Populate the snippet output array with CSS selector snippets
		 * Routes to Pro plugin for Pro CSS selector locations
		 */
		private static function populate_snippet_output($css_selector_snippets) {
			// Route to Pro plugin if available
			$pro_output = apply_filters('nexter_populate_pro_css_snippet_output', array(), $css_selector_snippets);
			if (!empty($pro_output)) {
				self::$snippet_output = $pro_output;
				return;
				}
				
			// Free plugin fallback for basic locations (if any exist in the future)
			self::$snippet_output = array();
		}

			private function get_memberpress_memberships() {
		$memberships = array();
		
		// Check if MemberPress is active
		if ( class_exists('MeprProduct') ) {
			// Get all MemberPress products (memberships)
			$args = array(
				'post_type' => 'memberpressproduct',
				'posts_per_page' => -1,
				'post_status' => 'publish'
			);
			
			$membership_posts = get_posts( $args );
			
			foreach ( $membership_posts as $membership ) {
				$memberships[] = array(
					'value' => $membership->ID,
					'label' => $membership->post_title
				);
			}
		}
		
		return $memberships;
	}

		/**
		 * Check Pro restrictions (device type and scheduling)
		 * Routes to Pro plugin if available, otherwise skips Pro restrictions
		 * 
		 * @param int $snippet_id The snippet ID
		 * @return bool True if snippet should be skipped due to Pro restrictions
		 */
		private static function should_skip_due_to_pro_restrictions($snippet_id) {
			if (!defined('NXT_PRO_EXT')) {
				// No Pro plugin, so no Pro restrictions to check
				return false;
			}
			
			// Check device restrictions via Pro plugin
			$should_skip_device = apply_filters('nexter_check_pro_device_restrictions', false, $snippet_id);
			if ($should_skip_device) {
				return true;
			}
			
			// Check schedule restrictions via Pro plugin
			$should_skip_schedule = apply_filters('nexter_check_pro_schedule_restrictions', false, $snippet_id);
			if ($should_skip_schedule) {
				return true;
			}
			
			return false;
		}

		/**
		 * Check if current page is in Elementor edit or preview mode
		 * This prevents frontend_only snippets from running in Elementor editor or preview
		 */
		private static function is_elementor_edit_or_preview_mode() {
			if (class_exists('\\Elementor\\Plugin')) {
				$plugin = \Elementor\Plugin::$instance;
				if ((isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) ||
					(isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode())) {
					return true;
				}
			}
			if ((isset($_GET['elementor-preview']) && $_GET['elementor-preview']) ||
				(isset($_GET['elementor']) && $_GET['elementor'])) {
				return true;
			}
			return false;
		}

		/**
		 * Check for caching plugins and add compatibility
		 */
		public function init_cache_compatibility() {
			// Detect common caching plugins
			$caching_plugins = array(
				'W3 Total Cache' => 'w3-total-cache/w3-total-cache.php',
				'WP Rocket' => 'wp-rocket/wp-rocket.php', 
				'WP Super Cache' => 'wp-super-cache/wp-cache.php',
				'LiteSpeed Cache' => 'litespeed-cache/litespeed-cache.php',
				'Autoptimize' => 'autoptimize/autoptimize.php'
			);
			
			$active_caching = array();
			foreach ($caching_plugins as $name => $plugin_path) {
				if (is_plugin_active($plugin_path)) {
					$active_caching[] = $name;
				}
			}
			
			// Add cache-busting for dynamic content if caching is detected
			if (!empty($active_caching)) {
				add_action('wp_head', function() use ($active_caching) {
					echo "<!-- Nexter Extension: Detected caching: " . implode(', ', $active_caching) . " -->\n";
				}, 1);
				
				// Use more aggressive hook timing for cached sites
				add_action('template_redirect', function() {
					// Force early execution of snippets before cache can interfere
					do_action('nexter_early_snippet_execution');
				}, 1);
			}
		}
	}
}
Nexter_Builder_Code_Snippets_Render::get_instance();