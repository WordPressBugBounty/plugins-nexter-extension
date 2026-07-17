<?php
/**
 * Field definitions: FAQPage (Schema.org FAQPage).
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
		'default' => '%post.url%#faq-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to FAQPage.', 'nexter-extension' ),
		'default' => 'FAQPage',
	),
	array(
		'key'     => 'mainEntity',
		'label'   => __( 'FAQ Questions', 'nexter-extension' ),
		'input'   => 'faq_main_entity_list',
		'tooltip' => __( 'Add your FAQ questions and answers one by one.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'isPartOf',
		'label'   => __( 'Is Part Of', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this if the FAQ belongs to a larger page or article (optional).', 'nexter-extension' ),
		'default' => '%schemas.webpage%',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (for example: article, product, or FAQ).', 'nexter-extension' ),
		'default' => '',
	),
);
