<?php
/**
 * Field definitions: Person (Schema.org Person).
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
		'tooltip' => __( "A unique ID for this schema. You can use the person's profile URL or leave the default value as it is.", 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The full name of the person.', 'nexter-extension' ),
		'default' => '%author.display_name%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( "The URL of the person's profile or website.", 'nexter-extension' ),
		'default' => '%author.posts_url%',
	),
	array(
		'key'     => 'givenName',
		'label'   => __( 'Given Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( "The person's first name.", 'nexter-extension' ),
		'default' => '%author.first_name%',
	),
	array(
		'key'     => 'familyName',
		'label'   => __( 'Family Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( "The person's last name.", 'nexter-extension' ),
		'default' => '%author.last_name%',
	),
	array(
		'key'     => 'brand',
		'label'   => __( 'Brand', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The brand or site name associated with this person.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The primary page or entity that this person represents.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'A short bio or description of the person.', 'nexter-extension' ),
		'default' => '%author.description%',
	),
	array(
		'key'     => 'email',
		'label'   => __( 'Email', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The contact email address for the person.', 'nexter-extension' ),
		'default' => '%author.email%',
	),
	array(
		'key'     => 'image',
		'label'   => __( 'Image', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The URL of an image representing the person.', 'nexter-extension' ),
		'default' => '%author.avatar%',
	),
	array(
		'key'     => 'telephone',
		'label'   => __( 'Telephone', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The contact phone number for the person.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'sameAs',
		'label'   => __( 'Same As', 'nexter-extension' ),
		'input'   => 'string_list',
		'tooltip' => __( "Links to the person's social media profiles or other relevant pages.", 'nexter-extension' ),
		'default' => array(),
	),
);
