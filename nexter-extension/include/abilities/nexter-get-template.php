<?php
/**
 * Ability: Get a single Nexter Theme Builder template with all settings.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/get-template-builder', [
    'label'       => __('Get Theme Builder Template', 'nexter-extension'),
    'description' => __(
        'Retrieves a single Nexter Theme Builder template by ID with all settings including display rules, conditions, type-specific options, and edit URL.',
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
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'template' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_get_template_builder',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns full details of a theme builder template.',
                'Includes all meta keys, display/exclude rules, and type-specific settings.',
                'Use nexter/list-templates-builder first to discover available IDs.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_get_template_builder(array $input): array {
    $post_id = (int) ($input['id'] ?? 0);

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'nxt_builder') {
        return ['success' => false, 'error' => 'Template not found with ID: ' . $post_id];
    }

    $sections     = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
    $build_status = get_post_meta($post_id, 'nxt_build_status', true);

    $template = [
        'id'            => $post->ID,
        'title'         => $post->post_title,
        'type'          => $sections ?: 'unknown',
        'post_status'   => $post->post_status,
        'build_status'  => (int) ($build_status ?: 0),
        'created'       => $post->post_date,
        'modified'      => $post->post_modified,
        'edit_url'      => get_edit_post_link($post->ID, 'raw'),
    ];

    // Display rules
    $template['display_rules']  = get_post_meta($post_id, 'nxt-add-display-rule', true) ?: [];
    $template['exclude_rules']  = get_post_meta($post_id, 'nxt-exclude-display-rule', true) ?: [];
    $template['include_specific'] = get_post_meta($post_id, 'nxt-hooks-layout-specific', true) ?: [];
    $template['exclude_specific'] = get_post_meta($post_id, 'nxt-hooks-layout-exclude-specific', true) ?: [];

    // Conditional rules
    foreach (['', 'exclude-'] as $prefix) {
        $key_prefix = $prefix ? 'exclude_' : 'include_';
        $template[$key_prefix . 'day_rules']    = get_post_meta($post_id, 'nxt-hooks-layout-' . $prefix . 'set-day', true) ?: [];
        $template[$key_prefix . 'os_rules']     = get_post_meta($post_id, 'nxt-hooks-layout-' . $prefix . 'os', true) ?: [];
        $template[$key_prefix . 'browser_rules'] = get_post_meta($post_id, 'nxt-hooks-layout-' . $prefix . 'browser', true) ?: [];
        $template[$key_prefix . 'login_rules']  = get_post_meta($post_id, 'nxt-hooks-layout-' . $prefix . 'login-status', true) ?: [];
        $template[$key_prefix . 'role_rules']   = get_post_meta($post_id, 'nxt-hooks-layout-' . $prefix . 'user-roles', true) ?: [];
    }

    // Type-specific settings
    switch ($sections) {
        case 'header':
            $template['sticky_header']      = get_post_meta($post_id, 'nxt-normal-sticky-header', true) ?: '';
            $template['transparent_header'] = get_post_meta($post_id, 'nxt-transparent-header', true) ?: '';
            break;

        case 'footer':
            $template['footer_style']    = get_post_meta($post_id, 'nxt-hooks-footer-style', true) ?: '';
            $template['footer_bg_color'] = get_post_meta($post_id, 'nxt-hooks-footer-smart-bgcolor', true) ?: '';
            break;

        case 'hooks':
            $template['hook_action'] = get_post_meta($post_id, 'nxt-display-hooks-action', true) ?: '';
            $template['priority']    = get_post_meta($post_id, 'nxt-hooks-priority', true) ?: '10';
            break;

        case 'page-404':
            $template['disable_header'] = get_post_meta($post_id, 'nxt-404-disable-header', true) ?: '';
            $template['disable_footer'] = get_post_meta($post_id, 'nxt-404-disable-footer', true) ?: '';
            break;

        case 'singular':
            $template['singular_group']   = get_post_meta($post_id, 'nxt-singular-group', true) ?: [];
            $template['preview_type']     = get_post_meta($post_id, 'nxt-singular-preview-type', true) ?: '';
            $template['preview_id']       = get_post_meta($post_id, 'nxt-singular-preview-id', true) ?: '';
            break;

        case 'archives':
            $template['archive_group']    = get_post_meta($post_id, 'nxt-archive-group', true) ?: [];
            $template['preview_type']     = get_post_meta($post_id, 'nxt-archive-preview-type', true) ?: '';
            $template['preview_id']       = get_post_meta($post_id, 'nxt-archive-preview-id', true) ?: '';
            break;
    }

    return ['success' => true, 'template' => $template];
}
