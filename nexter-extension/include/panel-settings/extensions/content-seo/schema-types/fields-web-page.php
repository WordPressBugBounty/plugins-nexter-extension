<?php
/**
 * Field definitions: WebPage (Schema.org WebPage).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/web-page-subtypes.php';

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the page URL or leave the default value as it is.', 'nexter-extension' ),
		// Use %site.url%#webpage (NOT %current.url%#webpage): (1) it must MATCH the value Article
		// uses for isPartOf / mainEntityOfPage (both %site.url%#webpage) or those refs dangle and get
		// pruned; (2) %site.url% is the token proven to resolve for node @ids (Organization uses
		// %site.url%#organization and renders), whereas %current.url% resolved empty here, dropping
		// the WebPage @id entirely.
		'default' => '%site.url%#webpage',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Choose the type of page (for example: AboutPage, ContactPage, CollectionPage).', 'nexter-extension' ),
		'default' => 'WebPage',
		'options' => nexter_content_seo_schema_webpage_subtype_groups(),
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Title of the page as it should appear in structured data.', 'nexter-extension' ),
		'default' => '%current.title%',
	),
	array(
		'key'     => 'author',
		'label'   => __( 'Author', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization who created the page content.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'inLanguage',
		'label'   => __( 'In Language', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the language of the page (for example: en-US).', 'nexter-extension' ),
		'default' => '%site.language%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the main link (URL) of this page.', 'nexter-extension' ),
		'default' => '%current.url%',
	),
	array(
		'key'     => 'breadcrumb',
		'label'   => __( 'Breadcrumb', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the breadcrumb structure for this page (optional).', 'nexter-extension' ),
		'default' => '%schemas.breadcrumblist%',
	),
	array(
		'key'     => 'contributor',
		'label'   => __( 'Contributor', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of any person or organization that helped create the page.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'copyrightHolder',
		'label'   => __( 'Copyright Holder', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization that owns the copyright for the content.', 'nexter-extension' ),
		'default' => '%schemas.person%',
	),
	array(
		'key'     => 'datePublished',
		'label'   => __( 'Date Published', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the date when the page was first published.', 'nexter-extension' ),
		'default' => '%post.date_c%',
	),
	array(
		'key'     => 'dateModified',
		'label'   => __( 'Date Modified', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the date when the page was last updated.', 'nexter-extension' ),
		'default' => '%post.modified_date_c%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short summary of the page to help search engines understand the content.', 'nexter-extension' ),
		'default' => '%post.excerpt%',
	),
	array(
		'key'     => 'isPartOf',
		'label'   => __( 'Is Part Of', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Use this if the page belongs to a larger section or website (optional).', 'nexter-extension' ),
		'default' => '%schemas.website%',
	),
	array(
		'key'     => 'publisher',
		'label'   => __( 'Publisher', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the person or organization that published the page.', 'nexter-extension' ),
		'default' => '%schemas.organization%',
	),
	array(
		'key'     => 'thumbnailUrl',
		'label'   => __( 'Thumbnail URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the link to a small preview image (thumbnail) for the page.', 'nexter-extension' ),
		'default' => '%post.thumbnail%',
	),
	array(
		'key'     => 'mainEntity',
		'label'   => __( 'Main Entity', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Specify the main item being described on the page (optional).', 'nexter-extension' ),
		'default' => '',
	),
);
