<?php
/**
 * Nexter Extension Rollback Theme Upgrader
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nxt_Ext_RB_Theme_Upgrader extends Theme_Upgrader {

	/**
	 * Theme rollback.
	 */
	public function nxt_ext_rollback_module( $theme, $args = array() ) {
		$defaults    = array(
			'clear_update_cache' => true,
		);
		$parsed_args = wp_parse_args( $args, $defaults );
		$this->init();
		$this->upgrade_strings();
		if ( 0 ) {
			$this->skin->before();
			$this->skin->set_result( false );
			$this->skin->error( 'up_to_date' );
			$this->skin->after();

			return false;
		}
		$theme_slug = $this->skin->theme;
		$theme_version = $this->skin->options['version'];

		$package_url = sprintf(
			'https://downloads.wordpress.org/theme/%s.%s.zip',
			$theme_slug,
			$theme_version
		);
		// Add upgrade hooks.
		add_filter( 'upgrader_pre_install', array( $this, 'current_before' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( $this, 'current_after' ), 10, 2 );
		add_filter( 'upgrader_clear_destination', array( $this, 'delete_old_theme' ), 10, 4 );

		$this->run( array(
			'package'           => $package_url,
			'destination'       => get_theme_root(),
			'clear_destination' => true,
			'clear_working'     => true,
			'hook_extra'        => array(
				'theme'  => $theme,
				'type'   => 'theme',
				'action' => 'update',
			),
		) );

		// Remove upgrade hooks.
		remove_filter( 'upgrader_pre_install', array( $this, 'current_before' ) );
		remove_filter( 'upgrader_post_install', array( $this, 'current_after' ) );
		remove_filter( 'upgrader_clear_destination', array( $this, 'delete_old_theme' ) );

		// Handle result.
		if ( is_wp_error( $this->result ) || ! $this->result ) {
			return $this->result;
		}

		// Clear the theme update cache.
		if ( $parsed_args['clear_update_cache'] ) {
			wp_clean_themes_cache( true );
		}

		return true;
	}
}