<?php
/**
 * Field definitions: Service (Schema.org Service).
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
		'default' => '%post.url%#service-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to Service.', 'nexter-extension' ),
		'default' => 'Service',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Service Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the service you offer.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'serviceType',
		'label'   => __( 'Service Type', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The category of the service (for example: Plumbing, Web Design, Consulting).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short summary of the service and what it includes. Keep it around 240 characters.', 'nexter-extension' ),
		'default' => '%post.excerpt%',
	),
	array(
		'key'     => 'provider',
		'subkey'  => '@type',
		'label'   => __( 'Provider Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'The type of entity providing the service (usually Organization or Person).', 'nexter-extension' ),
		'default' => 'Organization',
	),
	array(
		'key'     => 'provider',
		'subkey'  => 'name',
		'label'   => __( 'Provider Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The name of the business or person providing the service.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'provider',
		'subkey'  => 'url',
		'label'   => __( 'Provider URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The website of the service provider.', 'nexter-extension' ),
		'default' => '%site.url%',
	),
	array(
		'key'     => 'areaServed',
		'label'   => __( 'Area Served', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The geographic area where the service is offered (for example: a city, region, or country).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The link to the page describing this service.', 'nexter-extension' ),
		'default' => '%post.url%',
	),
	array(
		'key'     => 'image',
		'label'   => __( 'Image', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'A representative image for the service (optional).', 'nexter-extension' ),
		'default' => '%post.thumbnail%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => '@type',
		'label'   => __( 'Offer Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'The type of offer (usually set to Offer).', 'nexter-extension' ),
		'default' => 'Offer',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'price',
		'label'   => __( 'Price', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The price of the service (leave empty if not applicable).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'priceCurrency',
		'label'   => __( 'Price Currency', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The currency of the price (for example: USD, EUR, INR).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'aggregateRating',
		'subkey'  => 'ratingValue',
		'label'   => __( 'Rating Value', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The average rating score for the service.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'aggregateRating',
		'subkey'  => 'reviewCount',
		'label'   => __( 'Review Count', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'The total number of reviews for the service.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (optional).', 'nexter-extension' ),
		'default' => '',
	),
);
