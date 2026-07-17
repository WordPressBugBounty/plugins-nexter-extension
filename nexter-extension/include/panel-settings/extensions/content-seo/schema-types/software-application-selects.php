<?php
/**
 * Select groups for SoftwareApplication (play mode, app category, offer payment).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_software_play_mode_groups' ) ) {
	/**
	 * VideoGame.playMode (schema.org-style values).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_software_play_mode_groups() {
		return array(
			'mode' => array(
				'label'   => '',
				'options' => array(
					''             => __( '—', 'nexter-extension' ),
					'CoOp'         => __( 'Co-op', 'nexter-extension' ),
					'MultiPlayer'  => __( 'Multi player', 'nexter-extension' ),
					'SinglePlayer' => __( 'Single player', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_software_application_category_groups' ) ) {
	/**
	 * applicationCategory (Google / schema.org application category tokens).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_software_application_category_groups() {
		return array(
			'cat' => array(
				'label'   => '',
				'options' => array(
					'GameApplication'               => __( 'Game application', 'nexter-extension' ),
					'SocialNetworkingApplication'   => __( 'Social networking application', 'nexter-extension' ),
					'TravelApplication'             => __( 'Travel application', 'nexter-extension' ),
					'ShoppingApplication'           => __( 'Shopping application', 'nexter-extension' ),
					'SportsApplication'             => __( 'Sports application', 'nexter-extension' ),
					'LifestyleApplication'          => __( 'Lifestyle application', 'nexter-extension' ),
					'BusinessApplication'           => __( 'Business application', 'nexter-extension' ),
					'DesignApplication'             => __( 'Design application', 'nexter-extension' ),
					'DeveloperApplication'          => __( 'Developer application', 'nexter-extension' ),
					'DriverApplication'             => __( 'Driver application', 'nexter-extension' ),
					'EducationalApplication'        => __( 'Educational application', 'nexter-extension' ),
					'HealthApplication'             => __( 'Health application', 'nexter-extension' ),
					'FinanceApplication'            => __( 'Finance application', 'nexter-extension' ),
					'SecurityApplication'           => __( 'Security application', 'nexter-extension' ),
					'BrowserApplication'            => __( 'Browser application', 'nexter-extension' ),
					'CommunicationApplication'      => __( 'Communication application', 'nexter-extension' ),
					'DesktopEnhancementApplication' => __( 'Desktop enhancement application', 'nexter-extension' ),
					'EntertainmentApplication'      => __( 'Entertainment application', 'nexter-extension' ),
					'MultimediaApplication'         => __( 'Multimedia application', 'nexter-extension' ),
					'HomeApplication'               => __( 'Home application', 'nexter-extension' ),
					'UtilitiesApplication'          => __( 'Utilities application', 'nexter-extension' ),
					'ReferenceApplication'          => __( 'Reference application', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_software_offer_payment_groups' ) ) {
	/**
	 * Offer.acceptedPaymentMethod (GoodRelations PURLs, Schema.org conventions).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_software_offer_payment_groups() {
		return array(
			'pay' => array(
				'label'   => '',
				'options' => array(
					'http://purl.org/goodrelations/v1#ByBankTransferInAdvance' => __( 'By bank transfer in advance', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#ByInvoice'               => __( 'By invoice', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#Cash'                    => __( 'Cash', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#CheckInAdvance'          => __( 'Check in advance', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#COD'                     => __( 'COD', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#DirectDebit'            => __( 'Direct debit', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#GoogleCheckout'          => __( 'Google checkout', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#PayPal'                  => __( 'PayPal', 'nexter-extension' ),
					'http://purl.org/goodrelations/v1#PaySwarm'                => __( 'PaySwarm', 'nexter-extension' ),
				),
			),
		);
	}
}
