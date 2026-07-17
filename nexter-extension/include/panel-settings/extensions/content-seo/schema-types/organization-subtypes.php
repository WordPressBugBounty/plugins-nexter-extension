<?php
/**
 * Grouped JSON-LD @type values for Organization rows (schema.org subtypes).
 * Loaded by fields-organization.php and Nexter_Content_SEO_Schema::get_organization_schema_type_options().
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_organization_subtype_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_organization_subtype_groups() {
		$groups = array(
			'general'    => array(
				'label'   => __( 'General', 'nexter-extension' ),
				'options' => array(
					'Organization' => __( 'Organization', 'nexter-extension' ),
					'Corporation'  => __( 'Corporation', 'nexter-extension' ),
					'NGO'          => __( 'NGO', 'nexter-extension' ),
				),
			),
			'education'  => array(
				'label'   => __( 'Educational', 'nexter-extension' ),
				'options' => array(
					'EducationalOrganization' => __( 'EducationalOrganization', 'nexter-extension' ),
					'CollegeOrUniversity'     => __( 'CollegeOrUniversity', 'nexter-extension' ),
					'ElementarySchool'        => __( 'ElementarySchool', 'nexter-extension' ),
					'HighSchool'              => __( 'HighSchool', 'nexter-extension' ),
					'MiddleSchool'            => __( 'MiddleSchool', 'nexter-extension' ),
					'Preschool'               => __( 'Preschool', 'nexter-extension' ),
					'School'                  => __( 'School', 'nexter-extension' ),
				),
			),
			'government' => array(
				'label'   => __( 'Government', 'nexter-extension' ),
				'options' => array(
					'GovernmentOrganization' => __( 'GovernmentOrganization', 'nexter-extension' ),
					'FundingAgency'          => __( 'FundingAgency', 'nexter-extension' ),
				),
			),
			'medical'    => array(
				'label'   => __( 'Medical', 'nexter-extension' ),
				'options' => array(
					'MedicalOrganization' => __( 'MedicalOrganization', 'nexter-extension' ),
					'DiagnosticLab'       => __( 'DiagnosticLab', 'nexter-extension' ),
					'VeterinaryCare'      => __( 'VeterinaryCare', 'nexter-extension' ),
				),
			),
			'arts'       => array(
				'label'   => __( 'Arts & Performance', 'nexter-extension' ),
				'options' => array(
					'PerformingGroup' => __( 'PerformingGroup', 'nexter-extension' ),
					'DanceGroup'      => __( 'DanceGroup', 'nexter-extension' ),
					'MusicGroup'      => __( 'MusicGroup', 'nexter-extension' ),
					'TheaterGroup'    => __( 'TheaterGroup', 'nexter-extension' ),
				),
			),
			'media'      => array(
				'label'   => __( 'Media', 'nexter-extension' ),
				'options' => array(
					'NewsMediaOrganization' => __( 'NewsMediaOrganization', 'nexter-extension' ),
				),
			),
			'research'   => array(
				'label'   => __( 'Research', 'nexter-extension' ),
				'options' => array(
					'Project'         => __( 'Project', 'nexter-extension' ),
					'ResearchProject' => __( 'ResearchProject', 'nexter-extension' ),
					'Consortium'      => __( 'Consortium', 'nexter-extension' ),
				),
			),
			'sports'     => array(
				'label'   => __( 'Sports', 'nexter-extension' ),
				'options' => array(
					'SportsOrganization' => __( 'SportsOrganization', 'nexter-extension' ),
					'SportsTeam'         => __( 'SportsTeam', 'nexter-extension' ),
				),
			),
			'services'   => array(
				'label'   => __( 'Services', 'nexter-extension' ),
				'options' => array(
					'Airline'       => __( 'Airline', 'nexter-extension' ),
					'LibrarySystem' => __( 'LibrarySystem', 'nexter-extension' ),
					'WorkersUnion'  => __( 'WorkersUnion', 'nexter-extension' ),
				),
			),
		);

		return apply_filters( 'nexter_content_seo_organization_schema_type_options', $groups );
	}
}
