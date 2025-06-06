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
			/* if ( class_exists( '\Elementor\Plugin' )) {
				add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'nxt_elementor_wdk_preset_script' ) );
			} */

			add_action( 'wpml_loaded', array( $this, 'nxt_wpml_compatibility' ) );

			
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
				$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=nexter_welcome' ), __( 'Settings', 'nexter-extension' ) );
				$links = (array) $links;
				array_unshift( $links, $settings_link );
				if ( !apply_filters('nexter_remove_branding',false) ) {
					$need_help = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url('https://wordpress.org/support/plugin/nexter-extension/'), __( 'Need Help?', 'nexter-extension' ) );
					$links = (array) $links;
					$links[] = $need_help;
				}
			}

			// Upgrade PRO link.
			if ( ! defined('NXT_PRO_EXT') && !apply_filters('nexter_remove_branding',false) ) {
				$pro_link = sprintf( '<a href="%s" target="_blank" style="color: #cc0000;font-weight: 700;" rel="noopener noreferrer">%s</a>', esc_url('https://nexterwp.com/pricing'), __( 'Upgrade PRO', 'nexter-extension' ) );
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
					'official-site' => '<a href="'.esc_url('https://nexterwp.com/').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Official Site', 'nexter-extension' ).'</a>',
					'docs' => '<a href="'.esc_url('https://nexterwp.com/help/nexter-extension/').'" target="_blank" rel="noopener noreferrer" style="color:green;">'.esc_html__( 'Docs', 'nexter-extension' ).'</a>',
					'join-community' => '<a href="'.esc_url('https://www.facebook.com/groups/139678088029161/').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Join Community', 'nexter-extension' ).'</a>',
					'whats-new' => '<a href="'.esc_url('https://roadmap.nexterwp.com/updates?filter=Nexter+Extension+-+FREE').'" target="_blank" rel="noopener noreferrer" style="color: orange;">'.esc_html__( 'What\'s New?', 'nexter-extension' ).'</a>',
					'req-feature' => '<a href="'.esc_url('https://roadmap.nexterwp.com/boards/feature-requests').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Request Feature', 'nexter-extension' ).'</a>',
					'rate-theme' => '<a href="'.esc_url('https://wordpress.org/support/plugin/nexter-extension/reviews/?filter=5').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Rate Plugin', 'nexter-extension' ).'</a>'
				);
				 
				$plugin_meta = array_merge( $plugin_meta, $new_links );
			}else if(strpos( $plugin_file, NEXTER_EXT_BASE ) !== false && current_user_can( 'manage_options' ) && apply_filters('nexter_remove_branding',false)){
				unset($plugin_meta[2]);
			}
			 
			return $plugin_meta;
		}

		/**
		 * Template(Builder) Load
		 */
		public function nexter_builder_post_type() {
			//if(defined('NXT_VERSION') || defined('HELLO_ELEMENTOR_VERSION') || defined('ASTRA_THEME_VERSION') || defined('GENERATE_VERSION') || defined('OCEANWP_THEME_VERSION') || defined('KADENCE_VERSION') || function_exists('blocksy_get_wp_theme') || defined('NEVE_VERSION')){
				$template_uri = NEXTER_EXT_DIR . 'include/nexter-template/';
				if ( ! post_type_exists( 'nxt_builder' ) ) {
					require_once $template_uri . 'nexter-template-function.php';
				}
				
				require_once $template_uri . 'template-import-export.php';
				require_once $template_uri . 'nexter-builder-shortcode.php';

				require_once NEXTER_EXT_DIR . 'include/custom-options/module/nexter-display-sections-hooks.php';
			//}
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

			require_once NEXTER_EXT_DIR . 'include/rollback.php';
			require_once NEXTER_EXT_DIR . 'include/classes/nexter-class-load.php';
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/custom-fields/nxt-custom-fields.php';
			require_once NEXTER_EXT_DIR . 'include/panel-settings/nxt-deactive.php';

			if ( ! class_exists( 'Nexter_Builder_Compatibility' ) ) {
				$include_uri = NEXTER_EXT_DIR . 'include/classes/';
				require_once $include_uri . 'third-party/class-builder-compatibility.php';
				require_once $include_uri . 'third-party/class-nxt-theme-builder-load.php';
				require_once $include_uri . 'third-party/class-bricks.php';
				require_once $include_uri . 'third-party/class-elementor.php';
				require_once $include_uri . 'third-party/class-elementor-pro.php';
				require_once $include_uri . 'third-party/class-gutenberg.php';
				require_once $include_uri . 'third-party/class-visual-composer.php';
				require_once $include_uri . 'third-party/class-beaver.php';
				require_once $include_uri . 'third-party/class-beaver-build-theme.php';
			}

			require_once $custom_opt_uri . 'module/nexter-display-conditional-rules.php';
			require_once $custom_opt_uri . 'module/nexter-display-singular-archives-rules.php';
			require_once $custom_opt_uri . 'module/nexter-display-singular-rules.php';
			require_once $custom_opt_uri . 'module/nexter-display-archives-rules.php';
			
			if(is_admin()){
				require $custom_opt_uri . 'nexter-builder-condition.php';
				require $custom_opt_uri . 'nexter-sections-settings.php';
			}
				
		}

		/**
		 * After Theme Setup
		 */
		public function theme_after_setup() {
			require_once NEXTER_EXT_DIR . 'include/panel-settings/nexter-ext-panel-settings.php';
			
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-extra-settings-extension.php';
			require_once NEXTER_EXT_DIR . 'include/nexter-template/nexter-post-type-compatibility.php';
		}

		public function enqueue_scripts_admin( $hook_suffix ){
			wp_enqueue_script( 'nexter-ext-builder-js', NEXTER_EXT_URL .'assets/js/admin/nexter-ext-admin.min.js', array(), NEXTER_EXT_VER, true );
			
			$user = wp_get_current_user();
            $allowed_roles = array( 'administrator' );
			if( defined('NEXTER_EXT') && get_post_type() == 'nxt_builder' && ('post.php' == $hook_suffix || 'edit.php' == $hook_suffix || 'post-new.php' == $hook_suffix) && !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ){

				/* Edit Page Side Edit Condition Button Enqueue */
				if(is_admin() && isset( $_GET['action'] ) && $_GET['action'] == 'edit'){
					wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2.min.css', array(), NEXTER_EXT_VER );
			    	wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2.min.js', array(), NEXTER_EXT_VER, false );
					//Editor Theme Builder Conditional
					wp_enqueue_style( 'nexter-ext-edit-condition-css', NEXTER_EXT_URL .'assets/css/admin/nexter-edit-condition.min.css', array(), NEXTER_EXT_VER );
					wp_enqueue_script( 'nexter-ext-edit-condition-js', NEXTER_EXT_URL .'assets/js/admin/nexter-edit-condition.min.js', array(), NEXTER_EXT_VER, true );

					//wdesignkit Preset
					//wp_enqueue_style( 'nexter-ext-wdk-preset', NEXTER_EXT_URL .'assets/css/admin/nxt-wdk-preset.css', array(), NEXTER_EXT_VER );
					//wp_enqueue_script( 'nexter-ext-wdk-preset', NEXTER_EXT_URL .'assets/js/admin/nxt-wdk-preset.js', array( 'react', 'react-dom','wp-i18n', 'wp-dom-ready', 'wp-element','wp-components', 'wp-block-editor', 'wp-editor' ), NEXTER_EXT_VER, true );

				}
				
				$js_url = NEXTER_EXT_URL .'assets/js/admin/codemirror/';
				wp_deregister_style( 'wp-codemirror' );
				wp_enqueue_style( 'nxt-codemirror', NEXTER_EXT_URL .'assets/css/codemirror/codemirror.min.css', array(), NEXTER_EXT_VER );
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
				
				//
				/* Code Snippet Field Metabox
				 * @since 1.0.9
				 */
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
					});'
				);
			}

		}
		
		/**
		 * Nexter Extension Pro Load Notice
		 */
		public function nexter_extension_pro_load_notice() {
			$admin_notice = '<h4 class="nxt-notice-head">' . esc_html__( 'Design Your Masterpiece With Nexter Extension Pro !!!', 'nexter-extension' ) . '</h4>';
			$admin_notice .= '<p>' . esc_html__( 'Enhance your building experience by setting out with pro version of Nexter Extension. Check out why you should upgrade to pro?', 'nexter-extension' );
			$admin_notice .= sprintf( ' <a href="%s" target="_blank" rel="noopener noreferrer" >%s</a>', esc_url('https://nexterwp.com/free-vs-pro-compare/'), esc_html__( 'Free vs Pro', 'nexter-extension' ) ) . esc_html__('. You are backed with our 60 Days Money-Back Guarantee.', 'nexter-extension' ).'.</p>';
			$admin_notice .= '<p>' . sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer" class="button-primary">%s</a>', esc_url('https://nexterwp.com/pricing/'), esc_html__( 'UPGRADE NOW', 'nexter-extension' ) ) . '</p>';
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
				add_option( $nxt_option, $get_data );
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
								
								$php_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
								
								if( empty($php_code_execute) || (!empty($php_code_execute) && $php_code_execute=='yes') ){
									
									if(!empty($php_code) && !empty($code_execute)){
										
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

		/*
		 * Wdesignkit Preset Load templates Elementor Builder
		 */
		public function nxt_elementor_wdk_preset_script( $hook_suffix ){
			if(\Elementor\Plugin::$instance->editor->is_edit_mode() && get_post_type() == 'nxt_builder' ){

				$wdkPlugin = false;
				include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            	$pluginslist = get_plugins();

				if ( isset( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) && !empty( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) ) {
					if( is_plugin_active('wdesignkit/wdesignkit.php') ){
						$wdkPlugin = true;
					}
				}

				wp_enqueue_style( 'nexter-ele-wdkit-preset', NEXTER_EXT_URL .'assets/css/admin/nxt-wdk-preset.css', array(), NEXTER_EXT_VER );
				wp_enqueue_script( 'nexter-ele-wdkit-preset', NEXTER_EXT_URL .'assets/js/admin/nxt-ele-wdk-preset.js', array( 'jquery','elementor-common' ), NEXTER_EXT_VER, true );
				
				wp_localize_script(
					'nexter-ele-wdkit-preset',
					'nxt_ele_wdkit',
					array(
						'ajax_url'    => admin_url( 'admin-ajax.php' ),
						'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
						'wdkPlugin' => $wdkPlugin,
					)
				);
			}
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