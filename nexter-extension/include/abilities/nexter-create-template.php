<?php
/**
 * Ability: Create a new Nexter Theme Builder template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/create-template-builder', [
    'label'       => __('Create Theme Builder Template', 'nexter-extension'),
    'description' => __(
        'Creates a new Nexter Extension Theme Builder template (header, footer, singular, archives, 404, hooks, breadcrumb, or section) with display rules and type-specific settings.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'title' => [
                'type'        => 'string',
                'description' => 'Template name.',
                'minLength'   => 1,
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type.',
                'enum'        => ['header', 'footer', 'breadcrumb', 'hooks', 'singular', 'archives', 'page-404', 'section'],
            ],
            'post_status' => [
                'type'        => 'string',
                'description' => 'WordPress post status. Default: publish.',
                'enum'        => ['publish', 'draft'],
            ],
            'display_rules' => [
                'type'        => 'array',
                'description' => 'Display rules array (e.g. ["standard-universal"]).',
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
                'description' => 'Hook priority (hooks type only). Default: 10.',
            ],
            'disable_header_404' => [
                'type'        => 'string',
                'description' => 'Disable header on 404 page (page-404 type only).',
            ],
            'disable_footer_404' => [
                'type'        => 'string',
                'description' => 'Disable footer on 404 page (page-404 type only).',
            ],
            'activated' => [
                'type'        => 'boolean',
                'description' => 'Whether to activate the template immediately. Default: false.',
            ],
        ],
        'required'             => ['title', 'type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'integer'],
            'message'  => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_create_template_builder',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Creates a theme builder template.',
                'Types: header, footer, breadcrumb, hooks, singular, archives, page-404, section.',
                'Display rules: "standard-universal" (entire site), "standard-singulars", "standard-archives", etc.',
                'After creation, use Elementor to design the template content.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nexter_mcp_create_template_builder(array $input): array {
    $title = sanitize_text_field($input['title'] ?? '');
    $type  = sanitize_text_field($input['type'] ?? '');

    if (empty($title)) {
        return ['success' => false, 'error' => 'Template title is required.'];
    }

    $valid_types = ['header', 'footer', 'breadcrumb', 'hooks', 'singular', 'archives', 'page-404', 'section'];
    if (!in_array($type, $valid_types, true)) {
        return ['success' => false, 'error' => 'Invalid template type: ' . $type];
    }

    $post_status = $input['post_status'] ?? 'publish';

    $post_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'nxt_builder',
        'post_status' => $post_status,
    ], true);

    if (is_wp_error($post_id)) {
        return ['success' => false, 'error' => $post_id->get_error_message()];
    }

    // Core meta
    update_post_meta($post_id, 'nxt-hooks-layout-sections', $type);

    $activated = !empty($input['activated']);
    update_post_meta($post_id, 'nxt_build_status', $activated ? '1' : '0');

    // Display rules
    if (!empty($input['display_rules'])) {
        update_post_meta($post_id, 'nxt-add-display-rule', array_map('sanitize_text_field', $input['display_rules']));
    }
    if (!empty($input['exclude_rules'])) {
        update_post_meta($post_id, 'nxt-exclude-display-rule', array_map('sanitize_text_field', $input['exclude_rules']));
    }

    // Type-specific meta
    switch ($type) {
        case 'header':
            if (isset($input['sticky_header'])) {
                update_post_meta($post_id, 'nxt-normal-sticky-header', sanitize_text_field($input['sticky_header']));
            }
            if (isset($input['transparent_header'])) {
                update_post_meta($post_id, 'nxt-transparent-header', sanitize_text_field($input['transparent_header']));
            }
            break;

        case 'footer':
            if (isset($input['footer_style'])) {
                update_post_meta($post_id, 'nxt-hooks-footer-style', sanitize_text_field($input['footer_style']));
            }
            break;

        case 'hooks':
            if (isset($input['hook_action'])) {
                update_post_meta($post_id, 'nxt-display-hooks-action', sanitize_text_field($input['hook_action']));
            }
            $priority = $input['hook_priority'] ?? '10';
            update_post_meta($post_id, 'nxt-hooks-priority', sanitize_text_field($priority));
            break;

        case 'page-404':
            if (isset($input['disable_header_404'])) {
                update_post_meta($post_id, 'nxt-404-disable-header', sanitize_text_field($input['disable_header_404']));
            }
            if (isset($input['disable_footer_404'])) {
                update_post_meta($post_id, 'nxt-404-disable-footer', sanitize_text_field($input['disable_footer_404']));
            }
            break;
    }

    // Update cache
    $get_data = get_option('nxt-build-get-data', []);
    if (!is_array($get_data)) {
        $get_data = [];
    }
    $get_data['saved'] = time();
    update_option('nxt-build-get-data', $get_data, false);

    return [
        'success'  => true,
        'id'       => $post_id,
        'message'  => 'Template "' . $title . '" (' . $type . ') created successfully.',
        'edit_url' => get_edit_post_link($post_id, 'raw') ?: '',
    ];
}
