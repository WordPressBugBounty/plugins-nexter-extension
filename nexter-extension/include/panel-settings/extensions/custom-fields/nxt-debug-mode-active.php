<?php
// Securely add/update constants in wp-config.php using WP_Filesystem

try {
    if ( ! defined( 'ABSPATH' ) ) {
        die( "Not initiated via WordPress." );
    }

    // Load WP Filesystem
    require_once ABSPATH . '/wp-admin/includes/file.php';
    
    global $wp_filesystem;
    
    // Initialize WP Filesystem
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
        throw new \Exception( 'Failed to read wp-config.php.' );
    }

    $line   = "define( 'WP_DEBUG', true );";
    $regexp = '/define\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*(true|false|0|1|!0|!1)\s*\);/';

    if ( preg_match( $regexp, $content ) ) {
        $content = preg_replace( $regexp, $line, $content );
    } else {
        $insertionPos = strpos( $content, "\n" );
        if ( false === $insertionPos ) {
            throw new \Exception( 'Something went wrong while processing the file.' );
        }
        $content = substr_replace( $content, $line . "\n", $insertionPos + 1, 0 );
    }

    if ( ! $wp_filesystem->put_contents( $config, $content, FS_CHMOD_FILE ) ) {
        throw new \Exception( 'Failed to write wp-config.php.' );
    }
} catch ( \Exception $e ) {
    error_log( 'Config Update Error: ' . $e->getMessage() );
    throw new \Exception( $e->getMessage() );
}