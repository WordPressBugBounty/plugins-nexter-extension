<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Nexter_Builder_Condition' ) ) {

	class Nexter_Builder_Condition {

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 * Initiator
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
			if( is_admin() ){
                if ( current_user_can( 'manage_options' ) ) {
		            add_action( 'wp_ajax_nexter_ext_temp_popup', [ $this, 'nexter_ext_temp_popup_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_sections_condition_popup', [ $this, 'nexter_ext_sections_condition_popup_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_pages_condition_popup', [ $this, 'nexter_ext_pages_condition_popup_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_pages_404_condition_popup', [ $this, 'nexter_ext_pages_404_condition_popup_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_status', [ $this, 'nexter_ext_status_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_repeater_custom_structure', [ $this, 'nexter_ext_repeater_custom_structure_ajax'] );
                    add_action('wp_ajax_nexter_ext_edit_template',[$this,'nexter_ext_edit_template_form_data']);
                    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ), 1 );

                    add_action('admin_post_nexter_ext_save_template',[$this,'nexter_ext_add_template_form_data']);
                    add_action('admin_post_nopriv_nexter_ext_save_template',[$this,'nexter_ext_add_template_form_data']);
                }
            }
		}

        function nexter_ext_add_template_form_data() {
            $nonce = (isset($_POST['nonce']) && !empty($_POST['nonce'])) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
            if (empty($nonce) || !wp_verify_nonce($nonce, 'nxt-builder')) {
                wp_die(esc_html__('Nonce verification failed', 'nexter-extension'));
            }
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_die(esc_html__('You do not have permission to perform this action', 'nexter-extension'));
            }

            $template_name = (isset($_POST['template_name'])) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : 'Nexter Builder';
            $template_type = (isset($_POST['nxt-hooks-layout_sections']) && !empty($_POST['nxt-hooks-layout_sections'])) ? sanitize_text_field(wp_unslash($_POST['nxt-hooks-layout_sections'])) : 'header';
            if (!empty($template_name) && !empty($template_type)) {
                $template_id = wp_insert_post(array(
                    'post_title'  => $template_name,
                    'post_type'   => 'nxt_builder',
                    'post_status' => 'draft',
                    'meta_input'  => array('template_type' => $template_type)
                ));
                
                if (!empty($template_id)) {
                    add_post_meta($template_id, 'nxt-hooks-layout-sections', $template_type, false);
                    add_post_meta($template_id , 'nxt_build_status',"1", false);
                    if( $template_type == 'header' ){
                        $header_type = (isset($_POST['nxt-normal-sticky-header']) && !empty($_POST['nxt-normal-sticky-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-normal-sticky-header']) ) : '';
                        add_post_meta($template_id, 'nxt-normal-sticky-header', $header_type, false);
                        $trans_header = (isset($_POST['nxt-transparent-header']) && !empty($_POST['nxt-transparent-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-transparent-header']) ) : '';
                        if(!empty($trans_header)){
                            add_post_meta($template_id, 'nxt-transparent-header', $trans_header, false);
                        }
                    }
                    if( $template_type == 'footer' ){
                        $footer_style = (isset($_POST['nxt-hooks-footer-style']) && !empty($_POST['nxt-hooks-footer-style'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-style']) ) : '';
                        if(!empty($footer_style)){
                            add_post_meta($template_id, 'nxt-hooks-footer-style', $footer_style, false);
                        }
                        $footer_bg = (isset($_POST['nxt-hooks-footer-smart-bgcolor']) && !empty($_POST['nxt-hooks-footer-smart-bgcolor'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-smart-bgcolor']) ) : '';
                        if(!empty($footer_bg)){
                            add_post_meta($template_id, 'nxt-hooks-footer-smart-bgcolor', $footer_bg, false);
                        }
                    }
                    if( $template_type == 'hooks' ){
                        $hooks_action = (isset($_POST['nxt-display-hooks-action']) && !empty($_POST['nxt-display-hooks-action'])) ? sanitize_text_field( wp_unslash($_POST['nxt-display-hooks-action']) ) : '';
                        if(!empty($hooks_action)){
                            add_post_meta($template_id, 'nxt-display-hooks-action', $hooks_action, false);
                        }

                        $hooks_priority = (isset($_POST['nxt-hooks-priority']) && !empty($_POST['nxt-hooks-priority'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-priority']) ) : '';
                        if(!empty($hooks_action)){
                            add_post_meta($template_id, 'nxt-hooks-priority', $hooks_priority, false);
                        }
                    }

                    if($template_type == 'page-404'){
                        $dis_header = (isset($_POST['nxt-404-disable-header']) && !empty($_POST['nxt-404-disable-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-404-disable-header']) ) : '';
                        if(!empty( $dis_header)){
                            add_post_meta($template_id, 'nxt-404-disable-header', $dis_header, false);
                        }
                        $dis_footer = (isset($_POST['nxt-404-disable-footer']) && !empty($_POST['nxt-404-disable-footer'])) ? sanitize_text_field( wp_unslash($_POST['nxt-404-disable-footer']) ) : '';
                        if(!empty( $dis_footer)){
                            add_post_meta($template_id, 'nxt-404-disable-footer', $dis_footer, false);
                        }
                    }

                    if($template_type == 'singular'){
                        $nxt_singular_group = (isset($_POST['nxt-singular-group'])) ? map_deep(wp_unslash($_POST['nxt-singular-group']), 'sanitize_text_field') : [];
                        if(!empty($nxt_singular_group) && is_array($nxt_singular_group)){
                            // Iterate over the array and sanitize each value
                            foreach ($nxt_singular_group as $key => $group) {
                                if (is_array($group)) {
                                    $nxt_singular_group[$key]['nxt-singular-include-exclude'] = isset($group['nxt-singular-include-exclude']) ? sanitize_text_field($group['nxt-singular-include-exclude']) : '';

                                    $nxt_singular_group[$key]['nxt-singular-conditional-rule'] = isset($group['nxt-singular-conditional-rule']) ? sanitize_text_field($group['nxt-singular-conditional-rule']) : '';

                                    $nxt_singular_group[$key]['nxt-singular-conditional-type'] = isset($group['nxt-singular-conditional-type']) && is_array($group['nxt-singular-conditional-type']) ? array_map('sanitize_text_field', $group['nxt-singular-conditional-type']) : [];
                                }                
                            }
                            // Add the meta value to the post
                            add_post_meta($template_id, 'nxt-singular-group', $nxt_singular_group, false);
                    
                            // Optionally handle other fields
                            $nxt_singular_preview_type = (isset($_POST['nxt-singular-preview-type'])) ? sanitize_text_field( wp_unslash($_POST['nxt-singular-preview-type']) ) : '';
                            if(isset($nxt_singular_preview_type)) {
                                add_post_meta($template_id, 'nxt-singular-preview-type', $nxt_singular_preview_type, false);
                            }

                            $nxt_singular_preview_id = (isset($_POST['nxt-singular-preview-id'])) ? sanitize_text_field( wp_unslash($_POST['nxt-singular-preview-id']) ) : '';
                            if (isset($nxt_singular_preview_id)) {
                                add_post_meta($template_id, 'nxt-singular-preview-id', $nxt_singular_preview_id, false);
                            }
                        }
                    }

                    if($template_type == 'archives'){
                        $nxt_archive_group = (isset($_POST['nxt-archive-group'])) ? map_deep(wp_unslash($_POST['nxt-archive-group']), 'sanitize_text_field') : [];
                        if(!empty($nxt_archive_group) && is_array($nxt_archive_group)){
                            // Iterate over the array and sanitize each value
                            foreach ($nxt_archive_group as $key => $group) {
                                $nxt_archive_group[$key]['nxt-archive-include-exclude'] = (isset($group['nxt-archive-include-exclude'])) ? sanitize_text_field( wp_unslash($group['nxt-archive-include-exclude']) ) : '';
                                $nxt_archive_group[$key]['nxt-archive-conditional-rule'] = (isset($group['nxt-archive-conditional-rule'])) ? sanitize_text_field( wp_unslash($group['nxt-archive-conditional-rule']) ) : '';

                                $nxt_archive_group[$key]['nxt-archive-conditional-type'] = isset($group['nxt-archive-conditional-type']) && is_array($group['nxt-archive-conditional-type']) ? array_map('sanitize_text_field', $group['nxt-archive-conditional-type']) : [];
                            }
                            // Add the meta value to the post
                            add_post_meta($template_id, 'nxt-archive-group', $nxt_archive_group, false);
                    
                            // Optionally handle other fields
                            $nxt_archive_preview_type = (isset($_POST['nxt-archive-preview-type'])) ? sanitize_text_field( wp_unslash($_POST['nxt-archive-preview-type']) ) : '';
                            if (isset($nxt_archive_preview_type)) {
                                add_post_meta($template_id, 'nxt-archive-preview-type', $nxt_archive_preview_type, false);
                            }

                            $nxt_archive_preview_id = (isset($_POST['nxt-archive-preview-id'])) ? sanitize_text_field( wp_unslash($_POST['nxt-archive-preview-id']) ) : '';
                            if (isset($nxt_archive_preview_id)) {
                                add_post_meta($template_id, 'nxt-archive-preview-id', $nxt_archive_preview_id, false);
                            }
                        }
                    }
                    
                    if($template_type != 'section'){
                        $include_set = isset($_POST['nxt-add-display-rule']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-add-display-rule'])) : [];
                        $exclude_set = isset($_POST['nxt-exclude-display-rule']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-exclude-display-rule'])) : [];
                        $include_specific = isset($_POST['nxt-hooks-layout-specific']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-hooks-layout-specific'])) : [];
                        $exclude_specific = isset($_POST['nxt-hooks-layout-exclude-specific']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-hooks-layout-exclude-specific'])) : [];
                        
                        if(!empty($include_set)){
                            add_post_meta($template_id, 'nxt-add-display-rule', $include_set, false);
                            self::add_edit_meta_for_rules($template_id, $include_set, '', 'new');
                        }
                        if(!empty($exclude_set)){
                            add_post_meta($template_id, 'nxt-exclude-display-rule', $exclude_set, false);
                            self::add_edit_meta_for_rules($template_id, $exclude_set, 'exclude-', 'new');
                        }

                        if(!empty($include_specific)){
                            add_post_meta($template_id, 'nxt-hooks-layout-specific', $include_specific, false);
                        }
                        if(!empty($exclude_specific)){
                            add_post_meta($template_id, 'nxt-hooks-layout-exclude-specific', $exclude_specific, false);
                        }
                    }
                    // Redirect to the edit page
                    wp_redirect(admin_url('post.php?post=' . $template_id . '&action=edit'));
                    exit;
                }
            }
        } 

        function nexter_ext_edit_template_form_data() {
            $nonce = (isset($_POST['nonce']) && !empty($_POST['nonce'])) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
            if (!isset($nonce) || !wp_verify_nonce($nonce, 'nxt-builder')) {
                wp_die(esc_html__('Nonce verification failed', 'nexter-extension'));
            }
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_die(esc_html__('You do not have permission to perform this action', 'nexter-extension'));
            }

            $template_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : '';
            if (isset($template_id) && !empty($template_id)) {
                // Gather form data
                $form_data = [
                    'nxt-404-disable-header' => (isset($_POST['nxt-404-disable-header']) && !empty($_POST['nxt-404-disable-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-404-disable-header']) ) : '',
                    'nxt-404-disable-footer' => (isset($_POST['nxt-404-disable-footer']) && !empty($_POST['nxt-404-disable-footer'])) ? sanitize_text_field( wp_unslash($_POST['nxt-404-disable-footer']) ) : '',
                    'nxt-add-display-rule' => isset($_POST['nxt-add-display-rule']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-add-display-rule'])) : [],
                    'nxt-exclude-display-rule' => isset($_POST['nxt-exclude-display-rule']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-exclude-display-rule'])) : [],
                    'nxt-singular-group' => (isset($_POST['nxt-singular-group'])) ? map_deep(wp_unslash($_POST['nxt-singular-group']), 'sanitize_text_field') : [],
                    'nxt-singular-preview-type' => (isset($_POST['nxt-singular-preview-type'])) ? sanitize_text_field( wp_unslash($_POST['nxt-singular-preview-type']) ) : '',
                    'nxt-singular-preview-id' => (isset($_POST['nxt-singular-preview-id'])) ? sanitize_text_field( wp_unslash($_POST['nxt-singular-preview-id']) ) : '',
                    'nxt-archive-group' => (isset($_POST['nxt-archive-group'])) ? map_deep(wp_unslash($_POST['nxt-archive-group']), 'sanitize_text_field') : [],
                    'nxt-archive-preview-type' => (isset($_POST['nxt-archive-preview-type'])) ? sanitize_text_field( wp_unslash($_POST['nxt-archive-preview-type']) ) : '',
                    'nxt-archive-preview-id' => (isset($_POST['nxt-archive-preview-id'])) ? sanitize_text_field( wp_unslash($_POST['nxt-archive-preview-id']) ) : '',
                    'nxt-hooks-layout-specific' => isset($_POST['nxt-hooks-layout-specific']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-hooks-layout-specific'])) : [],
                    'nxt-hooks-layout-exclude-specific' => isset($_POST['nxt-hooks-layout-exclude-specific']) ? array_map('sanitize_text_field', wp_unslash($_POST['nxt-hooks-layout-exclude-specific'])) : []
                ];
        
                if(isset($_POST['nxt-normal-sticky-header'])){
                    $form_data['nxt-normal-sticky-header'] = (isset($_POST['nxt-normal-sticky-header']) && !empty($_POST['nxt-normal-sticky-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-normal-sticky-header']) ) : '';
                }
                if(isset($_POST['nxt-transparent-header'])){
                    $form_data['nxt-transparent-header'] = (isset($_POST['nxt-transparent-header']) && !empty($_POST['nxt-transparent-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-transparent-header']) ) : '';
                }
                if(isset($_POST['nxt-hooks-footer-style'])){
                    $form_data['nxt-hooks-footer-style'] = (isset($_POST['nxt-hooks-footer-style']) && !empty($_POST['nxt-hooks-footer-style'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-style']) ) : 'normal';
                }
                if(isset($_POST['nxt-hooks-footer-smart-bgcolor'])){
                    $form_data['nxt-hooks-footer-smart-bgcolor'] = (isset($_POST['nxt-hooks-footer-smart-bgcolor']) && !empty($_POST['nxt-hooks-footer-smart-bgcolor'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-smart-bgcolor']) ) : '';
                }
                if(isset($_POST['nxt-display-hooks-action'])){
                    $form_data['nxt-display-hooks-action'] = (isset($_POST['nxt-display-hooks-action']) && !empty($_POST['nxt-display-hooks-action'])) ? sanitize_text_field( wp_unslash($_POST['nxt-display-hooks-action']) ) : '';
                }
                if(isset($_POST['nxt-hooks-priority'])){
                    $form_data['nxt-hooks-priority'] = (isset($_POST['nxt-hooks-priority']) && !empty($_POST['nxt-hooks-priority'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-priority']) ) : '';
                }

                //Existing Entry Rewamp
                /* $old_layout = get_post_meta($template_id, 'nxt-hooks-layout', true);
                if(!empty($old_layout) && $old_layout!='none'){
                    $sections_pages = get_post_meta($template_id, 'nxt-hooks-layout-pages', true);
                    if(!empty($sections_pages) && $sections_pages!='none'){
                        update_post_meta($template_id, 'nxt-hooks-layout-sections', $sections_pages);
                    }
                } */

                // Update or delete post meta
                foreach ($form_data as $meta_key => $value) {
                    if (!empty($value)) {
                        if (metadata_exists('post', $template_id, $meta_key)) {
                            update_post_meta($template_id, $meta_key, $value);
                        } else {
                            add_post_meta($template_id, $meta_key, $value);
                        }
                    } else {
                        delete_post_meta($template_id, $meta_key);
                    }
                }
        
                // Update singular and archive groups with sanitized values
                $groups = ['nxt-singular-group', 'nxt-archive-group'];
                foreach ($groups as $group_key) {
                    if (!empty($form_data[$group_key])) {
                        foreach ($form_data[$group_key] as &$group) {
                            $group['nxt-singular-include-exclude'] = sanitize_text_field($group['nxt-singular-include-exclude'] ?? '');
                            $group['nxt-singular-conditional-rule'] = sanitize_text_field($group['nxt-singular-conditional-rule'] ?? '');
                            if (isset($group['nxt-singular-conditional-type'])) {
                                $group['nxt-singular-conditional-type'] = array_map('sanitize_text_field', $group['nxt-singular-conditional-type']);
                            }
                        }
                        update_post_meta($template_id, $group_key, $form_data[$group_key]);
                    }
                }
        
                // Update template name if provided
                $template_name = (isset($_POST['template_name'])) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
                if (!empty($template_name)) {
                    wp_update_post(['ID' => $template_id, 'post_title' => $template_name]);
                }
        
                // Handle include/exclude rules
                if (!empty($form_data['nxt-add-display-rule'])) {
                    self::add_edit_meta_for_rules($template_id, $form_data['nxt-add-display-rule'], '', 'edit');
                }
                if (!empty($form_data['nxt-exclude-display-rule'])) {
                    self::add_edit_meta_for_rules($template_id, $form_data['nxt-exclude-display-rule'], 'exclude-', 'edit');
                }

                $cache_option = 'nxt-build-get-data';
                $get_data = get_option($cache_option);
				if( $get_data === false ){
					$value = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
					add_option( $cache_option, $value );
				}else if( !empty($get_data) ){
					$get_data['saved'] = strtotime('now');
					update_option( $cache_option, $get_data, false );
				}

                wp_send_json_success();
            }
        }
        
        public function add_edit_meta_for_rules($template_id, $rules, $prefix, $type) {
            $nonce = (isset($_POST['nonce']) && !empty($_POST['nonce'])) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
            if (empty($nonce) || !wp_verify_nonce($nonce, 'nxt-builder')) {
                wp_die(esc_html__('Nonce verification failed', 'nexter-extension'));
            }
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_die(esc_html__('You do not have permission to perform this action', 'nexter-extension'));
            }

            $fields = [
                'set-day' => "nxt-hooks-layout-{$prefix}set-day",
                'os' => "nxt-hooks-layout-{$prefix}os",
                'browser' => "nxt-hooks-layout-{$prefix}browser",
                'login-status' => "nxt-hooks-layout-{$prefix}login-status",
                'user-roles' => "nxt-hooks-layout-{$prefix}user-roles"
            ];
        
            foreach ($fields as $key => $meta_key) {
                $value = isset($_POST[$meta_key]) ? array_map('sanitize_text_field', wp_unslash($_POST[$meta_key])) : [];
                if (in_array($key, $rules) && !empty($value)) {
                    update_post_meta($template_id, $meta_key, $value);
                } elseif ($type == 'edit' && empty($value)) {
                    delete_post_meta($template_id, $meta_key);
                }
            }
        }
        


        /**
         * Nexter Builder Save Warning Popup
         * Start
         */
        public function nexter_close_warning_popup(){
            $output = '';
            $output .= '<div class="nxt-close-warning-popup">';
                $output .= '<div class="warning-popup-inner">';
                    $output .= '<img src="'.esc_url(NEXTER_EXT_URL . 'assets/images/nxt-close-warning.png').'" class="warning-popup-img" />';
                    $output .= '<h3 class="warning-popup-title">'.__( "Are you sure?", "nexter-extension" ).'</h3>';
                    $output .= '<p class="warning-popup-desc">'.__( "Leaving the changes without saving the data cause unsaved material", "nexter-extension" ).'</p>';
                    $output .= '<a class="popup-leave-btn">'.__( "Yes I want to leave", "nexter-extension" ).'</a>';
                $output .= '</div>';
            $output .= '</div>';

           return $output;
        }

        /**
         * Nexter Builder Save Warning Popup
         * End
         */

        /**
         * Nexter Builder Create Temp Popup
         * Start
        * */
        public function nexter_ext_temp_popup_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $hooks_action_list = class_exists('Nexter_Builder_Display_Conditional_Rules') ? Nexter_Builder_Display_Conditional_Rules::get_sections_hooks_options() : [];

            $sec_type = [
				'header' => __('Header', 'nexter-extension'),
				'footer' => __('Footer', 'nexter-extension'),
				'breadcrumb' => __('Breadcrumb', 'nexter-extension'),
				'hooks' => __('Hooks', 'nexter-extension'),
				'section' => __('Sections', 'nexter-extension'),
			];
            $page_type = [
				'singular' => __('Singular', 'nexter-extension'),
				'archives' => __('Archives', 'nexter-extension'),
				'page-404' => __('404 Page', 'nexter-extension'),
			];

            $header_type = [
				'normal' => __('Normal', 'nexter-extension'),
				'sticky' => __('Sticky', 'nexter-extension'),
				'both' => __('Normal + Sticky', 'nexter-extension'),
			];

            $footer_type = [
				'normal' => __('Normal', 'nexter-extension'),
				'fixed' => __('Fixed', 'nexter-extension'),
				'smart' => __('Zoom Out Effect', 'nexter-extension'),
			];

            $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : '';

            $title = $disableSel = $selectedLayout = $footerStyle = $headerType = $headerTrans = $bgColor = $hooksActions = $hooksPriority = '';
            if(!empty($post_id)){
                $title = get_the_title($post_id);
                $disableSel = 'disabled';

                $oldLayout = get_post_meta($post_id, 'nxt-hooks-layout', true);
                if( !empty($oldLayout) && $oldLayout == 'sections'){
                    $selectedLayout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                }else if(!empty($oldLayout) && $oldLayout == 'pages'){
                    $selectedLayout = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
                }else{
                    $selectedLayout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                }

                $footerStyle = get_post_meta($post_id, 'nxt-hooks-footer-style', true);
                $headerType = get_post_meta($post_id, 'nxt-normal-sticky-header', true);
                $headerTrans = get_post_meta($post_id, 'nxt-transparent-header', true);
                $bgColor = get_post_meta($post_id, 'nxt-hooks-footer-smart-bgcolor', true);
                $hooksActions = get_post_meta( $post_id, 'nxt-display-hooks-action', true );
                $hooksPriority = get_post_meta( $post_id, 'nxt-hooks-priority', true );
            }

            $footerClass = (!empty($footerStyle) && $footerStyle == 'smart') ? 'visible' : '';
            $checkornot = (!empty($headerTrans) && $headerTrans == 'on') ? 'checked' : '';

            $type = $subtype ='';

            $accActive = '';
            if(!empty($selectedLayout) && ($selectedLayout == 'header' || $selectedLayout == 'footer' || $selectedLayout == 'hooks')){
                $accActive = 'visible';
            }

            $theme_comp_option = true;
            if(!defined('NXT_VERSION')){
                $theme_comp_option = false;
                if(!empty($selectedLayout) && ($selectedLayout == 'header' || $selectedLayout == 'footer')){
                    $accActive = '';
                }
            }

            $output = '';
            $output .= '<div class="nxt-bul-temp">';
                $output .= '<div class="nxt-temp-heading">';
                    $output .= '<h3 class="temp-head-title">'.__( "Create your New Templates", "nexter-extension" ).'</h3>';
                    $output .= '<p class="temp-head-desc">'.__( "Start by selecting the template type & post source.", "nexter-extension" ).'</p>';
                $output .= '</div>';
                // $output .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                $output .= '<form method="post">';
                    $output .= '<div class="nxt-common-cnt-wrap nxt-temp-details">';
                        $output .= '<div class="nxt-common-cnt-inner">';
                            $output .= '<div>';
                                // $output .= ' <input type="hidden" name="action" value="nexter_ext_save_template">';
                                $output .= '<label>'.__( "Select Template", "nexter-extension" ).'</label>';
                                $output .= '<select class="nxt-temp-select nxt-temp-layout" name="nxt-hooks-layout_sections" '.$disableSel.'>';
                                    $output .= '<option value="" disabled selected>'.__( "Select Type", "nexter-extension" ).'</option>';
                                    $output .= '<optgroup label="Layouts">';
                                        foreach ($sec_type as $index => $label) :
                                            $selected = '';
                                            if(!empty($selectedLayout) && $index == $selectedLayout){
                                                $selected = 'selected';
                                                $type = 'sections';
                                                $subtype = $index;
                                            }
                                            $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;

                                    $output .= '</optgroup>';
                                    $output .= '<optgroup label="Pages">';
                                        foreach ($page_type as $index => $label) :
                                            $selected = '';
                                            if(!empty($selectedLayout) && $index == $selectedLayout){
                                                $selected = 'selected';
                                                $type = 'pages';
                                                $subtype = $index;
                                            }
                                            $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;
                                    $output .= '</optgroup>';
                                $output .= '</select>';
                                if( !empty($disableSel) && $disableSel == 'disabled'){
                                    $output .= '<input type="hidden" name="nxt-hooks-layout_sections" value="'.esc_attr($selectedLayout).'">';
                                }
                            $output .= '</div>';
                            $output .= '<div>';
                                $output .= '<label>'.__( "Name of Template", "nexter-extension" ).'</label>';
                                $output .= '<input name="template_name" class="nxt-temp-name" placeholder="'.__( "Enter Template Name", "nexter-extension" ).'" required value="'.esc_attr($title).'"/>';
                            $output .= '</div>';

                            if($theme_comp_option){
                                $output .= '<div class="nxt-header-type-wrap">';
                                    $output .= '<label>'.__( "Type", "nexter-extension" ).'</label>';
                                    $output .= '<select class="nxt-temp-select nxt-header-type" id="nxt-normal-sticky-header" name="nxt-normal-sticky-header">';
                                        foreach ($header_type as $index => $label) :
                                            $selected = '';
                                            if(!empty($headerType) && $index == $headerType){
                                                $selected = 'selected';
                                            }
                                            $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;
                                    $output .= '</select>';
                                    $output .= '<div class="nxt-trans-header-wrap">';
                                        $output .= '<label>'.__( "Transparent Header", "nexter-extension" ).'</label>';
                                        $output .= '<div class="nxt-trans-header-inner">';
                                            $output .= '<input type="checkbox" class="nxt-trans-header" name="nxt-transparent-header" id="nxt-transparent-header" value="on" '.$checkornot.'>';
                                            $output .= '<label for="nxt-transparent-header"></label>';
                                        $output .= '</div>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }
                            if($theme_comp_option){
                                $output .= '<div class="nxt-footer-type-wrap">';
                                    $output .= '<div class="nxt-footer-type-inner">';
                                        $output .= '<label>'.__( "Footer Effects", "nexter-extension" ).'</label>';
                                        $output .= '<select class="nxt-temp-select nxt-footer-type" id="nxt-hooks-footer-style" name="nxt-hooks-footer-style">';
                                            foreach ($footer_type as $index => $label) :
                                                $selected = '';
                                                if(!empty($footerStyle) && $index == $footerStyle){
                                                    $selected = 'selected';
                                                }
                                                $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                            endforeach;
                                        $output .= '</select>';
                                    $output .= '</div>';
                                    $output .= '<div class="nxt-footer-smart-bgcolor '.esc_attr($footerClass).'">';
                                        // $output .= '<span class="nxt-color-preview"></span>';
                                        $output .= '<input type="text" id="nxt-hooks-footer-smart-bgcolor" name="nxt-hooks-footer-smart-bgcolor" value="'.esc_attr($bgColor).'"/>';
                                        $output .= '<span class="nxt-footer-picker-icon">';
                                            $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 12 13" fill="none"><path d="M12.0002 2.95534C11.9998 2.46962 11.8555 1.99491 11.5855 1.59117C11.3155 1.18742 10.9319 0.872766 10.4831 0.686939C10.0343 0.501113 9.54056 0.452456 9.06416 0.547115C8.58775 0.641774 8.15009 0.875503 7.80646 1.21878L6.23758 2.78766C5.90479 2.60737 5.52318 2.53791 5.14821 2.58935C4.77324 2.64079 4.42444 2.81046 4.1525 3.0737C3.82613 3.40027 3.6428 3.84306 3.6428 4.30475C3.6428 4.76644 3.82613 5.20924 4.1525 5.5358L4.27086 5.65416L0.610323 9.3147C0.534181 9.39143 0.482174 9.48878 0.460723 9.59472L0.0108274 11.8442C-0.00542624 11.9238 -0.00376571 12.0061 0.0156886 12.085C0.0351429 12.1639 0.071904 12.2375 0.123309 12.3004C0.174714 12.3634 0.239475 12.4141 0.312903 12.4489C0.38633 12.4837 0.466584 12.5018 0.547853 12.5018C0.584674 12.5021 0.621424 12.4984 0.65745 12.4908L2.90419 12.0415C3.01031 12.0202 3.10774 11.9679 3.18421 11.8913L6.84475 8.23024L6.96311 8.34915C7.2609 8.64563 7.65584 8.82444 8.07511 8.8526C8.49437 8.88076 8.90968 8.75637 9.24444 8.50238C9.5792 8.24839 9.81084 7.88192 9.89663 7.47057C9.98242 7.05921 9.91658 6.6307 9.71126 6.26407L11.2801 4.69519C11.5095 4.46737 11.6912 4.19624 11.8148 3.89755C11.9384 3.59887 12.0014 3.27859 12.0002 2.95534ZM2.52608 10.997L1.24818 11.2507L1.50409 9.97064L5.04516 6.42847L6.07044 7.4532L2.52608 10.997ZM10.5053 3.9176L8.59391 5.82897C8.49135 5.93171 8.43375 6.07095 8.43375 6.21612C8.43375 6.3613 8.49135 6.50053 8.59391 6.60327L8.64871 6.65807C8.76945 6.77909 8.83725 6.94305 8.83725 7.114C8.83725 7.28494 8.76945 7.4489 8.64871 7.56992C8.52581 7.68704 8.36256 7.75237 8.19279 7.75237C8.02302 7.75237 7.85976 7.68704 7.73687 7.56992L4.9257 4.75821C4.80486 4.63729 4.737 4.47332 4.73705 4.30237C4.7371 4.13141 4.80506 3.96748 4.92598 3.84664C5.0469 3.72579 5.21087 3.65793 5.38182 3.65798C5.55277 3.65803 5.7167 3.72599 5.83755 3.84691L5.89235 3.90171C5.99511 4.00444 6.13447 4.06215 6.27977 4.06215C6.42508 4.06215 6.56444 4.00444 6.6672 3.90171L8.57857 1.99034C8.70412 1.86051 8.85425 1.75698 9.02023 1.68578C9.1862 1.61458 9.36468 1.57713 9.54528 1.57562C9.72587 1.57411 9.90496 1.60856 10.0721 1.67697C10.2392 1.74539 10.3911 1.84639 10.5188 1.97409C10.6465 2.1018 10.7475 2.25365 10.8159 2.42079C10.8843 2.58793 10.9188 2.76701 10.9173 2.94761C10.9158 3.1282 10.8783 3.30669 10.8071 3.47266C10.7359 3.63863 10.6324 3.78877 10.5025 3.91431L10.5053 3.9176Z" fill="#040404"/></svg>';
                                        $output .= '</span>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            }

                            $output .= '<div class="nxt-hooks-type-wrap">';
                                $output .= '<div class="nxt-hooks-type-inner">';
                                    $output .= '<label>'.__( "Actions Hooks", "nexter-extension" ).'</label>';
                                    $output .= '<select class="nxt-temp-select nxt-hooks-action-type" id="nxt-display-hooks-action" name="nxt-display-hooks-action">';
                                        foreach ($hooks_action_list as $index => $label) :
                                            $selected = '';
                                            if(!empty($hooksActions) && $index == $hooksActions){
                                                $selected = 'selected';
                                            }
                                            $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;
                                    $output .= '</select>';
                                $output .= '</div>';
                                $output .= '<div class="nxt-hooks-type-inner">';
                                    $output .= '<label>'.__( "Priority", "nexter-extension" ).'</label>';
                                    $output .= '<input name="nxt-hooks-priority" class="nxt-hooks-priority" type="number" placeholder="'.__( "10", "nexter-extension" ).'" value="'.esc_attr($hooksPriority).'" max="100" min="1" step="1"/>';
                                $output .= '</div>';
                            $output .= '</div>';

                            $output .= '<div class="nxt-addition-toggle-wrap '.esc_attr($accActive).'">';
                                $output .= '<a class="nxt-addition-toggle">';
                                    $output .= '<span class="nxt-open active">'.__( "Additional Settings", "nexter-extension" ).'</span>';
                                    $output .= '<span class="nxt-close">'.__( "Close Settings", "nexter-extension" ).'</span>';
                                    $output .= '<p class="nxt-close nxt-header-note">'.__( "You can set your header effect from here or directly from your Navigation Menu block or widget.", "nexter-extension" ).'</p>';
                                $output .= '</a>';
                            $output .= '</div>';
                        $output .= '</div>';
                    $output .= '</div>';
                    $output .= '<div class="nxt-temp-action">';
                        $output .= '<a href="'.esc_url('https://nexterwp.com/help/nexter-theme/theme-builder-classic-theme/').'" class="nxt-temp-info" target="_blank" rel="noopener noreferrer">';
                            $output .= __( "Read How it Works", "nexter-extension" );
                        $output .= '</a>';

                        if(!empty($post_id)){
                            $output .= '<a type="submit" href="" class="temp-next-button" data-post="'.esc_attr($post_id).'" data-type="'.esc_attr($type).'" data-subtype="'.esc_attr($subtype).'">';
                                $output .= __( "Next", "nexter-extension" );
                            $output .= '</a>';
                        }else{
                            $output .= '<a type="submit" href="" class="temp-action-btn">';
                                $output .= __( "Next", "nexter-extension" );
                            $output .= '</a>';
                        }

                    $output .= '</div>';
                $output .= '</form>';
            $output .= '</div>';
            $output .= self::nexter_close_warning_popup();

            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
            wp_die();
        }
        /**
         * Nexter Builder Create Temp Popup
         * End
        * */

        /**
         * Nexter Builder Display Rules / Condition For Sections
         * Start
        */
        public function nexter_ext_sections_condition_popup_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content' => __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $getPost_Id = (isset($_POST['post_id']) && !empty($_POST['post_id'])) ? absint($_POST['post_id']) : '';

            $conType = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field( wp_unslash($_POST['type']) ) : 'edit';

            $btnText = ($conType == 'edit' || $conType == 'update') ?  __( 'Save', 'nexter-extension' ) :  __( 'Create', 'nexter-extension' );

            $add_Display = (!empty($getPost_Id)) ? get_post_meta($getPost_Id, 'nxt-add-display-rule', true) : [];
            $exclude_Display = (!empty($getPost_Id)) ? get_post_meta($getPost_Id, 'nxt-exclude-display-rule', true) : [];

            $hiddenAttr = [];
           
            if( $conType == 'new' || $conType == 'update' ){
                $layoutType =  (isset($_POST['nxt-hooks-layout_sections']) && !empty($_POST['nxt-hooks-layout_sections'])) ? sanitize_text_field(wp_unslash($_POST['nxt-hooks-layout_sections'])) : 'header';
                $tempName =  (isset($_POST['template_name']) && !empty($_POST['template_name'])) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : 'Nexter Builder';
                $hiddenAttr['nxt-hooks-layout_sections'] = $layoutType;
                $hiddenAttr['template_name'] = $tempName;
                if($layoutType == 'footer'){
                    $footerStyle = (isset($_POST['nxt-hooks-footer-style']) && !empty($_POST['nxt-hooks-footer-style'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-style']) ) : 'normal';
                    $hiddenAttr['nxt-hooks-footer-style'] = $footerStyle;

                    $footerBG = (isset($_POST['nxt-hooks-footer-smart-bgcolor']) && !empty($_POST['nxt-hooks-footer-smart-bgcolor'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-footer-smart-bgcolor']) ) : '#000';
                    $hiddenAttr['nxt-hooks-footer-smart-bgcolor'] = $footerBG;
                }
    
                if($layoutType == 'header'){
                    $nexterStyle = (isset($_POST['nxt-normal-sticky-header']) && !empty($_POST['nxt-normal-sticky-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-normal-sticky-header']) ) : 'normal';
                    $hiddenAttr['nxt-normal-sticky-header'] = $nexterStyle;
                    $nexterTrans = (isset($_POST['nxt-transparent-header']) && !empty($_POST['nxt-transparent-header'])) ? sanitize_text_field( wp_unslash($_POST['nxt-transparent-header']) ) : '';
                    $hiddenAttr['nxt-transparent-header'] = $nexterTrans;
                }

                if($layoutType == 'hooks'){
                    $hooks_action = (isset($_POST['nxt-display-hooks-action']) && !empty($_POST['nxt-display-hooks-action'])) ? sanitize_text_field( wp_unslash($_POST['nxt-display-hooks-action']) ) : '';
                    $hiddenAttr['nxt-display-hooks-action'] = $hooks_action;

                    $hooks_priority = (isset($_POST['nxt-hooks-priority']) && !empty($_POST['nxt-hooks-priority'])) ? sanitize_text_field( wp_unslash($_POST['nxt-hooks-priority']) ) : '';
                    $hiddenAttr['nxt-hooks-priority'] = $hooks_priority;
                }
            }

            $output = '';
            $output .= '<div class="nxt-bul-temp">';
                $output .= '<div class="nxt-temp-heading">';
                    $output .= '<h3 class="temp-head-title">'.__( "Set Display Conditions", "nexter-extension" ).'</h3>';
                    $output .= '<p class="temp-head-desc">'.__( "Select where you want to load the template", "nexter-extension" ).'</p>';
                $output .= '</div>';
                $output .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    if($conType == 'new'){
                        $output .= '<input type="hidden" name="action" value="nexter_ext_save_template">';
                    }else{
                        $output .= '<input type="hidden" name="action" value="nexter_ext_edit_template">';
                        $output .= '<input type="hidden" name="post_id" value="'.esc_attr($getPost_Id).'">';
                    }

                    $output .='<input type="hidden" name="nonce" value="'. esc_attr(wp_create_nonce("nxt-builder")).'" />';

                    $output .= '<div class="nxt-common-cnt-wrap nxt-condition-main-wrap">';
                        $output .= '<div class="nxt-common-cnt-inner">';
                            if(!empty($hiddenAttr)){
                                foreach ($hiddenAttr as $type => $label):
                                    $output .= '<input type="hidden" name="'.esc_attr($type).'" value="'.esc_attr($label).'">';
                                endforeach;
                            }
                            $output .= '<div class="nxt-condition-include">';
                                $output .= '<label class="nxt-main-label">'.__( "Include In", "nexter-extension" ).'</label>';
                                $output .= self::nxt_include_exclude_dis_rules('add', $add_Display, $conType);
                                $output .= self::nxt_include_exclude_value($getPost_Id, '', $conType);
                            $output .= '</div>';
                            $output .='<div class="nxt-include-exclude-sep"></div>';
                            $output .= '<div class="nxt-condition-exclude">';
                                $output .= '<label class="nxt-main-label">'.__( "Exclude From", "nexter-extension" ).'</label>';
                                $output .= self::nxt_include_exclude_dis_rules('exclude', $exclude_Display, $conType);
                                $output .= self::nxt_include_exclude_value($getPost_Id, 'exclude-', $conType);
                            $output .= '</div>';
                        $output .= '</div>';
                    $output .= '</div>';

                    $output .= '<div class="nxt-temp-action">';
                        $output .= '<a href="'.esc_url('https://nexterwp.com/help/nexter-theme/theme-builder-classic-theme/').'" class="nxt-temp-info" target="_blank" rel="noopener noreferrer">';
                            $output .= __( "Read How it Works", "nexter-extension" );
                        $output .= '</a>';
                        // $output .= '<button type="submit" class="temp-create-btn">'.$btnText.'</button>';
                        $output .= '<div class="nxt-action-btn-wrap">';
                            if(!empty($getPost_Id)){
                                $output .= '<a class="nxt-action-back">';
                                    $output .= __( "Go Back", "nexter-extension" );
                                $output .= '</a>';
                            }
                            
                            if($conType == 'new'){
                                $output .= '<button type="submit" class="temp-create-btn">';
                                    $output .= esc_html($btnText);
                                $output .= '</button>';
                            }else{
                                $output .= '<a class="temp-create-btn temp-edit-btn-save">';
                                    $output .= esc_html($btnText);
                                $output .= '</a>';
                            }
                        $output .= '</div>';
                    $output .= '</div>';

                $output .= '</form>';
            $output .= '</div>';
            $output .= self::nexter_close_warning_popup();
            // $output = $getPost_Id;


            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
            wp_die();
        }
        /**
         * Nexter Builder Display Rules / Condition Sections
         * End
        */

        /**
         * Nexter Builder Display Rules / Condition Pages 
         * Start
        */
        public function nexter_ext_pages_condition_popup_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $conType = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field( wp_unslash($_POST['type']) ) : 'edit';

            $btnText = ($conType == 'edit' || $conType == 'update') ?  __( 'Save', 'nexter-extension' ) :  __( 'Create', 'nexter-extension' );

            $layoutType =  (isset($_POST['layout_type']) && !empty($_POST['layout_type'])) ? sanitize_text_field( wp_unslash($_POST['layout_type']) ) : 'singular';

            $getPost_Id = (isset($_POST['post_id']) && !empty($_POST['post_id'])) ? absint($_POST['post_id']) : '';

            $hiddenAttr = [];
           
            if( $conType == 'new' ){
                $layoutType =  (isset($_POST['nxt-hooks-layout_sections']) && !empty($_POST['nxt-hooks-layout_sections'])) ? sanitize_text_field(wp_unslash($_POST['nxt-hooks-layout_sections'])) : 'header';
                $tempName =  (isset($_POST['template_name']) && !empty($_POST['template_name'])) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : 'Nexter Builder';;
                $hiddenAttr['nxt-hooks-layout_sections'] = $layoutType;
                $hiddenAttr['template_name'] = $tempName;
            }
            
            $output = '';
            $output .= '<div class="nxt-bul-temp">';
                $output .= '<div class="nxt-temp-heading">';
                    $output .= '<h3 class="temp-head-title">'.__( "Set Display Conditions", "nexter-extension" ).'</h3>';
                    $output .= '<p class="temp-head-desc">'.__( "Select where you want to load the template", "nexter-extension" ).'</p>';
                $output .= '</div>';
                $output .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    if($conType == 'new'){
                        $output .= '<input type="hidden" name="action" value="nexter_ext_save_template">';
                    }else{
                        $output .= '<input type="hidden" name="action" value="nexter_ext_edit_template">';
                        $output .= '<input type="hidden" name="post_id" value="'.esc_attr($getPost_Id).'">';
                    }

                    $output .='<input type="hidden" name="nonce" value="'. esc_attr(wp_create_nonce("nxt-builder")).'" />';

                    $output .= '<div class="nxt-common-cnt-wrap nxt-condition-main-wrap">';
                        $output .= '<div class="nxt-common-cnt-inner">';
                            if(!empty($hiddenAttr)){
                                foreach ($hiddenAttr as $type => $label):
                                    $output .= '<input type="hidden" name="'.esc_attr($type).'" value="'.esc_attr($label).'">';
                                endforeach;
                            }
                            $output .= self::render_accordion_repeater_field($layoutType, $getPost_Id);
                            $output .= self::nxt_pages_preview_field($layoutType, $getPost_Id);
                        $output .= '</div>';
                    $output .= '</div>';

                    $output .= '<div class="nxt-temp-action">';
                        $output .= '<a href="'.esc_url('https://nexterwp.com/help/nexter-theme/theme-builder-classic-theme/').'" class="nxt-temp-info" target="_blank" rel="noopener noreferrer">';
                            $output .= __( "Read How it Works", "nexter-extension" );
                        $output .= '</a>';
                        // $output .= '<button type="submit" class="temp-create-btn">';
                        // $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 13 15" fill="none"><path d="M10.5 0.572266H2.50003C1.86885 0.572266 1.35718 1.08394 1.35718 1.71512V13.6001C1.35718 14.1603 2.07955 14.386 2.39843 13.9254L5.56039 9.35811C6.01485 8.70166 6.98522 8.70166 7.43968 9.35811L10.6016 13.9254C10.9205 14.386 11.6429 14.1603 11.6429 13.6001V1.71512C11.6429 1.08394 11.1312 0.572266 10.5 0.572266Z" stroke="white" stroke-linecap="round"/></svg>';
                        // $output .= esc_html($btnText);
                        // $output .= '</button>';
                        $output .= '<div class="nxt-action-btn-wrap">';
                            if(!empty($getPost_Id)){
                                $output .= '<a class="nxt-action-back">';
                                    $output .= __( "Go Back", "nexter-extension" );
                                $output .= '</a>';
                            }
                            
                            if($conType == 'new'){
                                $output .= '<button type="submit" class="temp-create-btn">';
                                    $output .= esc_html($btnText);
                                $output .= '</button>';
                            }else{
                                $output .= '<a class="temp-create-btn temp-edit-btn-save">';
                                    $output .= esc_html($btnText);
                                $output .= '</a>';
                            }
                        $output .= '</div>';
                    $output .= '</div>';

                $output .= '</form>';
            $output .= '</div>';
            $output .= self::nexter_close_warning_popup();

            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
            wp_die();

        }

        public function nxt_pages_preview_field($layoutType, $post_id ='') {
            $output = '';
            $output .= '<div class="nxt-pages-preview-wrap">';
                $output .= '<div class="nxt-pages-preview-inner">';
                    $output .= '<label>'.__( "Preview Type", "nexter-extension" ).'</label>';
                    if($layoutType == 'archives'){
                        $get_arc_prev = (!empty($post_id)) ? get_post_meta($post_id, 'nxt-archive-preview-type', true) : '';
                        $output .= self::nxt_generate_select_from_array(Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions('preview'), 'nxt-archive-preview-type', '', '', $get_arc_prev);
                    }else{
                        $get_sin_prev = (!empty($post_id)) ? get_post_meta($post_id, 'nxt-singular-preview-type', true) : '';
                        $output .= self::nxt_generate_select_from_array(Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions('preview'), 'nxt-singular-preview-type', '', '',  $get_sin_prev);
                    }
                $output .= '</div>';
                $output .= '<div class="nxt-pages-preview-inner">';
                    $output .= '<label>'.__( "Preview ID", "nexter-extension" ).'</label>';
                        if($layoutType == 'archives'){
                            $arc_pre_opt = self::nxt_get_type_archives_preview_id_new($post_id);
                            $arc_pre_id = get_post_meta($post_id, 'nxt-archive-preview-id', true);
                            $output .='<select class="nxt-archive-preview-id-select" name="nxt-archive-preview-id" id="nxt-archive-preview-id">';
                                foreach ($arc_pre_opt as $index => $label) :
                                    $selected = '';
                                    if(!empty($arc_pre_id) && $index == $arc_pre_id){
                                        $selected = 'selected';
                                    }
                                    $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                endforeach;
                            $output .='</select>';
                        }else{
                            $sin_pre_opt = self::nxt_get_type_singular_preview_id_new($post_id);
                            $sin_pre_id = get_post_meta($post_id, 'nxt-singular-preview-id', true);
                            $output .='<select class="nxt-singular-preview-id-select" name="nxt-singular-preview-id" id="nxt-singular-preview-id">';
                                foreach ($sin_pre_opt as $index => $label) :
                                    $selected = '';
                                    if(!empty($sin_pre_id) && $index == $sin_pre_id){
                                        $selected = 'selected';
                                    }
                                    $output .='<option value="'.esc_attr($index).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                endforeach;
                            $output .='</select>';

                        }
                $output .= '</div>';
            $output .= '</div>';

            return $output;
        }

        public function render_accordion_repeater_field($layoutType, $post_id = '') {
            $include_exclude = [
				'include' => __('Include', 'nexter-extension'),
				'exclude' => __('Exclude', 'nexter-extension')
			];

            $get_singular_group = [];
            $output ='<div id="accordion-container">';
                if($layoutType == 'archives'){
                    $get_archive_group = get_post_meta($post_id, 'nxt-archive-group', true);
                    $hide_remove_btn = '';
                    if( !empty($get_archive_group) && count($get_archive_group) == 1 ){
                        $hide_remove_btn = 'hide-remove-btn';
                    }
                    if(!empty($get_archive_group)){
                        foreach ($get_archive_group as $index => $ag) :
                            $get_sin_in_ex = $ag['nxt-archive-include-exclude'];
                            $output .='<div class="accordion-item" data-id="'.esc_attr($index).'">';
                                $output .='<div class="accordion-content">';
                                    $output .='<select name="nxt-archive-group['.esc_attr($index).'][nxt-archive-include-exclude]" id="nxt-archive-group_'.esc_attr($index).'_nxt-archive-include-exclude">';
                                        foreach ($include_exclude as $type => $label):
                                            $selected = '';
                                            if($type == $get_sin_in_ex){
                                                $selected = 'selected';
                                            }
                                            $output .='<option value="'.esc_attr($type).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;
                                    $output .='</select>';
                                    $get_arc_rule = $ag['nxt-archive-conditional-rule'];
                                    $output .= self::nxt_generate_select_from_array(Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions(), 'nxt-archive-group', $index, 'nxt-archive-conditional-rule', $get_arc_rule);

                                    $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-archive-group['.esc_attr($index).'][nxt-archive-conditional-type][]" id="nxt-archive-group_'.esc_attr($index).'_nxt-archive-conditional-type">';

                                        $archive_opt = self::nxt_get_type_archives_field_new("nxt-archive-group_".$index."_nxt-archive-conditional-type" , $post_id, $index);
                                        if(!empty($archive_opt)){
                                            foreach ($archive_opt as $ind => $ao) :
                                                $selected = '';
                                                if(!empty($get_archive_group) && !empty($get_archive_group[$index]['nxt-archive-conditional-type']) && in_array($ind, $get_archive_group[$index]['nxt-archive-conditional-type'])){
                                                    $selected = "selected";
                                                }
                                                $output .='<option value="'.esc_attr($ind).'" '.esc_attr($selected).'>'.esc_html($ao).'</option>';
                                            endforeach;
                                        }
                                    $output .='</select>';
                                $output .='</div>';
                                $output .='<div class="accordion-header '.esc_attr($hide_remove_btn).'">';
                                    $output .='<svg class="remove-accordion" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path class="remove-accordion-path" d="M6.38453 11.2306V6.92286M9.61574 11.2306V6.92286M1 3.69247H15M11.7695 14.4617H4.23106C3.94545 14.4617 3.67153 14.3482 3.46956 14.1463C3.2676 13.9443 3.15414 13.6704 3.15414 13.3848V3.69247H12.8464V13.3848C12.8464 13.6704 12.733 13.9443 12.531 14.1463C12.3291 14.3482 12.0551 14.4617 11.7695 14.4617ZM9.61515 1.53833H6.38438C6.09877 1.53833 5.82485 1.65179 5.62288 1.85375C5.42092 2.05572 5.30746 2.32964 5.30746 2.61525V3.69218H10.6921V2.61525C10.6921 2.32964 10.5786 2.05572 10.3767 1.85375C10.1747 1.65179 9.90077 1.53833 9.61515 1.53833Z" stroke="#615E83" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                $output .='</div>';
                            $output .='</div>';
                        endforeach;
                    }else{
                        $output .='<div class="accordion-item" data-id="0">';
                            $output .='<div class="accordion-content">';
                                $output .='<select name="nxt-archive-group[0][nxt-archive-include-exclude]" id="nxt-archive-group_0_nxt-archive-include-exclude">';
                                    foreach ($include_exclude as $type => $label):
                                        $output .='<option value="'.esc_attr($type).'">'.esc_html($label).'</option>';
                                    endforeach;
                                $output .='</select>';

                                $output .= self::nxt_generate_select_from_array(Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions(), 'nxt-archive-group', 0, 'nxt-archive-conditional-rule', '');

                                $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-archive-group[0][nxt-archive-conditional-type][]" id="nxt-archive-group_0_nxt-archive-conditional-type">';
                                    $output .='<option value="attachment">'.__( "All", "nexter-extension" ).'</option>';
                                $output .='</select>';
                            $output .='</div>';
                            $output .='<div class="accordion-header hide-remove-btn">';
                                $output .='<svg class="remove-accordion" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path class="remove-accordion-path" d="M6.38453 11.2306V6.92286M9.61574 11.2306V6.92286M1 3.69247H15M11.7695 14.4617H4.23106C3.94545 14.4617 3.67153 14.3482 3.46956 14.1463C3.2676 13.9443 3.15414 13.6704 3.15414 13.3848V3.69247H12.8464V13.3848C12.8464 13.6704 12.733 13.9443 12.531 14.1463C12.3291 14.3482 12.0551 14.4617 11.7695 14.4617ZM9.61515 1.53833H6.38438C6.09877 1.53833 5.82485 1.65179 5.62288 1.85375C5.42092 2.05572 5.30746 2.32964 5.30746 2.61525V3.69218H10.6921V2.61525C10.6921 2.32964 10.5786 2.05572 10.3767 1.85375C10.1747 1.65179 9.90077 1.53833 9.61515 1.53833Z" stroke="#615E83" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            $output .='</div>';
                        $output .='</div>';
                    }
                }else{
                    $get_singular_group = get_post_meta($post_id, 'nxt-singular-group', true);
                    
                    $hide_remove_btn = '';
                    if( !empty($get_singular_group) && count($get_singular_group) == 1 ){
                        $hide_remove_btn = 'hide-remove-btn';
                    }

                    if(!empty($get_singular_group)){
                        foreach ($get_singular_group as $index => $sg) :
                            $get_sin_in_ex = $sg['nxt-singular-include-exclude'];
                            $output .='<div class="accordion-item" data-id="'.esc_attr($index).'">';
                                $output .='<div class="accordion-content">';
                                    $output .='<select name="nxt-singular-group['.esc_attr($index).'][nxt-singular-include-exclude]" id="nxt-singular-group_'.esc_attr($index).'_nxt-singular-include-exclude">';
                                        foreach ($include_exclude as $type => $label):
                                            $selected = '';
                                            if($type == $get_sin_in_ex){
                                                $selected = 'selected';
                                            }
                                            $output .='<option value="'.esc_attr($type).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                                        endforeach;
                                    $output .='</select>';
                                    $get_sin_rule = $sg['nxt-singular-conditional-rule'];
                                    $output .= self::nxt_generate_select_from_array(Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions(), 'nxt-singular-group', $index, 'nxt-singular-conditional-rule', $get_sin_rule);

                                    $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-singular-group['.esc_attr($index).'][nxt-singular-conditional-type][]" id="nxt-singular-group_'.esc_attr($index).'_nxt-singular-conditional-type">';

                                    $singular_opt = self::nxt_get_type_singular_field_new("nxt-singular-group_".$index."_nxt-singular-conditional-type" , $post_id);
                                    if(!empty($singular_opt)){
                                        foreach ($singular_opt as $ind => $so) :
                                            $selected = '';
                                            if(!empty($get_singular_group) && !empty($get_singular_group[$index]['nxt-singular-conditional-type']) && in_array($ind, $get_singular_group[$index]['nxt-singular-conditional-type'])){
                                                $selected = "selected";
                                            }
                                            $output .='<option value="'.esc_attr($ind).'" '.esc_attr($selected).'>'.esc_html($so).'</option>';
                                        endforeach;
                                    }

                                    $output .='</select>';
                                $output .='</div>';
                                $output .='<div class="accordion-header '.esc_attr($hide_remove_btn).'">';
                                    $output .='<svg class="remove-accordion" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path class="remove-accordion-path" d="M6.38453 11.2306V6.92286M9.61574 11.2306V6.92286M1 3.69247H15M11.7695 14.4617H4.23106C3.94545 14.4617 3.67153 14.3482 3.46956 14.1463C3.2676 13.9443 3.15414 13.6704 3.15414 13.3848V3.69247H12.8464V13.3848C12.8464 13.6704 12.733 13.9443 12.531 14.1463C12.3291 14.3482 12.0551 14.4617 11.7695 14.4617ZM9.61515 1.53833H6.38438C6.09877 1.53833 5.82485 1.65179 5.62288 1.85375C5.42092 2.05572 5.30746 2.32964 5.30746 2.61525V3.69218H10.6921V2.61525C10.6921 2.32964 10.5786 2.05572 10.3767 1.85375C10.1747 1.65179 9.90077 1.53833 9.61515 1.53833Z" stroke="#615E83" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                $output .='</div>';
                            $output .='</div>';
                        endforeach;
                    }else{
                        $output .='<div class="accordion-item" data-id="0">';
                            $output .='<div class="accordion-content">';
                                $output .='<select name="nxt-singular-group[0][nxt-singular-include-exclude]" id="nxt-singular-group_0_nxt-singular-include-exclude">';
                                    foreach ($include_exclude as $type => $label):
                                        $output .='<option value="'.esc_attr($type).'">'.esc_html($label).'</option>';
                                    endforeach;
                                $output .='</select>';

                                $output .= self::nxt_generate_select_from_array(Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions(), 'nxt-singular-group', 0, 'nxt-singular-conditional-rule', '');

                                $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-singular-group[0][nxt-singular-conditional-type][]" id="nxt-singular-group_0_nxt-singular-conditional-type">';
                                    $output .='<option value="attachment">'.__( "All", "nexter-extension" ).'</option>';
                                $output .='</select>';
                            $output .='</div>';
                            $output .='<div class="accordion-header hide-remove-btn">';
                                $output .='<svg class="remove-accordion" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path class="remove-accordion-path" d="M6.38453 11.2306V6.92286M9.61574 11.2306V6.92286M1 3.69247H15M11.7695 14.4617H4.23106C3.94545 14.4617 3.67153 14.3482 3.46956 14.1463C3.2676 13.9443 3.15414 13.6704 3.15414 13.3848V3.69247H12.8464V13.3848C12.8464 13.6704 12.733 13.9443 12.531 14.1463C12.3291 14.3482 12.0551 14.4617 11.7695 14.4617ZM9.61515 1.53833H6.38438C6.09877 1.53833 5.82485 1.65179 5.62288 1.85375C5.42092 2.05572 5.30746 2.32964 5.30746 2.61525V3.69218H10.6921V2.61525C10.6921 2.32964 10.5786 2.05572 10.3767 1.85375C10.1747 1.65179 9.90077 1.53833 9.61515 1.53833Z" stroke="#615E83" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            $output .='</div>';
                        $output .='</div>';
                    }
                }
                $output .='<button type="button" class="nxt-add-accordion" data-type="'.esc_attr($layoutType).'">';
                    $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="15" viewBox="0 0 16 15" fill="none"><path d="M7.99998 4.83398V10.1673M5.33331 7.50065H10.6666M14.6666 7.50065C14.6666 11.1825 11.6819 14.1673 7.99998 14.1673C4.31808 14.1673 1.33331 11.1825 1.33331 7.50065C1.33331 3.81875 4.31808 0.833984 7.99998 0.833984C11.6819 0.833984 14.6666 3.81875 14.6666 7.50065Z" stroke="#1717CC" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    $output .= __( "Add Condition", "nexter-extension" );
                $output .='</button>';
            $output .='</div>';

            return $output;
        }

        public function nexter_ext_repeater_custom_structure_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }
            $layoutType = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field( wp_unslash($_POST['type']) ) : 'singular';

            $include_exclude = [
				'include' => __('Include', 'nexter-extension'),
				'exclude' => __('Exclude', 'nexter-extension')
			];

            $getId = (isset($_POST['nextId']) && !empty($_POST['nextId'])) ? sanitize_text_field( wp_unslash($_POST['nextId']) ) : 0;
            
            $output = '';
            $output .='<div class="accordion-item" data-id="'.esc_attr($getId).'">';
                $output .='<div class="accordion-content">';
                    if($layoutType == 'archives'){
                        $output .='<select name="nxt-archive-group['.esc_attr($getId).'][nxt-archive-include-exclude]" id="nxt-archive-group_'.esc_attr($getId).'_nxt-archive-include-exclude">';
                            foreach ($include_exclude as $type => $label):
                                $output .='<option value="'.esc_attr($type).'">'.esc_html($label).'</option>';
                            endforeach;
                        $output .='</select>';
                        $output .= self::nxt_generate_select_from_array(Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions(), 'nxt-archive-group', $getId, 'nxt-archive-conditional-rule', '');

                        $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-archive-group['.esc_attr($getId).'][nxt-archive-conditional-type][]" id="nxt-archive-group_'.esc_attr($getId).'_nxt-archive-conditional-type">';
                            $output .='<option value="attachment">'.__( "All", "nexter-extension" ).'</option>';
                        $output .='</select>';
                    }else{
                        $output .='<select name="nxt-singular-group['.esc_attr($getId).'][nxt-singular-include-exclude]" id="nxt-singular-group_'.esc_attr($getId).'_nxt-singular-include-exclude">';
                            foreach ($include_exclude as $type => $label):
                                $output .='<option value="'.esc_attr($type).'">'.esc_html($label).'</option>';
                            endforeach;
                        $output .='</select>';

                        $output .= self::nxt_generate_select_from_array(Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions(), 'nxt-singular-group', $getId, 'nxt-singular-conditional-rule', '');

                        $output .='<select class="nxt-single-archive-post" multiple="multiple" name="nxt-singular-group['.esc_attr($getId).'][nxt-singular-conditional-type][]" id="nxt-singular-group_'.esc_attr($getId).'_nxt-singular-conditional-type">';
                            $output .='<option value="attachment">'.__( "All", "nexter-extension" ).'</option>';
                        $output .='</select>';
                    }
                $output .='</div>';
                $output .='<div class="accordion-header">';
                    $output .='<svg class="remove-accordion" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path class="remove-accordion-path" d="M6.38453 11.2306V6.92286M9.61574 11.2306V6.92286M1 3.69247H15M11.7695 14.4617H4.23106C3.94545 14.4617 3.67153 14.3482 3.46956 14.1463C3.2676 13.9443 3.15414 13.6704 3.15414 13.3848V3.69247H12.8464V13.3848C12.8464 13.6704 12.733 13.9443 12.531 14.1463C12.3291 14.3482 12.0551 14.4617 11.7695 14.4617ZM9.61515 1.53833H6.38438C6.09877 1.53833 5.82485 1.65179 5.62288 1.85375C5.42092 2.05572 5.30746 2.32964 5.30746 2.61525V3.69218H10.6921V2.61525C10.6921 2.32964 10.5786 2.05572 10.3767 1.85375C10.1747 1.65179 9.90077 1.53833 9.61515 1.53833Z" stroke="#615E83" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                $output .='</div>';
            $output .='</div>';

            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
        }
        

        /** Page 404 */
        public function nexter_ext_pages_404_condition_popup_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $getPost_Id = (isset($_POST['post_id']) && !empty($_POST['post_id']))? absint($_POST['post_id']) : '';

            $conType = (isset($_POST['type']) && !empty($_POST['type'])) ? sanitize_text_field( wp_unslash($_POST['type']) ) : 'edit';

            $btnText = ($conType == 'edit' || $conType == 'update') ?  __( 'Save', 'nexter-extension' ) :  __( 'Create', 'nexter-extension' );

            $get_header = get_post_meta($getPost_Id, 'nxt-404-disable-header', true);
            $get_footer = get_post_meta($getPost_Id, 'nxt-404-disable-footer', true);

            $headSelected = ((!empty($get_header) && $get_header == 'on') ? "checked" : "");
            $footSelected = ((!empty($get_footer) && $get_footer == 'on') ? "checked" : "");

            $hiddenAttr = [];
           
            if( $conType == 'new' || $conType == 'update'){
                $layoutType =  (isset($_POST['nxt-hooks-layout_sections']) && !empty($_POST['nxt-hooks-layout_sections'])) ? sanitize_text_field(wp_unslash($_POST['nxt-hooks-layout_sections'])) : 'header';
                $tempName =  !empty($_POST['template_name']) ? sanitize_text_field( wp_unslash($_POST['template_name']) ) : 'Nexter Builder';
                $hiddenAttr['nxt-hooks-layout_sections'] = $layoutType;
                $hiddenAttr['template_name'] = $tempName;
            }

            $output = '';
            $output .= '<div class="nxt-bul-temp">';
                $output .= '<div class="nxt-temp-heading">';
                    $output .= '<h3 class="temp-head-title">'.__( "Set Display Conditions", "nexter-extension" ).'</h3>';
                    $output .= '<p class="temp-head-desc">'.__( "Select where you want to load the template", "nexter-extension" ).'</p>';
                $output .= '</div>';
                $output .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    if($conType == 'new'){
                        $output .= '<input type="hidden" name="action" value="nexter_ext_save_template">';
                    }else{
                        $output .= '<input type="hidden" name="action" value="nexter_ext_edit_template">';
                        $output .= '<input type="hidden" name="post_id" value="'.esc_attr($getPost_Id).'">';
                    }

                    $output .='<input type="hidden" name="nonce" value="'. esc_attr(wp_create_nonce("nxt-builder")).'" />';

                    $output .= '<div class="nxt-common-cnt-wrap nxt-condition-main-wrap">';
                        $output .= '<div class="nxt-common-cnt-inner">';
                            if(!empty($hiddenAttr)){
                                foreach ($hiddenAttr as $type => $label):
                                    $output .= '<input type="hidden" name="'.esc_attr($type).'" value="'.esc_attr($label).'">';
                                endforeach;
                            }
                            $output .= '<div class="nxt-condition-include">';
                                $output .= '<label class="nxt-main-label">'.__( "Disable Header", "nexter-extension" ).'</label>';
                                $output .= '<div class="nxt-trans-header-wrap">';
                                    $output .= '<label>'.__( "Check this option to disable header.", "nexter-extension" ).'</label>';
                                    $output .= '<div class="nxt-trans-header-inner">';
                                        $output .= '<input type="checkbox" class="nxt-trans-header" name="nxt-404-disable-header" id="nxt-404-disable-header" value="on" '.$headSelected.'>';
                                        $output .= '<label for="nxt-404-disable-header"></label>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            $output .= '</div>';
                            $output .= '<div class="nxt-condition-exclude">';
                                $output .= '<label class="nxt-main-label">'.__( "Disable Footer", "nexter-extension" ).'</label>';
                                $output .= '<div class="nxt-trans-header-wrap">';
                                    $output .= '<label>'.__( "Check this option to disable footer.", "nexter-extension" ).'</label>';
                                    $output .= '<div class="nxt-trans-header-inner">';
                                        $output .= '<input type="checkbox" class="nxt-trans-header" name="nxt-404-disable-footer" id="nxt-404-disable-footer" value="on" '.$footSelected.'>';
                                        $output .= '<label for="nxt-404-disable-footer"></label>';
                                    $output .= '</div>';
                                $output .= '</div>';
                            $output .= '</div>';
                        $output .= '</div>';
                    $output .= '</div>';

                    $output .= '<div class="nxt-temp-action">';
                        $output .= '<a href="'.esc_url('https://nexterwp.com/help/nexter-theme/theme-builder-classic-theme/').'" class="nxt-temp-info" target="_blank" rel="noopener noreferrer">';
                            $output .= __( "Read How it Works", "nexter-extension" );
                        $output .= '</a>';
                        $output .= '<div class="nxt-action-btn-wrap">';
                            if(!empty($getPost_Id)){
                                $output .= '<a class="nxt-action-back">';
                                    $output .= __( "Go Back", "nexter-extension" );
                                $output .= '</a>';
                            }
                            if($conType == 'new'){
                                $output .= '<button type="submit" class="temp-create-btn">';
                                    $output .= esc_html($btnText);
                                $output .= '</button>';
                            }else{
                                $output .= '<a class="temp-create-btn temp-edit-btn-save">';
                                    $output .= esc_html($btnText);
                                $output .= '</a>';
                            }
                        $output .= '</div>';
                    $output .= '</div>';

                $output .= '</form>';
            $output .= '</div>';
            $output .= self::nexter_close_warning_popup();

            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
            wp_die();

        }
        /**
         * Nexter Builder Display Rules / Condition Pages 
         * End
        */

        /**
         * Nexter Builder Status 
         * Start
        */
        public function nexter_ext_status_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $getId = (isset($_POST['post_id']) && !empty($_POST['post_id'])) ? absint($_POST['post_id']) : '';
            $check = (isset($_POST['check']) && !empty($_POST['check'])) ? sanitize_text_field( wp_unslash($_POST['check']) ) : 0;
            $meta_key = 'nxt_build_status';
            $getPostStatus = get_post_meta($getId, $meta_key, false);

            if(empty($getPostStatus)){
                add_post_meta($getId, $meta_key, $check, false);
            }else{
                update_post_meta($getId, $meta_key, $check);
            }

            $option = 'nxt-build-get-data';
			$get_data = get_option($option);
			if( $get_data === false ){
				$value = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
				add_option( $option, $value );
			}else if(!empty($get_data)){
				$get_data['saved'] = strtotime('now');
				update_option( $option, $get_data, false );
			}

            wp_send_json_success(
                array(
                    'content'	=> $check,
                )
            );
            wp_die();
        }
        
        /**
		 * Get Generate Post Types Of Rules Options
		 */
		public static function get_post_type_rule_options( $post_type, $taxonomy ) {
			
			$post_name   = $post_type->name;
			$post_key    = str_replace( ' ', '-', strtolower( $post_type->label ) );
			$post_label  = ucwords( $post_type->label );
			
			$options = array();

			/* translators: %s: Post Label*/
			$options[ $post_name . '|entire' ]	= sprintf( __( 'All %s', 'nexter-extension' ), $post_label );

			if ( $post_key != 'pages' ) {
				/* translators: %s: Archive Post Label */
				$options[ $post_name . '|entire|archive' ] = sprintf( __( 'All %s Archive', 'nexter-extension' ), $post_label );
			}
			
			if ( in_array( $post_type->name, $taxonomy->object_type ) ) {
				$taxo_name  = $taxonomy->name;
				$taxo_label = ucwords( $taxonomy->label );
				/* translators: %s: Taxonomy Label */
				$options[ $post_name . '|entire|tax-archive|' . $taxo_name ] = sprintf( __( 'All %s Archive', 'nexter-extension' ), $taxo_label );
			}

			$post_output['key'] = $post_key;
			$post_output['label'] = $post_label;
			$post_output['value'] = $options;

			return $post_output;
		}
        
        /**
         * Nexter Builder Status 
         * End
        */
        public function nxt_include_exclude_dis_rules($type = '', $add_exclude = [], $conType='') {
            $standard_opt = [
                'standard-universal' => __('Entire Website', 'nexter-extension'),
                'standard-singulars' => __('All Singulars', 'nexter-extension'),
                'standard-archives' => __('All Archives', 'nexter-extension')
            ];
    
            $def_page_opt = [
                'default-front' => __('Front Page', 'nexter-extension'),
                'default-blog' => __('Blog / Posts Page', 'nexter-extension'),
                'default-date' => __('Date Archive', 'nexter-extension'),
                'default-author' => __('Author Archive', 'nexter-extension'),
                'default-search' => __('Search Page', 'nexter-extension'),
                'default-404' => __('404 Page', 'nexter-extension')
            ];
            if ( class_exists( 'WooCommerce' ) ) {
				$def_page_opt['default-woo-shop'] = __( 'WooCommerce Shop Page', 'nexter-extension' );
			}

            $day_opt = [
                'set-day' => __('Day of Week', 'nexter-extension'),
            ];
    
            $visitor_opt = [
                'os' => __('Operating System', 'nexter-extension'),
                'browser' => __('Browser', 'nexter-extension'),
                'login-status' => __('Login Status', 'nexter-extension'),
                'user-roles' => __('User Roles', 'nexter-extension')
            ];
    
            $parti_post_opt = [
                'particular-post' => __('Particular Posts / Pages / Taxonomies, etc.', 'nexter-extension')
            ];

            //Post Types Options
			$get_post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
			unset( $get_post_types[ NXT_BUILD_POST ] );
			
			$taxonomy_lists = get_taxonomies( array( 'public' => true ), 'objects' );
			
			$pages_posts = [];
			if ( !empty( $taxonomy_lists ) ) {
				foreach ( $taxonomy_lists as $taxonomy ) {

					if ( $taxonomy->name == 'post_format' ) {
						continue;
					}

					foreach ( $get_post_types as $post_type ) {

						$post_options = self::get_post_type_rule_options( $post_type, $taxonomy );

						if ( isset( $pages_posts[ $post_options['label'] ] )) {						
							if ( ! empty( $post_options['value'] ) && is_array( $post_options['value'] )) {
								foreach ( $post_options['value'] as $key => $value ) {
									if ( ! in_array( $value, $pages_posts[ $post_options['label'] ]) ) {
										$pages_posts[ $post_options['label'] ][ $key ] = $value;
									}
								}
							}
						} else {						
							$pages_posts[ $post_options['label'] ] = $post_options['value'];
						}
						
					}
				}
			}

            $output = '<select class="nxt-temp-select nxt-' . esc_attr($type) . '-display-rule" name="nxt-' . esc_attr($type) . '-display-rule[]" id="nxt-' . esc_attr($type) . '-display-rule" multiple="multiple">';
                $optgroups = [
                    'Standard' => $standard_opt,
                    'Default Pages' => $def_page_opt,
                    'Date & Time' => $day_opt,
                    'Visitors Source' => $visitor_opt,
                ];
                $optgroups = array_merge($optgroups, $pages_posts,['Particular Posts/Pages/Taxonomies' => $parti_post_opt]);
                foreach ($optgroups as $label => $options) {
                    $output .= '<optgroup label="' . esc_attr($label) . '">';
                    $output .= self::generate_options($options, $add_exclude, $conType);
                    $output .= '</optgroup>';
                }
            $output .= '</select>';
            
            return $output;
        }

        public function generate_options($options, $add_exclude, $conType) {
            $output = '';
            foreach ($options as $typ => $label) {
                $selected = (!empty($add_exclude) && ($conType == 'edit' || $conType == 'update') && in_array($typ, $add_exclude)) ? 'selected' : '';
                $output .= '<option value="' . esc_attr($typ) . '" ' . esc_attr($selected) . '>' . esc_html($label) . '</option>';
            }
            return $output;
        }

        public function nxt_include_exclude_value($post_id, $exClass = '', $conType=''){
            global $wp_roles;
            $roles = $wp_roles->get_names();

            $days_list = [
                '1' => __('Monday', 'nexter-extension'),
                '2' => __('Tuesday', 'nexter-extension'),
                '3' => __('Wednesday', 'nexter-extension'),
                '4' => __('Thursday', 'nexter-extension'),
                '5' => __('Friday', 'nexter-extension'),
                '6' => __('Saturday', 'nexter-extension'),
                '7' => __('Sunday', 'nexter-extension')
            ];
            $os_list = [
                'windows' => __('Windows', 'nexter-extension'),
                'open_bsd' => __('OpenBSD', 'nexter-extension'),
                'sun_os' => __('SunOS', 'nexter-extension'),
                'linux' => __('Linux', 'nexter-extension'),
                'safari' => __('Safari', 'nexter-extension'),
                'mac_os' => __('Mac OS', 'nexter-extension'),
                'qnx' => __('QNX', 'nexter-extension'),
                'beos' => __('BeOS', 'nexter-extension'),
                'os2' => __('OS/2', 'nexter-extension'),
                'search_bot' => __('Search Bot', 'nexter-extension')
            ];
            $browser_list = [
                'ie' => __('Internet Explorer', 'nexter-extension'),
                'firefox' => __('Mozilla Firefox', 'nexter-extension'),
                'chrome' => __('Google Chrome', 'nexter-extension'),
                'opera_mini' => __('Opera Mini', 'nexter-extension'),
                'opera' => __('Opera', 'nexter-extension'),
                'safari' => __('Safari', 'nexter-extension')
            ];
            $login_list = [
                'logged-in' => __('Logged In', 'nexter-extension'),
                'logged-out' => __('Logged Out', 'nexter-extension')
            ];

            $fields = [
                'set-day' => "nxt-hooks-layout-{$exClass}set-day",
                'os' => "nxt-hooks-layout-{$exClass}os",
                'browser' => "nxt-hooks-layout-{$exClass}browser",
                'login-status' => "nxt-hooks-layout-{$exClass}login-status",
                'user-roles' => "nxt-hooks-layout-{$exClass}user-roles",
                'specific' => "nxt-hooks-layout-{$exClass}specific"
            ];

            $get_days = get_post_meta($post_id, $fields['set-day'], true);
            $get_os = get_post_meta($post_id, $fields['os'], true);
            $get_browser = get_post_meta($post_id, $fields['browser'], true);
            $get_login = get_post_meta($post_id, $fields['login-status'], true);
            $get_user = get_post_meta($post_id, $fields['user-roles'], true);
            $specific = get_post_meta($post_id, $fields['specific'], true);

            $output ='<div class="nxt-set-day-wrap">';
                $output .='<label>'.__( "Select Days", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-set-day" name="nxt-hooks-layout-'.esc_attr($exClass).'set-day[]" id="nxt-hooks-layout-'.esc_attr($exClass).'set-day" multiple="multiple">';
                    foreach ($days_list as $typ=> $label):
                        $selected = "";
                        if(!empty($get_days) && ($conType == 'edit' || $conType == 'update') && in_array($typ, $get_days)){
                            $selected = "selected";
                        }
                        $output .='<option value="'.esc_attr($typ).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                    endforeach;
                $output .='</select>';
            $output .='</div>';

            $output .='<div class="nxt-layout-os-wrap">';
                $output .='<label>'.__( "Select Operating System", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-layout-os" name="nxt-hooks-layout-'.esc_attr($exClass).'os[]" id="nxt-hooks-layout-'.esc_attr($exClass).'os" multiple="multiple">';
                    foreach ($os_list as $typ=> $label):
                        $selected = "";
                        if(!empty($get_os) && ($conType == 'edit' || $conType == 'update') && in_array($typ, $get_os)){
                            $selected = "selected";
                        }
                        $output .='<option value="'.esc_attr($typ).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                    endforeach;
                $output .='</select>';
            $output .='</div>';

            $output .='<div class="nxt-layout-browser-wrap">';
                $output .='<label>'.__( "Select Browser", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-layout-browser" name="nxt-hooks-layout-'.esc_attr($exClass).'browser[]" id="nxt-hooks-layout-'.esc_attr($exClass).'browser" multiple="multiple">';
                   foreach ($browser_list as $typ=> $label):
                        $selected = "";
                        if(!empty($get_browser) && ($conType == 'edit' || $conType == 'update') && in_array($typ, $get_browser)){
                            $selected = "selected";
                        }
                        $output .='<option value="'.esc_attr($typ).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                    endforeach;
                $output .='</select>';
            $output .='</div>';

            $output .='<div class="nxt-layout-login-status-wrap">';
                $output .='<label>'.__( "Select Login Status", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-layout-login-status" name="nxt-hooks-layout-'.esc_attr($exClass).'login-status[]" id="nxt-hooks-layout-'.esc_attr($exClass).'login-status" multiple="multiple">';
                    foreach ($login_list as $typ=> $label):
                        $selected = "";
                        if(!empty($get_login) && ($conType == 'edit' || $conType == 'update') && in_array($typ, $get_login)){
                            $selected = "selected";
                        }
                        $output .='<option value="'.esc_attr($typ).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                    endforeach;
                $output .='</select>';
            $output .='</div>';

            $output .='<div class="nxt-layout-login-user-roles-wrap">';
                $output .='<label>'.__( "Select User Roles", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-layout-user-roles" name="nxt-hooks-layout-'.esc_attr($exClass).'user-roles[]" id="nxt-hooks-layout-'.esc_attr($exClass).'user-roles" multiple="multiple">';
                foreach ($roles as $type => $label):
                    $selected = '';
                    if(!empty($get_user) && ($conType == 'edit' || $conType == 'update') && in_array($type, $get_user)){
                        $selected = "selected";
                    }
                    $output .='<option value="'.esc_attr($type).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                endforeach;
                $output .='</select>';
            $output .='</div>';

            $output .='<div class="nxt-layout-specific-post-wrap">';
                $output .='<label>'.__( "Specific Pages/Posts", "nexter-extension" ).'</label>';
                $output .='<select class="nxt-temp-select nxt-layout-user-roles" name="nxt-hooks-layout-'.esc_attr($exClass).'specific[]" id="nxt-hooks-layout-'.esc_attr($exClass).'specific" multiple="multiple">';

                $specific_get = self::nexter_get_posts_query_specific_new('nxt-hooks-layout-'.esc_attr($exClass).'specific', $post_id);
                if(!empty($specific_get)){
                    foreach ($specific_get as $type => $label):
                        $selected = '';
                        if(!empty($specific) && ($conType == 'edit' || $conType == 'update') && in_array($type, $specific)){
                            $selected = "selected";
                        }
                        $output .='<option value="'.esc_attr($type).'" '.esc_attr($selected).'>'.esc_html($label).'</option>';
                    endforeach;
                }
                $output .='</select>';
            $output .='</div>';

            return $output;
        }

        /***
         * Array into Select/Dropdown
        */
        function nxt_generate_select_from_array($array, $prefix = '', $id = '', $postfix = '', $rule = '') {
            $sname = $sid = $sclass = '';

            if(!empty($prefix) && !empty($postfix)){
                $sname = $prefix.'['.$id.']['.$postfix.']';
                $sid = $prefix.'_'.$id.'_'.$postfix;
                $sclass = $prefix.'-select';
            }else{
                $sname = $prefix;
                $sid = $prefix;
                $sclass = $prefix.'-select';
            }


            $html = '<select class="'.esc_attr($sclass).'" name="'.esc_attr($sname).'" id="'.esc_attr($sid).'">';
        
            foreach ($array as $group) {
                $html .= '<optgroup label="' . htmlspecialchars($group['label']) . '">';
                foreach ($group['value'] as $key => $value) {
                    $selected = '';
                    if($rule == $key){
                        $selected = 'selected';
                    }
                    $html .= '<option value="' . htmlspecialchars($key) . '" '.esc_attr($selected).'>' . htmlspecialchars($value) . '</option>';
                }
                $html .= '</optgroup>';
            }
        
            $html .= '</select>';
            return $html;
        }

        public function enqueue_scripts_admin( $hook_suffix ){
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_style( 'nexter-builder-condition', NEXTER_EXT_URL .'assets/css/admin/nxt-builder-condition'. $minified .'.css', array(), NEXTER_EXT_VER );

            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script( 'nexter-builder-condition', NEXTER_EXT_URL . 'assets/js/admin/nexter-builder-condition'. $minified .'.js', array(), NEXTER_EXT_VER, true);

            if( class_exists('Nexter_Builders_Singular_Conditional_Rules') ){
                $NexterConfig = Nexter_Builders_Singular_Conditional_Rules::$Nexter_Singular_Config;
                $NexterConfig['nxt_archives'] = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
                $NexterConfig['adminPostUrl'] = admin_url('admin-post.php');
                $NexterConfig['hiddennonce'] = wp_create_nonce("nxt-builder");
                wp_localize_script( 'nexter-builder-condition', 'NexterConfig', $NexterConfig );
            }

        }

        public static function nexter_get_posts_query_specific_new( $specific_id, $post_id ){
            $specific_value = get_post_meta( $post_id, $specific_id, true );
            
            $data_query = Nexter_Builder_Display_Conditional_Rules::nexter_get_particular_posts_query();
            
            $options =array();
            if( !empty( $specific_value ) && $specific_value!='none') {
                foreach ( $data_query as $key => $parent ) {
                    foreach( $parent['children'] as $key => $value ){
                        if( !empty( $specific_value ) && in_array( $value['id'], $specific_value ) ) {
                            $options[$value['id']] = $value['text'];
                        }
                    }
                }
            }else{		
                $options['none'] = esc_html__('---Select---', 'nexter-extension');
            }
            
            return $options;
        }

        /** Singular Options */
        public function nxt_get_type_singular_field_new( $group_field, $post_id ) {
	
            $group_field = str_replace('nxt-singular-group_', '', $group_field);	
            $index_id = str_replace('_nxt-singular-conditional-type', '', $group_field);
            
            $group_value = get_post_meta( $post_id, 'nxt-singular-group', true );
            
            $index_id = (!empty($index_id)) ? $index_id : 0;
            $options = '';
            $data = Nexter_Builders_Singular_Conditional_Rules::$Nexter_Singular_Config;
            
            if( !empty( $group_value ) && isset( $group_value[$index_id]['nxt-singular-conditional-rule'] ) ) {
                $value = $group_value[$index_id]['nxt-singular-conditional-rule'];
                if(isset($data[$value])){
                    $query = $data[$value];
                    $query['rules'] = $value;
                    $options = Nexter_Builders_Singular_Conditional_Rules::get_singular_options($query);
                }
            }else {
                $query = $data['post'];
                $query['rules'] = 'post';
                $options = Nexter_Builders_Singular_Conditional_Rules::get_singular_options($query);
            }
            
            return $options;
        }
        /** Singular Preview Options */
        public function nxt_get_type_singular_preview_id_new($post_id) {
	
            $type_value = get_post_meta( $post_id, 'nxt-singular-preview-type', true );
            if( !empty( $type_value ) ) {
                $options = Nexter_Builders_Singular_Conditional_Rules::get_post_type_posts_list($type_value);
            }else{
                $options = Nexter_Builders_Singular_Conditional_Rules::get_post_type_posts_list('post');
            }
            
            return $options;
        }

        /** Archive Options */
        public function nxt_get_type_archives_field_new( $group_field, $post_id, $index_id ) {            
            $group_value = get_post_meta( $post_id, 'nxt-archive-group', true );
            
            $index_id = (!empty($index_id)) ? $index_id : 0;
            $options = '';
            $data = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
            
            if( !empty( $group_value ) && isset( $group_value[$index_id]['nxt-archive-conditional-rule'] ) ){
                
                $value = $group_value[$index_id]['nxt-archive-conditional-rule'];
                if( isset($data[$value]) && !empty($data[$value]["condition_type"]) && $data[$value]["condition_type"]=='yes' ){
                    $query = $data[$value];
                    $query['rules'] = $value;				
                    $options = Nexter_Builders_Archives_Conditional_Rules::get_archives_options($query);
                }else {
                    $options = array('all' => __('All','nexter-extension'));
                }
                
            }else{
                $data = $data['all'];
                if(!empty($data["condition_type"]) && $data["condition_type"]=='yes'){
                    $query['rules'] = 'all';
                    $options = Nexter_Builders_Archives_Conditional_Rules::get_archives_options($query);
                }else{
                    $options = array('all' => __('All','nexter-extension'));
                }
            }
            
            return $options;
        }
        /** Archives Preview Options */
        public function nxt_get_type_archives_preview_id_new( $post_id ){
	
            $archive_value = get_post_meta( $post_id, 'nxt-archive-preview-type', true );
            $data = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
            
            if( !empty( $archive_value ) && isset($data[$archive_value]) && !empty($data[$archive_value]["condition_type"]) && $data[$archive_value]["condition_type"]=='yes' ){
                    $query = $data[$archive_value];
                    $query['rules'] = $archive_value;
                $options = Nexter_Builders_Archives_Conditional_Rules::get_terms_by_taxonomy( $query );
            }else{
                $data = $data['category'];
                if(!empty($data["condition_type"]) && $data["condition_type"]=='yes'){
                    $query = $data;
                    $query['rules'] = 'category';
                    $options = Nexter_Builders_Archives_Conditional_Rules::get_terms_by_taxonomy($query);
                }else{
                    $options = array( 'all' => __( 'All','nexter-extension' ) );
                }
            }
            if( empty($options) ){
                $options = array( 'all' => __( 'All','nexter-extension' ) );
            }
            
            return $options;
        }

	}
}

Nexter_Builder_Condition::get_instance();
?>