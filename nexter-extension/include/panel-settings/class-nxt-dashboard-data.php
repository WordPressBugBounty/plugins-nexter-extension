<?php
/**
 * Dashboard Data
 *
 * Builds the localization data for the Nexter admin dashboard React app.
 * Includes post type lists, taxonomy lists, user roles, table sizes,
 * and AVIF support checks.
 *
 * Extracted from Nexter_Ext_Panel_Settings.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Dashboard_Data {

        protected $setting_name = '';
        protected $setting_logo = '';

        public function register_hooks() {
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts_admin' ], 1 );
        }

        public function get_setting_name() {
            return $this->setting_name;
        }

        public function get_setting_logo() {
            return $this->setting_logo;
        }

        /**
         * Public proxy for AVIF support check (called via parent delegation).
         *
         * @return bool
         */
        public function nxt_ext_check_avif_supported_proxy() {
            return $this->nxt_ext_check_avif_supported();
        }

        private function nxt_ext_check_avif_supported() {
            if ( ! extension_loaded( 'imagick' ) ) {
                return false;
            }
            try {
                $imagick = new Imagick();
                $formats = $imagick->queryFormats();
                $imagick->clear();
                $imagick->destroy();
                return is_array( $formats ) && in_array( 'AVIF', $formats, true );
            } catch ( Exception $e ) {
                return false;
            }
        }

        public function nexter_ext_object_convert_to_array($data) {
            if (is_object($data)) {
                $data = (array) $data;
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $data[ $key ] = $this->nexter_ext_object_convert_to_array( $value );
                }
            }
            return $data;
        }

        public function get_nxt_brand_name(){
            if(defined('NXT_PRO_EXT') || defined('TPGBP_VERSION')){
                $options = Nxt_Options::white_label();
                $this->setting_name = (!empty($options['brand_name'])) ? $options['brand_name'] : esc_html__('Nexter', 'nexter-extension');
                $this->setting_logo = (!empty($options['theme_logo'])) ? $options['theme_logo'] : esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter-logo.svg');
            }else{
                $this->setting_name = esc_html__('Nexter', 'nexter-extension');
                $this->setting_logo = esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter-logo.svg');
            }
        }

        /**
         * Check if the current admin page belongs to Nexter.
         *
         * @param string $hook_suffix The current admin page hook suffix.
         * @return bool
         */
        private function is_nexter_admin_page( $hook_suffix ) {
            // Nexter dashboard pages (toplevel_page_nexter_welcome, nexter-ext_page_*)
            if ( strpos( $hook_suffix, 'nexter' ) !== false || strpos( $hook_suffix, 'nxt_builder' ) !== false ) {
                return true;
            }
            // Page param based screens
            if ( isset( $_GET['page'] ) ) {
                $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
                if ( strpos( $page, 'nexter' ) !== false || strpos( $page, 'nxt_' ) !== false ) {
                    return true;
                }
            }
            // Theme builder CPT list screen
            if ( 'edit.php' === $hook_suffix && isset( $_GET['post_type'] ) && 'nxt_builder' === $_GET['post_type'] ) {
                return true;
            }
            // Single nxt_builder post edit
            if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && defined( 'NXT_BUILD_POST' ) && NXT_BUILD_POST === get_post_type() ) {
                return true;
            }
            return false;
        }

        /*Load Panel Settings Style & Scripts*/
        public function enqueue_scripts_admin( $hook_suffix ){
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
            //$is_nexter_page = $this->is_nexter_admin_page( $hook_suffix );

            // Theme builder CPT list: select2 assets (lightweight, scoped)
            if ( ( ( 'post-new.php' != $hook_suffix && 'post.php' != $hook_suffix && 'edit.php' == $hook_suffix ) && ( isset( $_GET['post_type'] ) && 'nxt_builder' == $_GET['post_type'] ) || ( defined( 'NXT_BUILD_POST' ) && NXT_BUILD_POST == get_post_type() ) ) || (isset($_GET['page']) && $_GET['page'] === 'nxt_builder')){
                wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2'. $minified .'.css', array(), NEXTER_EXT_VER );
			    wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2'. $minified .'.js', array(), NEXTER_EXT_VER, false );
            }

            // Early exit: skip all heavy assets on non-Nexter admin pages.
            // Admin JS for notices/builder toggle is enqueued separately in Nexter_Class_Load.
            /* if ( ! $is_nexter_page ) {
                return;
            } */

            // --- From here: Nexter pages only ---

            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( ! did_action( 'wp_enqueue_media' ) ) {
				wp_enqueue_media();
			}

            if ( ! is_customize_preview() ) {
                wp_enqueue_style( 'nxt-panel-settings', NEXTER_EXT_URL .'assets/css/admin/nexter-admin'. $minified .'.css', array(), NEXTER_EXT_VER );
				wp_enqueue_style( 'wp-color-picker' );
            }

            $user = wp_get_current_user();
            $enabled_is = [];
            $get_performance = Nxt_Options::performance();
            $legacy_disabled_images = Nxt_Options::disabled_images();
            if ( ! is_array( $legacy_disabled_images ) ) {
                $legacy_disabled_images = array();
            }
            $legacy_custom_image_sizes = Nxt_Options::custom_img_sizes();
            $legacy_elementor_icons = Nxt_Options::elementor_icons();

            $perf_google_fonts = array();
            if ( ! empty( $get_performance['google-fonts']['values'] ) ) {
                $perf_google_fonts = (array) $get_performance['google-fonts']['values'];
            } else {
                $perf_google_fonts = Nxt_Options::google_fonts();
            }
            if(!empty($get_performance) && isset($get_performance['disabled-image-sizes']) && isset($get_performance['disabled-image-sizes']['switch']) && isset($get_performance['disabled-image-sizes']['values'])){
                $enabled_is = (array) $get_performance['disabled-image-sizes']['values'];
            }else{
                $enabled_is = (array) $legacy_disabled_images;
            }
            $intermediate_image = get_intermediate_image_sizes();
            $get_image_sizes = array_unique(array_merge($intermediate_image, $enabled_is));

            $themes = wp_get_themes();
            $nexterInstalled = array_key_exists('nexter', $themes);
            $theme_det_link = Nexter_Ext_Panel_Settings::get_instance()->get_nexter_theme_details_link('nexter');

            $rollback_url = wp_nonce_url(admin_url('admin-post.php?action=nxtext_rollback&version=NEXTER_EXT_VER'), 'nxtext_rollback');

            $nxtPlugin = false;
            $tpaePlugin = false;
            $wdkPlugin = false;
            $uichemyPlugin = false;

            include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            $pluginslist = get_plugins();

            $tpgbactivate = false;
            if ( isset( $pluginslist[ 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' ] ) && !empty( $pluginslist[ 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' ] ) ) {
                if( is_plugin_active('the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php') ){
                    $nxtPlugin = true;
                }else{
                    $tpgbactivate = true;
                }
            }

            $extensioninstall = false;
            $extensionactivate = false;
            if ( isset( $pluginslist[ 'nexter-extension/nexter-extension.php' ] ) && !empty( $pluginslist[ 'nexter-extension/nexter-extension.php' ] ) ) {
                if( is_plugin_active('nexter-extension/nexter-extension.php') ){
                    $extensioninstall = true;
                }else{
                    $extensionactivate = true;
                }
            }

            $tpaeactive = false;
            if ( isset( $pluginslist[ 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' ] ) && !empty( $pluginslist[ 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' ] ) ) {
                if( is_plugin_active('the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php') ){
                    $tpaePlugin = true;
                }else{
                    $tpaeactive = true;
                }
            }

            $wdkactive = false;
            if ( isset( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) && !empty( $pluginslist[ 'wdesignkit/wdesignkit.php' ] ) ) {
                if( is_plugin_active('wdesignkit/wdesignkit.php') ){
                    $wdkPlugin = true;
                    $wdkVersion = '1.0.0';
                    if (defined('WDKIT_VERSION')) {
                        $wdkVersion = WDKIT_VERSION;
                    } else {
                        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/wdesignkit/wdesignkit.php');
                        if (isset($plugin_data['Version'])) {
                            $wdkVersion = $plugin_data['Version'];
                        }
                    }
                }else{
                    $wdkactive = true;
                }
            }

            $uichemyactive = false;
            if ( isset( $pluginslist[ 'uichemy/uichemy.php' ] ) && !empty( $pluginslist[ 'uichemy/uichemy.php' ] ) ) {
                if( is_plugin_active('uichemy/uichemy.php') ){
                    $uichemyPlugin = true;
                }else{
                    $uichemyactive = true;
                }
            }

            if ( ! is_customize_preview() ) {
                wp_enqueue_style( 'nexter-welcome-style', NEXTER_EXT_URL . 'dashboard/build/index.css', array(), NEXTER_EXT_VER, 'all' );
            }

			wp_enqueue_script( 'nexter-ext-dashscript', NEXTER_EXT_URL . 'dashboard/build/index.js', array( 'react', 'react-dom','wp-i18n', 'wp-dom-ready', 'wp-element','wp-components', 'wp-block-editor', 'wp-editor' ), NEXTER_EXT_VER, true );

            wp_set_script_translations(
                'nexter-ext-dashscript',
                'nexter-extension',
                NEXTER_EXT_DIR . 'languages'
            );

            if ( is_multisite() ) {
				$main_site_id = get_main_site_id();
				$licence_key = get_blog_option( $main_site_id, 'nexter_activate', [] );
				if(empty($licence_key)){
					$licence_key = Nxt_Options::activate();
				}
			}else{
				$licence_key = Nxt_Options::activate();
			}

            $dashData = [
                'userData' => [
                    'userName' => esc_html($user->display_name),
                    'profileLink' => esc_url( get_avatar_url( $user->ID ) )
                ],
                'whiteLabelData' => [
                    'brandname' => $this->setting_name,
                    'brandlogo' => $this->setting_logo
                ],
                'nxtExtra' => Nxt_Options::extra_ext(),
                'nxtPerformance' => Nxt_Options::performance(),
                'intermediateImgSize' => $intermediate_image,
                'nxtGetImgSize' => $get_image_sizes,
                'nxtDisableImg' => ! empty( $legacy_disabled_images ) ? $legacy_disabled_images : [],
                'nxtImageSize' => $legacy_custom_image_sizes,
                'nxtSecurity' => Nxt_Options::security(),
                'nexterThemeActive' => (defined('NXT_VERSION')) ? true : false,
                'nexterThemeIntall' =>  $nexterInstalled,
                'nexterThemeDet' => $theme_det_link,
                'nexterCustLink' => admin_url('customize.php'),
                'elementorplugin' => class_exists( '\Elementor\Plugin' ),
                'elementorDisIcons' => $legacy_elementor_icons,
                'nxtGoogleFonts' => $perf_google_fonts,
                'post_list' => self::nexter_ext_get_post_type_list(),
                'taxonomy_list' => $this->nexter_get_taxonomy_list(),
                'wpVersion' => get_bloginfo('version'),
                'pluginVer' => NEXTER_EXT_VER,
                'pluginpath' => NEXTER_EXT_URL,
                'extensioninstall' => $extensioninstall,
                'extensionactivate' => $extensionactivate,
                'nexterBlock' => $nxtPlugin,
                'tpgbinstall' => $nxtPlugin,
                'tpgbactivate' => $tpgbactivate,
                'tpaeAddon' => $tpaePlugin,
                'tpaeactive' => $tpaeactive,
                'wdkPlugin' => $wdkPlugin,
                'wdkactive' => $wdkactive,
                'wdadded' => $wdkPlugin,
                'wdkVersion' => isset($wdkVersion) ? $wdkVersion : '1.0.0',
                'uichemy' => $uichemyPlugin,
                'uichemyactive' => $uichemyactive,
                'woocommerce' => class_exists('WooCommerce') ? true : false,
                'ext_rollbacVer' => NxtExt_Rollback::get_rollback_versions(),
                'rollbackUrl' => $rollback_url,
                'whiteLabel' => defined('NXT_PRO_EXT') ? Nxt_Options::white_label() : (defined('TPGBP_VERSION') ? Nxt_Options::tpgb_white_label() : []),
                'keyActmsg' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_ext_pro_activate_msg() : '',
                'nxtactivateKey' => $licence_key,
                'activePlan' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_get_activate_plan() : '',
                'roles' => self::nexter_ext_get_users_roles(),
                'showSidebar' => Nexter_Ext_Panel_Settings::get_instance()->nxt_ext_notice_should_show(),
                'nxtThemeSetting' => (array) Nxt_Options::settings_opts(),
                'nxt_wdkit_url' => 'https://api.wdesignkit.com/',
                'extensionPro' =>  defined('NXT_PRO_EXT_VER'),
                'image_optimizer_avif_supported' => $this->nxt_ext_check_avif_supported(),
                'image_optimizer_pro_formats' => apply_filters( 'nexter_image_optimizer_pro_formats', array() ),
            ];

            $current_user_username = '';
            if (!empty($user) && isset($user->user_login) && !empty($user->user_login)) {
                $current_user_username = $user->user_login;
            }
            $themebuilder_status = Nxt_Options::builder_switcher();
            if ( false === $themebuilder_status ) {
                $themebuilder_status = true;
            }

            $locallize_data =array(
                'adminUrl' => admin_url(),
                'nxtex_url' => NEXTER_EXT_URL . 'dashboard/',
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                'smtp_url' => admin_url('admin.php?page=nexter-smtp-settings'),
                'smtp_state' => wp_create_nonce('gmail_oauth'),
                'gmail_auth_check_nonce' => wp_create_nonce('gmail_auth_check'),
                'pro' => (defined('NXT_PRO_EXT_VER')) ? true : false,
                'dashData' => $dashData,
                'site_url' => site_url(),
                'username' => $current_user_username,
                'themebuilderStatus' => $themebuilder_status,
            );

            if(has_filter( 'nxt_dashboard_localize_data' )){
                $locallize_data = apply_filters( 'nxt_dashboard_localize_data', $locallize_data );
            }

			wp_localize_script(
				'nexter-ext-dashscript',
				'nxtext_ajax_object',
				$locallize_data
			);

            $nexter_admin_localize = array(
                'adminUrl' => admin_url(),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                'nexter_path' => NEXTER_EXT_URL.'assets/',
                'is_pro' => (defined('NXT_PRO_EXT')) ? true : false,
                'duplicating' => esc_html__( 'Duplicating..', 'nexter-extension' ),
                'duplicated' => esc_html__( 'Duplicated', 'nexter-extension' ),
            );

            wp_localize_script( 'nexter-ext-dashscript', 'nexter_admin_config', $nexter_admin_localize );

            if (isset($_GET['page']) && $_GET['page'] === 'nxt_builder') {
                wp_enqueue_style( 'nexter-theme-builder', NEXTER_EXT_URL . 'theme-builder/build/index.css', array(), NEXTER_EXT_VER, 'all' );

                wp_enqueue_script( 'nexter-theme-builder', NEXTER_EXT_URL . 'theme-builder/build/index.js', array( 'react', 'react-dom', 'wp-dom-ready', 'wp-i18n' ), NEXTER_EXT_VER, true );

                wp_set_script_translations(
                    'nexter-theme-builder',
                    'nexter-extension',
                    NEXTER_EXT_DIR . 'languages'
                );

                $extension_option = Nxt_Options::extra_ext();
                $duplicate_enabled = false;
                if(!empty($extension_option) && isset($extension_option['wp-duplicate-post']) && !empty($extension_option['wp-duplicate-post']['switch']) && !empty($extension_option['wp-duplicate-post']['values']) ){
                    $duplicate_enabled = true;
                }

                $nexter_theme_builder_config = array(
                    'adminUrl' => admin_url(),
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                    'assets' => NEXTER_EXT_URL.'theme-builder/assets/',
                    'is_pro' => (defined('NXT_PRO_EXT')) ? true : false,
                    'keyActmsg' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_ext_pro_activate_msg() : '',
                    'dashboard_url' => admin_url( 'admin.php?page=nexter_welcome' ),
                    'version' => NEXTER_EXT_VER,
                    'import_temp_nonce' => wp_create_nonce('nxt_ajax'),
                    'wdkPlugin' => $wdkPlugin,
                    'wdkactive' => $wdkactive,
                    'extensioninstall' => $extensioninstall,
                    'extensionactivate' => $extensionactivate,
                    'duplicateEnabled' => $duplicate_enabled,
                );

                wp_localize_script( 'nexter-theme-builder', 'nexter_theme_builder_config', $nexter_theme_builder_config );

            }
        }

        public function nexter_replace_url_tables_and_size(){
            $cache_key = is_multisite()
                ? 'nexter_replace_url_tables_' . absint( get_current_blog_id() )
                : 'nexter_replace_url_tables_single';
            $cached_tables = get_transient( $cache_key );
            if ( is_array( $cached_tables ) ) {
                return $cached_tables;
            }

            global $wpdb;
            $tables = '';
            if (function_exists('is_multisite') && is_multisite()) {
                if(is_main_site()){
                    $tables 	= $wpdb->get_col('SHOW TABLES');
                }else{
                    $blog_id 	= get_current_blog_id();
                    $tables 	= $wpdb->get_col('SHOW TABLES LIKE "'.$wpdb->base_prefix.absint( $blog_id ).'\_%"');
                }
            }else{
                $tables = $wpdb->get_col('SHOW TABLES');
            }

            // $sizes 	= array();
            $sizes 	= [];
            $tablesNN	= $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
            if ( is_array( $tablesNN ) && ! empty( $tablesNN ) ) {
                foreach ( $tablesNN as $table ) {
                    $size = round( $table['Data_length'] / 1024 / 1024, 2 );
                    // Add a translators' comment explaining the placeholder
                    // translators: %s is the size of the table in megabytes
                    $sizes[$table['Name']] = sprintf( __( '(%s MB)', 'nexter-extension' ), $size );
                }
            }

            $table_and_sizes = [];
            foreach($tables as $tab){
                $table_size = isset( $sizes[$tab] ) ? $sizes[$tab] : '';
                $table_and_sizes[$tab] = esc_attr($tab)." ".esc_attr($table_size);
            }

            // Cache table metadata briefly to avoid repeated expensive SHOW queries.
            set_transient( $cache_key, $table_and_sizes, 5 * MINUTE_IN_SECONDS );
            return $table_and_sizes;
        }

        public function nexter_ext_get_post_type_list(){
            $args = array(
                'public'   => true,
                'show_ui' => true
            );
            $post_types = get_post_types( $args, 'objects' );

            $options = array();
            foreach ( $post_types  as $post_type ) {

                $exclude = array( 'elementor_library' );
                if( TRUE === in_array( $post_type->name, $exclude ) ){
                    continue;
                }

                if($post_type->name != 'nxt_builder'){
                    $options[$post_type->name] =  $post_type->label;
                }
            }
            return $options;
        }
        /*
         * Taxonomy Listout
         * */
        public function nexter_get_taxonomy_list(){
            $post_types = $this->nexter_ext_get_post_type_list();
            $taxonomies = array();
            if ( is_array( $post_types ) ) {
				foreach ( $post_types as $post_type_slug => $post_type_label ) {

					$post_type_taxonomies = get_object_taxonomies( $post_type_slug );


					// Get the hierarchical taxonomies for the post type
					foreach ( $post_type_taxonomies as $key => $taxonomy_name ) {
		                $taxonomy_info = get_taxonomy( $taxonomy_name );

		                if ( empty( $taxonomy_info->show_in_menu ) ||  $taxonomy_info->show_in_menu !== TRUE ) {
		                    unset( $post_type_taxonomies[$key] );
		                } else {
		                	$taxonomies[$post_type_slug][$taxonomy_name] = $taxonomy_info->label;
		                }
		            }
                }
            }

            return $taxonomies;
        }

        public function nexter_ext_get_users_roles(){
            global $wp_roles;

            if (!isset($wp_roles)) {
                $wp_roles = new WP_Roles();
            }

            $roles = $wp_roles->roles;
            $role_names = array_map(function($role) {
                return $role['name'];
            }, $roles);

            return $role_names;
        }
}
