<?php
/**
 * Ability: List all Nexter Extension code snippets.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/list-snippets', [
    'label'       => __('List Code Snippets', 'nexter-extension'),
    'description' => __(
        'Lists all Nexter Extension code snippets with metadata including name, type (php/css/javascript/html), status (active/inactive), tags, description, insertion location, and priority. Returns snippets sorted newest-first.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type' => [
                'type'        => 'string',
                'description' => 'Filter by code type: php, css, javascript, or htmlmixed. Leave empty for all.',
                'enum'        => ['php', 'css', 'javascript', 'htmlmixed', ''],
            ],
            'status' => [
                'type'        => 'string',
                'description' => 'Filter by activation status: active (enabled), inactive (disabled), or all.',
                'enum'        => ['active', 'inactive', 'all', ''],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'total'    => ['type' => 'integer', 'description' => 'Total number of snippets returned.'],
            'snippets' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'string'],
                        'name'         => ['type' => 'string'],
                        'description'  => ['type' => 'string'],
                        'type'         => ['type' => 'string'],
                        'tags'         => ['type' => 'array', 'items' => ['type' => 'string']],
                        'status'       => ['type' => 'integer', 'description' => '1 = active, 0 = inactive'],
                        'code_execute' => ['type' => 'string', 'description' => 'Execution scope: global, front-end, admin, etc.'],
                        'priority'     => ['type' => 'integer'],
                        'last_updated' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_list_snippets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns all Nexter Extension code snippets.',
                'Each snippet includes: id, name, description, type, tags, status (1=active, 0=inactive), code_execute scope, priority, and last_updated.',
                'Use the snippet id with nexter/get-snippet to retrieve full code content.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_list_snippets(array $input): array {
    if (!class_exists('Nexter_Code_Snippets_File_Based')) {
        return ['success' => false, 'total' => 0, 'snippets' => [], 'error' => 'Nexter Extension code snippets not available.'];
    }

    $file_based = new Nexter_Code_Snippets_File_Based();
    $all_snippets = $file_based->getListCode(true);

    $type_filter   = isset($input['type']) ? trim((string)$input['type']) : '';
    $status_filter = isset($input['status']) ? trim((string)$input['status']) : '';

    $result = [];
    foreach ($all_snippets as $snippet) {
        if (!empty($type_filter) && isset($snippet['type']) && $snippet['type'] !== $type_filter) {
            continue;
        }

        $is_active = isset($snippet['status']) ? (int)$snippet['status'] : 0;
        if ($status_filter === 'active' && $is_active !== 1) {
            continue;
        }
        if ($status_filter === 'inactive' && $is_active !== 0) {
            continue;
        }

        $result[] = [
            'id'           => $snippet['id'] ?? '',
            'name'         => $snippet['name'] ?? '',
            'description'  => $snippet['description'] ?? '',
            'type'         => $snippet['type'] ?? '',
            'tags'         => $snippet['tags'] ?? [],
            'status'       => $is_active,
            'code_execute' => $snippet['code-execute'] ?? 'global',
            'priority'     => $snippet['priority'] ?? 10,
            'last_updated' => $snippet['last_updated'] ?? '',
        ];
    }

    return [
        'success'  => true,
        'total'    => count($result),
        'snippets' => $result,
    ];
}
