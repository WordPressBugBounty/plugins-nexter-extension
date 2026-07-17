<?php
/**
 * Field definitions: WebSite
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name or brand of your website.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'author',
		'label'   => __( 'Author', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization who owns the website.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'copyrightHolder',
		'label'   => __( 'Copyright Holder', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization that holds the copyright for the website.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short summary of what your website is about.', 'nexter-extension' ),
		'default' => '%site.description%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the main link (URL) of your website.', 'nexter-extension' ),
		'default' => '%site.url%',
	),
	array(
		'key'     => 'potentialAction',
		'label'   => __( 'Potential Action', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this for advanced features like adding a search box to search results.', 'nexter-extension' ),
		'default' => '%schemas.searchaction%',
	),
	array(
		'key'     => 'publisher',
		'subkey'  => '@id',
		'label'   => __( 'Publisher', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the organization that publishes the website.', 'nexter-extension' ),
		'default' => '%site.url%',
	),
	array(
		'key'     => 'thumbnailUrl',
		'subkey'  => '@id',
		'label'   => __( 'Thumbnail URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the link to a preview image for your website (like a logo).', 'nexter-extension' ),
		'default' => '%website_details.website_logo%',
	),
);
