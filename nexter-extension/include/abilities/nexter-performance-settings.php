<?php
/**
 * Abilities: Get/Update all Performance settings.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('nexter/get-performance-settings', [
    'label'       => __('Get Performance Settings', 'nexter-extension'),
    'description' => __('Retrieves all Nexter performance settings including heartbeat control, lazy loading, preloading, minification, emoji/embed disabling, and more.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_get_performance_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Returns ALL performance features from nexter_site_performance option.',
                'Each feature has a switch (enabled/disabled) and values (settings).',
                'Common features: heartbeat-control, image-upload-optimize, disable-emojis, etc.',
            ]),
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('nexter/update-performance-settings', [
    'label'       => __('Update Performance Settings', 'nexter-extension'),
    'description' => __('Updates Nexter performance settings. Can enable/disable features and change their values. Only provided features are modified.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'feature' => [
                'type' => 'string',
                'description' => 'Feature key to update (e.g. "heartbeat-control", "image-upload-optimize").',
                'minLength' => 1,
            ],
            'enabled' => ['type' => 'boolean', 'description' => 'Enable or disable the feature.'],
            'values'  => ['type' => 'object', 'description' => 'Feature-specific settings to merge.'],
        ],
        'required' => ['feature'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_update_performance_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Updates a single performance feature by key.',
                'Use nexter/get-performance-settings to see available feature keys and current values.',
                'The values object is merged with existing values (partial update).',
            ]),
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nexter_mcp_get_performance_settings(array $input): array {
    $perf = get_option('nexter_site_performance', []);
    if (!is_array($perf)) { $perf = []; }

    // Also include performance-related features from ext options
    $ext = get_option('nexter_extra_ext_options', []);
    if (!is_array($ext)) { $ext = []; }

    $perf_ext_keys = [
        'heartbeat-control', 'disable-gutenberg', 'post-revision-control',
        'disable-elementor-icons', 'elementor-adfree', 'local-google-font',
        'image-sizes', 'svg-upload', 'local-user-avatar',
    ];

    $ext_features = [];
    foreach ($perf_ext_keys as $key) {
        if (isset($ext[$key])) {
            $ext_features[$key] = [
                'enabled' => !empty($ext[$key]['switch']),
                'values'  => $ext[$key]['values'] ?? [],
                'source'  => 'nexter_extra_ext_options',
            ];
        }
    }

    $perf_features = [];
    foreach ($perf as $key => $feature) {
        if (is_array($feature)) {
            $perf_features[$key] = [
                'enabled' => !empty($feature['switch']),
                'values'  => $feature['values'] ?? [],
                'source'  => 'nexter_site_performance',
            ];
        }
    }

    return [
        'success'  => true,
        'features' => array_merge($perf_features, $ext_features),
        'total'    => count($perf_features) + count($ext_features),
    ];
}

function nexter_mcp_update_performance_settings(array $input): array {
    $feature_key = sanitize_text_field($input['feature'] ?? '');
    if (empty($feature_key)) {
        return ['success' => false, 'error' => 'Feature key is required.'];
    }

    // Determine which option stores this feature
    $ext_keys = [
        'heartbeat-control', 'disable-gutenberg', 'post-revision-control',
        'disable-elementor-icons', 'elementor-adfree', 'local-google-font',
        'image-sizes', 'svg-upload', 'local-user-avatar',
    ];

    if (in_array($feature_key, $ext_keys, true)) {
        $option_name = 'nexter_extra_ext_options';
    } else {
        $option_name = 'nexter_site_performance';
    }

    $options = get_option($option_name, []);
    if (!is_array($options)) { $options = []; }

    if (!isset($options[$feature_key])) {
        $options[$feature_key] = ['switch' => false, 'values' => []];
    }

    if (isset($input['enabled'])) {
        $options[$feature_key]['switch'] = (bool) $input['enabled'];
    }

    if (isset($input['values']) && is_array($input['values'])) {
        $existing = $options[$feature_key]['values'] ?? [];
        if (!is_array($existing)) { $existing = []; }
        $options[$feature_key]['values'] = array_merge($existing, $input['values']);
    }

    update_option($option_name, $options);

    $status = !empty($options[$feature_key]['switch']) ? 'enabled' : 'disabled';
    return ['success' => true, 'message' => "Performance feature \"{$feature_key}\" updated ({$status})."];
}
