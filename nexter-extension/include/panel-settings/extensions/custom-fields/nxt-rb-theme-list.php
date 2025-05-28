<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include_once ABSPATH . WPINC . '/version.php';

if ( defined( 'WP_INSTALLING' ) || ! is_admin() ) {
    return false;
}

$expiration       = 12 * HOUR_IN_SECONDS;
$installed_themes = wp_get_themes();
$last_update      = get_site_transient( 'update_themes' );

if ( ! is_object( $last_update ) ) {
    set_site_transient( 'nxt_ext_rollback_themes', time(), $expiration );
}

$request = [
    'active' => get_option( 'stylesheet' ),
    'themes' => [],
];

$checked = [];

foreach ( $installed_themes as $theme ) {
    $stylesheet = $theme->get_stylesheet();
    $checked[ $stylesheet ] = $theme->get( 'Version' );
    $request['themes'][ $stylesheet ] = [
        'Name'       => $theme->get( 'Name' ),
        'Title'      => $theme->get( 'Name' ),
        'Version'    => '0.0.0.0.0.0',
        'Author'     => $theme->get( 'Author' ),
        'Author URI' => $theme->get( 'AuthorURI' ),
        'Template'   => $theme->get_template(),
        'Stylesheet' => $stylesheet,
    ];
}

$timeout = 3 + (int) ( count( $request['themes'] ) / 10 );

global $wp_version;
$options = [
    'timeout'    => $timeout,
    'body'       => [ 'themes' => wp_json_encode( $request ) ],
    'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ),
];

// Determine request URL.
$http_url = self::NXT_RB_THEME_UPDATE_CHECK;
$url      = wp_http_supports( [ 'ssl' ] ) ? set_url_scheme( $http_url, 'https' ) : $http_url;

// Attempt update check.
$response = wp_remote_post( $url, $options );

// Fallback to HTTP if SSL fails.
if ( is_wp_error( $response ) && wp_http_supports( [ 'ssl' ] ) ) {
    trigger_error(
        __(
            'An unexpected error occurred. Something may be wrong with WordPress.org or this server’s configuration.',
            'nexter-extension'
        ) . ' ' . __(
            '(WordPress could not establish a secure connection to WordPress.org. Please contact your server administrator.)',
            'nexter-extension'
        ),
        headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE
    );

    $response = wp_remote_post( $http_url, $options );
}

if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
    return false;
}

$body = json_decode( wp_remote_retrieve_body( $response ), true );

$update_data               = new stdClass();
$update_data->last_checked = time();
$update_data->checked      = $checked;

if ( is_array( $body ) && isset( $body['themes'] ) ) {
    $update_data->response = $body['themes'];
}

set_site_transient( 'nxt_ext_rollback_themes', $update_data, $expiration );

return true;
