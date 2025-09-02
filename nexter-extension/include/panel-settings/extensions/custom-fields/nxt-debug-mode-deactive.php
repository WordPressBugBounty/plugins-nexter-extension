<?php
// Securely add/update WP_DEBUG in wp-config.php using WP_Filesystem

try {
    if ( ! defined( 'ABSPATH' ) ) {
        die( "Not initiated via WordPress." );
    }

    global $wp_filesystem;

    // Initialize WP_Filesystem
    if ( ! function_exists( 'request_filesystem_credentials' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if ( ! WP_Filesystem() ) {
        throw new \Exception( 'Could not initialize WP_Filesystem.' );
    }

    $config = ABSPATH . 'wp-config.php';

    if ( ! $wp_filesystem->exists( $config ) ) {
        throw new \Exception( 'Config file not found.' );
    }

    if ( ! $wp_filesystem->is_writable( $config ) ) {
        throw new \Exception( 'wp-config.php is not writable. Please check file permissions.' );
    }

    $content = $wp_filesystem->get_contents( $config );

    if ( $content === false ) {
        throw new \Exception( 'Could not read wp-config.php.' );
    }

    $regexp = '/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(true|false|0|1|!0|!1)\s*\);/';
    $line   = "define( 'WP_DEBUG', false );\n";

    if ( preg_match( $regexp, $content ) ) {
        $content = preg_replace( $regexp, $line, $content );
    }

    if ( ! $wp_filesystem->put_contents( $config, $content, FS_CHMOD_FILE ) ) {
        throw new \Exception( 'Failed to write wp-config.php.' );
    }
}
catch ( \Exception $e ) {
    error_log( 'Config Update Error: ' . $e->getMessage() );
    throw new \Exception( $e->getMessage() );
}