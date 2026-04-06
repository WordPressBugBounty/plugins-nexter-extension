<?php
/**
 * Nexter Extensions Sections/Pages Load Functionality
 *
 * Orchestrator: delegates admin-bar logic to Nxt_Admin_Bar_Handler.
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nexter_Class_Load' ) ) {

	class Nexter_Class_Load {

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 * Admin bar handler instance.
		 *
		 * @var Nxt_Admin_Bar_Handler|null
		 */
		private $admin_bar;

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
			add_action( 'plugins_loaded', [ $this, 'nexter_load_code_snippet_system' ], 20 );

			if( !is_admin() ){
				require_once __DIR__ . '/class-nxt-admin-bar-handler.php';
				$this->admin_bar = new Nxt_Admin_Bar_Handler();

				// Defer admin-bar hooks to 'init' so is_user_logged_in() is available.
				// Avoids registering wp_footer callback (which queries post meta,
				// snippet IDs, etc.) for logged-out visitors who never see the bar.
				add_action( 'init', function() {
					if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
						$this->admin_bar->register_hooks();
					}
				} );
			}

			add_action('init', function() {
				if (has_action('wp_footer', 'wp_print_speculation_rules')) {
					remove_action('wp_footer', 'wp_print_speculation_rules');
				}
			});
		}

		// ── Backward-compatible delegation ─────────────────────────

		/**
		 * @since 1.0.7
		 */
		public function add_edit_template_admin_bar( \WP_Admin_Bar $wp_admin_bar ) {
			if ( $this->admin_bar ) {
				$this->admin_bar->add_edit_template_admin_bar( $wp_admin_bar );
			}
		}

		public function admin_bar_enqueue_scripts() {
			if ( $this->admin_bar ) {
				$this->admin_bar->admin_bar_enqueue_scripts();
			}
		}

		public function nxt_deep_merge_snippet_ids( $base, $append ) {
			if ( ! $this->admin_bar ) {
				require_once __DIR__ . '/class-nxt-admin-bar-handler.php';
				$this->admin_bar = new Nxt_Admin_Bar_Handler();
			}
			return $this->admin_bar->nxt_deep_merge_snippet_ids( $base, $append );
		}

		public function find_reusable_block( $post_ids ) {
			if ( ! $this->admin_bar ) {
				require_once __DIR__ . '/class-nxt-admin-bar-handler.php';
				$this->admin_bar = new Nxt_Admin_Bar_Handler();
			}
			return $this->admin_bar->find_reusable_block( $post_ids );
		}

		public function block_reference_id( $res_blocks ) {
			if ( ! $this->admin_bar ) {
				require_once __DIR__ . '/class-nxt-admin-bar-handler.php';
				$this->admin_bar = new Nxt_Admin_Bar_Handler();
			}
			return $this->admin_bar->block_reference_id( $res_blocks );
		}

		// ── Core Logic (stays here) ────────────────────────────────

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
		}

		public function nexter_load_code_snippet_system() {
			$include_uri = NEXTER_EXT_DIR . 'include/classes/';
			// Check if code snippets are enabled before including related files
			$get_opt = Nxt_Options::extra_ext();
			$code_snippets_enabled = true;

			if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
				$code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
			}

			if ( $code_snippets_enabled ) {
				// Import utility is admin-only and only needed during setup/migration.
				if ( is_admin() && get_option( 'nexter_snippets_imported' ) === false ) {
					require_once $include_uri . 'load-code-snippet/nexter-import-code-snippets.php';
				}
				// Load snippet runtime on every request when snippets are enabled. Admin used to
				// load only on Nexter snippet/builder screens for performance; that skipped CPT and
				// other admin screens, so register_post_type() from snippets never ran there.
				require_once $include_uri . 'load-code-snippet/nexter-code-snippet-render.php';
			}
		}
	}
}

Nexter_Class_Load::get_instance();
