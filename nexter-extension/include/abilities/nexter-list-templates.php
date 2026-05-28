<?php
/**
 * Ability: List all Nexter Theme Builder templates.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/list-templates-builder', [
    'label'       => __('List Theme Builder Templates', 'nexter-extension'),
    'description' => __(
        'Lists all Nexter Extension Theme Builder templates (headers, footers, singular, archives, 404, hooks, breadcrumbs) with their type, status, and display rules.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type' => [
                'type'        => 'string',
                'description' => 'Filter by template type. Leave empty for all.',
                'enum'        => ['', 'header', 'footer', 'breadcrumb', 'hooks', 'singular', 'archives', 'page-404', 'section'],
            ],
            'status' => [
                'type'        => 'string',
                'description' => 'Filter by post status.',
                'enum'        => ['', 'publish', 'draft'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'total'     => ['type' => 'integer'],
            'templates' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_list_templates_builder',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all theme builder templates with type, status, and display rules.',
                'Template types: header, footer, breadcrumb, hooks, singular, archives, page-404, section.',
                'Use this to discover template IDs before using get/update/delete abilities.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_list_templates_builder(array $input): array {
    $args = [
        'post_type'      => 'nxt_builder',
        'posts_per_page' => -1,
        'post_status'    => ['publish', 'draft'],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $status_filter = $input['status'] ?? '';
    if (!empty($status_filter)) {
        $args['post_status'] = [$status_filter];
    }

    $type_filter = $input['type'] ?? '';

    $posts = get_posts($args);
    $templates = [];

    foreach ($posts as $post) {
        $sections = get_post_meta($post->ID, 'nxt-hooks-layout-sections', true);

        if (!empty($type_filter) && $sections !== $type_filter) {
            continue;
        }

        $build_status = get_post_meta($post->ID, 'nxt_build_status', true);
        $display      = get_post_meta($post->ID, 'nxt-add-display-rule', true);
        $exclude      = get_post_meta($post->ID, 'nxt-exclude-display-rule', true);

        $template = [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'type'          => $sections ?: 'unknown',
            'post_status'   => $post->post_status,
            'build_status'  => (int) ($build_status ?: 0),
            'display_rules' => $display ?: [],
            'exclude_rules' => $exclude ?: [],
            'created'       => $post->post_date,
            'modified'      => $post->post_modified,
        ];

        // Add type-specific info
        if ($sections === 'header') {
            $template['sticky_header']      = get_post_meta($post->ID, 'nxt-normal-sticky-header', true);
            $template['transparent_header'] = get_post_meta($post->ID, 'nxt-transparent-header', true);
        } elseif ($sections === 'footer') {
            $template['footer_style']    = get_post_meta($post->ID, 'nxt-hooks-footer-style', true);
            $template['footer_bg_color'] = get_post_meta($post->ID, 'nxt-hooks-footer-smart-bgcolor', true);
        } elseif ($sections === 'hooks') {
            $template['hook_action'] = get_post_meta($post->ID, 'nxt-display-hooks-action', true);
            $template['priority']    = get_post_meta($post->ID, 'nxt-hooks-priority', true);
        }

        $templates[] = $template;
    }

    return [
        'success'   => true,
        'total'     => count($templates),
        'templates' => $templates,
    ];
}
