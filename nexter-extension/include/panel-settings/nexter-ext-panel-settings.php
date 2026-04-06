<?php
/**
 * Nexter Builder Shortcode
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Ext_Panel_Settings' ) ) {

	class Nexter_Ext_Panel_Settings {

        /**
         * Member Variable
         */
        private static $instance;
        
        /**
         * Options fields
         */
        protected $option_metabox = array();

        /**
         * Setting Name/Title
         */
        protected $setting_name = '';
        protected $setting_logo = '';

        /**
         * AJAX router handler instance.
         *
         * @var Nxt_Panel_Ajax_Router|null
         */
        private $ajax_router;

        /**
         * Dashboard data handler instance.
         *
         * @var Nxt_Dashboard_Data|null
         */
        private $dashboard_data;

        /**
         *  Initiator
         */
        public static function get_instance() {
            if ( ! isset( self::$instance ) ) {
                self::$instance = new self();
                }
            return self::$instance;
        }
        
        /*
         * Nexter Builder Local
         */
        public function __construct() {
            if (defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Pro_Ext_Activate') && version_compare( NXT_PRO_EXT_VER, '4.0.0', '<' )) {
                require_once NEXTER_EXT_DIR . 'include/panel-settings/nexter-ext-library.php';
            }
            if( is_admin() ){
                require_once __DIR__ . '/class-nxt-panel-ajax-router.php';
                require_once __DIR__ . '/class-nxt-dashboard-data.php';

                $this->ajax_router    = new Nxt_Panel_Ajax_Router( $this );
                $this->dashboard_data = new Nxt_Dashboard_Data();

                $this->get_nxt_brand_name();
                add_action('admin_menu', array( $this, 'nxt_add_menu_page' ));

                // Dashboard data handles admin enqueue scripts
                $this->dashboard_data->register_hooks();

                if ( current_user_can( 'manage_options' ) ) {
                    // AJAX handlers delegated to ajax router
                    $this->ajax_router->register_hooks();
                }

                // Add Extra attr to script tag
                add_filter( 'script_loader_tag', [ $this,'nxt_async_attribute' ], 10, 2 );
                add_action('admin_footer', array($this, 'nxt_link_in_new_tab'));

                add_filter( 'admin_body_class', function( $classes ) {
                    if ( isset($_GET['page']) && $_GET['page'] === 'nxt_builder' ) {
                        $classes .= ' post-type-nxt_builder nxt-page-nexter-builder ';
                    }
                    return $classes;
                }, 11);
            }
        }

        /**
         * Initiate our hooks
         * @since 4.2.0
         */
        public function hooks() {
            if( is_admin() ){
                add_action( 'nxt_ext_new_update_notice' , array( $this, 'nxt_ext_new_update_notice_callback' ) );
            }
        }

        /**
         * Add action to Update Notice Count
         * @since 4.2.0
         */
        public function nxt_ext_new_update_notice_callback(){
             $data = get_option( 'nxt_ext_menu_notice_count', [] );
            if ( ! is_array( $data ) ) {
                $data = [];
            }
            $flag = isset( $data['notice_flag'] ) ? intval( $data['notice_flag'] ) : 1;
            $data['menu_notice_count'] = $flag;
            update_option( 'nxt_ext_menu_notice_count', $data );
        }

        public function nxt_link_in_new_tab(){
            if ( ! $this->nxt_ext_notice_should_show() ) {
                return;
            }
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    var menuItem = document.querySelector('.toplevel_page_nexter_welcome.menu-top');
                    if (menuItem) {
                        menuItem.classList.add('nxt-ext-admin-notice-active');
                    }
                });
            </script>
            <?php
        }

        /**
         * Condition to Check Notice Show
         * @since 4.2.0
         */
        public function nxt_ext_notice_should_show(){
            $data = Nxt_Options::notice_count();
            if ( empty( $data ) || ! is_array( $data ) ) {
                return false;
            }

            $menu_count = isset( $data['menu_notice_count'] ) ? intval( $data['menu_notice_count'] ) : 0;
            $flag       = isset( $data['notice_flag'] ) ? intval( $data['notice_flag'] ) : 1;

            return $menu_count < $flag;
        }

        /*
        * Save Nexter Extension Data — delegated to Nxt_Panel_Ajax_Router.
        * @since 1.1.0
        */
        public function nexter_ext_save_data_ajax(){
            $this->ajax_router->nexter_ext_save_data_ajax();
        }

        /**
         * Safe JSON decode with validation
         * 
         * @param string $json JSON string to decode
         * @param bool $assoc Whether to return associative array
         * @return mixed Decoded data or null on failure
         */
        public function safe_json_decode( $json, $assoc = false ) {
            if ( empty( $json ) || ! is_string( $json ) ) {
                return null;
            }
            
            $decoded = json_decode( $json, $assoc );
            
            // Check for JSON decode errors
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'Nexter Extension: JSON decode error: ' . json_last_error_msg() );
                }
                return null;
            }
            
            return $decoded;
        }

        /**
         * Check if server supports AVIF format (Image Optimisation dashboard).
         * Self-contained in Extension plugin; does not call other plugins.
         *
         * @return bool
         */
        private function nxt_ext_check_avif_supported() {
            return $this->dashboard_data ? $this->dashboard_data->nxt_ext_check_avif_supported_proxy() : false;
        }

        public function nexter_ext_object_convert_to_array($data) {
            return $this->dashboard_data ? $this->dashboard_data->nexter_ext_object_convert_to_array($data) : $data;
        }

        public function get_nxt_brand_name(){
            if ( $this->dashboard_data ) {
                $this->dashboard_data->get_nxt_brand_name();
                $this->setting_name = $this->dashboard_data->get_setting_name();
                $this->setting_logo = $this->dashboard_data->get_setting_logo();
            } else {
                $this->setting_name = esc_html__('Nexter', 'nexter-extension');
                $this->setting_logo = esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter-logo.svg');
            }
        }
        
        /*Load Panel Settings Style & Scripts*/
        public function enqueue_scripts_admin( $hook_suffix ){
            if ( $this->dashboard_data ) {
                $this->dashboard_data->enqueue_scripts_admin( $hook_suffix );
            }
        }

        /* Settings Admin Menu */
        public function nxt_add_menu_page(){
            global $submenu;
            $builder_switch = get_option('nxt_builder_switcher', true);
            unset($submenu['themes.php'][20]);
            unset($submenu['themes.php'][15]);
            $whiteLabelData = Nxt_Options::white_label();
            add_menu_page( 
                esc_html( $this->setting_name ),
                esc_html( $this->setting_name ),
                'manage_options',
                'nexter_welcome',
                array( $this, 'nexter_ext_dashboard' ),
                'dashicons-nxt-builder-groups',
                58
            );
            add_submenu_page(
                'nexter_welcome',
                __( 'Dashboard', 'nexter-extension' ),
                __( 'Dashboard', 'nexter-extension' ),
                'manage_options',
                'nexter_welcome',
            );
            if(!defined('NXT_PRO_EXT') || empty($whiteLabelData) || !isset($whiteLabelData['nxt_template_tab']) || empty($whiteLabelData['nxt_template_tab']) || $whiteLabelData['nxt_template_tab'] != 'on'){
                add_submenu_page(
                    'nexter_welcome',
                    __( 'Templates', 'nexter-extension' ),
                    __( 'Templates', 'nexter-extension' ),
                    'manage_options',
                    'nexter_welcome#/templates',
                    array( $this, 'nexter_ext_dashboard' ),
                );
            }
           
            add_submenu_page(
                'nexter_welcome',
                __( 'Blocks', 'nexter-extension' ),
                __( 'Blocks', 'nexter-extension' ),
                'manage_options',
                'nexter_welcome#/blocks',
                array( $this, 'nexter_ext_dashboard' ),
            );
            
            if ($builder_switch === 'true' || $builder_switch === true) {
                add_submenu_page(
                    'nexter_welcome',
                    __( 'Theme Builder', 'nexter-extension' ),
                    __( 'Theme Builder', 'nexter-extension' ),
                    'manage_options',
                    'nxt_builder',
                    array($this, 'nexter_theme_builder_display')
                );
            } else {
                add_submenu_page(
                    'nexter_welcome',
                    __( 'Theme Builder', 'nexter-extension' ),
                    __( 'Theme Builder', 'nexter-extension' ),
                    'manage_options',
                    'edit.php?post_type=nxt_builder',
                    ''
                );
            }
            // Check if code snippets are enabled before adding the menu
            $get_opt = Nxt_Options::extra_ext();
            $code_snippets_enabled = true;

            if (isset($get_opt['code-snippets']) && isset($get_opt['code-snippets']['switch'])) {
                $code_snippets_enabled = !empty($get_opt['code-snippets']['switch']);
            }
            
            if ($code_snippets_enabled) {
                add_submenu_page(
                    'nexter_welcome',
                    __( 'Code Snippets', 'nexter-extension' ),
                    __( 'Code Snippets', 'nexter-extension' ),
                    'manage_options',
                    'nxt_code_snippets',
                    array($this, 'nexter_code_snippet_display'),
                );
            }
            add_submenu_page(
                'nexter_welcome',
                __( 'Extensions', 'nexter-extension' ),
                __( 'Extensions', 'nexter-extension' ),
                'manage_options',
                'nexter_welcome#/utilities',
                array( $this, 'nexter_ext_dashboard' ),
            );
            add_submenu_page(
                'nexter_welcome',
                __( 'Theme Customizer', 'nexter-extension' ),
                __( 'Theme Customizer', 'nexter-extension' ),
                'manage_options',
                'nexter_welcome#/theme_customizer',
                array( $this, 'nexter_ext_dashboard' ),
            );

            if(defined('TPGB_VERSION')){
                add_submenu_page( 'nexter_welcome',
                    esc_html__( 'Patterns', 'nexter-extension' ),
                    esc_html__( 'Patterns', 'nexter-extension' ),
                    'manage_options',
                    esc_url( admin_url('edit.php?post_type=wp_block'))
                );
            }

            
            if ( defined('TPGBP_VERSION') && defined('TPGBP_PATH') ) {
                $isSub = get_option('tpgb_connection_data');
                if (( empty($isSub) || ( !empty($isSub) && !isset($isSub['nxt_form_submission_Disable'])) || ( isset($isSub['nxt_form_submission_Disable']) && $isSub['nxt_form_submission_Disable'] == 'enable' ) )) {
                    add_submenu_page(
                        "nexter_welcome",
                        "Form Submissions",
                        "Form Submissions",
                        "manage_options",
                        "nxt-form-submissions",
                        [$this, "nxt_load_submissions_handler"]
                    );
                }
            }
            if(!defined('NXT_PRO_EXT') && !defined('TPGBP_VERSION')){
                add_submenu_page( 
                    'nexter_welcome', 
                    esc_html__( 'Get Pro Nexter', 'nexter-extension' ), 
                    esc_html__( 'Get Pro Nexter', 'nexter-extension' ), 
                    'manage_options', 
                    esc_url('https://nexterwp.com/pricing/?utm_source=wpbackend&utm_medium=blocks&utm_campaign=nextersettings')
                );
            }
            if (isset($submenu['nexter_welcome'])) {
                // Find the Dashboard submenu
                foreach ($submenu['nexter_welcome'] as $key => $item) {
                    if ($item[2] === 'nexter_welcome') {
                        $dashboard_item = $item;
                        unset($submenu['nexter_welcome'][$key]);
                        // Prepend Dashboard manually
                        array_unshift($submenu['nexter_welcome'], $dashboard_item);
                        break;
                    }
                }
            }
        }

        /**
		 * Code Snippet Render html
		 * @since  1.0.0
		 */
		public function nexter_code_snippet_display() {
			echo '<div id="nexter-code-snippets"></div>';
		}

        /**
		 * Theme Builder Render html
		 * @since  1.0.0
		 */
		public function nexter_theme_builder_display() {
			echo '<div id="nexter-theme-builder"></div>';
		}

        /**
         * Render Dashboard Root Div
         * @since 1.0.0 nxtext
         */
        public function nexter_ext_dashboard() {
            echo '<div id="nexter-dash"></div>';
            do_action('nxt_ext_new_update_notice');
        }

        /**
         * Load submissions handler when URL contains 'nxt-form-submissions'
         */
         public function nxt_load_submissions_handler(){
            if ( isset($_GET["page"]) && $_GET["page"] === "nxt-form-submissions" && file_exists(TPGBP_PATH . 'classes/extras/nxt-form-submissions.php' ) ) {
                require_once TPGBP_PATH . 'classes/extras/nxt-form-submissions.php';

                if ( class_exists("Tpgb_Submissions_Table") ) { 
                    $submissions_table = new Tpgb_Submissions_Table(); 
                    $submissions_table->nxt_submission_table();
                } else {
                    echo '<div class="wrap">';
                    echo "<h1>".esc_html__('Error: Submission table not found', 'nexter-extension')."</h1>";
                    echo "</div>";
                }
            }
        }

        /*
        * Nexter Extra Option Active Extension
        * @since 1.1.0
        */
        public function nexter_extra_ext_active_ajax(){
            $this->ajax_router->nexter_extra_ext_active_ajax();
        }

        /*
        * Nexter Extra Option DeActivate Extension
        * @since 1.1.0
        */
        public function nexter_extra_ext_deactivate_ajax(){
            $this->ajax_router->nexter_extra_ext_deactivate_ajax();
        }

        public static function nxt_extra_active_deactive( $data = '', $switch = '' ) {

            if ( empty( $data ) || empty( $switch ) ) {
                wp_send_json_error([
                    'content' => __( 'Server not found.', 'nexter-extension' ),
                ]);
            }

            $security_keys   = [ 'email-login-notification', '2-fac-authentication', 'captcha-security', 'svg-upload', 'limit-login-attempt', 'custom-login', 'wp-right-click-disable', 'advance-security' ];
            $performance_keys = [ 'heartbeat-control', 'post-revision-control', 'image-upload-optimize', 'disable-comments', 'advance-performance','google-fonts', 'disabled-image-sizes','nexter-custom-image-sizes', 'disable-elementor-icons' ];

            if ( in_array( $data, $security_keys, true ) ) {
                $option_page = 'nexter_site_security';
            } elseif ( in_array( $data, $performance_keys, true ) ) {
                $option_page = 'nexter_site_performance';
            } else {
                $option_page = 'nexter_extra_ext_options';
            }

            $is_active = ( $switch === 'active' );

            $option = get_option( $option_page, [] );

            if ( isset( $option[ $data ] ) && is_object( $option[ $data ] ) ) {
                $option[ $data ] = (array) $option[ $data ];
            }

            $option[ $data ]['switch'] = $is_active;

            // When turning off Image Optimisation with AVIF format, auto-select webp for next time
            if ( $data === 'image-upload-optimize' && ! $is_active ) {
                if ( isset( $option[ $data ]['values'] ) && is_array( $option[ $data ]['values'] ) ) {
                    if ( isset( $option[ $data ]['values']['image_format'] ) && $option[ $data ]['values']['image_format'] === 'avif' ) {
                        $option[ $data ]['values']['image_format'] = 'webp';
                    }
                }
            }

            update_option( $option_page, $option );

            // Special handling for wp-debug-mode
            if ( $data === 'wp-debug-mode' && $option_page === 'nexter_extra_ext_options' ) {
                if ( $is_active ) {
                    require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/custom-fields/nxt-debug-mode-active.php';
                } else {
                    require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/custom-fields/nxt-debug-mode-deactive.php';
                }
            }

            wp_send_json_success([
                'content' => $is_active ? __( 'Activated', 'nexter-extension' ) : __( 'DeActivated', 'nexter-extension' ),
            ]);
        }

        /*
         * Nexter WP Replace URL Settings
         * @since 1.1.0
         */

        public function nexter_replace_url_tables_and_size(){
            return $this->dashboard_data ? $this->dashboard_data->nexter_replace_url_tables_and_size() : [];
        }

        /**
         * Add the "async" attribute to our registered script.
        */
        public function nxt_async_attribute( $tag, $handle ) {
            if ( 'nexter_recaptcha_api' == $handle ) {
                $tag = str_replace( ' src', ' data-cfasync="false" async="async" defer="defer" src', $tag );
            }
            return $tag;
        }

        /**
         * Get Post List
         */
        public function nexter_ext_get_post_type_list(){
            return $this->dashboard_data ? $this->dashboard_data->nexter_ext_get_post_type_list() : [];
        }
        /*
         * Taxonomy Listout
         * */
        public function nexter_get_taxonomy_list(){
            return $this->dashboard_data ? $this->dashboard_data->nexter_get_taxonomy_list() : [];
        }

        /**
         * Install Nexter Theme Function
         * 
         */
        public function nexter_ext_theme_install_ajax(){
            $this->ajax_router->nexter_ext_theme_install_ajax();
        }

        /**
         * Nexter Theme Details Link
         */
        public function get_nexter_theme_details_link($theme_slug) {
            $admin_url = admin_url('themes.php');
            return add_query_arg('theme', $theme_slug, $admin_url);
        }

        /**
         * Nexter Other Plugin Install
         */
        public function nexter_ext_plugin_install_ajax(){
            $this->ajax_router->nexter_ext_plugin_install_ajax();
        }

        public function wdk_installed_settings_enable(){
            $this->ajax_router->wdk_installed_settings_enable();
        }
        
        /**
         * Get Users Roles
         */
        public function nexter_ext_get_users_roles(){
            return $this->dashboard_data ? $this->dashboard_data->nexter_ext_get_users_roles() : [];
        }

        /**
         * Get Type and SubType for Edit Condition
         */
        public function nexter_ext_edit_condition_data_ajax(){
            $this->ajax_router->nexter_ext_edit_condition_data_ajax();
        }

        /*
         * Export Customizer Data Theme Options
         * @since 4.3.0
         * */
        public function nxt_customizer_export_data(){
            $this->ajax_router->nxt_customizer_export_data();
        }

        /*
         * Import Customizer Data Theme Options
         * @since 4.3.0
         * */
        public function nxt_customizer_import_data(){
            $this->ajax_router->nxt_customizer_import_data();
        }

        
         /**
         * Enable Code Snippet Setting via WDesignKit Hook
         * @since 4.3.4
         */
        public function nexter_enable_code_snippet_ajax(){
            $this->ajax_router->nexter_enable_code_snippet_ajax();
        }

        /**
         * Get Replace URL Tables via AJAX
         * @since 4.3.4
         */
        public function nexter_get_replace_url_tables_ajax(){
            $this->ajax_router->nexter_get_replace_url_tables_ajax();
        }

        /*
         * Wdesignkit Templates load
         */
        public function nexter_temp_api_call() {
            $this->ajax_router->nexter_temp_api_call();
        }
    }
}

$nexter_settings_panel = Nexter_Ext_Panel_Settings::get_instance();
$nexter_settings_panel->hooks();