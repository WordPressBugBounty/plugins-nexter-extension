<?php 
/*
 * Nexter Builder Register Post Type
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
function nexter_builders_register_post() {
	if(defined('NXT_PRO_EXT')){
		$options = get_option( 'nexter_white_label' );
		$builder_name = (!empty($options['brand_name'])) ? $options['brand_name'].' Builder' : __( 'Nexter Builder', 'nexter-ext' );
	}else{
		$builder_name = 'Nexter Builder';
	}
	$labels = array(
		'name'                  => $builder_name,
		'singular_name'         => $builder_name,
		'menu_name'             => $builder_name,
		'name_admin_bar'        => $builder_name,
		'archives'              => __( 'Template Archives', 'nexter-ext' ),
		'attributes'            => __( 'Template Attributes', 'nexter-ext' ),
		'parent_item_colon'     => __( 'Parent Template:', 'nexter-ext' ),
		'all_items'             => __( 'All Templates', 'nexter-ext' ),
		'add_new_item'          => __( 'Add New Template', 'nexter-ext' ),
		'add_new'               => __( 'Add New', 'nexter-ext' ),
		'new_item'              => __( 'New Template', 'nexter-ext' ),
		'edit_item'             => __( 'Edit Template', 'nexter-ext' ),
		'update_item'           => __( 'Update Template', 'nexter-ext' ),
		'view_item'             => __( 'View Template', 'nexter-ext' ),
		'view_items'            => __( 'View Template', 'nexter-ext' ),
		'search_items'          => __( 'Search Template', 'nexter-ext' ),
		'not_found'             => __( 'Not found', 'nexter-ext' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'nexter-ext' ),
		'featured_image'        => __( 'Featured Image', 'nexter-ext' ),
		'set_featured_image'    => __( 'Set featured image', 'nexter-ext' ),
		'remove_featured_image' => __( 'Remove featured image', 'nexter-ext' ),
		'use_featured_image'    => __( 'Use as featured image', 'nexter-ext' ),
		'insert_into_item'      => __( 'Insert into template', 'nexter-ext' ),
		'uploaded_to_this_item' => __( 'Uploaded to this template', 'nexter-ext' ),
		'items_list'            => __( 'Templates list', 'nexter-ext' ),
		'items_list_navigation' => __( 'Templates list navigation', 'nexter-ext' ),
		'filter_items_list'     => __( 'Filter templates list', 'nexter-ext' ),
	);
	$args = array(
		'label'                 => __( 'Post Type', 'nexter-ext' ),
		'description'           => __( 'Post Type Description', 'nexter-ext' ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'revisions','elementor' ),
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 5,
		'menu_icon'             => 'dashicons-nxt-builder-groups',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => true,
		'exclude_from_search'   => true,
		'publicly_queryable'    => true,
		'capability_type'       => 'page',
		'show_in_rest'			=> true,
	);
	register_post_type( 'nxt_builder', $args );

}
add_action( 'init', 'nexter_builders_register_post', 0 );

function nexter_template_frontend() {
	if ( is_singular( 'nxt_builder' ) && ! current_user_can( 'edit_posts' ) ) {
		wp_redirect( home_url(), 301 );
		die;
	}
}
add_action( 'template_redirect','nexter_template_frontend' );

// Register Nexter Builder Category
function nexter_builder_category_register() {

	if(defined('NXT_PRO_EXT')){
		$options = get_option( 'nexter_white_label' );
		$cate_name = (!empty($options['brand_name'])) ? $options['brand_name'].' Category' : __( 'Nexter Category', 'nexter-ext' );
	}else{
		$cate_name = 'Nexter Category';
	}

	$labels = array(
		'name'                       => $cate_name,
		'singular_name'              => $cate_name,
		'menu_name'                  => $cate_name,
		'all_items'                  => __( 'All Categories', 'nexter-ext' ),
		'parent_item'                => __( 'Parent Category', 'nexter-ext' ),
		'parent_item_colon'          => __( 'Parent Category:', 'nexter-ext' ),
		'new_item_name'              => __( 'New Category Name', 'nexter-ext' ),
		'add_new_item'               => __( 'Add New Category', 'nexter-ext' ),
		'edit_item'                  => __( 'Edit Category', 'nexter-ext' ),
		'update_item'                => __( 'Update Category', 'nexter-ext' ),
		'view_item'                  => __( 'View Category', 'nexter-ext' ),
		'separate_items_with_commas' => __( 'Separate items with commas', 'nexter-ext' ),
		'add_or_remove_items'        => __( 'Add or remove items', 'nexter-ext' ),
		'choose_from_most_used'      => __( 'Choose from the most used', 'nexter-ext' ),
		'popular_items'              => __( 'Popular Category', 'nexter-ext' ),
		'search_items'               => __( 'Search Category', 'nexter-ext' ),
		'not_found'                  => __( 'Not Found', 'nexter-ext' ),
		'no_terms'                   => __( 'No items', 'nexter-ext' ),
		'items_list'                 => __( 'Category list', 'nexter-ext' ),
		'items_list_navigation'      => __( 'Category list navigation', 'nexter-ext' ),
	);
	$args = array(
		'labels'                     => $labels,
		'hierarchical'               => true,
		'public'                     => true,
		'show_ui'                    => true,
		'show_admin_column'          => false,
		'show_in_nav_menus'          => true,
		'show_tagcloud'              => true,
	);
	register_taxonomy( 'nxt_builder_category', array( 'nxt_builder' ), $args );

}
add_action( 'init', 'nexter_builder_category_register', 0 );

function nexter_builder_filter_by_category( $post_type ) {
	if ( 'nxt_builder' !== $post_type ) {
		return;
	}

	$all_items = get_taxonomy( 'nxt_builder_category' )->labels->all_items;

	$dropdown_options = array(
		'show_option_all' => $all_items,
		'show_option_none' => '',
		'hide_empty' => 0,
		'hierarchical' => 1,
		'show_count' => 0,
		'orderby' => 'name',
		'value_field' => 'slug',
		'taxonomy' => 'nxt_builder_category',
		'name' => 'nxt_builder_category',
		'selected' => empty( $_GET[ 'nxt_builder_category' ] ) ? '' : sanitize_text_field(wp_unslash($_GET[ 'nxt_builder_category' ])),
	);

	echo '<label class="screen-reader-text" for="cat">' . esc_html__( 'Filter by category', 'nexter-ext' ) . '</label>';
	wp_dropdown_categories( $dropdown_options );
}

add_action( 'restrict_manage_posts', 'nexter_builder_filter_by_category' );
