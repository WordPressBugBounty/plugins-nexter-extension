<?php
/**
 * Select groups for VideoObject (interaction type, quality, booleans, content rating).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_video_interaction_type_groups' ) ) {
	/**
	 * InteractionCounter.interactionType (schema.org action URLs).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_video_interaction_type_groups() {
		return array(
			'type' => array(
				'label'   => '',
				'options' => array(
					'http://schema.org/WatchAction'   => __( 'Watch action', 'nexter-extension' ),
					'http://schema.org/LikeAction'    => __( 'Like action', 'nexter-extension' ),
					'http://schema.org/CommentAction' => __( 'Comment action', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_video_quality_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_video_quality_groups() {
		return array(
			'q' => array(
				'label'   => '',
				'options' => array(
					''   => __( '—', 'nexter-extension' ),
					'HD' => __( 'HD', 'nexter-extension' ),
					'SD' => __( 'SD', 'nexter-extension' ),
					'4K' => __( '4K', 'nexter-extension' ),
					'8K' => __( '8K', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_video_yes_no_groups' ) ) {
	/**
	 * Yes/No stored as strings true|false for boolean JSON-LD fields.
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_video_yes_no_groups() {
		return array(
			'yn' => array(
				'label'   => '',
				'options' => array(
					'true'  => __( 'Yes', 'nexter-extension' ),
					'false' => __( 'No', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_video_content_rating_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_video_content_rating_groups() {
		return array(
			'rate' => array(
				'label'   => '',
				'options' => array(
					''      => __( '—', 'nexter-extension' ),
					'G'     => __( 'G — General audiences', 'nexter-extension' ),
					'PG'    => __( 'PG — Parental guidance', 'nexter-extension' ),
					'PG-13' => __( 'PG-13 — Parents strongly cautioned', 'nexter-extension' ),
					'R'     => __( 'R — Restricted', 'nexter-extension' ),
					'NC-17' => __( 'NC-17 — Adults only', 'nexter-extension' ),
				),
			),
		);
	}
}
