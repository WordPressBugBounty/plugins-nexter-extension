<?php
/**
 * Abilities: Get/Update SMTP Email settings.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('nexter/get-smtp-settings', [
    'label'       => __('Get SMTP Email Settings', 'nexter-extension'),
    'description' => __('Retrieves Nexter SMTP email configuration including type (Gmail/Custom), host, port, encryption, and from address.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_get_smtp_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns SMTP settings. Sensitive values (passwords, tokens) are masked. Type can be 'gmail' (OAuth2) or 'custom' (standard SMTP).",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('nexter/update-smtp-settings', [
    'label'       => __('Update SMTP Email Settings', 'nexter-extension'),
    'description' => __('Updates Nexter SMTP email configuration. Supports Gmail OAuth and Custom SMTP with host/port/encryption/auth.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'enabled'    => ['type' => 'boolean', 'description' => 'Enable/disable SMTP.'],
            'type'       => ['type' => 'string', 'description' => 'SMTP type.', 'enum' => ['gmail', 'custom']],
            'from_name'  => ['type' => 'string', 'description' => 'From name for emails.'],
            'from_email' => ['type' => 'string', 'description' => 'From email address.'],
            'host'       => ['type' => 'string', 'description' => 'Custom SMTP host.'],
            'port'       => ['type' => 'integer', 'description' => 'Custom SMTP port (25, 465, 587).'],
            'encryption' => ['type' => 'string', 'description' => 'Encryption type.', 'enum' => ['tls', 'ssl', 'none']],
            'auto_tls'   => ['type' => 'boolean', 'description' => 'Enable STARTTLS auto-negotiation.'],
            'auth'       => ['type' => 'boolean', 'description' => 'Require authentication.'],
            'username'   => ['type' => 'string', 'description' => 'SMTP username.'],
            'password'   => ['type' => 'string', 'description' => 'SMTP password.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_update_smtp_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Updates SMTP settings. For custom SMTP: set type='custom', host, port, encryption, username, password. Gmail OAuth requires setup in WP admin UI.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nexter_mcp_get_smtp_settings(array $input): array {
    $ext = get_option('nexter_extra_ext_options', []);
    if (!is_array($ext)) { $ext = []; }

    $feature = $ext['smtp-email'] ?? [];
    $values  = $feature['values'] ?? [];
    $custom  = $values['custom'] ?? [];

    // Mask sensitive values
    $masked = [
        'type'       => $values['type'] ?? '',
        'from_name'  => $values['from_name'] ?? '',
        'from_email' => $values['from_email'] ?? '',
    ];

    if (($values['type'] ?? '') === 'gmail') {
        $masked['gmail_email']     = $values['email'] ?? '';
        $masked['gmail_client_id'] = !empty($values['gclient_id']) ? '***configured***' : '';
        $masked['gmail_connected'] = !empty($values['access_token']);
    }

    if (($values['type'] ?? '') === 'custom') {
        $masked['host']       = $custom['host'] ?? '';
        $masked['port']       = (int) ($custom['port'] ?? 587);
        $masked['encryption'] = $custom['encryption'] ?? 'tls';
        $masked['auto_tls']   = !empty($custom['autoTLS']);
        $masked['auth']       = !empty($custom['auth']);
        $masked['username']   = $custom['username'] ?? '';
        $masked['password']   = !empty($custom['password']) ? '***set***' : '';
        $masked['connected']  = !empty($custom['connect']);
    }

    return [
        'success'  => true,
        'enabled'  => !empty($feature['switch']),
        'settings' => $masked,
    ];
}

function nexter_mcp_update_smtp_settings(array $input): array {
    $ext = get_option('nexter_extra_ext_options', []);
    if (!is_array($ext)) { $ext = []; }

    if (!isset($ext['smtp-email'])) {
        $ext['smtp-email'] = ['switch' => false, 'values' => []];
    }

    if (isset($input['enabled'])) {
        $ext['smtp-email']['switch'] = (bool) $input['enabled'];
    }

    $values = $ext['smtp-email']['values'] ?? [];

    if (isset($input['type']))       { $values['type'] = sanitize_text_field($input['type']); }
    if (isset($input['from_name']))  { $values['from_name'] = sanitize_text_field($input['from_name']); }
    if (isset($input['from_email'])) { $values['from_email'] = sanitize_email($input['from_email']); }

    // Custom SMTP fields
    if (($values['type'] ?? '') === 'custom' || isset($input['host'])) {
        $custom = $values['custom'] ?? [];
        if (isset($input['host']))       { $custom['host'] = sanitize_text_field($input['host']); }
        if (isset($input['port']))       { $custom['port'] = (int) $input['port']; }
        if (isset($input['encryption'])) { $custom['encryption'] = sanitize_text_field($input['encryption']); }
        if (isset($input['auto_tls']))   { $custom['autoTLS'] = (bool) $input['auto_tls']; }
        if (isset($input['auth']))       { $custom['auth'] = (bool) $input['auth']; }
        if (isset($input['username']))   { $custom['username'] = sanitize_text_field($input['username']); }
        if (isset($input['password']))   { $custom['password'] = $input['password']; }
        $values['custom'] = $custom;
    }

    $ext['smtp-email']['values'] = $values;
    update_option('nexter_extra_ext_options', $ext);

    return ['success' => true, 'message' => 'SMTP settings updated.'];
}
