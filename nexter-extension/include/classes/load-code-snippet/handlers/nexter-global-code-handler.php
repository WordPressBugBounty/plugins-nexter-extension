<?php
/**
 * Global Code Handler
 * Handles site-wide and admin area code snippet execution
 * 
 * @since 1.0.0
 * @package Nexter Extensions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Nexter_Global_Code_Handler {
    
    /**
     * Get instance
     */
    public static function get_instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Get global location to hook mappings
     */
    public static function get_global_location_hooks() {
        return [
            // Site-Wide - Frontend
            'site_header' => 'wp_head',
            'site_body' => 'wp_body_open',
            'site_footer' => 'wp_footer',
            
            // Site-Wide - Admin Area
            'admin_header' => 'admin_head',
            'admin_footer' => 'admin_footer',
            
            // PHP Run Everywhere
            'run_everywhere' => 'init', // Execute on init hook for PHP snippets
            
            // Frontend/Admin Only locations
            'frontend_only' => 'init', // Execute on init hook but check frontend
            'admin_only' => 'init', // Execute on init hook but check admin
        ];
    }

    /**
     * Check if location is global
     */
    public static function is_global_location($location) {
        $global_locations = array_keys(self::get_global_location_hooks());
        return in_array($location, $global_locations);
    }

    /**
     * Get enqueue hook for global locations
     */
    public static function get_global_enqueue_hook($location) {
        $admin_locations = array('admin_header', 'admin_footer');
        $login_locations = array('login_head', 'login_footer');

        if (in_array($location, $admin_locations)) {
            return strpos($location, 'header') !== false ? 'admin_head' : 'admin_footer';
        } elseif (in_array($location, $login_locations)) {
            return strpos($location, 'head') !== false ? 'login_head' : 'login_footer';
        }

        // Site-wide locations
        switch ($location) {
            case 'site_header':
                return 'wp_head';
            case 'site_body':
                return 'wp_body_open';
            case 'site_footer':
                return 'wp_footer';
            case 'run_everywhere':
                return 'wp_enqueue_scripts'; // Use wp_enqueue_scripts for CSS/JS in run_everywhere
            default:
                return 'wp_head';
        }
    }

    /**
     * Execute PHP code for global locations
     */
    public static function execute_global_php($snippet_id, $code, $location) {
        if (!self::is_global_location($location)) {
            return false;
        }

        $hook = self::get_global_location_hooks()[$location] ?? '';
        if (empty($hook)) {
            return false;
        }

        // Get priority from post meta, default to 10
        $hook_priority = get_post_meta($snippet_id, 'nxt-code-hooks-priority', true);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Handle run_everywhere with special logic for immediate execution
        if ($location === 'run_everywhere') {
            // For run_everywhere, execute immediately if conditions are met
            if (function_exists('get_post_meta')) {
                $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                $code_hidden_execute = get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true);
                
                if ($is_active == '1' && $code_hidden_execute === 'yes') {
                    // Check schedule restrictions before executing
                    if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                        return true; // Skip execution but return true to indicate location was handled
                    }
                    
                    // Execute PHP code immediately for run_everywhere
                    Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                }
            }
            return true;
        }

        // Handle frontend_only and admin_only locations
        if ($location === 'frontend_only' || $location === 'admin_only') {
            // Execute immediately with admin/frontend checks
            if (function_exists('get_post_meta')) {
                $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                $code_hidden_execute = get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true);
                
                if ($is_active == '1' && $code_hidden_execute === 'yes') {
                    // Check schedule restrictions before executing
                    if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                        return true; // Skip execution but return true to indicate location was handled
                    }
                    
                    // Check if we should execute based on admin/frontend context
                    $should_execute = false;
                    
                    if (function_exists('is_admin')) {
                        if ($location === 'frontend_only' && !is_admin() && !self::is_elementor_edit_or_preview_mode()) {
                            $should_execute = true;
                        } elseif ($location === 'admin_only' && is_admin()) {
                            $should_execute = true;
                        }
                    }
                    
                    if ($should_execute) {
                        // Execute PHP code immediately for frontend_only/admin_only
                        Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                    }
                }
            }
            return true;
        }

        if (function_exists('add_action')) {
            add_action($hook, function() use ($code, $snippet_id) {
                if (function_exists('get_post_meta')) {
                    $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                    $code_hidden_execute = get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true);
                    
                    if ($is_active != '1' || $code_hidden_execute !== 'yes') {
                        return;
                    }

                    // Check schedule restrictions before executing
                    if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                        return; // Skip execution
                    }

                    // Execute PHP code
                    Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                }
            }, $priority);
        }

        return true;
    }

    /**
     * Check if snippet should be skipped due to schedule restrictions
     * Uses the same logic as the main system
     */
    private static function should_skip_due_to_schedule_restrictions($snippet_id) {
        // Check if Pro plugin is available for schedule restrictions
        if (defined('NXT_PRO_EXT') && function_exists('apply_filters')) {
            $should_skip = apply_filters('nexter_check_pro_schedule_restrictions', false, $snippet_id);
            return $should_skip;
        }
        
        // If no Pro plugin, no schedule restrictions to check
        return false;
    }

    /**
     * Check if current page is in Elementor edit or preview mode
     * This prevents frontend_only snippets from running in Elementor editor or preview
     */
    private static function is_elementor_edit_or_preview_mode() {
        if (class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if ((isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) ||
                (isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode())) {
                return true;
            }
        }
        if ((isset($_GET['elementor-preview']) && $_GET['elementor-preview']) ||
            (isset($_GET['elementor']) && $_GET['elementor'])) {
            return true;
        }
        return false;
    }

    /**
     * Enqueue CSS for global locations
     */
    public static function enqueue_global_css($snippet_id, $css, $location) {
        if (!self::is_global_location($location)) {
            return false;
        }

        // For site_body, use the direct hook, not the enqueue hook
        if ($location === 'site_body') {
            $hook = self::get_global_location_hooks()[$location] ?? '';
        } else {
            $hook = self::get_global_enqueue_hook($location);
        }
        
        if (empty($hook)) {
            return false;
        }

        // Get priority from post meta, default to 10
        $hook_priority = get_post_meta($snippet_id, 'nxt-code-hooks-priority', true);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        add_action($hook, function() use ($css, $snippet_id) {
            $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
            if ($is_active != '1') {
                return;
            }

            $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
            if ($compress) {
                $css = self::compress_css($css);
            }

            echo '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';
        }, $priority);

        return true;
    }

    /**
     * Enqueue JavaScript for global locations
     */
    public static function enqueue_global_js($snippet_id, $js, $location) {
        if (!self::is_global_location($location)) {
            return false;
        }

        // For site_body, use the direct hook, not the enqueue hook
        if ($location === 'site_body') {
            $hook = self::get_global_location_hooks()[$location] ?? '';
        } else {
            $hook = self::get_global_enqueue_hook($location);
        }
        
        if (empty($hook)) {
            return false;
        }

        // Get priority from post meta, default to 10
        $hook_priority = get_post_meta($snippet_id, 'nxt-code-hooks-priority', true);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        add_action($hook, function() use ($js, $snippet_id) {
            $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
            if ($is_active != '1') {
                return;
            }

            $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
            if ($compress) {
                $js = self::compress_js($js);
            }

            echo '<script id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $js . '</script>';
        }, $priority);

        return true;
    }

    /**
     * Output HTML for global locations
     */
    public static function output_global_html($snippet_id, $html, $location) {
        if (!self::is_global_location($location)) {
            return false;
        }

        $hook = self::get_global_location_hooks()[$location] ?? '';
        if (empty($hook)) {
            return false;
        }

        // Get priority from post meta, default to 10
        $hook_priority = get_post_meta($snippet_id, 'nxt-code-hooks-priority', true);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        add_action($hook, function() use ($html, $snippet_id) {
            $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
            if ($is_active != '1') {
                return;
            }

            echo $html;
        }, $priority);

        return true;
    }

    /**
     * Compress CSS by removing comments and extra whitespace
     */
    private static function compress_css($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove unnecessary whitespace
        $css = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css);
        return trim($css);
    }

    /**
     * Compress JavaScript by removing comments and extra whitespace
     */
    private static function compress_js($js) {
        // Remove single-line comments (but preserve URLs)
        $js = preg_replace('/(?<![:\'])\/\/.*$/m', '', $js);
        // Remove multi-line comments
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
        // Remove unnecessary whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        return trim($js);
    }
} 