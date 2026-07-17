<?php
/**
 * Field definitions: HowTo (Schema.org HowTo).
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
		'default' => '%post.url%#howto-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to HowTo.', 'nexter-extension' ),
		'default' => 'HowTo',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the title of your how-to guide.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add a brief summary explaining what this guide will help users achieve.', 'nexter-extension' ),
		'default' => '%post.excerpt%',
	),
	array(
		'key'     => 'image',
		'label'   => __( 'Image', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the main image URL showing the completed task.', 'nexter-extension' ),
		'default' => '%post.thumbnail%',
	),
	array(
		'key'     => 'step',
		'label'   => __( 'Steps', 'nexter-extension' ),
		'input'   => 'how_to_step_list',
		'tooltip' => __( 'Add each step of the instructions one by one. You can also add images or videos for each step.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'supply',
		'label'   => __( 'Supplies', 'nexter-extension' ),
		'input'   => 'how_to_supply_list',
		'tooltip' => __( 'List the supplies or materials needed to complete the task.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'tool',
		'label'   => __( 'Tools', 'nexter-extension' ),
		'input'   => 'how_to_tool_list',
		'tooltip' => __( 'List the tools needed (for example: hammer, laptop, software).', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'totalTime',
		'label'   => __( 'Total Time', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the total time required to complete all steps (for example: 30 minutes).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'estimatedCost',
		'label'   => __( 'Estimated Cost', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the estimated cost of all the materials or supplies.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'isPartOf',
		'label'   => __( 'Is Part Of', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this if the guide belongs to a larger section or website (optional).', 'nexter-extension' ),
		'default' => '%schemas.webpage%',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (for example: article, product, or how-to).', 'nexter-extension' ),
		'default' => '',
	),
);
