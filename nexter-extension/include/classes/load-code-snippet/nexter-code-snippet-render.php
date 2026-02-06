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
require_once NEXTER_EXT_DIR . 'include/classes/load-code-snippet/nexter-code-file-snippet.php';
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

	private static $file_based_instance = null;

	private static $storage_dir_cache = null;

	private static $file_count_cache = null;

	private static $code_snippets_enabled_cache = null;

	private static $file_code_list_cache_with_meta = null;

		private static $file_code_list_cache_without_meta = null;

	private static $glob_file_count_cache = null;

		/**
		 * Check if code snippets functionality is enabled (with cache)
		 */
		private function is_code_snippets_enabled() {
			if ( self::$code_snippets_enabled_cache !== null ) {
				return self::$code_snippets_enabled_cache;
			}
			$get_opt = get_option('nexter_extra_ext_options');
			$code_snippets_enabled = true;

			if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
				$code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
			}

			self::$code_snippets_enabled_cache = $code_snippets_enabled;
			return $code_snippets_enabled;
		}

		/**
		 * Check if WP_CONTENT_DIR is writable (pre-check for file operations)
		 *
		 * @return bool True if writable, false otherwise
		 */
		private static function check_content_dir_writable() {
			// Use is_writable() as wp_is_writable() may not exist in all WordPress versions
			if ( function_exists( 'wp_is_writable' ) ) {
				return wp_is_writable( WP_CONTENT_DIR );
			}
			return is_writable( WP_CONTENT_DIR );
		}

	/**
	 * Get file_based instance (cached) - static version for use in static methods
	 * 
	 * @return object|null File based instance or null
	 */
	private static function get_file_based_instance_static() {
		if ( self::$file_based_instance !== null ) {
			return self::$file_based_instance;
		}

		if ( class_exists('Nexter_Code_Snippets_File_Based') ) {
			self::$file_based_instance = new Nexter_Code_Snippets_File_Based();
		}

		return self::$file_based_instance;
	}

	/**
	 * Get file_based instance (cached) - instance method wrapper
	 * 
	 * @return object|null File based instance or null
	 */
	private function get_file_based_instance() {
		return self::get_file_based_instance_static();
	}

	/**
	 * Get storage directory (cached)
	 */
	private function get_storage_directory() {
		if ( self::$storage_dir_cache !== null ) {
			return self::$storage_dir_cache;
		}

		$file_based = $this->get_file_based_instance();
		if ( $file_based ) {
			self::$storage_dir_cache = $file_based::getfileDir();
		}

		return self::$storage_dir_cache;
	}

	/**
	 * Get file code list with metadata (cached)
	 * 
	 * @return array File code list with metadata
	 */
	private function get_file_code_list_with_meta() {
		if ( self::$file_code_list_cache_with_meta !== null ) {
			return self::$file_code_list_cache_with_meta;
		}

		$file_based = $this->get_file_based_instance();
		if ( $file_based ) {
			self::$file_code_list_cache_with_meta = $file_based->getListCode(true);
		} else {
			self::$file_code_list_cache_with_meta = array();
		}

		return self::$file_code_list_cache_with_meta;
	}

	/**
	 * Get file code list without metadata (cached)
	 * 
	 * @return array File code list without metadata
	 */
	private function get_file_code_list_without_meta() {
		if ( self::$file_code_list_cache_without_meta !== null ) {
			return self::$file_code_list_cache_without_meta;
		}

		$file_based = $this->get_file_based_instance();
		if ( $file_based ) {
			self::$file_code_list_cache_without_meta = $file_based->getListCode(false);
		} else {
			self::$file_code_list_cache_without_meta = array();
		}

		return self::$file_code_list_cache_without_meta;
	}

		/**
		 * Get file count for snippet naming (cached)
		 */
		private function get_file_count() {
			if ( self::$file_count_cache !== null ) {
				return self::$file_count_cache;
			}

			$storageDir = $this->get_storage_directory();
			if ( ! $storageDir ) {
				self::$file_count_cache = 1;
				return 1;
			}

			$fileCount = count(glob($storageDir . '/*.php'));
			if ( ! $fileCount ) {
				$fileCount = 1;
			}

			self::$file_count_cache = $fileCount;
			return $fileCount;
		}

		/**
		 * Generate file name from title (optimized)
		 */
		private function generate_snippet_filename( $title, $fileCount = null ) {
			if ( $fileCount === null ) {
				$fileCount = $this->get_file_count();
			}

			// Get the first 4 words of the snippet name
			$nameArr = explode(' ', $title);
			if ( count($nameArr) > 4 ) {
				$nameArr = array_slice($nameArr, 0, 4);
			}
			$fileTitle = implode(' ', $nameArr);
			$fileTitle = sanitize_title($fileTitle, 'snippet');

			$fileName = $fileCount . '-' . $fileTitle . '.php';
			return sanitize_file_name($fileName);
		}

		/**
		 * Build metadata array from snippet data (optimized)
		 */
		private function build_snippet_metadata( $snippet, $title = '' ) {
			if ( empty($title) && isset($snippet['name']) ) {
				$title = sanitize_text_field(wp_unslash($snippet['name']));
			}

			return [
				'name' => $title,
				'status' => 'publish',
				'tags' => isset($snippet['tags']) ? $snippet['tags'] : [],
				'description' => isset($snippet['description']) ? $snippet['description'] : '',
				'type' => isset($snippet['type']) ? $snippet['type'] : '',
				'condition' => [
					'insertion' => isset($snippet['insertion']) ? $snippet['insertion'] : 'auto',
					'css_selector' => isset($snippet['css_selector']) ? $snippet['css_selector'] : '',
					'element_index' => isset($snippet['element_index']) ? $snippet['element_index'] : 0,
					'location' => isset($snippet['location']) ? $snippet['location'] : '',
					'priority' => isset($snippet['hooksPriority']) ? $snippet['hooksPriority'] : 10,
					'word_count' => isset($snippet['word_count']) ? $snippet['word_count'] : 100,
					'word_interval' => isset($snippet['word_interval']) ? $snippet['word_interval'] : 200,
					'post_number' => isset($snippet['post_number']) ? $snippet['post_number'] : 1,
					'customname' => isset($snippet['customname']) ? $snippet['customname'] : '',
					'compresscode' => isset($snippet['compresscode']) ? $snippet['compresscode'] : false,
					'startDate' => isset($snippet['startDate']) ? $snippet['startDate'] : '',
					'endDate' => isset($snippet['endDate']) ? $snippet['endDate'] : '',
					'shortcodeattr' => isset($snippet['shortcodeattr']) ? $snippet['shortcodeattr'] : [],
					'html_hooks' => isset($snippet['htmlHooks']) ? $snippet['htmlHooks'] : '',
					'in-sub-rule' => isset($snippet['in_sub_data']) ? $snippet['in_sub_data'] : [],
					'ex-sub-rule' => isset($snippet['ex_sub_data']) ? $snippet['ex_sub_data'] : [],
					'status' => 0,
					'smart-logic' => isset($snippet['smart_conditional_logic']) ? $snippet['smart_conditional_logic'] : [],
					'php-hidden-execute' => isset($snippet['php_hidden_execute']) ? $snippet['php_hidden_execute'] : 'no',
				],
			];
		}

		/**
		 * Process PHP code (add <?php if needed, remove ?>)
		 */
		private function process_php_code( $lang_code, $type = 'php' ) {
			if ( $type !== 'php' ) {
				return $lang_code;
			}

			// Check if the code starts with <?php
			if ( preg_match('/^<\?php/', $lang_code) ) {
				return $lang_code; // Already has <?php
			}

			$lang_code = rtrim($lang_code, '?>');
			return '<?php' . PHP_EOL . $lang_code;
		}

		/**
		 * Update cache option (optimized)
		 * 
		 * @param string $cache_option Option name
		 * @return void
		 */
		private function update_cache_option( $cache_option = 'nxt-build-get-data' ) {
			$get_data = get_option($cache_option);
			if ( $get_data === false ) {
				$value = [
					'saved' => strtotime('now'),
					'singular_updated' => '',
					'archives_updated' => '',
					'sections_updated' => '',
					'code_updated' => ''
				];
				add_option( $cache_option, $value );
			} elseif ( ! empty($get_data) ) {
				$get_data['saved'] = strtotime('now');
				update_option( $cache_option, $get_data, false );
			}
		}

	/**
	 * Clear file-based caches
	 * 
	 * @return void
	 */
	private function clear_file_based_cache() {
		self::$file_count_cache = null;
		// Note: We don't clear file_based_instance and storage_dir_cache as they're stable
	}

	/**
	 * Sanitize and unslash POST data (optimized helper)
	 * 
	 * @param string $key POST key
	 * @param mixed $default Default value if not set
	 * @return mixed Sanitized value
	 */
	private function sanitize_post( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Check if class exists (cached)
	 * 
	 * @param string $class_name Class name to check
	 * @return bool
	 */
	private static $class_exists_cache = [];

	private function class_exists_cached( $class_name ) {
		if ( ! isset( self::$class_exists_cache[ $class_name ] ) ) {
			self::$class_exists_cache[ $class_name ] = class_exists( $class_name );
		}
		return self::$class_exists_cache[ $class_name ];
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
			
			//if(!is_admin()){
			//	add_action( 'wp', array( $this, 'get_snippet_ids' ), 1 );
			//}
				
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
				add_action( 'admin_init', array( $this, 'migrate_post_snippets_to_file_based' ), 1 );
			}

			// Execute PHP snippets with immediate bypass for REST API registration
			if((!isset($_GET['test_code']) || empty($_GET['test_code']))){ // phpcs:ignore WordPress.Security.NonceVerification.Recommended, handled by the core method already.
				//add_action( 'wp', array( $this, 'nexter_php_execution_snippet' ), 1 );
				$this->nexter_php_execution_snippet();
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
			//add_action( 'init', array( $this, 'home_page_code_execute' ) );
			
			// Initialize CSS Selector functionality
			add_action( 'wp', array( $this, 'init_css_selector_functionality' ), 4 );
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

		/* public function home_page_code_execute(){
			if(isset($_GET['test_code']) && $_GET['test_code']=='code_test' && isset($_GET['code_id']) && !empty($_GET['code_id'])){ // phpcs:ignore WordPress.Security.NonceVerification.Recommended, handled by the core method already.
				$code_id = isset($_GET['code_id']) ? sanitize_text_field(wp_unslash($_GET['code_id'])) : '';
				$this->nexter_code_test_php_snippets($code_id);
			}
		} */

		/*
		 * Get Code Snippets Php Execute
		 * @since 1.0.4
		 */
		/* public function nexter_code_test_php_snippets( $post_id = null){
			
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
		} */
		
		/**
		 * List of Data Get Load Snippets
		 */
		/* public function get_snippet_ids(){
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
		} */

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
			/* if (!empty($get_result) && !empty($type)) {
				$fallback_results = self::get_snippets_fallback($type);
				if (!empty($fallback_results)) {
					// Merge and remove duplicates
					$get_result = array_unique(array_merge($get_result, $fallback_results));
				}
			} */
			
			return $get_result;
		}

		/**
		 * Enqueue script admin area.
		 *
		 * @since 2.0.0
		 */
		public function enqueue_scripts_admin( $hook_suffix ) {
			
			// Code Snippet Dashboard enquque
			if ( strpos( $hook_suffix, 'nxt_code_snippets' ) === false ) {
				return;
			}else if ( ! str_contains( $hook_suffix, 'nxt_code_snippets' ) ) {
				return;
			}

			wp_enqueue_style( 'nxt-code-snippet-style', NEXTER_EXT_URL . 'assets/css/admin/nxt-code-snippet.min.css', array(), NEXTER_EXT_VER, 'all' );
			//wp_enqueue_style( 'nxt-code-snippet-style', NEXTER_EXT_URL . 'code-snippets/build/index.css', array(), NEXTER_EXT_VER, 'all' );

			wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'assets/js/admin/index.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash', 'wp-i18n' ), NEXTER_EXT_VER, true );
			
			// Attach JavaScript translations
            wp_set_script_translations(
                'nxt-code-snippet',  // Handle must match enqueue
                'nexter-extension',	// Your text domain
                NEXTER_EXT_DIR . 'languages'
            );
			//wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'code-snippets/build/index.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash' ), NEXTER_EXT_VER, true );

			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			
			// Get dynamic post types and taxonomies
			$post_types_data = $this->get_dynamic_post_types();
			$taxonomies_data = $this->get_dynamic_taxonomies();
			$page_templates_data = $this->get_dynamic_page_templates();


			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        	$pluginslist = get_plugins();

			$extensioninstall = false;
			if ( isset( $pluginslist[ 'nexter-extension/nexter-extension.php' ] ) && !empty( $pluginslist[ 'nexter-extension/nexter-extension.php' ] ) ) {
				if( is_plugin_active('nexter-extension/nexter-extension.php') ){
					$extensioninstall = true;
				}
			}

			wp_localize_script(
				'nxt-code-snippet',
				'nxt_code_snippet_data',
				array(
					'adminUrl' => admin_url(),
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'nxt-code-snippet' ),
					'nxt_url' => NEXTER_EXT_URL.'code-snippets/',
					'assets' => NEXTER_EXT_URL . 'assets/',
					'htmlHooks' => class_exists('Nexter_Builder_Display_Conditional_Rules') ? Nexter_Builder_Display_Conditional_Rules::get_sections_hooks_options() : [],
					'in_ex_option' => class_exists('Nexter_Builder_Display_Conditional_Rules') ? Nexter_Builder_Display_Conditional_Rules::get_location_rules_options() : [],
					'user_role' => class_exists('Nexter_Builder_Display_Conditional_Rules') ?Nexter_Builder_Display_Conditional_Rules::get_others_location_sub_options('user-roles') : [],
					'post_types' => $post_types_data,
					'taxonomies' => $taxonomies_data,
					'page_templates' => $page_templates_data,
					'whiteLabel' => get_option('nexter_white_label'),
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
					'cs_ec_required_icon' => NEXTER_EXT_URL . 'assets/images/cs_ec_require.svg',
					'extensioninstall' => $extensioninstall,
					'nxtExtra' => get_option('nexter_extra_ext_options'),
					'dashboard_url' => admin_url( 'admin.php?page=nexter_welcome' ),
				)
			);
		}

		/**
         * AJAX endpoint to fetch taxonomy terms for Dynamic Conditional Logic
		 */
		public function get_taxonomy_terms_ajax() {
			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
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
			wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
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
			wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
		}
		
		check_ajax_referer('nxt-code-snippet', 'nonce');
		$snippet_list = [];
		$file_code_list = $this->get_file_code_list_with_meta();
		$search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
		if($file_code_list){
			foreach($file_code_list as $code){
				if(!empty($search_query) && stripos($code['name'], $search_query) === false){
					continue;
				}
				$snippet_list[] = [
					'id' => $code['id'],
					'name' => $code['name'],
					'type' => isset($code['type']) ? $code['type'] : 'php',
					'status' => $code['status'] ? 'active' : 'inactive'
				];
			}
		}
		
		/* $search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
		
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
				$post_id = get_the_ID();
				// Batch get_post_meta calls for better performance
				$all_meta = get_post_meta($post_id);
				$type = isset($all_meta['nxt-code-type'][0]) ? $all_meta['nxt-code-type'][0] : '';
				$status = isset($all_meta['nxt-code-status'][0]) ? $all_meta['nxt-code-status'][0] : '';
				
				$snippet_list[] = [
					'id' => $post_id,
					'name' => get_the_title(),
					'type' => $type ?: 'unknown',
					'status' => $status ? 'active' : 'inactive'
				];
			}
			wp_reset_postdata();
		} */
	
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

		public function sanitizeMetaValue($value) {
			if (is_numeric($value)) {
				return $value;
			}

			if (!$value) {
				return $value;
			}

			if (str_contains($value, '*/')) {
				$value = str_replace('*/', '', $value);
			}

			return $value;
		}

		private function parseInputMeta($metaData, $convertString = false) {
			$metaDefaults = [
				'name'         => 'Snippet Created @ ' . current_time('mysql'),
				'description'  => '',
				'tags'         => '',
				'type'         => 'php',
				'status'       => 'draft',
				'created_by'   => get_current_user_id(),
				'created_at'   => gmdate('Y-m-d H:i:s'),
				'updated_at'   => gmdate('Y-m-d H:i:s'),
				'updated_by'   => get_current_user_id(),
				'condition'    => [
					'status' => 0,
					'priority'     => 10,
				]
			];

			//$metaData = array_intersect_key($metaData, array_keys($metaDefaults));
			$metaData = wp_parse_args($metaData, $metaDefaults);

			if (!$convertString) {
				return $metaData;
			}

			$docBlockString = '<?php' . PHP_EOL . '// <Internal Start>' . PHP_EOL . '/*' . PHP_EOL . '*';

			foreach ($metaData as $key => $value) {
				if (is_array($value)) {
					$value = json_encode($value);
				}
				$docBlockString .= PHP_EOL . '* @' . $key . ': ' . $this->sanitizeMetaValue($value);
			}

			$docBlockString .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL . '<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL;

			return $docBlockString;
		}
		
		
		/**
		 * Create New Snippet Data
		 */
		public function create_new_snippet(){
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
			if ( ! self::check_content_dir_writable() ) {
				wp_send_json_error(__('File-based snippets require write access. This environment restricts file creation.', 'nexter-extension'));
				return;
			}

			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}
			
			check_ajax_referer('nxt-code-snippet', 'nonce');

			if ( isset($_POST['title']) ) {
				$title = sanitize_text_field(wp_unslash($_POST['title']));
				if(empty($title)){
					wp_send_json_error(__('Enter Name Snippet', 'nexter-extension'));
				}
				$file_based = $this->get_file_based_instance();
				if ( $file_based ) {
					$storageDir = $this->get_storage_directory();
					if ( $storageDir ) {
						$cacheFile = $storageDir . '/nxt-snippet-list.php';
						if ( ! is_dir($cacheFile) ) {
							wp_mkdir_p( dirname( $cacheFile ) );
						}

						$fileName = $this->generate_snippet_filename( $title );
						$file = $storageDir . '/' . $fileName;
						
						// Security: Validate file path to prevent directory traversal
						$real_file = realpath( dirname( $file ) );
						$real_storage = realpath( $storageDir );
						if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
							wp_send_json_error(__('Invalid file path detected.', 'nexter-extension'));
							return;
						}

						if ( is_file($file) ) {
							wp_send_json_error(__('File already exists. Please try a different name.', 'nexter-extension'));
							return;
						}
						
						$metaData = [
							'name' => $title,
							'status' => 'publish',
						];
						$metaData = $this->add_update_metadata($metaData);
						$lang_code = isset($metaData['lang-code']) ? $metaData['lang-code'] : '';
						unset($metaData['lang-code']);

						// Validate PHP code
						if ( $metaData['type'] == 'php' && preg_match('/^<\?php/', $lang_code) ) {
							wp_send_json_error(__('Please remove <?php from the beginning of the code', 'nexter-extension'));
							return;
						}

						$lang_code = $this->process_php_code( $lang_code, $metaData['type'] );
						$docBlockString = $this->parseInputMeta($metaData, true);
						$fullCode = $docBlockString . $lang_code;

						if ( file_put_contents($file, $fullCode) ) {
							$this->clear_file_based_cache(); // Clear cache after file creation
							$file_based->snippetIndexData();
						} else {
							wp_send_json_error(__('Failed to create snippet file.', 'nexter-extension'));
							return;
						}
					}
				}

				/* $new_post = array(
					'post_title' => $title,
					'post_status' => 'publish',
					'post_type' => self::$snippet_type,
				);
		
				$post_id = wp_insert_post($new_post); */
				$id = preg_replace('/\.php$/', '', $fileName);
				wp_send_json_success(['id' => $id, 'message' => __('Snippet Created Successfully.', 'nexter-extension')]);
				/* if ($post_id) {
					$this->add_update_metadata($post_id);
					wp_send_json_success(['id' => $post_id, 'message' => __('Snippet Created Successfully.', 'nexter-extension')]);
				} else {
					wp_send_json_error(__('Failed to Create Snippet.', 'nexter-extension'));
				} */
			} else {
				wp_send_json_error(__('Missing required fields.', 'nexter-extension'));
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
	public function add_update_metadata($metaData = []){
		check_ajax_referer('nxt-code-snippet', 'nonce');
		//if($post_id){

			// Update cache option using optimized helper
			$this->update_cache_option();
				
				$type = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
				if(!empty($type) && in_array($type, ['php','htmlmixed','css','javascript'])){
					$metaData['type'] = $type;
				//	update_post_meta( $post_id , 'nxt-code-type', $type );
				}

				$insertion = (isset($_POST['insertion']) && !empty($_POST['insertion'])) ? sanitize_text_field(wp_unslash($_POST['insertion'])) : 'auto';

			if( !empty($insertion) ){
				$metaData['condition']['insertion'] = $insertion;
				//update_post_meta( $post_id , 'nxt-code-insertion', $insertion );
			}

			$location = (isset($_POST['location']) && !empty($_POST['location'])) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
			
			// CSS Selector specific settings (always save these when they're provided)
			$css_selector = (isset($_POST['css_selector'])) ? sanitize_text_field(wp_unslash($_POST['css_selector'])) : '';
			$metaData['condition']['css_selector'] = $css_selector;
			//update_post_meta( $post_id , 'nxt-css-selector', $css_selector );

			$element_index = (isset($_POST['element_index'])) ? absint($_POST['element_index']) : 0;
			$metaData['condition']['element_index'] = $element_index;
			//update_post_meta( $post_id , 'nxt-element-index', $element_index );

				// If no location is provided, set default based on code type
				if (empty($location) && !empty($type)) {
					$location = $this->get_default_location_for_type($type);
				}
				
				if( !empty($location) ){
					// Validate eCommerce location before saving
					if ($this->validate_ecommerce_location($location)) {
						$metaData['condition']['location'] = $location;
						//update_post_meta( $post_id , 'nxt-code-location', $location );
					} else {
						// Reset to default if invalid eCommerce location
						$default_location = $this->get_default_location_for_type($type);
						$metaData['condition']['location'] = $default_location;
						//update_post_meta( $post_id , 'nxt-code-location', $default_location );
					}
				}

				$hooks_priority = (isset($_POST['hooks_priority']) && !empty($_POST['hooks_priority'])) ? absint($_POST['hooks_priority']) : 10;
				if(isset($hooks_priority)){
					$metaData['condition']['priority'] = $hooks_priority;
					//update_post_meta( $post_id ,'nxt-code-hooks-priority', $hooks_priority);
				}

				// Save word-based insertion settings
				$word_count = (isset($_POST['word_count']) && !empty($_POST['word_count'])) ? absint($_POST['word_count']) : 100;
				$metaData['condition']['word_count'] = $word_count;
				//update_post_meta( $post_id , 'nxt-insert-word-count', $word_count );

				$word_interval = (isset($_POST['word_interval']) && !empty($_POST['word_interval'])) ? absint($_POST['word_interval']) : 200;
				$metaData['condition']['word_interval'] = $word_interval;
				//update_post_meta( $post_id , 'nxt-insert-word-interval', $word_interval );

				// Save post number for Before X Post and After X Post locations
				$post_number = (isset($_POST['post_number']) && !empty($_POST['post_number'])) ? absint($_POST['post_number']) : 1;
				$metaData['condition']['post_number'] = $post_number;
				//update_post_meta( $post_id , 'nxt-post-number', $post_number );

				$customname = (isset($_POST['customname']) && !empty($_POST['customname']) ) ? sanitize_text_field(wp_unslash($_POST['customname'])) : '';
				$metaData['condition']['customname'] = $customname;
				//update_post_meta( $post_id , 'nxt-code-customname', $customname );

				$compresscode = isset($_POST['compresscode']) ? rest_sanitize_boolean(wp_unslash($_POST['compresscode'])) : false;
				$metaData['condition']['compresscode'] = $compresscode;
				//update_post_meta( $post_id , 'nxt-code-compresscode', $compresscode );

				$startDate = (isset($_POST['startDate']) && !empty($_POST['startDate'])) ? sanitize_text_field(wp_unslash($_POST['startDate'])) : '';
				$metaData['condition']['startDate'] = $startDate;
				//update_post_meta( $post_id , 'nxt-code-startdate', $startDate );

				$endDate = (isset($_POST['endDate']) && !empty($_POST['endDate'])) ? sanitize_text_field(wp_unslash($_POST['endDate'])) : '';
				$metaData['condition']['endDate'] = $endDate;
				//update_post_meta( $post_id , 'nxt-code-enddate', $endDate );

				$shortcodeattr = (isset($_POST['shortcodeattr']) && !empty($_POST['shortcodeattr'])) ? array_map('sanitize_text_field', explode(',', $_POST['shortcodeattr'])) : [];
				if(isset($shortcodeattr) ){
					if (is_array($shortcodeattr) && !empty($shortcodeattr)) {
						$metaData['condition']['shortcodeattr'] = $shortcodeattr;
						// update_post_meta($post_id, 'nxt-code-shortcodeattr', $shortcodeattr);
					}else{
						$metaData['condition']['shortcodeattr'] = [];
						//update_post_meta($post_id, 'nxt-code-shortcodeattr', []);
					}
				}


				$submit_error_log = [];
				if (isset($_POST['lang-code']) && !empty($type)) {
					$lang_code = '';
					if($type==='css'){
						$lang_code = wp_strip_all_tags(wp_unslash($_POST['lang-code']));
						$metaData['lang-code'] = $lang_code;
						//update_post_meta( $post_id ,'nxt-css-code', $lang_code);
					}else if($type=='javascript'){
						$lang_code = wp_unslash($_POST['lang-code']);
						$metaData['lang-code'] = $lang_code;
						//update_post_meta( $post_id ,'nxt-javascript-code', $lang_code);
					}else if($type=='htmlmixed'){
						$html_code = (isset($_POST['lang-code']) && !empty($_POST['lang-code'])) ? wp_unslash(stripslashes($_POST['lang-code'])) : '';
						$metaData['lang-code'] = $html_code;
						//update_post_meta( $post_id ,'nxt-htmlmixed-code', $html_code);

						if(!empty($html_code)){
							$error_log = $this->check_and_recover_html($html_code);
							if(!empty($error_log) && isset($error_log['error'])){
								$submit_error_log = $error_log['error'];
							}
						}

						$html_hooks = (isset($_POST['html_hooks']) && !empty($_POST['html_hooks'])) ? sanitize_text_field(wp_unslash($_POST['html_hooks'])) : '';
						if(isset($html_hooks)){
							$metaData['condition']['html_hooks'] = $html_hooks;
							//update_post_meta( $post_id ,'nxt-code-html-hooks', $html_hooks);
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
								$metaData['condition']['php-hidden-execute'] = 'yes';
								//update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'yes');
							} else {
								$metaData['condition']['php-hidden-execute'] = 'no';
								//update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
							}
						} else {
							$metaData['condition']['php-hidden-execute'] = 'no';
							//update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
						}

						$lang_code = wp_unslash($_POST['lang-code']);
						$metaData['lang-code'] = $lang_code;
						//update_post_meta($post_id, 'nxt-php-code', $lang_code);

						$code_execute = (isset($_POST['code-execute']) && !empty($_POST['code-execute'])) ? sanitize_text_field(wp_unslash($_POST['code-execute'])) : 'global';
						if(!empty($code_execute) && in_array($code_execute, ['global','admin','front-end'])){
							$metaData['condition']['code-execute'] = $code_execute;
							//update_post_meta($post_id, 'nxt-code-execute', $code_execute);
						}
						
						// For PHP snippets, validate before saving
						$executor = Nexter_Builder_Code_Snippets_Executor::get_instance();
						$validation_result = $executor->validate_php_snippet_on_save('', $lang_code);
						
						if (is_wp_error($validation_result)) {
							// Disable problematic snippet instead of returning error
							$metaData = $this->disable_problematic_snippet('', $metaData);
							
							// Still return error for user feedback, but snippet is now safely disabled
							wp_send_json_error([
								'id' => '',
								'message' => $validation_result->get_error_message() . ' (' . __('Snippet has been disabled for safety', 'nexter-extension') . ')',
								'code' => $validation_result->get_error_code(),
								'line_info' => __('Check the line numbers and suggestions above. Snippet is disabled until fixed.', 'nexter-extension')
							]);
							return;
						}else if($validation_result == true){
							$metaData['condition']['status'] = 1;
							$metaData['condition']['php-hidden-execute'] = 'yes';
						}
					}

					if($type==='css' || $type=='javascript' || $type=='htmlmixed'){
						$include_exclude = (isset($_POST['include_exclude']) && !empty($_POST['include_exclude'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['include_exclude'])), true)) : [];

						if(isset($include_exclude['include']) && is_array($include_exclude['include'])){
							$metaData['condition']['add-display-rule'] = $include_exclude['include'];
							//update_post_meta( $post_id ,'nxt-add-display-rule', $include_exclude['include']);
						}
						if(isset($include_exclude['exclude']) && is_array($include_exclude['exclude'])){
							$metaData['condition']['exclude-display-rule'] = $include_exclude['exclude'];
							//update_post_meta( $post_id ,'nxt-exclude-display-rule', $include_exclude['exclude']);
						}

						$in_sub_field = (isset($_POST['in_sub_field']) && !empty($_POST['in_sub_field'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['in_sub_field'])), true)) : [];
						if(isset($in_sub_field) && is_array($in_sub_field)){
							$metaData['condition']['in-sub-rule'] = $in_sub_field;
							//update_post_meta( $post_id ,'nxt-in-sub-rule', $in_sub_field);
						}

						$ex_sub_field = (isset($_POST['ex_sub_field']) && !empty($_POST['ex_sub_field'])) ? $this->sanitize_custom_array(json_decode(wp_unslash(html_entity_decode($_POST['ex_sub_field'])), true)) : [];
						if(isset($ex_sub_field) && is_array($ex_sub_field)){
							$metaData['condition']['ex-sub-rule'] = $ex_sub_field;
							//update_post_meta( $post_id ,'nxt-ex-sub-rule', $ex_sub_field);
						}
					}
				}

				$snippet_note = (isset($_POST['snippet_note']) && !empty($_POST['snippet_note'])) ? sanitize_text_field(wp_unslash($_POST['snippet_note'])) : '';
				if(isset($snippet_note)){
					$metaData['description'] = $snippet_note;
					//update_post_meta( $post_id , 'nxt-code-note', $snippet_note );
				}

				$tags = (isset($_POST['tags']) && !empty($_POST['tags'])) ? array_map('sanitize_text_field', explode(',', $_POST['tags'])) : [];
				if(isset($tags) ){
					if (is_array($tags) && !empty($tags)) {
						$metaData['tags'] = $tags;
						//update_post_meta($post_id, 'nxt-code-tags', $tags);
					}else{
						$metaData['tags'] = [];
						//update_post_meta($post_id, 'nxt-code-tags', []);
					}
				}

				$status = isset($_POST['status']) ? rest_sanitize_boolean(wp_unslash($_POST['status'])) : false;
				if(isset($status)){
					$status = !empty($submit_error_log) ? 0 : $status;
					$metaData['condition']['status'] = $status ? 1 : 0;
					//update_post_meta( $post_id , 'nxt-code-status', $status ? 1 : 0 );
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
						/* delete_post_meta( $post_id, 'nxt-add-display-rule' );
						delete_post_meta( $post_id, 'nxt-exclude-display-rule' );
						delete_post_meta( $post_id, 'nxt-in-sub-rule' );
						delete_post_meta( $post_id, 'nxt-ex-sub-rule' ); */
						unset($metaData['condition']['add-display-rule']);
						unset($metaData['condition']['exclude-display-rule']);
						unset($metaData['condition']['in-sub-rule']);
						unset($metaData['condition']['ex-sub-rule']);
					}
					
					// Save the Dynamic Conditional Logic data
					$metaData['condition']['smart-logic'] = $smart_conditional_logic;
					//update_post_meta( $post_id , 'nxt-smart-conditional-logic', $smart_conditional_logic );
				}

				if(!empty($submit_error_log)){
					wp_send_json_error([
						//'id' => $post_id,
						'errors' => $submit_error_log
					]);
				}
				return $metaData;
			//}
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
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
			if ( ! self::check_content_dir_writable() ) {
				wp_send_json_error(__('File-based snippets require write access. This environment restricts file creation.', 'nexter-extension'));
				return;
			}

			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}

			check_ajax_referer('nxt-code-snippet', 'nonce');
			/*$post_id = isset($_POST['post_id']) && is_numeric($_POST['post_id']) ? intval($_POST['post_id']) : 0;

			 if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {
					if ( isset($_POST['title']) ) {
						$title = sanitize_text_field(wp_unslash($_POST['title']));
						if(empty($title)){
							wp_send_json_error(__('Enter Name Snippet', 'nexter-extension'));
						}
						$post_data = array(
							'ID'         => $post_id,
							'post_title' => $title,
						);
						wp_update_post($post_data);
					}

					$this->add_update_metadata($post_id);
					wp_send_json_success(__('Snippet Updated Successfully.', 'nexter-extension'));
				} else {
					wp_send_json_error(['message' => __('Invalid post or post type', 'nexter-extension')]);
				}
			}else */
			if(isset($_POST['post_id']) && !empty($_POST['post_id'])){
				$post_id = isset($_POST['post_id']) ? sanitize_text_field($_POST['post_id']) : '';
				$file_based = $this->get_file_based_instance();
				
				if ( $file_based && !empty($post_id) ) {
					$storageDir = $this->get_storage_directory();
					if ( ! $storageDir ) {
						wp_send_json_error(__('Storage directory not available.', 'nexter-extension'));
						return;
					}

					// Security: Validate and sanitize post_id to prevent directory traversal
					$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '';
					$post_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $post_id ); // Only allow alphanumeric, underscore, hyphen
					if ( empty( $post_id ) ) {
						wp_send_json_error(__('Invalid snippet ID.', 'nexter-extension'));
						return;
					}
					
					$existingFile = $storageDir . '/' . $post_id . '.php';
					
					// Security: Validate file path to prevent directory traversal
					$real_file = realpath( dirname( $existingFile ) );
					$real_storage = realpath( $storageDir );
					if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
						wp_send_json_error(__('Invalid file path detected.', 'nexter-extension'));
						return;
					}
					
					if (!is_file($existingFile)) {
						wp_send_json_error(__('Snippet ID does not exist.', 'nexter-extension'));
						return;
					}
					// Get new title
					$newTitle = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

					if (empty($newTitle)) {
						wp_send_json_error(__('Enter Name Snippet', 'nexter-extension'));
						return;
					}

					$metaData = [
						'name' => $newTitle,
						'status' => 'publish',
					];
					
					$metaData = $this->add_update_metadata($metaData);
					$lang_code = isset($metaData['lang-code']) ? $metaData['lang-code'] : '';
					unset($metaData['lang-code']);
					
					// Validate PHP code
					if ( $metaData['type'] == 'php' && preg_match('/^<\?php/', $lang_code) ) {
						wp_send_json_error(__('Please remove <?php from the beginning of the code', 'nexter-extension'));
						return;
					}

					$lang_code = $this->process_php_code( $lang_code, $metaData['type'] );
					$docBlockString = $this->parseInputMeta($metaData, true);
					$fullCode = $docBlockString . $lang_code;

					if ( file_put_contents($existingFile, $fullCode) ) {
						$file_based->snippetIndexData();
						wp_send_json_success(__('Snippet Updated Successfully.', 'nexter-extension'));
					} else {
						wp_send_json_error(__('Failed to update snippet file.', 'nexter-extension'));
					}
				} else {
					wp_send_json_error(['message' => __('File-based storage not available', 'nexter-extension')]);
				}
			} else {
				wp_send_json_error(['message' => __('Invalid Snippet ID', 'nexter-extension')]);
			}
		}

	/**
	 * Migrate post-based snippets to file-based (runs once on admin_init)
	 */
	public function migrate_post_snippets_to_file_based() {
		// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
		if ( ! self::check_content_dir_writable() ) {
			// Log error but don't break execution for migration
			error_log( 'Nexter Extension: File-based snippets require write access. Migration skipped.' );
			return;
		}

		// Check if migration has already been completed
		$get_opt = get_option('nexter_extra_ext_options');
		$migration = false;

		if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['values']['migration'])) {
			$migration = !empty($get_opt['code-snippets']['values']['migration']);
		}

		if ($migration) {
			return;
		}
		// Only proceed if file-based class exists
		$file_based = $this->get_file_based_instance();
		if ( ! $file_based ) {
			return;
		}

		$storageDir = $this->get_storage_directory();
		if ( ! $storageDir ) {
			return;
		}

		// Create directory if it doesn't exist
		if (!is_dir($storageDir)) {
			wp_mkdir_p($storageDir);
		}

		// Get all existing post-based snippets
		$args = array(
			'post_type'      => self::$snippet_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$query = new WP_Query($args);
		$migrated_count = 0;

		// Get initial file count (cached)
		if ( self::$glob_file_count_cache === null ) {
			$fileCount = count(glob($storageDir . '/*.php'));
			if (!$fileCount) {
				$fileCount = 1;
			}
			self::$glob_file_count_cache = $fileCount;
		} else {
			$fileCount = self::$glob_file_count_cache;
		}

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$post_id = get_the_ID();
				$post = get_post($post_id);

				if (!$post || $post->post_type !== self::$snippet_type) {
					continue;
				}

				// Get all meta data
				$type = get_post_meta($post_id, 'nxt-code-type', true);
				if (empty($type)) {
					continue; // Skip snippets without type
				}

				// Get code based on type
				$lang_code = get_post_meta($post_id, 'nxt-' . $type . '-code', true);
				if (empty($lang_code)) {
					continue; // Skip snippets without code
				}

				// Build metadata array
				$metaData = array(
					'name'         => $post->post_title,
					'description'  => get_post_meta($post_id, 'nxt-code-note', true),
					'tags'         => get_post_meta($post_id, 'nxt-code-tags', true),
					'type'         => strtolower($type),
					'status'       => 'publish',
					'created_by'   => $post->post_author,
					'created_at'   => get_post_time('Y-m-d H:i:s', true, $post_id),
					'updated_at'   => get_post_modified_time('Y-m-d H:i:s', true, $post_id),
					'updated_by'   => get_current_user_id(),
					'condition'    => array(
						'status'         => get_post_meta($post_id, 'nxt-code-status', true) ? 1 : 0,
						'priority'       => get_post_meta($post_id, 'nxt-code-hooks-priority', true) ?: 10,
						'insertion'      => get_post_meta($post_id, 'nxt-code-insertion', true) ?: 'auto',
						'location'       => get_post_meta($post_id, 'nxt-code-location', true),
						'customname'     => get_post_meta($post_id, 'nxt-code-customname', true),
						'compresscode'  => get_post_meta($post_id, 'nxt-code-compresscode', true),
						'startDate'     => get_post_meta($post_id, 'nxt-code-startdate', true),
						'endDate'       => get_post_meta($post_id, 'nxt-code-enddate', true),
						'shortcodeattr' => get_post_meta($post_id, 'nxt-code-shortcodeattr', true),
						'word_count'    => get_post_meta($post_id, 'nxt-insert-word-count', true) ?: 100,
						'word_interval' => get_post_meta($post_id, 'nxt-insert-word-interval', true) ?: 200,
						'post_number'   => get_post_meta($post_id, 'nxt-post-number', true) ?: 1,
						'css_selector'  => get_post_meta($post_id, 'nxt-css-selector', true),
						'element_index' => get_post_meta($post_id, 'nxt-element-index', true) ?: 0,
						'add-display-rule' => get_post_meta($post_id, 'nxt-add-display-rule', true),
						'exclude-display-rule' => get_post_meta($post_id, 'nxt-exclude-display-rule', true),
						'in-sub-rule'   => get_post_meta($post_id, 'nxt-in-sub-rule', true),
						'ex-sub-rule'   => get_post_meta($post_id, 'nxt-ex-sub-rule', true),
						'smart-logic'   => get_post_meta($post_id, 'nxt-smart-conditional-logic', true),
					),
				);

				// Normalize type to lowercase for comparison
				$type_lower = strtolower($type);

				// Add type-specific condition data
				if ($type_lower === 'php') {
					$metaData['condition']['code-execute'] = get_post_meta($post_id, 'nxt-code-execute', true) ?: 'global';
					$metaData['condition']['php-hidden-execute'] = get_post_meta($post_id, 'nxt-code-php-hidden-execute', true) ?: 'no';
				} elseif ($type_lower === 'htmlmixed') {
					$metaData['condition']['html_hooks'] = get_post_meta($post_id, 'nxt-code-html-hooks', true);
				}

				// Add post_id when insertion is shortcode
				if ($metaData['condition']['insertion'] === 'shortcode') {
					$metaData['condition']['post_id'] = $post_id;
				}

				// Clean up empty condition values
				$metaData['condition'] = array_filter($metaData['condition'], function($value) {
					return $value !== '' && $value !== null;
				});

				// Handle tags - ensure it's an array
				if (!empty($metaData['tags']) && !is_array($metaData['tags'])) {
					if (is_string($metaData['tags'])) {
						$decoded = json_decode($metaData['tags'], true);
						$metaData['tags'] = is_array($decoded) ? $decoded : explode(',', $metaData['tags']);
					} else {
						$metaData['tags'] = array();
					}
				}

				// Process code based on type
				if ($type_lower === 'php') {
					// Remove <?php if present at the beginning
					$lang_code = preg_replace('/^<\?php\s*/', '', $lang_code);
					$lang_code = rtrim($lang_code, '?>');
					$lang_code = '<?php' . PHP_EOL . $lang_code;
				}

				// Generate file name using same convention as create_new_snippet
				// Get the first 4 words of the snippet name
				$fileTitle = $post->post_title;
				$nameArr = explode(' ', $fileTitle);
				if (count($nameArr) > 4) {
					$nameArr = array_slice($nameArr, 0, 4);
					$fileTitle = implode(' ', $nameArr);
				}

				$fileTitle = sanitize_title($fileTitle, 'snippet');

				$fileName = $fileCount . '-' . $fileTitle . '.php';
				$fileName = sanitize_file_name($fileName);

				$file = $storageDir . '/' . $fileName;
				
				// Security: Validate file path to prevent directory traversal
				$real_file = realpath( dirname( $file ) );
				$real_storage = realpath( $storageDir );
				if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
					continue; // Skip invalid paths
				}

				// If file exists, add post ID to make it unique
				if (is_file($file)) {
					$fileName = $fileCount . '-' . $fileTitle . '-' . $post_id . '.php';
					$fileName = sanitize_file_name($fileName);
					$file = $storageDir . '/' . $fileName;
					
					// Security: Re-validate after path change
					$real_file = realpath( dirname( $file ) );
					if ( ! $real_file || strpos( $real_file, $real_storage ) !== 0 ) {
						continue; // Skip invalid paths
					}
				}

				// Increment file count for next file
				$fileCount++;

				// Parse metadata to docblock format
				$docBlockString = $this->parseInputMeta($metaData, true);
				$fullCode = $docBlockString . $lang_code;

				// Write file
				if (file_put_contents($file, $fullCode)) {
					$migrated_count++;
				}
			}
			wp_reset_postdata();

			// Update index after migration
			if ($migrated_count > 0) {
				$file_based->snippetIndexData();
			}
		}

		// Mark migration as completed in the same option structure
		if (!is_array($get_opt)) {
			$get_opt = array();
		}
		if (!isset($get_opt['code-snippets'])) {
			$get_opt['code-snippets'] = array();
		}
		if (!isset($get_opt['code-snippets']['values'])) {
			$get_opt['code-snippets']['values'] = array();
		}
		$get_opt['code-snippets']['values']['migration'] = true;
		update_option('nexter_extra_ext_options', $get_opt);
	}

	/*
	 * Fetch nxt-code-Snippet List
	 * 
	 * */
	public function fetch_code_list(){
			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');
			$code_list = [];
			/* $args = array(
				'post_type'      => self::$snippet_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			);
		
			$query = new WP_Query($args);
			
			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();
					$type = get_post_meta(get_the_ID(), 'nxt-code-type', true);
					$code_list[] = [
						'id' => $post_id,
						'name'        => get_post_field('post_title', $post_id, 'raw'),
						'description'	=> isset($all_meta['nxt-code-note'][0]) ? $all_meta['nxt-code-note'][0] : '',
						'type'	=> $type,
						'tags'	=> isset($all_meta['nxt-code-tags'][0]) ? $all_meta['nxt-code-tags'][0] : '',
						'code-execute'	=> isset($all_meta['nxt-code-execute'][0]) ? $all_meta['nxt-code-execute'][0] : '',
						'status'	=> isset($all_meta['nxt-code-status'][0]) ? $all_meta['nxt-code-status'][0] : '',
						'priority' => isset($all_meta['nxt-code-hooks-priority'][0]) ? $all_meta['nxt-code-hooks-priority'][0] : '',
						'last_updated' => get_the_modified_time('F j, Y'),
					];
					
				}
				wp_reset_postdata();
			}else{
				wp_send_json_error(__('No List Found.', 'nexter-extension'));
			} */
			
			$file_code_list = $this->get_file_code_list_without_meta();
			if(!empty($file_code_list)){
				$code_list = array_merge($file_code_list, $code_list);
			}
			wp_send_json_success($code_list);
		}

	/**
	 * Export Snippet
	 */
	public function fetch_code_snippet_export(){
		if(!$this->check_permission_user()){
			wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
		}
		check_ajax_referer('nxt-code-snippet', 'nonce');

		// Handle both single post_id and array of post_id[]
		$post_ids = [];
		if (isset($_GET['post_id']) && is_array($_GET['post_id'])) {
			// Multiple IDs passed as array
			$post_ids = array_map('sanitize_text_field', $_GET['post_id']);
			$post_ids = array_filter($post_ids); // Remove invalid IDs
		} elseif (isset($_GET['post_id']) && !empty($_GET['post_id'])) {
			// Single ID passed as string/number
			$post_id = is_numeric($_GET['post_id']) ? intval($_GET['post_id']) : sanitize_text_field($_GET['post_id']);
			if (!empty($post_id)) {
				$post_ids = [$post_id];
			}
		}

		if (empty($post_ids)) {
			wp_send_json_error( __('Invalid snippet ID.', 'nexter-extension') );
		}

		$snippets_data = [];
		
		foreach ($post_ids as $post_id) {
			$data = [];

			// Check if it's a numeric ID (database post) or file-based snippet
			if (is_numeric($post_id)) {
				$post = get_post( $post_id );
				if ( ! $post || $post->post_type !== self::$snippet_type ) {
					continue; // Skip invalid posts
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
			} else {
				// File-based snippet
				$file_based = $this->get_file_based_instance();
				if ( $file_based && !empty($post_id) ) {
					$storageDir = $this->get_storage_directory();
					if ( $storageDir ) {
						// Security: Validate and sanitize post_id to prevent directory traversal
						$post_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $post_id );
						if ( empty( $post_id ) ) {
							continue; // Skip invalid post IDs
						}
						
						$existingFile = $storageDir . '/' . $post_id . '.php';
						
						// Security: Validate file path to prevent directory traversal
						$real_file = realpath( dirname( $existingFile ) );
						$real_storage = realpath( $storageDir );
						if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
							continue; // Skip invalid paths
						}
						
						if (!is_file($existingFile)) {
							continue; // Skip if file doesn't exist
						}

						$data = $file_based->getSnippetData( $post_id );
					}
				}
			}
			
			if (!empty($data)) {
				$snippets_data[] = $data;
			}
		}

		if (empty($snippets_data)) {
			wp_send_json_error( __('No valid snippets found to export.', 'nexter-extension') );
		}

		$export_object = [
			'generator'    => 'Nexter Snippet Export v'.NEXTER_EXT_VER,
			'date_created' => gmdate( 'Y-m-d H:i' ),
			'snippets'     => $snippets_data,
		];

		// Generate filename based on first snippet or use generic name for multiple
		$first_snippet = $snippets_data[0];
		$title = sanitize_title( isset($first_snippet['name']) ? $first_snippet['name'] : 'code' );
		$parts = explode( '-', $title );
		$first_part = $parts[0];
		$filename_prefix = substr( $first_part, 0, 7 );
		$filename_prefix = ucfirst( $filename_prefix );
		
		$filename_suffix = count($snippets_data) > 1 ? 'multiple' : (isset($first_snippet['id']) ? $first_snippet['id'] : '');
		
		// Send export as file
		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Disposition: attachment; filename="' . $filename_prefix . '-nxt-snippet-' . $filename_suffix . '.json"' );
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
	 * Import single snippet file-based
	 * 
	 * @param array $snippet Snippet data array
	 * @param object|null $file_based File based handler instance
	 * @return array|WP_Error Returns array with 'success', 'id', 'name' on success, or WP_Error on failure
	 */
	public function import_single_snippet_file_based( $snippet, $file_based = null ) {
		// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
		if ( ! self::check_content_dir_writable() ) {
			return new \WP_Error( 'write_access_denied', __('File-based snippets require write access. This environment restricts file creation.', 'nexter-extension') );
		}

		// Validate snippet
		if ( empty( $snippet['post_type'] ) || $snippet['post_type'] !== self::$snippet_type ) {
			return new \WP_Error( 'invalid_snippet_type', isset($snippet['name']) ? $snippet['name'] : __('Unknown snippet', 'nexter-extension') );
		}
		
		if ( ! isset($snippet['name']) ) {
			return new \WP_Error( 'missing_name', __('Snippet name is required', 'nexter-extension') );
		}
		
		$title = sanitize_text_field(wp_unslash($snippet['name']));
		if(empty($title)){
			return new \WP_Error( 'empty_title', __('Untitled snippet', 'nexter-extension') );
		}
		
		// Initialize file_based if not provided
		if ( $file_based === null ) {
			$file_based = $this->get_file_based_instance();
		}
		
		if( ! $file_based ){
			return new \WP_Error( 'file_based_not_available', __('File based storage not available', 'nexter-extension') );
		}
		
		$storageDir = $this->get_storage_directory();
		if ( ! $storageDir ) {
			return new \WP_Error( 'storage_dir_not_available', __('Storage directory not available', 'nexter-extension') );
		}

		$fileName = $this->generate_snippet_filename( $title );
		$file = $storageDir . '/' . $fileName;
		
		// Security: Validate file path to prevent directory traversal
		$real_file = realpath( dirname( $file ) );
		$real_storage = realpath( $storageDir );
		if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
			return new \WP_Error('invalid_path', 'Invalid file path detected.');
		}

		// If file exists, add timestamp to make it unique
		if (is_file($file)) {
			return new \WP_Error('file_exists', 'Please try a different name');
		}
		
		// Remove .php extension from post_id
		$post_id = str_replace('.php', '', $fileName);
		$metaData = $this->build_snippet_metadata( $snippet, $title );
		$lang_code = isset($snippet['langCode']) ? $snippet['langCode'] : '';
		$lang_code = $this->process_php_code( $lang_code, $metaData['type'] );
		$docBlockString = $this->parseInputMeta($metaData, true);

		$fullCode = $docBlockString . $lang_code;

		if (file_put_contents($file, $fullCode)) {
			return [
				'success' => true,
				'id' => $post_id,
				'name' => $title,
			];
		} else {
			return new \WP_Error('file_write_failed', $title);
		}
	}

	/**
	 * Import Snippet
	 * Handles both single and multiple snippet imports
	 */
	public function fetch_code_snippet_import() {
		// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
		if ( ! self::check_content_dir_writable() ) {
			wp_send_json_error(__('File-based snippets require write access. This environment restricts file creation.', 'nexter-extension'));
			return;
		}

		if(!$this->check_permission_user()){
			wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
		}
		check_ajax_referer('nxt-code-snippet', 'nonce');
	
		if ( empty( $_FILES['snippet_file'] ) || $_FILES['snippet_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( __('No file uploaded or file upload error.', 'nexter-extension') );
		}
	
		// Security: Validate file size (limit to 10MB to prevent resource exhaustion)
		$max_size = 10 * 1024 * 1024; // 10MB
		if ( isset( $_FILES['snippet_file']['size'] ) && $_FILES['snippet_file']['size'] > $max_size ) {
			wp_send_json_error( __('File size exceeds maximum allowed size (10MB).', 'nexter-extension') );
			return;
		}
		
		// Security: Validate file type
		$file_type = wp_check_filetype( $_FILES['snippet_file']['name'] );
		
		// Fallback: If wp_check_filetype returns empty (extension not in allowed mime types),
		// manually extract extension from filename
		$file_extension = '';
		if ( ! empty( $file_type['ext'] ) ) {
			$file_extension = strtolower( $file_type['ext'] );
		} else {
			// Extract extension manually as fallback
			$file_name = $_FILES['snippet_file']['name'];
			$path_info = pathinfo( $file_name );
			if ( isset( $path_info['extension'] ) ) {
				$file_extension = strtolower( $path_info['extension'] );
			}
		}
		
		if ( $file_extension !== 'json' ) {
			wp_send_json_error( __('Invalid file type. Only JSON files are allowed.', 'nexter-extension') );
			return;
		}
	
		$uploaded_file = $_FILES['snippet_file']['tmp_name'];
		
		// Security: Verify uploaded file is actually an uploaded file (prevent path traversal)
		if ( ! is_uploaded_file( $uploaded_file ) ) {
			wp_send_json_error( __('Invalid file upload detected.', 'nexter-extension') );
			return;
		}
		
		$content = file_get_contents( $uploaded_file );

		if ( ! $content ) {
			wp_send_json_error( __('Empty or unreadable file.', 'nexter-extension') );
		}
	
		$json = json_decode( $content, true );
	
		if ( ! $json || empty( $json['snippets'] ) || ! is_array( $json['snippets'] ) ) {
			wp_send_json_error( __('Invalid snippet file.', 'nexter-extension') );
		}
	
		$imported_snippets = [];
		$failed_snippets = [];
		$file_based = $this->get_file_based_instance();
	
		// Process all snippets in the array (supports both single and multiple)
		foreach ( $json['snippets'] as $snippet ) {
			// Allow hook to intercept and handle import
			$result = apply_filters('nexter_before_import_snippet_file_based', null, $snippet, $file_based);
			
			// If hook didn't handle it, use the default import function
			if ( $result === null ) {
				$result = $this->import_single_snippet_file_based( $snippet, $file_based );
			}
			
			// Handle import result
			if ( is_wp_error( $result ) ) {
				$failed_snippets[] = $result->get_error_message();
			} elseif ( isset( $result['success'] ) && $result['success'] ) {
				$imported_snippets[] = [
					'id' => $result['id'],
					'name' => $result['name'],
				];
			} else {
				$failed_snippets[] = isset($snippet['name']) ? $snippet['name'] : __('Unknown snippet', 'nexter-extension');
			}
		}
		
		// Update index after all imports
		if ($file_based && !empty($imported_snippets)) {
			$this->clear_file_based_cache(); // Clear cache after imports
			$file_based->snippetIndexData();
		}
		
		// Prepare response message
		$total_snippets = count($json['snippets']);
		$imported_count = count($imported_snippets);
		$failed_count = count($failed_snippets);
		
		if ($imported_count === 0) {
			wp_send_json_error( [
				'message' => __('No snippets were imported.', 'nexter-extension'),
				'failed' => $failed_snippets,
			] );
		}
		
		$message = '';
		if ($total_snippets === 1) {
			$message = __('Snippet imported successfully.', 'nexter-extension');
		} else {
			/* translators: %d: Number of snippets imported successfully */
			$message = sprintf(
				_n(
					'%d snippet imported successfully.',
					'%d snippets imported successfully.',
					$imported_count,
					'nexter-extension'
				),
				$imported_count
			);
			if ($failed_count > 0) {
				/* translators: %d: Number of snippets that failed to import */
				$message .= ' ' . sprintf(
					_n(
						'%d snippet failed.',
						'%d snippets failed.',
						$failed_count,
						'nexter-extension'
					),
					$failed_count
				);
			}
		}
		
		wp_send_json_success( [
			'message' => $message,
			'imported' => $imported_snippets,
			'failed' => $failed_snippets,
			'total' => $total_snippets,
			'imported_count' => $imported_count,
			'failed_count' => $failed_count,
		] );
	}
		

		/**
		 * Delete Snippet 
		 */
		public function fetch_code_snippet_delete(){
			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_POST['post_id']) && is_numeric($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {

					if (current_user_can('delete_post', $post_id)) {
					$deleted = wp_delete_post($post_id, true);

						if ($deleted) {
							wp_send_json_success(['message' => __('Snippet deleted successfully', 'nexter-extension')]);
						} else {
							wp_send_json_error(['message' => __('Failed to delete Snippet', 'nexter-extension')]);
						}
					} else {
						wp_send_json_error(['message' => __('You do not have permission to delete this snippet', 'nexter-extension')]);
					}
				} else {
					wp_send_json_error(['message' => __('Invalid post or post type', 'nexter-extension')]);
				}
			} else if(isset($_POST['post_id']) && !empty($_POST['post_id'])){
				$post_id = isset($_POST['post_id']) ? sanitize_text_field($_POST['post_id']) : '';
				
				// Security: Validate post_id format to prevent path traversal
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $post_id)) {
					wp_send_json_error(['message' => __('Invalid snippet ID format.', 'nexter-extension')]);
					return;
				}
				
				$file_based = $this->get_file_based_instance();
				if ( $file_based && !empty($post_id) ) {
					$storageDir = $this->get_storage_directory();
					if ( $storageDir ) {
						$existingFile = $storageDir . '/' . $post_id . '.php';
						
						// Security: Validate file path is within storage directory to prevent directory traversal
						$normalized_file = wp_normalize_path($existingFile);
						$normalized_storage = wp_normalize_path($storageDir);
						
						if (strpos($normalized_file, $normalized_storage) !== 0) {
							wp_send_json_error(['message' => __('Invalid file path detected.', 'nexter-extension')]);
							return;
						}
						
						if (is_file($existingFile)) {
							unlink($existingFile);
							$this->clear_file_based_cache(); // Clear cache after deletion
							// update index
							$file_based->snippetIndexData();

							wp_send_json_success(__('Snippet deleted successfully.', 'nexter-extension'));
						} else {
							wp_send_json_error(__('Snippet ID does not exist.', 'nexter-extension'));
						}
					}
				} else {
					wp_send_json_error(['message' => __('Invalid Snippet ID', 'nexter-extension')]);
				}
			} else {
				wp_send_json_error(['message' => __('Invalid Snippet ID', 'nexter-extension')]);
			}
		}

		/*
		 * Snippet Status Change
		 */
		public function fetch_code_snippet_status(){
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
			if ( ! self::check_content_dir_writable() ) {
				wp_send_json_error(__('File-based snippets require write access. This environment restricts file creation.', 'nexter-extension'));
				return;
			}

			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			$post_id = isset($_POST['post_id']) && is_numeric($_POST['post_id']) ? intval($_POST['post_id']) : 0;
			if ($post_id) {
				$post = get_post($post_id);
		
				if ($post && $post->post_type === self::$snippet_type) {
					$get_status = get_post_meta($post_id, 'nxt-code-status', true);
					update_post_meta($post_id, 'nxt-code-status', !$get_status);
					
					// Update cache option using optimized helper
					$this->update_cache_option();

					wp_send_json_success(['status' => !$get_status, 'message' => __('Updated Status Successfully', 'nexter-extension')]);
				} else {
					wp_send_json_error(['message' => __('Invalid post or post type', 'nexter-extension')]);
				}
			}else if(isset($_POST['post_id']) && !empty($_POST['post_id'])){
				$post_id = isset($_POST['post_id']) ? sanitize_text_field($_POST['post_id']) : '';
				if (class_exists('Nexter_Code_Snippets_File_Based') && !empty($post_id)) {

					$file_based = $this->get_file_based_instance();
					if ( $file_based ) {
					// Security: Validate and sanitize post_id to prevent directory traversal
					$post_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $post_id ); // Only allow alphanumeric, underscore, hyphen
					if ( empty( $post_id ) ) {
						wp_send_json_error(__('Invalid snippet ID.', 'nexter-extension'));
						return;
					}
					
					$storageDir = $this->get_storage_directory();
					$existingFile = $storageDir . '/' . $post_id . '.php';
					
					// Security: Validate file path to prevent directory traversal
					$real_file = realpath( dirname( $existingFile ) );
					$real_storage = realpath( $storageDir );
					if ( ! $real_file || ! $real_storage || strpos( $real_file, $real_storage ) !== 0 ) {
						wp_send_json_error(__('Invalid file path detected.', 'nexter-extension'));
						return;
					}
					
					if (is_file($existingFile)) {
							$file_content = file_get_contents($existingFile);
							$file_data = $file_based->parseBlock($file_content);
							$metaData = [];
							$new_status = 0;
							if($file_data && isset($file_data[0])){
								$metaData = $file_data[0];
								$current_status = isset($metaData['condition']['status']) ? $metaData['condition']['status'] : 0;
								$new_status = $current_status ? 0 : 1;
								$metaData['condition']['status'] = $new_status;
							}
							
							$lang_code = '';
							if($file_data && isset($file_data[1])){
								$lang_code = $file_data[1];
							}
							
							$docBlockString = $this->parseInputMeta($metaData, true);
							$fullCode = $docBlockString . $lang_code;

							if ( file_put_contents($existingFile, $fullCode) ) {
								$file_based->snippetIndexData();
								wp_send_json_success(['status' => $new_status, 'message' => __('Updated Status Successfully.', 'nexter-extension')]);
							} else {
								wp_send_json_error(__('Failed to update snippet file.', 'nexter-extension'));
							}
						} else {
							wp_send_json_error(__('Snippet ID does not exist.', 'nexter-extension'));
						}
					} else {
						wp_send_json_error(['message' => __('Invalid post ID', 'nexter-extension')]);
					}
				} else {
					wp_send_json_error(['message' => __('Invalid post ID', 'nexter-extension')]);
				}
			}
		}

		/*
		 * Edit Snippet Get Data
		 */
		public function get_edit_snippet_data(){
			if(!$this->check_permission_user()){
				wp_send_json_error(__('Insufficient permissions.', 'nexter-extension'));
			}
			check_ajax_referer('nxt-code-snippet', 'nonce');

			/*$post_id = isset($_POST['post_id']) && is_numeric($_POST['post_id']) ? intval($_POST['post_id']) : 0;
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
					wp_send_json_error(['message' => __('Invalid post or post type', 'nexter-extension')]);
				}
			} */
			if(isset($_POST['post_id']) && !empty($_POST['post_id'])){
				$post_id = $this->sanitize_post('post_id', '');
				$file_based = $this->get_file_based_instance();
				if ( $file_based && !empty($post_id) ) {
					$file_code_list = $file_based->getSnippetData($post_id);
					if(!empty($file_code_list)){
						$type = isset($file_code_list['type']) ? $file_code_list['type'] : '';
						$current_location = isset($file_code_list['location']) ? $file_code_list['location'] : '';
						
						// Perform location migration if needed
						$migrated_location = $this->migrate_location_if_needed($post_id, $type, $current_location, 
							isset($file_code_list['htmlHooks']) ? $file_code_list['htmlHooks'] : '', 
							isset($file_code_list['codeExecute']) ? $file_code_list['codeExecute'] : ''
						);
						$file_code_list['location'] = $migrated_location;
						wp_send_json_success($file_code_list);
					}
				}
			}else{
				wp_send_json_error(['message' => __('Invalid post ID', 'nexter-extension')]);
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
		private function migrate_location_if_needed($post_id, $type, $current_location, $html_hooks = '', $code_execute = '') {
			// If location is already set, no migration needed
			if (!empty($current_location)) {
				return $current_location;
			}
			
			$migrated_location = '';
			
			// Handle migration based on snippet type
			switch ($type) {
				case 'htmlmixed':
					// For HTML snippets, check if there was a hook set
					$html_hooks = !is_numeric($post_id) ? $html_hooks : get_post_meta($post_id, 'nxt-code-html-hooks', true);
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
					$code_execute = !is_numeric($post_id) ? $code_execute : get_post_meta($post_id, 'nxt-code-execute', true);
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
			if (!empty($migrated_location) && is_numeric($post_id)) {
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
			$file_snippets = [];
			// Enhanced fallback: Always ensure we have all active CSS snippets
			if (empty($css_actions)) {
				//$css_actions = self::get_snippets_fallback('css');
				$file_snippets = self::get_file_snippets_fallback('css');
			}
			
			/* if( !empty( $css_actions ) ){
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
			} */

			if (!empty($file_snippets)) {
				foreach ($file_snippets as $css_snippet) {
					$post_id = isset($css_snippet['id']) ? $css_snippet['id'] : '';
					$insertion_type = isset($css_snippet['insertion']) ? $css_snippet['insertion'] : '';
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

					self::$snippet_loaded_ids['css'][] = $post_id;
					
					// Check for new location system
					$location = isset($css_snippet['location']) ? $css_snippet['location'] : '';
					if (!empty($location)) {
						// Use new location-based system
						self::enqueue_css_at_location($post_id, $css_snippet, $location);
					} else {
						$file_based = self::get_file_based_instance_static();
						if ( $file_based ) {
							$file_path = isset($css_snippet['file_path']) ? $css_snippet['file_path'] : '';
							if ( $file_path && is_file($file_path) ) {
								// SECURITY FIX: Wrap file operations in try-catch to prevent crashes
								try {
									$css_code = $file_based->parseBlock(file_get_contents($file_path), true);
									
									// Use old system (default to wp_head)
									wp_register_style( 'nxt-snippet-'.$post_id, false );
									wp_enqueue_style( 'nxt-snippet-'.$post_id );
									wp_add_inline_style( 'nxt-snippet-'.$post_id, wp_specialchars_decode($css_code) );
								} catch (Exception $e) {
									error_log(sprintf('Nexter Extension: Error loading CSS snippet %s: %s', $post_id, $e->getMessage()));
								} catch (Error $e) {
									error_log(sprintf('Nexter Extension: Fatal error loading CSS snippet %s: %s', $post_id, $e->getMessage()));
								}
							}
						}
					}
				}
			}
			
			// JavaScript Snippets
			$javascript_actions = self::get_snippets_ids_list( 'javascript' );
			$file_javascript = [];
			// Enhanced fallback: Always ensure we have all active JavaScript snippets
			if (empty($javascript_actions)) {
				//$javascript_actions = self::get_snippets_fallback('javascript');
				$file_javascript = self::get_file_snippets_fallback('javascript');
			}
			
			/* if( !empty( $javascript_actions ) ){
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
			} */

			if(!empty($file_javascript)){
				foreach ($file_javascript as $js_snippet) {
					$post_id = isset($js_snippet['id']) ? $js_snippet['id'] : '';
					$insertion_type = isset($js_snippet['insertion']) ? $js_snippet['insertion'] : '';
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

					self::$snippet_loaded_ids['javascript'][] = $post_id;
					
					// Check for new location system
					$location = isset($js_snippet['location']) ? $js_snippet['location'] : '';
					if (!empty($location)) {
						// Use new location-based system
						self::enqueue_js_at_location($post_id, $js_snippet, $location);
					} else {
						$file_based = self::get_file_based_instance_static();
						if ( $file_based ) {
							$file_path = isset($js_snippet['file_path']) ? $js_snippet['file_path'] : '';
							if ( $file_path && is_file($file_path) ) {
								$javascript_code = $file_based->parseBlock(file_get_contents($file_path), true);
							} else {
								$javascript_code = '';
							}
						} else {
							$javascript_code = '';
						}

						if(!empty($javascript_code) ){
							// Use old system (default to footer)
							wp_add_inline_script( 'nxt-snippet-js', html_entity_decode($javascript_code, ENT_QUOTES) );
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
			$file_css = [];
			// Enhanced fallback: Always ensure we have all active CSS snippets
			if (empty($css_actions)) {
				//$css_actions = self::get_snippets_fallback('css');
				$file_css = self::get_file_snippets_fallback('css');
			}
			
			/* if( !empty( $css_actions ) ){
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
			} */

			if(!empty($file_css)){
				foreach ($file_css as $css_snippet) {
					$post_id = isset($css_snippet['id']) ? $css_snippet['id'] : '';
					$insertion_type = isset($css_snippet['insertion']) ? $css_snippet['insertion'] : '';
					if( !empty($insertion_type) && $insertion_type == 'shortcode'){
						continue;
					}

					// Only process admin locations
					$location = isset($css_snippet['location']) ? $css_snippet['location'] : '';
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

					$file_based = self::get_file_based_instance_static();
					if ( $file_based ) {
						$file_path = isset($css_snippet['file_path']) ? $css_snippet['file_path'] : '';
						if ( $file_path && is_file($file_path) ) {
							$css_code = $file_based->parseBlock(file_get_contents($file_path), true);
						} else {
							$css_code = '';
						}
					} else {
						$css_code = '';
					}

					if(!empty($css_code) ){
						self::$snippet_loaded_ids['css'][] = $post_id;
						
						// Use location-based system for admin locations
						self::enqueue_css_at_location($post_id, $css_snippet, $location);
					}
				}
			}
			// JavaScript Snippets for Admin
			$javascript_actions = self::get_snippets_ids_list( 'javascript' );
			$file_javascript = [];
			// Enhanced fallback: Always ensure we have all active JavaScript snippets
			if (empty($javascript_actions)) {
				//$javascript_actions = self::get_snippets_fallback('javascript');
				$file_javascript = self::get_file_snippets_fallback('javascript');
			}
			
			/* if( !empty( $javascript_actions ) ){
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
			} */

			if(!empty($file_javascript)){
				foreach ($file_javascript as $js_snippet) {
					$post_id = isset($js_snippet['id']) ? $js_snippet['id'] : '';
					$insertion_type = isset($js_snippet['insertion']) ? $js_snippet['insertion'] : '';
					if( !empty($insertion_type) && $insertion_type == 'shortcode'){
						continue;
					}

					// Only process admin locations
					$location = isset($js_snippet['location']) ? $js_snippet['location'] : '';
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

					$file_based = self::get_file_based_instance_static();
					if ( $file_based ) {
						$file_path = isset($js_snippet['file_path']) ? $js_snippet['file_path'] : '';
						if ( $file_path && is_file($file_path) ) {
							$javascript_code = $file_based->parseBlock(file_get_contents($file_path), true);
						} else {
							$javascript_code = '';
						}
					} else {
						$javascript_code = '';
					}

					if(!empty($javascript_code) ){
						self::$snippet_loaded_ids['javascript'][] = $post_id;
						
						// Use location-based system for admin locations
						self::enqueue_js_at_location($post_id, $js_snippet, $location);
					}
				}
			}
		}

		/**
		 * PHP snippets hooks actions with location support
		 * Enhanced to support location-based execution for PHP snippets
		 */
		/* public static function nexter_code_php_hooks_actions() {
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
								// Skip old system basic execution types - they're handled by bypass
								// (global, admin, front-end are handled immediately in bypass system)
								// This prevents duplication between bypass system and hook-based system
							}
						}
					}
				}
			}
		} */

		/**
		 * HTML Snippets Hooks for Admin (Optimized for PHP 7.4 + Memory)
		 */
		public static function nexter_code_html_hooks_actions_admin() {

			static $admin_locations = ['admin_header', 'admin_footer'];

			// Fetch DB-based HTML snippets
			$html_snippets = self::get_snippets_ids_list('htmlmixed');

			// Fallback if primary lookup is empty
			$file_snippets = [];
			if (empty($html_snippets)) {
				//$html_snippets = self::get_snippets_fallback('htmlmixed');
				$file_snippets = self::get_file_snippets_fallback('htmlmixed');
			}

			/** ------------------------------------------------------------------
			 * PROCESS POST-BASED SNIPPETS
			 * -------------------------------------------------------------------*/
			/* if (!empty($html_snippets)) {

				foreach ($html_snippets as $post_id) {

					// Skip if invalid post (prevents unnecessary memory load)
					if (!get_post($post_id)) {
						continue;
					}

					// Skip shortcode insertion
					$insertion = get_post_meta($post_id, 'nxt-code-insertion', true);
					if ($insertion === 'shortcode') {
						continue;
					}

					// Get location once
					$location = get_post_meta($post_id, 'nxt-code-location', true);
					if (!isset($location[0]) || !in_array($location, $admin_locations, true)) {
						continue;
					}

					// Pro logic checks
					if (self::should_skip_due_to_pro_restrictions($post_id)) {
						continue;
					}

					// Smart conditional logic
					$conditions = get_post_meta($post_id, 'nxt-smart-conditional-logic', true);
					if ($conditions && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
						if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($conditions)) {
							continue;
						}
					}

					// Mark snippet as loaded
					self::$snippet_loaded_ids['htmlmixed'][] = $post_id;

					// Load HTML code once and render
					$html_code = get_post_meta($post_id, 'nxt-htmlmixed-code', true);
					if (!empty($html_code)) {
						self::output_html_at_location($post_id, $html_code, $location);
					}
				}
			} */

			/** ------------------------------------------------------------------
			 * PROCESS FILE-BASED SNIPPETS (Fallback)
			 * -------------------------------------------------------------------*/
			if (!empty($file_snippets)) {

				foreach ($file_snippets as $s) {

					// Avoid heavy array operations — use local variables
					$snippet_id = $s['id'] ?? null;
					$location   = $s['location'] ?? '';

					if (!$snippet_id || !in_array($location, $admin_locations, true)) {
						continue;
					}

					// Pro restrictions
					if (self::should_skip_due_to_pro_restrictions($snippet_id)) {
						continue;
					}

					// Smart conditional logic
					if (!empty($s['smart_conditional_logic']) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
						if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($s['smart_conditional_logic'])) {
							continue;
						}
					}

					self::$snippet_loaded_ids['htmlmixed'][] = $snippet_id;

					// Passing snippet array directly — no extra memory copy
					self::output_html_at_location($snippet_id, $s, $location);
				}
			}
		}


		/**
		 * Optimized HTML Snippets Hook Execution
		 */
		public static function nexter_code_html_hooks_actions() {

			$snippets = self::get_snippets_ids_list('htmlmixed');
			$file_snippets = [];

			// Fallbacks only when needed
			if (empty($snippets)) {
				//$snippets       = self::get_snippets_fallback('htmlmixed');
				$file_snippets  = self::get_file_snippets_fallback('htmlmixed');
			}

			// CSS selector locations (skipped here)
			$css_locations = apply_filters('nexter_get_css_selector_locations', [
				'before_html_element', 'after_html_element', 
				'start_html_element', 'end_html_element', 
				'replace_html_element'
			]);

			// --- Unified processing for post-based & file-based snippets ---
			$process_snippet = function($id, $data, $is_file = false) use ($css_locations) {

				// Basic metadata
				$insertion = $is_file ? ($data['insertion'] ? $data['insertion'] : '') : get_post_meta($id, 'nxt-code-insertion', true);
				if ($insertion === 'shortcode') return;

				$location = $is_file ? ($data['location'] ? $data['location'] : '') : get_post_meta($id, 'nxt-code-location', true);
				if ($location && in_array($location, $css_locations, true)) return;

				// Pro restrictions
				if (self::should_skip_due_to_pro_restrictions($id)) return;

				// Smart conditional logic
				$smart_logic = $is_file 
					? ($data['smart_conditional_logic'] ? $data['smart_conditional_logic'] : [])
					: get_post_meta($id, 'nxt-smart-conditional-logic', true);

				if (!empty($smart_logic) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
					if (!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_logic)) return;
				}

				self::$snippet_loaded_ids['htmlmixed'][] = $id;

				// --- OUTPUT HANDLING ---
				if (!empty($location)) {
					// New location system
					$html_data = $is_file ? $data : get_post_meta($id, 'nxt-htmlmixed-code', true);
					self::output_html_at_location($id, $html_data, $location);
					return;
				}

				// Old hook system
				$hook_action   = $is_file ? ($data['htmlHooks'] ? $data['htmlHooks'] : '') : get_post_meta($id, 'nxt-code-html-hooks', true);
				$hook_priority = $is_file ? ($data['hooksPriority'] ? $data['hooksPriority'] : 10) : get_post_meta($id, 'nxt-code-hooks-priority', true);
				$hook_priority = intval($hook_priority) ? $hook_priority : 10;

				// Shared callback generator
				$callback = function() use ($id, $data, $is_file) {
					$status = $is_file ? ($data['status'] ? $data['status'] : '0') : get_post_meta($id, 'nxt-code-status', true);
					if ($status != '1') return;

					if ($is_file) {
						if (!empty($data['file_path']) && file_exists($data['file_path'])) {
							// Use safe file execution method
							if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
								Nexter_Code_Snippets_File_Based::safe_include_file( $data['file_path'] );
							} else {
								// Fallback: basic validation
								$file_path = wp_normalize_path( $data['file_path'] );
								$storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
								if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
									include $file_path;
								}
							}
						}
					} else {
						$html = get_post_meta($id, 'nxt-htmlmixed-code', true);
						if ($html !== '') {
							echo apply_filters('nexter_html_snippets_executed', $html, $id);
						}
					}
				};

				// Attach to hook or default wp_footer
				if ($hook_action) {
					add_action($hook_action, $callback, $hook_priority);
				} else {
					add_action('wp_footer', $callback, 10);
				}
			};

			// Process all post-based snippets
			/* foreach ($snippets as $post_id) {
				$process_snippet($post_id, [], false);
			} */

			// Process file-based snippets
			foreach ($file_snippets as $file_snippet) {
				$process_snippet($file_snippet['id'], $file_snippet, true);
			}
		}


		/**
		 * Fallback method to get snippets when display rules don't work
		 */
		/* private static function get_snippets_fallback($code_type) {
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
		} */

		private static function get_file_snippets_fallback( $code_type ) {

			// Early bail for max performance
			if ( ! class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
				return array();
			}

			// Use cached instance
			$file_based = self::get_file_based_instance_static();
			if ( ! $file_based ) {
				return array();
			}

			// Run snippet loader
			$snippets = $file_based->getSnippetData( '', $code_type );

			// Ensure returned data is valid array
			if ( ! empty( $snippets ) && is_array( $snippets ) ) {
				return $snippets;
			}

			return array();
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
		/* private static function insert_content_at_advanced_location($content, $insert_content, $location, $snippet_id) {
			// All advanced content locations are Pro features
			// Route them through the Pro plugin filter system
			return apply_filters('nexter_process_pro_content_insertion', $content, $location, $insert_content, $snippet_id);
		} */

		/**
		 * Insert content at a specific percentage of the total content
		 * This is a Pro feature - should only be called by Pro plugin
		 */
		/* private static function insert_at_content_percentage($content, $insert_content, $percentage) {
			// This method should only be used by the Pro plugin
			// If we reach here without Pro plugin, return original content
			return $content;
		} */

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

		/*
		 * Get Code Snippets Php Execute
		 * @since 1.0.4
		 */
		/* public function nexter_code_php_snippets_actions(){
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
									
									// Check if using new location system (excluding basic locations handled by bypass)
									if (!empty($location) && !in_array($location, ['run_everywhere', 'frontend_only', 'admin_only'])) {
										// Use new location-based system with specialized handlers for complex locations
										// Basic locations (run_everywhere, frontend_only, admin_only) are handled by bypass
										self::execute_php_at_location($post_id, $php_code, $location);
									} 
									// Skip old system basic execution types - they're handled by bypass
									// (global, admin, front-end are handled immediately in bypass system)
								}
							}
						}
					}
					
				}
			}
		} */

		/**
		 * Immediate PHP Execution Bypass for REST API Registration
		 * This method executes PHP snippets immediately like the old version
		 * Bypasses all the new system's security checks and routing for immediate execution
		 * @since 1.0.4
		 */
		public function nexter_php_execution_snippet(){
			global $wpdb;
			
			// Check if PHP execution is globally disabled
			$php_snippet_filter = apply_filters('nexter_php_codesnippet_execute', true);
			if (empty($php_snippet_filter)) {
				return; // PHP execution is disabled globally
			}

			$file_php = self::get_file_snippets_fallback('php');
			
			if( !empty($file_php) ){
				
				foreach ( $file_php as $file_data ) {
					
					$post_id = isset($file_data['id']) ? $file_data['id'] : '';
					if(empty($post_id) ){
						continue;
					}

					$code_status = isset($file_data['status']) ? $file_data['status'] : 0;	
					if ( $code_status == 1 ) {

						$insertion_type = isset($file_data['insertion']) ? $file_data['insertion'] : 'auto';
						if (!empty($insertion_type) && $insertion_type == 'shortcode') {
							continue;
						}

						$code_execute = isset($file_data['codeExecute']) ? $file_data['codeExecute'] : 'global';
						$code_location = isset($file_data['location']) ? $file_data['location'] : '';
						$code_hidden_execute = isset($file_data['php_hidden_execute']) ? $file_data['php_hidden_execute'] : 'no';
						
						// Apply same validation checks as main system for consistency
						// Validate eCommerce location before proceeding
						if (!$this->validate_ecommerce_location($code_location)) {
							continue; // Skip this snippet if invalid eCommerce location
						}

						// Check Pro restrictions (device and scheduling)
						if (self::should_skip_due_to_pro_restrictions($post_id)) {
							continue; // Skip this snippet due to Pro restrictions
						}

						// Smart Conditional Logic check
						$smart_conditions = isset($file_data['smart_conditional_logic']) ? $file_data['smart_conditional_logic'] : [];
						if (!empty($smart_conditions) && is_array($smart_conditions) && class_exists('Nexter_Builder_Display_Conditional_Rules')) {
							if(!Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic($smart_conditions)) {
								continue; // Skip this snippet, Smart Conditional Logic not met
							}
						}
						
						// Auto-enable execution if not set (like old version)
						if (empty($code_hidden_execute) || $code_hidden_execute === 'no') {
							$code_hidden_execute = 'yes';
						}

						// Execute immediately if conditions are met (like old version)
						if($code_hidden_execute === 'yes'){
							
							// Only handle basic locations in bypass (for REST API registration)
							$should_execute_immediately = false;
							
							// Check new location system - only basic locations
							if (!empty($code_location)) {
								if (in_array($code_location, ['run_everywhere', 'frontend_only', 'admin_only'])) {
									// Check admin context for admin_only and frontend_only
									if ($code_location === 'admin_only' && !is_admin()) {
										$should_execute_immediately = false;
									} elseif ($code_location === 'frontend_only' && is_admin()) {
										$should_execute_immediately = false;
									} else {
										$should_execute_immediately = true;
									}
								}
							}
							// Check old system - only basic execution types
							elseif (!empty($code_execute)) {
								if ($code_execute === 'global') {
									$should_execute_immediately = true;
								} elseif ($code_execute === 'admin' && is_admin()) {
									$should_execute_immediately = true;
								} elseif ($code_execute === 'front-end' && !is_admin()) {
									$should_execute_immediately = true;
								}
							}
							// Default to run_everywhere for snippets without location
							else {
								$should_execute_immediately = true;
							}
							
							// Execute immediately using old-style direct execution
							if ($should_execute_immediately) {
								if(is_array($file_data)){
									self::$snippet_loaded_ids['php'][] = $post_id;
									$file_based = $this->get_file_based_instance();
									if($file_based){
										$is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
				
										if (!$is_ajax && !empty( $file_data['file_path']) && file_exists( $file_data['file_path'] ) ) {
											// Use safe file execution method
											if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
												Nexter_Code_Snippets_File_Based::safe_include_file( $file_data['file_path'] );
											} else {
												// Fallback: basic validation
												$file_path = wp_normalize_path( $file_data['file_path'] );
												$storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
												if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
													require_once $file_path;
												}
											}
										}
									}
								}
							}else {
								self::$snippet_loaded_ids['php'][] = $post_id;
								// Check if using new location system (excluding basic locations handled by bypass)
								if (!empty($code_location) && !in_array($code_location, ['run_everywhere', 'frontend_only', 'admin_only'])) {
									// Use new location-based system with specialized handlers for complex locations
									// Basic locations (run_everywhere, frontend_only, admin_only) are handled by bypass
									self::execute_php_at_location($post_id, $file_data, $code_location);
								} 
							}
						}
					}
				}
			}
			// Get all PHP snippets directly from database (like old version)
			/* $sqlquery = "SELECT p.ID FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = 'nxt-code-type') AND p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = 'php' ORDER BY p.post_date DESC";
			$posts = $wpdb->get_results( $wpdb->prepare( $sqlquery, self::$snippet_type ) );
			
			if( !empty($posts) ){
				foreach ( $posts as $post_data ) {
					$post_id = $post_data->ID;
					
					// Basic checks only (like old version)
					$code_status = get_post_meta( $post_id, 'nxt-code-status', true );
					$authorID = get_post_field( 'post_author', $post_id );
					$theAuthorDataRoles = get_userdata($authorID);
					$theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
					
					if ( in_array( 'administrator', $theRolesAuthor ) && !empty($code_status)) {
						// Skip shortcode insertion type for auto execution (same as main system)
						$insertion_type = get_post_meta($post_id, 'nxt-code-insertion', true);
						if (!empty($insertion_type) && $insertion_type == 'shortcode') {
							continue;
						}
						
						$php_code = get_post_meta( $post_id, 'nxt-php-code', true );
						$code_execute = get_post_meta( $post_id, 'nxt-code-execute', true );
						$code_location = get_post_meta( $post_id, 'nxt-code-location', true );
						$code_hidden_execute = get_post_meta( $post_id, 'nxt-code-php-hidden-execute', true );
						
						// Apply same validation checks as main system for consistency
						// Validate eCommerce location before proceeding
						if (!$this->validate_ecommerce_location($code_location)) {
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

						// Auto-enable execution if not set (like old version)
						if (empty($code_hidden_execute)) {
							update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'yes');
							$code_hidden_execute = 'yes';
						}

						// Execute immediately if conditions are met (like old version)
						if(!empty($php_code) && $code_hidden_execute === 'yes'){
							
							// Only handle basic locations in bypass (for REST API registration)
							$should_execute_immediately = false;
							
							// Check new location system - only basic locations
							if (!empty($code_location)) {
								if (in_array($code_location, ['run_everywhere', 'frontend_only', 'admin_only'])) {
									// Check admin context for admin_only and frontend_only
									if ($code_location === 'admin_only' && !is_admin()) {
										$should_execute_immediately = false;
									} elseif ($code_location === 'frontend_only' && is_admin()) {
										$should_execute_immediately = false;
									} else {
										$should_execute_immediately = true;
									}
								}
							}
							// Check old system - only basic execution types
							elseif (!empty($code_execute)) {
								if ($code_execute === 'global') {
									$should_execute_immediately = true;
								} elseif ($code_execute === 'admin' && is_admin()) {
									$should_execute_immediately = true;
								} elseif ($code_execute === 'front-end' && !is_admin()) {
									$should_execute_immediately = true;
								}
							}
							// Default to run_everywhere for snippets without location
							else {
								$should_execute_immediately = true;
								// Auto-set to run_everywhere
								update_post_meta( $post_id, 'nxt-code-location', 'run_everywhere');
							}
							
							// Execute immediately using old-style direct execution
							if ($should_execute_immediately) {
								$this->nexter_direct_php_execute($php_code, $post_id);
							}
						}
					}
				}
			} */
		}

		/**
		 * Direct PHP execution with simple error handling
		 * Prevents site crashes and disables problematic snippets
		 */
		/* private function nexter_direct_php_execute($code, $post_id = null) {
			if (empty($code)) {
				return false;
			}
			
			// Clean the code like old version
			$code = html_entity_decode(htmlspecialchars_decode($code));
			
			// Set up error handling to prevent site crashes
			$old_error_reporting = error_reporting();
			$old_display_errors = ini_get('display_errors');
			
			// Suppress errors to prevent site crash
			error_reporting(0);
			ini_set('display_errors', 0);
			
			// Use output buffering for AJAX requests to prevent interference
			$is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || 
					   (defined('REST_REQUEST') && REST_REQUEST) ||
					   (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
			
			// Allow filtering to control output buffering behavior
			// To allow PHP output during AJAX (for debugging), use:
			// add_filter('nexter_suppress_php_output_during_ajax', '__return_false');
			$suppress_ajax_output = apply_filters('nexter_suppress_php_output_during_ajax', true);
			
			if ($is_ajax && $suppress_ajax_output) {
				ob_start();
			}
			
			try {
				// Execute the code
				eval($code);
				
				// Clean output buffer for AJAX requests
				if ($is_ajax && $suppress_ajax_output) {
					$output = ob_get_clean();
					// Optionally log the output for debugging
					if (!empty($output) && defined('WP_DEBUG') && WP_DEBUG) {
						error_log("Nexter Extension: PHP snippet {$post_id} produced output during AJAX: " . substr($output, 0, 100));
					}
				}
				
				// Restore error handling
				error_reporting($old_error_reporting);
				ini_set('display_errors', $old_display_errors);
				
				return true;
				
			} catch (ParseError $e) {
				// Clean output buffer if needed
				if ($is_ajax && $suppress_ajax_output && ob_get_level() > 0) {
					ob_end_clean();
				}
				
				// Restore error handling
				error_reporting($old_error_reporting);
				ini_set('display_errors', $old_display_errors);
				
				// Disable snippet on parse error
				$this->disable_problematic_snippet($post_id);
				return false;
				
			} catch (Error $e) {
				// Clean output buffer if needed
				if ($is_ajax && $suppress_ajax_output && ob_get_level() > 0) {
					ob_end_clean();
				}
				
				// Restore error handling
				error_reporting($old_error_reporting);
				ini_set('display_errors', $old_display_errors);
				
				// Disable snippet on fatal error
				$this->disable_problematic_snippet($post_id);
				return false;
				
			} catch (Exception $e) {
				// Clean output buffer if needed
				if ($is_ajax && $suppress_ajax_output && ob_get_level() > 0) {
					ob_end_clean();
				}
				
				// Restore error handling
				error_reporting($old_error_reporting);
				ini_set('display_errors', $old_display_errors);
				
				// Disable snippet on exception
				$this->disable_problematic_snippet($post_id);
				return false;
			}
		} */
		
		/**
		 * Simple method to disable problematic snippets
		 */
		private function disable_problematic_snippet($post_id, $metaData = []) {
			if (empty($post_id) && empty($metaData)) return;
			
			// Disable the snippet
			if(is_numeric($post_id)){
				update_post_meta($post_id, 'nxt-code-status', 0);
				update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
			}else if(!empty($metaData) && is_array($metaData)){
				$metaData['condition']['status'] = 0;
				$metaData['condition']['php-hidden-execute'] = 'no';
			}
			return $metaData;
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

	}
}
Nexter_Builder_Code_Snippets_Render::get_instance();