<?php 
/**
 * Plugin Name: Nexter Extension
 * Plugin URI: https://nexterwp.com
 * Description: Extension for Nexter Theme to unlock all FREE features. Keep this active to use access its all features
 * Version: 4.2.4
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 * Text Domain: nexter-extension
 * Requires at least: 4.0
 * Tested up to: 6.8.1
 * Requires PHP: 5.6
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 * @package Nexter Extensions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Define Constants */
define( 'NEXTER_EXT_FILE', __FILE__ );
define( 'NEXTER_EXT', 'nexter-extensions' );
define( 'NEXTER_EXT_BASE', plugin_basename( NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_DIR', plugin_dir_path( NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_URL', plugins_url( '/', NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_CPT', 'nxt_builder' );
define( 'NEXTER_EXT_VER', '4.2.4' );
define( 'NEXTER_EXT_NOTICE_FLAG', 1 );

if(!defined('NXT_BUILD_POST')){
	define( 'NXT_BUILD_POST', 'nxt_builder' );
}
/**
 * Nexter Extension Plugins Loaded
 */
function nexter_extension_plugins_loaded() {
	load_plugin_textdomain( 'nexter-extension', false, NEXTER_EXT_DIR . '/languages' );
	
	if ( ! version_compare( PHP_VERSION, '5.6', '>=' ) ) {
		add_action( 'admin_notices', 'nexter_ext_php_version_notice' );
	} else {
		require_once NEXTER_EXT_DIR . 'include/class-nexter-load-ext.php';
	}
}
add_action( 'plugins_loaded', 'nexter_extension_plugins_loaded' );

require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-custom-login-redirect.php';
/**
 * Nexter Ext notice for minimum PHP version.
 */
function nexter_ext_php_version_notice() {
	/* translators: %s: Php Required */
	$message = sprintf( esc_html__( 'Nexter Extensions requires PHP version %s+, plugin is currently NOT RUNNING.', 'nexter-extension' ), '5.6' );
	$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
	echo wp_kses_post( $html_message );
}