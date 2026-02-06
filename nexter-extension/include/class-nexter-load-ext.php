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
			add_action( 'after_setup_theme', function() {
				if(defined('NXT_VERSION') && version_compare( NXT_VERSION, '4.2.0', '>' ) ){
					require_once NEXTER_EXT_DIR . 'include/classes/third-party/class-elementor-pro.php';
				}
			} );
			add_action( 'after_setup_theme', [ $this, 'theme_after_setup' ] );

			$nexter_white_label = get_option('nexter_white_label');
            if( is_admin() && ( empty($nexter_white_label) || ( !empty($nexter_white_label['nxt_help_link']) && $nexter_white_label['nxt_help_link'] !== 'on') ) ) {
				add_filter( 'plugin_action_links_' . NEXTER_EXT_BASE, array( $this, 'add_settings_pro_link' ) );
			}

			if( is_admin() ){
				add_filter( 'plugin_row_meta', array( $this, 'add_extra_links_plugin_row_meta' ), 10, 2 );
				add_filter( 'admin_body_class', function( $classes ) {
					// Security: Sanitize input
					$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
					if ( $page === 'nxt_code_snippets' ) {
						$classes .= ' nxt-code-snippet-page ';
					}
					return $classes;
				}, 11);
			}

			if( !defined( 'NXT_PRO_EXT' ) && empty( get_option( 'nexter-ext-pro-load-notice' ) ) ) {
				global $pagenow;
				// Security: Sanitize input
				$get_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
				if(!empty( $pagenow ) && ! ( $pagenow == 'update.php' && ! empty( $get_action ) && ( $get_action === 'install-plugin' || $get_action === 'upgrade-plugin' ))){
					add_action( 'admin_notices', array( $this, 'nexter_extension_pro_load_notice' ) );
				}
				add_action( 'wp_ajax_nexter_ext_pro_dismiss_notice', array( $this, 'nexter_ext_pro_dismiss_notice_ajax' ) );
			}
	
			if( defined( 'TPGB_VERSION' ) && version_compare( TPGB_VERSION, '4.6.0', '<' ) ){
                add_action( 'admin_notices', array( $this , 'nexter_blocks_version_notice' )  );
            }
            
			add_action( 'wp_ajax_nexter_ext_dismiss_notice', array( $this, 'nexter_ext_dismiss_notice_data' ) );
			
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'nxt_elementor_wdk_preset_script' ) );
			if ( class_exists( '\Elementor\Plugin' )) {
				add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'nxt_elementor_wdk_preset_script' ) );
			}

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

				add_filter('rank_math/sitemap/post_type_exclude', function ($exclude, $post_type) {
					if ($post_type === 'nxt_builder') {
						$exclude = true;
					}
					return $exclude;
				}, 10, 2);
				
				// Remove from XML sitemap
				add_filter( 'rank_math/sitemap/post_types', function( $post_types ) {
					if ( isset( $post_types['nxt_builder'] ) ) {
						unset( $post_types['nxt_builder'] ); // Force remove from XML sitemap
					}
					return $post_types;
				}, 99 );

				// Remove from HTML sitemap
				add_filter( 'rank_math/sitemap/html/post_types', function( $post_types ) {
					if ( isset( $post_types['nxt_builder'] ) ) {
						unset( $post_types['nxt_builder'] ); // Force remove from HTML sitemap
					}
					return $post_types;
				}, 20 );

				
				add_filter('wp_sitemaps_post_types', function ($post_types) {
					if ( isset( $post_types['nxt_builder'] ) ) {
						unset( $post_types['nxt_builder'] ); // Force remove from HTML sitemap
					}
					return $post_types;
				});

				//Yoast SEO
				add_filter('wpseo_sitemap_exclude_post_type', function ($excluded, $post_type) {
					if ($post_type === 'nxt_builder') {
						return true;
					}
					return $excluded;
				}, 10, 2);
			}
			
		}
		
		public function nexter_ext_copyright_symbol(){
			return '&copy;';
		}

		public function nexter_ext_getyear( $atts ){
			$atts = shortcode_atts( array(
				'format' => 'Y',
			), $atts, 'nxt-year' );
			return esc_html( wp_date( $atts['format'] ) );
		}

		/**
		 * Adds Links to the plugins page.
		 * @since 1.1.0
		 */
		public function add_settings_pro_link( $links ) {
			// Settings link.
			if ( current_user_can( 'manage_options' ) ) {
				$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=nexter_welcome#/utilities' ), __( 'Settings', 'nexter-extension' ) );
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
				$pro_link = sprintf( '<a href="%s" target="_blank" style="color: #cc0000;font-weight: 700;" rel="noopener noreferrer">%s</a>', esc_url('https://nexterwp.com/pricing?utm_source=wpbackend&utm_medium=banner&utm_campaign=nextersettings'), __( 'Upgrade PRO', 'nexter-extension' ) );
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
			$nexter_white_label = get_option('nexter_white_label');

			if ( strpos( $plugin_file, NEXTER_EXT_BASE ) !== false && current_user_can( 'manage_options' ) && !apply_filters('nexter_remove_branding',false) && ( empty($nexter_white_label) || ( !empty($nexter_white_label['nxt_help_link']) && $nexter_white_label['nxt_help_link'] !== 'on') ) ) {
				$new_links = array(
					'official-site' => '<a href="'.esc_url('https://nexterwp.com/?utm_source=wpbackend&utm_medium=banner&utm_campaign=nextersettings').'" target="_blank" rel="noopener noreferrer">'.esc_html__( 'Official Site', 'nexter-extension' ).'</a>',
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
			
			if(strpos( $plugin_file, NEXTER_EXT_BASE ) !== false && current_user_can( 'manage_options' ) && isset($nexter_white_label['nxt_help_link']) && !empty($nexter_white_label['nxt_help_link']) && $nexter_white_label['nxt_help_link'] == 'on' ){
                foreach ( $plugin_meta as $key => $meta ) {
					if ( stripos( $meta, 'View details' ) !== false ) {
						unset( $plugin_meta[ $key ] );
					}
				}
            }

			return $plugin_meta;
		}

		/**
		 * Template(Builder) Load
		 */
		public function nexter_builder_post_type() {
			$template_uri = NEXTER_EXT_DIR . 'include/nexter-template/';
			if ( ! post_type_exists( 'nxt_builder' ) ) {
				require_once $template_uri . 'nexter-template-function.php';
			}
			
			require_once $template_uri . 'template-import-export.php';
			require_once $template_uri . 'nexter-builder-shortcode.php';

			require_once NEXTER_EXT_DIR . 'include/custom-options/module/nexter-display-sections-hooks.php';
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
			if(is_admin()){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/nexter-whats-new.php';
			}
			
			if ( ! class_exists( 'Nexter_Builder_Compatibility' ) ) {
				$include_uri = NEXTER_EXT_DIR . 'include/classes/';
				require_once $include_uri . 'third-party/class-builder-compatibility.php';
				require_once $include_uri . 'third-party/class-nxt-theme-builder-load.php';
				require_once $include_uri . 'third-party/class-bricks.php';
				require_once $include_uri . 'third-party/class-elementor.php';
				
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
			$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
			wp_enqueue_script( 'nexter-ext-builder-js', NEXTER_EXT_URL .'assets/js/admin/nexter-ext-admin'.$minified.'.js', array(), NEXTER_EXT_VER, true );

			$user = wp_get_current_user();
            $allowed_roles = array( 'administrator' );
			if( defined('NEXTER_EXT') && get_post_type() == 'nxt_builder' && ('post.php' == $hook_suffix || 'edit.php' == $hook_suffix || 'post-new.php' == $hook_suffix) && !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ){

				/* Edit Page Side Edit Condition Button Enqueue */
				// Security: Sanitize input
				$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
				if(is_admin() && $action == 'edit'){
					wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2.min.css', array(), NEXTER_EXT_VER );
			    	wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2.min.js', array(), NEXTER_EXT_VER, false );
					//Editor Theme Builder Conditional
					wp_enqueue_style( 'nexter-ext-edit-condition-css', NEXTER_EXT_URL .'assets/css/admin/nexter-edit-condition.min.css', array(), NEXTER_EXT_VER );
					wp_enqueue_script( 'nexter-ext-edit-condition-js', NEXTER_EXT_URL .'assets/js/admin/nexter-edit-condition'.$minified.'.js', array(), NEXTER_EXT_VER, true );

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
			// Only show for admins
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

			$nxtData = get_option( 'nexter-ext-install-data' );
            if ( ! is_array( $nxtData ) || empty( $nxtData['install-date'] ) ) {
                return;
            }

            // Convert from d-m-Y to timestamp
            $inTime = strtotime( $nxtData['install-date'] );

            if ( ! $inTime ) {
                return;
            }

            $dayCount = floor( ( current_time( 'timestamp' ) - $inTime ) / DAY_IN_SECONDS );
            // Show after 2 days
            if ( $dayCount >= 2 ) {
				if(defined('TPGB_VERSION') && !defined('TPGBP_VERSION')){
					$noticeTitle = esc_html__( 'Upgrade to Nexter Pro to Unlock 1000+ Templates, 90+ Blocks & 50+ Extensions', 'nexter-extension');
					$noticeDesc = esc_html__( 'A single suite to design faster, reduce plugins, and keep your site lightweight.', 'nexter-extension');
				}else{
					$noticeTitle = esc_html__( 'Replace 50+ WordPress Plugins with Nexter Extension Pro', 'nexter-extension' );
					$noticeDesc = esc_html__( 'Nexter Extension free options cover the basics, but Pro takes it further with features like Login Protection, 2-Factor Authentication, Branded WP Admin & More.', 'nexter-extension' );
				}

				echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="nexter_block_show_pro">';
					echo '<div class="nexter-license-activate">';
                        echo '<div class="nexter-license-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"><rect width="24" height="24" fill="#1717CC" rx="5"/><path fill="#fff" d="M12.605 17.374c.026 0 .038.013.039.038.102 0 .192.014.27.04.025 0 .05.012.076.037.128.077.23.167.307.27v.038c0 .026.013.051.039.077v.038a.63.63 0 0 1 .038.193l-.038 1.882h-2.652v-2.613h1.921Zm.308-13.414c.128 0 .23.038.308.115a.259.259 0 0 1 .115.23V15.26c.025.153-.052.295-.23.423a.872.872 0 0 1-.578.192h-1.844V3.96h2.23Z"/></svg></div>';
                        echo '<div class="nexter-license-content">';
                            echo '<h2>' . esc_html($noticeTitle) . '</h2>';
                            echo '<p>' . esc_html($noticeDesc) . '</p>';
                            echo '<a href="' . esc_url('https://nexterwp.com/pricing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=pluginpage') . '" target="_blank" rel="noopener noreferrer" class="nxt-nobtn-primary">' . esc_html__( 'Upgrade Now', 'nexter-extension' ) . '</a>';
                            echo '<a href="' . esc_url('https://nexterwp.com/free-vs-pro/?utm_source=wpbackend&utm_medium=admin&utm_campaign=pluginpage') . '" target="_blank" rel="noopener noreferrer" class="nxt-nobtn-secondary">' . esc_html__( 'Compare Free vs Pro', 'nexter-extension' ) . '</a>';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';
			}
		}

		public function nexter_blocks_version_notice() {
			if ( get_option( 'nexter_blocks_installed_dismissed' ) ) {
                return;
            }

            echo '<div class="notice notice-info is-dismissible nxt-notice-wrap" data-notice-id="nexter_blocks_installed">';
                echo '<div class="nexter-license-activate" style="align-items: center;">';
                    echo '<div class="nexter-license-icon" style="display: flex;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"><rect width="24" height="24" fill="#1717CC" rx="5"/><path fill="#fff" d="M12.605 17.374c.026 0 .038.013.039.038.102 0 .192.014.27.04.025 0 .05.012.076.037.128.077.23.167.307.27v.038c0 .026.013.051.039.077v.038a.63.63 0 0 1 .038.193l-.038 1.882h-2.652v-2.613h1.921Zm.308-13.414c.128 0 .23.038.308.115a.259.259 0 0 1 .115.23V15.26c.025.153-.052.295-.23.423a.872.872 0 0 1-.578.192h-1.844V3.96h2.23Z"/></svg></div>';
                    echo '<div class="nexter-license-content">';
                        echo '<h2>' . esc_html__( 'To continue using all latest features smoothly, please update Nexter Blocks to version 4.6.0 or higher.', 'nexter-extension' ) . '</h2>';
                    echo '</div>';
                echo '</div>';
            echo '</div>';
		}

		/**
		 * Nexter Pro Notice Dismiss Ajax
		 */
		public function nexter_ext_pro_dismiss_notice_ajax(){
			// Security: Verify nonce
			//check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
			
			// Security: Check user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
			}
			
			update_option( 'nexter-ext-pro-load-notice', 1 );
			wp_send_json_success();
		}

		public function nexter_ext_dismiss_notice_data(){
			// Security: Verify nonce
			//check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
			
			// Security: Check user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
			}
			
			if ( ! empty($_POST['notice_id']) ) {
				// Security: Use sanitize_key for option names and whitelist allowed notices
				$notice_id = sanitize_key( $_POST['notice_id'] );
				$allowed_notices = array( 'nexter_blocks_installed', 'nexter_block_show_pro' );
				
				if ( in_array( $notice_id, $allowed_notices, true ) ) {
					update_option( $notice_id . '_dismissed', true );
					wp_send_json_success( array( 'dismissed' => $notice_id ) );
				} else {
					wp_send_json_error( array( 'content' => __( 'Invalid notice ID.', 'nexter-extension' ) ) );
				}
			} else {
				wp_send_json_error( array( 'content' => __( 'Invalid Notice ID', 'nexter-extension' ) ) );
			}
		}
		
		/**
		 * Check if current page is in Elementor edit or preview mode
		 * This prevents frontend_only snippets from running in Elementor editor or preview
		 */
		private function is_elementor_edit_or_preview_mode() {
			if (class_exists('\\Elementor\\Plugin')) {
				$plugin = \Elementor\Plugin::$instance;
				if ((isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) ||
					(isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode())) {
					return true;
				}
			}
			// Security: Sanitize input
			$get_elementor_preview = isset( $_GET['elementor-preview'] ) ? sanitize_text_field( wp_unslash( $_GET['elementor-preview'] ) ) : '';
			$get_elementor = isset( $_GET['elementor'] ) ? sanitize_text_field( wp_unslash( $_GET['elementor'] ) ) : '';
			if ( ! empty( $get_elementor_preview ) || ! empty( $get_elementor ) ) {
				return true;
			}
			return false;
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
			if(('edit.php' === $hook_suffix && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'nxt_builder') || get_post_type() == 'nxt_builder' ){
				$wdkPlugin = false;
				include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            	$pluginslist = get_plugins();

				$wdkVersion = '';
				$wdkactivate = false;
				if ( isset( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) && !empty( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) ) {
					if( is_plugin_active('wdesignkit/wdesignkit.php') ){
						$wdkPlugin = true;
						// Get WDesignKit version
						$wdkVersion = '1.0.0'; // Default version
						if (defined('WDKIT_VERSION')) {
							$wdkVersion = WDKIT_VERSION;
						} else {
							// Try to get version from plugin data
							$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/wdesignkit/wdesignkit.php');
							if (isset($plugin_data['Version'])) {
								$wdkVersion = $plugin_data['Version'];
							}
						}
					}else{
						$wdkactivate = true;
					}
				}
				$loaded = array( 'jquery');
				if ( class_exists( '\Elementor\Plugin' ) ) {
					$loaded[] = 'elementor-common';
				}
				$nexter_white_label = get_option('nexter_white_label');
				$wdk_integration = (!empty($nexter_white_label['nxt_wdk_integration'])) ? $nexter_white_label['nxt_wdk_integration'] : '';
				if($wdk_integration !== 'on'){
					wp_enqueue_style( 'nexter-ele-wdkit-preset', NEXTER_EXT_URL .'assets/css/admin/nxt-wdk-preset.css', array(), NEXTER_EXT_VER );
					wp_enqueue_script( 'nexter-ele-wdkit-preset', NEXTER_EXT_URL .'assets/js/admin/nxt-ele-wdk-preset.js', $loaded, NEXTER_EXT_VER, true );
				
					global $post;
					$layout_type = '';
					if( !empty( $post ) ){
						$post_id = isset($post->ID) ? $post->ID : '';
						if(!empty($post_id)){
							$layout_type = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
						}
					}
					wp_localize_script(
						'nexter-ele-wdkit-preset',
						'nxt_ele_wdkit',
						array(
							'ajax_url'    => admin_url( 'admin-ajax.php' ),
							'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
							'wdkPlugin' => $wdkPlugin,
							'wdkactivate' => $wdkactivate,
							'wdkVersion' => isset($wdkVersion) ? $wdkVersion : '1.0.0',
							'layout_type' => $layout_type,
						)
					);
				}
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