<?php
/**
 * Select-group options for Product schema fields.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_product_availability_groups' ) ) {
	/**
	 * Restricted, valid schema.org values for Product offers.availability — replaces the old
	 * free-text editor input so users can only pick a valid ItemAvailability value. The first
	 * option keeps the WooCommerce stock token (%product.stock%) so Woo products still resolve
	 * their live stock status at output; the rest are the canonical schema.org ItemAvailability
	 * URLs (matching normalize_schema_availability()).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_product_availability_groups() {
		return array(
			'availability' => array(
				'label'   => __( 'Availability', 'nexter-extension' ),
				'options' => array(
					'%product.stock%'                 => __( 'Automatic (from WooCommerce stock)', 'nexter-extension' ),
					'https://schema.org/InStock'      => __( 'In stock', 'nexter-extension' ),
					'https://schema.org/OutOfStock'   => __( 'Out of stock', 'nexter-extension' ),
					'https://schema.org/BackOrder'    => __( 'On back order', 'nexter-extension' ),
					'https://schema.org/PreOrder'     => __( 'Pre-order', 'nexter-extension' ),
					'https://schema.org/PreSale'      => __( 'Pre-sale', 'nexter-extension' ),
					'https://schema.org/SoldOut'      => __( 'Sold out', 'nexter-extension' ),
					'https://schema.org/LimitedAvailability' => __( 'Limited availability', 'nexter-extension' ),
					'https://schema.org/InStoreOnly'  => __( 'In store only', 'nexter-extension' ),
					'https://schema.org/OnlineOnly'   => __( 'Online only', 'nexter-extension' ),
					'https://schema.org/Discontinued' => __( 'Discontinued', 'nexter-extension' ),
				),
			),
		);
	}
}
