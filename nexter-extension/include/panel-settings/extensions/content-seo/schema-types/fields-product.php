<?php
/**
 * Field definitions: Product (WooCommerce-aligned; template variables).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/product-select-groups.php';

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the page URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%post.url%#%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to Product.', 'nexter-extension' ),
		'default' => 'Product',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the full name of the product.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'description',
		'label'   => __( 'Description', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Write a short summary of the product (for example: features or benefits).', 'nexter-extension' ),
		'default' => '%product.short_description%',
	),
	array(
		'key'     => 'brand',
		'subkey'  => '@type',
		'label'   => __( 'Brand Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Specify the type of brand (usually set to Brand).', 'nexter-extension' ),
		'default' => 'Brand',
	),
	array(
		'key'     => 'brand',
		'subkey'  => 'name',
		'label'   => __( 'Brand Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the product’s brand.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'url',
		'label'   => __( 'URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the link to the product page on your website.', 'nexter-extension' ),
		'default' => '%post.url%',
	),
	array(
		'key'     => 'sku',
		'label'   => __( 'SKU', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the product SKU (Stock Keeping Unit) if available.', 'nexter-extension' ),
		'default' => '%product.sku%',
	),
	array(
		'key'     => 'image',
		'subkey'  => '@id',
		'label'   => __( 'Image @id', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add a unique ID for the product image (optional).', 'nexter-extension' ),
		'default' => '%product.image%',
	),
	array(
		'key'     => 'image',
		'subkey'  => '@type',
		'label'   => __( 'Image Type', 'nexter-extension' ),
		'input'   => 'text',
		'default' => 'ImageObject',
	),
	array(
		'key'     => 'image',
		'subkey'  => 'url',
		'label'   => __( 'Image URL', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.image%',
	),
	array(
		'key'     => 'image',
		'subkey'  => 'width',
		'label'   => __( 'Image Width', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.image_width%',
	),
	array(
		'key'     => 'image',
		'subkey'  => 'height',
		'label'   => __( 'Image Height', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.image_height%',
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (for example: article, product, or review).', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'aggregateRating',
		'subkey'  => 'ratingValue',
		'label'   => __( 'Rating Value', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the average rating score for the product.', 'nexter-extension' ),
		'default' => '%product.rating_value%',
	),
	array(
		'key'     => 'aggregateRating',
		'subkey'  => 'reviewCount',
		'label'   => __( 'Review Count', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the total number of reviews for the product.', 'nexter-extension' ),
		'default' => '%product.review_count%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => '@type',
		'label'   => __( 'Offer Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Select if the product is simple or has multiple versions (variable).', 'nexter-extension' ),
		'default' => 'Offer',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'price',
		'label'   => __( 'Price', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the current sale price of the product.', 'nexter-extension' ),
		'default' => '%product.price%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'priceCurrency',
		'label'   => __( 'Price Currency', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.currency%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'availability',
		'label'   => __( 'Availability', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Select the product availability. "Automatic" uses the live WooCommerce stock status; the rest are fixed schema.org values.', 'nexter-extension' ),
		'default' => '%product.stock%',
		'options' => nexter_content_seo_schema_product_availability_groups(),
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'lowPrice',
		'label'   => __( 'Low Price', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the lowest price for product variations.', 'nexter-extension' ),
		'default' => '%product.low_price%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'highPrice',
		'label'   => __( 'High Price', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.high_price%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'offerCount',
		'label'   => __( 'Offer Count', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%product.offer_count%',
	),
);
