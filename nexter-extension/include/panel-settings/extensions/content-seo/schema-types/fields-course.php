<?php
/**
 * Field definitions: Course (Schema.org Course).
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
		'default' => '%post.url%#course-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to Course.', 'nexter-extension' ),
		'default' => 'Course',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Course Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the full name of the course.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Course Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short description of the course and what students will learn. Try to keep it around 240 characters.', 'nexter-extension' ),
		'default' => '%post.excerpt%',
	),
	array(
		'key'     => 'courseCode',
		'label'   => __( 'Course Code', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter a unique code or ID for the course (for example: CS101).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'provider',
		'label'   => __( 'Course Provider', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the organization or person providing the course.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'educationalCredentialAwarded',
		'label'   => __( 'Credential Awarded', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the qualification or certificate students receive after completing the course.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'hasCourseInstance',
		'label'   => __( 'Course Instances', 'nexter-extension' ),
		'input'   => 'course_instance_list',
		'tooltip' => __( 'Add details about when and where the course is held (for example: online, in-person, dates, and instructors).', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'offers',
		'label'   => __( 'Course Pricing', 'nexter-extension' ),
		'input'   => 'course_offer_list',
		'tooltip' => __( 'Add the price, currency, and type of offer for the course.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'hasPart',
		'label'   => __( 'Course Program Parts', 'nexter-extension' ),
		'input'   => 'course_part_list',
		'tooltip' => __( 'If this course is part of a larger program, add those child courses here.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'isPartOf',
		'label'   => __( 'Is Part Of', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this if the course belongs to a larger section or website (optional).', 'nexter-extension' ),
		'default' => '%schemas.webpage%',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (for example: article, product, or course).', 'nexter-extension' ),
		'default' => '',
	),
);
