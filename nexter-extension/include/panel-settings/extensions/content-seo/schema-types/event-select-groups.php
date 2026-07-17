<?php
/**
 * Select groups for Event schema (attendance mode, event status).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexter_content_seo_schema_event_attendance_mode_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_event_attendance_mode_groups() {
		return array(
			'attendance' => array(
				'label'   => __( 'Attendance mode', 'nexter-extension' ),
				'options' => array(
					'https://schema.org/OnlineEventAttendanceMode'  => __( 'Online', 'nexter-extension' ),
					'https://schema.org/OfflineEventAttendanceMode' => __( 'Offline', 'nexter-extension' ),
					'https://schema.org/MixedEventAttendanceMode'   => __( 'Mixed', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_event_status_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_event_status_groups() {
		return array(
			'status' => array(
				'label'   => __( 'Event status', 'nexter-extension' ),
				'options' => array(
					'https://schema.org/EventCancelled'   => __( 'Cancelled', 'nexter-extension' ),
					'https://schema.org/EventMovedOnline' => __( 'Moved online', 'nexter-extension' ),
					'https://schema.org/EventPostponed'   => __( 'Postponed', 'nexter-extension' ),
					'https://schema.org/EventRescheduled' => __( 'Rescheduled', 'nexter-extension' ),
					'https://schema.org/EventScheduled'   => __( 'Scheduled', 'nexter-extension' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'nexter_content_seo_schema_event_ticket_availability_groups' ) ) {
	/**
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	function nexter_content_seo_schema_event_ticket_availability_groups() {
		return array(
			'availability' => array(
				'label'   => __( 'Ticket availability', 'nexter-extension' ),
				'options' => array(
					'https://schema.org/InStock'  => __( 'In stock', 'nexter-extension' ),
					'https://schema.org/SoldOut'  => __( 'Sold out', 'nexter-extension' ),
					'https://schema.org/PreOrder' => __( 'Pre-order', 'nexter-extension' ),
				),
			),
		);
	}
}
