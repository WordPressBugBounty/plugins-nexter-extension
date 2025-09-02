<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Configure WordPress to use SMTP with custom settings
add_action('phpmailer_init', function (PHPMailer $phpmailer) {

    $options = get_option('nexter_extra_ext_options', []);
    $smtp = $options['smtp-email']['values'] ?? [];
    $smtp_custom = $smtp['custom'] ?? [];

    if ( !isset($smtp['type']) || empty($smtp['type']) || $smtp['type'] !== 'custom' ) {
        return;
    }

    if (!isset($smtp_custom['host']) || empty($smtp_custom['host'])) {
        return;
    }
    
    try {
        $phpmailer->isSMTP();
        $phpmailer->SMTPDebug = 0; // Set to 2 for detailed debug
        $phpmailer->Host = sanitize_text_field($smtp_custom['host']);
        $phpmailer->Port = intval($smtp_custom['port'] ?? 587);
        $phpmailer->SMTPSecure = (!empty($smtp_custom['encryption']) && $smtp_custom['encryption'] !== 'none') ? $smtp_custom['encryption'] : '';
        $phpmailer->SMTPAutoTLS = !empty($smtp_custom['autoTLS']) && ($smtp_custom['autoTLS'] === true || $smtp_custom['autoTLS'] === 'true');
        $phpmailer->SMTPAuth = !empty($smtp_custom['auth']) && ($smtp_custom['auth'] == true || $smtp_custom['auth'] == 'true');
        $phpmailer->Username = sanitize_text_field($smtp_custom['username']);
        $phpmailer->Password = sanitize_text_field($smtp_custom['password']);

        $from_email = !empty($smtp_custom['from_email']) ? sanitize_email($smtp_custom['from_email']) : '';
        $from_name  = !empty($smtp_custom['from_name'])  ? sanitize_text_field($smtp_custom['from_name']) : get_bloginfo('name');

        if ($from_email && is_email($from_email)) {
            $phpmailer->setFrom($from_email, $from_name);
        } elseif (!empty($smtp_custom['username']) && is_email($smtp_custom['username'])) {
            $phpmailer->setFrom($smtp_custom['username'], $smtp['name'] ?? get_bloginfo('name'));
        }
    } catch (Exception $e) {
        error_log('SMTP Configuration Error: ' . $e->getMessage());
    }
});