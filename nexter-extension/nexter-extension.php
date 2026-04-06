<?php 
/**
 * Plugin Name: Nexter Extension
 * Plugin URI: https://nexterwp.com
 * Description: Nexter Extension adds lightweight performance, security, and admin features to WordPress so you can improve and manage your website without installing many plugins.
 * Version: 4.6.3
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 * Text Domain: nexter-extension
 * Requires at least: 4.0
 * Tested up to: 6.9
 * Requires PHP: 5.6
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 * Domain Path: /languages
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
define( 'NEXTER_EXT_VER', '4.6.3' );

if(!defined('NXT_BUILD_POST')){
	define( 'NXT_BUILD_POST', 'nxt_builder' );
}

/* Centralized settings cache — load once, before any module reads options. */
require_once NEXTER_EXT_DIR . 'include/classes/class-nxt-options.php';

/**
 * Load Custom Login Redirect early if enabled.
 * Must run before plugins_loaded priority 2 so its early hooks (plugins_loaded:2, setup_theme:1) fire.
 *
 * @since 4.6.3
 */
function nexter_ext_early_custom_login_redirect() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	$security_option = Nxt_Options::security();
	if ( ! empty( $security_option ) && isset( $security_option['custom-login']['switch'] ) && ! empty( $security_option['custom-login']['switch'] ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-custom-login-redirect.php';
		new Nexter_Ext_Custom_Login_Redirect();
	}
}
add_action( 'plugins_loaded', 'nexter_ext_early_custom_login_redirect', 1 );

/**
 * Nexter Extension Plugins Loaded
 */
function nexter_extension_plugins_loaded() {
	load_plugin_textdomain( 'nexter-extension', false, NEXTER_EXT_DIR . 'languages' );

	if ( ! version_compare( PHP_VERSION, '5.6', '>=' ) ) {
		add_action( 'admin_notices', 'nexter_ext_php_version_notice' );
	} else {
		require_once NEXTER_EXT_DIR . 'include/class-nexter-load-ext.php';
	}
}
add_action( 'plugins_loaded', 'nexter_extension_plugins_loaded' );
/**
 * Handle plugin activation.
 */
function nxt_ext_activate() {

	if ( ! get_option( 'nexter-ext-install-data' ) ) {
        update_option( 'nexter-ext-install-data', [
			"install-version" => NEXTER_EXT_VER, 
            'install-date' => wp_date( 'd-m-Y' )
        ] );
    }

	require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/class-activation.php';
	if(class_exists('Nexter_Ext_Activation')){
		$activation = new Nexter_Ext_Activation();
		$activation->create_login_attempt_table();
	}
	delete_transient( 'nxtext_cached_feed_data' );
}

/**
 * Handle plugin deactivation.
 */
function nxt_ext_deactivate() {
	require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/class-deactivation.php';

	if ( is_admin() && class_exists( 'Nexter_Ext_Deactivation' ) ) {
		$deactivation = new Nexter_Ext_Deactivation();
		$deactivation->remove_login_attempt_table();
	}
	delete_transient( 'nxtext_cached_feed_data' );
}

// Plugin Activation and Deactivation Hooks
register_activation_hook(__FILE__, 'nxt_ext_activate');
register_deactivation_hook(__FILE__, 'nxt_ext_deactivate');
/**
 * Nexter Ext notice for minimum PHP version.
 */
function nexter_ext_php_version_notice() {
	/* translators: %s: Php Required */
	$message = sprintf( esc_html__( 'Nexter Extensions requires PHP version %s+, plugin is currently NOT RUNNING.', 'nexter-extension' ), '5.6' );
	$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
	echo wp_kses_post( $html_message );
}

add_action( 'upgrader_process_complete', 'nxt_ext_after_update', 10, 2 );
function nxt_ext_after_update( $upgrader_object, $options ) {

    if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {

        $plugin_slug = 'nexter-extension/nexter-extension.php';

        if ( isset( $options['plugins'] ) && in_array( $plugin_slug, $options['plugins'], true ) ) {
            delete_transient( 'nxtext_cached_feed_data' );
        }
    }
}
