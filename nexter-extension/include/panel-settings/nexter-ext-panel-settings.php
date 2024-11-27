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

/**
 * White Label Content
 */
if( !function_exists('nexter_ext_white_label_content')){

    function nexter_ext_white_label_content(){
        echo '<div class="nxt-pro-note-title"><p style="margin-bottom:50px;">'.esc_html__('White Label our plugin and setup client\'s branding all around. You can update name, description, Icon and even hide the menu from dashboard. Get our pro version to have access of this feature.','nexter-ext').'</p></div>
            <div style="text-align:center;">
                <img style="width:55%;" src="'.esc_url(NEXTER_EXT_URL .'assets/images/white-lable.png').'" alt="'.esc_attr__('White Label','nexter-ext').'" class="panel-plus-white-lable" />
            </div>';
    }
    add_action('nexter_white_label_notice', 'nexter_ext_white_label_content' );
}

/**
 * Activate Content
 */
if( !function_exists('nexter_ext_activate_content')){
    function nexter_ext_activate_content(){
        echo '<div class="nxt-active-notice-pro">
                <img style="width:55%;" src="'.esc_url(NEXTER_EXT_URL .'assets/images/activate.png').'" alt="'.esc_attr__('Activate','nexter-ext').'" class="panel-plus-activate" />
                <div class="nxt-pro-active-msg">Have you already bought a <a href="'.esc_url('https://nexterwp.com/pricing/').'" target="_blank" rel="noreferrer noopener">PRO</a> version? Visit store <a href="'.esc_url('https://store.posimyth.com/download/').'" target="_blank" rel="noreferrer noopener">https://store.posimyth.com/download/</a> to download latest pro plugin</div>
            </div>';
    }
    add_action('nexter_activate_notice', 'nexter_ext_activate_content' );
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

        /**
         * Option key, and option page slug
         */
        private $key = 'nexter_settings_opts';
        
        /**
         * Array of recaptch version
         * @var string
         */

        protected $nxtrecpVer,$nxtrecpForm,$recaptheme;
        
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
            if( is_admin() ){
                $this->get_nxt_brand_name();
                add_action('admin_init', array( $this, 'init' ) );
                add_action('admin_menu', array( $this, 'nxt_add_menu_page' ));
                add_action('admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ));

                add_action( 'wp_ajax_nexter_import_activate_builder', [ $this, 'nexter_import_activate_builder_ajax'] );

                add_action('nexter_extra_options_render', array( $this,'nexter_extra_options_content' ));
                add_action('nexter_help_actions', array( $this, 'nexter_help_actions_render' ));

               

                if ( current_user_can( 'manage_options' ) ) {
                    add_action( 'wp_ajax_nexter_extra_ext_active', [ $this, 'nexter_extra_ext_active_ajax'] );
                    add_action( 'wp_ajax_nexter_extra_ext_deactivate', [ $this, 'nexter_extra_ext_deactivate_ajax'] );
                    add_action( 'wp_ajax_nexter_ext_wp_replace_url_settings', [ $this, 'nexter_ext_wp_replace_url_settings_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_wp_duplicate_post_settings', [ $this, 'nexter_ext_wp_duplicate_post_settings_ajax'] );
                    add_action( 'wp_ajax_nexter_ext_save_data', [ $this, 'nexter_ext_save_data_ajax']);
                    add_action( 'wp_ajax_nexter_ext_google_recaptcha', [ $this, 'nexter_ext_google_recaptcha'] );
                }

                // Add Extra attr to script tag
                add_filter( 'script_loader_tag', [ $this,'nxt_async_attribute' ], 10, 2 );
                
                $this->nxtrecpVer  = array(
                    'v2' => [ 
                        'title' => sprintf( '%s 2', __( 'Version', 'nexter-ext' ) ) , 
                        'desc' => sprintf( '%s 2', __( 'reCAPTCHA v2 is added below the form fields, where user has to check the box to verify the human authenticity and if found suspicious it prompts verification challenge.', 'nexter-ext' ) ) , 
                    ],
                    'invisible'	=> [
                        'title' => sprintf( '%s', __( 'v2 (Invisible)', 'nexter-ext' ) ),
                        'desc' => sprintf( '%s 3', __( 'Invisible reCAPTCHA is a better version of v2, where no checkbox verification is required. It works on a score of user interaction and if found suspicious it asks to submit a challenge.', 'nexter-ext' ) ),
                    ],
                    'v3' => [
                        'title' => sprintf( '%s 3', __( 'Version', 'nexter-ext' ) ),
                        'desc' => sprintf( '%s 3', __( 'reCAPTCHA v3 is a user-friendly spam protection. It verifies the visitor on his score, which is based on overall website interaction. It is shown at the bottom left-side of the website.', 'nexter-ext' ) ),
                    ],
                );
        
                $this->nxtrecpForm  = array(
                    'login_form'    => sprintf( '%s', __( 'Login Form', 'nexter-ext' ) ),
                    'registration_form' => sprintf( '%s', __('Registration Form', 'nexter-ext' ) ),
                    'reset_pwd_form'    => sprintf( '%s', __( 'Reset Password Form', 'nexter-ext' ) ),
                    'comments_form' => sprintf( '%s', __( 'Comments Form', 'nexter-ext' ) ),
                );
        
                $this->recaptheme = array(
                    'light' => sprintf( '%s', __( 'Light', 'nexter-ext' ) ),
                    'dark'  => sprintf( '%s', __('Dark', 'nexter-ext' ) ),
                );
            }
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
            $recapData = ( isset( $_POST['recapData'] ) ) ? wp_unslash( $_POST['recapData'] ) : '';
            $wpDisableSet = ( isset( $_POST['wpDisableSet'] ) ) ? wp_unslash( $_POST['wpDisableSet'] ) : '';
           
            $wpEmailNotiSet = ( isset( $_POST['wpEmailNotiSet'] ) ) ? wp_unslash( $_POST['wpEmailNotiSet'] ) : '';
            $wpLoginWL = ( isset( $_POST['wpLoginWL'] ) ) ? wp_unslash( $_POST['wpLoginWL'] ) : '';
            $performance = ( isset( $_POST['advanceperfo'] ) ) ? wp_unslash( $_POST['advanceperfo'] ) : '';
            $commdata = ( isset( $_POST['discomment'] ) ) ? wp_unslash( $_POST['discomment'] ) : '';
            $wpDupPostSet = ( isset( $_POST['wpDupPostSet'] ) ) ? wp_unslash( $_POST['wpDupPostSet'] ) : '';
            $wpWLSet = ( isset( $_POST['wpWLSet'] ) ) ? wp_unslash( $_POST['wpWLSet'] ) : '';
            $securData = ( isset( $_POST['securData'] ) ) ? wp_unslash( $_POST['securData'] ) : '';
            $nxtctmLogin = ( isset( $_POST['nxtctmLogin'] ) ) ? wp_unslash( $_POST['nxtctmLogin'] ) : '';
            $image_size = ( isset( $_POST['image_size'] ) ) ? wp_unslash( $_POST['image_size'] ) : '';
            $new_custom_image_size = ( isset( $_POST['new_custom_size'] ) ) ? wp_unslash( $_POST['new_custom_size'] ) : '';
            $new_custom_image_size = (array)json_decode($new_custom_image_size);
            $ele_icons = ( isset( $_POST['ele_icons'] ) ) ? wp_unslash( $_POST['ele_icons'] ) : '';
            $disabled_image_sizes = get_option('nexter_disabled_images');
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

            if( !empty( $ext ) && $ext==='local-google-font' && !empty($fonts)){
                if( !empty( $get_option ) && isset($get_option[ $ext ]) ){
                    $get_option[ $ext ]['values'] = json_decode($fonts);
                    update_option( $option_page, $get_option );
                    if(class_exists('Nexter_Font_Families_Listing')){
                        Nexter_Font_Families_Listing::get_local_google_font_data();
                    }
                }
                wp_send_json_success();
            }else if(!empty( $ext ) && $ext==='custom-upload-font' && !empty($fonts)){
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
            }else if( !empty( $ext ) && ( $ext==='advance-performance' && !empty($performance) ) || ($ext==='disable-comments' && !empty($commdata) ) ){
                $advanceData =  json_decode($performance);
                $disableComm = (array) json_decode($commdata);
                
                if( False === $getperoption ){	
                    if(!empty($advanceData) ){
                        add_option($perforoption,$advanceData);
                    }else{
                        add_option($perforoption,$disableComm);
                    }
                }else{
                    $get_option = get_option($perforoption);
                    if(!empty($get_option)){
                        if( $ext==='advance-performance'){
                            $old_comment = [];
                            $old_comment['disable_comments'] = (isset($get_option['disable_comments']) ? $get_option['disable_comments'] : '');
                            $old_comment['disble_custom_post_comments'] = (isset($get_option['disble_custom_post_comments']) ? $get_option['disble_custom_post_comments'] : [] );

                            $new = array_merge($old_comment,$advanceData);
                        }else if($ext==='disable-comments'){
                            if(isset($get_option['disable_comments'])){
                                unset($get_option['disable_comments']);
                            }
                            if(isset($get_option['disable_comments'])){
                                unset($get_option['disble_custom_post_comments']);
                            }
                            $new = array_merge($get_option,$disableComm);
                        }
                        update_option( $perforoption, $new );
                    }
                }
                wp_send_json_success();
            }else if( !empty( $ext ) && ( $ext==='advance-security' && !empty($securData) ) || ( $ext==='custom-login' && !empty($nxtctmLogin) ) || ( $ext==='wp-right-click-disable' && !empty($wpDisableSet) ) || ($ext==='email-login-notification' && !empty($wpEmailNotiSet)) || ($ext==='2-fac-authentication' )){
                
                $securData = (array) json_decode($securData);
                $nxtctmLogin = (array) json_decode($nxtctmLogin);
                $disrightclick = (array) json_decode($wpDisableSet,true);
                $emailNotiSet = (array) json_decode($wpEmailNotiSet);
                
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
                        }
                        else if($ext === '2-fac-authentication'){
	                        $allowed_2fa_roles = ( isset( $_POST['allowed_2fa_roles'] ) ) ? wp_unslash( $_POST['allowed_2fa_roles'] ) : '';
	                        $allowed_2fa_roles = json_decode($allowed_2fa_roles, true);
	                        $email_customisation = array();
                            $email_customisation['subject'] = ( isset( $_POST["customEmailSubject"] ) ) ? sanitize_text_field( wp_unslash( $_POST['customEmailSubject'] ) ) : '';
                            $email_customisation['body'] = ( isset( $_POST["customEmailBody"] ) ) ? sanitize_textarea_field( ( $_POST['customEmailBody'] ) ) : '';
                            $temp_roles[$ext]['values']['allowed_2fa_roles'] = $allowed_2fa_roles;
                            $temp_roles[$ext]['values']['email_customisations'] = $email_customisation;
                            $temp_roles[$ext]['switch'] = true;
                            $newArr = array_merge($get_option,$temp_roles);

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
            }

            wp_send_json_error();
        }
        
        /**
         * Register nexter setting to WP
         */
        public function init() {
            $option_tabs = self::option_fields();
            
            foreach ($option_tabs as $index => $option_tab) {
                register_setting($option_tab['id'], $option_tab['id']);
            }
        }

        public function nexter_extension_option_config(){
            $config = [
                'google-recaptcha' => [
                    'title' => esc_html__( 'Google reCAPTCHA', 'nexter-ext' ),
                    'description' => esc_html__( 'Stop Spammers and Bad bots from visiting your site. Add Google reCAPTCHA on your WP-Admin, comments etc.', 'nexter-ext' ),
                    'type' => 'free',
                    'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/google-recaptch.svg',
                    'priority' => 5,
                    'button' => true,
                ],
                'wp-replace-url' => [
                    'title' => esc_html__( 'Replace URL & Text', 'nexter-ext' ),
                    'description' => esc_html__( 'Facing HTTPS issues or moved staging to live?  Replace your olddomain.com with newdomain.com from completely from database.', 'nexter-ext' ),
                    'type' => 'free',
                    'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/replace-url.svg',
                    'priority' => 7,
                    'button' => false,
                ],
                'wp-duplicate-post' => [
                    'title' => esc_html__( 'Duplicate Post', 'nexter-ext' ),
                    'description' => esc_html__( 'This option gives you to duplicate any post types including taxonomies & custom fields.', 'nexter-ext' ),
                    'type' => 'free',
                    'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/duplicate-post.svg',
                    'priority' => 8,
                    'button' => true,
                ],
            ];
        
            return apply_filters('nexter-extension-extra-option-config', $config );
        }

        public function nexter_extra_options_content(){
            $config_option = $this->nexter_extension_option_config();

            $extension = get_option( 'nexter_extra_ext_options' );
            echo '<div class="nxt-extra-opt-wrap nxt-mt-50">';
                echo '<div class="nxt-panel-row">';
                    if( !empty($config_option) ){
                        $columns = array_column($config_option, 'priority');
                        array_multisort($columns, SORT_ASC, $config_option);
        
                        foreach($config_option as $name => $data){
                            echo '<div class="nxt-panel-col nxt-panel-col-33">';
                                echo '<div class="nxt-panel-sec nxt-'.esc_attr($name).' nxt-p-20">';
                                    echo '<div class="nxt-extra-icon"><img src="'.esc_url($data['svg']).'" alt="'.esc_attr($name).'" /></div>';
                                    if(isset($data['beta']) && !empty($data['beta'])){
                                        echo '<div class="nxt-beta-ext">'.esc_html__('Beta','nexter-ext').'</div>';
                                    }
                                    echo '<div class="nxt-extra-title">';
                                        echo wp_kses_post($data['title']);
                                        echo '<span class="nxt-desc-icon" >';
                                            echo '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_attr($name).'" /> ';
                                            echo '<div class="nxt-tooltip">'.wp_kses_post($data['description']).'</div>';
                                        echo '</span>';
                                    echo '</div>';
                                    if($data['button'] == true){
        
                                        if($name == 'regenerate-thumbnails' ){
                                            echo '<button class="nxt-ext-btn nxt-ext-active" data-ext="'.esc_attr($name).'" data-enable-disable="active"><span>'.esc_html__( 'Regenerate', 'nexter-ext' ).'</span></button>';
                                        }else if( !empty($extension) && !empty($extension[ $name ]) && !empty($extension[ $name ]['switch'])){
                                            echo '<button class="nxt-ext-btn nxt-ext-deactivate" data-ext="'.esc_attr($name).'" data-enable-disable="deactive"><span>'.esc_html__( 'Deactivate', 'nexter-ext' ).'</span></button>';
                                            echo '<button class="nxt-ext-btn nxt-ext-settings" data-ext="'.esc_attr($name).'"><span><img src ="'.esc_url(NEXTER_EXT_URL.'assets/images/panel-icon/setting.svg').'" alt="Setting" /></span></button>';
                                        }else{
                                            echo '<button class="nxt-ext-btn nxt-ext-active" data-ext="'.esc_attr($name).'" data-enable-disable="active"><span>'.esc_html__( 'Enable', 'nexter-ext' ).'</span></button>';
                                        }
                                    }else{
                                        if($name == 'branded-wp-admin'){
                                            echo '<button class="nxt-ext-coming-soon" data-ext="'.esc_attr($name).'"><span>'.esc_html__( 'Coming Soon', 'nexter-ext' ).'</span></button>';
                                        }else{
                                            echo '<button class="nxt-ext-btn nxt-ext-settings" data-ext="'.esc_attr($name).'"><span>'.esc_html__( 'Settings', 'nexter-ext' ).'</span></button>';
                                        }
                                    }
                                echo '</div>';
                            echo '</div>';
                        }
                    }
                echo '</div>';
            echo '</div>';
        }

        public function nexter_help_actions_render(){
            echo '<div class="nxt-welcome-support-toggle" title="'.esc_attr__('Need Help?','nexter-ext').'"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M202.021 0C122.202 0 70.503 32.703 29.914 91.026c-7.363 10.58-5.093 25.086 5.178 32.874l43.138 32.709c10.373 7.865 25.132 6.026 33.253-4.148 25.049-31.381 43.63-49.449 82.757-49.449 30.764 0 68.816 19.799 68.816 49.631 0 22.552-18.617 34.134-48.993 51.164-35.423 19.86-82.299 44.576-82.299 106.405V320c0 13.255 10.745 24 24 24h72.471c13.255 0 24-10.745 24-24v-5.773c0-42.86 125.268-44.645 125.268-160.627C377.504 66.256 286.902 0 202.021 0zM192 373.459c-38.196 0-69.271 31.075-69.271 69.271 0 38.195 31.075 69.27 69.271 69.27s69.271-31.075 69.271-69.271-31.075-69.27-69.271-69.27z"/></svg></div>';

            echo '<div class="nxt-welcome-support-content">';
                echo '<div class="nxt-sup-top">';
                    echo '<h3 class="nxt-quick-sup-title">'.esc_html__('Quick support','nexter-ext').'</h3>';
                    if(defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')){
                        $active_status = Nexter_Pro_Ext_Activate::nexter_ext_pro_activate_msg();
                        if(!empty($active_status) && isset($active_status['status']) && $active_status['status']=='valid'){
                            echo '<a class="nxt-sup-free-btn pro-activated" ><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/diamond.svg').'" />'.esc_html__('PRO ACTIVATED','nexter-ext').'</a>';
                        }
                    }else{
                        echo '<a class="nxt-sup-free-btn">'.esc_html__('FREE','nexter-ext').'</a>';
                    }
                echo '</div>';
                echo '<div class="nxt-support-inner">';
                    echo '<ul class="nxt-support-list">';
                    $right_arrow_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="16" fill="none" stroke="#22379c"><path class="arrow-one" d="m1 15 7-7-7-7" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path class="arrow-two" d="m1 15 7-7-7-7" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path class="arrow-three" d="m1 15 7-7-7-7" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        echo '<li><a href="'.( (!defined('NXT_PRO_EXT')) ? esc_url('https://wordpress.org/support/theme/nexter/') : esc_url('https://store.posimyth.com/helpdesk/')).'"  target="_blank" rel="noopener noreferrer"><span class="support-title-wrap"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/free-support-icon.svg').'" />'.( (!defined('NXT_PRO_EXT')) ? esc_html__('Get Free Support','nexter-ext') : esc_html__('Get Premium Support','nexter-ext') ).'</span>'.$right_arrow_icon.'</a></li>';
                        echo '<li><a href="'.( (!defined('NXT_PRO_EXT')) ? esc_url('https://roadmap.nexterwp.com/updates?filter=Free+Theme') : esc_url('https://roadmap.nexterwp.com/updates')).'"  target="_blank" rel="noopener noreferrer"><span class="support-title-wrap sup-latest-update"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/support-latest-update.svg').'" />'.esc_html__('Latest Updates','nexter-ext').'</span>'.$right_arrow_icon.'</a></li>';
                        echo '<li><a href="'.esc_url('https://www.facebook.com/groups/139678088029161/').'" target="_blank" rel="noopener noreferrer"><span class="support-title-wrap"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/support-join-fb.svg').'" />'.esc_html__('Join Facebook Community','nexter-ext').'</span>'.$right_arrow_icon.'</a></li>';
                        echo '<li><a href="'.esc_url('https://roadmap.nexterwp.com/boards/feature-requests').'" target="_blank" rel="noopener noreferrer"><span class="support-title-wrap"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/support-new-fea.svg').'" />'.esc_html__('Suggest New Features','nexter-ext').'</span>'.$right_arrow_icon.'</a></li>';
                        echo '<li><a href="'.esc_url('https://roadmap.nexterwp.com/boards/bug').'" target="_blank" rel="noopener noreferrer"><span class="support-title-wrap"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/support-report-bug.svg').'" />'.esc_html__('Report Bug','nexter-ext').'</span>'.$right_arrow_icon.'</a></li>';
                    echo '</ul>';
                    if( !defined('NXT_PRO_EXT') ){
                        echo '<a href="'.esc_url('https://nexterwp.com/pricing/').'" class="sup-upgrade-get-pro"><img src="'.esc_url(NEXTER_EXT_URL.'/assets/images/panel-icon/sup-upgrade-pro.svg').'" />'.esc_html__('Upgrade to PRO Version','nexter-ext').'</a>';
                    }
                    echo '<div class="nxt-panel-row" style="justify-content: space-between;">';
                        echo '<a href="'.esc_url('https://www.youtube.com/c/POSIMYTHInnovations/?sub_confirmation=1').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Youtube','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="M13.69 4.09a1.76 1.76 0 0 0-1.24-1.24c-1.09-.3-5.45-.3-5.45-.3s-4.36 0-5.45.3C.95 3 .48 3.48.3 4.09.02 5.19.02 7.47.02 7.47s0 2.29.3 3.38c.16.6.63 1.06 1.23 1.22 1.09.3 5.45.3 5.45.3s4.36 0 5.45-.3c.6-.16 1.07-.62 1.24-1.22.29-1.1.29-3.38.29-3.38s0-2.29-.3-3.38zM5.57 9.54V5.4l3.65 2.07-3.65 2.07z" fill="#22379b"/></svg></span></a>';
                        echo '<a href="'.esc_url('https://twitter.com/posimyth').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Twitter','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="m12.56 4.15.01.37c0 3.8-2.89 8.16-8.16 8.16A8.11 8.11 0 0 1 0 11.4a5.86 5.86 0 0 0 4.25-1.2 2.88 2.88 0 0 1-2.68-1.98 3.62 3.62 0 0 0 .54.04c.26 0 .52-.03.76-.1a2.87 2.87 0 0 1-2.3-2.81V5.3a2.89 2.89 0 0 0 1.3.37 2.87 2.87 0 0 1-1.28-2.4 2.85 2.85 0 0 1 .39-1.44 8.16 8.16 0 0 0 5.91 3 3.24 3.24 0 0 1-.07-.66A2.87 2.87 0 0 1 9.7 1.32a2.86 2.86 0 0 1 2.1.9 5.65 5.65 0 0 0 1.82-.7 2.86 2.86 0 0 1-1.26 1.59A5.75 5.75 0 0 0 14 2.67a6.17 6.17 0 0 1-1.44 1.48z" fill="#22379b"/></svg></span></a>';
                        echo '<a href="'.esc_url('https://www.facebook.com/nexterwp').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Facebook','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="m10.26 7.88.39-2.54H8.2V3.7c0-.7.34-1.37 1.43-1.37h1.1V.17S9.75 0 8.8 0c-2 0-3.31 1.21-3.31 3.41v1.93H3.25v2.54h2.23V14H8.2V7.87h2.05z" fill="#22379b"/></svg></span></a>';
                        echo '<a href="'.esc_url('https://www.instagram.com/posimyth').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Instagram','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="M7 3.85a3.14 3.14 0 1 0 0 6.28 3.14 3.14 0 0 0 0-6.28zm0 5.19a2.05 2.05 0 1 1 .01-4.1 2.05 2.05 0 0 1 0 4.1zm4-5.31a.73.73 0 1 1-1.46 0 .73.73 0 1 1 1.47 0zm2.09.74c-.05-.98-.27-1.85-1-2.57S10.52.96 9.54.91a74.77 74.77 0 0 0-5.06 0c-.98.05-1.84.27-2.56.99S.96 3.49.9 4.47a74.77 74.77 0 0 0 0 5.05c.05.99.28 1.85 1 2.57s1.58.94 2.56 1c1.02.05 4.05.05 5.06 0 .98-.05 1.85-.28 2.57-1s.94-1.58.99-2.57c.06-1 .06-4.04 0-5.05zm-1.31 6.14c-.21.54-.63.95-1.17 1.16-.8.32-2.72.25-3.6.25s-2.82.07-3.62-.25a2.07 2.07 0 0 1-1.16-1.16C1.9 9.8 1.98 7.89 1.98 7s-.07-2.81.25-3.61c.2-.54.62-.95 1.16-1.17.8-.32 2.72-.25 3.61-.25s2.81-.07 3.61.25a2.07 2.07 0 0 1 1.17 1.17c.32.8.25 2.72.25 3.6s.07 2.82-.25 3.62z" fill="#22379b"/></svg></span></a>';
                        echo '<a href="'.esc_url('https://nexterwp.com/blog').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Blogs','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="M1.57 4.82a.66.66 0 0 0-.7.6c-.02.37.26.68.62.7a7.06 7.06 0 0 1 6.38 6.39c.03.35.32.62.63.62h.05a.66.66 0 0 0 .61-.7 8.34 8.34 0 0 0-7.59-7.61zM1.75.87a.87.87 0 1 0 0 1.75 9.64 9.64 0 0 1 9.63 9.63.87.87 0 1 0 1.74 0A11.39 11.39 0 0 0 1.76.87zm.85 8.75c-.94 0-1.73.79-1.73 1.76s.79 1.74 1.73 1.74a1.77 1.77 0 0 0 1.75-1.74c0-.97-.76-1.76-1.75-1.76z" fill="#22379b"/></svg></span></a>';
                        echo '<a href="'.esc_url('https://store.posimyth.com/join-affiliate/').'" class="nxt-panel-col sup-social-link" title="'.esc_html__('Join Affiliate','nexter-ext').'" target="_blank" rel="noopener noreferrer"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"><path d="M7.03 11.87c-.65 0-1.24-.07-1.76-.23a4.31 4.31 0 0 1-1.5-.87 1.1 1.1 0 0 1-.28-.35.87.87 0 0 1-.1-.4.79.79 0 0 1 .22-.55.78.78 0 0 1 .6-.26.74.74 0 0 1 .47.17 3.37 3.37 0 0 0 3.3.73 2 2 0 0 0 .7-.43.9.9 0 0 0 .27-.63 1.06 1.06 0 0 0-.25-.72c-.17-.2-.43-.36-.77-.49a5.66 5.66 0 0 0-1.32-.32c-.5-.08-.95-.19-1.33-.34-.38-.16-.7-.35-.97-.58a2.45 2.45 0 0 1-.57-.84c-.13-.33-.2-.7-.2-1.1a2.6 2.6 0 0 1 1.74-2.53 4.87 4.87 0 0 1 4.72.63c.27.21.4.46.4.74 0 .2-.07.4-.23.56s-.35.25-.56.25a.61.61 0 0 1-.38-.12 2.37 2.37 0 0 0-.6-.37 4.52 4.52 0 0 0-.8-.29 2.98 2.98 0 0 0-1.8.03c-.28.1-.48.24-.62.42s-.21.38-.21.61c0 .28.08.52.23.7.17.18.41.33.72.44a9.02 9.02 0 0 0 1.1.28c.56.1 1.05.22 1.47.36.43.14.79.32 1.07.55a1.98 1.98 0 0 1 .63.82c.14.33.2.73.2 1.2 0 .61-.16 1.14-.5 1.58s-.77.77-1.32 1c-.55.24-1.13.35-1.77.35zm1 .76a.8.8 0 0 1-.24.6.8.8 0 0 1-.6.25.78.78 0 0 1-.6-.25.81.81 0 0 1-.23-.6V1.37A.84.84 0 0 1 7.2.52c.25 0 .44.08.6.25.15.16.23.36.23.6v11.26z" fill="#22379c"/></svg></span></a>';
                    echo '</div>';
                echo '</div>';
                echo '<a href="'.esc_url('https://posimyth.com/').'" class="nxt-support-bottom" target="_blank" rel="noopener noreferrer">'.esc_html__('Powered by POSIMYTH Innovations','nexter-ext').'</a>';
            echo '</div>';
        }

        public function nexter_import_activate_builder_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            
            if ( ! current_user_can( 'install_plugins' ) || ! isset( $_POST['file'] ) || ! sanitize_text_field( wp_unslash( $_POST['file'] ) ) ) {
                wp_send_json_error(
                    array(
                        'success' => false,
                        'content' => __( 'No plugin..', 'nexter-ext' ),
                    )
                );
            }
    
            $file = ( isset( $_POST['file'] ) ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
    
            $activate = activate_plugin( $file, '', false, true );
    
            if ( is_wp_error( $activate ) ) {
                wp_send_json_error(
                    array(
                        'success'	=> false,
                        'content'	=> $activate->get_error_message(),
                    )
                );
            }
    
            wp_send_json_success(
                array(
                    'success'	=> true,
                    'content'	=> __( 'Activated', 'nexter-ext' ),
                )
            );
    
        }
        
        public function get_nxt_brand_name(){
            if(defined('NXT_PRO_EXT')){
                $options = get_option( 'nexter_white_label' );
                $this->setting_name = (!empty($options['brand_name'])) ? $options['brand_name'] : esc_html__('Nexter Settings', 'nexter-ext');
            }else{
                $this->setting_name = esc_html__('Nexter Settings', 'nexter-ext');
            }
        }
        
        /*Load Panel Settings Style & Scripts*/
        public function enqueue_scripts_admin( $hook_suffix ){
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
            
            if(! did_action('wp_enqueue_media')){
				wp_enqueue_media();
			}
            wp_enqueue_script( 'jquery-masonry' );
            
            wp_enqueue_style( 'nxt-panel-settings', NEXTER_EXT_URL .'assets/css/admin/nexter-admin'. $minified .'.css', array(), NEXTER_EXT_VER );
            if ( 'post-new.php' != $hook_suffix && 'post.php' != $hook_suffix && NXT_BUILD_POST == get_post_type()) {
                wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL .'assets/css/extra/select2'. $minified .'.css', array(), NEXTER_EXT_VER );
			    wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2'. $minified .'.js', array(), NEXTER_EXT_VER, false );
            }
            if ( ! is_customize_preview() ) {
				wp_enqueue_style( 'wp-color-picker' );
				global $pagenow;
				$thirdjs = array('wp-util', 'wp-color-picker');
				if(!empty($pagenow) && $pagenow === 'themes.php' && isset($_GET['page']) && $_GET['page'] === 'nexter_settings_welcome'){
					$thirdjs = array_merge($thirdjs, ['updates']);
				}
				wp_enqueue_script( 'nexter-panel-setting', NEXTER_EXT_URL . 'assets/js/admin/nexter-panel-settings'. $minified .'.js', $thirdjs, NEXTER_EXT_VER, false );
			}
            
            //nexter component CSS & JS
			wp_enqueue_style( 'nxt-custom-fields-css', NEXTER_EXT_URL .'assets/css/admin/nexter-custom-fields'. $minified .'.css', array(), NEXTER_EXT_VER );
			wp_enqueue_script( 'nexter-custom-fields-js', NEXTER_EXT_URL . 'assets/js/admin/nexter-custom-fields'. $minified .'.js', array(), NEXTER_EXT_VER, true);

            $nexter_admin_localize = array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'ajax_nonce' => wp_create_nonce('nexter_admin_nonce'),
                'nexter_path' => NEXTER_EXT_URL.'assets/',
                'is_pro' => (defined('NXT_PRO_EXT')) ? true : false,
              );
              
            wp_localize_script( 'nexter-panel-setting', 'nexter_admin_config', $nexter_admin_localize );
        }

        /* Settings Admin Menu */
        public function nxt_add_menu_page(){

            add_theme_page( $this->setting_name, $this->setting_name, 'manage_options', 'nexter_settings_welcome', array( $this, 'admin_page_display' ) );

            $option_tabs = self::option_fields();
            global $_registered_pages, $submenu;
            
            unset($submenu['themes.php'][20]);
            unset($submenu['themes.php'][15]);
            
            foreach ($option_tabs as $index => $option_tab) {
                if(isset($option_tabs) && $option_tab['id'] != "nexter_white_label" && $option_tab['id'] != "nexter_activate"){
					$function_name = array( $this,'admin_page_display');
					$hookname = get_plugin_page_hookname( $option_tab['id'], $option_tabs[0]['id'] );
					if ( ! empty( $function_name ) && ! empty( $hookname ) ) {
						add_action( $hookname, $function_name );
					}
					$_registered_pages[ $hookname ] = true;	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}else{
					$label_options=get_option( 'nexter_white_label' );	
					if( ((empty($label_options['nxt_hidden_label']) || $label_options['nxt_hidden_label']!='on') && ($option_tab['id'] == "nexter_white_label" || $option_tab['id'] == "nexter_activate")) || !defined('NXT_PRO_EXT_VER')){
						$function_name = array( $this,'admin_page_display');
						$hookname = get_plugin_page_hookname( $option_tab['id'], $option_tabs[0]['id'] );
						if ( ! empty( $function_name ) && ! empty( $hookname ) ) {
							add_action( $hookname, $function_name );
						}
						$_registered_pages[ $hookname ] = true;	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					}
				}
            }
        }

        /* Settings Display Page */
        public function admin_page_display() {
            $option_tabs = self::option_fields();
            $tab_forms   = [];

            $output ='';
		
            $output .='<div class="'.esc_attr($this->key).'">';
                
                $output .='<div id="nxt-setting-header-wrapper">';
                    $output .='<div class="nxt-head-inner">';
                    
                        $options = get_option( 'nexter_white_label' );
                        if(defined('NXT_PRO_EXT') && (!empty($options['theme_logo']))){
                          $output .='<img src="'.esc_url($options['theme_logo']).'" style="max-width:150px;"/>';
                        }else{
                            $output .='<svg xmlns="http://www.w3.org/2000/svg" width="202" height="65" fill="none"><g fill="#fff" clip-path="url(#a)"><path d="M45.61 22.466 35.309.178h-6.902v63.227h6.902V11.51l10.288 22.284.015 29.612h6.902V.178H45.61v22.288ZM104.905 38.569c0 .449-.27.798-.808 1.052l-4.368 1.69-4.374-1.664c-.54-.247-.808-.603-.808-1.055V.026L87.652 0v38.694c0 2.166 1.313 3.881 3.938 5.147l1.236.589-1.236.591c-2.626 1.266-3.938 2.978-3.938 5.138v13.066h6.914V50.331c0-.466.27-.845.808-1.1l4.355-1.683 4.35 1.684c.538.254.808.624.808 1.099v12.894h6.928V50.16c0-2.158-1.314-3.87-3.94-5.138l-1.237-.591 1.237-.59c2.623-1.265 3.937-2.98 3.94-5.146V0h-6.91v38.569ZM119.4 5.578l8.314-.007v57.834h6.884l.023-57.834h8.302V.178h-23.516l-.007 5.4ZM197.181 51.037c2.92-.986 4.379-2.799 4.376-5.438V6.08c0-1.631-.753-3.028-2.241-4.193C197.828.722 196.72.133 194.673.133H178.06v63.232h6.902v-9.627h7.035c.058 0 .107.02.161.028.304.032.602.105.887.217.093.032.177.07.263.109l.07.033c.459.205.835.56 1.067 1.006l.027.05c.043.092.079.186.108.283.021.063.04.128.055.193.054.229.081.463.082.699l-.033 7.011h6.91v-6.846c0-2.643-1.481-4.474-4.402-5.482l-.011-.002Zm-2.497-4.972a1.977 1.977 0 0 1-.866 1.6c-.6.459-1.34.697-2.096.675h-6.753V5.57h8.109c.406-.008.803.119 1.129.361a1.072 1.072 0 0 1 .487.902l-.01 39.232ZM60.107 63.398h19.955v-5.405H67.028v-10.83h9.238v-5.399h-9.238V5.571h13.034V.173H60.107v63.225ZM150.51 5.578l13.041.007v14.12h-9.236v5.404h9.229v32.896H150.51v5.4h19.962V.178H150.51v5.4ZM10.36 57.176l-.032 7.196H0v-9.995h7.47c.066 0 .115.023.17.03.32.036.633.114.932.233.096.033.193.075.284.114.023.017.05.026.072.042a2.33 2.33 0 0 1 1.13 1.071l.032.056c.047.097.085.197.114.3.025.07.044.14.056.212.06.242.09.49.089.739M10.328 4.05v42.08c0 .657-.3 1.225-.918 1.714a3.46 3.46 0 0 1-2.233.722H0V2.69h8.616c.433-.009.856.128 1.202.389a1.165 1.165 0 0 1 .51.966"/></g><defs><clipPath id="a"><path fill="#fff" d="M0 0h201.571v64.371H0z"/></clipPath></defs></svg>';
                        }
                        $output .='<div class="nxt-panel-head-inner">';
                            $output .='<h2 class="nxt-head-setting-panel">'.esc_html__('Extension Settings','nexter-ext').'</h2>';
                            $output .='<div class="nxt-current-version"> '.esc_html__('Version','nexter-ext').' '.NEXTER_EXT_VER.'</div>';
                        $output .='</div>';
                    $output .='</div>';
                    
                    $output .='<div class="nxt-nav-tab-wrapper">';
                        $output .='<div class="nav-tab-wrapper">';
                            ob_start();
                            foreach ($option_tabs as $option_tab):
                                $tab_slug  = $option_tab['id'];
                                $nav_class = 'nav-tab';
                                if (isset($_GET['page']) && $tab_slug == $_GET['page']) {
                                    $nav_class .= ' nav-tab-active'; //add active class to current tab
                                    $tab_forms[] = $option_tab; //add current tab to forms to be rendered
                                }
                                $navicon = $nav_url ='';
                                
                                if($tab_slug == "nexter_settings_welcome"){
                                    $nav_url = menu_page_url($tab_slug, false);
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.559 1.5a.668.668 0 0 1 .883 0L16 4.64v-.973c0-.552-.054-.667.5-.667h4c.555 0 .5.115.5.667v5.497l2.5 2.166c.275.242.656.549.403.89-.204.275-.628.28-.903 0l-2-1.72v8.834C21 21.175 19.842 22 18 22H6c-1.84 0-3-.825-3-2.666V10.5l-2 1.72c-.276.28-.627.275-.87 0-.244-.312.094-.648.37-.89L11.56 1.5ZM20 8.3V4h-3v1.817L20 8.3ZM4 9.62v9.713C4 20.438 4.896 21 6 21h3v-6.333c0-.738.263-.667 1-.667h4c.738 0 1-.07 1 .667V21h3c1.105 0 2-.562 2-1.666V9.62l-8-6.96-8 6.96Zm6 5.38v6h4v-6h-4Z"/></svg>';
                                }
                                if($tab_slug == "nexter_activate"){
                                    $nav_url = admin_url( 'admin.php?page=' . $tab_slug );
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_1467_14917)"><path fill-rule="evenodd" clip-rule="evenodd" d="M9.236 10.161a7.004 7.004 0 1 1 5.592 4.765l-.007-.002a.573.573 0 0 0-.052-.005.5.5 0 0 0-.412.143l-2.448 2.447H9.116a.5.5 0 0 0-.5.5v2.5h-2.5a.5.5 0 0 0-.5.5v2h-4.5v-4.293l8-8a.498.498 0 0 0 .146-.33.548.548 0 0 0-.024-.218l-.002-.007Zm10.07 5.082a8 8 0 0 1-4.413.697l-2.423 2.423a.5.5 0 0 1-.354.146h-2.5v2.5a.5.5 0 0 1-.5.5h-2.5v2a.5.5 0 0 1-.5.5h-5.5a.5.5 0 0 1-.5-.5v-5a.5.5 0 0 1 .147-.353L8.21 10.21a8 8 0 1 1 11.095 5.032ZM18.02 7.296a1 1 0 1 1-1.415-1.414 1 1 0 0 1 1.415 1.414Zm.707.707a2 2 0 1 1-2.829-2.828 2 2 0 0 1 2.829 2.828Z"/></g><defs><clipPath id="clip0_1467_14917"><path d="M0 0h24v24H0z"/></clipPath></defs></svg>';
                                }
                                if($tab_slug == "nexter_site_performance"){
                                    $nav_url = admin_url( 'admin.php?page=' . $tab_slug );
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 1c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10ZM6.016 11.506v-.003a6.004 6.004 0 0 1 8.545-4.934l.003.002a.528.528 0 0 0 .687-.202.471.471 0 0 0-.19-.665 7.248 7.248 0 0 0-.075-.035l-.054-.025A7 7 0 0 0 5.03 11.36l-.005.06a6.951 6.951 0 0 0-.006.082.471.471 0 0 0 .48.497.528.528 0 0 0 .518-.494Zm11.456-1.977-.001-.003a.528.528 0 0 1 .213-.684.471.471 0 0 1 .662.203l.034.075.024.054a7 7 0 0 1 .567 2.187l.005.06.006.082a.471.471 0 0 1-.48.497.528.528 0 0 1-.518-.494v-.003a6.005 6.005 0 0 0-.512-1.974ZM12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm0 1a2 2 0 0 0 1.585-3.22l3.719-3.72a.5.5 0 1 0-.707-.706l-3.808 3.808A2 2 0 1 0 12 14Z"/></svg>';
                                }
                                if($tab_slug == "nexter_site_security"){
                                    $nav_url = admin_url( 'admin.php?page=' . $tab_slug );
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M11.824 1.532a.5.5 0 0 1 .352 0l8 3A.5.5 0 0 1 20.5 5v7c0 3.223-2.14 5.85-4.17 7.626a22.18 22.18 0 0 1-4.008 2.77c-.03.017-.054.029-.071.037l-.02.01-.005.003h-.002v.001L12 22l-.224.447-.002-.001-.005-.003-.02-.01a8.814 8.814 0 0 1-.335-.18 22.174 22.174 0 0 1-3.743-2.628C5.64 17.851 3.5 15.224 3.5 12.001V5a.5.5 0 0 1 .324-.468l8-3ZM12 22l.224.447a.5.5 0 0 1-.448 0L12 22Zm0-.565a19.593 19.593 0 0 1-.985-.577 21.199 21.199 0 0 1-2.686-1.984C6.36 17.15 4.5 14.777 4.5 12V5.346L12 2.534l7.5 2.813V12c0 2.777-1.86 5.15-3.83 6.874a21.194 21.194 0 0 1-3.67 2.56ZM12 11a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm-.5.937V14.5a.5.5 0 0 0 1 0v-2.563a2 2 0 1 0-1 0Z"/></svg>';
                                }
                                
                                if($tab_slug == "nexter_extra_options"){
                                    $nav_url = admin_url( 'admin.php?page=' . $tab_slug );
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M9.5 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm.959-.5a3 3 0 0 0-5.918 0H2a.5.5 0 0 0 0 1h2.541a3 3 0 0 0 5.918 0H22a.5.5 0 0 0 0-1H10.459ZM13.5 18a2 2 0 1 0-4 0 2 2 0 0 0 4 0Zm-4.959-.5H2a.5.5 0 0 0 0 1h6.541a3 3 0 0 0 5.918 0H22a.5.5 0 0 0 0-1h-7.541a3 3 0 0 0-5.918 0ZM1.5 12a.5.5 0 0 1 .5-.5h14.041a3 3 0 1 1 0 1H2a.5.5 0 0 1-.5-.5ZM19 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4Z"/></svg>';
                                }
                                
                                if($tab_slug == "nexter_white_label"){
                                    $nav_url = admin_url( 'admin.php?page=' . $tab_slug );
                                    $navicon = '<svg class="tab-nav-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M18.181 7.933a.938.938 0 1 1-.334-.827 1.782 1.782 0 0 0 .72-.7l-.018-.002-3.764-.224a5.625 5.625 0 0 0-4.02 1.365l-6.298 5.461a.656.656 0 0 0-.057.937l5.522 6.094c.24.264.647.289.917.055l6.364-5.518a5.625 5.625 0 0 0 1.919-3.76l.296-3.393a.932.932 0 0 0-.087-.483 2.728 2.728 0 0 1-1.138 1.038v.001l-.022-.044Zm1.547-2.234c-.004.07-.011.141-.02.211.446.38.709.963.654 1.593l-.296 3.392a6.562 6.562 0 0 1-.895 2.78v6.669a.844.844 0 0 1-.844.843h-7.804a1.591 1.591 0 0 1-1.285-.52l-5.523-6.095a1.594 1.594 0 0 1 .138-2.274l6.299-5.462a6.563 6.563 0 0 1 4.16-1.602 2.72 2.72 0 0 1 5.417.465Zm-7.63 14.551h6.136v-5.351c-.13.133-.266.261-.407.384l-5.73 4.967Zm6.508-15.52c.119.236.183.496.19.76a1.895 1.895 0 0 0-.19-.022l-3.353-.2a1.782 1.782 0 0 1 3.353-.538ZM6.533 12.644a.469.469 0 0 1 .663.03l1.858 2.042a.469.469 0 0 1-.693.631l-1.859-2.041a.469.469 0 0 1 .031-.662Z"/></svg>';
                                }
                                
                                $label_options=get_option( 'nexter_white_label' );
                                if( (empty($label_options['nxt_hidden_label']) || $label_options['nxt_hidden_label']!='on') && ($tab_slug == "nexter_white_label" || $tab_slug == "nexter_activate") ){
                                    echo '<a class="'.esc_attr($nav_class).'" href="'.esc_url($nav_url).'">';
                                        echo '<span>'.$navicon.'</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo '<span>'.esc_html($option_tab['title']).'</span>';
                                    echo '</a>';
                                }else if(($tab_slug != "nexter_white_label" && $tab_slug != "nexter_activate") || !defined('NXT_PRO_EXT_VER')){
                                    echo '<a class="'.esc_attr($nav_class).'" href="'.esc_url($nav_url).'">';
                                        echo '<span>'.$navicon.'</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo '<span>'.esc_html($option_tab['title']).'</span>';
                                    echo '</a>';
                                }
                            endforeach;
                            $out = ob_get_clean();
                            $output .= $out;
                        $output .='</div>';
                    $output .='</div>';
                
                $output .='</div>';
                
                /*Content Options*/
                $output .='<div class="nxt-settings-form-wrapper form-'.esc_attr($tab_forms[0]['id']).'">';
                
                    if(!empty($tab_forms)){
                        ob_start();
                        foreach ($tab_forms as $tab_form):
                            
                            if($tab_form['id']=='nexter_white_label'){
                                do_action('nexter_white_label_notice');
                            }else if($tab_form['id']=='nexter_activate'){
                                do_action('nexter_activate_notice');
                            }else if($tab_form['id']=='nexter_extra_options'){
                                do_action('nexter_extra_options_render');
                            }
                            
                            if( ( defined('NXT_PRO_EXT') && $tab_form['id']=='nexter_white_label' ) || $tab_form['id']=='nexter_site_performance' || $tab_form['id']=='nexter_site_security' ){
                                do_action('nexter_ext_extra_option' , $tab_form['id'] );
                            }else if($tab_form['id']=='nexter_settings_welcome'){
                                include_once NEXTER_EXT_DIR . 'include/panel-settings/welcome-page.php';
                            }
                        endforeach;
                            do_action('nexter_help_actions');
                        $out = ob_get_clean();
                        $output .= $out;
                    }
                $output .='</div>';
                
            $output .='</div>';
            
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        /* Settings fields configuration */
        public function option_fields() {

            if (!empty($this->option_metabox)) {
                return $this->option_metabox;
            }
            
            $this->option_metabox[] = array(
                'id' => 'nexter_settings_welcome',
                'title' => esc_html__('Welcome', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_settings_welcome' )
                ),
                'show_names' => true,
                'fields' => ''
            );
            
            $this->option_metabox[] = array(
                'id' => 'nexter_extra_options',
                'title' => esc_html__('Extra Options', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_extra_options' )
                ),
                'show_names' => true,
                'fields' => '',
            );
            
            $performance_options=[];
            if(has_filter('nexter_site_performance_options')) {
                $performance_options = apply_filters('nexter_site_performance_options', $performance_options);
            }
            
            $this->option_metabox[] = array(
                'id' => 'nexter_site_performance',
                'title' => esc_html__('Performance', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_site_performance' )
                ),
                'show_names' => true,
                'fields' => $performance_options,
            );
            
            $security_options=[];
            if(has_filter('nexter_site_security_options')) {
                $security_options = apply_filters('nexter_site_security_options', $security_options);
            }

            $this->option_metabox[] = array(
                'id' => 'nexter_site_security',
                'title' => esc_html__('Security', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_site_security' )
                ),
                'show_names' => true,
                'fields' => $security_options,
            );

            $this->option_metabox[] = array(
                'id' => 'nexter_activate',
                'title' => esc_html__('Activate', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_activate' )
                ),
                'show_names' => true,
                'fields' => '',
            );
            
            $this->option_metabox[] = array(
                'id' => 'nexter_white_label',
                'title' => esc_html__('White Label', 'nexter-ext'),
                'show_on' => array(
                    'key' => 'options-page',
                    'value' => array( 'nexter_white_label' )
                ),
                'show_names' => true,
                'fields' => '',
            );
            
            return $this->option_metabox;
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
                        'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
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
                        'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
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
                        'content' => __( 'server not found..', 'nexter-ext' ),
                    )
                );
            }else if( !empty( $data ) && !empty( $switch ) ){

                $option_page = '';
                if($data=='email-login-notification' || $data=='2-fac-authentication'){
                    $option_page = 'nexter_site_security';
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
                        'content'	=> ($switch=='active') ? __( 'Activated', 'nexter-ext' ) : __( 'DeActivate', 'nexter-ext' ),
                    )
                );
            }
        }

        /*
         * Nexter WP Replace URL Settings
         * @since 1.1.0
         */
        public function nexter_ext_wp_replace_url_settings_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-ext' ),
                    )
                );
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
                    $sizes[$table['Name']] = sprintf( __( '(%s MB)', 'nexter-ext' ), $size );
                }
            }

            $output = '';
            $output .= '<div class="nxt-ext-modal-content">';
                $output .= '<div class="nxt-modal-title-wrap">';
                    $output .= '<div class="nxt-modal-title">'.esc_html__( 'Replace URL & Text', 'nexter-ext' ).'</div>';
                    //$output .= '<div class="nxt-modal-desc">'.esc_html__( 'This option gives you to replace media url and text. ', 'nexter-ext' ).'</div>';
                $output .= '</div>';

                $output .= '<div class="nxt-replace-loader"></div>';
                
                $output .= '<div class="nxt-replace-url-wrap">';
                    $output .= '<div class="nxt-replace-url-note"><strong>'.esc_html__('Important:', 'nexter-ext').'</strong>'.esc_html__( ' We strongly recommend that you ', 'nexter-ext' ).'<a href="https://wordpress.org/support/article/wordpress-backups/" target="_blank" rel="noopener noreferrer">'.esc_html__('backup your database', 'nexter-ext').'</a>'.esc_html__( ' before using Replace URL & Text.', 'nexter-ext' ).'</div>';

                    $output .= '<div class="nxt-replace-url-table-wrap">';
                    $output .= '<span class="nxt-r-table-title">'.esc_html__('Select Tables', 'nexter-ext').'</span>';
                        $output .= '<select class="nxt-replace-url-table" id="nxt-replace-url-table" name="dbtable[]" multiple="multiple" style="width: 100%; max-width: 100%;">';
                        foreach($tables as $tab){
                            $table_size = isset( $sizes[$tab] ) ? $sizes[$tab] : '';
                            $output .= '<option value="'.esc_attr($tab).'" selected>'.esc_html($tab).' '.esc_html($table_size).'</option>';
                        }
                        $output .= '</select>';
                    $output .= '</div>';
                    
                    $output .= '<label class="nxt-old-title">'.esc_html__('Old URL/Text', 'nexter-ext').'<input type="text" class="nxt-old-url" placeholder="http://oldurl.com or old text"/></label>';
                    $output .= '<label class="nxt-new-title">'.esc_html__('New URL/Text', 'nexter-ext').'<input type="text" class="nxt-new-url" placeholder="http://newurl.com or new text"/></label>';
                    $output .= '<div class="nxt-replace-case-wrap"><input type="checkbox" id="case_sensitive_toggle" name="case_sensitive_toggle" value="true"/><label for="case_sensitive_toggle"></label>'.esc_html__('Case Sensitive', 'nexter-ext').'</div>';

                    $output .= '<div class="nxt-replace-guid-wrap"><input type="checkbox" id="guid_toggle" name="guid_toggle" value="true"/><label for="case_sensitive_toggle"></label>'.esc_html__('Replace GUIDs', 'nexter-ext').'<a href="https://wordpress.org/documentation/article/changing-the-site-url/#important-guid-note" target="_blank"><img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="wp-replace-url-guid"></a></div>';
                    
                    $output .='<div class="nxt-replace-search-limit">'.esc_html__('Search Limit', 'nexter-ext').'<input id="search-limit" type="number" min="500" max="50000" step="100" value="20000"/><span class="nxt-desc-icon"><img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="wp-replace-url-limit"> <div class="nxt-tooltip">'.esc_html__('Decrease the value if you face any server timeout.', 'nexter-ext').'</div></span></div>';
                    
                    $output .= '<div class="nxt-replace-note-wrap"><p class="nxt-replace-url-notice"></p></div>';
                    
                    $output .= '<div class="nxt-replace-url-btn-wrap">';
                        $output .= '<button class="nxt-replace-url-btn"><span>'.esc_html__('Replace', 'nexter-ext').'</span></button>';
                        $output .= '<button class="nxt-replace-url-confirm-btn"><span>'.esc_html__('Confirm', 'nexter-ext').'</span></button>';
                    $output .= '</div>';

                $output .= '</div>';
            $output .= '</div>';
                        
            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
        }

        /*
         * Nexter WP Duplicate Post Settings
         * @since 1.1.0
         */
        public function nexter_ext_wp_duplicate_post_settings_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            $output = '';

            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-ext' ),
                    )
                );
            }

            $ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
            $extension_option = get_option( 'nexter_extra_ext_options' );
            if( !empty( $ext ) && $ext == 'wp-duplicate-post' ){

                $all_users=$current_author=$original_date='checked';

                $original_user=$original_author=$current_date='';

                $sSame=$sDraft=$sPublised=$sPending=$sPrivate='';

                $postfixText= 'Copy'; $slugText='copy';
                $wpDupPostSet = (!empty($extension_option) && isset($extension_option['wp-duplicate-post']) && !empty($extension_option['wp-duplicate-post']['values']) ) ? $extension_option['wp-duplicate-post']['values'] : '';

                if(!empty($wpDupPostSet)){
                    if(!empty($wpDupPostSet['nxt-duppost-access']) && $wpDupPostSet['nxt-duppost-access']=='original_user'){
                        $original_user = 'checked';
                        $all_users ='';
                    }
                    if(!empty($wpDupPostSet['nxt-duppost-author']) && $wpDupPostSet['nxt-duppost-author']=='original_author'){
                        $original_author = 'checked';
                        $current_author ='';
                    }
                    if(!empty($wpDupPostSet['nxt-duppost-date']) && $wpDupPostSet['nxt-duppost-date']=='current_date'){
                        $current_date = 'checked';
                        $original_date ='';
                    }
                    
                    if(!empty($wpDupPostSet['nxt-duppost-status'])){
                        if($wpDupPostSet['nxt-duppost-status']=='same'){
                            $sSame='selected';
                        }else if($wpDupPostSet['nxt-duppost-status']=='draft'){
                            $sDraft='selected';
                        }else if($wpDupPostSet['nxt-duppost-status']=='publish'){
                            $sPublised='selected';
                        }else if($wpDupPostSet['nxt-duppost-status']=='pending'){
                            $sPending='selected';
                        }else if($wpDupPostSet['nxt-duppost-status']=='private'){
                            $sPrivate='selected';
                        }
                    }

                    $postfixText = (!empty($wpDupPostSet['nxt-duplicate-postfix'])) ? $wpDupPostSet['nxt-duplicate-postfix'] : '';
                    $slugText = (!empty($wpDupPostSet['nxt-duplicate-slug'])) ? $wpDupPostSet['nxt-duplicate-slug'] : '';

                }
                
                $output .= '<div class="nxt-ext-modal-content">';
                    $output .= '<div class="nxt-modal-title-wrap">';
                        $output .= '<div class="nxt-modal-title">'.esc_html__( 'Duplicate Post', 'nexter-ext' ).'</div>';
                        //$output .= '<div class="nxt-modal-desc">'.esc_html__( 'This option gives you to duplicate any post types including taxonomies & custom fields.', 'nexter-ext' ).'</div>';
                    $output .= '</div>';

                    
                    $output .='<div class="nxt-dup-post-row">';
                        $output .='<div class="nxt-dup-post-column">';
                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center;">
                                <span class="nxt-wp-dppost-set-title">'.esc_html__('Who Can Duplicate','nexter-ext').'</span>
                                <div class="nxt-duppost-access">
                                    <input type="radio" value="all_users" name="nxt-who-dp" id="dp-all-users" '.$all_users.'/>
                                    <label for="dp-all-users">All Users</label>
                                    <input type="radio" value="original_user" name="nxt-who-dp" id="dp-original-user" '.$original_user.'/>
                                    <label for="dp-original-user">Original Author</label>
                                </div>
                            </div>';

                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center;">
                                <span class="nxt-wp-dppost-set-title">'.esc_html__('Post Author','nexter-ext').'</span>
                                <div class="nxt-duppost-author">
                                    <input type="radio" value="current_author" name="nxt-author-dp" id="dp-current-author" '.$current_author.'/>
                                    <label for="dp-current-author">Current User</label>
                                    <input type="radio" value="original_author" name="nxt-author-dp" id="dp-original-author" '.$original_author.'/>
                                    <label for="dp-original-author">Original Author</label>
                                </div>
                            </div>';

                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center;">
                                <span class="nxt-wp-dppost-set-title">'.esc_html__('Post Date','nexter-ext').'</span>
                                <div class="nxt-duppost-date">
                                    <input type="radio" value="original_date" name="nxt-date-dp" id="dp-original-date" '.$original_date.'/>
                                    <label for="dp-original-date">Duplicate Time</label>
                                    <input type="radio" value="current_date" name="nxt-date-dp" id="dp-current-date" '.$current_date.'/>
                                    <label for="dp-current-date">Current Time</label>
                                </div>
                            </div>';

                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center; ">
                                <span class="nxt-wp-dppost-set-title nxt-dis-style-title">'.esc_html__('Post Status','nexter-ext').'</span>
                                <select class="duplicate-post-status" style="margin-left: 10px">
                                    <option value="same" '.$sSame.'>Same as Original</option>
                                    <option value="draft" '.$sDraft.'>Draft</option>
                                    <option value="publish" '.$sPublised.'>Published</option>
                                    <option value="pending" '.$sPending.'>Pending</option>
                                    <option value="private" '.$sPrivate.'>Private</option>
                                </select>
                            </div>';

                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center; ">
                                <span class="nxt-wp-dppost-set-title">'.esc_html__('Postfix Text','nexter-ext').'</span>
                                <input class="nxt-duplicate-postfix" type="text" value="'.esc_attr($postfixText).'"/>
                            </div>';
                            $output .='<div class="nxt-wp-duplicate-post-wrap" style="flex-direction: row; align-items: center; ">
                                <span class="nxt-wp-dppost-set-title">'.esc_html__('Slug Text','nexter-ext').'</span>
                                <input class="nxt-duplicate-slug" type="text" value="'.esc_attr($slugText).'"/>
                            </div>';


                        $output .= '</div>';
                    $output .= '</div>';

                    $output .= '<button type="button" class="nxt-duplicate-post-set"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" ><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html( 'Save', 'nexter-ext' ).'</button>';
                $output .= '</div>';
                            
                wp_send_json_success(
                    array(
                        'content'	=> $output,
                    )
                );

            }
        }

        /*
        * Nexter Google Recaptcha
        * @since 1.1.0
        */
        public function nexter_ext_google_recaptcha() {
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-ext' ),
                    )
                );
            }
            $ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
            $option = get_option( 'nexter_extra_ext_options' );
            $reoption = ( isset( $option['google-recaptcha'] ) && !empty($option['google-recaptcha']) && isset( $option['google-recaptcha']['values'] ) &&  !empty( $option['google-recaptcha']['values'] ) ) ? $option['google-recaptcha']['values'] : '' ;

            $output = '';
            if( !empty( $ext ) && $ext == 'google-recaptcha' ){
                $output .= '<div class="nxt-ext-modal-content">';
                    $output .= '<div class="nxt-modal-title-wrap">';
                        $output .= '<div class="nxt-modal-title">'.esc_html__( 'GOOGLE reCAPTCHA', 'nexter-ext' ).'</div>';
                        //$output .= '<div class="nxt-modal-desc">'.esc_html__( 'Serve your chosen Google Fonts from your own web server. This will increase the loading speed and makes sure your website complies with the privacy regulations.', 'nexter-ext' ).'</div>';
                    $output .= '</div>';
                    $output .= '<div class="nxt-recaptch-wrap">';
                        $output .= '<div class="nxt-recaptch-inner">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'reCAPTCHA Version', 'nexter-ext' ).'</label>';
                            $output .= '<ul class="nxt-check-list">';
                                foreach ( $this->nxtrecpVer as $version => $version_name ) {
                                    $output .= '<li>';
                                        $output .= '<input type="radio" id="'.$version.'" name="nexter_recaptcha_version" value="'.$version.'" '.( isset($reoption['version']) && !empty($reoption['version']) &&  $reoption['version'] == $version ? 'checked' : '' ).'  >';
                                        $output .= '<label for="'.$version.'">';
                                            $output .= $version_name['title'] ;
                                            $output .= '<span class="nxt-desc-icon" >';
                                                $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_attr($version_name['title']).'" /> ';
                                                $output .= '<div class="nxt-tooltip bottom">'.wp_kses_post($version_name['desc']).'</div>';
                                            $output .= '</span>';
                                        $output .= '</label>';
                                    $output .= '</li>';
                                    
                                }
                            $output .= '</ul>'; 
                        $output .= '</div>';
                        $output .= '<div class="nxt-recaptch-inner nxt-spce-bet">';
                            
                            $output .= '<div class="nxt-recaptch-field">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'Site Key', 'nexter-ext' );
                                $output .= '<span class="nxt-desc-icon" >';
                                    $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_html__( 'Site Key', 'nexter-ext' ).'" /> ';
                                    $output .= '<div class="nxt-tooltip right">';
                                        $output .= esc_html__( 'Copy your Site Key from your Google reCAPTCHA Account. ' , 'nexter-ext' );
                                        $output .= sprintf('<a href="'.esc_url('https://www.google.com/recaptcha/admin#list').'" target="_blank" rel="noopener noreferrer" style="color : #fff;" >'.esc_html__('Get Keys' , 'nexter-ext').'</a>');
                                    $output .= '</div>';
                                $output .= '</span>';
                            $output .= '</label>';
                            $output .= '<span class="dashicons dashicons-yes nxt-verify-icon '.( !isset($reoption['keyverify']) || true != $reoption['keyverify'] ? 'hidden' : '' ).' " ></span>';
                                $output .= '<input type="text" class="nxt-recap-input" name="nexter_re_public_key" placeholder="'.esc_html('Please Enter Site Key','nexter-ext').'"  value="'.( isset($reoption['siteKey']) && !empty($reoption['siteKey']) ? $reoption['siteKey'] : '' ).'" />';
                            $output .= '</div>'; 

                            $output .= '<div class="nxt-recaptch-field">';
                                $output .= '<label class="upload-font-label">'.esc_html__( 'Secret Key', 'nexter-ext' );
                                $output .= '<span class="nxt-desc-icon" >';
                                    $output .= '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_html__( 'Site Key', 'nexter-ext' ).'" /> ';
                                    $output .= '<div class="nxt-tooltip right">';
                                        $output .= esc_html__( 'Copy your Secret Key from your Google reCAPTCHA Account. ' , 'nexter-ext' );
                                        $output .= sprintf('<a href="'.esc_url('https://www.google.com/recaptcha/admin#list').'" target="_blank" rel="noopener noreferrer" style="color : #fff;" >'.esc_html__('Get Keys' , 'nexter-ext').'</a>');
                                    $output .= '</div>';
                                $output .= '</span>';
                                $output .= '</label>'; 
                                $output .= '<span class="dashicons dashicons-yes nxt-verify-icon '.( !isset($reoption['keyverify']) || true != $reoption['keyverify'] ? 'hidden' : '' ).' " ></span>';
                                $output .= '<input type="text" class="nxt-recap-input" name="nexter_re_private_key" placeholder="'.esc_html('Please Enter Secret Key','nexter-ext').'" value="'.( isset($reoption['secretKey']) && !empty($reoption['secretKey']) ? $reoption['secretKey'] : '' ).'" />';
                            $output .= '</div>';
                        $output .= '</div>'; 

                        if( ( isset($reoption['siteKey']) && !empty($reoption['siteKey']) ) && ( isset($reoption['secretKey']) && !empty($reoption['secretKey']) ) ){
                            $output .= '<div class="nxt-recaptch-inner">';
                                $output .= '<div class="nxt-recaptch-field">';
                                    $output .= '<button class="nxt-test-recaptch" id="nxtrecapver">';
                                        $output .= '<svg width="11" height="14" viewBox="0 0 11 14" stroke="white" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.25 6.56697C10.5833 6.75942 10.5833 7.24054 10.25 7.43299L1.25 12.6291C0.916666 12.8216 0.499999 12.581 0.499999 12.1961L0.5 1.80383C0.5 1.41893 0.916666 1.17836 1.25 1.37082L10.25 6.56697Z" /></svg>';
                                        $output .= esc_html__( 'Test reCAPTCHA', 'nexter-ext' );
                                    $output .=  '</button>';
                                    $output .= '<div class="nxtcptch-test-results"></div>';
                                $output .= '</div>'; 
                            $output .= '</div>';
                        }

                        $output .= '<div class="nxt-recaptch-inner">';
                            $output .= '<label class="upload-font-label">'.esc_html__( 'Enable reCAPTCHA for', 'nexter-ext' ).'</label>';
                            $output .= '<ul class="nxt-check-list">';
                                foreach ( $this->nxtrecpForm as $form => $form_name ) {
                                    $output .= '<li>';
                                        $output .= '<input type="checkbox" id="'.esc_attr($form).'" name="nexter_recaptcha_enable" value="'.esc_attr($form).'" '.( isset($reoption['formType']) && !empty($reoption['formType']) && in_array($form,$reoption['formType'])  ? 'checked' : '' ).' >';
                                        $output .= '<label for="'.esc_attr($form).'">'.esc_html( $form_name).'</label>';
                                    $output .= '</li>';
                                }
                            $output .= '</ul>'; 
                        $output .= '</div>';
                            
                        if( isset($reoption['version']) && !empty($reoption['version']) &&  $reoption['version'] == 'v2' ){
                            $output .= '<div class="nxt-recaptch-inner">';
                                $output .= '<label class="upload-font-label">'.esc_html__( 'reCaptcha Theme', 'nexter-ext' ).'</label>';
                                $output .= '<ul class="nxt-check-list">';
                                    foreach ( $this->recaptheme as $theme => $theme_name ) {
                                        $output .= '<li>';
                                            $output .= '<input type="radio" id="'.esc_attr($theme).'" name="nexter_recaptcha_theme" value="'.esc_attr($theme).'" '.( isset($reoption['recapTheme']) && !empty($reoption['recapTheme']) &&  $reoption['recapTheme'] == $theme ? 'checked' : ( isset($reoption['recapTheme']) && empty($reoption['recapTheme']) && $theme == 'light' ? 'checked' : ''  ) ).' >';
                                            $output .= '<label for="'.esc_attr($theme).'">'.esc_html( $theme_name).'</label>';
                                        $output .= '</li>';
                                        
                                    }
                                $output .= '</ul>'; 
                            $output .= '</div>';
                        }

                        if( isset( $reoption['version'] ) && !empty( $reoption['version'] )  && ($reoption['version'] == 'v3' || $reoption['version'] == 'invisible' )){
                            $output .= '<div class="nxt-recaptch-inner">';
                                $output .= '<div class="nxt-recaptch-field">';
                                    $output .= '<label class="nxt-hide-recap">';
                                        $output .= '<input type="checkbox" name="nexter_recaptcha_invisi" value="1"'.( isset($reoption['invisi']) && !empty($reoption['invisi'])  ? 'checked' : '' ).' >';
                                        $output .= '<span class="nxt-recap-desc">';
                                            $output .= '<label class="upload-font-label">'.esc_html__( 'Hide reCaptcha Badge', 'nexter-ext' ).'</label>';
                                            $output .= '<span>'.esc_html__( 'Enable to hide reCAPTCHA Badge for version 3 and invisible reCAPTCHA.', 'nexter-ext' ). '</span>' ;
                                        $output .= '</span>';
                                    $output .= '</label>';
                                $output .= '</div>';
                            $output .= '</div>';
                        }
                    $output .= '</div>'; 
                    $output .= '<button type="button" class="nxt-recaptcha-save"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" ><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html( 'Save', 'nexter-ext' ).'</button>';
                $output .= '</div>'; 

                wp_send_json_success(
                    array(
                        'content'	=> $output,
                    )
                );
            }
            wp_send_json_error();
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
    }
}

Nexter_Ext_Panel_Settings::get_instance();
