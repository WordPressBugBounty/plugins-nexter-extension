<?php 
/*
 * Disable Gutenberg Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Disable_Gutenberg {

    public static $post_type_opt = [];

    public function __construct() {
        $this->nxt_get_disable_gutenberg_settings();

        // Register both block-editor filters in the constructor so they are always
        // present when WordPress applies them — no dependency on $pagenow/$typenow.
        add_filter( 'use_block_editor_for_post_type', [ $this, 'maybe_disable_block_editor' ], 100, 2 );

        // Gutenberg plugin hook removal must still run at admin_init (before admin_menu fires).
        add_action( 'admin_init', [ $this, 'maybe_remove_gutenberg_hooks' ] );
        add_action( 'admin_print_styles', [ $this, 'safari_18_fix' ] );

        if ( isset( self::$post_type_opt->frontend_style ) && true === self::$post_type_opt->frontend_style ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'disable_gutenberg_frontend_style' ], 100 );
        }
    }

    private function nxt_get_disable_gutenberg_settings() {
        if ( isset( self::$post_type_opt ) && ! empty( self::$post_type_opt ) ) {
            return self::$post_type_opt;
        }
        $option = Nxt_Options::extra_ext();
        if ( ! empty( $option ) && isset( $option['disable-gutenberg'] )
            && ! empty( $option['disable-gutenberg']['switch'] )
            && ! empty( $option['disable-gutenberg']['values'] ) ) {
            self::$post_type_opt = (object) $option['disable-gutenberg']['values'];
        }
    }

    /**
     * Filter: use_block_editor_for_post_type — disable by post type.
     */
    public function maybe_disable_block_editor( $use_block_editor, $post_type ) {
        if ( ! $this->should_disable_for_post_type( $post_type ) ) {
            return $use_block_editor;
        }
        return false;
    }

    /**
     * Returns true when the block editor should be suppressed for the given post type.
     */
    private function should_disable_for_post_type( $post_type ) {
        if ( empty( self::$post_type_opt ) ) {
            return false;
        }
        $disable_type = isset( self::$post_type_opt->type ) ? self::$post_type_opt->type : 'only-on';
        $posts        = isset( self::$post_type_opt->posts ) ? (array) self::$post_type_opt->posts : [];

        switch ( $disable_type ) {
            case 'all-post-types':
                return true;
            case 'except-on':
                // Guard: if no post types are selected, disable nothing.
                return ! empty( $posts ) && ! in_array( $post_type, $posts, true );
            case 'only-on':
            default:
                return ! empty( $posts ) && in_array( $post_type, $posts, true );
        }
    }

    /**
     * admin_init: remove Gutenberg plugin hooks when the plugin is active and the
     * editor should be suppressed for the current screen's post type.
     * Must run at admin_init because admin_menu fires before the page script takes over.
     */
    public function maybe_remove_gutenberg_hooks() {
        if ( ! is_admin() || ! function_exists( 'gutenberg_register_scripts_and_styles' ) ) {
            return;
        }

        global $pagenow;
        $post_type = null;
        if ( 'post.php' === $pagenow ) {
            $post_type = isset( $_GET['post'] )
                ? get_post_type( absint( wp_unslash( $_GET['post'] ) ) )
                : 'post';
        } elseif ( 'post-new.php' === $pagenow ) {
            $post_type = isset( $_GET['post_type'] )
                ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) )
                : 'post';
        }

        if ( ! $post_type || ! $this->should_disable_for_post_type( $post_type ) ) {
            return;
        }

        add_filter( 'gutenberg_can_edit_post_type', '__return_false', 100 );
        $this->remove_all_gutenberg_hooks();
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

        // Read the saved mode; fall back to 'only-on' for sites that haven't set one yet.
        $disable_gutenberg_type = ( ! empty( self::$post_type_opt ) && isset( self::$post_type_opt->type ) )
            ? self::$post_type_opt->type
            : 'only-on';
        $post_type = $post->post_type;

        $should_disable = (
            ($disable_gutenberg_type === 'only-on' && !empty(self::$post_type_opt->posts) && in_array($post_type, self::$post_type_opt->posts, true)) ||
            ($disable_gutenberg_type === 'except-on' && !empty(self::$post_type_opt->posts) && !in_array($post_type, self::$post_type_opt->posts, true)) ||
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