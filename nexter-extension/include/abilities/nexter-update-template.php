<?php
/**
 * Ability: Update an existing Nexter Theme Builder template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/update-template-builder', [
    'label'       => __('Update Theme Builder Template', 'nexter-extension'),
    'description' => __(
        'Updates an existing Nexter Theme Builder template settings including title, display rules, and type-specific options. Only provided fields are updated.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'integer',
                'description' => 'The template post ID to update.',
                'minimum'     => 1,
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'New template name.',
            ],
            'display_rules' => [
                'type'        => 'array',
                'description' => 'Display rules array.',
                'items'       => ['type' => 'string'],
            ],
            'exclude_rules' => [
                'type'        => 'array',
                'description' => 'Exclude rules array.',
                'items'       => ['type' => 'string'],
            ],
            'sticky_header' => [
                'type'        => 'string',
                'description' => 'Header sticky behavior (header type only).',
            ],
            'transparent_header' => [
                'type'        => 'string',
                'description' => 'Header transparency (header type only).',
            ],
            'footer_style' => [
                'type'        => 'string',
                'description' => 'Footer style variant (footer type only).',
            ],
            'hook_action' => [
                'type'        => 'string',
                'description' => 'WordPress hook action name (hooks type only).',
            ],
            'hook_priority' => [
                'type'        => 'string',
                'description' => 'Hook priority (hooks type only).',
            ],
            'disable_header_404' => [
                'type'        => 'string',
                'description' => 'Disable header on 404 page (page-404 type only).',
            ],
            'disable_footer_404' => [
                'type'        => 'string',
                'description' => 'Disable footer on 404 page (page-404 type only).',
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
    'execute_callback'    => 'nexter_mcp_update_template_builder',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates a theme builder template. Only fields you provide are changed.',
                'Use nexter/get-template-builder to see current settings first.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_update_template_builder(array $input): array {
    $post_id = (int) ($input['id'] ?? 0);

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'nxt_builder') {
        return ['success' => false, 'error' => 'Template not found with ID: ' . $post_id];
    }

    $type = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);

    // Update title
    if (isset($input['title']) && !empty($input['title'])) {
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => sanitize_text_field($input['title']),
        ]);
    }

    // Display rules
    if (array_key_exists('display_rules', $input)) {
        update_post_meta($post_id, 'nxt-add-display-rule', array_map('sanitize_text_field', $input['display_rules']));
    }
    if (array_key_exists('exclude_rules', $input)) {
        update_post_meta($post_id, 'nxt-exclude-display-rule', array_map('sanitize_text_field', $input['exclude_rules']));
    }

    // Type-specific meta
    if ($type === 'header') {
        if (isset($input['sticky_header'])) {
            update_post_meta($post_id, 'nxt-normal-sticky-header', sanitize_text_field($input['sticky_header']));
        }
        if (isset($input['transparent_header'])) {
            update_post_meta($post_id, 'nxt-transparent-header', sanitize_text_field($input['transparent_header']));
        }
    }

    if ($type === 'footer') {
        if (isset($input['footer_style'])) {
            update_post_meta($post_id, 'nxt-hooks-footer-style', sanitize_text_field($input['footer_style']));
        }
    }

    if ($type === 'hooks') {
        if (isset($input['hook_action'])) {
            update_post_meta($post_id, 'nxt-display-hooks-action', sanitize_text_field($input['hook_action']));
        }
        if (isset($input['hook_priority'])) {
            update_post_meta($post_id, 'nxt-hooks-priority', sanitize_text_field($input['hook_priority']));
        }
    }

    if ($type === 'page-404') {
        if (isset($input['disable_header_404'])) {
            update_post_meta($post_id, 'nxt-404-disable-header', sanitize_text_field($input['disable_header_404']));
        }
        if (isset($input['disable_footer_404'])) {
            update_post_meta($post_id, 'nxt-404-disable-footer', sanitize_text_field($input['disable_footer_404']));
        }
    }

    // Update cache
    $get_data = get_option('nxt-build-get-data', []);
    if (!is_array($get_data)) {
        $get_data = [];
    }
    $get_data['saved'] = time();
    update_option('nxt-build-get-data', $get_data, false);

    $title = $input['title'] ?? $post->post_title;

    return [
        'success' => true,
        'message' => 'Template "' . $title . '" updated successfully.',
    ];
}
