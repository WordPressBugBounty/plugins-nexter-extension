<?php
/**
 * Abilities: Get/Update Security settings from nexter_site_security.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('nexter/get-security-settings', [
    'label'       => __('Get Nexter Security Settings', 'nexter-extension'),
    'description' => __('Retrieve all security settings. Returns every configured security feature with its enabled status and values.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_get_security_settings',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns all security settings from nexter_site_security. Features: advance-security (headers, XML-RPC, REST API, file editor, cookies, meta generator, XSS protection, iframe security), limit-login-attempt (failed attempts, lockout duration, IP whitelist), captcha-security (Google reCAPTCHA / Cloudflare Turnstile), custom-login (custom login URL), svg-upload (allowed roles). Sensitive captcha keys are masked.",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('nexter/update-security-settings', [
    'label'       => __('Update Nexter Security Settings', 'nexter-extension'),
    'description' => __('Update a specific security feature. Set enabled to toggle, and values to update configuration.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'feature' => [
                'type'        => 'string',
                'description' => 'Feature key: advance-security, limit-login-attempt, captcha-security, custom-login, svg-upload',
            ],
            'enabled' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable the feature.',
            ],
            'values' => [
                'type'        => 'object',
                'description' => 'Feature values. advance-security: mixed array with string toggles (remove_meta_generator, xss_protection, disable_xml_rpc, disable_wp_version, disable_rest_api_links, disable_file_editor, secure_cookies, user_register_date_time, user_last_login_display) and keyed values (iframe_security: deny|sameorigin|allow-from, disable_rest_api: non_admin|logged_out). limit-login-attempt: {failed_login, lockout_login, ip_address_list, header_override}. captcha-security: {siteKey, secretKey, turnSiteKey, turnSecretKey, formType, invisi}. custom-login: {custom_login_url, disable_login_url_behavior, login_page_message}. svg-upload: array of role slugs.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['feature'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_update_security_settings',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Updates a security feature's enabled state and/or values. Use nexter/get-security-settings first to see current config.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nexter_mcp_get_security_settings(array $input): array {
    $option = get_option('nexter_site_security', []);
    if (!is_array($option)) { $option = []; }

    $known_features = [
        'advance-security'    => 'Advanced Security (headers, XML-RPC, REST API, file editor, cookies, meta generator)',
        'limit-login-attempt' => 'Limit Login Attempts (failed login lockout, IP whitelist)',
        'captcha-security'    => 'CAPTCHA Security (Google reCAPTCHA / Cloudflare Turnstile)',
        'custom-login'        => 'Custom Login URL',
        'svg-upload'          => 'SVG Upload (allowed roles)',
    ];

    $result = [];
    foreach ($known_features as $key => $desc) {
        if (isset($option[$key])) {
            $values = $option[$key]['values'] ?? null;
            if ($key === 'captcha-security' && is_array($values)) {
                $values = nexter_mcp_mask_captcha_keys($values);
            }
            $result[$key] = [
                'enabled'     => !empty($option[$key]['switch']),
                'values'      => $values,
                'description' => $desc,
            ];
        } else {
            $result[$key] = [
                'enabled'     => false,
                'values'      => null,
                'description' => $desc,
            ];
        }
    }

    return ['success' => true, 'features' => $result];
}

function nexter_mcp_update_security_settings(array $input): array {
    $feature = sanitize_text_field($input['feature'] ?? '');
    if (empty($feature)) {
        return ['success' => false, 'error' => 'feature parameter is required.'];
    }

    $valid = ['advance-security', 'limit-login-attempt', 'captcha-security', 'custom-login', 'svg-upload'];
    if (!in_array($feature, $valid, true)) {
        return ['success' => false, 'error' => 'Invalid feature. Valid: ' . implode(', ', $valid)];
    }

    $option = get_option('nexter_site_security', []);
    if (!is_array($option)) { $option = []; }

    if (!isset($option[$feature])) {
        $option[$feature] = ['switch' => 0, 'values' => []];
    }

    if (isset($input['enabled'])) {
        $option[$feature]['switch'] = $input['enabled'] ? 1 : 0;
    }

    if (isset($input['values'])) {
        $option[$feature]['values'] = $input['values'];
    }

    update_option('nexter_site_security', $option);

    return [
        'success' => true,
        'feature' => $feature,
        'enabled' => !empty($option[$feature]['switch']),
        'message' => "Security feature '{$feature}' updated.",
    ];
}

function nexter_mcp_mask_captcha_keys(array $values): array {
    $sensitive = ['secretKey', 'turnSecretKey', 'siteKey', 'turnSiteKey'];
    foreach ($sensitive as $key) {
        if (!empty($values[$key]) && is_string($values[$key]) && strlen($values[$key]) > 8) {
            $values[$key] = substr($values[$key], 0, 4) . '****' . substr($values[$key], -4);
        }
    }
    return $values;
}
