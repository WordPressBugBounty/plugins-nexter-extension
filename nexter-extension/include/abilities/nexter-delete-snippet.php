<?php
/**
 * Ability: Delete a Nexter Extension code snippet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/delete-snippet', [
    'label'       => __('Delete Code Snippet', 'nexter-extension'),
    'description' => __(
        'Permanently deletes a Nexter Extension code snippet by ID. This removes the snippet file from the filesystem and updates the index. This action cannot be undone.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'The snippet ID to delete (from nexter/list-snippets).',
                'minLength'   => 1,
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
    'execute_callback'    => 'nexter_mcp_delete_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'DESTRUCTIVE: Permanently deletes a code snippet. This cannot be undone.',
                'Always confirm with the user before deleting.',
                'Use nexter/list-snippets to verify the correct snippet ID.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_delete_snippet(array $input): array {
    if (!class_exists('Nexter_Code_Snippets_File_Based')) {
        return ['success' => false, 'error' => 'Nexter Extension code snippets not available.'];
    }

    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['id'] ?? '');
    if (empty($id)) {
        return ['success' => false, 'error' => 'Snippet ID is required.'];
    }

    $file_based  = new Nexter_Code_Snippets_File_Based();
    $storage_dir = Nexter_Code_Snippets_File_Based::getfileDir();

    if (empty($storage_dir) || !is_dir($storage_dir)) {
        return ['success' => false, 'error' => 'Snippet storage directory not available.'];
    }

    $file_path = wp_normalize_path($storage_dir . '/' . $id . '.php');

    $real_storage = realpath($storage_dir);
    if (!$real_storage) {
        return ['success' => false, 'error' => 'Storage directory validation failed.'];
    }

    if (!is_file($file_path)) {
        return ['success' => false, 'error' => 'Snippet not found: ' . $id];
    }

    $real_file = realpath($file_path);
    if (!$real_file || strpos($real_file, $real_storage) !== 0) {
        return ['success' => false, 'error' => 'Invalid file path detected.'];
    }

    $data = $file_based->get_all_snippets([], $id, false);
    $snippet_name = (isset($data['meta']['name'])) ? $data['meta']['name'] : $id;

    if (!unlink($file_path)) {
        return ['success' => false, 'error' => 'Failed to delete snippet file.'];
    }

    $file_based->snippetIndexData();

    $get_data = get_option('nxt-build-get-data');
    if (!empty($get_data) && is_array($get_data)) {
        $get_data['saved'] = time();
        update_option('nxt-build-get-data', $get_data, false);
    }

    return [
        'success' => true,
        'message' => 'Snippet "' . $snippet_name . '" deleted successfully.',
    ];
}
