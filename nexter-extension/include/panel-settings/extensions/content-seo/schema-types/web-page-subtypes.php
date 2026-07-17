<?php
/**
 * JSON-LD @type options for WebPage schema rows (CollectionPage, AboutPage, etc.).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_webpage_subtype_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_webpage_subtype_groups() {
		$groups = array(
			'page' => array(
				'label'   => __( 'Page type', 'nexter-extension' ),
				'options' => array(
					'WebPage'        => __( 'WebPage', 'nexter-extension' ),
					'CollectionPage' => __( 'CollectionPage', 'nexter-extension' ),
					'AboutPage'      => __( 'AboutPage', 'nexter-extension' ),
					'ContactPage'    => __( 'ContactPage', 'nexter-extension' ),
				),
			),
		);

		return apply_filters( 'nexter_content_seo_webpage_schema_type_options', $groups );
	}
}
