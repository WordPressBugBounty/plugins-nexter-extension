<?php
/**
 * Template Display Single Posts(Singular)/Archives.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

//Get header 
get_header();

/*
 * Get Content Template Hook
 */
do_action( 'nexter_pages_hooks_template' );

//Get Footer
get_footer();
