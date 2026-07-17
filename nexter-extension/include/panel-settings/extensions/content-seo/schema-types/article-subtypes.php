<?php
/**
 * JSON-LD @type options for Article schema rows (Article, NewsArticle, BlogPosting).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_article_subtype_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_article_subtype_groups() {
		$groups = array(
			'article' => array(
				'label'   => __( 'Schema Type', 'nexter-extension' ),
				'options' => array(
					'Article'     => __( 'Article', 'nexter-extension' ),
					'NewsArticle' => __( 'NewsArticle', 'nexter-extension' ),
					'BlogPosting' => __( 'BlogPosting', 'nexter-extension' ),
				),
			),
		);

		return apply_filters( 'nexter_content_seo_article_schema_type_options', $groups );
	}
}
