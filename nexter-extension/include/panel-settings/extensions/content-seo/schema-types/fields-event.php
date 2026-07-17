<?php
/**
 * Field definitions: Event (Schema.org Event).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/event-select-groups.php';

return array(
	array(
		'key'     => '@id',
		'label'   => __( 'ID', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'A unique ID for this schema. You can use the page URL or leave the default value as it is.', 'nexter-extension' ),
		'default' => '%post.url%#event-%schema.item.id%',
	),
	array(
		'key'     => '@type',
		'label'   => __( 'Type', 'nexter-extension' ),
		'input'   => 'text',
		'tooltip' => __( 'Set the schema type. This should always be set to Event.', 'nexter-extension' ),
		'default' => 'Event',
	),
	array(
		'key'     => 'name',
		'label'   => __( 'Event Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the full name of the event.', 'nexter-extension' ),
		'default' => '%post.title%',
	),
	array(
		'key'     => 'startDate',
		'label'   => __( 'Start Date', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the starting date and time for the event.', 'nexter-extension' ),
		'default' => '%post.date_c%',
	),
	array(
		'key'     => 'endDate',
		'label'   => __( 'End Date', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the ending date and time for the event.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'location',
		'label'   => __( 'Locations', 'nexter-extension' ),
		'input'   => 'event_location_list',
		'tooltip' => __( 'Add the venue or multiple locations if the event happens in more than one place.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'organizer',
		'subkey'  => '@type',
		'label'   => __( 'Organizer Type', 'nexter-extension' ),
		'input'   => 'text',
		'default' => 'Organization',
	),
	array(
		'key'     => 'organizer',
		'subkey'  => 'name',
		'label'   => __( 'Organizer Name', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the name of the organization hosting the event.', 'nexter-extension' ),
		'default' => '%site.title%',
	),
	array(
		'key'     => 'organizer',
		'subkey'  => 'url',
		'label'   => __( 'Organizer URL', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Add the website link of the event organizer.', 'nexter-extension' ),
		'default' => '%site.url%',
	),
	array(
		'key'     => 'performer',
		'label'   => __( 'Performers', 'nexter-extension' ),
		'input'   => 'event_performer_list',
		'tooltip' => __( 'Add the names of people performing or speaking at the event.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'offers',
		'subkey'  => '@type',
		'label'   => __( 'Offer Type', 'nexter-extension' ),
		'input'   => 'text',
		'default' => 'Offer',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'name',
		'label'   => __( 'Ticket Name', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'price',
		'label'   => __( 'Price', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'priceCurrency',
		'label'   => __( 'Price Currency', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => 'USD',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'url',
		'label'   => __( 'Ticket URL', 'nexter-extension' ),
		'input'   => 'editor',
		'default' => '%post.url%',
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'availability',
		'label'   => __( 'Availability', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Select if tickets are currently available (for example: In Stock, Sold Out).', 'nexter-extension' ),
		'default' => 'https://schema.org/InStock',
		'options' => nexter_content_seo_schema_event_ticket_availability_groups(),
	),
	array(
		'key'     => 'offers',
		'subkey'  => 'validFrom',
		'label'   => __( 'Valid From', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Enter the date and time when tickets will be available for purchase.', 'nexter-extension' ),
		'default' => '',
	),
	array(
		'key'     => 'eventAttendanceMode',
		'label'   => __( 'Attendance Mode', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Select how the event will be held (for example: in-person, online, or both).', 'nexter-extension' ),
		'default' => 'https://schema.org/OfflineEventAttendanceMode',
		'options' => nexter_content_seo_schema_event_attendance_mode_groups(),
	),
	array(
		'key'     => 'eventStatus',
		'label'   => __( 'Event Status', 'nexter-extension' ),
		'input'   => 'select_group',
		'tooltip' => __( 'Select the current status of the event (for example: Scheduled, Cancelled, Rescheduled).', 'nexter-extension' ),
		'default' => 'https://schema.org/EventScheduled',
		'options' => nexter_content_seo_schema_event_status_groups(),
	),
	array(
		'key'     => 'previousStartDate',
		'label'   => __( 'Previous Start Dates', 'nexter-extension' ),
		'input'   => 'string_list',
		'tooltip' => __( 'Add any previous start dates if the event was rescheduled.', 'nexter-extension' ),
		'default' => array(),
	),
	array(
		'key'     => 'mainEntityOfPage',
		'label'   => __( 'Main Entity Of Page', 'nexter-extension' ),
		'input'   => 'editor',
		'tooltip' => __( 'Define what the page is mainly about (for example: article, product, or event).', 'nexter-extension' ),
		'default' => '',
	),
);
