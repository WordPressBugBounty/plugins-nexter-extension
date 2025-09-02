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

        $code = html_entity_decode(htmlspecialchars_decode($code));

        // Enhanced security: Check for dangerous functions
        if ($this->is_code_not_allowed($code)) {
            $this->nexter_deactivate_snippet($post_id, 'Dangerous function detected in code.');
            return false;
        }
        
        // Additional suspicious pattern checks
        if (preg_match_all('/(base64_decode|error_reporting|ini_set|eval)\s*\(/i', $code, $matches) && count($matches[0]) > 3) {
            $this->nexter_deactivate_snippet($post_id, 'Multiple suspicious functions detected.');
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
                // Blacklist of dangerous variable names
                $forbidden_vars = [
                    '_POST', '_GET', '_COOKIE', '_SESSION', '_SERVER', '_ENV', '_FILES',
                    'GLOBALS', 'wp_query', 'wpdb', 'current_user', 'wp_roles'
                ];
                
                foreach ($attributes as $key => $value) {
                    if (is_string($key) && !empty($key)) {
                        // Security: Validate variable name format and check blacklist
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) && 
                            !in_array($key, $forbidden_vars) && 
                            strpos($key, '_') !== 0 && 
                            strlen($key) <= 50) {
                            // Use variable variables to set dynamic variable names
                            ${$key} = is_string($value) ? sanitize_text_field($value) : $value;
                        }
                    }
                }
            }
            
            $this->nexter_run_eval($code);
            if ($catch_output) {
                $output = ob_get_contents();
                ob_end_clean();
                // Security: Only echo output if it's safe and not in sensitive contexts
                if (!defined('DOING_AJAX') || !DOING_AJAX) {
                    // Additional security: Check if we should allow output
                    if ($this->is_output_safe($output) && !$this->is_sensitive_context()) {
                        echo $output;
                    }
                }
            }
        } catch (\ParseError $e) {
            if ($catch_output) {
                ob_end_clean();
            }
            // Return detailed parse error with line number
            return new WP_Error(
                'php_parse_error',
                sprintf('Parse error: %s on line %d', $e->getMessage(), $e->getLine())
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
            return new WP_Error(
                'php_exception',
                sprintf('Exception: %s on line %d', $e->getMessage(), $e->getLine())
            );
        }

        if ($error) {
            $this->nexter_deactivate_snippet($post_id, $error->getMessage());
            return new WP_Error(
                'php_error',
                sprintf('Fatal error: %s on line %d', $error->getMessage(), $error->getLine())
            );
        }

        // Track snippet execution for conditional logic
        if ($post_id) {
            $this->track_snippet_execution($post_id);
        }

        return isset($output) ? $output : true;
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
        // Comprehensive list of dangerous functions
        $dangerous_functions = [
            // Code execution
            'eval', 'assert', 'create_function', 'call_user_func', 'call_user_func_array',
            // System commands
            'system', 'exec', 'passthru', 'shell_exec', 'popen', 'proc_open', 'proc_close',
            // File operations that could be dangerous
            'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fputs',
            // Network functions
            'dns_get_record', 'gethostbyname', 'fsockopen', 'curl_exec', 'curl_init',
            // Obfuscation
            'base64_decode', 'str_rot13', 'convert_uudecode',
            // Dangerous globals manipulation
            'extract', 'parse_str', 'import_request_variables',
            // Include/require with variables (potential LFI)
            'include_once', 'require_once'
        ];
        
        // Check for dangerous functions with word boundaries
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $dangerous_functions)) . ')\s*\(/i';
        
        // Also check for variable functions like $func()
        $var_function_pattern = '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\(/';
        
        return preg_match($pattern, $code) || preg_match($var_function_pattern, $code);
    }

    /**
     * Check if output is safe to echo
     */
    protected function is_output_safe($output) {
        if (empty($output)) {
            return true;
        }
        
        // Check for potentially dangerous content
        $dangerous_patterns = [
            '/<script[^>]*>/i',           // Script tags
            '/javascript:/i',             // Javascript protocols
            '/on\w+\s*=/i',              // Event handlers (onclick, onload, etc)
            '/<iframe[^>]*>/i',          // Iframes
            '/<object[^>]*>/i',          // Objects
            '/<embed[^>]*>/i',           // Embeds
            '/data:.*base64/i',          // Data URLs with base64
            '/vbscript:/i',              // VBScript
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return false;
            }
        }
        
        return true;
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

    protected function nexter_run_eval( $code ) {
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
                
                // If any functions already exist, we need to handle this carefully
                if (!empty($existing_functions)) {
                    // For now, we'll allow the execution but log a warning
                    // In a production environment, you might want to handle this differently
                    error_log('Nexter Extension: Functions already exist: ' . implode(', ', $existing_functions));
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
            update_post_meta($post_id, 'nxt-code-status', 0);
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
            $this->nexter_deactivate_snippet($post_id, 'Empty code provided');
            return new WP_Error('empty_code', 'No PHP code provided');
        }

        // Clean the code
        $code = trim($code);
        
        // Check for PHP syntax using multiple methods
        $syntax_error = $this->check_php_syntax($code);
        if (is_wp_error($syntax_error)) {
            if (function_exists('update_post_meta')) {
                update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
            }
            // Auto-deactivate snippet when syntax errors are detected
            $this->nexter_deactivate_snippet($post_id, 'Syntax error detected: ' . $syntax_error->get_error_message());
            return $syntax_error;
        }

        // Test execution safely
        $execution_result = $this->test_php_execution($code, $post_id);
        if (is_wp_error($execution_result)) {
            if (function_exists('update_post_meta')) {
                update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'no');
            }
            // Auto-deactivate snippet when execution errors are detected
            $this->nexter_deactivate_snippet($post_id, 'Execution error detected: ' . $execution_result->get_error_message());
            return $execution_result;
        }
        
        if (function_exists('update_post_meta')) {
            update_post_meta($post_id, 'nxt-code-php-hidden-execute', 'yes');
        }
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
        
        // Check for missing semicolons (only if no other major errors on this line)
        if (empty($errors)) {
            $semicolon_error = $this->check_missing_semicolon($line, $line_number, $lines, $current_index);
            if (is_wp_error($semicolon_error)) {
                $errors[] = [
                    'line' => $line_number,
                    'type' => 'missing_semicolon',
                    'message' => $semicolon_error->get_error_message(),
                    'code' => $line
                ];
            }
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
            return [
                'line' => $line_number,
                'type' => 'typo',
                'message' => "Syntax Error: Unknown function '$typo' on line $line_number\n\nLine $line_number: $line\n\n💡 Help: Did you mean 'echo'? Check the spelling of function names.\nCorrect format: echo 'Hello World';",
                'code' => $line
            ];
        }
        
        // Common typos for print
        if (preg_match('/^\s*(prin|pint|prnt)\s+/', $line, $matches)) {
            $typo = $matches[1];
            return [
                'line' => $line_number,
                'type' => 'typo',
                'message' => "Syntax Error: Unknown function '$typo' on line $line_number\n\nLine $line_number: $line\n\n💡 Help: Did you mean 'print'? Check the spelling of function names.\nCorrect format: print 'Hello World';",
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
                return [
                    'line' => $line_number,
                    'type' => 'typo',
                    'message' => "Syntax Error: Unknown function '$typo' on line $line_number\n\nLine $line_number: $line\n\n💡 Help: Did you mean '$correct'? Check the spelling of function names.",
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
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        "Syntax Error: Unterminated string on line %d\n\nLine %d: %s\n\n💡 Help: You're missing a closing single quote ('). Make sure all quotes are properly matched.\nExample: echo 'Hello World';",
                        $line_number,
                        $line_number,
                        $line
                    )
                );
            }
            
            // Check for unterminated double-quoted string
            if ($in_double_string) {
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        "Syntax Error: Unterminated string on line %d\n\nLine %d: %s\n\n💡 Help: You're missing a closing double quote (\"). Make sure all quotes are properly matched.\nExample: echo \"Hello World\";",
                        $line_number,
                        $line_number,
                        $line
                    )
                );
            }
        }
        
        return true;
    }

    /**
     * Check for missing semicolons
     */
    private function check_missing_semicolon($line, $line_number, $lines, $current_index) {
        // Skip lines that don't need semicolons
        if (preg_match('/^\s*(if|else|for|while|foreach|function|class|switch|case|default|try|catch|finally|\{|\}|\/\/|\/\*|\*|#)/', $line)) {
            return true;
        }
        
        // Check if line ends with semicolon or closing brace
        if (!preg_match('/[;}]\s*$/', $line) && !empty($line)) {
            // Check if this is a complete statement that needs a semicolon
            // More specific pattern to avoid false positives with function calls
            if (preg_match('/^\s*(echo|print|return|\$\w+\s*=)/i', $line)) {
                // Look ahead to see if next line exists and starts with a statement
                if (isset($lines[$current_index + 1])) {
                    $next_line = trim($lines[$current_index + 1]);
                    if (!empty($next_line) && !preg_match('/^\s*[\)}]/', $next_line)) {
                        return new WP_Error(
                            'syntax_error',
                            sprintf(
                                "Syntax Error: Missing semicolon on line %d\n\nLine %d: %s\n\n💡 Help: Most PHP statements should end with a semicolon (;).\nCorrect format: %s;",
                                $line_number,
                                $line_number,
                                $line,
                                $line
                            )
                        );
                    }
                }
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
                return new WP_Error(
                    'syntax_error',
                    sprintf(
                        "Syntax Error: Unexpected identifier on line %d\n\nLine %d: %s\n\n💡 Help: You're missing an opening quote before '%s'.\nCorrect format: echo '%s';",
                        $line_number,
                        $line_number,
                        $line,
                        $identifier,
                        $identifier
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
        file_put_contents($temp_file, "<?php\n" . $code);
        
        $output = shell_exec("php -l " . escapeshellarg($temp_file) . " 2>&1");
        unlink($temp_file);
        
        if ($output && strpos($output, 'Parse error') !== false) {
            // Extract error details
            preg_match('/Parse error: (.+?) in .+ on line (\d+)/', $output, $matches);
            $error_message = isset($matches[1]) ? $matches[1] : 'Unknown syntax error';
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
        // Handle function declarations in execution testing
        if (preg_match('/function\s+\w+\s*\(/i', $code)) {
            // For function declarations, we'll skip the execution test
            // since function redeclaration would cause a fatal error
            // Syntax validation already passed
            return true;
        }
        
        ob_start();
        $old_error_reporting = error_reporting(E_ALL);
        
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
            
            // Execute in a controlled environment
            eval($code);
            $output = ob_get_clean();
            error_reporting($old_error_reporting);
            return true;
        } catch (ParseError $e) {
            ob_end_clean();
            error_reporting($old_error_reporting);
            return $this->format_parse_error($e, $code);
        } catch (Error $e) {
            ob_end_clean();
            error_reporting($old_error_reporting);
            return $this->format_php_error($e, $code);
        } catch (Exception $e) {
            ob_end_clean();
            error_reporting($old_error_reporting);
            return new WP_Error(
                'execution_error',
                sprintf(
                    "Runtime Exception on line %d: %s\n\nTip: Check your logic and variable usage.",
                    $e->getLine(),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Format parse error with helpful information
     */
    private function format_parse_error($error, $code) {
        $line_number = $error->getLine();
        $message = $error->getMessage();
        $lines = explode("\n", $code);
        
        // Get the problematic line
        $problem_line = isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
        
        // Provide specific help based on error type
        $help_text = $this->get_error_help($message, $problem_line);
        
        return new WP_Error(
            'parse_error',
            sprintf(
                "Parse Error on line %d: %s\n\nLine %d: %s\n\n%s",
                $line_number,
                $message,
                $line_number,
                $problem_line,
                $help_text
            )
        );
    }

    /**
     * Format PHP error with helpful information
     */
    private function format_php_error($error, $code) {
        $line_number = $error->getLine();
        $message = $error->getMessage();
        $lines = explode("\n", $code);
        
        $problem_line = isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
        
        return new WP_Error(
            'php_error',
            sprintf(
                "PHP Error on line %d: %s\n\nLine %d: %s\n\nTip: Check variable names, function calls, and syntax on this line.",
                $line_number,
                $message,
                $line_number,
                $problem_line
            )
        );
    }

    /**
     * Format syntax error from php -l
     */
    private function format_syntax_error($error_message, $line_number, $code) {
        $lines = explode("\n", $code);
        $problem_line = isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
        
        $help_text = $this->get_error_help($error_message, $problem_line);
        
        return new WP_Error(
            'syntax_error',
            sprintf(
                "Syntax Error on line %d: %s\n\nLine %d: %s\n\n%s",
                $line_number,
                $error_message,
                $line_number,
                $problem_line,
                $help_text
            )
        );
    }

    /**
     * Get helpful error suggestions
     */
    private function get_error_help($error_message, $problem_line) {
        $error_lower = strtolower($error_message);
        $line_lower = strtolower($problem_line);
        
        if (strpos($error_lower, 'unexpected') !== false && strpos($error_lower, 'expecting') !== false) {
            if (strpos($error_lower, 'expecting ";"') !== false || strpos($error_lower, "expecting ','") !== false) {
                return "💡 Help: You're missing a semicolon (;) at the end of this line.\nEvery PHP statement should end with a semicolon.";
            }
            if (strpos($error_lower, 'unexpected \'"\'') !== false || strpos($error_lower, "unexpected \"'\"") !== false) {
                return "💡 Help: You have unmatched quotes. Make sure every opening quote has a closing quote.\nExample: echo 'Hello World';";
            }
            if (strpos($error_lower, "unexpected '\$'") !== false) {
                return "💡 Help: There's an issue with a variable. Check that variable names start with \$ and are properly formatted.\nExample: \$my_variable = 'value';";
            }
        }
        
        if (strpos($error_lower, 'unterminated') !== false) {
            return "💡 Help: You have an unterminated string. Make sure all quotes are properly closed.\nExample: echo 'Hello World'; (not echo 'Hello World)";
        }
        
        if (strpos($line_lower, 'echo') !== false && strpos($problem_line, ';') === false) {
            return "💡 Help: Your echo statement is missing a semicolon (;) at the end.\nCorrect format: echo 'Your text here';";
        }
        
        if (strpos($error_lower, 'undefined') !== false) {
            return "💡 Help: You're using an undefined variable or function. Make sure it's declared before use.\nVariables should start with \$ like: \$my_var = 'value';";
        }
        
        return "💡 Help: Double-check your syntax, quotes, semicolons, and variable names.\nEach statement should end with a semicolon, and all quotes should be properly matched.";
    }

    /**
     * Format multiple errors into a comprehensive error message
     */
    private function format_multiple_errors($errors) {
        $total_errors = count($errors);
        $message = "Found $total_errors syntax error" . ($total_errors > 1 ? 's' : '') . " in your PHP code:\n\n";
        
        foreach ($errors as $index => $error) {
            $error_num = $index + 1;
            $line_num = $error['line'];
            $type = $error['type'];
            
            $message .= "🚫 Error #$error_num (Line $line_num):\n";
            $message .= $error['message'] . "\n";
            
            if ($index < count($errors) - 1) {
                $message .= "\n" . str_repeat("-", 50) . "\n\n";
            }
        }
        
        $message .= "\n💡 Fix these errors one by one, starting from the top. Each error might affect the detection of others.";
        
        return $message;
    }
}

// Initialize executor
Nexter_Builder_Code_Snippets_Executor::get_instance();
