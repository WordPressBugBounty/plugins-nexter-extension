<?php
/**
 * Ability: Toggle a Nexter Extension module on/off.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/toggle-extension', [
    'label'       => __('Toggle Nexter Extension', 'nexter-extension'),
    'description' => __(
        'Enables or disables a specific Nexter Extension module by key name. Use nexter/list-extensions to see available module keys.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'key' => [
                'type'        => 'string',
                'description' => 'The extension module key (from nexter/list-extensions).',
                'minLength'   => 1,
            ],
            'enabled' => [
                'type'        => 'boolean',
                'description' => 'Set to true to enable, false to disable. Omit to toggle.',
            ],
        ],
        'required'             => ['key'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'new_status' => ['type' => 'string'],
            'message'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_toggle_extension',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles a Nexter Extension module on or off.',
                'The key must match one from nexter/list-extensions.',
                'CAUTION: Disabling some modules may affect site functionality.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_toggle_extension(array $input): array {
    $key = sanitize_text_field($input['key'] ?? '');

    if (empty($key)) {
        return ['success' => false, 'error' => 'Extension key is required.'];
    }

    $options = get_option('nexter_extra_ext_options', []);
    if (!is_array($options)) {
        $options = [];
    }

    if (!array_key_exists($key, $options)) {
        return ['success' => false, 'error' => 'Unknown extension key: ' . $key . '. Use nexter/list-extensions to see available keys.'];
    }

    $current = ($options[$key] === '1' || $options[$key] === 1 || $options[$key] === true);

    if (isset($input['enabled'])) {
        $new_enabled = (bool) $input['enabled'];
    } else {
        $new_enabled = !$current;
    }

    $options[$key] = $new_enabled ? '1' : '';
    update_option('nexter_extra_ext_options', $options);

    $status_label = $new_enabled ? 'enabled' : 'disabled';

    return [
        'success'    => true,
        'new_status' => $new_enabled ? '1' : '0',
        'message'    => 'Extension "' . $key . '" ' . $status_label . ' successfully.',
    ];
}
