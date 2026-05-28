<?php
/**
 * Ability: Update an existing Nexter Extension code snippet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/update-snippet', [
    'label'       => __('Update Code Snippet', 'nexter-extension'),
    'description' => __(
        'Updates an existing Nexter Extension code snippet. You can update the code, name, description, tags, type, location, conditions, and all other settings. Only provided fields are updated — omitted fields keep their current values.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'The snippet ID to update (from nexter/list-snippets).',
                'minLength'   => 1,
            ],
            'name'        => ['type' => 'string', 'description' => 'Updated name/title.'],
            'code'        => ['type' => 'string', 'description' => 'Updated source code. For PHP, do NOT include <?php tag.'],
            'type'        => ['type' => 'string', 'description' => 'Code type.', 'enum' => ['php', 'css', 'javascript', 'htmlmixed']],
            'description' => ['type' => 'string', 'description' => 'Updated description.'],
            'tags'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Updated tags.'],
            'location'    => ['type' => 'string', 'description' => 'Updated insertion location.'],
            'code_execute' => ['type' => 'string', 'description' => 'Updated execution scope.'],
            'priority'    => ['type' => 'integer', 'description' => 'Updated priority.'],
            'insertion'   => ['type' => 'string', 'description' => 'Insertion method.', 'enum' => ['auto', 'shortcode']],
            'start_date'  => ['type' => 'string', 'description' => 'Schedule start date.'],
            'end_date'    => ['type' => 'string', 'description' => 'Schedule end date.'],
            'include_rules' => ['type' => 'array', 'description' => 'Include display rules.'],
            'exclude_rules' => ['type' => 'array', 'description' => 'Exclude display rules.'],
            'php_hidden_execute' => ['type' => 'string', 'enum' => ['yes', 'no']],
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
    'execute_callback'    => 'nexter_mcp_update_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates an existing snippet. Only provided fields are changed.',
                'IMPORTANT: For PHP code, do NOT include <?php opening tag.',
                'Use nexter/get-snippet first to see current values before updating.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nexter_mcp_update_snippet(array $input): array {
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

    $data = $file_based->get_all_snippets([], $id, true);
    if (empty($data) || !isset($data['meta'])) {
        return ['success' => false, 'error' => 'Failed to parse snippet metadata.'];
    }

    $meta = $data['meta'];
    $cond = $meta['condition'] ?? [];
    $existing_code = $data['code'] ?? '';

    $existing_type = $meta['type'] ?? 'php';
    if ($existing_type === 'php' && is_string($existing_code)) {
        $existing_code = preg_replace('/^<\?php\s*/', '', $existing_code);
        $existing_code = ltrim($existing_code, "\r\n");
    }

    // Merge updates
    $name        = isset($input['name']) ? sanitize_text_field($input['name']) : ($meta['name'] ?? '');
    $type        = isset($input['type']) ? sanitize_text_field($input['type']) : ($meta['type'] ?? 'php');
    $description = isset($input['description']) ? sanitize_textarea_field($input['description']) : ($meta['description'] ?? '');
    $code        = isset($input['code']) ? $input['code'] : $existing_code;

    if ($type === 'php' && preg_match('/^<\?php/', $code)) {
        return ['success' => false, 'error' => 'PHP code must NOT start with <?php — it is added automatically.'];
    }

    $tags = $meta['tags'] ?? [];
    if (isset($input['tags'])) {
        $tags = is_array($input['tags']) ? array_map('sanitize_text_field', $input['tags']) : [];
    }
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        $tags = is_array($decoded) ? $decoded : [];
    }

    $updated_cond = [
        'status'             => isset($cond['status']) ? (int)$cond['status'] : 0,
        'priority'           => isset($input['priority']) ? (int)$input['priority'] : (isset($cond['priority']) ? (int)$cond['priority'] : 10),
        'insertion'          => isset($input['insertion']) ? sanitize_text_field($input['insertion']) : ($cond['insertion'] ?? 'auto'),
        'location'           => isset($input['location']) ? sanitize_text_field($input['location']) : ($cond['location'] ?? ''),
        'code-execute'       => isset($input['code_execute']) ? sanitize_text_field($input['code_execute']) : ($cond['code-execute'] ?? 'global'),
        'css_selector'       => $cond['css_selector'] ?? '',
        'element_index'      => isset($cond['element_index']) ? (int)$cond['element_index'] : 0,
        'compresscode'       => !empty($cond['compresscode']),
        'on_ajax_work'       => !empty($cond['on_ajax_work']),
        'startDate'          => isset($input['start_date']) ? sanitize_text_field($input['start_date']) : ($cond['startDate'] ?? ''),
        'endDate'            => isset($input['end_date']) ? sanitize_text_field($input['end_date']) : ($cond['endDate'] ?? ''),
        'shortcodeattr'      => $cond['shortcodeattr'] ?? [],
        'customname'         => $cond['customname'] ?? '',
        'in-sub-rule'        => isset($input['include_rules']) ? $input['include_rules'] : ($cond['in-sub-rule'] ?? []),
        'ex-sub-rule'        => isset($input['exclude_rules']) ? $input['exclude_rules'] : ($cond['ex-sub-rule'] ?? []),
        'smart-logic'        => $cond['smart-logic'] ?? [],
        'php-hidden-execute' => isset($input['php_hidden_execute']) ? sanitize_text_field($input['php_hidden_execute']) : ($cond['php-hidden-execute'] ?? 'no'),
    ];

    $meta_data = [
        'name'        => $name,
        'description' => $description,
        'tags'        => $tags,
        'type'        => $type,
        'status'      => $meta['status'] ?? 'publish',
        'created_by'  => $meta['created_by'] ?? get_current_user_id(),
        'created_at'  => $meta['created_at'] ?? gmdate('Y-m-d H:i:s'),
        'updated_at'  => gmdate('Y-m-d H:i:s'),
        'updated_by'  => get_current_user_id(),
        'condition'   => $updated_cond,
    ];

    $doc_block = '<?php' . PHP_EOL . '// <Internal Start>' . PHP_EOL . '/*' . PHP_EOL . '*';
    foreach ($meta_data as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $safe_val = str_replace(['*/', PHP_EOL], ['* /', ' '], (string)$value);
        $doc_block .= PHP_EOL . '* @' . $key . ': ' . $safe_val;
    }
    $doc_block .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL;
    $doc_block .= '<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL;

    if ($type === 'php') {
        $code = '<?php' . PHP_EOL . $code;
    }

    $result = file_put_contents($file_path, $doc_block . $code);
    if ($result === false) {
        return ['success' => false, 'error' => 'Failed to write snippet file.'];
    }

    $file_based->snippetIndexData();

    $get_data = get_option('nxt-build-get-data');
    if (!empty($get_data) && is_array($get_data)) {
        $get_data['saved'] = time();
        update_option('nxt-build-get-data', $get_data, false);
    }

    return [
        'success' => true,
        'message' => 'Snippet "' . $name . '" updated successfully.',
    ];
}
