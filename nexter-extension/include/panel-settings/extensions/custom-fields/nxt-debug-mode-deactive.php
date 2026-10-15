<?php
// Switch WP_DEBUG off in wp-config.php (see nxt-debug-mode-config.php).
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/nxt-debug-mode-config.php';
nxt_debug_mode_update_config( false );
