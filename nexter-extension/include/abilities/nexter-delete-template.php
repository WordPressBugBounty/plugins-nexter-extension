<?php
/**
 * Ability: Delete a Nexter Theme Builder template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/delete-template-builder', [
    'label'       => __('Delete Theme Builder Template', 'nexter-extension'),
    'description' => __(
        'Permanently deletes a Nexter Theme Builder template by post ID. This action cannot be undone.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'integer',
                'description' => 'The template post ID to delete.',
                'minimum'     => 1,
            ],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_delete_template_builder',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Permanently deletes a theme builder template.',
                'WARNING: This cannot be undone. The template and all its meta data will be removed.',
                'Use nexter/list-templates-builder to find the template ID first.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_delete_template_builder(array $input): array {
    $post_id = (int) ($input['id'] ?? 0);

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'nxt_builder') {
        return ['success' => false, 'error' => 'Template not found with ID: ' . $post_id];
    }

    $title = $post->post_title;

    $deleted = wp_delete_post($post_id, true);
    if (!$deleted) {
        return ['success' => false, 'error' => 'Failed to delete template.'];
    }

    // Update cache
    $get_data = get_option('nxt-build-get-data', []);
    if (!is_array($get_data)) {
        $get_data = [];
    }
    $get_data['saved'] = time();
    update_option('nxt-build-get-data', $get_data, false);

    return [
        'success' => true,
        'message' => 'Template "' . $title . '" deleted permanently.',
    ];
}
