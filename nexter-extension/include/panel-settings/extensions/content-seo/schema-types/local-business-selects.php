<?php
/**
 * Select groups for LocalBusiness (reservations, contact point options).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_local_business_accepts_reservations_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_local_business_accepts_reservations_groups() {
		return array(
			'res' => array(
				'label'   => '',
				'options' => array(
					'True'  => __( 'True', 'nexter-extension' ),
					'False' => __( 'False', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_contact_point_option_groups' ) ) {
	/**
	 * ContactPoint.contactOption (schema.org URL values).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_contact_point_option_groups() {
		return array(
			'opt' => array(
				'label'   => '',
				'options' => array(
					'https://schema.org/TollFree' => __( 'Toll-free number', 'nexter-extension' ),
					'https://schema.org/HearingImpairedSupported' => __( 'Hearing-impaired support', 'nexter-extension' ),
				),
			),
		);
	}
}
