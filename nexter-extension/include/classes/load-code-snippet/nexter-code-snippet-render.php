<?php
/**
 * Nexter Builder Code Snippets Render
 *
 * @package Nexter Extensions
 * @since 1.0.4
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Builder_Code_Snippets_Render' ) ) {

	class Nexter_Builder_Code_Snippets_Render {

		/**
		 * Member Variable
		 */
		private static $instance;

		private static $snippet_type = 'nxt-code-snippet';

		public static $snippet_ids = array();

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
				add_action( 'wp', array( $this, 'get_snippet_ids' ), 1 );
			}
			add_action( 'wp', array( $this, 'nexter_code_html_hooks_actions' ),2 );
			if(!is_admin()){
				add_action( 'wp_enqueue_scripts', array( $this, 'nexter_code_snippets_css_js' ),2 );
			}
			if((!isset($_GET['test_code']) || empty($_GET['test_code']))){ // phpcs:ignore WordPress.Security.NonceVerification.Recommended, handled by the core method already.
				$this->nexter_code_php_snippets_actions();
			}

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ) );
			add_action('wp_ajax_create_code_snippets', array( $this, 'create_new_snippet') );
			add_action('wp_ajax_update_edit_code_snippets', array( $this, 'update_edit_snippet') );
			add_action('wp_ajax_fetch_code_snippet_list', array( $this, 'fetch_code_list') );
			add_action('wp_ajax_fetch_code_snippet_delete', array( $this, 'fetch_code_snippet_delete') );
			add_action('wp_ajax_fetch_code_snippet_status', array( $this, 'fetch_code_snippet_status') );
			add_action('wp_ajax_get_edit_snippet_data', array( $this, 'get_edit_snippet_data') );
			add_action( 'init', array( $this, 'home_page_code_execute' ) );
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
						$this->nexter_code_php_snippets_execute($php_code);
					}
				}
			}
		}
		
		/*
		 * Execute Php Snippets Code
		 * @since 1.0.4
		 */
		public function nexter_code_php_snippets_execute( $code, $catch_output = true ) {
			if ( empty( $code ) ) {
				return false;
			}
			$code = html_entity_decode(htmlspecialchars_decode($code));
			
			if ( $catch_output ) {
				ob_start();
			}

			// @codingStandardsIgnoreStart
			$result = eval( $code );
			// @codingStandardsIgnoreEnd
			
			if ( $catch_output ) {
				ob_end_clean();
			}
			
			return $result;
		}
		
		public function nexter_ext_code_execute( $post_id = 0 ){
			
			$user = wp_get_current_user();
			if ( !empty($user) && isset($user->roles) && !in_array( 'administrator', $user->roles ) ) {
				wp_send_json_error(
					array(
						'code'    => 'php_error',
						'message' => __( 'Only Admin can run this.', 'nexter-extension' ),
					)
				);
			}
			
			$post_id = !empty($post_id) ? sanitize_key( intval(wp_unslash( $post_id )) ) : '';
			if(empty($post_id)){
				wp_send_json_error(
					array(
						'code'    => 'php_error',
						'message' => __( 'Undefined Content ID', 'nexter-extension' ),
					)
				);
			}
			
			update_post_meta( $post_id, 'nxt-code-php-hidden-execute','no');

			$scrape_key   = md5( wp_rand() );
			$transient    = 'scrape_key_' . $scrape_key;
			$scrape_nonce = (string) wp_rand();
			set_transient( $transient, $scrape_nonce, 5 );

			$cookies       = wp_unslash( $_COOKIE );
			$scrape_params = array(
				'wp_scrape_key'   => $scrape_key,
				'wp_scrape_nonce' => $scrape_nonce,
				'test_code' => 'code_test',
				'code_id' => intval($post_id),
			);
			$headers       = array(
				'Cache-Control' => 'no-cache',
			);

			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			$sslverify = apply_filters( 'https_local_ssl_verify', false );

			// Include Basic auth in loopback requests.
			$auth_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( wp_unslash($_SERVER['PHP_AUTH_USER']) ) : '';

			if ( isset( $auth_user ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( $auth_user . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
			}

			// Make sure PHP process doesn't die before loopback requests complete.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 );
			}

			// Time to wait for loopback requests to finish.
			$timeout = 100;

			$needle_start = "###### wp_scraping_result_start:$scrape_key ######";
			$needle_end   = "###### wp_scraping_result_end:$scrape_key ######";


			if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
				// Close any active session to prevent HTTP requests from timing out
				// when attempting to connect back to the site.
				session_write_close();
			}
			
			$url = home_url( '/' );
			$url = add_query_arg( $scrape_params, $url );
			
			$r = wp_remote_get( $url, compact( 'cookies', 'headers', 'timeout', 'sslverify' ) );
			$body = wp_remote_retrieve_body( $r );
			
			$scrape_result_position = strpos( $body, $needle_start );
			
			$loopback_request_failure = array(
				'code'    => 'loopback_request_failed',
				'message' => __( 'Unable to communicate back with site to check for fatal errors, so the PHP change was reverted. You will need to upload your PHP file change by some other means, such as by using SFTP.' , 'nexter-extension' ),
			);
			$json_parse_failure       = array(
				'code' => 'json_parse_error',
			);
			
			$result = null;
			
			if ( false === $scrape_result_position ) {
				$result = $loopback_request_failure;
			} else {
				$error_output = substr( $body, $scrape_result_position + strlen( $needle_start ) );
				$error_output = substr( $error_output, 0, strpos( $error_output, $needle_end ) );
				$result       = json_decode( trim( $error_output ), true );
				if ( empty( $result ) ) {
					$result = $json_parse_failure;
				}
			}
			
			delete_transient( $transient );
			
			$error_code = null;
			if ( true !== $result ) {
				if ( ! isset( $result['message'] ) ) {
					$message = __( 'Something went wrong.' , 'nexter-extension'  );
				} else {
					$file_msg = (isset($result['file'])) ? $result['file'] : '';
					$message = str_replace($file_msg, '', $result['message']);
					unset( $result['message'] );
					unset( $result['file'] );
				}

				$error_code = new WP_Error( 'php_error', $message, $result );
			}

			if ( is_wp_error( $error_code ) ) {
				wp_send_json_error(
					array_merge(
						array(
							'id' => $post_id,
							'code'    => $error_code->get_error_code(),
							'message' => $error_code->get_error_message(),
						),
						(array) $error_code->get_error_data()
					)
				);
			}else{
				update_post_meta( $post_id, 'nxt-code-php-hidden-execute','yes');
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

			wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'assets/js/admin/nxt-code-snippet.min.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash' ), NEXTER_EXT_VER, true );
			//wp_enqueue_script( 'nxt-code-snippet', NEXTER_EXT_URL . 'code-snippets/build/index.js', array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-dom-ready', 'wp-element','lodash' ), NEXTER_EXT_VER, true );

			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			
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
					'isactivate' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::get_instance()->nexter_activate_status() : ''
				)
			);
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
		 * Add meta Data Create/Update Snippet
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

						$hooks_priority = (isset($_POST['hooks_priority']) && !empty($_POST['hooks_priority'])) ? absint($_POST['hooks_priority']) : 10;
						if(isset($hooks_priority)){
							update_post_meta( $post_id ,'nxt-code-hooks-priority', $hooks_priority);
						}

					}else if($type=='php'){
						update_post_meta( $post_id, 'nxt-code-php-hidden-execute','no');

						$lang_code = wp_unslash($_POST['lang-code']);
						update_post_meta( $post_id ,'nxt-php-code', $lang_code);

						$code_execute = (isset($_POST['code-execute']) && !empty($_POST['code-execute'])) ? sanitize_text_field(wp_unslash($_POST['code-execute'])) : 'global';
						if(!empty($code_execute) && in_array($code_execute, ['global','admin','front-end'])){
							update_post_meta( $post_id , 'nxt-code-execute', $code_execute );
						}
						
						$this->nexter_ext_code_execute($post_id);
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
						'name'        => get_the_title(),
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
					$get_data = [
						'id' => $post->ID,
						'name'        => $post->post_title,
						'description'	=> get_post_meta($post->ID, 'nxt-code-note', true),
						'type'	=> $type,
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
					];
					wp_send_json_success($get_data);
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			} else {
				wp_send_json_error(['message' => 'Invalid post ID']);
			}
		}

		/*
		 * Nexter Builder Code Snippets Css/Js Enqueue
		 */
		public static function nexter_code_snippets_css_js() {
			wp_register_script( 'nxt-snippet-js', false );
            wp_enqueue_script( 'nxt-snippet-js' );
			//new Snippet
			$css_actions = self::get_snippets_ids_list( 'css' );
			
			if( !empty( $css_actions ) ){
				foreach ( $css_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {
						$css_code = get_post_meta( $post_id, 'nxt-css-code', true );
						if(!empty($css_code) ){
							wp_register_style( 'nxt-snippet-css', false );
    						wp_enqueue_style( 'nxt-snippet-css' );

							wp_add_inline_style( 'nxt-snippet-css', wp_specialchars_decode($css_code) );
						}
					}
				}
			}
			//New Snippet
			$javascript_actions = self::get_snippets_ids_list( 'javascript' );
			if( !empty( $javascript_actions ) ){
				foreach ( $javascript_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {
						$javascript_code = get_post_meta( $post_id, 'nxt-javascript-code', true );
						if(!empty($javascript_code) ){
							wp_add_inline_script( 'nxt-snippet-js', html_entity_decode($javascript_code, ENT_QUOTES) );
						}
					}
				}
			}

			//Old Nexter Builder Snippet
			$old_css_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'css' );
			if( !empty( $old_css_actions ) ){
				foreach ( $old_css_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
						$old_css_code = get_post_meta( $post_id, 'nxt-code-css-snippet', true );
						$old_css_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if(!empty($old_css_code) && ( empty($old_css_code_execute) || (!empty($old_css_code_execute) && $old_css_code_execute=='yes') ) ){
							wp_register_style( 'nxt-snippet-css', false );
    						wp_enqueue_style( 'nxt-snippet-css' );

							wp_add_inline_style( 'nxt-snippet-css', wp_specialchars_decode($old_css_code) );
						}
					}
				}
			}
			$old_js_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'javascript' );
			if( !empty( $old_js_actions ) ){
				foreach ( $old_js_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
						$old_js_code = get_post_meta( $post_id, 'nxt-code-javascript-snippet', true );
						$old_js_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if(!empty($old_js_code) && ( empty($old_js_code_execute) || (!empty($old_js_code_execute) && $old_js_code_execute=='yes' ) ) ){
							wp_add_inline_script( 'nxt-snippet-js', html_entity_decode($old_js_code, ENT_QUOTES) );
						}
					}
				}
			}
		}
		
		/*
		 * Nexter Builder Code Snippets Html Hooks
		 */
		public static function nexter_code_html_hooks_actions() {
			//New Snippet
			$html_actions = self::get_snippets_ids_list( 'htmlmixed' );
			
			if( !empty( $html_actions ) ){
				foreach ( $html_actions as $post_id) {
					$post_type = get_post_type();

					if ( self::$snippet_type != $post_type ) {
					
						$hook_action = get_post_meta( $post_id, 'nxt-code-html-hooks', true );
						$hook_priority = get_post_meta( $post_id, 'nxt-code-hooks-priority', true );
						add_action(
							$hook_action,
							function() use ( $post_id ) {
								$html_code = get_post_meta( $post_id, 'nxt-htmlmixed-code', true );
								echo $html_code;
							},
							$hook_priority
						);
					}
				}
			}

			//Old Nexter Builder Snippet
			$old_html_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'html' );
			
			if( !empty( $old_html_actions ) ){
				foreach ( $old_html_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
					
						$old_hook_action = get_post_meta( $post_id, 'nxt-code-hooks-action', true );
						$old_html_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if( empty($old_html_code_execute) || ( !empty($old_html_code_execute) && $old_html_code_execute=='yes' ) ){
							add_action(
								$old_hook_action,
								function() use ( $post_id ) {
									$html_code = get_post_meta( $post_id, 'nxt-code-htmlmixed-snippet', true );
									echo $html_code;
								},
								10
							);
						}
					}
				}
			}
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
							$code_status = get_post_meta( $post_id, 'nxt-code-status', true );
							
							$authorID = get_post_field( 'post_author', $post_id );
							$theAuthorDataRoles = get_userdata($authorID);
							$theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
							
							if ( in_array( 'administrator', $theRolesAuthor ) && !empty($code_status)) {
								$php_code = get_post_meta( $post_id, 'nxt-php-code', true );
								$code_execute = get_post_meta( $post_id, 'nxt-code-execute', true );
								$code_hidden_execute = get_post_meta( $post_id, 'nxt-code-php-hidden-execute', true );

								if(!empty($code_hidden_execute) && $code_hidden_execute==='yes' && !empty($php_code) && !empty($code_execute)){
									
									if($code_execute=='global'){
										$error_code = $this->nexter_code_php_snippets_execute($php_code);
									}else if(is_admin() && $code_execute=='admin'){
										$error_code = $this->nexter_code_php_snippets_execute($php_code);
									}else if(! is_admin() && $code_execute=='front-end'){
										$error_code = $this->nexter_code_php_snippets_execute($php_code);
									}
								}
							}
						}
					}
					
				}
			}
		}
		
	}
}
Nexter_Builder_Code_Snippets_Render::get_instance();