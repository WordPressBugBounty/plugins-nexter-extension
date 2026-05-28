<?php
/**
 * Ability: List all Nexter Extension modules and their status.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/list-extensions', [
    'label'       => __('List Nexter Extensions', 'nexter-extension'),
    'description' => __(
        'Lists all available Nexter Extension modules with their activation status, showing which features are enabled or disabled.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'status' => [
                'type'        => 'string',
                'description' => 'Filter by status: "active", "inactive", or empty for all.',
                'enum'        => ['', 'active', 'inactive'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'total'      => ['type' => 'integer'],
            'extensions' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_list_extensions',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all Nexter Extension modules and their enabled/disabled status.',
                'The extension options are stored in the nexter_extra_ext_options option.',
                'Use nexter/toggle-extension to enable or disable specific modules.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_list_extensions(array $input): array {
    $options = get_option('nexter_extra_ext_options', []);

    if (!is_array($options)) {
        $options = [];
    }

    $status_filter = $input['status'] ?? '';

    if (!empty($status_filter)) {
        $filtered = [];
        foreach ($options as $key => $value) {
            $is_active = ($value === '1' || $value === 1 || $value === true);
            if ($status_filter === 'active' && $is_active) {
                $filtered[$key] = $value;
            } elseif ($status_filter === 'inactive' && !$is_active) {
                $filtered[$key] = $value;
            }
        }
        $options = $filtered;
    }

    return [
        'success'    => true,
        'total'      => count($options),
        'extensions' => $options,
    ];
}
