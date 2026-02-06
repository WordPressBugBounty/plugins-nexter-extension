<?php
/**
 * Nexter Builder PHP Code Handling
 *
 * @package Nexter Extensions
 * @since 1.0.4
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Nexter_Builder_Code_Snippets_Executor {
    private static $instance;

    private function __construct() {
        // Register shutdown error handler
        register_shutdown_function([$this, 'nexter_handle_fatal_errors']);
    }

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Execute PHP Snippet Safely
     */
    public function execute_php_snippet($code, $post_id = null, $catch_output = true, $attributes = array()) {
        if (empty($code)) return false;

        if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($code)){
            $file_based = new Nexter_Code_Snippets_File_Based();
            $file_path = isset($code['file_path']) ? $code['file_path'] : '';
            $code = $file_based->parseBlock(file_get_contents($file_path), true);
            // Remove Beginning php tag
            $code= preg_replace('/^<\?php/', '', $code);
            // remove new line at the very first
            $code = ltrim($code, PHP_EOL);
        }else{
            if (strpos($code, '&') !== false || strpos($code, '&lt;') !== false) {
                $code = html_entity_decode(htmlspecialchars_decode($code), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        
        // Enhanced security: Check for dangerous functions and infinite loops
        if ($this->is_code_not_allowed($code)) {
            $reason = __('Dangerous code detected.', 'nexter-extension');
            if ($this->has_restricted_file_operations($code)) {
                $reason = __('Permission Violation: File operations on restricted directories are not allowed.', 'nexter-extension');
            } elseif ($this->has_infinite_loop($code)) {
                $reason = __('Infinite loop detected in code.', 'nexter-extension');
            }
            $this->nexter_deactivate_snippet($post_id, $reason);
            return false;
        }

        $error = null;
        $result = false;
        $output = '';

        if ($catch_output) {
            ob_start();
        }

        try {
            // Get attributes from Pro version if available
            $attributes = apply_filters('nexter_php_snippet_attributes', $attributes, $post_id, $code);

            // Extract shortcode attributes as variables if provided (with security validation)
            if (!empty($attributes) && is_array($attributes)) {
                
                foreach ($attributes as $key => $value) {
                    if (is_string($key) && !empty($key)) {
                        // Security: Validate variable name format and check blacklist
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) && 
                            strpos($key, '_') !== 0 && 
                            strlen($key) <= 50) {
                            // Use variable variables to set dynamic variable names
                            ${$key} = is_string($value) ? sanitize_text_field($value) : $value;
                        }
                    }
                }
            }
            
            $this->nexter_run_eval($code, $post_id);
            if ($catch_output) {
                $output = ob_get_contents();
                ob_end_clean();
                // Security: Only echo output if it's safe and not in sensitive contexts
                if (!defined('DOING_AJAX') || !DOING_AJAX) {
                    // Additional security: Check if we should allow output
                    if (!$this->is_sensitive_context()) {
                        echo $output;
                    }
                }
            }
        } catch (\ParseError $e) {
            if ($catch_output) {
                ob_end_clean();
            }
            // Return short parse error message
            /* translators: 1: Error message, 2: Line number */
            return new WP_Error(
                'php_parse_error',
                sprintf(__('Parse error: %1$s on line %2$d', 'nexter-extension'), $e->getMessage(), $e->getLine())
            );
        } catch (\Error $e) {
            if ($catch_output) {
                ob_end_clean();
            }
            $error = $e;
        } catch (\Exception $e) {
            if ($catch_output) {
                ob_end_clean();
            }
            /* translators: 1: Exception message, 2: Line number */
            return new WP_Error(
                'php_exception',
                sprintf(__('Exception: %1$s on line %2$d', 'nexter-extension'), $e->getMessage(), $e->getLine())
            );
        }

        if ($error) {
            $this->nexter_deactivate_snippet($post_id, $error->getMessage());
            /* translators: 1: Error message, 2: Line number */
            return new WP_Error(
                'php_error',
                sprintf(__('Fatal error: %1$s on line %2$d', 'nexter-extension'), $error->getMessage(), $error->getLine())
            );
        }

        // Track snippet execution for conditional logic
        if ($post_id) {
            $this->track_snippet_execution($post_id);
        }

        return !empty($output) ? $output : true;
    }

    /**
     * Track snippet execution for conditional logic
     */
    private function track_snippet_execution($post_id) {
        global $nexter_executed_snippets;
        if (!isset($nexter_executed_snippets)) {
            $nexter_executed_snippets = array();
        }
        if (!in_array($post_id, $nexter_executed_snippets)) {
            $nexter_executed_snippets[] = $post_id;
        }
    }

    protected function is_code_not_allowed( $code ) {
        // Check for specific functions and count occurrences
        if ( preg_match_all( '/(base64_decode|error_reporting|ini_set|eval)\s*\(/i', $code, $matches ) ) {
            if ( count( $matches[0] ) > 5 ) {
                return true;
            }
        }

        // Check for 'dns_get_record'
        if ( preg_match( '/dns_get_record\s*\(/i', $code ) ) {
            return true;
        }

        // Check for file write operations on sensitive WordPress directories (Permission Violation)
        if ( $this->has_restricted_file_operations( $code ) ) {
            return true;
        }

        // Check for infinite loops (while(true), for(;;), etc.)
        if ( $this->has_infinite_loop( $code ) ) {
            return true;
        }

        // Check for recursive hooks (hook calling itself)
        if ( $this->has_recursive_hook( $code ) ) {
            return true;
        }

        return false;
    }

    /**
     * Detect file write operations on restricted WordPress directories (Permission Violation)
     * Blocks writing to ABSPATH, wp-config.php, .htaccess, and other sensitive locations
     */
    protected function has_restricted_file_operations( $code ) {
        // File write functions to check
        $file_write_functions = [
            'file_put_contents',
            'fwrite',
            'fputs',
            'fopen.*[\'"]w|fopen.*[\'"]a|fopen.*[\'"]x|fopen.*[\'"]c',
            'copy',
            'move_uploaded_file',
            'rename',
            'unlink',
            'rmdir',
            'mkdir',
            'chmod',
            'chown',
            'chgrp',
            'symlink',
            'link',
            'touch'
        ];
        
        // Check if code contains file write functions
        $has_file_write = false;
        foreach ($file_write_functions as $func_pattern) {
            if (preg_match('/' . $func_pattern . '\s*\(/i', $code)) {
                $has_file_write = true;
                break;
            }
        }
        
        // If no file write functions found, no restriction needed
        if (!$has_file_write) {
            return false;
        }
        
        // Check for ABSPATH concatenation with file paths
        // Pattern: ABSPATH . '/filename' or ABSPATH . "/filename" or ABSPATH.'/filename'
        if (preg_match('/ABSPATH\s*\.\s*[\'"]([^\'"]+)[\'"]/i', $code, $matches)) {
            $path = $matches[1];
            // Block if writing to root directory (single level path starting with /)
            // Examples: '/test.txt', '/wp-config.php', '/.htaccess'
            if (preg_match('/^\/[^\/]+\.(txt|php|htaccess|htpasswd|log|ini|conf)$/i', $path) ||
                preg_match('/^\/wp-config\.php$/i', $path) ||
                preg_match('/^\/\.htaccess$/i', $path) ||
                preg_match('/^\/\.htpasswd$/i', $path) ||
                preg_match('/^\/[^\/]+$/i', $path)) { // Single level path (root file)
                return true; // Permission violation
            }
        }
        
        // Check for path traversal attempts
        if (preg_match('/\.\.\s*\/\s*\.\./i', $code) || preg_match('/\/\s*\.\s*\.\s*\//i', $code)) {
            return true; // Permission violation
        }
        
        // Check for direct root directory file writes
        // Pattern: '/filename' or "/filename" (absolute paths to root)
        if (preg_match('/[\'"]\s*\/\s*[\w\-\.]+\.(txt|php|htaccess|htpasswd|log|ini|conf)\s*[\'"]/i', $code)) {
            return true; // Permission violation
        }
        
        return false;
    }

    /**
     * Detect infinite loop patterns in code
     */
    protected function has_infinite_loop( $code ) {
        // Remove comments and strings to avoid false positives
        $clean_code = $this->strip_comments_and_strings( $code );
        
        // Check for while(true) patterns
        if ( preg_match( '/while\s*\(\s*true\s*\)/i', $clean_code ) ) {
            return true;
        }
        
        // Check for for(;;) patterns (infinite for loop)
        if ( preg_match( '/for\s*\(\s*;\s*;\s*\)/i', $clean_code ) ) {
            return true;
        }
        
        // Check for while(1) patterns
        if ( preg_match( '/while\s*\(\s*1\s*\)/i', $clean_code ) ) {
            return true;
        }
        
        // Check for time-based busy-wait loops (CPU exhaustion pattern)
        // Pattern: while (time() - $start < X) or while(time() - $start < X)
        // These are dangerous as they burn CPU cycles
        if ( preg_match( '/while\s*\(\s*time\s*\(\s*\)\s*-\s*\$?\w+\s*[<>=]+\s*\d+/i', $clean_code ) ) {
            return true;
        }
        
        // Check for microtime-based busy-wait loops
        if ( preg_match( '/while\s*\(\s*microtime\s*\(\s*true\s*\)\s*-\s*\$?\w+\s*[<>=]+\s*[\d.]+/i', $clean_code ) ) {
            return true;
        }
        
        return false;
    }

    /**
     * Strip comments and strings from code to check for patterns
     */
    protected function strip_comments_and_strings($code) {
        $patterns = [
            '/\/\/.*$/m',           // Single-line comments
            '/\/\*.*?\*\//s',      // Multi-line comments
            '/\'[^\']*\'/',         // Single-quoted strings
            '/"[^"]*"/'             // Double-quoted strings
        ];
        return preg_replace($patterns, '', $code);
    }

    /**
     * Detect recursive hook patterns (hook calling itself)
     * Example: add_action('the_content', function() { apply_filters('the_content', ...) })
     */
    protected function has_recursive_hook( $code ) {
        // Find all add_action and add_filter calls
        $hook_functions = ['add_action', 'add_filter'];
        
        foreach ($hook_functions as $hook_func) {
            // Pattern to match: add_action('hook_name', function() { ... })
            // or add_filter('hook_name', function() { ... })
            $pattern = '/' . preg_quote($hook_func) . '\s*\(\s*([\'"])([^\'"\)]+)\1[^,]*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/';
            
            $offset = 0;
            while (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $hook_name = $matches[2][0];
                $match_pos = $matches[0][1];
                $match_len = strlen($matches[0][0]);
                $start_pos = $match_pos + $match_len - 1; // Position of opening brace
                
                // Find matching closing brace for the callback
                $brace_count = 0;
                $pos = $start_pos;
                $callback_start = $start_pos + 1;
                $callback_end = $start_pos;
                
                while ($pos < strlen($code)) {
                    $char = $code[$pos];
                    
                    if ($char === '{') {
                        $brace_count++;
                    } elseif ($char === '}') {
                        $brace_count--;
                        if ($brace_count === 0) {
                            $callback_end = $pos;
                            break;
                        }
                    }
                    $pos++;
                }
                
                if ($callback_end > $callback_start) {
                    $callback_code = substr($code, $callback_start, $callback_end - $callback_start);
                    
                    // Check if callback contains apply_filters or do_action with the same hook name
                    // Pattern: apply_filters('hook_name', ...) or do_action('hook_name', ...)
                    $recursive_patterns = [
                        '/apply_filters\s*\(\s*[\'"]' . preg_quote($hook_name, '/') . '[\'"]/i',
                        '/do_action\s*\(\s*[\'"]' . preg_quote($hook_name, '/') . '[\'"]/i',
                    ];
                    
                    foreach ($recursive_patterns as $recursive_pattern) {
                        if (preg_match($recursive_pattern, $callback_code)) {
                            return true;
                        }
                    }
                }
                
                // Move offset to continue searching
                $offset = $callback_end + 1;
            }
        }
        
        return false;
    }

    /**
     * Get information about recursive hook (hook name)
     */
    private function get_recursive_hook_info($code) {
        $hook_functions = ['add_action', 'add_filter'];
        
        foreach ($hook_functions as $hook_func) {
            // Pattern to match: add_action('hook_name', function() { ... })
            $pattern = '/' . preg_quote($hook_func) . '\s*\(\s*([\'"])([^\'"\)]+)\1[^,]*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/';
            
            $offset = 0;
            while (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $hook_name = $matches[2][0];
                $match_pos = $matches[0][1];
                $match_len = strlen($matches[0][0]);
                $start_pos = $match_pos + $match_len - 1;
                
                // Find matching closing brace
                $brace_count = 0;
                $pos = $start_pos;
                $callback_end = $start_pos;
                
                while ($pos < strlen($code)) {
                    $char = $code[$pos];
                    if ($char === '{') {
                        $brace_count++;
                    } elseif ($char === '}') {
                        $brace_count--;
                        if ($brace_count === 0) {
                            $callback_end = $pos;
                            break;
                        }
                    }
                    $pos++;
                }
                
                if ($callback_end > $start_pos + 1) {
                    $callback_code = substr($code, $start_pos + 1, $callback_end - $start_pos - 1);
                    
                    // Check if callback contains recursive call
                    $recursive_patterns = [
                        '/apply_filters\s*\(\s*[\'"]' . preg_quote($hook_name, '/') . '[\'"]/i',
                        '/do_action\s*\(\s*[\'"]' . preg_quote($hook_name, '/') . '[\'"]/i',
                    ];
                    
                    foreach ($recursive_patterns as $recursive_pattern) {
                        if (preg_match($recursive_pattern, $callback_code)) {
                            return ['hook' => $hook_name, 'function' => $hook_func];
                        }
                    }
                }
                
                $offset = $callback_end + 1;
            }
        }
        
        return ['hook' => 'unknown', 'function' => 'unknown'];
    }

    /**
     * Check if we're in a sensitive context where output should be restricted
     */
    protected function is_sensitive_context() {
        // Don't output in admin login/register pages
        if (function_exists('is_admin') && is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && in_array($screen->id, ['login', 'wp-login'])) {
                return true;
            }
        }
        
        // Don't output during WordPress installation
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return true;
        }
        
        // Don't output in REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        
        return false;
    }

    protected function nexter_run_eval( $code, $post_id ) {
        // Handle function declarations safely
        if (preg_match('/function\s+\w+\s*\(/i', $code)) {
            // Extract function names to check for conflicts
            preg_match_all('/function\s+(\w+)\s*\(/i', $code, $matches);
            if (!empty($matches[1])) {
                $existing_functions = array();
                foreach ($matches[1] as $function_name) {
                    if (function_exists($function_name)) {
                        $existing_functions[] = $function_name;
                    }
                }
                
                // If any functions already exist, prevent execution to avoid fatal errors
                if (!empty($existing_functions)) {
                    /* translators: %s: Comma-separated list of function names */
                    $this->nexter_deactivate_snippet($post_id, sprintf(__('Functions already exist: %s', 'nexter-extension'), implode(', ', $existing_functions)));
                    error_log('Nexter Extension: Prevented execution - Functions already exist: ' . implode(', ', $existing_functions));
                    // Return early to prevent fatal error from function redeclaration
                    return;
                }
            }
        }
        
        eval( $code ); // Run in isolated scope
    }
    

    /**
     * Handle fatal errors during shutdown
     */
    public function nexter_handle_fatal_errors() {
        $error = error_get_last();
    }

    /**
     * Deactivate snippet and optionally log error message
     */
    private function nexter_deactivate_snippet($post_id, $reason = '') {
        if ($post_id && function_exists('update_post_meta')) {
            //update_post_meta($post_id, 'nxt-code-status', 0);
            if (function_exists('do_action')) {
                do_action('nexter_php_snippet_deactivated', $post_id, $reason);
            }
        }
    }

    /**
     * Check snippet via loopback during save
     */
    public function validate_php_snippet_on_save($post_id, $code) {
        if (empty($code)) {
            // Auto-deactivate snippet when no code is provided
            $this->nexter_deactivate_snippet($post_id, __('Empty code provided', 'nexter-extension'));
            return new WP_Error('empty_code', __('No PHP code provided', 'nexter-extension'));
        }

        // Clean the code
        $code = trim($code);
        
        // Check for PHP syntax using multiple methods
        $syntax_error = $this->check_php_syntax($code);
        
        if (is_wp_error($syntax_error)) {
            /* if (function_exists('update_post_meta')) {
                update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
            } */
            // Auto-deactivate snippet when syntax errors are detected
            /* translators: %s: Syntax error message */
            $this->nexter_deactivate_snippet($post_id, sprintf(__('Syntax error detected: %s', 'nexter-extension'), $syntax_error->get_error_message()));
            return $syntax_error;
        }

        // Test execution safely
        $execution_result = $this->test_php_execution($code, $post_id);
        if (is_wp_error($execution_result)) {
            /* if (function_exists('update_post_meta')) {
                update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
            } */
            // Auto-deactivate snippet when execution errors are detected
            /* translators: %s: Execution error message */
            $this->nexter_deactivate_snippet($post_id, sprintf(__('Execution error detected: %s', 'nexter-extension'), $execution_result->get_error_message()));
            return $execution_result;
        }
        
        /* if (function_exists('update_post_meta')) {
            update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'yes');
        } */
        return true;
    }

    /**
     * Comprehensive PHP syntax checking
     */
    private function check_php_syntax($code) {
        // Method 1: Use token_get_all for syntax checking
        $token_error = $this->check_syntax_with_tokens($code);
        if (is_wp_error($token_error)) {
            return $token_error;
        }

        // Method 2: Use temporary file with php -l
        $lint_error = $this->check_syntax_with_lint($code);
        if (is_wp_error($lint_error)) {
            return $lint_error;
        }

        // Method 3: Use eval with error capture
        $eval_error = $this->check_syntax_with_eval($code);
        if (is_wp_error($eval_error)) {
            return $eval_error;
        }

        return true;
    }

    /**
     * Check syntax using PHP tokens
     */
    private function check_syntax_with_tokens($code) {
        // First do a simple line-by-line check for common errors
        $line_check_error = $this->check_lines_for_errors($code);
        if (is_wp_error($line_check_error)) {
            return $line_check_error;
        }

        // Add PHP opening tag for tokenization
        $test_code = "<?php\n" . $code;
        
        // Suppress errors and capture them
        $old_error_reporting = error_reporting(0);
        
        try {
            $tokens = token_get_all($test_code);
            error_reporting($old_error_reporting);
        } catch (ParseError $e) {
            error_reporting($old_error_reporting);
            return $this->format_parse_error($e, $code);
        } catch (Error $e) {
            error_reporting($old_error_reporting);
            return $this->format_php_error($e, $code);
        }
        
        error_reporting($old_error_reporting);
        return true;
    }

    /**
     * Check each line for common syntax errors
     */
    private function check_lines_for_errors($code) {
        $lines = explode("\n", $code);
        $all_errors = [];
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            $line_number = $i + 1;
            
            // Skip empty lines and comments
            if (empty($line) || preg_match('/^\s*(\/\/|\*|#)/', $line)) {
                continue;
            }
            
            // Check for common typos and syntax errors
            $line_errors = $this->check_line_for_all_errors($line, $line_number, $lines, $i);
            if (!empty($line_errors)) {
                $all_errors = array_merge($all_errors, $line_errors);
            }
        }
        
        // If we found errors, return them all
        if (!empty($all_errors)) {
            $error_message = $this->format_multiple_errors($all_errors);
            return new WP_Error('multiple_syntax_errors', $error_message);
        }
        
        return true;
    }

    /**
     * Check a single line for all possible errors
     */
    private function check_line_for_all_errors($line, $line_number, $lines, $current_index) {
        $errors = [];
        
        // Check for common typos first
        $typo_error = $this->check_for_typos($line, $line_number);
        if ($typo_error) {
            $errors[] = $typo_error;
        }
        
        // Check for unterminated strings
        $unterminated_error = $this->check_unterminated_string($line, $line_number);
        if (is_wp_error($unterminated_error)) {
            $errors[] = [
                'line' => $line_number,
                'type' => 'unterminated_string',
                'message' => $unterminated_error->get_error_message(),
                'code' => $line
            ];
        }
        
        // Check for unquoted identifiers (only if no typos found)
        if (empty($errors)) {
            $unquoted_error = $this->check_unquoted_identifier($line, $line_number);
            if (is_wp_error($unquoted_error)) {
                $errors[] = [
                    'line' => $line_number,
                    'type' => 'unquoted_identifier',
                    'message' => $unquoted_error->get_error_message(),
                    'code' => $line
                ];
            }
        }
        
        return $errors;
    }

    /**
     * Check for common typos and function name errors
     */
    private function check_for_typos($line, $line_number) {
        // Common typos for echo
        if (preg_match('/^\s*(ech|ecoh|eho|ehco)\s+/', $line, $matches)) {
            $typo = $matches[1];
            /* translators: 1: Function name (typo), 2: Line number */
            return [
                'line' => $line_number,
                'type' => 'typo',
                'message' => sprintf(__('Syntax Error: Unknown function \'%1$s\' on line %2$d', 'nexter-extension'), $typo, $line_number),
                'code' => $line
            ];
        }
        
        // Common typos for print
        if (preg_match('/^\s*(prin|pint|prnt)\s+/', $line, $matches)) {
            $typo = $matches[1];
            /* translators: 1: Function name (typo), 2: Line number */
            return [
                'line' => $line_number,
                'type' => 'typo',
                'message' => sprintf(__('Syntax Error: Unknown function \'%1$s\' on line %2$d', 'nexter-extension'), $typo, $line_number),
                'code' => $line
            ];
        }
        
        // Check for other common function typos
        $common_functions = [
            'functoin' => 'function',
            'funtion' => 'function',
            'fucntion' => 'function',
            'retrun' => 'return',
            'retur' => 'return',
            'includ' => 'include',
            'requir' => 'require',
            'isset' => 'isset', // This is correct, but check common typos
            'iset' => 'isset',
            'empyt' => 'empty',
            'emty' => 'empty'
        ];
        
        foreach ($common_functions as $typo => $correct) {
            if (preg_match('/^\s*' . preg_quote($typo) . '\s*[\(\s]/', $line)) {
                /* translators: 1: Function name (typo), 2: Line number */
                return [
                    'line' => $line_number,
                    'type' => 'typo',
                    'message' => sprintf(__('Syntax Error: Unknown function \'%1$s\' on line %2$d', 'nexter-extension'), $typo, $line_number),
                    'code' => $line
                ];
            }
        }
        
        return null;
    }

    /**
     * Check for unterminated strings on a specific line
     */
    private function check_unterminated_string($line, $line_number) {
        // Check if line starts with echo and has an opening quote but no closing quote
        if (preg_match('/^\s*echo\s+/', $line)) {
            // Use a more sophisticated approach to count quotes properly
            $single_quotes = 0;
            $double_quotes = 0;
            $in_single_string = false;
            $in_double_string = false;
            $escaped = false;
            
            for ($i = 0; $i < strlen($line); $i++) {
                $char = $line[$i];
                
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                
                if ($char === "'" && !$in_double_string) {
                    $single_quotes++;
                    $in_single_string = !$in_single_string;
                } elseif ($char === '"' && !$in_single_string) {
                    $double_quotes++;
                    $in_double_string = !$in_double_string;
                }
            }
            
            // Check for unterminated single-quoted string
            if ($in_single_string) {
                /* translators: %d: Line number */
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        __('Syntax Error: Unterminated string on line %d', 'nexter-extension'),
                        $line_number
                    )
                );
            }
            
            // Check for unterminated double-quoted string
            if ($in_double_string) {
                /* translators: %d: Line number */
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        __('Syntax Error: Unterminated string on line %d', 'nexter-extension'),
                        $line_number
                    )
                );
            }
        }
        
        return true;
    }

    /**
     * Check for unquoted identifiers
     */
    private function check_unquoted_identifier($line, $line_number) {
        // Check for patterns like: echo Hi.....';
        if (preg_match('/echo\s+([a-zA-Z_][a-zA-Z0-9_]*[^\'\"]*[\'\"];?\s*)$/', $line)) {
            // Check if it starts with an identifier but ends with a quote
            if (preg_match('/echo\s+([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"];?$/', $line, $matches)) {
                $identifier = $matches[1];
                /* translators: %d: Line number */
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        __('Syntax Error: Unexpected identifier on line %d', 'nexter-extension'),
                        $line_number
                    )
                );
            }
        }
        
        return true;
    }

    /**
     * Check syntax using php -l command
     */
    private function check_syntax_with_lint($code) {
        if (!function_exists('shell_exec')) {
            return true; // Skip if shell_exec is disabled
        }

        $temp_file = tempnam(sys_get_temp_dir(), 'nexter_php_check');
        if ($temp_file === false) {
            return true; // Skip lint check if temp file creation fails
        }
        $written = file_put_contents($temp_file, "<?php\n" . $code);
        if ($written === false) {
            @unlink($temp_file);
            return true; // Skip if write fails
        }
        file_put_contents($temp_file, "<?php\n" . $code);
        
        $output = shell_exec("php -l " . escapeshellarg($temp_file) . " 2>&1");
        unlink($temp_file);
        
        if ($output && strpos($output, 'Parse error') !== false) {
            // Extract error details
            preg_match('/Parse error: (.+?) in .+ on line (\d+)/', $output, $matches);
            $error_message = isset($matches[1]) ? $matches[1] : __('Unknown syntax error', 'nexter-extension');
            $line_number = isset($matches[2]) ? max(1, intval($matches[2]) - 1) : 1;
            
            return $this->format_syntax_error($error_message, $line_number, $code);
        }
        
        return true;
    }

    /**
     * Check syntax using eval
     */
    private function check_syntax_with_eval($code) {
        // Handle function declarations in syntax checking
        if (preg_match('/function\s+\w+\s*\(/i', $code)) {
            // For function declarations, we'll skip the eval syntax check
            // since function redeclaration would cause a fatal error
            // Token and lint checks already handled syntax validation
            return true;
        }
        
        $old_error_reporting = error_reporting(E_ALL);
        
        try {
            // Use eval with a condition that prevents execution
            eval('return false; ' . $code);
        } catch (ParseError $e) {
            error_reporting($old_error_reporting);
            return $this->format_parse_error($e, $code);
        } catch (Error $e) {
            error_reporting($old_error_reporting);
            return $this->format_php_error($e, $code);
        }
        
        error_reporting($old_error_reporting);
        return true;
    }

    /**
     * Test PHP code execution safely
     */
    private function test_php_execution($code, $post_id, $attributes = array()) {
        // Check for infinite loops and CPU-intensive busy-wait loops before execution
        if ($this->has_infinite_loop($code)) {
            // Check if it's a time-based busy-wait loop
            $clean_code = $this->strip_comments_and_strings($code);
            $is_busy_wait = preg_match('/while\s*\(\s*(?:time|microtime)\s*\([^)]*\)\s*-\s*\$?\w+\s*[<>=]+\s*[\d.]+/i', $clean_code);
            
            if ($is_busy_wait) {
                return new WP_Error(
                    'busy_wait_loop_detected',
                    __('CPU-intensive busy-wait loop detected', 'nexter-extension')
                );
            }
            
            return new WP_Error(
                'infinite_loop_detected',
                __('Infinite loop detected', 'nexter-extension')
            );
        }
        
        // Check for recursive hooks before execution
        if ($this->has_recursive_hook($code)) {
            $hook_info = $this->get_recursive_hook_info($code);
            $hook_name = isset($hook_info['hook']) ? $hook_info['hook'] : 'hook';
            
            /* translators: %s: Hook name */
            return new WP_Error(
                'recursive_hook_detected',
                sprintf(__('Recursive hook detected: %s', 'nexter-extension'), $hook_name)
            );
        }
        
        // Handle function declarations in execution testing
        if (preg_match('/function\s+\w+\s*\(/i', $code)) {
            // For function declarations, we'll skip the execution test
            // since function redeclaration would cause a fatal error
            // Syntax validation already passed
            return true;
        }
        
        ob_start();
        $old_error_reporting = error_reporting(E_ALL | E_STRICT);
        
        // Set execution time limit for testing (5 seconds max)
        $old_time_limit = ini_get('max_execution_time');
        if (function_exists('set_time_limit')) {
            @set_time_limit(5);
        }
        
        // Track errors and warnings
        $captured_errors = array();
        $old_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$captured_errors) {
            // Only capture warnings and notices (not fatal errors which are caught by exceptions)
            if (in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED])) {
                $captured_errors[] = [
                    'type' => $errno,
                    'message' => $errstr,
                    'line' => $errline
                ];
            }
            // Return false to allow PHP's default error handler to also run
            return false;
        });
        
        try {
            // Extract shortcode attributes as variables if provided
            if (!empty($attributes) && is_array($attributes)) {
                foreach ($attributes as $key => $value) {
                    if (is_string($key) && !empty($key)) {
                        // Use variable variables to set dynamic variable names
                        ${$key} = $value;
                    }
                }
            }
            
            // Check for wp_die() usage - it will terminate execution, so we need special handling
            $has_wp_die = preg_match('/wp_die\s*\(/i', $code);
            
            // If wp_die() is detected, skip execution testing to prevent AJAX save from breaking
            // wp_die() is a valid WordPress function, but it terminates execution
            // We'll validate syntax but skip execution testing to avoid breaking the save flow
            if ($has_wp_die) {
                // wp_die() will terminate execution, so we can't safely test execution
                // Syntax validation already passed, so we'll allow the code
                // Note: wp_die() is a valid WordPress function for security/error handling
                return true;
            }
            
            // Check for exit() and die() usage - they will terminate execution immediately
            // These functions can break AJAX requests during validation, so we skip execution testing
            // exit() and die() are valid PHP functions but terminate execution without WordPress cleanup
            // Pattern matches: exit(), exit('message'), exit;, die(), die('message'), die;
            $has_exit = preg_match('/\bexit\s*(\(|;)/i', $code);
            $has_die = preg_match('/\bdie\s*(\(|;)/i', $code);
            if ($has_exit || $has_die) {
                // exit() and die() will terminate execution immediately, breaking AJAX save
                // Syntax validation already passed, so we'll allow the code
                // Note: exit() and die() are valid PHP functions but should be used carefully
                // In AJAX contexts, wp_die() is preferred for proper WordPress cleanup
                return true;
            }
            
            // Check for sleep() usage - it will delay execution and may cause timeout during validation
            // sleep() is a valid PHP function, but it can cause execution timeouts during testing
            // We'll validate syntax but skip execution testing to avoid timeout errors
            $has_sleep = preg_match('/sleep\s*\(/i', $code);
            if ($has_sleep) {
                // sleep() will delay execution, which may cause timeout during validation
                // Syntax validation already passed, so we'll allow the code
                // Note: sleep() is a valid PHP function for delays, rate limiting, etc.
                return true;
            }
            
            // Check for "headers already sent" pattern - output before wp_redirect() or header()
            $headers_issue = $this->detect_headers_already_sent($code);
            if ($headers_issue !== false) {
                return new WP_Error(
                    'headers_already_sent',
                    __('Headers already sent error detected', 'nexter-extension')
                );
            }
            
            // Execute in a controlled environment with timeout protection
            $start_time = microtime(true);
            eval($code);
            $execution_time = microtime(true) - $start_time;
            
            // Warn if execution took too long (potential infinite loop that was caught by timeout)
            if ($execution_time > 4.5) {
                return new WP_Error(
                    'execution_timeout',
                    __('Code execution timeout', 'nexter-extension')
                );
            }
            
            // Check for code that registers hooks (like add_action) and test the callback
            // This handles cases where undefined variables are used inside hook callbacks
            // We extract and test callbacks separately because they don't execute until the hook fires
            $this->test_registered_hooks($code, $captured_errors);
            
            $output = ob_get_clean();
            
            // Restore error handler
            if ($old_error_handler !== null) {
                set_error_handler($old_error_handler);
            } else {
                restore_error_handler();
            }
            error_reporting($old_error_reporting);
            
            // Restore time limit
            if (function_exists('set_time_limit') && $old_time_limit !== false) {
                @set_time_limit($old_time_limit);
            }
            
            // If we captured any errors, return them
            if (!empty($captured_errors)) {
                return $this->format_execution_warnings($captured_errors, $code);
            }
            
            return true;
        } catch (ParseError $e) {
            ob_end_clean();
            if ($old_error_handler !== null) {
                set_error_handler($old_error_handler);
            } else {
                restore_error_handler();
            }
            error_reporting($old_error_reporting);
            if (function_exists('set_time_limit') && $old_time_limit !== false) {
                @set_time_limit($old_time_limit);
            }
            return $this->format_parse_error($e, $code);
        } catch (Error $e) {
            ob_end_clean();
            if ($old_error_handler !== null) {
                set_error_handler($old_error_handler);
            } else {
                restore_error_handler();
            }
            error_reporting($old_error_reporting);
            if (function_exists('set_time_limit') && $old_time_limit !== false) {
                @set_time_limit($old_time_limit);
            }
            return $this->format_php_error($e, $code);
        } catch (Exception $e) {
            ob_end_clean();
            if ($old_error_handler !== null) {
                set_error_handler($old_error_handler);
            } else {
                restore_error_handler();
            }
            error_reporting($old_error_reporting);
            if (function_exists('set_time_limit') && $old_time_limit !== false) {
                @set_time_limit($old_time_limit);
            }
            /* translators: 1: Line number, 2: Exception message */
            return new WP_Error(
                'execution_error',
                sprintf(
                    __('Runtime Exception on line %1$d: %2$s', 'nexter-extension'),
                    $e->getLine(),
                    $e->getMessage()
                )
            );
        }
    }
    
    /**
     * Test registered hooks for undefined variables
     */
    private function test_registered_hooks($code, &$captured_errors) {
        // Find all add_action and add_filter calls with anonymous functions
        // Use a more robust approach to extract callback bodies
        $hook_functions = ['add_action', 'add_filter'];
        
        foreach ($hook_functions as $hook_func) {
            // Find all occurrences of add_action/add_filter
            $pattern = '/' . preg_quote($hook_func) . '\s*\([^,]+,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/';
            
            $offset = 0;
            while (preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $match_pos = $matches[0][1];
                $match_len = strlen($matches[0][0]);
                $start_pos = $match_pos + $match_len - 1; // Position of opening brace
                
                // Find matching closing brace
                $brace_count = 0;
                $pos = $start_pos;
                $callback_start = $start_pos + 1;
                $callback_end = $start_pos;
                
                while ($pos < strlen($code)) {
                    $char = $code[$pos];
                    
                    if ($char === '{') {
                        $brace_count++;
                    } elseif ($char === '}') {
                        $brace_count--;
                        if ($brace_count === 0) {
                            $callback_end = $pos;
                            break;
                        }
                    }
                    $pos++;
                }
                
                if ($callback_end > $callback_start) {
                    $callback_code = substr($code, $callback_start, $callback_end - $callback_start);
                    $line_number = substr_count(substr($code, 0, $callback_start), "\n") + 1;
                    
                    $this->test_callback_code($callback_code, $captured_errors, $line_number);
                }
                
                // Move offset to continue searching
                $offset = $callback_end + 1;
            }
        }
    }
    
    /**
     * Test callback code for undefined variables, functions, and classes
     * 
     * Note: If callback code contains try/catch blocks, exceptions thrown inside
     * will be caught by the callback's own catch block and won't propagate.
     * This method checks for try/catch as a safety measure, but properly
     * handled exceptions won't reach our outer catch block anyway.
     */
    private function test_callback_code($callback_code, &$captured_errors, $base_line = 0) {
        // Check if callback has proper try/catch blocks - if so, exceptions are intentionally handled
        // This is a safety check; properly caught exceptions won't propagate anyway
        $has_try_catch = preg_match('/try\s*\{[^}]*catch\s*\([^)]+\)\s*\{/s', $callback_code);
        
        // Try to execute the callback code in isolation to catch undefined variables, functions, and classes
        $callback_errors = array();
        
        $old_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$callback_errors, $base_line) {
            if (in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_STRICT])) {
                // Adjust line number to account for base line
                $adjusted_line = $base_line > 0 ? ($base_line + $errline - 1) : $errline;
                $callback_errors[] = [
                    'type' => $errno,
                    'message' => $errstr,
                    'line' => $adjusted_line
                ];
            }
            return false;
        });
        
        $old_error_reporting = error_reporting(E_ALL | E_STRICT);
        
        try {
            // Wrap callback code to simulate execution
            // If callback has try/catch, exceptions thrown inside will be caught by the callback's own catch block
            eval($callback_code);
        } catch (\Throwable $e) {
            // Catch Throwable (PHP 7+) - includes both Error and Exception
            // Only report if the callback doesn't have its own try/catch (unhandled exception)
            // If callback has try/catch, the exception should be handled internally
            if (!$has_try_catch) {
                $adjusted_line = $base_line > 0 ? ($base_line + $e->getLine() - 1) : $e->getLine();
                
                // Check if this is a fatal error (Error) or just an exception
                if ($e instanceof \Error) {
                    $callback_errors[] = [
                        'type' => 'fatal_error',
                        'message' => $e->getMessage(),
                        'line' => $adjusted_line,
                        'error_class' => get_class($e)
                    ];
                } else {
                    // Exception that escaped - only report if not intentionally caught
                    $callback_errors[] = [
                        'type' => 'exception',
                        'message' => $e->getMessage(),
                        'line' => $adjusted_line,
                        'error_class' => get_class($e)
                    ];
                }
            }
            // If callback has try/catch, we assume exceptions are intentionally handled
        } catch (\Error $e) {
            // Fallback for PHP < 7 (though Throwable should cover this)
            if (!$has_try_catch) {
                $adjusted_line = $base_line > 0 ? ($base_line + $e->getLine() - 1) : $e->getLine();
                $callback_errors[] = [
                    'type' => 'fatal_error',
                    'message' => $e->getMessage(),
                    'line' => $adjusted_line,
                    'error_class' => get_class($e)
                ];
            }
        } catch (\Exception $e) {
            // Fallback for PHP < 7
            if (!$has_try_catch) {
                $adjusted_line = $base_line > 0 ? ($base_line + $e->getLine() - 1) : $e->getLine();
                $callback_errors[] = [
                    'type' => 'exception',
                    'message' => $e->getMessage(),
                    'line' => $adjusted_line,
                    'error_class' => get_class($e)
                ];
            }
        }
        
        // Merge callback errors into main errors array
        $captured_errors = array_merge($captured_errors, $callback_errors);
        
        if ($old_error_handler !== null) {
            set_error_handler($old_error_handler);
        } else {
            restore_error_handler();
        }
        error_reporting($old_error_reporting);
    }
    
    /**
     * Format execution warnings and notices into short error messages
     */
    private function format_execution_warnings($errors, $code) {
        $error_messages = array();
        
        foreach ($errors as $error) {
            $line_number = isset($error['line']) ? $error['line'] : 0;
            $message = $error['message'];
            $error_type = $error['type'];
            
            // Determine error type name
            $type_name = __('Warning', 'nexter-extension');
            if ($error_type === 'fatal_error') {
                $type_name = __('Fatal Error', 'nexter-extension');
            } elseif ($error_type === 'exception') {
                $type_name = __('Exception', 'nexter-extension');
            } elseif ($error_type === E_NOTICE || $error_type === E_USER_NOTICE) {
                $type_name = __('Notice', 'nexter-extension');
            } elseif ($error_type === E_WARNING || $error_type === E_USER_WARNING) {
                $type_name = __('Warning', 'nexter-extension');
            }
            
            /* translators: 1: Error type name, 2: Line number, 3: Error message */
            $error_msg = sprintf(
                __('%1$s on line %2$d: %3$s', 'nexter-extension'),
                $type_name,
                $line_number > 0 ? $line_number : __('unknown', 'nexter-extension'),
                $message
            );
            
            $error_messages[] = $error_msg;
        }
        
        $total_errors = count($error_messages);
        /* translators: %d: Number of execution errors */
        $formatted_message = sprintf(
            _n('Found %d execution error:', 'Found %d execution errors:', $total_errors, 'nexter-extension'),
            $total_errors
        ) . '<br>';
        
        foreach ($error_messages as $index => $error_msg) {
            $formatted_message .= $error_msg;
            if ($index < count($error_messages) - 1) {
                $formatted_message .= "<br>";
            }
        }
        
        return new WP_Error('syntax_error', $formatted_message);
    }

    /**
     * Format parse error with short message
     */
    private function format_parse_error($error, $code) {
        $line_number = $error->getLine();
        $message = $error->getMessage();
        
        return new WP_Error(
            'syntax_error',
            sprintf(
                __('Parse Error on line %d: %s', 'nexter-extension'),
                $line_number,
                $message
            )
        );
    }

    /**
     * Format PHP error with short message
     */
    private function format_php_error($error, $code) {
        $line_number = $error->getLine();
        $message = $error->getMessage();
        
        /* translators: 1: Line number, 2: Fatal error message */
        return new WP_Error(
            'syntax_error',
            sprintf(
                __('Fatal Error on line %1$d: %2$s', 'nexter-extension'),
                $line_number,
                $message
            )
        );
    }
    
    /**
     * Get short error message for fatal errors
     */
    private function get_fatal_error_help($error_message, $problem_line) {
        return "";
    }

    /**
     * Check if a constant is a WordPress constant being checked with defined()
     * This recognizes common WordPress security patterns
     */
    private function is_wordpress_constant($constant_name, $code_context) {
        // Common WordPress constants that are typically checked with defined()
        $wordpress_constants = [
            'ABSPATH',
            'WPINC',
            'WP_CONTENT_DIR',
            'WP_PLUGIN_DIR',
            'WP_DEBUG',
            'DOING_AJAX',
            'DOING_CRON',
            'WP_ADMIN',
            'WP_CLI',
            'REST_REQUEST',
            'WP_INSTALLING',
            'WP_UNINSTALL_PLUGIN'
        ];
        
        // Check if it's a known WordPress constant
        if (!in_array($constant_name, $wordpress_constants)) {
            return false;
        }
        
        // Check if the constant is being used with defined() function
        // Pattern: defined('CONSTANT_NAME') or defined("CONSTANT_NAME")
        $pattern = '/defined\s*\(\s*[\'"]' . preg_quote($constant_name, '/') . '[\'"]\s*\)/i';
        if (preg_match($pattern, $code_context)) {
            return true;
        }
        
        // Also check if it's in a security check pattern: if (!defined('CONSTANT'))
        $security_pattern = '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]' . preg_quote($constant_name, '/') . '[\'"]\s*\)\s*\)/i';
        if (preg_match($security_pattern, $code_context)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if code contains WordPress security check pattern
     * Pattern: if (!defined('ABSPATH')) { exit; }
     */
    private function has_wordpress_security_check($code) {
        // Check for common WordPress security patterns
        $patterns = [
            '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{[^}]*exit/i',
            '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{[^}]*die/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Detect "headers already sent" pattern - output before wp_redirect() or header() calls
     * Returns descriptive message if pattern is detected, false otherwise
     */
    private function detect_headers_already_sent($code) {
        // Find all wp_redirect() and header() calls
        $redirect_patterns = [
            '/wp_redirect\s*\(/i',
            '/header\s*\(\s*[\'"]/i'
        ];
        
        $has_redirect = false;
        $redirect_positions = [];
        
        foreach ($redirect_patterns as $pattern) {
            if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                $has_redirect = true;
                foreach ($matches[0] as $match) {
                    $redirect_positions[] = $match[1];
                }
            }
        }
        
        if (!$has_redirect) {
            return false; // No redirect/header calls, no issue
        }
        
        // Find all output statements (echo, print, var_dump, print_r, etc.)
        $output_patterns = [
            '/echo\s+/i',
            '/print\s+/i',
            '/var_dump\s*\(/i',
            '/print_r\s*\(/i',
            '/var_export\s*\(/i',
            '/printf\s*\(/i',
            '/vprintf\s*\(/i'
        ];
        
        $output_positions = [];
        foreach ($output_patterns as $pattern) {
            if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $output_positions[] = $match[1];
                }
            }
        }
        
        if (empty($output_positions)) {
            return false; // No output statements, no issue
        }
        
        // Check if any output comes before any redirect/header call
        $min_redirect_pos = min($redirect_positions);
        foreach ($output_positions as $output_pos) {
            if ($output_pos < $min_redirect_pos) {
                // Found output before redirect - this will cause "headers already sent" error
                // Extract the problematic line for better error message
                $lines = explode("\n", $code);
                $output_line_num = substr_count(substr($code, 0, $output_pos), "\n") + 1;
                $redirect_line_num = substr_count(substr($code, 0, $min_redirect_pos), "\n") + 1;
                
                $output_line = isset($lines[$output_line_num - 1]) ? trim($lines[$output_line_num - 1]) : '';
                $redirect_line = isset($lines[$redirect_line_num - 1]) ? trim($lines[$redirect_line_num - 1]) : '';
                
                /* translators: 1: Output line number, 2: Output line content, 3: Redirect line number, 4: Redirect line content */
                return sprintf(
                    __('Output on line %1$d (\'%2$s\') appears before redirect/header call on line %3$d (\'%4$s\')', 'nexter-extension'),
                    $output_line_num,
                    substr($output_line, 0, 50),
                    $redirect_line_num,
                    substr($redirect_line, 0, 50)
                );
            }
        }
        
        return false; // Output comes after redirect, which is fine
    }

    /**
     * Format syntax error from php -l
     */
    private function format_syntax_error($error_message, $line_number, $code) {
        /* translators: 1: Line number, 2: Syntax error message */
        return new WP_Error(
            'syntax_error',
            sprintf(
                __('Syntax Error on line %1$d: %2$s', 'nexter-extension'),
                $line_number,
                $error_message
            )
        );
    }

    /**
     * Get short error message
     */
    private function get_error_help($error_message, $problem_line) {
        return "";
    }

    /**
     * Format multiple errors into short error messages
     */
    private function format_multiple_errors($errors) {
        $total_errors = count($errors);
        /* translators: %d: Number of syntax errors */
        $message = sprintf(
            _n('Found %d syntax error:', 'Found %d syntax errors:', $total_errors, 'nexter-extension'),
            $total_errors
        ) . '<br>';
        
        foreach ($errors as $index => $error) {
            $line_num = $error['line'];
            
            /* translators: 1: Line number, 2: Error message */
            $message .= sprintf(__('Line %1$d: %2$s', 'nexter-extension'), $line_num, $error['message']);
            
            if ($index < count($errors) - 1) {
                $message .= "<br>";
            }
        }
        
        return $message;
    }
}

// Initialize executor
Nexter_Builder_Code_Snippets_Executor::get_instance();
