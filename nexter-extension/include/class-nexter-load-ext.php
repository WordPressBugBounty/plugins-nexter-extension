<?php 
/**
 * Nexter Extensions Load
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Extensions_Load' ) ) {

	class Nexter_Extensions_Load {

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
		 * @since 1.0.4
		 */
		public function __construct() {
			add_action( 'after_setup_theme', [ $this, 'nexter_builder_post_type' ] );
			$this->include_custom_options();
			add_action( 'after_setup_theme', [ $this, 'theme_after_setup' ] );
			if ( is_admin() ) {
				add_filter( 'plugin_action_links_' . NEXTER_EXT_BASE, array( $this, 'add_settings_pro_link' ) );
				add_filter( 'plugin_row_meta', array( $this, 'add_extra_links_plugin_row_meta' ), 10, 2 );
			}

			if((!isset($_GET['test_code']) || empty($_GET['test_code']))){
				$this->nexter_code_php_snippets_actions();
			}
			
			if( !defined( 'NXT_PRO_EXT' ) && empty( get_option( 'nexter-ext-pro-load-notice' ) ) ) {
				add_action( 'admin_notices', array( $this, 'nexter_extension_pro_load_notice' ) );
				add_action( 'wp_ajax_nexter_ext_pro_dismiss_notice', array( $this, 'nexter_ext_pro_dismiss_notice_ajax' ) );
			}
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ) );
			if(is_admin() && current_user_can( 'manage_options' )){
				add_action( 'wp_ajax_nexter_ext_code_execute', array( $this, 'nexter_ext_code_execute' ) );
			}
			
			add_action( 'wpml_loaded', array( $this, 'nxt_wpml_compatibility' ) );

			add_action( 'init', array( $this, 'home_page_code_execute' ) );
			add_shortcode( 'nxt-copyright', array( $this,'nexter_ext_copyright_symbol') );
			add_shortcode( 'nxt-year', array( $this,'nexter_ext_getyear') );

			add_filter( 'user_has_cap', array( $this,'restrict_editor_from_nxt_code_php_snippet'), 10, 3 );
			if ( post_type_exists( 'nxt_builder' ) ) {
				add_filter(
					'map_meta_cap',
					function( $required_caps, $cap, $user_id, $args ) {
							if ( 'edit_post' === $cap || 'delete_post' === $cap) {
									$post = get_post( $args[0] );
									if ( !empty($post) && $post->post_type=='nxt_builder' && user_can( $post->post_author, 'administrator' ) ) {
											if( get_post_meta($args[0], 'nxt-hooks-layout', true) == 'code_snippet' && get_post_meta($args[0], 'nxt-hooks-layout-code-snippet', true) == 'php' ){
													$required_caps[] = 'administrator';
											}
									}
							}
			
							return $required_caps;
					}, 10, 4
				);
			}
			
		}
		
		public function nexter_ext_copyright_symbol(){
			return '&copy;';
		}

		public function nexter_ext_getyear( $atts ){
			$atts = shortcode_atts( array(
				'format' => 'Y',
			), $atts, 'nxt-year' );
			return wp_date( $atts['format'] );
		}

		/**
		 * Adds Links to the plugins page.
		 * @since 1.1.0
		 */
		public function add_settings_pro_link( $links ) {
			// Settings link.
			if ( current_user_can( 'manage_options' ) ) {
				$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'themes.php?page=nexter_settings_welcome' ), __( 'Settings', 'nexter-ext' ) );
				$links = (array) $links;
				array_unshift( $links, $settings_link );
				if ( !apply_filters('nexter_remove_branding',false) ) {
					$need_help = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url('https://wordpress.org/support/plugin/nexter-extension/'), __( 'Need Help?', 'nexter-ext' ) );
					$links = (array) $links;
					$links[] = $need_help;
				}
			}

			// Upgrade PRO link.
			if ( ! defined('NXT_PRO_EXT') && !apply_filters('nexter_remove_branding',false) ) {
				$pro_link = sprintf( '<a href="%s" target="_blank" style="color: #cc0000;font-weight: 700;" rel="noopener noreferrer">%s</a>', esc_url('https://nexterwp.com/pricing'), __( 'Upgrade PRO', 'nexter-ext' ) );
				$links = (array) $links;
				$links[] = $pro_link;
			}

			return $links;
		}

		/**
		 * Adds Extra Links to the plugins row meta.
		 * @since 1.1.0
		 */
		public function add_extra_links_plugin_row_meta( $plugin_meta, $plugin_file ) {
 
			if ( strpos( $plugin_file, NEXTER_EXT_BASE ) !== false && current_user_can( 'manage_options' ) && !apply_filters('nexter_remove_branding',false) ) {
				$new_links = array(
					'official-site' => '<a href="'.esc_url('https://nexterwp.com/').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Official Site', 'nexter-ext' ).'</a>',
					'docs' => '<a href="'.esc_url('https://docs.posimyth.com/nexterwp').'" target="_blank" rel="noopener noreferrer" style="color:green;">'.esc_html__( 'Docs', 'nexter-ext' ).'</a>',
					'join-community' => '<a href="'.esc_url('https://www.facebook.com/groups/139678088029161/').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Join Community', 'nexter-ext' ).'</a>',
					'whats-new' => '<a href="'.esc_url('https://roadmap.nexterwp.com/updates?filter=Free+Theme').'" target="_blank" rel="noopener noreferrer" style="color: orange;">'.esc_html__( 'What\'s New?', 'nexter-ext' ).'</a>',
					'req-feature' => '<a href="'.esc_url('https://roadmap.nexterwp.com/boards/feature-requests').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Request Feature', 'nexter-ext' ).'</a>',
					'rate-theme' => '<a href="'.esc_url('https://wordpress.org/support/plugin/nexter-extension/reviews/?filter=5').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Rate Plugin', 'nexter-ext' ).'</a>'
				);
				 
				$plugin_meta = array_merge( $plugin_meta, $new_links );
			}else if(strpos( $plugin_file, NEXTER_EXT_BASE ) !== false && current_user_can( 'manage_options' ) && apply_filters('nexter_remove_branding',false)){
				unset($plugin_meta[2]);
			}
			 
			return $plugin_meta;
		}

		public function home_page_code_execute(){
			if(isset($_GET['test_code']) && $_GET['test_code']=='code_test' && isset($_GET['code_id']) && !empty($_GET['code_id'])){
				$code_id = isset($_GET['code_id']) ? sanitize_text_field(wp_unslash($_GET['code_id'])) : '';
				$this->nexter_code_test_php_snippets($code_id);
			}
		}
		
		/* code execute*/
		public function nexter_ext_code_execute(){
			
			$user = wp_get_current_user();
			if ( !empty($user) && isset($user->roles) && !in_array( 'administrator', $user->roles ) ) {
				wp_send_json_error(
					array(
						'code'    => 'php_error',
						'message' => __( 'Only Admin can run this.', 'nexter-ext' ),
					)
				);
			}
			$security = !empty($_POST['security']) ? sanitize_key( wp_unslash( $_POST['security'] ) ) : '';
			if ( ! wp_verify_nonce( $security, 'nexter_admin_nonce' ) ) {
				wp_send_json_error(
					array(
						'code'    => 'php_error',
						'message' => 'Security check',
					)
				);
			}
			
			$post_id = !empty($_POST['post_id']) ? sanitize_key( intval(wp_unslash( $_POST['post_id'] )) ) : '';
			if(empty($post_id)){
				wp_send_json_error(
					array(
						'code'    => 'php_error',
						'message' => 'Undefined Content ID',
					)
				);
			}
			
			update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'byyyy' );
			
			$scrape_key   = md5( rand() );
			$transient    = 'scrape_key_' . $scrape_key;
			$scrape_nonce = (string) rand();
			// It shouldn't take more than 5 seconds to make the two loopback requests.
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
			if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
			}

			// Make sure PHP process doesn't die before loopback requests complete.
			set_time_limit( 300 );

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
				'message' => __( 'Unable to communicate back with site to check for fatal errors, so the PHP change was reverted. You will need to upload your PHP file change by some other means, such as by using SFTP.' , 'nexter-ext' ),
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
					$message = __( 'Something went wrong.' , 'nexter-ext'  );
				} else {
					$file_msg = (isset($result['file'])) ? $result['file'] : '';
					$message = str_replace($file_msg, '', $result['message']);
					unset( $result['message'] );
					unset( $result['file'] );
				}

				$error_code = new WP_Error( 'php_error', $message, $result );
			}
			update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'byyyy' );
			if ( is_wp_error( $error_code ) ) {
				wp_send_json_error(
					array_merge(
						array(
							'code'    => $error_code->get_error_code(),
							'message' => $error_code->get_error_message(),
						),
						(array) $error_code->get_error_data()
					)
				);
			} else {
				update_post_meta( $post_id, 'nxt-code-php-hidden-execute', 'hiiii' );
				wp_send_json_success(
					array(
						'message' => 'successfully',
					)
				);
			}
		}
		
		/**
		 * Template(Builder) Load
		 */
		public function nexter_builder_post_type() {
			if(defined('NXT_VERSION') || defined('HELLO_ELEMENTOR_VERSION')){
				$template_uri = NEXTER_EXT_DIR . 'include/nexter-template/';
				
				require_once $template_uri . 'nexter-template-function.php';
				require_once $template_uri . 'template-import-export.php';
				require_once $template_uri . 'nexter-builder-shortcode.php';

				require_once NEXTER_EXT_DIR . 'include/custom-options/module/nexter-display-sections-hooks.php';
			}
		}

		/*
		 * Nexter Wpml Compatibility
		 * @since 2.0.3
		 */
		public function nxt_wpml_compatibility(){
			require_once NEXTER_EXT_DIR . 'include/classes/nexter-class-wpml-compatibility.php';
		}
		
		/*
		 * Custom Options Load
		 */
		public function include_custom_options(){
			$custom_opt_uri = NEXTER_EXT_DIR . 'include/custom-options/';

			require_once NEXTER_EXT_DIR . 'include/classes/nexter-class-load.php';
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/custom-fields/nxt-custom-fields.php';

			if ( ! class_exists( 'Nexter_Builder_Compatibility' ) ) {
				$include_uri = NEXTER_EXT_DIR . 'include/classes/';
				require_once $include_uri . 'third-party/class-builder-compatibility.php';
				require_once $include_uri . 'third-party/class-nxt-theme-builder-load.php';
				require_once $include_uri . 'third-party/class-elementor.php';
				require_once $include_uri . 'third-party/class-elementor-pro.php';
				require_once $include_uri . 'third-party/class-gutenberg.php';
				require_once $include_uri . 'third-party/class-visual-composer.php';
				require_once $include_uri . 'third-party/class-beaver.php';
				require_once $include_uri . 'third-party/class-beaver-build-theme.php';
			}
			
			if ( !class_exists( 'CMB2_Bootstrap_290' ) ) {
				require_once $custom_opt_uri . 'metabox/init.php';
			}

			if(class_exists( 'CMB2_Bootstrap_290' )){
				require_once NEXTER_EXT_DIR . 'include/nexter-template/nexter-import-settings.php';
				
				require_once $custom_opt_uri.'metabox/extension/cmb2-conditionals.php';
				require_once $custom_opt_uri.'metabox/extension/cmb2-image-select/cmb2-field-image-select.php';
				require_once $custom_opt_uri.'metabox/extension/cmb-field-select2/cmb-field-select2.php';
				require_once $custom_opt_uri.'metabox/extension/cmb2-switch-button/cmb2-switch-button.php';
	
				require_once $custom_opt_uri . 'module/nexter-display-conditional-rules.php';
				require_once $custom_opt_uri . 'module/nexter-display-singular-archives-rules.php';
				require_once $custom_opt_uri . 'module/nexter-display-singular-rules.php';
				require_once $custom_opt_uri . 'module/nexter-display-archives-rules.php';
				
				if(is_admin()){
					require $custom_opt_uri . 'nexter-sections-settings.php';
				}
				
			}
		}

		/**
		 * After Theme Setup
		 */
		public function theme_after_setup() {
			if(!defined('NXT_VERSION')){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/nexter-ext-panel-settings.php';
			}
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-extra-settings-extension.php';
			require_once NEXTER_EXT_DIR . 'include/nexter-template/nexter-post-type-compatibility.php';
		}

		public function enqueue_scripts_admin( $hook_suffix ){
			wp_enqueue_script( 'nexter-ext-builder-js', NEXTER_EXT_URL .'assets/js/admin/nexter-ext-admin.min.js', array(), NEXTER_EXT_VER );
			if ( class_exists( 'CMB2_Bootstrap_290' ) ) {
				wp_enqueue_script( 'nxt-cmb2-conditionals', NEXTER_EXT_URL .'include/custom-options/metabox/extension/cmb2-conditionals.js', array() );
			}
			
			if(defined('HELLO_ELEMENTOR_VERSION')){
				wp_enqueue_style( 'nxt-ext-metabox-editor-style', NEXTER_EXT_URL .'assets/css/admin/metabox-editor-style.min.css', array(), NEXTER_EXT_VER );
			}
			
			if('post.php' == $hook_suffix || 'post-new.php' == $hook_suffix) {
				wp_enqueue_script( 'nxt-builder-editor', NEXTER_EXT_URL .'assets/js/admin/nexter-builder-editor.min.js', array(), NEXTER_EXT_VER );
				if( class_exists('Nexter_Builders_Singular_Conditional_Rules') ){
					$NexterConfig = Nexter_Builders_Singular_Conditional_Rules::$Nexter_Singular_Config;
					$NexterConfig['nxt_archives'] = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
					wp_localize_script( 'nxt-builder-editor', 'NexterConfig', $NexterConfig );
				}
			}
			
			$user = wp_get_current_user();
            $allowed_roles = array( 'administrator' );
			if( defined('NEXTER_EXT') && get_post_type() == 'nxt_builder' && ('post.php' == $hook_suffix || 'edit.php' == $hook_suffix || 'post-new.php' == $hook_suffix) && !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ){
				
				$js_url = NEXTER_EXT_URL .'assets/js/admin/codemirror/';
				wp_deregister_style( 'wp-codemirror' );
				wp_enqueue_style( 'nxt-codemirror', NEXTER_EXT_URL .'assets/css/codemirror/codemirror.min.css', array() );
				//Main
				wp_deregister_script( 'wp-codemirror' );
				wp_enqueue_script( 'nxt-codemirror', $js_url.'codemirror.min.js', [], NEXTER_EXT_VER, true );
				
				//Mode
				wp_enqueue_script( 'nexter-matchbrackets-addon', $js_url.'matchbrackets.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-htmlmixed-mode', $js_url.'htmlmixed.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-javascript', $js_url.'javascript.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-css', $js_url.'css.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-clike-mode', $js_url.'clike.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-php-mode', $js_url.'php.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-xml-mode', $js_url.'xml.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				
				//hint
				wp_enqueue_script( 'nexter-show-hint', $js_url.'show-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-anyword-hint', $js_url.'anyword-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-xml-hint', $js_url.'xml-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-css-hint', $js_url.'css-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-html-hint', $js_url.'html-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-javascript-hint', $js_url.'javascript-hint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-jshint', $js_url.'jshint.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-csslint', $js_url.'csslint.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				//lint
				wp_enqueue_script( 'nexter-lint', $js_url.'lint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				wp_enqueue_script( 'nexter-javascript-lint', $js_url.'javascript-lint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-coffeescript-lint', $js_url.'coffeescript-lint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-css-lint', $js_url.'css-lint.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				wp_enqueue_script( 'nexter-coffeescript-mode', $js_url.'coffeescript.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				
				wp_enqueue_script( 'nexter-autorefresh-addon', $js_url.'autorefresh.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-closebrackets-addon', $js_url.'closebrackets.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-closetag-addon', $js_url.'closetag.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				
				wp_enqueue_script( 'nexter-matchtags-addon', $js_url.'matchtags.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-trailingspace-addon', $js_url.'trailingspace.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				wp_enqueue_script( 'nexter-selection-pointer-addon', $js_url.'selection-pointer.min.js', ['nxt-codemirror'], NEXTER_EXT_VER, true );
				//wp_enqueue_script( 'nexter-html-lint', $js_url.'html-lint.min.js', ['nxt-codemirror','nexter-html-hint'], NEXTER_EXT_VER, true );
			
				//
				/* Code Snippet Field Metabox
				 * @since 1.0.9
				 */
				$post_id = (isset($_GET['post']) && !empty($_GET['post'])) ? intval(wp_unslash( $_GET['post'] ) ) : 'nxt_post_id';
				wp_add_inline_script( 'nxt-codemirror', '
					window.addEventListener("load", (event) => {
						var jssnippet = document.getElementById("nxt-code-javascript-snippet")
						if(jssnippet){
							var nxtJavascript = CodeMirror.fromTextArea(jssnippet, {
								lineNumbers: true,
								mode: {name: "javascript", globalVars: true},
								gutters: ["CodeMirror-lint-markers"],
								lint: true,
								autoRefresh:true,
								lineWrapping:true,
								matchBrackets:true,
								direction: "ltr",
								extraKeys: {"Ctrl-Space": "autocomplete"},
							  });
						}
						var csssnippet = document.getElementById("nxt-code-css-snippet")
						if(csssnippet){
						var nxtCss = CodeMirror.fromTextArea( csssnippet, {
							lineNumbers: true,
							mode: "css",
							gutters: ["CodeMirror-lint-markers"],
							lint: true,
							autoRefresh:true,
							lineWrapping:true,
							matchBrackets:true,
							direction: "ltr",
							extraKeys: {"Ctrl-Space": "autocomplete"},
						  });
						}
						var htmlsnippet = document.getElementById("nxt-code-htmlmixed-snippet")
						if(htmlsnippet){
							var mixedMode = {
								name: "htmlmixed",
								scriptTypes: [{matches: /\/x-handlebars-template|\/x-mustache/i,
											   mode: null},
											  {matches: /(text|application)\/(x-)?vb(a|script)/i,
											   mode: "vbscript"}]
							  };
							var nxtHtmlMixed = CodeMirror.fromTextArea(htmlsnippet, {
								lineNumbers: true,
								mode: mixedMode,
								gutters: ["CodeMirror-lint-markers"],
								lint: true,
								autoRefresh:true,
								lineWrapping:true,
								matchBrackets:true,
								direction: "ltr",
								extraKeys: {"Ctrl-Space": "autocomplete"},
							});
						}
						
						  var phpsnippet = document.getElementById("nxt-code-php-snippet")
						  if(phpsnippet){
							var nxtPhp = CodeMirror.fromTextArea(document.getElementById("nxt-code-php-snippet"), {
								lineNumbers: true,
								mode: {
									name: "application/x-httpd-php",
									startOpen: !0
								},
								selectionPointer: true,
								gutters: ["CodeMirror-lint-markers"],
								lint: true,
								autoRefresh:true,
								direction: "ltr",
								matchBrackets: true,
								indentUnit: 4,
								indentWithTabs: true
							  });
						  }
						if(wp.data){

						wp.data.subscribe(function () {
							try {
								const {hasNonPostEntityChanges,isSavingPost,isAutosavingPost } = wp.data.select("core/editor");
				
								if (wp.data.select("core/editor").isPublishingPost() || isSavingPost() && !isAutosavingPost()){ 
									code_editor_save_field()
								}else{
									wp.data.select("core/editor").isPreviewingPost() ? code_editor_save_field() : hasNonPostEntityChanges() && jQuery(".components-button.editor-post-publish-button.editor-post-publish-button__button.is-primary").click((function() {
										setTimeout((function() {
											!1 === window.PlusCss && (jQuery(".components-button.editor-entities-saved-states__save-button.is-primary").bind("click", (function() {
												console.log("hasNonPostEntityChanges saving"), code_editor_save_field()
											})))
										}))
									}))
								}
							} catch (e) {
								console.error(e)
							}
					   });
					}
					    function nxt_getUrlParameter(sParam) {
							var sPageURL = window.location.search.substring(1),
								sURLVariables = sPageURL.split("&"),
								sParameterName,
								i;

							for (i = 0; i < sURLVariables.length; i++) {
								sParameterName = sURLVariables[i].split("=");

								if (sParameterName[0] === sParam) {
									return typeof sParameterName[1] === undefined ? true : decodeURIComponent(sParameterName[1]);
								}
							}
							return false;
						}
					   function code_editor_save_field(){
							var error_array = nxtPhp.state.lint.marked;
							var ElMsg = document.querySelector(".nxt-php-code-execute-msg");
							var el = document.querySelector("#nxt-code-php-hidden-execute");
							var nxt_layout = document.querySelector(".cmb2-id-nxt-hooks-layout input[name=nxt-hooks-layout]:checked");
							var nxt_code_snippet = document.querySelector(".cmb2-id-nxt-hooks-layout-code-snippet input[name=nxt-hooks-layout-code-snippet]:checked");
							if(nxt_layout && nxt_layout.value=="code_snippet" && nxt_code_snippet && nxt_code_snippet.value=="php"){
								el.value = "byyyy";
								
								setTimeout(function(){
									let nxt_post_id = nxt_getUrlParameter("post");
									var data = {
										"action": "nexter_ext_code_execute",
										"post_id": '.(!is_array($post_id) ? intval($post_id) : 'nxt_post_id').',
										"security" : nexter_admin_config.ajax_nonce,
									};
									jQuery.post(nexter_admin_config.ajaxurl, data, function(response) {
										if(response && response.success==false){
											if(ElMsg){
												el.value = "byyyy";
												ElMsg.classList.remove("hidden")
												ElMsg.innerHTML = "<b>Warning : </b> There is error in code, Line <b class=\'nxt-snip-errr\'>"+response.data.line+"</b> : <b class=\'nxt-snip-errr\'>\""+response.data.message+ "\".</b> </br>We just saved your website, Check your code twice before pressing Save Button.";
											}
										}else if(response && response.data.message == "successfully"){
											el.value = "hiiii";
											if(ElMsg){
												ElMsg.classList.add("hidden")
												ElMsg.innerHTML = "";
											}
										}
									});
								}, 1500);
							}
							if(phpsnippet){
								nxtPhp.save();
							}
							if(htmlsnippet){
								nxtHtmlMixed.save();
							}
							if(csssnippet){
								nxtCss.save();
							}
							if(jssnippet){
								nxtJavascript.save();
							}
					   }
					   
					});'
				);
			}
		}
		
		/**
		 * Nexter Extension Pro Load Notice
		 */
		public function nexter_extension_pro_load_notice() {
			$admin_notice = '<h4 class="nxt-notice-head">' . esc_html__( 'Design Your Masterpiece With Nexter Pro !!!', 'nexter-ext' ) . '</h4>';
			$admin_notice .= '<p>' . esc_html__( 'Enhance your building experience by setting out with pro version of Nexter WP Theme. Check out why you should upgrade to pro?', 'nexter-ext' );
			$admin_notice .= sprintf( ' <a href="%s" target="_blank" rel="noopener noreferrer" >%s</a>', esc_url('https://nexterwp.com/free-vs-pro-compare/'), esc_html__( 'Free vs Pro', 'nexter-ext' ) ) . esc_html__('. You are backed with our 60 Days Money-Back Guarantee.', 'nexter-ext' ).'.</p>';
			$admin_notice .= '<p>' . sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer" class="button-primary">%s</a>', esc_url('https://nexterwp.com/pricing/'), esc_html__( 'UPGRADE NOW', 'nexter-ext' ) ) . '</p>';
			echo '<div class="notice notice-info nexter-pro-ext-notice is-dismissible">'.wp_kses_post($admin_notice).'</div>';
		}

		/**
		 * Nexter Pro Notice Dismiss Ajax
		 */
		public function nexter_ext_pro_dismiss_notice_ajax(){
			update_option( 'nexter-ext-pro-load-notice', 1 );
		}
		
		/*
		 * Get Code Snippets Php Execute
		 * @since 1.0.4
		 */
		public function nexter_code_php_snippets_actions(){
			global $wpdb;
			
			$code_snippet = 'nxt-hooks-layout';
			$type = 'nxt_builder';
			
			$join_meta = "pm.meta_value = 'code_snippet'";
			
			$nxt_option = 'nxt-build-get-data';
			$get_data = get_option( $nxt_option );
			if( $get_data === false ){
				$get_data = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
				add_option( $nxt_option, $get_data, false );
			}

			$posts = [];
			if(!empty($get_data) && isset($get_data['saved']) && isset($get_data['sections_updated']) && $get_data['saved'] !== $get_data['sections_updated']){

				$sqlquery = "SELECT p.ID, pm.meta_value FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = %s) AND p.post_type = %s AND p.post_status = 'publish' AND ( {$join_meta} ) ORDER BY p.post_date DESC";
				
				$sql3 = $wpdb->prepare( $sqlquery , [ $code_snippet, $type] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				
				$posts  = $wpdb->get_results( $sql3 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				$get_data['sections_updated'] = $get_data['saved'];
				$get_data[ 'code_snippet' ] = $posts;
				update_option( $nxt_option, $get_data );

			}else if( isset($get_data[ 'code_snippet' ]) && !empty($get_data[ 'code_snippet' ])){
				$posts = $get_data[ 'code_snippet' ];
			}
			$php_snippet_filter = apply_filters('nexter_php_codesnippet_execute',true);
			if( !empty($posts) && !empty($php_snippet_filter)){
				foreach ( $posts as $post_data ) {

					$get_layout_type = get_post_meta( $post_data->ID , 'nxt-hooks-layout-code-snippet', false );
					
					if(!empty($get_layout_type) && !empty($get_layout_type[0]) && 'php' == $get_layout_type[0]){
						$post_id = isset($post_data->ID) ? $post_data->ID : '';
						if(!empty($post_id)){
							$authorID = get_post_field( 'post_author', $post_id );
							$theAuthorDataRoles = get_userdata($authorID);
							$theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
							
							if ( in_array( 'administrator', $theRolesAuthor ) ) {
								$php_code = get_post_meta( $post_id, 'nxt-code-php-snippet', true );
								$code_execute = get_post_meta( $post_id, 'nxt-code-execute', true );
								$check_code_execute = get_post_meta( $post_id, 'nxt-code-php-hidden-execute', true );
								$php_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
								
								if( empty($php_code_execute) || (!empty($php_code_execute) && $php_code_execute=='yes') ){
									
									if(!empty($php_code) && !empty($code_execute) && !empty($check_code_execute) && $check_code_execute=='hiiii'){
										
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
					$php_code = get_post_meta( $post_id, 'nxt-code-php-snippet', true );
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

		/*
		 * Remove Capability for the Editor role
		 * @since 2.0.4
		 */
		public function restrict_editor_from_nxt_code_php_snippet( $allcaps, $cap, $args ){
			if ( isset( $args[0] ) && $args[0] === 'nxt-code-php-snippet' && isset( $allcaps['editor'] ) ) {
				$allcaps['editor'] = false; // Remove the capability for the Editor role
			}
			return $allcaps;
		}
	}
}

Nexter_Extensions_Load::get_instance();
if( ! function_exists('nexter_content_load') ){
	
	function nexter_content_load( $post_id ) {
				
		if(!empty( $post_id ) && $post_id != 'none' ){
			$post_id = apply_filters( 'wpml_object_id', $post_id, NXT_BUILD_POST, TRUE  );
			$page_builder_base_instance = Nexter_Builder_Compatibility::get_instance();
			$page_builder_instance = $page_builder_base_instance->get_active_page_builder( $post_id );
			$page_builder_instance->render_content( $post_id );
		}
	}
}