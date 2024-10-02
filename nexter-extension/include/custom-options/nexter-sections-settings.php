<?php
/*
 * Custom Options Nexter Builder
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */

add_action( 'cmb2_admin_init', 'nexter_builder_settings' );

/**
 * Define the metabox and field configurations
 */
function nexter_builder_settings() {

	$prefix ='nxt-';
	$image_path ='';
	if(defined('NXT_THEME_URI') || defined('HELLO_ELEMENTOR_VERSION')){
		$image_path =  NEXTER_EXT_URL.'/assets/images/';
	}
	
	if(defined('NXT_PRO_EXT')){
		$options = get_option( 'nexter_white_label' );
		$builder_name = (!empty($options['brand_name'])) ? $options['brand_name'].' Builder' : __( 'Nexter Builder', 'nexter-ext' );
	}else{
		$builder_name = 'Nexter Builder';
	}

	$others_location_field = [
		'set-day' => esc_html__('Select Days', 'nexter-ext'),
		'os' => esc_html__('Operating System', 'nexter-ext'),
		'browser' => esc_html__('Browser', 'nexter-ext'),
		'login-status' => esc_html__('Login Status', 'nexter-ext'),
		'user-roles' => esc_html__('User Roles', 'nexter-ext'),
	];

	$header_fields = new_cmb2_box( array(
		'id'         => 'nxt_builder_settings',
		'title'      => $builder_name,
		'object_types'	=> array('nxt_builder'),
		'context'    => 'normal',
		'priority'   => 'high',
		'show_names' => true,
		'classes' => 'nxt-metabox-wrap',
	) );
	
	$layout_wrap_open = '<div class="nxt-sections-pages-fields">';
	$layout_page_wrap_close = '</div>';
	$layout_wrap_close = '';
	
	$hook_layout = array(
		'none' => array('title' => esc_html__('None', 'nexter-ext'), 'alt' => esc_html__('none', 'nexter-ext'), 'img' => esc_url($image_path . 'none.jpg')),
		'sections' => array('title' => esc_html__('Sections', 'nexter-ext'), 'alt' => esc_html__('sections', 'nexter-ext'), 'img' => esc_url($image_path . 'sections.jpg')),
		'pages' => array('title' => esc_html__('Pages', 'nexter-ext'), 'alt' => esc_html__('pages', 'nexter-ext'), 'img' => esc_url($image_path . 'pages.jpg')),
	);
	if(function_exists('nxt_user_roles_check')){
		if(nxt_user_roles_check()){
			$hook_layout['code_snippet'] = array('title' => esc_html__('Code Snippets', 'nexter-ext'), 'alt' => esc_html__('code-snippets', 'nexter-ext'), 'img' => esc_url($image_path . 'code-snippet.jpg'));
			$layout_wrap_close = '</div>';
			$layout_page_wrap_close = '';
		}
	}
	/** Code Type
	 * @since 1.0.9
	 */
	//Layout : Sections/Pages/Code Snippets
	$header_fields->add_field( array(
		'before_row' => $layout_wrap_open,
		'name'	=> esc_html__('Layout', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout',
		'type'	=> 'image_select',
		'options' => $hook_layout,
		'default' => 'none',
	) );
	
	/* Sections
	 * Header/Footer/Breadcrumb/Hooks/Code-snippet
	 */
	$header_fields->add_field( array(
		'name'	=> esc_html__('Sections', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout-sections',
		'type'	=> 'image_select',
		'options' => array(
			'none' => array('title' => esc_html__('None', 'nexter-ext'), 'alt' => esc_html__('none', 'nexter-ext'), 'img' => esc_url($image_path . 'none.jpg')),
			'header' => array('title' => esc_html__('Header', 'nexter-ext'), 'alt' => esc_html__('header', 'nexter-ext'), 'img' => esc_url($image_path . 'header.jpg')),
			'footer' => array('title' => esc_html__('Footer', 'nexter-ext'), 'alt' => esc_html__('footer', 'nexter-ext'), 'img' => esc_url($image_path . 'footer.jpg')),
			'breadcrumb' => array('title' => esc_html__('Breadcrumb', 'nexter-ext'), 'alt' => esc_html__('breadcrumb', 'nexter-ext'), 'img' => esc_url($image_path . 'breadcrumb.jpg')),
			'hooks' => array('title' => esc_html__('Hooks', 'nexter-ext'), 'alt' => esc_html__('hooks', 'nexter-ext'), 'img' => esc_url($image_path . 'hooks.jpg')),
		),
		'default' => 'none',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout',
			'data-conditional-value' => 'sections',
		),
	) );
	
	$header_fields->add_field( array(
		'name'	=> esc_html__('Pages', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout-pages',
		'type'	=> 'image_select',
		'options' => array(
			'none' => array('title' => esc_html__('None', 'nexter-ext'), 'alt' => esc_html__('none', 'nexter-ext'), 'img' => esc_url($image_path . 'none.jpg')),
			'page-404' => array('title' => esc_html__('404 Page', 'nexter-ext'), 'alt' => esc_html__('page-404', 'nexter-ext'), 'img' => esc_url($image_path . '404-page.jpg')),
			'singular' => array('title' => esc_html__('Singular', 'nexter-ext'), 'alt' => esc_html__('singular', 'nexter-ext'), 'img' => esc_url($image_path . 'singular.jpg')),
			'archives' => array('title' => esc_html__('Archives', 'nexter-ext'), 'alt' => esc_html__('archives', 'nexter-ext'), 'img' => esc_url($image_path . 'archives.jpg')),
		),
		'default' => 'none',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout',
			'data-conditional-value' => 'pages',
		),
		'after_row' => $layout_page_wrap_close,
	) );
	
	/** Code Type
	 * @since 1.0.9
	 */
	$header_fields->add_field( array(
		'after_row' => $layout_wrap_close,
		'name'	=> esc_html__('Code Type', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout-code-snippet',
		'type'	=> 'image_select',
		'options' => array(
			'php' => array('title' => esc_html__('PHP', 'nexter-ext'), 'alt' => esc_html__('php snippet', 'nexter-ext'), 'img' => esc_url($image_path . 'code-php.jpg')),
			'html' => array('title' => esc_html__('HTML', 'nexter-ext'), 'alt' => esc_html__('html snippet', 'nexter-ext'), 'img' => esc_url($image_path . 'code-html.jpg')),
			'css' => array('title' => esc_html__('CSS', 'nexter-ext'), 'alt' => esc_html__('css snippet', 'nexter-ext'), 'img' => esc_url($image_path . 'code-css.jpg')),
			'javascript' => array('title' => esc_html__('JS', 'nexter-ext'), 'alt' => esc_html__('javascript snippet', 'nexter-ext'), 'img' => esc_url($image_path . 'code-js.jpg')),
		),
		'default' => 'php',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout',
			'data-conditional-value' => 'code_snippet',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	
	/** Code Type */
	 
	if (  did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
	
		$header_fields->add_field( array(
			'id'	=> '_elementor_template_type',
			'type'	=> 'hidden',
			'default' => 'wp-post',
		) );
	}
	
	$section_wrap_open = '<div class="nxt-sections-wrap-fields">';
	$section_wrap_close = '</div>';
	
	/* Sections
	 * Header/Footer/Breadcrumb/Hooks
	 */
	$section_inner_open = '<div class="nxt-sections-inner-fields">';
	$section_inner_close = '</div>';
if(!defined('HELLO_ELEMENTOR_VERSION')){
	/*Header Options*/
	$header_fields->add_field( array(
		'before_row' => 	(!defined('HELLO_ELEMENTOR_VERSION') ? $section_wrap_open.$section_inner_open : ''),
        'name'				=> esc_html__( 'Header Type', 'nexter-ext' ),
        'id'				=> $prefix . 'normal-sticky-header',
        'desc'				=> '',
        'type'				=> 'select',
		'default'			=> 'normal',
		'options' => array(
			'normal' => esc_html__('Normal', 'nexter-ext'),
			'sticky' => esc_html__('Sticky', 'nexter-ext'),
			'both' => esc_html__('Normal + Sticky', 'nexter-ext'),				
		),
		'attributes' => array(			
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => 'header',
		),
    ) );
	$header_fields->add_field( array(
        'name'             => esc_html__( 'Transparent Header', 'nexter-ext' ),
        'id'               => $prefix . 'transparent-header',
        'desc'             => '',
        'type'	           => 'nxt_switch',
        'default'          => 'off',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => 'header',
		),
    ) );
	/*Header Options*/
	
	/*Footer Options*/
	$header_fields->add_field( array(
		'name'	=> esc_html__('Footer Style', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-footer-style',
		'type'	=> 'select',
		'default'  => 'normal',
		'options' => array(
			'normal' => esc_html__('Normal', 'nexter-ext'),
			'fixed' => esc_html__('Fixed', 'nexter-ext'),
			'smart' => esc_html__('Zoom Out Effect', 'nexter-ext'),
		),
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => 'footer',
		),
	) );

	$header_fields->add_field( array(
		'name'				=> esc_html__( 'Background Color', 'nexter-ext' ),
		'id'				=> $prefix . 'hooks-footer-smart-bgcolor',
		'type'    => 'colorpicker',
		'default' => '#292c31',
		'options' => array(
		 	'alpha' => true,
		),
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-footer-style',
			'data-conditional-value' => 'smart',
		),
	) );
	/*Footer Options*/
}
	/*Hooks Actions*/
	$header_fields->add_field( array(
		'before_row' => (defined('HELLO_ELEMENTOR_VERSION') ? $section_wrap_open.$section_inner_open : ''),
		'name'    => esc_html__('Actions Hooks','nexter-ext'),
		'id'      => $prefix . 'display-hooks-action',
		'desc'    => '',
		'type'    => 'pw_select',
		'options' => Nexter_Builder_Display_Conditional_Rules::get_sections_hooks_options(),
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => 'hooks',
		),
	) );
	$header_fields->add_field( array(
		'name' => __( 'Priority', 'nexter-ext' ),
		'desc' => '',		
		'id'   => $prefix . 'hooks-priority',
		'type' => 'text_medium',
		'attributes' => array(
			'type' => 'number',
			'pattern' => '\d*',
		),
		'attributes' => array(
			'placeholder' => 10,
			'type' => 'number',
			'step' => '1',
			'min' => '0',
			'max' => '100',
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => 'hooks',
		),
		'after_row' => $section_inner_close,
	) );
	/*Hooks Actions*/

	/** Code Snippets Options
	 * @since 1.0.9
	 */
	$code_wrap_open = '<div class="nxt-code-wrap-fields">';
	$code_wrap_close ='</div>';
	
	$user_secure_execute = 'yes';
	if ( !current_user_can('unfiltered_html') ){
		$code_wrap_open .='<div class="nxt-permission">'.esc_html__('Note : Your User Role doesn\'t have "unfiltered_html" capabilities. Your added code will be saved but It will not executed. Ask your administrator to give your Proper permissions.','nexter-ext').'</div>';
		$user_secure_execute = 'no';
	}
	
	$header_fields->add_field( array(
		'id'	=> $prefix.'code-snippet-secure-executed',
		'type'	=> 'hidden',
		'default' => $user_secure_execute,
		'escape_cb'  => 'nexter_ext_user_secure_sanitizer',
	) );
	
	$header_fields->add_field( array(
		'before_row' => $code_wrap_open,
		'name'	=> esc_html__('Code Execute', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'code-execute',
		'type'	=> 'radio_inline',
		'classes' => 'nxt-code-execute',
		'options' => array(
			'global' => esc_html__('Run Snippet Front-end/Back-end', 'nexter-ext'),
			'admin' => esc_html__('Only Back-end', 'nexter-ext'),
			'front-end' => esc_html__('Only Front-end', 'nexter-ext'),
		),
		'default' => 'global',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'php',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	$header_fields->add_field( array(
		'name'    => esc_html__('Html Hooks','nexter-ext'),
		'id'      => $prefix . 'code-hooks-action',
		'desc'    => '',
		'type'    => 'pw_select',
		'options' => Nexter_Builder_Display_Conditional_Rules::get_sections_hooks_options(),
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'html',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	$header_fields->add_field( array(
		'id'	=> $prefix.'code-php-hidden-execute',
		'type'	=> 'hidden',
		'default' => 'byyyy',
	) );
	$header_fields->add_field( array(
		'before_field' => '<div class="nxt-php-code-execute-msg hidden"></div>',
		'name'	=> esc_html__('PHP Code', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'code-php-snippet',
		'type'	=> 'textarea',
		'default'  => '',
		'escape_cb'  => 'nexter_ext_field_sanitizer',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'php',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	$header_fields->add_field( array(
		'name'	=> esc_html__('Html Code', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'code-htmlmixed-snippet',
		'type'	=> 'textarea',
		'default'  => '',
		'sanitization_cb'  => 'nexter_ext_field_sanitizer',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'html',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	$header_fields->add_field( array(
		'name'	=> esc_html__('CSS Code', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'code-css-snippet',
		'type'	=> 'textarea',
		'default'  => '',
		'escape_cb'  => 'nexter_ext_field_sanitizer',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'css',
		),
		'show_on_cb' => 'nxt_user_roles_check',
	) );
	$header_fields->add_field( array(
		'name'	=> esc_html__('Javascript Code', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'code-javascript-snippet',
		'type'	=> 'textarea',
		'default'  => '',
		'escape_cb'  => 'nexter_ext_field_sanitizer',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-code-snippet',
			'data-conditional-value' => 'javascript',
		),
		'show_on_cb' => 'nxt_user_roles_check',
		'after_row' => $code_wrap_close,
	) );
	/*Code Snippets Options*/
	
	/* Options
	 * Nexter Rules	 
	*/
	$rules_open = '<div class="nxt-sections-rules-fields">';
	$rules_close = '</div>';
	
	$header_fields->add_field( array(
		'before_row' => $rules_open,
		'name' => esc_html__('Display Rules','nexter-ext'),		
		'type' => 'title',
		'id'   => $prefix . 'display_rule_heading',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => wp_json_encode( array( 'header', 'footer','breadcrumb','hooks') ),
		),
	) );
	
	//Include in
	$header_fields->add_field( array(
		'name'    => esc_html__('Include In','nexter-ext'),
		'id'      => $prefix . 'add-display-rule',
		'desc'    => esc_html__('Select locations where you want to show above template.','nexter-ext'),
		'type'    => 'pw_multiselect',
		'options' => Nexter_Builder_Display_Conditional_Rules::get_location_rules_options(),
		'multigroup' => true,
		/*'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => wp_json_encode( array( 'header', 'footer','breadcrumb','hooks' ) ),
		),*/
	) );
	
	//Others Include Check Field
	foreach($others_location_field as $key => $label){
		$header_fields->add_field( array(
			'name'	=> $label,
			'desc'	=> '',
			'id'	=> $prefix.'hooks-layout-'.$key,
			'type' => 'pw_multiselect',		
			'options' => Nexter_Builder_Display_Conditional_Rules::get_others_location_sub_options($key),
		) );
	}

	$header_fields->add_field( array(
		'name'	=> esc_html__('Specific Pages/Posts', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout-specific',
		'type' => 'pw_multiselect',		
		'options_cb' => 'nexter_get_posts_query_specific',
	) );

	//Exclude Display Rules
	$header_fields->add_field( array(
		'name'    => esc_html__('Exclude From','nexter-ext'),
		'id'      => $prefix . 'exclude-display-rule',
		'desc'    => esc_html__('Select locations where you want to hide above template.','nexter-ext'),
		'type'    => 'pw_multiselect',
		'options' => Nexter_Builder_Display_Conditional_Rules::get_location_rules_options(),
		'multigroup' => true,			
		/*'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-sections',
			'data-conditional-value' => wp_json_encode( array( 'header', 'footer','breadcrumb','hooks' ) ),
		),*/
	) );
	
	//Others Exclude Check Field
	foreach($others_location_field as $key => $label){
		$header_fields->add_field( array(
			'name'	=> $label,
			'desc'	=> '',
			'id'	=> $prefix.'hooks-layout-exclude-'.$key,
			'type' => 'pw_multiselect',		
			'options' => Nexter_Builder_Display_Conditional_Rules::get_others_location_sub_options($key),
		) );
	}
	
	$header_fields->add_field( array(
		'name'	=> esc_html__('Exclude Specific Pages/Posts', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'hooks-layout-exclude-specific',
		'type' => 'pw_multiselect',		
		'options_cb' => 'nexter_get_posts_query_specific',
		'after_row' => $rules_close.$section_wrap_close,
	) );
	/* Sections
	 * Header/Footer/Breadcrumb/Hooks/Code-snippet
	 */
	
	/* Pages
	 * 404 Page/Single/Archive
	 */
	$pages_wrap_open = '<div class="nxt-pages-rules-fields">';
	$pages_wrap_close ='</div>';
	//404 Page
	$header_fields->add_field( array(
		'before_row' => $pages_wrap_open,
		'name' => esc_html__('Disable Header', 'nexter-ext'),
		'desc' => esc_html__('Check this option to disable header.', 'nexter-ext'),
		'id'   => $prefix.'404-disable-header',
		'type' => 'checkbox',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-pages',
			'data-conditional-value' => 'page-404',
		),
	) );
	$header_fields->add_field( array(
		'name' => esc_html__('Disable Footer', 'nexter-ext'),
		'desc' => esc_html__('Check this option to disable footer.', 'nexter-ext'),
		'id'   => $prefix.'404-disable-footer',
		'type' => 'checkbox',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-pages',
			'data-conditional-value' => 'page-404',
		),
	) );

	//Group Repeater Singular 
	$singluar_group = $header_fields->add_field( array(
		'id'          => $prefix.'singular-group',
		'type'        => 'group',
		'repeatable'  => true,
		'classes' => 'nxt-condition-group',
		'options'     => array(
			'group_title'   => esc_html__('Singular {#}', 'nexter-ext'),
			'add_button'    => esc_html__('Add Conditions', 'nexter-ext'),
			'remove_button' => esc_html__('Remove', 'nexter-ext'),
			'closed'        => false,
			'sortable'      => false,
		),
		
	) );
	$header_fields->add_group_field( $singluar_group, array(
		'name'	=> esc_html__('Include/Exclude', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'singular-include-exclude',
		'type'	=> 'radio',
		'classes' => 'nxt-condition-inc-exc',
		'options' => array(
			'include' => esc_html__('Include', 'nexter-ext'),
			'exclude' => esc_html__('Exclude', 'nexter-ext'),
		),
		'default' => 'include',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-pages',
			'data-conditional-value' => 'singular',
		),
	) );
	$singular_wrap_open = '<div class="nxt-singular-group-combine">';
	$singular_wrap_close ='</div>';
	$header_fields->add_group_field( $singluar_group, array(
		'before_row' => $singular_wrap_open,
		'name' => esc_html__('Conditional Rules', 'nexter-ext'),
		'id'   => $prefix.'singular-conditional-rule',
		'type' => 'pw_select',
		'classes' => 'nxt-singular-cond-rule',
		'multigroup' => true,
		'options' => Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions(),
		'default' => 'post',
	) );
	
	$header_fields->add_group_field( $singluar_group, array(
		'after_row'	=> $singular_wrap_close,
		'name' => esc_html__('Condition Type', 'nexter-ext'),
		'id'   => $prefix.'singular-conditional-type',
		'type' => 'pw_multiselect',
		'classes' => 'nxt-singular-cond-type',
		'default' => array('all'),
		'options_cb' => 'nxt_get_type_singular_field',
	) );
	
	/*
	 * Preview Singular Post Field
	 * @since 1.0.2
	 */
	$header_fields->add_field( array(
		'before_row'=> '<div class="nxt-preview-singular"><h3 class="nxt-prev-setting-title">'.esc_html__('Preview Settings', 'nexter-ext').'</h3>',
		'name'		=> esc_html__('Preview Type', 'nexter-ext'),
		'id'		=> $prefix.'singular-preview-type',
		'type'		=> 'pw_select',
		'multigroup'=> true,
		'classes'	=> 'nxt-singular-preview-type',
		'options'	=> Nexter_Builders_Singular_Conditional_Rules::register_post_types_conditions('preview'),
		'default'	=> 'post',
	) );
	$header_fields->add_field( array(
		'after_row'	=> '</div>',
		'name'		=> esc_html__('Preview Id', 'nexter-ext'),
		'id'		=> $prefix.'singular-preview-id',
		'type'		=> 'pw_select',
		'classes'	=> 'nxt-singular-preview-id',
		'default'	=> 'all',
		'options_cb' => 'nxt_get_type_singular_preview_id',
	) );
	
	//Group Repeater Archives 
	$archive_group = $header_fields->add_field( array(
		'id'          => $prefix.'archive-group',
		'type'        => 'group',
		'repeatable'  => true,
		'classes' => 'nxt-archives-group',
		'options'     => array(
			'group_title'   => esc_html__('Archive {#}', 'nexter-ext'),
			'add_button'    => esc_html__('Add Conditions', 'nexter-ext'),
			'remove_button' => esc_html__('Remove', 'nexter-ext'),
			'closed'        => false,
			'sortable'      => false,
		),
		'after_row' => $pages_wrap_close,
	) );
	$header_fields->add_group_field( $archive_group, array(
		'name'	=> esc_html__('Include/Exclude', 'nexter-ext'),
		'desc'	=> '',
		'id'	=> $prefix.'archive-include-exclude',
		'type'	=> 'radio',
		'classes' => 'nxt-condition-archive-inc-exc',
		'options' => array(
			'include' => esc_html__('Include', 'nexter-ext'),
			'exclude' => esc_html__('Exclude', 'nexter-ext'),
		),
		'default' => 'include',
		'attributes' => array(
			'data-conditional-id'    => $prefix.'hooks-layout-pages',
			'data-conditional-value' => 'archives',
		),
	) );
	$archive_wrap_open = '<div class="nxt-archive-group-combine">';
	$archive_wrap_close ='</div>';
	$header_fields->add_group_field( $archive_group, array(
		'before_row' => $archive_wrap_open,
		'name' => esc_html__('Conditional Rules', 'nexter-ext'),
		'id'   => $prefix.'archive-conditional-rule',
		'type' => 'pw_select',
		'classes' => 'nxt-archive-cond-rule',
		'multigroup' => true,
		'options' => Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions(),
		'default' => 'all',
	) );
	
	$header_fields->add_group_field( $archive_group, array(
		'after_row' => $archive_wrap_close,
		'name' => esc_html__('Condition Type', 'nexter-ext'),
		'id'   => $prefix.'archive-conditional-type',
		'type' => 'pw_multiselect',
		'classes' => 'nxt-archive-cond-type',
		'default' => array('all'),
		'options_cb' => 'nxt_get_type_archives_field',
	) );
	
	/*
	 * Preview Archive Post Field
	 * @since 1.0.2
	 */
	$header_fields->add_field( array(
		'before_row'=> '<div class="nxt-preview-archive"><h3 class="nxt-prev-setting-title">'.esc_html__('Preview Settings', 'nexter-ext').'</h3>',
		'name'		=> esc_html__('Preview Type', 'nexter-ext'),
		'id'		=> $prefix.'archive-preview-type',
		'type'		=> 'pw_select',
		'multigroup'=> true,
		'classes'	=> 'nxt-archive-preview-type',
		'options'	=> Nexter_Builders_Archives_Conditional_Rules::register_post_type_conditions( 'preview' ),
		'default'	=> 'all',
	) );
	$header_fields->add_field( array(
		'after_row'	=> '</div>',
		'name'		=> esc_html__('Preview Id', 'nexter-ext'),
		'id'		=> $prefix.'archive-preview-id',
		'type'		=> 'pw_select',
		'classes'	=> 'nxt-archive-preview-id',
		'default'	=> 'all',
		'options_cb' => 'nxt_get_type_archives_preview_id',
	) );
	 /* Pages
	 * 404 Page/Single/Archive
	 */
}

function nexter_ext_field_sanitizer($value, $field_args, $field){
	return wp_unslash($value);
}
function nexter_ext_user_secure_sanitizer($value, $field_args, $field){
	if ( !current_user_can('unfiltered_html') ){
		return "no";
	}
	return "yes";
}

if(!function_exists('nxt_user_roles_check')){
	function nxt_user_roles_check(){
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
}