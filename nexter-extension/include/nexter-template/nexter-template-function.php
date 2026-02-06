<?php 
/*
 * Nexter Builder Register Post Type
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
if( ! function_exists('nexter_builders_register_post')){
	function nexter_builders_register_post() {
		$builder_name = 'Theme Builder';
		$labels = array(
			'name'                  => $builder_name,
			'singular_name'         => $builder_name,
			'menu_name'             => $builder_name,
			'name_admin_bar'        => $builder_name,
			'archives'              => __( 'Template Archives', 'nexter-extension' ),
			'attributes'            => __( 'Template Attributes', 'nexter-extension' ),
			'parent_item_colon'     => __( 'Parent Template:', 'nexter-extension' ),
			'all_items'             => $builder_name,
			'add_new_item'          => __( 'Add New Template', 'nexter-extension' ),
			'add_new'               => __( 'Create New', 'nexter-extension' ),
			'new_item'              => __( 'New Template', 'nexter-extension' ),
			'edit_item'             => __( 'Edit Template', 'nexter-extension' ),
			'update_item'           => __( 'Update Template', 'nexter-extension' ),
			'view_item'             => __( 'View Template', 'nexter-extension' ),
			'view_items'            => __( 'View Template', 'nexter-extension' ),
			'search_items'          => __( 'Search Template', 'nexter-extension' ),
			'not_found'             => __( 'Not found', 'nexter-extension' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'nexter-extension' ),
			'featured_image'        => __( 'Featured Image', 'nexter-extension' ),
			'set_featured_image'    => __( 'Set featured image', 'nexter-extension' ),
			'remove_featured_image' => __( 'Remove featured image', 'nexter-extension' ),
			'use_featured_image'    => __( 'Use as featured image', 'nexter-extension' ),
			'insert_into_item'      => __( 'Insert into template', 'nexter-extension' ),
			'uploaded_to_this_item' => __( 'Uploaded to this template', 'nexter-extension' ),
			'items_list'            => __( 'Templates list', 'nexter-extension' ),
			'items_list_navigation' => __( 'Templates list navigation', 'nexter-extension' ),
			'filter_items_list'     => __( 'Filter templates list', 'nexter-extension' ),
		);
		//$builder_switch = get_option('nxt_builder_switcher', false);
		$args = array(
			'label'                 => __( 'Post Type', 'nexter-extension' ),
			'description'           => __( 'Post Type Description', 'nexter-extension' ),
			'labels'                => $labels,
			'supports'              => array( 'title', 'editor', 'revisions','elementor' ),
			'hierarchical'          => false,
			'public'                => true,
			'show_ui'               => true,
			'show_in_menu' => false,
			'show_in_admin_bar'     => true,
			'show_in_nav_menus'     => true,
			'can_export'            => true,
			'has_archive'           => true,
			'exclude_from_search'   => true,
			'publicly_queryable'    => true,
			'capability_type'       => 'page',
			'show_in_rest'			=> true,
			'show_in_editor'        => true,
		);
		register_post_type( 'nxt_builder', $args );
	
	}
	add_action( 'init', 'nexter_builders_register_post', 0 );
}

function nexter_template_frontend() {
	if ( is_singular( 'nxt_builder' ) && ! current_user_can( 'edit_posts' ) ) {
		wp_safe_redirect( home_url(), 301 );
		die;
	}
}
add_action( 'template_redirect','nexter_template_frontend' );

add_action('template_redirect', function() {
    if (!is_singular('nxt_builder')) {
        return;
    }

    // Check for preview query
    if (!isset($_GET['preview_template'])) {
        return;
    }

    global $post;

    // Disable admin bar and default layout
    add_filter('show_admin_bar', '__return_false');

    // Output minimal clean HTML
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html style="margin-top: 0 !important;">';
    wp_head(); 
    echo '<body class="nxt-builder-preview-only">';
    
    // Render the builder content properly
	if(!empty($post->post_content)){
		$content = apply_filters('the_content', $post->post_content);
    	echo $content;
	}else{
		$layout = get_post_meta($post->ID, 'nxt-hooks-layout-sections', true);
		echo '<img src="'.esc_url(NEXTER_EXT_URL.'theme-builder/assets/svg/'.esc_attr($layout).'.svg').'" alt="'.esc_attr($layout).'" style="width: 100%;" />';
	}
     wp_footer();
    echo '</body></html>';

    exit;
});