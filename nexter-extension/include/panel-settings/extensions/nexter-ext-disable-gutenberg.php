<?php 
/*
 * Disable Gutenberg Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Disable_Gutenberg {
    
    public static $post_type_opt = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->nxt_get_post_order_settings();
        add_action( 'admin_init', [$this, 'disable_gutenberg_for_post_types_admin'] );
        add_action( 'admin_print_styles', [$this, 'safari_18_fix'] );
        
        if ( isset(self::$post_type_opt->frontend_style) && self::$post_type_opt->frontend_style === true ) {
            add_action( 'wp_enqueue_scripts', [$this, 'disable_gutenberg_frontend_style'], 100 );
        }
    }

    private function nxt_get_post_order_settings(){
        
		if(isset(self::$post_type_opt) && !empty(self::$post_type_opt)){
			return self::$post_type_opt;
		}

		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['disable-gutenberg']) && !empty($option['disable-gutenberg']['switch']) && !empty($option['disable-gutenberg']['values']) ){
			self::$post_type_opt = $option['disable-gutenberg']['values'];
		}
        
	}
 
    /**
     * Disable Gutenberg in wp-admin for some or all post types
     *
     * @since 2.8.0
     */
    public function disable_gutenberg_for_post_types_admin() {
        if (!is_admin()) return;

        global $pagenow, $typenow;
        $post_type = null;
        if ( 'edit.php' === $pagenow ) {
            $post_type = $typenow;
        } elseif ( 'post.php' === $pagenow ) {
            $post_type = ( isset( $_GET['post'] ) ? get_post_type( $_GET['post'] ) : 'post' );
        } elseif ( 'post-new.php' === $pagenow ) {
            $post_type = ( isset( $_GET['post_type'] ) ? $_GET['post_type'] : 'post' );
        }
        
        if (!$post_type) return;

        $gutenberg = function_exists( 'gutenberg_register_scripts_and_styles' );
        
        $block_editor = has_action( 'enqueue_block_assets' );
        if ( !$gutenberg && false === $block_editor ) {
            return;
        }

        $disable_gutenberg_type = 'only-on';

        $disable_type = 'only-on';
        $disable = (
            ($disable_type === 'only-on' && !empty(self::$post_type_opt) && isset(self::$post_type_opt->posts) && in_array($post_type, self::$post_type_opt->posts, true)) ||
            ($disable_type === 'except-on' && !empty(self::$post_type_opt) && isset(self::$post_type_opt->posts) && !in_array($post_type, self::$post_type_opt->posts, true)) ||
            $disable_type === 'all-post-types'
        );

        if (!$disable) return;

        add_filter('use_block_editor_for_post_type', '__return_false', 100);

        if (function_exists('gutenberg_register_scripts_and_styles')) {
            add_filter('gutenberg_can_edit_post_type', '__return_false', 100);
            $this->remove_all_gutenberg_hooks();
        }
    }

    private function remove_all_gutenberg_hooks() {
        // [Gutenberg core hook removals]
        $actions = [
            ['admin_menu', 'gutenberg_menu'],
            ['admin_init', 'gutenberg_redirect_demo'],
            ['wp_enqueue_scripts', 'gutenberg_register_scripts_and_styles'],
            ['admin_enqueue_scripts', 'gutenberg_register_scripts_and_styles'],
            ['admin_notices', 'gutenberg_wordpress_version_notice'],
            ['rest_api_init', 'gutenberg_register_rest_widget_updater_routes'],
            ['admin_print_styles', 'gutenberg_block_editor_admin_print_styles'],
            ['admin_print_scripts', 'gutenberg_block_editor_admin_print_scripts'],
            ['admin_print_footer_scripts', 'gutenberg_block_editor_admin_print_footer_scripts'],
            ['admin_footer', 'gutenberg_block_editor_admin_footer'],
            ['admin_enqueue_scripts', 'gutenberg_widgets_init'],
            ['admin_notices', 'gutenberg_build_files_notice'],
            ['rest_api_init', 'gutenberg_register_rest_routes'],
            ['rest_api_init', 'gutenberg_add_taxonomy_visibility_field'],
            ['do_meta_boxes', 'gutenberg_meta_box_save'],
            ['submitpost_box', 'gutenberg_intercept_meta_box_render'],
            ['submitpage_box', 'gutenberg_intercept_meta_box_render'],
            ['edit_page_form', 'gutenberg_intercept_meta_box_render'],
            ['edit_form_advanced', 'gutenberg_intercept_meta_box_render'],
        ];

        $filters = [
            ['load_script_translation_file', 'gutenberg_override_translation_file'],
            ['block_editor_settings', 'gutenberg_extend_block_editor_styles'],
            ['default_content', 'gutenberg_default_demo_content'],
            ['default_title', 'gutenberg_default_demo_title'],
            ['block_editor_settings', 'gutenberg_legacy_widget_settings'],
            ['rest_request_after_callbacks', 'gutenberg_filter_oembed_result'],
            ['wp_refresh_nonces', 'gutenberg_add_rest_nonce_to_heartbeat_response_headers'],
            ['get_edit_post_link', 'gutenberg_revisions_link_to_editor'],
            ['wp_prepare_revision_for_js', 'gutenberg_revisions_restore'],
            ['redirect_post_location', 'gutenberg_meta_box_save_redirect'],
            ['filter_gutenberg_meta_boxes', 'gutenberg_filter_meta_boxes'],
            ['body_class', 'gutenberg_add_responsive_body_class'],
            ['admin_url', 'gutenberg_modify_add_new_button_url'],
            ['register_post_type_args', 'gutenberg_filter_post_type_labels'],
        ];

        foreach ($actions as [$hook, $func]) {
            remove_action($hook, $func);
        }

        foreach ($filters as [$hook, $func]) {
            remove_filter($hook, $func);
        }
    }

    /**
     * Disable Gutenberg block styles on the frontend for selected post types.
     */
    public function disable_gutenberg_frontend_style() {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!isset($post->post_type)) {
            return;
        }

        $disable_gutenberg_type = 'only-on'; // You can set this dynamically if needed
        $post_type = $post->post_type;

        $should_disable = (
            ($disable_gutenberg_type === 'only-on' && in_array($post_type, self::$post_type_opt->posts, true)) ||
            ($disable_gutenberg_type === 'except-on' && !in_array($post_type, self::$post_type_opt->posts, true)) ||
            ($disable_gutenberg_type === 'all-post-types')
        );

        if (!$should_disable) {
            return;
        }

        // Remove Gutenberg styles
        $block_styles_to_keep = []; // Add any styles you wish to retain

        global $wp_styles;
        if (isset($wp_styles->queue) && is_array($wp_styles->queue)) {
            foreach ($wp_styles->queue as $handle) {
                if (strpos($handle, 'wp-block') === 0 && !in_array($handle, $block_styles_to_keep, true)) {
                    wp_dequeue_style($handle);
                }
            }
        }

        wp_dequeue_style('core-block-supports');
        wp_dequeue_style('global-styles');
        wp_dequeue_style('classic-theme-styles');
        wp_deregister_style('wp-block-library');
    }

    public function safari_18_fix() {
        global $current_screen;
        if (!isset($current_screen->base) || $current_screen->base !== 'post') return;

        $clear = is_rtl() ? 'right' : 'left';
        echo '<style id="classic-editor-safari-18-temp-fix">
            _::-webkit-full-page-media, _:future, :root #post-body #postbox-container-2 {
                clear: ' . esc_html($clear) . ';
            }
        </style>';
    }
}

 new Nexter_Ext_Disable_Gutenberg();