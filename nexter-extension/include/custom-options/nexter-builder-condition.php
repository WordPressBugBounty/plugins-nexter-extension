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
		 * UI handler instance.
		 *
		 * @var Nxt_Builder_Condition_UI|null
		 */
		private $ui = null;

		/**
		 *  Constructor
		 */
		public function __construct() {
			if( is_admin() ){
                if ( current_user_can( 'manage_options' ) ) {
                    // UI popup rendering — delegated to extracted class.
                    require_once __DIR__ . '/class-nxt-builder-condition-ui.php';
                    $this->ui = new Nxt_Builder_Condition_UI();
                    $this->ui->register_hooks();

                    // CRUD + non-UI AJAX handlers stay here.
		            add_action( 'wp_ajax_nexter_ext_temp_listout', [ $this, 'nexter_ext_temp_listout_data'] );
		            add_action( 'wp_ajax_nexter_ext_status', [ $this, 'nexter_ext_status_ajax'] );
		            add_action( 'wp_ajax_nexter_ext_builder_update', [ $this, 'nexter_ext_builder_update_ajax'] );
                    add_action('wp_ajax_nexter_ext_edit_template',[$this,'nexter_ext_edit_template_form_data']);
                    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_admin' ), 1 );

                    add_action('admin_post_nexter_ext_save_template',[$this,'nexter_ext_add_template_form_data']);
                    add_action('admin_post_nopriv_nexter_ext_save_template',[$this,'nexter_ext_add_template_form_data']);
                    // Register hook so other plugins can trigger it
                    add_action( 'nxt_update_builder_status', array( $this, 'update_builder_status' ), 10, 1 );
                }
            }
		}

        /**
		 * Check User Permission Ajax
		 */
		public function check_permission_user(){
			
			if ( ! is_user_logged_in() ) {
                return false;
            }
			
			$user = wp_get_current_user();
			if ( empty( $user ) ) {
				return false;
			}
			$allowed_roles = array( 'administrator' );
			if ( !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ) {
				return true;
			}
			return false;
		}
        
        public function nexter_ext_temp_listout_data(){
            if(!$this->check_permission_user()){
				wp_send_json_error('Insufficient permissions.');
			}
			check_ajax_referer('nexter_admin_nonce', 'nonce');

			$args = array(
				'post_type'      => NXT_BUILD_POST,
				'post_status'    => array('publish', 'draft', 'private'),
				'posts_per_page' => -1,
			);
		
			$query = new WP_Query($args);
			$code_list = [];

			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();

					$post_id = get_the_ID();
                    $post_status = get_post_status($post_id);
                    $old_layout = get_post_meta($post_id, 'nxt-hooks-layout', true);
				
                    $layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                    $sections_pages = '';
                    $nxt_type = (!empty($layout)) ? $layout : '';
                    $section_pages_layout = '';
                    if(!empty($old_layout)){
                        $section_pages_layout = $old_layout;
                        if($old_layout == 'sections'){
                            $nxt_type = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
                            $sections_pages = 'sections';
                        }else if($old_layout == 'pages'){
                            $nxt_type = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
                            $sections_pages = 'pages';
                        }else if($old_layout == 'code_snippet'){
                            $nxt_type = esc_html__('Snippet : ', 'nexter-extension').get_post_meta($post_id, 'nxt-hooks-layout-code-snippet', true);
                            $sections_pages = 'code_snippet';
                        }else{
                            $sections_pages = __('None', 'nexter-extension');
                        }
                    }
                    if( $layout === 'header' ) {
                        $sections_pages = __('Header', 'nexter-extension');
                    }else if( $layout === 'footer' ){
                        $sections_pages = __('Footer', 'nexter-extension');
                    }else if( $layout === 'breadcrumb' ){
                        $sections_pages = __('Breadcrumb', 'nexter-extension');
                    }else if( $layout === 'hooks' ){
                        $sections_pages = __('Hooks', 'nexter-extension');
                    }else if( $layout === 'singular' ){
                        $sections_pages = __('Singular', 'nexter-extension');
                    }else if( $layout === 'archives' ){
                        $sections_pages = __('Archive', 'nexter-extension');
                    }else if( $layout === 'page-404' ){
                        $sections_pages = __('404 Page', 'nexter-extension');
                    }else if( $layout === 'section' ){
                        $sections_pages = __('Section', 'nexter-extension');
                    }else{
                        $sections_pages = __('None', 'nexter-extension');
                    }
                    if( $layout === 'header' || $layout === 'footer' || $layout === 'breadcrumb' || $layout === 'hooks' ) {
                        $section_pages_layout = 'sections';
                    }else if( $layout === 'singular' || $layout === 'archives' || $layout === 'page-404' ){
                        $section_pages_layout = 'pages';
                    }

                    $getPostStatus = '';
                    if($layout!='' && $layout != 'section'){
                        $getPostStatus = get_post_meta($post_id, 'nxt_build_status', true);
                    }

                    $export_url = add_query_arg(
                        [
                            'action' => 'nxt_builder_export_actions',
                            'nxt_action' => 'export_template',
                            'source' => 'nxt',
                            '_nonce' => wp_create_nonce( 'nxt_ajax' ),
                            'post_id' => $post_id,
                        ],
                        admin_url( 'admin-ajax.php' )
                    );

                    $edit_with_elementor = '';
                    if ( did_action( 'elementor/loaded' ) ) {
                        $document = \Elementor\Plugin::$instance->documents->get( $post_id );
                        if ( $document && $document->is_built_with_elementor() ) {
                            $edit_with_elementor = $document->get_edit_url();
                        }
                    }

					$code_list['templates'][] = [
						'id' => $post_id,
						'name'        => get_post_field('post_title', $post_id, 'raw'),
						'section_page'	=> $section_pages_layout,
						'type_title'	=> $sections_pages,
						'type_slug'	=> $nxt_type,
                        'post_status' => $post_status,
						'status'	=> $getPostStatus,
                        'edit' => html_entity_decode( get_edit_post_link( $post_id ) ),
                        'edit_with_elementor' => html_entity_decode( $edit_with_elementor ),
                        'view' => html_entity_decode( get_permalink( $post_id ) ),
                        'export' => $export_url,
						'last_updated' => get_the_modified_time('F j, Y \a\t g:i a', $post_id),
                        'author_name' => esc_html( get_the_author_meta('display_name', get_post_field('post_author', $post_id)) ),
                        'author_avtar' => esc_url( get_avatar_url( get_post_field( 'post_author', $post_id ), ['size'=>64] ) )
					];
					
				}
				wp_reset_postdata();
			}else{
				wp_send_json_error('No List Found.');
			}
            
            $code_list['info']['switcher'] = get_option( 'nxt_builder_switcher', true );

			wp_send_json_success($code_list);
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
					add_option( $cache_option, $value, '', 'yes' );
				}else if( !empty($get_data) ){
					$get_data['saved'] = strtotime('now');
					update_option( $cache_option, $get_data, true );
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
            return Nxt_Builder_Condition_UI::nexter_close_warning_popup();
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
            $this->ui->nexter_ext_temp_popup_ajax();
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
            $this->ui->nexter_ext_sections_condition_popup_ajax();
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
            $this->ui->nexter_ext_pages_condition_popup_ajax();
        }

        public function nxt_pages_preview_field($layoutType, $post_id ='') {
            return $this->ui->nxt_pages_preview_field($layoutType, $post_id);
        }

        public function render_accordion_repeater_field($layoutType, $post_id = '') {
            return $this->ui->render_accordion_repeater_field($layoutType, $post_id);
        }

        public function nexter_ext_repeater_custom_structure_ajax(){
            $this->ui->nexter_ext_repeater_custom_structure_ajax();
        }
        

        /** Page 404 */
        public function nexter_ext_pages_404_condition_popup_ajax(){
            $this->ui->nexter_ext_pages_404_condition_popup_ajax();
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
				add_option( $option, $value, '', 'yes' );
			}else if(!empty($get_data)){
				$get_data['saved'] = strtotime('now');
				update_option( $option, $get_data, true );
			}

            wp_send_json_success(
                array(
                    'content'	=> $check,
                )
            );
            wp_die();
        }

        /**
         * Nexter Builder Trash 
         * Start
        */
        public function nexter_ext_builder_update_ajax(){
            check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                wp_send_json_success(
                    array(
                        'content'	=> __( 'Insufficient permissions.', 'nexter-extension' ),
                    )
                );
            }

            $post_id = (isset($_POST['post_id']) && !empty($_POST['post_id'])) ? absint($_POST['post_id']) : '';
            $updated = (isset($_POST['updated']) && !empty($_POST['updated'])) ? sanitize_text_field($_POST['updated']) : '';

            if ($post_id && $updated == 'trash' ) {
				$post = get_post($post_id);
		
				if ( $post && $post->post_type === NXT_BUILD_POST ) {

					if (current_user_can('delete_post', $post_id)) {
					    $deleted = wp_delete_post($post_id, true);

						if ($deleted) {
							wp_send_json_success(['message' => 'Snippet deleted successfully']);
						} else {
							wp_send_json_error(['message' => 'Failed to delete Snippet']);
						}
					} else {
						wp_send_json_error(['message' => 'You do not have permission to delete this snippet']);
					}
				} else {
					wp_send_json_error(['message' => 'Invalid post or post type']);
				}
			}else if($updated == 'switcher'){
                $checked = isset($_POST['checked']) ? sanitize_text_field($_POST['checked']) : null;
                if (get_option('nxt_builder_switcher') === false) {
                    add_option('nxt_builder_switcher', $checked);
                } else {
                    update_option('nxt_builder_switcher', $checked);
                }
                wp_send_json_success(['switcher' => $checked, 'message' => 'Snippet Switcher updated']);
            } else {
				wp_send_json_error(['message' => 'Invalid Snippet ID']);
			}

        }
        
        /**
		 * Get Generate Post Types Of Rules Options
		 */
		public static function get_post_type_rule_options( $post_type, $taxonomy ) {
			return Nxt_Builder_Condition_UI::get_post_type_rule_options( $post_type, $taxonomy );
		}
        
        /**
         * Nexter Builder Status 
         * End
        */
        public function nxt_include_exclude_dis_rules($type = '', $add_exclude = [], $conType='') {
            return $this->ui->nxt_include_exclude_dis_rules($type, $add_exclude, $conType);
        }

        public function generate_options($options, $add_exclude, $conType) {
            return $this->ui->generate_options($options, $add_exclude, $conType);
        }

        public function nxt_include_exclude_value($post_id, $exClass = '', $conType=''){
            return $this->ui->nxt_include_exclude_value($post_id, $exClass, $conType);
        }

        function nxt_generate_select_from_array($array, $prefix = '', $id = '', $postfix = '', $rule = '') {
            return $this->ui->nxt_generate_select_from_array($array, $prefix, $id, $postfix, $rule);
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
                $NexterConfig['createLabel'] = __( 'Create', 'nexter-extension' );
                $NexterConfig['nexterBuilderI18n'] = array(
                    'saving'               => __( 'Saving…', 'nexter-extension' ),
                    'saved'                => __( 'Saved', 'nexter-extension' ),
                    'save'                 => __( 'Save', 'nexter-extension' ),
                    'all'                  => __( 'All', 'nexter-extension' ),
                    'placeholderInclude'   => __( 'Select locations where you want to show your template.', 'nexter-extension' ),
                    'placeholderExclude'   => __( 'Select locations where you want to hide your template.', 'nexter-extension' ),
                );
                wp_localize_script( 'nexter-builder-condition', 'NexterConfig', $NexterConfig );
            }
        }

        public static function nexter_get_posts_query_specific_new( $specific_id, $post_id ){
            return Nxt_Builder_Condition_UI::nexter_get_posts_query_specific_new( $specific_id, $post_id );
        }

        public function nxt_get_type_singular_field_new( $group_field, $post_id ) {
            return $this->ui->nxt_get_type_singular_field_new( $group_field, $post_id );
        }

        public function nxt_get_type_singular_preview_id_new($post_id) {
            return $this->ui->nxt_get_type_singular_preview_id_new($post_id);
        }

        public function nxt_get_type_archives_field_new( $group_field, $post_id, $index_id ) {            
            return $this->ui->nxt_get_type_archives_field_new( $group_field, $post_id, $index_id );
        }

        public function nxt_get_type_archives_preview_id_new( $post_id ){
            return $this->ui->nxt_get_type_archives_preview_id_new( $post_id );
        }

        /**
         * Update 'nxt_build_status' meta key to 0
         * for all or specific 'nxt_builder' posts.
         *
         * @param array|string $args Optional. Can be 'all' or an array of post IDs.
         */
        public function update_builder_status( $args = 'all' ) {
            $post_ids = array();

            if ( $args === 'all' ) {
                // Get all posts of type nxt_builder
                $posts = get_posts( array(
                    'post_type'      => 'nxt_builder',
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ) );

                $post_ids = $posts;
            } elseif ( is_array( $args ) ) {
                // Use provided IDs (ensure integers)
                $post_ids = array_map( 'intval', $args );
            }

            if ( ! empty( $post_ids ) ) {
                foreach ( $post_ids as $post_id ) {
                    update_post_meta( $post_id, 'nxt_build_status', 0 );
                }
            }
        }
	}
}

Nexter_Builder_Condition::get_instance();
?>