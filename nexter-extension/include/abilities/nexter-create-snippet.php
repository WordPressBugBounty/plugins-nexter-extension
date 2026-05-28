<?php
/**
 * Ability: Create a new Nexter Extension code snippet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nexter/create-snippet', [
    'label'       => __('Create Code Snippet', 'nexter-extension'),
    'description' => __(
        'Creates a new Nexter Extension code snippet. Supports PHP, CSS, JavaScript, and HTML snippet types with configurable insertion locations, conditions, scheduling, and display rules.',
        'nexter-extension',
    ),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'name' => [
                'type'        => 'string',
                'description' => 'Name/title for the snippet.',
                'minLength'   => 1,
            ],
            'code' => [
                'type'        => 'string',
                'description' => 'The source code. For PHP snippets, do NOT include opening <?php tag.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Code language type.',
                'enum'        => ['php', 'css', 'javascript', 'htmlmixed'],
                'default'     => 'php',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Brief description of what the snippet does.',
            ],
            'tags' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Tags for categorizing the snippet.',
            ],
            'status' => [
                'type'        => 'integer',
                'description' => '1 = active (enabled), 0 = inactive (disabled). Default: 0.',
                'enum'        => [0, 1],
                'default'     => 0,
            ],
            'insertion' => [
                'type'        => 'string',
                'description' => 'Insertion method: auto or shortcode.',
                'enum'        => ['auto', 'shortcode'],
                'default'     => 'auto',
            ],
            'location' => [
                'type'        => 'string',
                'description' => 'Where to execute. PHP: global, front-end, admin, wp_head, wp_body_open, wp_footer, before-content, after-content. CSS: header-css, footer-css. JS: header-js, footer-js. HTML: header-html, footer-html.',
            ],
            'code_execute' => [
                'type'        => 'string',
                'description' => 'Execution scope: global, front-end, admin.',
                'default'     => 'global',
            ],
            'priority' => [
                'type'        => 'integer',
                'description' => 'Execution priority (lower = earlier). Default: 10.',
                'default'     => 10,
            ],
            'start_date' => [
                'type'        => 'string',
                'description' => 'Schedule start date (ISO format).',
            ],
            'end_date' => [
                'type'        => 'string',
                'description' => 'Schedule end date (ISO format).',
            ],
            'include_rules' => [
                'type'        => 'array',
                'description' => 'Include display rules array.',
            ],
            'exclude_rules' => [
                'type'        => 'array',
                'description' => 'Exclude display rules array.',
            ],
            'php_hidden_execute' => [
                'type'        => 'string',
                'description' => 'Whether PHP runs hidden (no output): yes or no.',
                'enum'        => ['yes', 'no'],
                'default'     => 'no',
            ],
        ],
        'required'             => ['name', 'type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'string', 'description' => 'The ID of the newly created snippet.'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nexter_mcp_create_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Creates a new code snippet in Nexter Extension.',
                'IMPORTANT: For PHP code, do NOT include <?php opening tag — it is added automatically.',
                'The snippet is created as inactive (status=0) by default. Use nexter/toggle-snippet to activate.',
                'Supported types: php, css, javascript, htmlmixed.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nexter_mcp_create_snippet(array $input): array {
    if (!class_exists('Nexter_Code_Snippets_File_Based')) {
        return ['success' => false, 'error' => 'Nexter Extension code snippets not available.'];
    }

    $name = sanitize_text_field($input['name'] ?? '');
    if (empty($name)) {
        return ['success' => false, 'error' => 'Snippet name is required.'];
    }

    $type = sanitize_text_field($input['type'] ?? 'php');
    if (!in_array($type, ['php', 'css', 'javascript', 'htmlmixed'], true)) {
        return ['success' => false, 'error' => 'Invalid type. Must be: php, css, javascript, or htmlmixed.'];
    }

    $code = $input['code'] ?? '';

    if ($type === 'php' && preg_match('/^<\?php/', $code)) {
        return ['success' => false, 'error' => 'PHP code must NOT start with <?php — it is added automatically.'];
    }

    $file_based = new Nexter_Code_Snippets_File_Based();
    $storage_dir = Nexter_Code_Snippets_File_Based::getfileDir();

    if (empty($storage_dir)) {
        return ['success' => false, 'error' => 'Snippet storage directory not available.'];
    }

    if (!is_dir($storage_dir)) {
        wp_mkdir_p($storage_dir);
    }

    // Generate filename
    $existing_files = glob($storage_dir . '/*.php');
    $file_count = is_array($existing_files) ? count($existing_files) : 0;
    if ($file_count < 1) {
        $file_count = 1;
    }
    $name_arr = explode(' ', $name);
    if (count($name_arr) > 4) {
        $name_arr = array_slice($name_arr, 0, 4);
    }
    $file_title = sanitize_title(implode(' ', $name_arr), 'snippet');
    $file_name  = $file_count . '-' . $file_title . '.php';
    $file_name  = sanitize_file_name($file_name);
    $file_path  = $storage_dir . '/' . $file_name;

    // Avoid collision
    while (is_file($file_path)) {
        $file_count++;
        $file_name = $file_count . '-' . $file_title . '.php';
        $file_name = sanitize_file_name($file_name);
        $file_path = $storage_dir . '/' . $file_name;
    }

    $tags = [];
    if (isset($input['tags']) && is_array($input['tags'])) {
        $tags = array_map('sanitize_text_field', $input['tags']);
    }

    // Set default location if not provided
    $location = sanitize_text_field($input['location'] ?? '');
    if (empty($location)) {
        $defaults = [
            'php'        => 'front-end',
            'css'        => 'header-css',
            'javascript' => 'header-js',
            'htmlmixed'  => 'header-html',
        ];
        $location = $defaults[$type] ?? '';
    }

    $condition = [
        'status'             => isset($input['status']) ? (int)$input['status'] : 0,
        'priority'           => isset($input['priority']) ? (int)$input['priority'] : 10,
        'insertion'          => sanitize_text_field($input['insertion'] ?? 'auto'),
        'location'           => $location,
        'code-execute'       => sanitize_text_field($input['code_execute'] ?? 'global'),
        'css_selector'       => '',
        'element_index'      => 0,
        'compresscode'       => false,
        'on_ajax_work'       => false,
        'startDate'          => sanitize_text_field($input['start_date'] ?? ''),
        'endDate'            => sanitize_text_field($input['end_date'] ?? ''),
        'shortcodeattr'      => [],
        'customname'         => '',
        'in-sub-rule'        => $input['include_rules'] ?? [],
        'ex-sub-rule'        => $input['exclude_rules'] ?? [],
        'smart-logic'        => [],
        'php-hidden-execute' => sanitize_text_field($input['php_hidden_execute'] ?? 'no'),
    ];

    $meta_data = [
        'name'        => $name,
        'description' => sanitize_textarea_field($input['description'] ?? ''),
        'tags'        => $tags,
        'type'        => $type,
        'status'      => 'publish',
        'created_by'  => get_current_user_id(),
        'created_at'  => gmdate('Y-m-d H:i:s'),
        'updated_at'  => gmdate('Y-m-d H:i:s'),
        'updated_by'  => get_current_user_id(),
        'condition'   => $condition,
    ];

    // Build file content
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

    $full_content = $doc_block . $code;

    $result = file_put_contents($file_path, $full_content);
    if ($result === false) {
        return ['success' => false, 'error' => 'Failed to write snippet file.'];
    }

    $file_based->snippetIndexData();

    $get_data = get_option('nxt-build-get-data');
    if (!empty($get_data) && is_array($get_data)) {
        $get_data['saved'] = time();
        update_option('nxt-build-get-data', $get_data, false);
    }

    $id = preg_replace('/\.php$/', '', $file_name);

    return [
        'success' => true,
        'id'      => $id,
        'message' => 'Snippet "' . $name . '" created successfully.',
    ];
}
