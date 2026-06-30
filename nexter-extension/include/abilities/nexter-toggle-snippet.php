<?php
/**
 * Ability: Toggle a Nexter Extension code snippet active/inactive.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/toggle-snippet', [
    'label'       => __('Toggle Code Snippet', 'nexter-extension'),
    'description' => __(
        'Enables or disables a Nexter Extension code snippet by toggling its activation status. Can explicitly set active/inactive or toggle the current state.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'The snippet ID to toggle (from nexter/list-snippets).',
                'minLength'   => 1,
            ],
            'status' => [
                'type'        => 'integer',
                'description' => 'Set explicit status: 1 = active, 0 = inactive. Omit to toggle current state.',
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
            'new_status' => ['type' => 'integer', 'description' => '1 = active, 0 = inactive'],
            'message'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_toggle_snippet',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles a snippet between active (1) and inactive (0).',
                'Pass status=1 to activate, status=0 to deactivate, or omit status to toggle.',
                'CAUTION: Activating a PHP snippet with errors could affect the site.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_toggle_snippet(array $input): array {
    if (!class_exists('Nexter_Code_Snippets_File_Based')) {
        return ['success' => false, 'error' => 'Nexter Extension code snippets not available.'];
    }

    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['id'] ?? '');
    if (empty($id)) {
        return ['success' => false, 'error' => 'Snippet ID is required.'];
    }

    $file_based  = new Nexter_Code_Snippets_File_Based();
    $storage_dir = Nexter_Code_Snippets_File_Based::getfileDir();
    $file_path   = $storage_dir . '/' . $id . '.php';

    if (!is_file($file_path)) {
        return ['success' => false, 'error' => 'Snippet not found: ' . $id];
    }

    // Security: validate path
    $real_file    = realpath($file_path);
    $real_storage = realpath($storage_dir);
    if (!$real_file || !$real_storage || strpos($real_file, $real_storage) !== 0) {
        return ['success' => false, 'error' => 'Invalid file path.'];
    }

    // Read existing file
    $content = file_get_contents($file_path);
    if ($content === false) {
        return ['success' => false, 'error' => 'Failed to read snippet file.'];
    }

    // Parse existing data
    $data = $file_based->get_all_snippets([], $id, true);
    if (empty($data) || !isset($data['meta'])) {
        return ['success' => false, 'error' => 'Failed to parse snippet metadata.'];
    }

    $meta = $data['meta'];
    $cond = $meta['condition'] ?? [];
    $code = $data['code'] ?? '';

    // Determine new status
    $current_status = isset($cond['status']) ? (int)$cond['status'] : 0;
    if (isset($input['status'])) {
        $new_status = (int)$input['status'];
    } else {
        $new_status = ($current_status === 1) ? 0 : 1;
    }

    // Update condition status
    $cond['status'] = $new_status;
    $meta['condition'] = $cond;
    $meta['updated_at'] = gmdate('Y-m-d H:i:s');
    $meta['updated_by'] = get_current_user_id();

    // Remove non-docblock keys
    unset($meta['file_name']);

    // Rebuild file with updated metadata
    $doc_block = '<?php' . PHP_EOL . '// <Internal Start>' . PHP_EOL . '/*' . PHP_EOL . '*';
    foreach ($meta as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $safe_val = str_replace(['*/', PHP_EOL], ['* /', ' '], (string)$value);
        $doc_block .= PHP_EOL . '* @' . $key . ': ' . $safe_val;
    }
    $doc_block .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL;
    $doc_block .= '<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL;

    $full_content = $doc_block . $code;

    // Write file
    $result = file_put_contents($file_path, $full_content);
    if ($result === false) {
        return ['success' => false, 'error' => 'Failed to write snippet file.'];
    }

    // Rebuild index
    $file_based->snippetIndexData();

    // Update cache
    $get_data = get_option('nxt-build-get-data');
    if (!empty($get_data) && is_array($get_data)) {
        $get_data['saved'] = time();
        update_option('nxt-build-get-data', $get_data, false);
    }

    $snippet_name = $meta['name'] ?? $id;
    $status_label = ($new_status === 1) ? 'activated' : 'deactivated';

    return [
        'success'    => true,
        'new_status' => $new_status,
        'message'    => 'Snippet "' . $snippet_name . '" ' . $status_label . ' successfully.',
    ];
}
