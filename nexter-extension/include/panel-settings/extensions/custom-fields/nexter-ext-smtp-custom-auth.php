<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' )) exit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Prevent WordPress from setting its default From address when custom SMTP is configured
add_filter(
	'wp_mail_from',
	function($from_email) {
		$options = Nxt_Options::extra_ext() ?: [];
		$smtp    = $options['smtp-email']['values'] ?? [];
		$custom  = $smtp['custom'] ?? [];

		if ( empty( $options['smtp-email']['switch'] ) || ($smtp['type'] ?? '') !== 'custom' ) {
			return $from_email;
		}

		// Respect the user-configured From Email first — most providers (Brevo, SendGrid, Mailgun, etc.)
		// use an API-key-style username that is not a valid sender address.
		if ( ! empty( $custom['from_email'] ) && is_email( $custom['from_email'] ) ) {
			return $custom['from_email'];
		}

		// Yandex requires the From address to match the authenticated username.
		$is_yandex = stripos( $custom['host'] ?? '', 'yandex' ) !== false;
		if ( $is_yandex && ! empty( $custom['username'] ) && is_email( $custom['username'] ) ) {
			return $custom['username'];
		}

		// No From Email configured — fall back to the username only if it is a usable address.
		if ( ! empty( $custom['username'] ) && is_email( $custom['username'] ) ) {
			return $custom['username'];
		}

		return $from_email;
	},
	999
);

// Configure WordPress to use SMTP with custom settings
// Use high priority to ensure our settings override WordPress defaults
add_action(
	'phpmailer_init',
	function (PHPMailer $phpmailer) {

		$options = Nxt_Options::extra_ext() ?: [];
	
		// Only configure SMTP if it's enabled
		if ( empty( $options['smtp-email']['switch'] ) ) {
			return;
		}
	
		$smtp        = $options['smtp-email']['values'] ?? [];
		$smtp_custom = $smtp['custom'] ?? [];

		if ( ! isset( $smtp['type'] ) || empty( $smtp['type'] ) || $smtp['type'] !== 'custom' ) {
			return;
		}

		if ( ! isset( $smtp_custom['host'] ) || empty( $smtp_custom['host'] ) ) {
			return;
		}
	
		try {
			$phpmailer->isSMTP();
			$phpmailer->SMTPDebug = 0; // Set to 2 for detailed debug
			$phpmailer->Host      = sanitize_text_field( $smtp_custom['host'] );
			$phpmailer->Port      = intval( $smtp_custom['port'] ?? 587 );
		
			// Handle encryption - if port is 465, use 'ssl', otherwise use the specified encryption
			$encryption = ! empty( $smtp_custom['encryption'] ) && $smtp_custom['encryption'] !== 'none' 
			? $smtp_custom['encryption'] 
			: '';
		
			// Port 465 = SMTPS — always force SSL regardless of user-selected encryption
			// Port 587 = STARTTLS — tls is correct there
			if ( $phpmailer->Port == 465 ) {
				$encryption = 'ssl';
			}
		
			$phpmailer->SMTPSecure = $encryption;
		
			// SMTPAutoTLS should be false when using SSL/TLS, true for STARTTLS
			if ( ! empty( $encryption ) && $encryption === 'ssl' ) {
				$phpmailer->SMTPAutoTLS = false; // SSL doesn't need AutoTLS
			} else {
				$phpmailer->SMTPAutoTLS = ! empty( $smtp_custom['autoTLS'] ) && ($smtp_custom['autoTLS'] === true || $smtp_custom['autoTLS'] === 'true');
			}
		
			// Only set auth if enabled
			$smtp_auth           = ! empty( $smtp_custom['auth'] ) && ($smtp_custom['auth'] == true || $smtp_custom['auth'] == 'true');
			$phpmailer->SMTPAuth = $smtp_auth;
		
			// Only set username/password if auth is enabled
			if ( $smtp_auth ) {
				if ( ! empty( $smtp_custom['username'] ) ) {
					$phpmailer->Username = sanitize_text_field( $smtp_custom['username'] );
				}
				if ( '' !== Nxt_Secret::smtp( $smtp_custom, 'password' ) ) {
					$phpmailer->Password = Nxt_Secret::smtp( $smtp_custom, 'password' );
				}
			}

			// Set From email — the user-configured From Email takes priority. Only Yandex actually
			// requires the From address to match the authenticated username; for most providers
			// (Brevo, SendGrid, Mailgun, etc.) the username is an API-key-style value that isn't
			// a valid sender address, so forcing it as From causes provider-side rejections.

			$from_name  = ! empty( $smtp_custom['from_name'] ) ? sanitize_text_field( $smtp_custom['from_name'] ) : get_bloginfo( 'name' );
			$from_email = ! empty( $smtp_custom['from_email'] ) ? sanitize_email( $smtp_custom['from_email'] ) : '';
			$is_yandex  = stripos( $phpmailer->Host, 'yandex' ) !== false;

			if ( $is_yandex && $smtp_auth && ! empty( $smtp_custom['username'] ) && is_email( $smtp_custom['username'] ) ) {
				// Yandex requires From to match the authenticated username.
				$phpmailer->setFrom( $smtp_custom['username'], $from_name, false );
			} elseif ( $from_email && is_email( $from_email ) ) {
				$phpmailer->setFrom( $from_email, $from_name, false );
			} elseif ( $smtp_auth && ! empty( $smtp_custom['username'] ) && is_email( $smtp_custom['username'] ) ) {
				// No From Email configured — fall back to the username only if it is a usable address.
				$phpmailer->setFrom( $smtp_custom['username'], $from_name, false );
			}
		
			// Always allow self-signed / mismatched certs — matches behaviour of WP Mail SMTP,
			// Fluent SMTP and other plugins; without this shared-hosting servers fail with
			// "Peer certificate CN did not match" even when credentials are correct.
			$phpmailer->SMTPOptions = [
			'ssl' => [
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			],
			];

			// Set CharSet and encoding
			$phpmailer->CharSet  = 'UTF-8';
			$phpmailer->Encoding = 'base64';
		
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SMTP Configuration Error: ' . $e->getMessage() ); }
			// Don't throw - let wp_mail handle the error
		}
	},
	999
); // High priority to override WordPress defaults