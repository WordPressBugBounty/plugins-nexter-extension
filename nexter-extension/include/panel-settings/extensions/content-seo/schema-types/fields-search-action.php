<?php
/**
 * Field definitions: SearchAction (Schema.org SearchAction).
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
		'tooltip' => __( 'A unique ID for this schema. You can use the site URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%site.url%#searchaction',
	),
	array(
		'key'     => 'target',
		'label'   => __( 'Target', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The URL template used for search (for example: https://example.com/?s={search_term_string}).', 'nexter-extension' ),
		'default' => '%site.search_url%',
	),
	array(
		'key'     => 'query-input',
		'label'   => __( 'Query Input', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'The name of the parameter used for the search term (for example: name=search_term_string).', 'nexter-extension' ),
		'default' => 'required name=search_term_string',
	),
);
