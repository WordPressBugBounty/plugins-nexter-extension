<?php
/**
 * Ability: Toggle a Nexter Theme Builder template active/inactive.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/toggle-template-builder', [
    'label'       => __('Toggle Theme Builder Template', 'nexter-extension'),
    'description' => __(
        'Enables or disables a Nexter Theme Builder template. Can explicitly set active/inactive or toggle current state.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'integer',
                'description' => 'The template post ID.',
                'minimum'     => 1,
            ],
            'status' => [
                'type'        => 'integer',
                'description' => 'Set explicit status: 1 = active, 0 = inactive. Omit to toggle.',
                'enum'        => [0, 1],
            ],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'new_status' => ['type' => 'integer'],
            'message'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_toggle_template_builder',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles a template between active (1) and inactive (0).',
                'Pass status=1 to activate, status=0 to deactivate, or omit to toggle.',
                'CAUTION: Activating a header/footer template affects the live site.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_toggle_template_builder(array $input): array {
    $post_id = (int) ($input['id'] ?? 0);

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'nxt_builder') {
        return ['success' => false, 'error' => 'Template not found with ID: ' . $post_id];
    }

    $current = (int) (get_post_meta($post_id, 'nxt_build_status', true) ?: 0);

    if (isset($input['status'])) {
        $new_status = (int) $input['status'];
    } else {
        $new_status = ($current === 1) ? 0 : 1;
    }

    update_post_meta($post_id, 'nxt_build_status', (string) $new_status);

    // Update cache
    $get_data = get_option('nxt-build-get-data', []);
    if (!is_array($get_data)) {
        $get_data = [];
    }
    $get_data['saved'] = time();
    update_option('nxt-build-get-data', $get_data, false);

    $status_label = ($new_status === 1) ? 'activated' : 'deactivated';

    return [
        'success'    => true,
        'new_status' => $new_status,
        'message'    => 'Template "' . $post->post_title . '" ' . $status_label . ' successfully.',
    ];
}
