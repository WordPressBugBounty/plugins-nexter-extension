<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// PHP Version Check
if (version_compare(PHP_VERSION, '8.0.2', '<')) {
    if (is_admin()) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    sprintf(
                        'The Nexter Extension SMTP integration requires PHP version %s or higher. You are using %s.',
                        '8.0.2',
                        PHP_VERSION
                    ),
                    'nexter-extension'
                )
            );
        });
    }
    
    return;
}


// Load PHPMailer classes
if (file_exists(NEXTER_EXT_DIR . 'vendor/autoload.php')) {
    require_once NEXTER_EXT_DIR . 'vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use League\OAuth2\Client\Provider\Google;
use PHPMailer\PHPMailer\OAuth;

// Configure WordPress to use SMTP with OAuth
add_action('phpmailer_init', function (PHPMailer $phpmailer) {
    $options = get_option('nexter_extra_ext_options', []);
    $smtp = $options['smtp-email']['values'] ?? [];

    // Check for all required credentials
    if (
        empty($smtp['gclient_id']) ||
        empty($smtp['gsecret_key']) ||
        empty($smtp['refresh_token']) ||
        empty($smtp['email'])
    ) {
       // error_log('SMTP OAuth: Missing required credentials.');
        return;
    }

    if(empty( $smtp['type'] ) || ( isset($smtp['type']) && $smtp['type']!='gmail' ) ){
        return;
    }

    // Set up PHPMailer for Gmail SMTP with OAuth2
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'smtp.gmail.com';
    $phpmailer->Port       = 587;
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->SMTPAuth   = true;
    $phpmailer->AuthType   = 'XOAUTH2';

    try {
        $provider = new \League\OAuth2\Client\Provider\Google([
            'clientId'     => $smtp['gclient_id'],
            'clientSecret' => $smtp['gsecret_key'],
        ]);

        $accessToken = $provider->getAccessToken('refresh_token', [
            'refresh_token' => $smtp['refresh_token'],
        ]);

        $phpmailer->setOAuth(new \PHPMailer\PHPMailer\OAuth([
            'provider'     => $provider,
            'clientId'     => $smtp['gclient_id'],
            'clientSecret' => $smtp['gsecret_key'],
            'refreshToken' => $smtp['refresh_token'],
            'userName'     => $smtp['email'],
        ]));

        $phpmailer->setFrom($smtp['email'], $smtp['name'] ?? get_bloginfo('name'));

    } catch (Exception $e) {
        error_log('SMTP OAuth: Exception - ' . $e->getMessage());
    }
});