<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Prevent WordPress from setting default From address when SMTP is configured
add_filter('wp_mail_from', function($from_email) {
    $options = get_option('nexter_extra_ext_options', []);
    $smtp = $options['smtp-email']['values'] ?? [];
    
    // Only override if custom SMTP is configured and enabled
    if (!empty($options['smtp-email']['switch']) && 
        !empty($smtp['type']) && 
        $smtp['type'] === 'custom' && 
        !empty($smtp['custom']['username']) && 
        is_email($smtp['custom']['username'])) {
        // Return the authenticated username to prevent WordPress default
        return $smtp['custom']['username'];
    }
    
    return $from_email;
}, 999);

// Configure WordPress to use SMTP with custom settings
// Use high priority to ensure our settings override WordPress defaults
add_action('phpmailer_init', function (PHPMailer $phpmailer) {

    $options = get_option('nexter_extra_ext_options', []);
    
    // Only configure SMTP if it's enabled
    if (empty($options['smtp-email']['switch'])) {
        return;
    }
    
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
        
        // Handle encryption - if port is 465, use 'ssl', otherwise use the specified encryption
        $encryption = !empty($smtp_custom['encryption']) && $smtp_custom['encryption'] !== 'none' 
            ? $smtp_custom['encryption'] 
            : '';
        
        // For port 465, force SSL if encryption is not set
        if ($phpmailer->Port == 465 && empty($encryption)) {
            $encryption = 'ssl';
        }
        
        $phpmailer->SMTPSecure = $encryption;
        
        // SMTPAutoTLS should be false when using SSL/TLS, true for STARTTLS
        if (!empty($encryption) && $encryption === 'ssl') {
            $phpmailer->SMTPAutoTLS = false; // SSL doesn't need AutoTLS
        } else {
            $phpmailer->SMTPAutoTLS = !empty($smtp_custom['autoTLS']) && ($smtp_custom['autoTLS'] === true || $smtp_custom['autoTLS'] === 'true');
        }
        
        // Only set auth if enabled
        $smtp_auth = !empty($smtp_custom['auth']) && ($smtp_custom['auth'] == true || $smtp_custom['auth'] == 'true');
        $phpmailer->SMTPAuth = $smtp_auth;
        
        // Only set username/password if auth is enabled
        if ($smtp_auth) {
            if (!empty($smtp_custom['username'])) {
                $phpmailer->Username = sanitize_text_field($smtp_custom['username']);
            }
            if (!empty($smtp_custom['password'])) {
                $phpmailer->Password = sanitize_text_field($smtp_custom['password']);
            }
        }

        // Set From email - for providers like Yandex, From must match authenticated username
        // CRITICAL: Always set From address to match authenticated username when auth is enabled
        // This prevents WordPress default From address from being used
        
        $from_name  = !empty($smtp_custom['from_name'])  ? sanitize_text_field($smtp_custom['from_name']) : get_bloginfo('name');
        
        // Check if this is Yandex SMTP (requires From to match username)
        $is_yandex = stripos($phpmailer->Host, 'yandex') !== false;
        
        // If auth is enabled, From MUST match username (required for Yandex and many other providers)
        if ($smtp_auth && !empty($smtp_custom['username'])) {
            // Always use username as From address when auth is enabled
            // This ensures the From address matches the authenticated user
            $username_email = is_email($smtp_custom['username']) 
                ? $smtp_custom['username'] 
                : sanitize_email($smtp_custom['username']);
            
            if ($username_email) {
                // Force set From address - this will override any default WordPress set
                $phpmailer->setFrom($username_email, $from_name, false);
            } else {
                // Fallback: use from_email if username is not a valid email
                $from_email = !empty($smtp_custom['from_email']) ? sanitize_email($smtp_custom['from_email']) : '';
                if ($from_email && is_email($from_email)) {
                    $phpmailer->setFrom($from_email, $from_name, false);
                }
            }
        } else {
            // If auth is not enabled, use from_email if provided
            $from_email = !empty($smtp_custom['from_email']) ? sanitize_email($smtp_custom['from_email']) : '';
            if ($from_email && is_email($from_email)) {
                $phpmailer->setFrom($from_email, $from_name, false);
            }
        }
        
        // Set CharSet and encoding
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Encoding = 'base64';
        
    } catch (Exception $e) {
        error_log('SMTP Configuration Error: ' . $e->getMessage());
        // Don't throw - let wp_mail handle the error
    }
}, 999); // High priority to override WordPress defaults