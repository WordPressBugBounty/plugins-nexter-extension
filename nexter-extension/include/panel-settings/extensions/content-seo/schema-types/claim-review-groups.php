<?php
/**
 * Select options: ClaimReview author @type (Person | Organization).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_claim_review_author_type_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_claim_review_author_type_groups() {
		return array(
			'author' => array(
				'label'   => __( 'Author type', 'nexter-extension' ),
				'options' => array(
					'Person'       => __( 'Person', 'nexter-extension' ),
					'Organization' => __( 'Organization', 'nexter-extension' ),
				),
			),
		);
	}
}
