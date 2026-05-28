<?php
/**
 * Abilities: Get/Update Custom Font Upload settings.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('nexter/get-custom-fonts', [
    'label'       => __('Get Custom Fonts Settings', 'nexter-extension'),
    'description' => __('Retrieves uploaded custom fonts configuration including font names, file IDs, and weight variations.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_get_custom_fonts',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns custom uploaded fonts with names, media attachment IDs, and weight/style variations (400, 700, 400i, etc.).",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('nexter/update-custom-fonts', [
    'label'       => __('Update Custom Fonts Settings', 'nexter-extension'),
    'description' => __('Updates custom font upload feature: enable/disable and configure font families with weight variations.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'enabled' => ['type' => 'boolean', 'description' => 'Enable/disable custom font upload feature.'],
            'fonts'   => [
                'type' => 'array',
                'description' => 'Array of font configurations. Each font has simplefont and/or variablefont data.',
                'items' => ['type' => 'object'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_update_custom_fonts',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Updates custom fonts. Font files must be uploaded to Media Library first (WOFF2/TTF/OTF). Pass attachment IDs in the fonts array.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nexter_mcp_get_custom_fonts(array $input): array {
    $ext = get_option('nexter_extra_ext_options', []);
    if (!is_array($ext)) { $ext = []; }

    $feature = $ext['custom-upload-font'] ?? [];

    return [
        'success' => true,
        'enabled' => !empty($feature['switch']),
        'fonts'   => $feature['values'] ?? [],
    ];
}

function nexter_mcp_update_custom_fonts(array $input): array {
    $ext = get_option('nexter_extra_ext_options', []);
    if (!is_array($ext)) { $ext = []; }

    if (!isset($ext['custom-upload-font'])) {
        $ext['custom-upload-font'] = ['switch' => false, 'values' => []];
    }

    if (isset($input['enabled'])) {
        $ext['custom-upload-font']['switch'] = (bool) $input['enabled'];
    }
    if (isset($input['fonts'])) {
        $ext['custom-upload-font']['values'] = $input['fonts'];
    }

    update_option('nexter_extra_ext_options', $ext);

    return ['success' => true, 'message' => 'Custom font settings updated.'];
}
