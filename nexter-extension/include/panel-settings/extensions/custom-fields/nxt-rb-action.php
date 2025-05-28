<?php
/**
 * Nexter Extension Rollback Action
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Theme rollback
if ( ! empty( $_GET['theme_file'] ) && file_exists( WP_CONTENT_DIR . '/themes/' . $_GET['theme_file'] ) ) {

	$theme     = sanitize_text_field( $_GET['theme_file'] );
	$title     = sanitize_text_field( $_GET['rollback_name'] );
	$version   = sanitize_text_field( $_GET['theme_version'] );
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

} elseif ( ! empty( $_GET['plugin_file'] ) && file_exists( WP_PLUGIN_DIR . '/' . $_GET['plugin_file'] ) ) {

	$plugin_file = sanitize_text_field( $_GET['plugin_file'] );
	$plugin  = self::set_plugin_slug();
	$title       = sanitize_text_field( $_GET['rollback_name'] );
	$version     = sanitize_text_field( $_GET['plugin_version'] );
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