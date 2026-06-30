<?php
/**
 * Abilities: Get/Update Image Optimization settings.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('nexter/get-image-optimization', [
    'label'       => __('Get Image Optimization Settings', 'nexter-extension'),
    'description' => __('Retrieves Nexter Extension image optimization/compression settings including format, quality, resize, and exclusion rules.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_get_image_optimization',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns image optimization config: format (webp/avif/original/smart), quality_mode (balanced/lossless/aggressive), max dimensions, EXIF handling, exclusion paths, and more.",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('nexter/update-image-optimization', [
    'label'       => __('Update Image Optimization Settings', 'nexter-extension'),
    'description' => __('Updates Nexter Extension image optimization settings. Only provided fields are changed.', 'nexter-extension'),
    'category'    => 'nexter-extension',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'enabled'           => ['type' => 'boolean', 'description' => 'Enable/disable image optimization.'],
            'image_format'      => ['type' => 'string', 'description' => 'Output format.', 'enum' => ['webp', 'original', 'smart', 'avif']],
            'quality_mode'      => ['type' => 'string', 'description' => 'Quality preset.', 'enum' => ['balanced', 'lossless', 'aggressive']],
            'max_width'         => ['type' => 'integer', 'description' => 'Max image width in pixels.', 'minimum' => 100],
            'max_height'        => ['type' => 'integer', 'description' => 'Max image height in pixels.', 'minimum' => 100],
            'auto_convert'      => ['type' => 'boolean', 'description' => 'Auto convert on upload.'],
            'exif_data'         => ['type' => 'string', 'description' => 'EXIF data handling.', 'enum' => ['strip', 'keep']],
            'resize_large'      => ['type' => 'boolean', 'description' => 'Resize oversized images.'],
            'processing_speed'  => ['type' => 'string', 'description' => 'Processing speed.', 'enum' => ['fast', 'balanced', 'slow']],
            'avoid_larger'      => ['type' => 'boolean', 'description' => 'Skip if optimized is larger.'],
            'exclude_paths'     => ['type' => 'array', 'description' => 'Paths to exclude.', 'items' => ['type' => 'string']],
            'run_in_background' => ['type' => 'boolean', 'description' => 'Process in background.'],
            'exclude_png_webp'  => ['type' => 'boolean', 'description' => 'Exclude PNG from WebP conversion.'],
            'exclude_png_avif'  => ['type' => 'boolean', 'description' => 'Exclude PNG from AVIF conversion.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nexter_mcp_update_image_optimization',
    'permission_callback' => 'nexter_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Updates image optimization settings. Only fields you provide are changed. Use nexter/get-image-optimization to see current values first.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function nexter_mcp_get_image_optimization(array $input): array {
    $perf = get_option('nexter_site_performance', []);
    if (!is_array($perf)) { $perf = []; }

    $feature = $perf['image-upload-optimize'] ?? [];
    $enabled = !empty($feature['switch']);
    $values  = $feature['values'] ?? [];

    return [
        'success' => true,
        'enabled' => $enabled,
        'settings' => [
            'image_format'      => $values['image_format'] ?? 'webp',
            'quality_mode'      => $values['quality_mode'] ?? 'balanced',
            'max_width'         => (int) ($values['max_width'] ?? 1920),
            'max_height'        => (int) ($values['max_height'] ?? 1920),
            'auto_convert'      => !empty($values['auto_convert']),
            'exif_data'         => $values['exif_data'] ?? 'strip',
            'resize_large'      => !empty($values['resize_large']),
            'processing_speed'  => $values['processing_speed'] ?? 'fast',
            'avoid_larger'      => !empty($values['avoid_larger']),
            'exclude_paths'     => $values['exclude_paths'] ?? [],
            'run_in_background' => !empty($values['run_in_background']),
            'exclude_png_webp'  => !empty($values['exclude_png_webp']),
            'exclude_png_avif'  => !empty($values['exclude_png_avif']),
        ],
    ];
}

function nexter_mcp_update_image_optimization(array $input): array {
    $perf = get_option('nexter_site_performance', []);
    if (!is_array($perf)) { $perf = []; }

    if (!isset($perf['image-upload-optimize'])) {
        $perf['image-upload-optimize'] = ['switch' => false, 'values' => []];
    }

    if (isset($input['enabled'])) {
        $perf['image-upload-optimize']['switch'] = (bool) $input['enabled'];
    }

    $values = $perf['image-upload-optimize']['values'] ?? [];

    $string_keys = ['image_format', 'quality_mode', 'exif_data', 'processing_speed'];
    $int_keys    = ['max_width', 'max_height'];
    $bool_keys   = ['auto_convert', 'resize_large', 'avoid_larger', 'run_in_background', 'exclude_png_webp', 'exclude_png_avif'];

    foreach ($string_keys as $k) {
        if (isset($input[$k])) { $values[$k] = sanitize_text_field($input[$k]); }
    }
    foreach ($int_keys as $k) {
        if (isset($input[$k])) { $values[$k] = (int) $input[$k]; }
    }
    foreach ($bool_keys as $k) {
        if (isset($input[$k])) { $values[$k] = (bool) $input[$k]; }
    }
    if (isset($input['exclude_paths'])) {
        $values['exclude_paths'] = array_map('sanitize_text_field', $input['exclude_paths']);
    }

    $perf['image-upload-optimize']['values'] = $values;
    update_option('nexter_site_performance', $perf);

    return ['success' => true, 'message' => 'Image optimization settings updated.'];
}
