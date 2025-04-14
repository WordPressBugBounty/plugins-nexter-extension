<?php
/**
 * Nexter Default Import Code Snippets
 *
 * @package Nexter Extensions
 * @since 4.1.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Code_Snippets_Import_Data' ) ) {

	class Nexter_Code_Snippets_Import_Data {

		/**
		 * Member Variable
		 */
		private static $instance;

		private static $snippet_type = 'nxt-code-snippet';

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
			add_action( 'init', array( $this, 'import_data_code_snippet' ) );
		}

		public function import_data_code_snippet(){
			$default_data = array(
				array(
					'title' => esc_html__( 'Disable Emojis for Faster Loading', 'nexter-extension' ),
					'type' => 'php',
					'code' => "remove_action('wp_head', 'print_emoji_detection_script', 7);\n\tremove_action('wp_print_styles', 'print_emoji_styles');",
					'code-execute' => 'front-end',
					'desc' => esc_html__( 'Disable WordPress emoji scripts to improve page speed.', 'nexter-extension' ),
					'tags' => array( 'Performance', 'Optimization', 'Frontend' ),
				),
				array(
					'title' => esc_html__( 'Add Google Analytics Tracking Code', 'nexter-extension' ),
					'type' => 'php',
					'code' => "add_action('wp_head', function() { ?>\n<!-- Replace with your Google Analytics Code -->\n<script async src='https://www.googletagmanager.com/gtag/js?id=YOUR-ID'></script>\n<script>\n\twindow.dataLayer = window.dataLayer || [];\n\tfunction gtag(){dataLayer.push(arguments);}\n\tgtag('js', new Date());\n\tgtag('config', 'YOUR-ID');\n</script>\n<?php });",
					'code-execute' => 'front-end',
					'desc' => esc_html__( 'Insert Google Analytics script directly without extra plugins.', 'nexter-extension' ),
					'tags' => array( 'Analytics', 'Tracking', 'Frontend' ),
				),
				array(
					'title' => esc_html__( 'Limit Post Revisions to Optimize Database', 'nexter-extension' ),
					'type' => 'php',
					'code' => "define('WP_POST_REVISIONS', 5);",
					'code-execute' => 'global',
					'desc' => esc_html__( 'Restrict number of saved post revisions to reduce database size.', 'nexter-extension' ),
					'tags' => array( 'Database', 'Optimization', 'Performance' ),
				),
				array(
					'title' => esc_html__( 'Customize Login Logo Link URL', 'nexter-extension' ),
					'type' => 'php',
					'code' => "function custom_login_url() {\n\treturn home_url();\n}\nadd_filter('login_headerurl', 'custom_login_url');",
					'code-execute' => 'front-end',
					'desc' => esc_html__( 'Change WordPress login logo URL to your site homepage.', 'nexter-extension' ),
					'tags' => array( 'Branding', 'Login-Page', 'Frontend' ),
				),
				array(
					'title' => esc_html__( 'Disable Gutenberg Editor (Use Classic Editor)', 'nexter-extension' ),
					'type' => 'php',
					'code' => "add_filter('use_block_editor_for_post', '__return_false');",
					'code-execute' => 'front-end',
					'desc' => esc_html__( 'Disable Gutenberg block editor and enable classic editor experience.', 'nexter-extension' ),
					'tags' => array( 'Editor', 'Backend', 'Classic' ),
				),
			);

			foreach ( $default_data as $snippet ) {
				$this->import_snippet( $snippet );
			}
			
			// Mark as imported
			update_option( 'nexter_snippets_imported', true );
		}

		/**
		 * Insert Snippet into Post Type
		 */
		private function import_snippet( $snippet ) {
			if ( empty( $snippet['title'] ) || empty( $snippet['code'] ) ) {
				return;
			}

			// Prevent duplicate by title
			$existing = get_page_by_title( $snippet['title'], OBJECT, self::$snippet_type );
			if ( $existing ) {
				return;
			}

			$post_args = array(
				'post_title'   => wp_strip_all_tags( sanitize_text_field($snippet['title']) ),
				'post_type'    => self::$snippet_type,
				'post_status'  => 'publish',
			);

			$snippet_id = wp_insert_post( $post_args );

			if ( ! is_wp_error( $snippet_id ) ) {
				//Type
				$type = (!empty($snippet['type'])) ? sanitize_text_field(wp_unslash($snippet['type'])) : '';
				if(!empty($type) && in_array($type, ['php','htmlmixed','css','javascript'])){
					update_post_meta( $snippet_id , 'nxt-code-type', $type );
				}

				//code
				if (isset($snippet['code']) && !empty($type)) {
					$lang_code = '';
					if($type==='css'){
						$lang_code = wp_strip_all_tags(wp_unslash($snippet['code']));
						update_post_meta( $snippet_id ,'nxt-css-code', $lang_code);
					}else if($type=='javascript'){
						$lang_code = sanitize_textarea_field(wp_unslash($snippet['code']));
						update_post_meta( $snippet_id ,'nxt-javascript-code', $lang_code);
					}else if($type=='htmlmixed'){
						$html_code = (isset($snippet['code']) && !empty($snippet['code'])) ? wp_unslash(stripslashes($snippet['code'])) : '';
						update_post_meta( $snippet_id ,'nxt-htmlmixed-code', $html_code);
					}else if($type=='php'){
						update_post_meta( $snippet_id, 'nxt-code-php-hidden-execute','no');

						$lang_code = wp_unslash($snippet['code']);
						update_post_meta( $snippet_id ,'nxt-php-code', $lang_code);

						$code_execute = (isset($snippet['code-execute']) && !empty($snippet['code-execute'])) ? sanitize_text_field(wp_unslash($snippet['code-execute'])) : 'global';
						if(!empty($code_execute) && in_array($code_execute, ['global','admin','front-end'])){
							update_post_meta( $snippet_id , 'nxt-code-execute', $code_execute );
						}
						update_post_meta( $snippet_id, 'nxt-code-php-hidden-execute','yes');
					}
				}

				//Description
				$desc = (!empty($snippet['desc'])) ? $snippet['desc'] : '';
				if(!empty($desc)){
					update_post_meta( $snippet_id , 'nxt-code-note', $desc );
				}

				//Tags
				if ( ! empty( $snippet['tags'] ) && is_array( $snippet['tags'] ) ) {
					update_post_meta($snippet_id, 'nxt-code-tags', $snippet['tags']);
				}else{
					update_post_meta($snippet_id, 'nxt-code-tags', []);
				}

				update_post_meta( $snippet_id , 'nxt-code-status', 0 );
			}
		}
	}
}

Nexter_Code_Snippets_Import_Data::get_instance();