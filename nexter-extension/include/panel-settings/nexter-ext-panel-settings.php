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
         * Option key, and option page slug
         */
        private $key = 'nexter_settings_opts';
        
        /**
         * Array of recaptch version
         * @var string
         */

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
                }

                // Add Extra attr to script tag
                add_filter( 'script_loader_tag', [ $this,'nxt_async_attribute' ], 10, 2 );
                add_action('admin_footer', array($this, 'nxt_link_in_new_tab'));
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
            $get_option = get_option( 'nxt_ext_menu_notice_count' );
            if ( get_option( 'nxt_ext_menu_notice_count' ) < NEXTER_EXT_NOTICE_FLAG ) {
                update_option( 'nxt_ext_menu_notice_count', NEXTER_EXT_NOTICE_FLAG, false );
            }
        }

        public function nxt_link_in_new_tab(){
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    <?php if( $this->nxt_ext_notice_should_show() ) { ?>
                        var menuItem = document.querySelector('.toplevel_page_nexter_welcome.menu-top');
                        if (menuItem) {
                            menuItem.classList.add('nxt-ext-admin-notice-active');
                        }
                    <?php } ?>
                });
            </script>
            <?php
        }

        /**
         * Condition to Check Notice Show
         * @since 4.2.0
         */
        public function nxt_ext_notice_should_show(){
            return ( get_option( 'nxt_ext_menu_notice_count' ) < NEXTER_EXT_NOTICE_FLAG );
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
            $cleanUserProfile = ( isset( $_POST['cleanUserProfile'] ) ) ? (wp_unslash( $_POST['cleanUserProfile'] ) ) : '';
            $recapData = ( isset( $_POST['recapData'] ) ) ? wp_unslash( $_POST['recapData'] ) : '';
            $wpDisableSet = ( isset( $_POST['wpDisableSet'] ) ) ? wp_unslash( $_POST['wpDisableSet'] ) : '';
            $svgUploadRoles = ( isset( $_POST['svgUploadRoles'] ) ) ? wp_unslash( $_POST['svgUploadRoles'] ) : '';
           
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
            $redirect_404 = ( isset( $_POST['redirect_404'] ) ) ? sanitize_text_field( wp_unslash( $_POST['redirect_404'] ) )  : '';
            $wpWLSet = ( isset( $_POST['wpWLSet'] ) ) ? wp_unslash( $_POST['wpWLSet'] ) : '';
            $securData = ( isset( $_POST['securData'] ) ) ? wp_unslash( $_POST['securData'] ) : '';
            $nxtctmLogin = ( isset( $_POST['nxtctmLogin'] ) ) ? wp_unslash( $_POST['nxtctmLogin'] ) : '';
            $image_size = ( isset( $_POST['image_size'] ) ) ? wp_unslash( $_POST['image_size'] ) : '';
            $new_custom_image_size = ( isset( $_POST['new_custom_size'] ) ) ? wp_unslash( $_POST['new_custom_size'] ) : '';
            $new_custom_image_size = (array)json_decode($new_custom_image_size);
            $ele_icons = ( isset( $_POST['ele_icons'] ) ) ? wp_unslash( $_POST['ele_icons'] ) : '';
            $disabled_image_sizes = get_option('nexter_disabled_images');
            $editoropt = ( isset( $_POST['editoropt'] ) ) ? sanitize_text_field(wp_unslash( $_POST['editoropt'] ) ) : '';

            if(!empty($ext) && $ext==='disabled-image-sizes'){
                //Convert $image_size to Array
                $image_size = explode(",",$image_size);
                update_option('nexter_disabled_images',$image_size);
                wp_send_json_success();
            }else if(!empty($ext) && $ext==='disable-elementor-icons'){
                $ele_icons = explode(",",$ele_icons);
                update_option('nexter_elementor_icons',$ele_icons);
                wp_send_json_success();
            }else if(!empty($ext) && $ext ==='nexter-custom-image-sizes'){
                $all_custom_image_sized = get_option('nexter_custom_image_sizes',array());
                if(isset($all_custom_image_sized[$new_custom_image_size['name']])){
                    wp_send_json_error();
                }
                $all_custom_image_sized[$new_custom_image_size['name']] = $new_custom_image_size;
                $all_custom_image_sized2 = get_option('nexter_custom_image_sizes',array());
                if(update_option('nexter_custom_image_sizes', $all_custom_image_sized)){
                    wp_send_json_success(
                        array(
                            'content'	=> $new_custom_image_size,
                        )
                    );
                } else{
                    wp_send_json_error();
                }
                //Todo Logic to store Newly created Image Size
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
                    $get_option[ $ext ]['values'] = json_decode($fonts, true);
                    update_option( $option_page, $get_option );
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
                    $get_option[ $ext ]['values'] = json_decode($cleanUserProfile);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='google-recaptcha' && !empty($recapData)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($recapData, true);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='wp-login-white-label' && !empty($wpLoginWL)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $wpLoginDE = json_decode($wpLoginWL, true);
                    $get_option[ $ext ]['values'] = $wpLoginDE;
                    if(class_exists('Nexter_Ext_Wp_Login_White_Label')){
                        $get_option[ $ext ]['css'] = Nexter_Ext_Wp_Login_White_Label::nxtWLCSSGenerate($wpLoginDE);
                    }
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && ( $ext==='advance-performance' && !empty($performance) ) || ($ext==='disable-comments' && !empty($commdata) ) || ($ext==='google-fonts' && !empty($googlefonts) ) || ($ext==='post-revision-control' && !empty($revisionControl) ) || ($ext==='heartbeat-control' && !empty($heartbeatOpt) ) ){
                $advanceData =  json_decode($performance);
                $disableComm = (array) json_decode($commdata);

                $googlefonts = json_decode($googlefonts, true);

                if( False === $getperoption || empty($getperoption) ){	
                    if(!empty($advanceData) ){
                        update_option($perforoption,$advanceData);
                    }else{
                        update_option($perforoption,$disableComm);
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
                            $old_comment['disable_comments'] = (isset($get_option['disable_comments']) ? $get_option['disable_comments'] : '');
                            $old_comment['disble_custom_post_comments'] = (isset($get_option['disble_custom_post_comments']) ? $get_option['disble_custom_post_comments'] : [] );
                            $old_comment['nexter_google_fonts'] = (isset($get_option['nexter_google_fonts']) ? $get_option['nexter_google_fonts'] : [] );

                            $new = array_merge($old_comment,$advanceData);
                        }else if($ext==='disable-comments'){
                            if(isset($get_option['disable_comments'])){
                                unset($get_option['disable_comments']);
                            }
                            if(isset($get_option['disable_comments'])){
                                unset($get_option['disble_custom_post_comments']);
                            }
                            $new = array_merge($get_option,$disableComm);
                        }else if($ext==='google-fonts'){
                            if(isset($get_option['nexter_google_fonts'])){
                                unset($get_option['nexter_google_fonts']);
                            }
                            $new = array_merge($get_option,$googlefonts);
                        }else if($ext==='heartbeat-control' && !empty($heartbeatOpt)){
                            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                                $get_option[ $ext ]['values'] = json_decode($heartbeatOpt);
                                $new = $get_option;
                            }
                        }else if($ext==='post-revision-control' && !empty($revisionControl)){
                            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                                $get_option[ $ext ]['values'] = json_decode($revisionControl);
                                $new = $get_option;
                            }
                        }
                        update_option( $perforoption, $new );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && ( $ext==='advance-security' && !empty($securData) ) || ( $ext==='custom-login' && !empty($nxtctmLogin) ) || ( $ext==='wp-right-click-disable' && !empty($wpDisableSet) ) || ($ext==='email-login-notification' && !empty($wpEmailNotiSet)) || ($ext==='2-fac-authentication' ) || ($ext==='captcha-security' && !empty($captchaSetting)) || ($ext==='svg-upload' && !empty($svgUploadRoles))){
                
                $securData = (array) json_decode($securData);
                $nxtctmLogin = (array) json_decode($nxtctmLogin);
                $disrightclick = (array) json_decode($wpDisableSet,true);
                $svg_upload_roles = (array) json_decode($svgUploadRoles,true);
                $emailNotiSet = (array) json_decode($wpEmailNotiSet);
                $captchaSetting = (array) json_decode($captchaSetting);
                
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
                    }

                }else{
                    
                    $get_option = get_option($secr_opt);
                    
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
                            if(false !== array_search('iframe_security', $get_option)){
                                unset($get_option[array_search('iframe_security' , $get_option)]);
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
                            $get_option = self::nexter_ext_object_convert_to_array($get_option);
                            $securData = self::nexter_ext_object_convert_to_array($securData);
                            $newArr = array_merge($get_option,$securData);

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
                            $newArr = array_merge($get_option,$nxtctmLogin);
                        }else if( $ext==='wp-right-click-disable' ){
                            if(isset($get_option[ $ext ]['values']) && !empty($get_option[ $ext ]['values']) ){
                                unset($get_option[ $ext ]['values']);
                            }
                            if(isset($get_option[ $ext ]['css']) && !empty($get_option[ $ext ]['css']) ){
                                unset($get_option[ $ext ]['css']);
                            }
                            $newdata = [];
                            if(class_exists('Nexter_Ext_Right_Click_Disable')){
                                $newdata[ $ext ]['values'] = $disrightclick;
                                $newdata[ $ext ]['css'] = Nexter_Ext_Right_Click_Disable::nxtrClickCSSGenerate($disrightclick);
                            }
                            $newArr = array_merge($get_option,$newdata);
                        }else if($ext==='email-login-notification'){
                            $newdata[ $ext ]['values'] =  $emailNotiSet;
                            $newdata[ $ext ]['switch'] =  true;
                            $newArr = array_merge($get_option,$newdata);
                        }else if($ext==='svg-upload'){
                            $newdata[ $ext ]['values'] =  $svg_upload_roles;
                            $newdata[ $ext ]['switch'] =  true;
                            $newArr = array_merge($get_option,$newdata);
                        }else if($ext === '2-fac-authentication'){
	                        $allowed_2fa_roles = ( isset( $_POST['allowed_2fa_roles'] ) ) ? wp_unslash( $_POST['allowed_2fa_roles'] ) : '';
	                        $allowed_2fa_roles = json_decode($allowed_2fa_roles, true);
	                        $email_customisation = array();
                            $email_customisation['subject'] = ( isset( $_POST["customEmailSubject"] ) ) ? sanitize_text_field( wp_unslash( $_POST['customEmailSubject'] ) ) : '';
                            $email_customisation['body'] = ( isset( $_POST["customEmailBody"] ) ) ? sanitize_textarea_field( ( $_POST['customEmailBody'] ) ) : '';
                            $temp_roles[$ext]['values']['allowed_2fa_roles'] = $allowed_2fa_roles;
                            $temp_roles[$ext]['values']['email_customisations'] = $email_customisation;
                            $temp_roles[$ext]['switch'] = true;
                            $newArr = array_merge($get_option,$temp_roles);
                        }else if($ext==='captcha-security'){
                            $newdata[ $ext ]['values'] = $captchaSetting;
                            $newdata[ $ext ]['switch'] = true;
                            $newArr = array_merge($get_option,$newdata);
                        }
                        update_option( $secr_opt, $newArr );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='wp-duplicate-post' && !empty($wpDupPostSet)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = (array) json_decode($wpDupPostSet);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='content-post-order' && !empty($post_types)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = (array) json_decode($post_types);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='disable-gutenberg' && !empty($disable_gutenberg_posts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($disable_gutenberg_posts);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='public-preview-drafts' && !empty($preview_drafts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ])){
                    $get_option[ $ext ]['values'] = json_decode($preview_drafts);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && $ext==='redirect-404-page' && defined( 'NXT_PRO_EXT' ) ){
                if( !empty( $get_option ) && isset($get_option[ $ext ])){
                    $get_option[ $ext ]['values'] = !empty($redirect_404) ? $redirect_404 : '';
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='white-label' && !empty($wpWLSet)){
                $whiteLabelData =  (array) json_decode($wpWLSet);
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
                if( !empty( $get_option ) ){
                    $get_option[ $ext ]['values'] = (array) json_decode($editoropt);
                    update_option( $option_page, $get_option );
                }
                wp_send_json_success();
            }

            wp_send_json_error();
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
            if(defined('NXT_PRO_EXT')){
                $options = get_option( 'nexter_white_label' );
                $this->setting_name = (!empty($options['brand_name'])) ? $options['brand_name'] : esc_html__('Nexter Extension', 'nexter-extension');
                $this->setting_logo = (!empty($options['theme_logo'])) ? $options['theme_logo'] : esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter_logo.svg');
            }else{
                $this->setting_name = esc_html__('Nexter Extension', 'nexter-extension');
                $this->setting_logo = esc_url(NEXTER_EXT_URL . 'dashboard/assets/svg/navbox/nexter_logo.svg');
            }
        }
        
        /*Load Panel Settings Style & Scripts*/
        public function enqueue_scripts_admin( $hook_suffix ){
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

            $user = wp_get_current_user();

            $enabled_is = get_option('nexter_disabled_images',array());
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

            if ( isset( $pluginslist[ 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' ] ) && !empty( $pluginslist[ 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' ] ) ) {
                if( is_plugin_active('the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php') ){
                    $nxtPlugin = true;
                }
            }

            if ( isset( $pluginslist[ 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' ] ) && !empty( $pluginslist[ 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' ] ) ) {
                if( is_plugin_active('the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php') ){
                    $tpaePlugin = true;
                }
            }

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
                }
            }

            if ( isset( $pluginslist[ 'uichemy/uichemy.php' ] ) && !empty( $pluginslist[ 'uichemy/uichemy.php' ] ) ) {
                if( is_plugin_active('uichemy/uichemy.php') ){
                    $uichemyPlugin = true;
                }
            }
            
            if(! did_action('wp_enqueue_media')){
				wp_enqueue_media();
			}
            
            wp_enqueue_style( 'nxt-panel-settings', NEXTER_EXT_URL .'assets/css/admin/nexter-admin'. $minified .'.css', array(), NEXTER_EXT_VER );
            
            // if ( 'post-new.php' != $hook_suffix && 'post.php' != $hook_suffix && NXT_BUILD_POST == get_post_type()) {
            if (( 'post-new.php' != $hook_suffix && 'post.php' != $hook_suffix && 'edit.php' == $hook_suffix ) && (( isset( $_GET['post_type'] ) && 'nxt_builder' == $_GET['post_type'] ) || ( defined( 'NXT_BUILD_POST' ) && NXT_BUILD_POST == get_post_type() ))){
                wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2'. $minified .'.css', array(), NEXTER_EXT_VER );
			    wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2'. $minified .'.js', array(), NEXTER_EXT_VER, false );
            }
            if ( ! is_customize_preview() ) {
				wp_enqueue_style( 'wp-color-picker' );
			}   
            
            
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                return;
            }

            // Dash Board Css Js Enqueue
            wp_enqueue_style( 'nexter-welcome-style', NEXTER_EXT_URL . 'dashboard/build/index.css', array(), NEXTER_EXT_VER, 'all' );

			wp_enqueue_script( 'nexter-ext-dashscript', NEXTER_EXT_URL . 'dashboard/build/index.js', array( 'react', 'react-dom','wp-i18n', 'wp-dom-ready', 'wp-element','wp-components', 'wp-block-editor', 'wp-editor' ), NEXTER_EXT_VER, true );

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
                'nxtTables' => self::nexter_replace_url_tables_and_size(),
                'post_list' => self::nexter_ext_get_post_type_list(),
                'wpVersion' => get_bloginfo('version'),
                'pluginVer' => NEXTER_EXT_VER,
                'pluginpath' => NEXTER_EXT_URL,
                'nexterBlock' => $nxtPlugin,
                'tpaeAddon' => $tpaePlugin,
                'wdkPlugin' => $wdkPlugin,
                'wdkVersion' => isset($wdkVersion) ? $wdkVersion : '1.0.0',
                'uichemy' => $uichemyPlugin,
                'rollbacVer' => NxtExt_Rollback::get_rollback_versions(),
                'rollbackUrl' => $rollback_url,
                'whiteLabel' => get_option('nexter_white_label'),
                'keyActmsg' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_ext_pro_activate_msg() : '',
                'nxtactivateKey' => get_option('nexter_activate'),
                'activePlan' => (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) ? Nexter_Pro_Ext_Activate::nexter_get_activate_plan() : '',
                'roles' => self::nexter_ext_get_users_roles(),
                'showSidebar' => $this->nxt_ext_notice_should_show(),
            ];

			wp_localize_script(
				'nexter-ext-dashscript',
				'nxtext_ajax_object',
				array(
                    'adminUrl' => admin_url(),
					'nxtex_url' => NEXTER_EXT_URL . 'dashboard/',
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
                    'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                    'pro' => defined('NXT_PRO_EXT_VER'),
                    'dashData' => $dashData
				)
			);

            $nexter_admin_localize = array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                'nexter_path' => NEXTER_EXT_URL.'assets/',
                'is_pro' => (defined('NXT_PRO_EXT')) ? true : false,
            );
            wp_localize_script( 'nexter-ext-dashscript', 'nexter_admin_config', $nexter_admin_localize );

        }

        /* Settings Admin Menu */
        public function nxt_add_menu_page(){

            add_menu_page( $this->setting_name, $this->setting_name, 'manage_options', 'nexter_welcome', array( $this, 'nexter_ext_dashboard' ), 'dashicons-nxt-builder-groups', 58  );

            add_submenu_page(
                'nexter_welcome',
                __( 'Dashboard', 'nexter-extension' ),
                __( 'Dashboard', 'nexter-extension' ),
                'manage_options',
                'nexter_welcome'
            );

            //if(defined('NXT_VERSION') || defined('HELLO_ELEMENTOR_VERSION') || defined('ASTRA_THEME_VERSION') || defined('GENERATE_VERSION') || defined('OCEANWP_THEME_VERSION') || defined('KADENCE_VERSION') || function_exists('blocksy_get_wp_theme') || defined('NEVE_VERSION')){
                add_submenu_page(
                    'nexter_welcome',
                    __( 'Theme Builder', 'nexter-extension' ),
                    __( 'Theme Builder', 'nexter-extension' ),
                    'manage_options',
                    'edit.php?post_type=nxt_builder'
                );
                
            //}
            add_submenu_page(
                'nexter_welcome',
                __( 'Code Snippets', 'nexter-extension' ),
                __( 'Code Snippets', 'nexter-extension' ),
                'manage_options',
                'nxt_code_snippets',
                array($this, 'nexter_code_snippet_display'),
            );
        }

        /**
		 * Code Snippet Render html
		 * @since  1.0.0
		 */
		public function nexter_code_snippet_display() {
			echo '<div id="nexter-code-snippets"></div>';
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

        public static function nxt_extra_active_deactive( $data = '', $switch = ''){
            
            if( empty( $data ) && empty($switch) ){
                wp_send_json_error(
                    array( 
                        'content' => __( 'server not found..', 'nexter-extension' ),
                    )
                );
            }else if( !empty( $data ) && !empty( $switch ) ){

                $option_page = '';
                if($data=='email-login-notification' || $data=='2-fac-authentication' || $data == 'captcha-security' ||  $data == 'svg-upload'){
                    $option_page = 'nexter_site_security';
                }else if($data == 'heartbeat-control' || $data == 'post-revision-control'){
                    $option_page = 'nexter_site_performance';
                }else{
                    $option_page = 'nexter_extra_ext_options';
                }
                if ( FALSE === get_option($option_page) ){
                    $default_value = [ 
                        $data => [
                            'switch' => ($switch=='active') ? true : false,
                        ],
                    ];
                    add_option($option_page,$default_value);
                }else{
                    $get_option = get_option($option_page);
                    if( !empty( $get_option ) ){
                        $get_option[ $data ]['switch'] = ($switch=='active') ? true : false;
                        update_option( $option_page, $get_option );
                    }
                }
                wp_send_json_success(
                    array(
                        'content'	=> ($switch=='active') ? __( 'Activated', 'nexter-extension' ) : __( 'DeActivate', 'nexter-extension' ),
                    )
                );
            }
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
                $theme_info = unserialize( $response['body'] );
                $theme_name = $theme_info->name;
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
    
            $plu_slug = ( isset( $_POST['slug'] ) && !empty( $_POST['slug'] ) ) ? sanitize_text_field( $_POST['slug'] ) : '';

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
    
            $plugin_info = unserialize( wp_remote_retrieve_body( $response ) );
    
            if ( ! $plugin_info ) {
                wp_send_json_error( array( 'content' => __( 'Failed to retrieve plugin information.', 'nexter-extension' ) ) );
            }
    
            $skin     = new \Automatic_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader( $skin );
    
            $plugin_basename = ''.$plu_slug.'/'.$phpFileName.'.php';
            
            if ( ! isset( $installed_plugins[ $plugin_basename ] ) && empty( $installed_plugins[ $plugin_basename ] ) ) {
                $installed = $upgrader->install( $plugin_info->download_link );
    
                $activation_result = activate_plugin( $plugin_basename );
    
                $success = null === $activation_result;
                wp_send_json(['Sucees' => true]);
    
            } elseif ( isset( $installed_plugins[ $plugin_basename ] ) ) {
                $activation_result = activate_plugin( $plugin_basename );
    
                $success = null === $activation_result;
                wp_send_json(['Sucees' => true]);
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
                        $selectType = __('sections', 'nexter-extension');
                    }else if( $layout === 'singular' || $layout === 'archives' || $layout === 'page-404'){
                        $selectType = __('pages', 'nexter-extension');
                    }
                    $selectSType = $layout;
                }
            }
            wp_send_json (['type'=> $selectType, 'subtype'=> $selectSType]);
        }
    }
}

$nexter_settings_panel = Nexter_Ext_Panel_Settings::get_instance();
$nexter_settings_panel->hooks();