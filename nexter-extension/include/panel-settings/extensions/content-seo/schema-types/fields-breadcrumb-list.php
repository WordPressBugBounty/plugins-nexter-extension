<?php
/**
 * Field definitions: BreadcrumbList (Schema.org BreadcrumbList).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the page URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%post.url%#breadcrumb-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to BreadcrumbList.', 'nexter-extension' ),
		'default' => 'BreadcrumbList',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter a name for the breadcrumb list. This is used in structured data.', 'nexter-extension' ),
		'default' => 'BreadcrumbList',
	),
	array(
		'key'     => 'itemListElement',
		'label'   => __( 'Breadcrumb Items', 'nexter-extension' ),
		'input'   => 'breadcrumb_items',
		'tooltip' => __( 'Add the breadcrumb path step by step (each item should have a title and a URL).', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'breadcrumbJsonFallback',
		'label'   => __( 'Item List (JSON Or Variable)', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this only if you’re not adding items manually above. You can enter a JSON list or use a dynamic variable like breadcrumbs.', 'nexter-extension' ),
		'default' => '%current.breadcrumbs%',
	),
	array(
		'key'     => 'customFields',
		'label'   => __( 'Custom Fields', 'nexter-extension' ),
		'input'   => 'key_value_list',
		'tooltip' => __( 'Add any extra structured data fields if needed (advanced use only).', 'nexter-extension' ),
		'default' => array(),
	),
);
