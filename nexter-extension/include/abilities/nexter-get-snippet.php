<?php
/**
 * Ability: Get a single Nexter code snippet with full code and metadata.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/get-snippet', [
    'label'       => __('Get Code Snippet', 'nexter-extension'),
    'description' => __(
        'Retrieves a single Nexter Extension code snippet by ID, including full source code, metadata, conditions, display rules, and all settings. Use nexter/list-snippets first to discover available snippet IDs.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'The snippet ID (e.g. "5-disable-gutenberg-editor-use"). Get IDs from nexter/list-snippets.',
                'minLength'   => 1,
            ],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'snippet'  => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'string'],
                    'name'        => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'type'        => ['type' => 'string'],
                    'tags'        => ['type' => 'array', 'items' => ['type' => 'string']],
                    'code'        => ['type' => 'string', 'description' => 'The full source code of the snippet.'],
                    'status'      => ['type' => 'integer', 'description' => '1 = active, 0 = inactive'],
                    'insertion'   => ['type' => 'string'],
                    'location'    => ['type' => 'string'],
                    'priority'    => ['type' => 'integer'],
                    'code_execute' => ['type' => 'string'],
                ],
            ],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_get_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns the full details and source code of a specific snippet.',
                'The id must match a snippet from nexter/list-snippets.',
                'For PHP snippets, the code is returned without the opening <?php tag.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_get_snippet(array $input): array {
    if (!class_exists('Nexter_Code_Snippets_File_Based')) {
        return ['success' => false, 'error' => 'Nexter Extension code snippets not available.'];
    }

    $id = sanitize_text_field($input['id'] ?? '');
    if (empty($id)) {
        return ['success' => false, 'error' => 'Snippet ID is required.'];
    }

    $file_based = new Nexter_Code_Snippets_File_Based();
    $data = $file_based->get_all_snippets([], $id, true);

    if (empty($data) || !isset($data['meta'])) {
        return ['success' => false, 'error' => 'Snippet not found: ' . $id];
    }

    $meta = $data['meta'] ?? [];
    $cond = $meta['condition'] ?? [];
    $code = $data['code'] ?? '';

    if (isset($meta['type']) && $meta['type'] === 'php' && is_string($code)) {
        $code = preg_replace('/^<\?php\s*/', '', $code);
        $code = ltrim($code, "\r\n");
    }

    $tags = [];
    if (isset($meta['tags'])) {
        if (is_array($meta['tags'])) {
            $tags = $meta['tags'];
        } elseif (is_string($meta['tags'])) {
            $decoded = json_decode($meta['tags'], true);
            $tags = is_array($decoded) ? $decoded : [];
        }
    }

    $snippet = [
        'id'                 => $id,
        'name'               => $meta['name'] ?? '',
        'description'        => $meta['description'] ?? '',
        'type'               => $meta['type'] ?? '',
        'tags'               => $tags,
        'code'               => $code,
        'status'             => isset($cond['status']) ? (int)$cond['status'] : 0,
        'insertion'          => $cond['insertion'] ?? 'auto',
        'location'           => $cond['location'] ?? '',
        'priority'           => isset($cond['priority']) ? (int)$cond['priority'] : 10,
        'code_execute'       => $cond['code-execute'] ?? 'global',
        'css_selector'       => $cond['css_selector'] ?? '',
        'element_index'      => isset($cond['element_index']) ? (int)$cond['element_index'] : 0,
        'compresscode'       => !empty($cond['compresscode']),
        'on_ajax_work'       => !empty($cond['on_ajax_work']),
        'start_date'         => $cond['startDate'] ?? '',
        'end_date'           => $cond['endDate'] ?? '',
        'shortcode_attrs'    => $cond['shortcodeattr'] ?? [],
        'include_rules'      => $cond['in-sub-rule'] ?? [],
        'exclude_rules'      => $cond['ex-sub-rule'] ?? [],
        'smart_logic'        => $cond['smart-logic'] ?? [],
        'custom_name'        => $cond['customname'] ?? '',
        'php_hidden_execute' => $cond['php-hidden-execute'] ?? 'no',
    ];

    return [
        'success' => true,
        'snippet' => $snippet,
    ];
}
