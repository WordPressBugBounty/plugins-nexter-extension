<?php
/**
 * Panel AJAX Router
 *
 * Handles all admin AJAX requests for the Nexter extension panel:
 * settings save, extension activate/deactivate, theme/plugin install,
 * customizer import/export, and API calls.
 *
 * Extracted from Nexter_Ext_Panel_Settings.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Panel_Ajax_Router {

    /**
     * Parent panel instance for shared utility access.
     *
     * @var Nexter_Ext_Panel_Settings
     */
    private $parent;

    /**
     * Constructor.
     *
     * @param Nexter_Ext_Panel_Settings $parent Parent instance.
     */
    public function __construct( $parent ) {
        $this->parent = $parent;
    }

    /**
     * Register all AJAX hooks.
     */
    public function register_hooks() {
        add_action( 'wp_ajax_nexter_extra_ext_active', [ $this, 'nexter_extra_ext_active_ajax'] );
        add_action( 'wp_ajax_nexter_extra_ext_deactivate', [ $this, 'nexter_extra_ext_deactivate_ajax'] );
        add_action( 'wp_ajax_nexter_ext_save_data', [ $this, 'nexter_ext_save_data_ajax'] );
        add_action( 'wp_ajax_nexter_ext_theme_install', [ $this, 'nexter_ext_theme_install_ajax'] );
        add_action( 'wp_ajax_nexter_ext_plugin_install', [ $this, 'nexter_ext_plugin_install_ajax'] );
        add_action( 'wp_ajax_nexter_ext_edit_condition_data', [ $this, 'nexter_ext_edit_condition_data_ajax'] );
        add_action( 'wp_ajax_nexter_enable_code_snippet', [ $this, 'nexter_enable_code_snippet_ajax'] );
        add_action( 'wp_ajax_nexter_get_replace_url_tables', [ $this, 'nexter_get_replace_url_tables_ajax'] );
        add_action( 'wp_ajax_nexter_temp_api_call', [ $this, 'nexter_temp_api_call' ] );
        add_action( 'admin_init', [ $this, 'nxt_customizer_export_data' ] );
        add_action('wp_ajax_nxt_import_customizer_data', [ $this, 'nxt_customizer_import_data' ]);
    }

    /**
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
        $blockAiCrawlers = ( isset( $_POST['blockAiCrawlers'] ) ) ? wp_unslash( $_POST['blockAiCrawlers'] ) : '';

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
        $new_custom_image_size = $this->parent->safe_json_decode( $new_custom_image_size, true );
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
                $decoded_fonts = $this->parent->safe_json_decode( $fonts, true );
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
                $decoded_cleanUserProfile = $this->parent->safe_json_decode( $cleanUserProfile, false );
                if ( $decoded_cleanUserProfile !== null ) {
                    $get_option[ $ext ]['values'] = $decoded_cleanUserProfile;
                    update_option( $option_page, $get_option );
                }
            }
            wp_send_json_success();
        }else if(!empty( $ext ) && $ext==='elementor-adfree' && !empty($elementorAdFree)){
            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                // Security: Safe JSON decode with validation
                $decoded_elementorAdFree = $this->parent->safe_json_decode( $elementorAdFree, false );
                if ( $decoded_elementorAdFree !== null ) {
                    $get_option[ $ext ]['values'] = $decoded_elementorAdFree;
                    update_option( $option_page, $get_option );
                }
            }
            wp_send_json_success();
        }else if( !empty( $ext ) && $ext==='google-recaptcha' && !empty($recapData)){
            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                // Security: Safe JSON decode with validation
                $decoded_recapData = $this->parent->safe_json_decode( $recapData, true );
                if ( is_array( $decoded_recapData ) ) {
                    $get_option[ $ext ]['values'] = $decoded_recapData;
                    update_option( $option_page, $get_option );
                }
            }
            wp_send_json_success();
        }else if(!empty( $ext ) && $ext==='wp-login-white-label' && !empty($wpLoginWL)){
            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                // Security: Safe JSON decode with validation
                $wpLoginDE = $this->parent->safe_json_decode( $wpLoginWL, true );
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
            $advanceData = $this->parent->safe_json_decode( $performance, false );
            $disableComm_raw = $this->parent->safe_json_decode( $commdata, false );
            $disableComm = is_array( $disableComm_raw ) ? $disableComm_raw : (array) $disableComm_raw;

            $googlefonts = $this->parent->safe_json_decode( $googlefonts, true );
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
                            $decoded_heartbeatOpt = $this->parent->safe_json_decode( $heartbeatOpt, false );
                            if ( $decoded_heartbeatOpt !== null ) {
                                $get_option[ $ext ]['values'] = $decoded_heartbeatOpt;
                                $new = $get_option;
                            }
                        }
                    }else if($ext==='post-revision-control' && !empty($revisionControl)){
                        if(  isset($get_option[ $ext ]) ){
                            // Security: Safe JSON decode
                            $decoded_revisionControl = $this->parent->safe_json_decode( $revisionControl, false );
                            if ( $decoded_revisionControl !== null ) {
                                $get_option[ $ext ]['values'] = $decoded_revisionControl;
                                $new = $get_option;
                            }
                        }
                    }else if($ext==='image-upload-optimize' && !empty($imageUploadOpt)){
                        if( isset($get_option[ $ext ]) ){
                            // Security: Safe JSON decode — all fields saved in extension option only
                            $decoded_imageUploadOpt = $this->parent->safe_json_decode( $imageUploadOpt, false );
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
            $securData_decoded = $this->parent->safe_json_decode( $securData, true );
            $securData = is_array( $securData_decoded ) ? $securData_decoded : array();

            $nxtctmLogin_decoded = $this->parent->safe_json_decode( $nxtctmLogin, true );
            $nxtctmLogin = is_array( $nxtctmLogin_decoded ) ? $nxtctmLogin_decoded : array();

            $disrightclick_decoded = $this->parent->safe_json_decode( $wpDisableSet, true );
            $disrightclick = is_array( $disrightclick_decoded ) ? $disrightclick_decoded : array();

            $svg_upload_roles_decoded = $this->parent->safe_json_decode( $svgUploadRoles, true );
            $svg_upload_roles = is_array( $svg_upload_roles_decoded ) ? $svg_upload_roles_decoded : array();

            $limit_login_attempt_decoded = $this->parent->safe_json_decode( $limitLogin, true );
            $limit_login_attempt = is_array( $limit_login_attempt_decoded ) ? $limit_login_attempt_decoded : array();

            $emailNotiSet_decoded = $this->parent->safe_json_decode( $wpEmailNotiSet, true );
            $emailNotiSet = is_array( $emailNotiSet_decoded ) ? $emailNotiSet_decoded : array();

            $captchaSetting_decoded = $this->parent->safe_json_decode( $captchaSetting, true );
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
                        $get_option = Nexter_Ext_Panel_Settings::nexter_ext_object_convert_to_array($get_option);

                        $securData = Nexter_Ext_Panel_Settings::nexter_ext_object_convert_to_array($securData);
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
                        $allowed_2fa_roles_decoded = $this->parent->safe_json_decode( $allowed_2fa_roles_raw, true );
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
                $decoded_wpDupPostSet = $this->parent->safe_json_decode( $wpDupPostSet, true );
                $get_option[ $ext ]['values'] = is_array( $decoded_wpDupPostSet ) ? $decoded_wpDupPostSet : array();
                update_option( $option_page, $get_option );
            }
            wp_send_json_success();
        }else if( !empty( $ext ) && $ext==='content-post-order' && !empty($post_types)){
            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                // Security: Safe JSON decode
                $decoded_post_types = $this->parent->safe_json_decode( $post_types, true );
                $get_option[ $ext ]['values'] = is_array( $decoded_post_types ) ? $decoded_post_types : array();
                update_option( $option_page, $get_option );
            }
            wp_send_json_success();
        }else if( !empty( $ext ) && $ext==='disable-gutenberg' && !empty($disable_gutenberg_posts)){
            if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                // Security: Safe JSON decode
                $decoded_disable_gutenberg_posts = $this->parent->safe_json_decode( $disable_gutenberg_posts, false );
                if ( $decoded_disable_gutenberg_posts !== null ) {
                    $get_option[ $ext ]['values'] = $decoded_disable_gutenberg_posts;
                    update_option( $option_page, $get_option );
                }
            }
            wp_send_json_success();
        }else if( !empty( $ext ) && $ext==='public-preview-drafts' && !empty($preview_drafts)){
            if( !empty( $get_option ) && isset($get_option[ $ext ])){
                // Security: Safe JSON decode
                $decoded_preview_drafts = $this->parent->safe_json_decode( $preview_drafts, false );
                if ( $decoded_preview_drafts !== null ) {
                    $get_option[ $ext ]['values'] = $decoded_preview_drafts;
                    update_option( $option_page, $get_option );
                }
            }
            wp_send_json_success();
        }else if( !empty( $ext ) && $ext==='taxonomy-order' && !empty($taxonomy_order)){
            if( !empty( $get_option ) && isset($get_option[ $ext ])){
                // Security: Safe JSON decode
                $decoded_taxonomy_order = $this->parent->safe_json_decode( $taxonomy_order, false );
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
            $whiteLabelData_decoded = $this->parent->safe_json_decode( $wpWLSet, true );
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
            $decoded_editoropt = $this->parent->safe_json_decode( $editoropt, true );
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

    /**
     * Helper for wdk plugin install.
     */
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

        $tables = Nexter_Ext_Panel_Settings::get_instance()->nexter_replace_url_tables_and_size();

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
        $body = $this->parent->safe_json_decode( $body_raw, true );
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
        $response_data = $this->parent->safe_json_decode( $getdataone, true );
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
