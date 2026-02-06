<?php
/**
 * Nexter Extension Rollback Action
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Security: Verify nonce and user capabilities
if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'nxt_ext_rollback_nonce' ) ) {
	wp_die( esc_html__( 'Security check failed. Please try again.', 'nexter-extension' ) );
}

// Security: Check user capabilities
if ( ! current_user_can( 'update_themes' ) && ! current_user_can( 'update_plugins' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'nexter-extension' ) );
}

// Helper function to sanitize plugin/theme paths while preserving slashes
function nxt_ext_sanitize_plugin_path( $path ) {
	// Remove any null bytes and trim
	$path = str_replace( "\0", '', trim( $path ) );
	// Split by slash and sanitize each segment
	$segments = explode( '/', $path );
	$segments = array_map( 'sanitize_file_name', $segments );
	// Remove empty segments and rejoin
	$segments = array_filter( $segments );
	return implode( '/', $segments );
}

// Theme rollback
$theme_file_raw = isset( $_GET['theme_file'] ) ? wp_unslash( $_GET['theme_file'] ) : '';
$theme = ! empty( $theme_file_raw ) ? nxt_ext_sanitize_plugin_path( $theme_file_raw ) : '';

if ( ! empty( $theme ) && file_exists( WP_CONTENT_DIR . '/themes/' . $theme ) ) {

	$title     = isset( $_GET['rollback_name'] ) ? sanitize_text_field( wp_unslash( $_GET['rollback_name'] ) ) : '';
	$version   = isset( $_GET['theme_version'] ) ? sanitize_text_field( wp_unslash( $_GET['theme_version'] ) ) : '';
	
	// Security: Additional capability check for themes
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'You do not have permission to update themes.', 'nexter-extension' ) );
	}
	
	// Security: Validate theme file path to prevent directory traversal
	$theme_path = WP_CONTENT_DIR . '/themes/' . $theme;
	$real_theme_path = realpath( $theme_path );
	$real_themes_dir = realpath( WP_CONTENT_DIR . '/themes' );
	
	if ( ! $real_theme_path || strpos( $real_theme_path, $real_themes_dir ) !== 0 ) {
		wp_die( esc_html__( 'Invalid theme path detected.', 'nexter-extension' ) );
	}
	
	$nonce     = 'upgrade-theme_' . $theme;
	$url       = 'admin.php?page=nxt-rollback&theme_file=' . urlencode( $theme ) . '&action=upgrade-theme';

	$upgrader  = new Nxt_Ext_RB_Theme_Upgrader(
		new Theme_Upgrader_Skin( compact( 'title', 'nonce', 'url', 'theme', 'version' ) )
	);

	$result = $upgrader->nxt_ext_rollback_module( $theme );

	if ( ! is_wp_error( $result ) && $result ) {
		do_action( 'nxt_ext_theme_success', $theme, $version );
	} else {
		do_action( 'nxt_ext_theme_failure', $result );
	}
	die;

} else {
	// Plugin rollback - sanitize path while preserving slashes
	$plugin_file_raw = isset( $_GET['plugin_file'] ) ? wp_unslash( $_GET['plugin_file'] ) : '';
	$plugin_file = ! empty( $plugin_file_raw ) ? nxt_ext_sanitize_plugin_path( $plugin_file_raw ) : '';
	
	if ( ! empty( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
	
		// Security: Additional capability check for plugins
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'nexter-extension' ) );
		}
		
		// Security: Validate plugin file path to prevent directory traversal
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		$real_plugin_path = realpath( $plugin_path );
		$real_plugins_dir = realpath( WP_PLUGIN_DIR );
		
		if ( ! $real_plugin_path || strpos( $real_plugin_path, $real_plugins_dir ) !== 0 ) {
			wp_die( esc_html__( 'Invalid plugin path detected.', 'nexter-extension' ) );
		}
	
		$plugin  = self::set_plugin_slug();
		$title       = isset( $_GET['rollback_name'] ) ? sanitize_text_field( wp_unslash( $_GET['rollback_name'] ) ) : '';
		$version     = isset( $_GET['plugin_version'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin_version'] ) ) : '';
		$nonce       = 'upgrade-plugin_' . $plugin;
		$url         = 'admin.php?page=nxt-rollback&plugin_file=' . urlencode( $plugin_file ) . '&action=upgrade-plugin';

		$skin     = new Nxt_Ext_RB_Silent_Skin( array(
			'plugin'  => $plugin,
			'version' => $version,
		) );
		
		$upgrader = new Nxt_Ext_RB_Plugin_Upgrader( $skin );

		$result = $upgrader->nxt_ext_rollback_module( $plugin, array(
			'slug'    => $plugin,
			'version' => $version,
		) );

		/* $upgrader    = new Nxt_Ext_RB_Plugin_Upgrader(
			new Plugin_Upgrader_Skin( compact( 'title', 'nonce', 'url', 'plugin', 'version' ) )
		);

		$result = $upgrader->nxt_ext_rollback_module( plugin_basename( $plugin_file ) ); */

		if ( ! is_wp_error( $result ) && $result ) {
			do_action( 'nxt_ext_plugin_success', $plugin_file, $version );
		} else {
			do_action( 'nxt_ext_plugin_failure', $result );
		}
		die;
		
	} else {
		wp_die( esc_html__( 'This rollback request is missing a proper query string. Please contact support.', 'nexter-extension' ) );
	}
}