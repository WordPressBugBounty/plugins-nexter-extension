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
                $this->get_nxt_brand_name();
                add_action('admin_menu', array( $this, 'nxt_add_menu_page' ));
                
                add_action('admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ));

                if ( current_user_can( 'manage_options' ) ) {
                    add_action( 'wp_ajax_nexter_extra_ext_active', [ $this, 'nexter_extra_ext_active_ajax'] );
                    add_action( 'wp_ajax_nexter_extra_ext_deactivate', [ $this, 'nexter_extra_ext_deactivate_ajax'] );
                    add_action( 'wp_ajax_nexter_ext_save_data', [ $this, 'nexter_ext_save_data_ajax']);
                    add_action( 'wp_ajax_nexter_ext_theme_install', [ $this, 'nexter_ext_theme_install_ajax']);
                    add_action( 'wp_ajax_nexter_ext_plugin_install', [ $this, 'nexter_ext_plugin_install_ajax']);
                    add_action( 'wp_ajax_nexter_ext_edit_condition_data', [ $this, 'nexter_ext_edit_condition_data_ajax']);
                    add_action( 'wp_ajax_nexter_enable_code_snippet', [ $this, 'nexter_enable_code_snippet_ajax']);
                    add_action( 'wp_ajax_nexter_get_replace_url_tables', [ $this, 'nexter_get_replace_url_tables_ajax']);
                    add_action( 'wp_ajax_nexter_temp_api_call', [ $this, 'nexter_temp_api_call' ] );
                }

                // Add Extra attr to script tag
                add_filter( 'script_loader_tag', [ $this,'nxt_async_attribute' ], 10, 2 );
                add_action('admin_footer', array($this, 'nxt_link_in_new_tab'));

                //export Theme customize data
                add_action( 'admin_init', [ $this, 'nxt_customizer_export_data' ] );
                add_action('wp_ajax_nxt_import_customizer_data', [ $this, 'nxt_customizer_import_data' ]);
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
            $data = get_option( 'nxt_ext_menu_notice_count', [] );
            if ( ! is_array( $data ) ) {
                return false;
            }

            $menu_count = isset( $data['menu_notice_count'] ) ? intval( $data['menu_notice_count'] ) : 0;
            $flag       = isset( $data['notice_flag'] ) ? intval( $data['notice_flag'] ) : 1;

            return $menu_count < $flag;
        }

        /*
        * Save Nexter Extension Data
        * @since 1.1.0
        */
        public function nexter_ext_save_data_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            
            if ( ! current_user_can( 'manage_options' ) ) {
                return false;
            }

            $ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
            $fonts = ( isset( $_POST['fonts'] ) ) ? wp_unslash( $_POST['fonts'] ) : '';
            $adminHide = ( isset( $_POST['adminHide'] ) ) ? wp_unslash( $_POST['adminHide'] ) : '';
            $adminbarClean = ( isset( $_POST['adminbarClean'] ) ) ? wp_unslash( $_POST['adminbarClean'] ) : '';
            $adminMenuWidth = ( isset( $_POST['adminMenuWidth'] ) ) ? sanitize_text_field(wp_unslash( $_POST['adminMenuWidth'] ) ) : '';
            $revisionControl = ( isset( $_POST['revisionControl'] ) ) ? (wp_unslash( $_POST['revisionControl'] ) ) : '';
            $heartbeatOpt = ( isset( $_POST['heartbeatOpt'] ) ) ? (wp_unslash( $_POST['heartbeatOpt'] ) ) : '';
            $imageUploadOpt = ( isset( $_POST['imageUploadOpt'] ) ) ? (wp_unslash( $_POST['imageUploadOpt'] ) ) : '';
            $cleanUserProfile = ( isset( $_POST['cleanUserProfile'] ) ) ? (wp_unslash( $_POST['cleanUserProfile'] ) ) : '';
            $elementorAdFree = ( isset( $_POST['elementorAdFree'] ) ) ? (wp_unslash( $_POST['elementorAdFree'] ) ) : '';
            $recapData = ( isset( $_POST['recapData'] ) ) ? wp_unslash( $_POST['recapData'] ) : '';
            $wpDisableSet = ( isset( $_POST['wpDisableSet'] ) ) ? wp_unslash( $_POST['wpDisableSet'] ) : '';
            $svgUploadRoles = ( isset( $_POST['svgUploadRoles'] ) ) ? wp_unslash( $_POST['svgUploadRoles'] ) : '';
            $limitLogin = ( isset( $_POST['limitLogin'] ) ) ? wp_unslash( $_POST['limitLogin'] ) : '';

            $wpEmailNotiSet = ( isset( $_POST['wpEmailNotiSet'] ) ) ? wp_unslash( $_POST['wpEmailNotiSet'] ) : '';
            $captchaSetting = ( isset( $_POST['captchaSetting'] ) ) ? wp_unslash( $_POST['captchaSetting'] ) : '';
            $wpLoginWL = ( isset( $_POST['wpLoginWL'] ) ) ? wp_unslash( $_POST['wpLoginWL'] ) : '';
            $performance = ( isset( $_POST['advanceperfo'] ) ) ? wp_unslash( $_POST['advanceperfo'] ) : '';
            $commdata = ( isset( $_POST['discomment'] ) ) ? wp_unslash( $_POST['discomment'] ) : '';
            $googlefonts = ( isset( $_POST['googlefonts'] ) ) ? wp_unslash( $_POST['googlefonts'] ) : '';
            $wpDupPostSet = ( isset( $_POST['wpDupPostSet'] ) ) ? wp_unslash( $_POST['wpDupPostSet'] ) : '';
            $post_types = ( isset( $_POST['post_types'] ) ) ? wp_unslash( $_POST['post_types'] ) : '';
            $disable_gutenberg_posts = ( isset( $_POST['disable_gutenberg_posts'] ) ) ? wp_unslash( $_POST['disable_gutenberg_posts'] ) : '';
            $preview_drafts = ( isset( $_POST['preview_drafts'] ) ) ? wp_unslash( $_POST['preview_drafts'] ) : '';
            $taxonomy_order = ( isset( $_POST['taxonomy_order'] ) ) ? wp_unslash( $_POST['taxonomy_order'] ) : '';
            $redirect_404 = ( isset( $_POST['redirect_404'] ) ) ? sanitize_text_field( wp_unslash( $_POST['redirect_404'] ) )  : '';
            $wpWLSet = ( isset( $_POST['wpWLSet'] ) ) ? wp_unslash( $_POST['wpWLSet'] ) : '';
            $securData = ( isset( $_POST['securData'] ) ) ? wp_unslash( $_POST['securData'] ) : '';
            $nxtctmLogin = ( isset( $_POST['nxtctmLogin'] ) ) ? wp_unslash( $_POST['nxtctmLogin'] ) : '';
            $image_size = ( isset( $_POST['image_size'] ) ) ? wp_unslash( $_POST['image_size'] ) : '';
            $new_custom_image_size = ( isset( $_POST['new_custom_size'] ) ) ? wp_unslash( $_POST['new_custom_size'] ) : '';
            // Security: Validate JSON before decoding
            $new_custom_image_size = $this->safe_json_decode( $new_custom_image_size, true );
            if ( ! is_array( $new_custom_image_size ) ) {
                $new_custom_image_size = array();
            }
            $ele_icons = ( isset( $_POST['ele_icons'] ) ) ? wp_unslash( $_POST['ele_icons'] ) : '';
            
            $editoropt = ( isset( $_POST['editoropt'] ) ) ? sanitize_text_field(wp_unslash( $_POST['editoropt'] ) ) : '';
            if(!empty($ext) && $ext ==='nexter-custom-image-sizes'){
                $all_custom_image_sized = get_option('nexter_custom_image_sizes',array());
                if(isset($all_custom_image_sized[$new_custom_image_size['name']])){
                    wp_send_json_error();
                }
                $all_custom_image_sized[$new_custom_image_size['name']] = $new_custom_image_size;
                if(update_option('nexter_custom_image_sizes', $all_custom_image_sized)){
                    wp_send_json_success(
                        array(
                            'content'	=> $new_custom_image_size,
                        )
                    );
                } else{
                    wp_send_json_error();
                }
            }
            $option_page = 'nexter_extra_ext_options';
            $get_option = get_option($option_page);

            $perforoption = 'nexter_site_performance';
            $getperoption = get_option($perforoption);

            $secr_opt = 'nexter_site_security';
            $getSecopt = get_option($secr_opt);

            $wlOption = 'nexter_white_label';
            $get_wl_option = get_option($wlOption);

            /*if( !empty( $ext ) && $ext==='local-google-font' && !empty($fonts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($fonts);
                    update_option( $option_page, $get_option );
                    if(class_exists('Nexter_Font_Families_Listing')){
                        Nexter_Font_Families_Listing::get_local_google_font_data();
                    }
                }
                wp_send_json_success();
            }else*/
            if(!empty( $ext ) && $ext==='custom-upload-font' && !empty($fonts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode with validation
                    $decoded_fonts = $this->safe_json_decode( $fonts, true );
                    if ( is_array( $decoded_fonts ) ) {
                        $get_option[ $ext ]['values'] = $decoded_fonts;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='disable-admin-setting' && !empty($adminHide)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($adminHide);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='clean-up-admin-bar' && !empty($adminbarClean)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($adminbarClean);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='wider-admin-menu' && !empty($adminMenuWidth)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($adminMenuWidth);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='clean-user-profile' && !empty($cleanUserProfile) && defined( 'NXT_PRO_EXT' )){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode with validation
                    $decoded_cleanUserProfile = $this->safe_json_decode( $cleanUserProfile, false );
                    if ( $decoded_cleanUserProfile !== null ) {
                        $get_option[ $ext ]['values'] = $decoded_cleanUserProfile;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='elementor-adfree' && !empty($elementorAdFree)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode with validation
                    $decoded_elementorAdFree = $this->safe_json_decode( $elementorAdFree, false );
                    if ( $decoded_elementorAdFree !== null ) {
                        $get_option[ $ext ]['values'] = $decoded_elementorAdFree;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='google-recaptcha' && !empty($recapData)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode with validation
                    $decoded_recapData = $this->safe_json_decode( $recapData, true );
                    if ( is_array( $decoded_recapData ) ) {
                        $get_option[ $ext ]['values'] = $decoded_recapData;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='wp-login-white-label' && !empty($wpLoginWL)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode with validation
                    $wpLoginDE = $this->safe_json_decode( $wpLoginWL, true );
                    if ( ! is_array( $wpLoginDE ) ) {
                        $wpLoginDE = array();
                    }
                    $get_option[ $ext ]['values'] = $wpLoginDE;
                    if(class_exists('Nexter_Ext_Wp_Login_White_Label')){
                        $get_option[ $ext ]['css'] = Nexter_Ext_Wp_Login_White_Label::nxtWLCSSGenerate($wpLoginDE);
                    }
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && ( $ext==='advance-performance' && !empty($performance) ) || ($ext==='disable-comments' && !empty($commdata) ) || ($ext==='google-fonts' && !empty($googlefonts) ) || ($ext==='post-revision-control' && !empty($revisionControl) ) || ($ext==='heartbeat-control' && !empty($heartbeatOpt) ) || ($ext==='image-upload-optimize' && !empty($imageUploadOpt) ) || ($ext==='disabled-image-sizes' ) || ($ext==='disable-elementor-icons' ) ){
                // Security: Safe JSON decode with validation
                $advanceData = $this->safe_json_decode( $performance, false );
                $disableComm_raw = $this->safe_json_decode( $commdata, false );
                $disableComm = is_array( $disableComm_raw ) ? $disableComm_raw : (array) $disableComm_raw;

                $googlefonts = $this->safe_json_decode( $googlefonts, true );
                if ( ! is_array( $googlefonts ) ) {
                    $googlefonts = array();
                }

                if( False === $getperoption || empty($getperoption) ){	
                    if(!empty($advanceData) ){
                        update_option($perforoption,$advanceData);
                    }
                    if(!empty($googlefonts)){
                        update_option($perforoption,$googlefonts);
                    }
                }else{
                    $get_option = get_option($perforoption);
                    $new = $get_option;
                    if(!empty($get_option)){
                        if( $ext==='advance-performance'){
                            $old_comment = [];
                            if(isset($get_option['disable_comments'])){
                                $old_comment['disable_comments'] = $get_option['disable_comments'];
                            }
                            if(isset($get_option['disble_custom_post_comments'])){
                                $old_comment['disble_custom_post_comments'] = $get_option['disble_custom_post_comments'];
                            }
                            $get_option = array_merge($get_option,$old_comment);
                            if(!empty($advanceData)){
                                $get_option[ $ext ]['switch'] = true;
                                foreach($advanceData as $value){
                                    if(($key = array_search($value, $get_option, true)) !== false){
                                        unset($get_option[$key]);
                                    }
                                }
                            }
                            $get_option[ $ext ]['values'] = $advanceData;
                            $new = $get_option;
                        }else if($ext==='disable-comments'){
                            if(isset($get_option['disable_comments'])){
                                unset($get_option['disable_comments']);
                            }
                            if(isset($get_option['disble_custom_post_comments'])){
                                unset($get_option['disble_custom_post_comments']);
                            }
                            if( !isset($get_option[ $ext ]['switch']) && !empty($disableComm)){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            $get_option[ $ext ]['values'] = $disableComm;
                            $new = $get_option;
                        }else if($ext==='google-fonts'){
                            if(isset($get_option['nexter_google_fonts'])){
                                unset($get_option['nexter_google_fonts']);
                            }
                            if( !isset($get_option[ $ext ]['switch']) && !empty($googlefonts)){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            $get_option[ $ext ]['values'] = $googlefonts;
                            $new = $get_option;
                        }else if($ext==='heartbeat-control' && !empty($heartbeatOpt)){
                            if( isset($get_option[ $ext ]) ){
                                // Security: Safe JSON decode
                                $decoded_heartbeatOpt = $this->safe_json_decode( $heartbeatOpt, false );
                                if ( $decoded_heartbeatOpt !== null ) {
                                    $get_option[ $ext ]['values'] = $decoded_heartbeatOpt;
                                    $new = $get_option;
                                }
                            }
                        }else if($ext==='post-revision-control' && !empty($revisionControl)){
                            if(  isset($get_option[ $ext ]) ){
                                // Security: Safe JSON decode
                                $decoded_revisionControl = $this->safe_json_decode( $revisionControl, false );
                                if ( $decoded_revisionControl !== null ) {
                                    $get_option[ $ext ]['values'] = $decoded_revisionControl;
                                    $new = $get_option;
                                }
                            }
                        }else if($ext==='image-upload-optimize' && !empty($imageUploadOpt)){
                            if( isset($get_option[ $ext ]) ){
                                // Security: Safe JSON decode
                                $decoded_imageUploadOpt = $this->safe_json_decode( $imageUploadOpt, false );
                                if ( $decoded_imageUploadOpt !== null ) {
                                    $get_option[ $ext ]['values'] = $decoded_imageUploadOpt;
                                    $new = $get_option;
                                }
                            }
                        }else if($ext==='disabled-image-sizes'){
                            if( !isset($get_option[ $ext ])){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            if( isset($get_option[ $ext ]) ){
                                $image_size = !empty($image_size) ? explode(",",$image_size) : array();
                                $get_option[ $ext ]['values'] = $image_size;
                                delete_option('nexter_disabled_images');
                            }
                            $new = $get_option;
                        }else if($ext==='nexter-custom-image-sizes'){
                            if( !isset($get_option[ $ext ])){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            $new = $get_option;
                        }else if($ext==='disable-elementor-icons'){
                            if( !isset($get_option[ $ext ])){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            if( isset($get_option[ $ext ]) ){
                                $ele_icons = !empty($ele_icons) ? explode(",",$ele_icons) : [];
                                $get_option[ $ext ]['values'] = $ele_icons;
                                delete_option('nexter_elementor_icons');
                            }
                            $new = $get_option;
                        }
                        update_option( $perforoption, $new );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && ( $ext==='advance-security' && !empty($securData) ) || ( $ext==='custom-login' && !empty($nxtctmLogin) ) || ( $ext==='wp-right-click-disable' && !empty($wpDisableSet) ) || ($ext==='email-login-notification' && !empty($wpEmailNotiSet)) || ($ext==='2-fac-authentication' ) || ($ext==='captcha-security' && !empty($captchaSetting)) || ($ext==='svg-upload' && !empty($svgUploadRoles)) || ($ext==='limit-login-attempt' && !empty($limitLogin)) ){
                // Security: Safe JSON decode with validation
                $securData_decoded = $this->safe_json_decode( $securData, true );
                $securData = is_array( $securData_decoded ) ? $securData_decoded : array();
                
                $nxtctmLogin_decoded = $this->safe_json_decode( $nxtctmLogin, true );
                $nxtctmLogin = is_array( $nxtctmLogin_decoded ) ? $nxtctmLogin_decoded : array();
                
                $disrightclick_decoded = $this->safe_json_decode( $wpDisableSet, true );
                $disrightclick = is_array( $disrightclick_decoded ) ? $disrightclick_decoded : array();
                
                $svg_upload_roles_decoded = $this->safe_json_decode( $svgUploadRoles, true );
                $svg_upload_roles = is_array( $svg_upload_roles_decoded ) ? $svg_upload_roles_decoded : array();
                
                $limit_login_attempt_decoded = $this->safe_json_decode( $limitLogin, true );
                $limit_login_attempt = is_array( $limit_login_attempt_decoded ) ? $limit_login_attempt_decoded : array();
                
                $emailNotiSet_decoded = $this->safe_json_decode( $wpEmailNotiSet, true );
                $emailNotiSet = is_array( $emailNotiSet_decoded ) ? $emailNotiSet_decoded : array();
                
                $captchaSetting_decoded = $this->safe_json_decode( $captchaSetting, true );
                $captchaSetting = is_array( $captchaSetting_decoded ) ? $captchaSetting_decoded : array();
                
                if( False === $getSecopt || empty($getSecopt) ){
                    if(!empty($securData) ){
                        add_option($secr_opt,$securData);
                    }else if(!empty($nxtctmLogin)){
                        if(isset($nxtctmLogin['custom_login_url']) && !empty($nxtctmLogin['custom_login_url'])){
                            $nxtctmLogin['custom_login_url'] = sanitize_key($nxtctmLogin['custom_login_url']);
                        }
                        add_option($secr_opt,$nxtctmLogin);
                    }else if(!empty($disrightclick)){
                        $disValue = [];
                        if(class_exists('Nexter_Ext_Right_Click_Disable')){
                            $disValue[ $ext ]['values'] = $disrightclick;
                            $disValue[ $ext ]['css'] = Nexter_Ext_Right_Click_Disable::nxtrClickCSSGenerate($disrightclick);
                        }
                        add_option($secr_opt,$disValue);
                    }else if(!empty($emailNotiSet)){
                        $emailVal[ $ext ]['values'] = $emailNotiSet;
                        $emailVal[ $ext ]['switch'] = true;
                        update_option( $secr_opt, $emailVal );
                    }else if(!empty($captchaSetting)){
                        $captchaVal[ $ext ]['values'] = $captchaSetting;
                        $captchaVal[ $ext ]['switch'] = true;
                        update_option( $secr_opt, $captchaVal );
                    }else if(!empty($svg_upload_roles)){
                        $svg_upload_val[ $ext ]['values'] = $svg_upload_roles;
                        $svg_upload_val[ $ext ]['switch'] = true;
                        update_option( $secr_opt, $svg_upload_val );
                    }else if(!empty($limit_login_attempt)){
                        $svg_upload_val[ $ext ]['values'] = $limit_login_attempt;
                        $svg_upload_val[ $ext ]['switch'] = true;
                        update_option( $secr_opt, $svg_upload_val );
                    }
                }else{
                    
                    $get_option = get_option($secr_opt);
                    $new_sec = $get_option;
                    if(!empty($get_option)){
                        if($ext==='advance-security'){

                            if( false !== array_search('disable_xml_rpc', $get_option)){
                                unset($get_option[array_search('disable_xml_rpc', $get_option)]);
                            }
                            if( false !== array_search('disable_wp_version', $get_option)){
                                unset($get_option[array_search('disable_wp_version', $get_option)]);
                            }
                            if( false !== array_search('disable_rest_api_links', $get_option)){
                                unset($get_option[array_search('disable_rest_api_links', $get_option)]);
                            }
                            if(false !== array_search('disable_file_editor', $get_option)){
                                unset($get_option[array_search('disable_file_editor' , $get_option)]);
                            }
                            if(false !== array_search('disable_wordpress_application_password', $get_option)){
                                unset($get_option[array_search('disable_wordpress_application_password' , $get_option)]);
                            }
                            if(false !== array_search('redirect_user_enumeration', $get_option)){
                                unset($get_option[array_search('redirect_user_enumeration' , $get_option)]);
                            }
                            if(false !== array_search('remove_meta_generator', $get_option)){
                                unset($get_option[array_search('remove_meta_generator' , $get_option)]);
                            }
                            if(false !== array_search('remove_css_version', $get_option)){
                                unset($get_option[array_search('remove_css_version' , $get_option)]);
                            }
                            if(false !== array_search('remove_js_version', $get_option)){
                                unset($get_option[array_search('remove_js_version' , $get_option)]);
                            }
                            if(false !== array_search('hide_wp_include_folder', $get_option)){
                                unset($get_option[array_search('hide_wp_include_folder' , $get_option)]);
                            }
                            if(array_key_exists('disable_rest_api', $get_option)){
                                unset($get_option['disable_rest_api']);
                            }
                            if(false !== array_search('secure_cookies', $get_option)){
                                unset($get_option[array_search('secure_cookies' , $get_option)]);
                            }
                            if(array_key_exists('iframe_security', $get_option)){
                                unset($get_option['iframe_security']);
                            }
                            if(false !== array_search('xss_protection', $get_option)){
                                unset($get_option[array_search('xss_protection' , $get_option)]);
                            }
                            if(false !== array_search('user_last_login_display', $get_option)){
                                unset($get_option[array_search('user_last_login_display' , $get_option)]);
                            }
                            if(false !== array_search('user_register_date_time', $get_option)){
                                unset($get_option[array_search('user_register_date_time' , $get_option)]);
                            }
                            if(false !== array_search('obfuscator_email_address', $get_option)){
                                unset($get_option[array_search('obfuscator_email_address' , $get_option)]);
                            }
                            if(false !== array_search('obfuscator_author_slug', $get_option)){
                                unset($get_option[array_search('obfuscator_author_slug' , $get_option)]);
                            }
                            if(false !== array_search('hide_telephone_secure', $get_option)){
                                unset($get_option[array_search('hide_telephone_secure' , $get_option)]);
                            }
                            $get_option = self::nexter_ext_object_convert_to_array($get_option);
                           
                            $securData = self::nexter_ext_object_convert_to_array($securData);
                            $get_option[ $ext ]['switch'] = true;
                            
                            $get_option[ $ext ]['values'] = $securData;
                            $newArr = $get_option;
                        }else if($ext==='custom-login'){
                            if(isset($get_option['custom_login_url'])){
                                unset($get_option['custom_login_url']);
                            }
                            if(isset($get_option['disable_login_url_behavior'])){
                                unset($get_option['disable_login_url_behavior']);
                            }
                            if(isset($get_option['login_page_message'])){
                                unset($get_option['login_page_message']);
                            }
                            if(isset($nxtctmLogin['custom_login_url']) && !empty($nxtctmLogin['custom_login_url'])){
                                $nxtctmLogin['custom_login_url'] = sanitize_key($nxtctmLogin['custom_login_url']);
                            }
                            if(isset($nxtctmLogin['login_page_message']) && !empty($nxtctmLogin['login_page_message'])){
                                $nxtctmLogin['login_page_message'] = sanitize_text_field( wp_unslash($nxtctmLogin['login_page_message']));
                            }
                            if( !isset($get_option[ $ext ])){
                                $get_option[ $ext ]['switch'] = true;
                            }
                            if( isset($get_option[ $ext ]) ){
                                $get_option[ $ext ]['values'] = $nxtctmLogin;
                            }
                            $newArr = $get_option;
                        }else if( $ext==='wp-right-click-disable' ){
                            if(isset($get_option[ $ext ]['values']) && !empty($get_option[ $ext ]['values']) ){
                                unset($get_option[ $ext ]['values']);
                            }
                            if(isset($get_option[ $ext ]['css']) && !empty($get_option[ $ext ]['css']) ){
                                unset($get_option[ $ext ]['css']);
                            }
                            $newdata = [];
                            if(class_exists('Nexter_Ext_Right_Click_Disable')){
                                $get_option[ $ext ]['switch'] = true;
                                $get_option[ $ext ]['values'] = $disrightclick;
                                $get_option[ $ext ]['css'] = Nexter_Ext_Right_Click_Disable::nxtrClickCSSGenerate($disrightclick);
                            }
                            $newArr = $get_option;
                        }else if($ext==='email-login-notification'){
                            $get_option[ $ext ]['values'] =  $emailNotiSet;
                            $get_option[ $ext ]['switch'] =  true;
                            $newArr = $get_option;
                        }else if($ext==='svg-upload'){
                            $get_option[ $ext ]['values'] =  $svg_upload_roles;
                            $get_option[ $ext ]['switch'] =  true;
                            $newArr = $get_option;
                        }else if($ext === '2-fac-authentication'){
	                        $allowed_2fa_roles_raw = ( isset( $_POST['allowed_2fa_roles'] ) ) ? wp_unslash( $_POST['allowed_2fa_roles'] ) : '';
	                        // Security: Safe JSON decode
	                        $allowed_2fa_roles_decoded = $this->safe_json_decode( $allowed_2fa_roles_raw, true );
	                        $allowed_2fa_roles = is_array( $allowed_2fa_roles_decoded ) ? $allowed_2fa_roles_decoded : array();
	                        $email_customisation = array();
                            $email_customisation['subject'] = ( isset( $_POST["customEmailSubject"] ) ) ? wp_kses_post( wp_unslash( $_POST['customEmailSubject'] ) ) : '';
                            $email_customisation['body'] = ( isset( $_POST["customEmailBody"] ) ) ? wp_kses_post( wp_unslash( $_POST['customEmailBody'] ) ) : '';
                            $get_option[$ext]['values']['allowed_2fa_roles'] = $allowed_2fa_roles;
                            $get_option[$ext]['values']['email_customisations'] = $email_customisation;
                            $get_option[$ext]['switch'] = true;
                            $newArr = $get_option;
                        }else if($ext==='captcha-security'){
                            $get_option[ $ext ]['values'] = $captchaSetting;
                            $get_option[ $ext ]['switch'] = true;
                            $newArr = $get_option;
                        }else if($ext==='limit-login-attempt'){
                            $get_option[ $ext ]['values'] = $limit_login_attempt;
                            $get_option[ $ext ]['switch'] = true;
                            $newArr = $get_option;
                        }
                        update_option( $secr_opt, $newArr );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='wp-duplicate-post' && !empty($wpDupPostSet)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode
                    $decoded_wpDupPostSet = $this->safe_json_decode( $wpDupPostSet, true );
                    $get_option[ $ext ]['values'] = is_array( $decoded_wpDupPostSet ) ? $decoded_wpDupPostSet : array();
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='content-post-order' && !empty($post_types)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode
                    $decoded_post_types = $this->safe_json_decode( $post_types, true );
                    $get_option[ $ext ]['values'] = is_array( $decoded_post_types ) ? $decoded_post_types : array();
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='disable-gutenberg' && !empty($disable_gutenberg_posts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    // Security: Safe JSON decode
                    $decoded_disable_gutenberg_posts = $this->safe_json_decode( $disable_gutenberg_posts, false );
                    if ( $decoded_disable_gutenberg_posts !== null ) {
                        $get_option[ $ext ]['values'] = $decoded_disable_gutenberg_posts;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='public-preview-drafts' && !empty($preview_drafts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ])){
                    // Security: Safe JSON decode
                    $decoded_preview_drafts = $this->safe_json_decode( $preview_drafts, false );
                    if ( $decoded_preview_drafts !== null ) {
                        $get_option[ $ext ]['values'] = $decoded_preview_drafts;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='taxonomy-order' && !empty($taxonomy_order)){
                if( !empty( $get_option ) && isset($get_option[ $ext ])){
                    // Security: Safe JSON decode
                    $decoded_taxonomy_order = $this->safe_json_decode( $taxonomy_order, false );
                    if ( $decoded_taxonomy_order !== null ) {
                        $get_option[ $ext ]['values'] = $decoded_taxonomy_order;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='redirect-404-page' && defined( 'NXT_PRO_EXT' ) ){
                if( !empty( $get_option ) && isset($get_option[ $ext ])){
                    $get_option[ $ext ]['values'] = !empty($redirect_404) ? $redirect_404 : '';
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='white-label' && !empty($wpWLSet)){
                // Security: Safe JSON decode
                $whiteLabelData_decoded = $this->safe_json_decode( $wpWLSet, true );
                $whiteLabelData = is_array( $whiteLabelData_decoded ) ? $whiteLabelData_decoded : array();
                if( !empty($whiteLabelData) && isset($whiteLabelData['theme_screenshot_id']) && !empty($whiteLabelData['theme_screenshot_id']) && isset($whiteLabelData['theme_screenshot'])){
                    $fileName = basename(get_attached_file($whiteLabelData['theme_screenshot_id']));
                    $filepathname = basename($whiteLabelData['theme_screenshot']);
                    if(!empty($fileName) && !empty($filepathname)){
                        $filetype = wp_check_filetype($fileName);
                        $filepathtype = wp_check_filetype($filepathname);
                        if(!empty($filetype) && isset($filetype['type']) && !empty($filepathtype) && isset($filepathtype['type'])){
                            if(!(strpos($filetype['type'], 'image') !== false) || !(strpos($filepathtype['type'], 'image') !== false)) {
                                $whiteLabelData['theme_screenshot'] = '';
                                $whiteLabelData['theme_screenshot_id'] = '';
                            }
                        }
                    }
                }
                if( !empty($whiteLabelData) && isset($whiteLabelData['theme_logo_id']) && !empty($whiteLabelData['theme_logo_id']) && isset($whiteLabelData['theme_logo'])){
                    $fileName = basename(get_attached_file($whiteLabelData['theme_logo_id']));
                    $filepathname = basename($whiteLabelData['theme_logo']);
                    if(!empty($fileName) && !empty($filepathname)){
                        $filetype = wp_check_filetype($fileName);
                        $filepathtype = wp_check_filetype($filepathname);
                        if(!empty($filetype) && isset($filetype['type']) && !empty($filepathtype) && isset($filepathtype['type'])){
                            if(!(strpos($filetype['type'], 'image') !== false) || !(strpos($filepathtype['type'], 'image') !== false)) {
                                $whiteLabelData['theme_logo'] = '';
                                $whiteLabelData['theme_logo_id'] = '';
                            }
                        }
                    }
                }
                if( False === $get_wl_option ){
                    add_option($wlOption,$whiteLabelData);
                }else{
                    update_option( $wlOption, $whiteLabelData );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='code-snippets' && isset($editoropt)){
                // Security: Safe JSON decode
                $decoded_editoropt = $this->safe_json_decode( $editoropt, true );
                if ( ! is_array( $decoded_editoropt ) ) {
                    $decoded_editoropt = array();
                }
                
                if( !empty( $get_option ) ){
                    $get_option[ $ext ]['values'] = $decoded_editoropt;
                }else{
                    if ( ! is_array( $get_option ) ) {
                        $get_option = [];
                    }
                    $get_option[ $ext ]['values'] = $decoded_editoropt;
                }
                update_option( $option_page, $get_option );
                wp_send_json_success();
            }

            wp_send_json_error();
        }

        /**
         * Safe JSON decode with validation
         * 
         * @param string $json JSON string to decode
         * @param bool $assoc Whether to return associative array
         * @return mixed Decoded data or null on failure
         */
        private function safe_json_decode( $json, $assoc = false ) {
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
        
        public function nexter_ext_object_convert_to_array($data) {
            if (is_object($data)) {
                $data = (array) $data;
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $data[$key] = self::nexter_ext_object_convert_to_array($value);
                }
            }
            return $data;
        }
        
        public function get_nxt_brand_name(){
            if(defined('NXT_PRO_EXT') || defined('TPGBP_VERSION')){
                $options = get_option( 'nexter_white_label' );
                $this->setting_name = (!empty($options['brand_name'])) ? $options['brand_name'] : esc_html__('Nexter', 'nexter-extension');
                $this->setting_logo = (!empty($options['theme_logo'])) ? $options['theme_logo'] : esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter-logo.svg');
            }else{
                $this->setting_name = esc_html__('Nexter', 'nexter-extension');
                $this->setting_logo = esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter-logo.svg');
            }
        }
        
        /*Load Panel Settings Style & Scripts*/
        public function enqueue_scripts_admin( $hook_suffix ){
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

            $user = wp_get_current_user();
            $enabled_is = [];
            $get_performance = get_option('nexter_site_performance');
            if(!empty($get_performance) && isset($get_performance['disabled-image-sizes']) && isset($get_performance['disabled-image-sizes']['switch']) && isset($get_performance['disabled-image-sizes']['values'])){
                $enabled_is = (array) $get_performance['disabled-image-sizes']['values'];
            }else{
                $enabled_is = get_option('nexter_disabled_images',array());
            }
            $intermediate_image = get_intermediate_image_sizes();
            $get_image_sizes = array_unique(array_merge($intermediate_image, $enabled_is));

            $themes = wp_get_themes();
            $nexterInstalled = array_key_exists('nexter', $themes);
            $theme_det_link = self::get_nexter_theme_details_link('nexter');

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
            
            if(! did_action('wp_enqueue_media')){
				wp_enqueue_media();
			}
            
            if ( ! is_customize_preview() ) {
                wp_enqueue_style( 'nxt-panel-settings', NEXTER_EXT_URL .'assets/css/admin/nexter-admin'. $minified .'.css', array(), NEXTER_EXT_VER );
            }

            if ( ( ( 'post-new.php' != $hook_suffix && 'post.php' != $hook_suffix && 'edit.php' == $hook_suffix ) && ( isset( $_GET['post_type'] ) && 'nxt_builder' == $_GET['post_type'] ) || ( defined( 'NXT_BUILD_POST' ) && NXT_BUILD_POST == get_post_type() ) ) || (isset($_GET['page']) && $_GET['page'] === 'nxt_builder')){
                wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2'. $minified .'.css', array(), NEXTER_EXT_VER );
			    wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2'. $minified .'.js', array(), NEXTER_EXT_VER, false );
            }
            if ( ! is_customize_preview() ) {
				wp_enqueue_style( 'wp-color-picker' );
			}   
            
            
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( ! is_customize_preview() ) {
                // Dash Board Css Js Enqueue
                wp_enqueue_style( 'nexter-welcome-style', NEXTER_EXT_URL . 'dashboard/build/index.css', array(), NEXTER_EXT_VER, 'all' );
            }

			wp_enqueue_script( 'nexter-ext-dashscript', NEXTER_EXT_URL . 'dashboard/build/index.js', array( 'react', 'react-dom','wp-i18n', 'wp-dom-ready', 'wp-element','wp-components', 'wp-block-editor', 'wp-editor' ), NEXTER_EXT_VER, true );

            // Attach JavaScript translations
            wp_set_script_translations(
                'nexter-ext-dashscript',  // Handle must match enqueue
                'nexter-extension',                 // Your text domain
                NEXTER_EXT_DIR . 'languages'
            );
            
            if ( is_multisite() ) {
				$main_site_id = get_main_site_id();
				$licence_key = get_blog_option( $main_site_id, 'nexter_activate', [] );
				if(empty($licence_key)){
					$licence_key = get_option('nexter_activate');
				}
			}else{
				$licence_key = get_option('nexter_activate');
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
                'nxtExtra' => get_option('nexter_extra_ext_options'),
                'nxtPerformance' => get_option('nexter_site_performance'),
                'intermediateImgSize' => $intermediate_image,
                'nxtGetImgSize' => $get_image_sizes,
                'nxtDisableImg' => (!empty(get_option('nexter_disabled_images'))) ? get_option('nexter_disabled_images') : [],
                'nxtImageSize' => get_option('nexter_custom_image_sizes'),
                'nxtSecurity' => get_option('nexter_site_security'),
                'nexterThemeActive' => (defined('NXT_VERSION')) ? true : false,
                'nexterThemeIntall' =>  $nexterInstalled,
                'nexterThemeDet' => $theme_det_link,
                'nexterCustLink' => admin_url('customize.php'),
                'elementorplugin' => class_exists( '\Elementor\Plugin' ),
                'elementorDisIcons' => get_option('nexter_elementor_icons'),
                'nxtGoogleFonts' => get_option('nexter_google_fonts'),
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
                'whiteLabel' => defined('NXT_PRO_EXT') ? get_option('nexter_white_label') : (defined('TPGBP_VERSION') ? get_option('tpgb_white_label') : []),
                'keyActmsg' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_ext_pro_activate_msg() : '',
                'nxtactivateKey' => $licence_key,
                'activePlan' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_get_activate_plan() : '',
                'roles' => self::nexter_ext_get_users_roles(),
                'showSidebar' => $this->nxt_ext_notice_should_show(),
                'nxtThemeSetting' => (array) get_option( 'nexter_settings_opts', [] ),
                'nxt_wdkit_url' => 'https://api.wdesignkit.com/',
                'extensionPro' =>  defined('NXT_PRO_EXT_VER'),
            ];

            $current_user_username = '';
            if (!empty($user) && isset($user->user_login) && !empty($user->user_login)) {
                $current_user_username = $user->user_login;
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
                'themebuilderStatus' => get_option('nxt_builder_switcher', true),
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
            );

            wp_localize_script( 'nexter-ext-dashscript', 'nexter_admin_config', $nexter_admin_localize );

            if (isset($_GET['page']) && $_GET['page'] === 'nxt_builder') {
                // Theme Builder JS Enqueue
                wp_enqueue_style( 'nexter-theme-builder', NEXTER_EXT_URL . 'theme-builder/build/index.css', array(), NEXTER_EXT_VER, 'all' );

                wp_enqueue_script( 'nexter-theme-builder', NEXTER_EXT_URL . 'theme-builder/build/index.js', array( 'react', 'react-dom', 'wp-dom-ready', 'wp-i18n' ), NEXTER_EXT_VER, true );

                // Check if post duplicator extension is enabled
                $extension_option = get_option( 'nexter_extra_ext_options' );
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

        /* Settings Admin Menu */
        public function nxt_add_menu_page(){
            global $submenu;
            $builder_switch = get_option('nxt_builder_switcher', true);
            unset($submenu['themes.php'][20]);
            unset($submenu['themes.php'][15]);
            $whiteLabelData =get_option('nexter_white_label');
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
            $get_opt = get_option('nexter_extra_ext_options');
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

        /*
        * Nexter Extra Option Active Extension
        * @since 1.1.0
        */
        public function nexter_extra_ext_active_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(
                    array( 
                        'content' => __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }
            $type = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
            self::nxt_extra_active_deactive($type, 'active');
        }

        /*
        * Nexter Extra Option DeActivate Extension
        * @since 1.1.0
        */
        public function nexter_extra_ext_deactivate_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(
                    array( 
                        'content' => __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }
            $type = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
            self::nxt_extra_active_deactive($type, 'deactive');
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
            return $table_and_sizes;
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

        /**
         * Install Nexter Theme Function
         * 
         */
        public function nexter_ext_theme_install_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' ); 
    
            if ( !current_user_can( 'manage_options' ) ) {
                wp_send_json_error( __( 'You are not allowed to do this action', 'nexter-extension' ) );
            }
    
            $theme_slug = (!empty($_POST['slug'])) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : 'nexter';
            $theme_api_url = 'https://api.wordpress.org/themes/info/1.0/';
    
            // Parameters for the request
            $args = array(
                'body' => array(
                    'action' => 'theme_information',
                    'request' => serialize((object) array(
                        'slug' => 'nexter',
                        'fields' => [
                            'description' => false,
                            'sections' => false,
                            'rating' => true,
                            'ratings' => false,
                            'downloaded' => true,
                            'download_link' => true,
                            'last_updated' => true,
                            'homepage' => true,
                            'tags' => true,
                            'template' => true,
                            'active_installs' => false,
                            'parent' => false,
                            'versions' => false,
                            'screenshot_url' => true,
                            'active_installs' => false
                        ],
                    ))),
            );
    
            // Make the request
            $response = wp_remote_post($theme_api_url, $args);
    
            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
    
                wp_send_json(['Sucees' => false]);
            } else {
                // Security: Safe unserialize with error checking
                $body = wp_remote_retrieve_body( $response );
                $theme_info = @unserialize( $body );
                
                // Security: Validate unserialize was successful and is an object
                if ( false === $theme_info || ! is_object( $theme_info ) ) {
                    wp_send_json_error( array( 'content' => __( 'Invalid theme information format.', 'nexter-extension' ) ) );
                }
                
                // Security: Verify object has required properties
                if ( ! isset( $theme_info->name ) || ! isset( $theme_info->download_link ) ) {
                    wp_send_json_error( array( 'content' => __( 'Invalid theme information structure.', 'nexter-extension' ) ) );
                }
                
                $theme_name = sanitize_text_field( $theme_info->name );
                $theme_zip_url = $theme_info->download_link;
                global $wp_filesystem;
                // Install the theme
                $theme = wp_remote_get( $theme_zip_url );
                if ( ! function_exists( 'WP_Filesystem' ) ) {
                    require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/file.php' );
                }
    
                WP_Filesystem();
    
                $active_theme = wp_get_theme();
                $theme_name = $active_theme->get('Name');
                
                $wp_filesystem->put_contents( WP_CONTENT_DIR.'/themes/'.$theme_slug . '.zip', $theme['body'] );
                $zip = new ZipArchive();
                if ( $zip->open( WP_CONTENT_DIR . '/themes/' . $theme_slug . '.zip' ) === true ) {
                    $zip->extractTo( WP_CONTENT_DIR . '/themes/' );
                    $zip->close();
                }
                $wp_filesystem->delete( WP_CONTENT_DIR . '/themes/' . $theme_slug . '.zip' );
                
    
                wp_send_json(['Sucees' => true]);
            }
            exit;
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
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' ); 
    
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
            }
    
            $plu_slug = ( isset( $_POST['slug'] ) && !empty( $_POST['slug'] ) ) ? sanitize_text_field( wp_unslash($_POST['slug']) ) : '';

            $phpFileName = $plu_slug;
            if(!empty($plu_slug) && $plu_slug == 'the-plus-addons-for-elementor-page-builder'){
                $phpFileName = 'theplus_elementor_addon';
            }
    
            $installed_plugins = get_plugins();
    
            include_once ABSPATH . 'wp-admin/includes/file.php';
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
            include_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
    
            $result   = array();
            $response = wp_remote_post(
                'http://api.wordpress.org/plugins/info/1.0/',
                array(
                    'body' => array(
                        'action'  => 'plugin_information',
                        'request' => serialize(
                            (object) array(
                                'slug'   => $plu_slug,
                                'fields' => array(
                                    'version' => false,
                                ),
                            )
                        ),
                    ),
                )
            );
    
            // Security: Safe unserialize with error checking
            $body = wp_remote_retrieve_body( $response );
            $plugin_info = @unserialize( $body );
            
            // Security: Validate unserialize was successful and is an object
            if ( false === $plugin_info || ! is_object( $plugin_info ) ) {
                wp_send_json_error( array( 'content' => __( 'Failed to retrieve plugin information or invalid format.', 'nexter-extension' ) ) );
            }
            
            // Security: Verify object has required properties
            if ( ! isset( $plugin_info->name ) || ! isset( $plugin_info->download_link ) ) {
                wp_send_json_error( array( 'content' => __( 'Invalid plugin information structure.', 'nexter-extension' ) ) );
            }
    
            $skin     = new \Automatic_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader( $skin );
    
            $plugin_basename = ''.$plu_slug.'/'.$phpFileName.'.php';
            
            
            if ( ! isset( $installed_plugins[ $plugin_basename ] ) && empty( $installed_plugins[ $plugin_basename ] ) ) {
                $installed = $upgrader->install( $plugin_info->download_link );
    
                $activation_result = activate_plugin( $plugin_basename );
                if(!empty($plu_slug) && $plu_slug == 'wdesignkit'){
                    $this->wdk_installed_settings_enable();
                }
                $success = null === $activation_result;
                wp_send_json(['Sucees' => true]);
    
            } elseif ( isset( $installed_plugins[ $plugin_basename ] ) ) {
                $activation_result = activate_plugin( $plugin_basename );
                if(!empty($plu_slug) && $plu_slug == 'wdesignkit'){
                    $this->wdk_installed_settings_enable();
                }
                $success = null === $activation_result;
                wp_send_json(['Sucees' => true]);
            }
        }

        public function wdk_installed_settings_enable(){
            if( defined( 'TPGB_VERSION' ) ){
                $settings = array('gutenberg_builder' => true,'gutenberg_template' => true);
                $builder = array( 'elementor' );
                do_action( 'wdkit_active_settings', $settings, $builder );
            }else if( defined('ELEMENTOR_VERSION') ){
                $settings = array('elementor_builder' => true,'elementor_template' => true);
                $builder = array( 'nexter-blocks');
                do_action( 'wdkit_active_settings', $settings, $builder );
            }
        }
        
        /**
         * Get Users Roles
         */
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

        /**
         * Get Type and SubType for Edit Condition
         */
        public function nexter_ext_edit_condition_data_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' ); 
    
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
            }

            $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : '';
            $selectType = $selectSType = '';
            if(!empty($post_id)){
                $old_layout = get_post_meta($post_id, 'nxt-hooks-layout', true);
                if(!empty($old_layout)){
                    $selectType = $old_layout;
                    if($old_layout == 'sections'){
                        $selectSType = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                    }else if($old_layout == 'pages'){
                        $selectSType = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
                    }else if($old_layout == 'code_snippet'){
                        $selectSType = get_post_meta($post_id, 'nxt-hooks-layout-code-snippet', true);
                    }else{
                        $selectSType = __('None', 'nexter-extension');
                    }
                }else{
                    $layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                    if( $layout === 'header' || $layout === 'footer' || $layout === 'breadcrumb' || $layout === 'hooks' ) {
                        $selectType = 'sections';
                    }else if( $layout === 'singular' || $layout === 'archives' || $layout === 'page-404'){
                        $selectType = 'pages';
                    }
                    $selectSType = $layout;
                }
            }
            wp_send_json (['type'=> $selectType, 'subtype'=> $selectSType]);
        }

        /*
         * Export Customizer Data Theme Options
         * @since 4.3.0
         * */
        public function nxt_customizer_export_data(){
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( ! isset( $_POST['nexter_export_cust_nonce'] ) ||
                ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexter_export_cust_nonce'] ) ), 'nexter_admin_nonce' ) ) {
                return;
            }

            if ( empty( $_POST['nxt_customizer_export_action'] ) || $_POST['nxt_customizer_export_action'] !== 'nxt_export_cust' ) {
                return;
            }

            // Get Customizer options
            $customizer_options = class_exists('Nexter_Customizer_Options') ? Nexter_Customizer_Options::get_options() : [];

            $customizer_options = apply_filters( 'nexter_customizer_export_data', $customizer_options );
            nocache_headers();
            
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=nexter-customizer-export-' . gmdate( 'm-d-Y' ) . '.json' );
            header( 'Expires: 0' );
            echo wp_json_encode( $customizer_options );
            die();
        }

        /*
         * Import Customizer Data Theme Options
         * @since 4.3.0
         * */
        public function nxt_customizer_import_data(){
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(esc_html__( 'Not a permission', 'nexter-extension' ));
            }

            if ( ! isset( $_POST['nexter_import_cust_nonce'] ) ||
                ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexter_import_cust_nonce'] ) ), 'nexter_admin_nonce' ) ) {
                wp_send_json_error( esc_html__( 'Security check failed', 'nexter-extension' ) );
            }

            // Check file upload
            if (! isset( $_FILES['nxt_import_file'] ) ||
                ! isset( $_FILES['nxt_import_file']['error'] ) ||
                $_FILES['nxt_import_file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(esc_html__( 'File Import failed', 'nexter-extension' ));
            }
            
            $filename = isset( $_FILES['nxt_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['nxt_import_file']['name'] ) ) : '';

            if ( empty( $filename ) ) {
                wp_send_json_error(esc_html__( 'File Import failed', 'nexter-extension' ));
            }
            
            $file_extension  = explode( '.', $filename );
            $extension = end( $file_extension );

            if ( $extension !== 'json' ) {
                wp_send_json_error( esc_html__( 'Valid .json file extension', 'nexter-extension' ) );
            }

            $nxt_import_file = isset( $_FILES['nxt_import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['nxt_import_file']['tmp_name'] ) ) : '';

            if ( empty( $nxt_import_file ) ) {
                wp_send_json_error( esc_html__( 'Please upload a file', 'nexter-extension' ) );
            }

            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            $get_contants = $wp_filesystem->get_contents( $nxt_import_file );
            $customizer_options      = json_decode( $get_contants, 1 );

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(esc_html__( 'Invalid JSON format', 'nexter-extension' ));
            }

            if ( !empty( $customizer_options ) && defined('NXT_VERSION')) {
                update_option( 'nxt-theme-options', $customizer_options );
                wp_send_json_success(esc_html__( 'Customizer settings imported successfully', 'nexter-extension' ));
            }

            wp_send_json_error(esc_html__( 'No valid settings found in the file', 'nexter-extension' ));
        }

        
         /**
         * Enable Code Snippet Setting via WDesignKit Hook
         * @since 4.3.4
         */
        public function nexter_enable_code_snippet_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' ); 
    
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
            }

            // Check if code snippet is already enabled
            $wkit_settings_panel = get_option( 'wkit_settings_panel', array() );
            
            if ( ! empty( $wkit_settings_panel ) && isset( $wkit_settings_panel['code_snippet'] ) && $wkit_settings_panel['code_snippet'] === true ) {
                // Already enabled, no need to call the hook
                wp_send_json_success( array( 
                    'message' => __( 'Code snippet setting is already enabled.', 'nexter-extension' ),
                    'already_enabled' => true
                ) );
            }

            // Enable code snippet setting only if not already enabled
            $settings = array(
                'code_snippet' => true,
            );

            $builder = array();

            // Call the WDesignKit hook to enable code snippet
            do_action( 'wdkit_active_settings', $settings, $builder );

            wp_send_json_success( array( 
                'message' => __( 'Code snippet setting enabled successfully.', 'nexter-extension' ),
                'newly_enabled' => true
            ) );
        }

        /**
         * Get Replace URL Tables via AJAX
         * @since 4.3.4
         */
        public function nexter_get_replace_url_tables_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' ); 
    
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
            }

            $tables = self::nexter_replace_url_tables_and_size();
            
            wp_send_json_success( array( 
                'tables' => $tables
            ) );
        }

        /*
         * Wdesignkit Templates load
         */
        public function nexter_temp_api_call() {

            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ) );
                wp_die();
            }

            $method  = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'POST';
            // Security: Validate HTTP method
            if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
                wp_send_json_error( array( 'content' => __( 'Invalid HTTP method.', 'nexter-extension' ) ) );
                wp_die();
            }
            
            $api_url = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';
            // Security: SSRF Protection - Whitelist allowed domains
            $allowed_domains = array( 'api.wdesignkit.com', 'nexterwp.com', 'api.wordpress.org' );
            $parsed_url = wp_parse_url( $api_url );
            if ( empty( $parsed_url['host'] ) || ! in_array( $parsed_url['host'], $allowed_domains, true ) ) {
                wp_send_json_error( array( 'content' => __( 'Unauthorized API endpoint.', 'nexter-extension' ) ) );
                wp_die();
            }
            
            // Security: Safe JSON decode
            $body_raw = isset( $_POST['url_body'] ) ? wp_unslash( $_POST['url_body'] ) : '';
            $body = $this->safe_json_decode( $body_raw, true );
            if ( ! is_array( $body ) ) {
                $body = array();
            }

            $args = array(
                'method'  => $method,
                'timeout' => 60,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            );

            if ( ! empty( $body ) ) {
                $args['body'] = wp_json_encode( $body );
            }

            // Make the request based on method
            if ( 'POST' === $method ) {
                $response = wp_remote_post( $api_url, $args );
            } elseif ( 'GET' === $method ) {
                $response = wp_remote_get( $api_url, $args );
            } else {
                wp_send_json_error( array(
                    'HTTP_CODE' => 400,
                    'error' => 'Invalid HTTP method'
                ) );
                wp_die();
            }

            $statuscode = wp_remote_retrieve_response_code( $response );
            $getdataone = wp_remote_retrieve_body( $response );
            
            // Security: Safe JSON decode for response
            $response_data = $this->safe_json_decode( $getdataone, true );
            if ( ! is_array( $response_data ) ) {
                $response_data = array();
            }

            $statuscode_array = array( 'HTTP_CODE' => $statuscode );

            // Merge status code with response data
            if ( is_array( $response_data ) ) {
                $final = array_merge( $statuscode_array, $response_data );
            } else {
                $final = array_merge( $statuscode_array, array( 'data' => $response_data ) );
            }

            wp_send_json( $final );
            wp_die();
        }
    }
}

$nexter_settings_panel = Nexter_Ext_Panel_Settings::get_instance();
$nexter_settings_panel->hooks();