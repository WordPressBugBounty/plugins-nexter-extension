<?php
/**
 * Field definitions: Organization
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/organization-subtypes.php';

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the site URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%site.url%#organization',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Select the type that best describes the organization, such as Corporation, NGO, or EducationalOrganization. This is output as JSON-LD @type.', 'nexter-extension' ),
		'default' => 'Organization',
		'options' => nexter_content_seo_schema_organization_subtype_groups(),
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The name of your organization.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( "The URL of your organization's website.", 'nexter-extension' ),
		'default' => '%site.url%',
	),
	array(
		'key'     => 'email',
		'label'   => __( 'Email', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The contact email address for your organization.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'telephone',
		'label'   => __( 'Telephone', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The primary contact phone number for your organization.', 'nexter-extension' ),
		'default' => '%website_details.website_owner_phone%',
	),
	array(
		'key'     => 'faxNumber',
		'label'   => __( 'Fax Number', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The fax number for your organization (if any).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'founder',
		'label'   => __( 'Founder', 'nexter-extension' ),
		'input'   => 'person_list',
		'tooltip' => __( 'The name of the person or people who founded the organization.', 'nexter-extension' ),
		'default' => array(
			array(
				'@type' => 'Person',
				'name'  => '%schema.primary_user_display_name%',
			),
		),
	),
	array(
		'key'     => 'foundingDate',
		'label'   => __( 'Founding Date', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The date the organization was founded.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'keywords',
		'label'   => __( 'Keywords', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add some keywords that describe your organization.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'logo',
		'label'   => __( 'Logo URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( "The URL of your organization's logo image.", 'nexter-extension' ),
		'default' => '%website_details.website_logo%',
	),
	array(
		'key'     => 'sameAs',
		'label'   => __( 'Same As', 'nexter-extension' ),
		'input'   => 'string_list',
		'tooltip' => __( "Links to your organization's social media profiles or other relevant pages.", 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'slogan',
		'label'   => __( 'Slogan', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The official slogan or tagline of your organization.', 'nexter-extension' ),
		'default' => '%site.description%',
	),
);
