<?php
/**
 * Page-Specific Code Handler
 * Handles content-based code snippet execution for posts, pages, and archives
 * 
 * @since 1.0.0
 * @package Nexter Extensions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Nexter_Page_Specific_Code_Handler {
    
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
     * Get page-specific location to hook mappings
     * Using WordPress core hooks for better compatibility with live sites
     */
    public static function get_page_specific_location_hooks() {
        return [
            // Page-Specific - Page, Post, Custom Post Type
            'insert_before_post' => 'the_post', // Hook into the_post action for proper post element insertion
            'insert_after_post' => 'the_content', // Hook into the_content filter for proper post element insertion
            'insert_before_content' => 'the_content',
            'insert_after_content' => 'the_content',
            
            // Paragraph-level insertions (handled specially through content filter)
            'insert_before_paragraph' => 'the_content',
            'insert_after_paragraph' => 'the_content',
            
            // Archive-specific locations - use template_redirect for better timing
            'insert_before_excerpt' => 'template_redirect',
            'insert_after_excerpt' => 'template_redirect',
            'between_posts' => 'template_redirect',
            'before_post' => 'template_redirect',
            'after_post' => 'template_redirect',
        ];
    }

    /**
     * Get advanced content insertion locations
     */
    public static function get_advanced_content_locations() {
        return [
            'insert_after_words',
            'insert_every_words', 
            'insert_middle_content',
            'insert_after_25',
            'insert_after_33', 
            'insert_after_66',
            'insert_after_75',
            'insert_after_80'
        ];
    }

    /**
     * Check if location is page-specific
     */
    public static function is_page_specific_location($location) {
        $page_locations = array_keys(self::get_page_specific_location_hooks());
        $advanced_locations = self::get_advanced_content_locations();
        $archive_locations = self::is_archive_based_location($location) ? [$location] : [];
        return in_array($location, array_merge($page_locations, $advanced_locations, $archive_locations));
    }

    /**
     * Check if location is advanced content location
     */
    public static function is_advanced_content_location($location) {
        return in_array($location, self::get_advanced_content_locations());
    }

    /**
     * Check if location is content-based (uses the_content filter)
     */
    public static function is_content_based_location($location) {
        $content_locations = [
            'insert_before_content',
            'insert_after_content', 
            'insert_before_paragraph',
            'insert_after_paragraph'
        ];
        return in_array($location, $content_locations);
    }

    /**
     * Check if location is excerpt-based (uses get_the_excerpt filter) - DEPRECATED
     * These are now handled as archive-based locations
     * @deprecated No longer used - excerpt locations moved to archive-based
     */
    public static function is_excerpt_based_location($location) {
        // All excerpt locations are now archive-based
        return false;
    }

    /**
     * Check if location is post-based (uses the_post action) - FOR SINGULAR PAGES ONLY
     * Archive post locations are now handled as archive-based
     */
    public static function is_post_based_location($location) {
        $post_locations = [
            'insert_before_post',  // Singular page "insert before post"
            'insert_after_post'    // Singular page "insert after post"
        ];
        return in_array($location, $post_locations);
    }

    /**
     * Check if location is archive-based (requires archive pages)
     */
    public static function is_archive_based_location($location) {
        $archive_locations = array(
            'insert_before_excerpt',
            'insert_after_excerpt',
            'between_posts',
            'before_post',
            'before_x_post',
            'after_x_post'
        );
        
        return in_array($location, $archive_locations);
    }

    /**
     * Handle content insertion based on location
     */
    private static function handle_content_insertion($content, $insert_content, $location, $snippet_id) {
        if (self::is_advanced_content_location($location)) {
            return self::insert_content_at_advanced_location($content, $insert_content, $location, $snippet_id);
        }
        $html_data = $insert_content;
        if(!is_numeric($snippet_id)) {
            if (is_array($html_data) && isset($html_data['file_path']) && file_exists( $html_data['file_path'] ) ) {
                ob_start();
                // Use safe file execution method
                if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                    Nexter_Code_Snippets_File_Based::safe_include_file( $html_data['file_path'] );
                } else {
                    // Fallback: basic validation
                    $file_path = wp_normalize_path( $html_data['file_path'] );
                    $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                    if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                        require_once $file_path;
                    }
                }
                $insert_content = ob_get_clean();
            }
        }
        
        switch ($location) {
            case 'insert_before_content':
                return $insert_content . $content;
                
            case 'insert_after_content':
            case 'insert_after_post':
                return $content . $insert_content;
                
            case 'insert_before_paragraph':
                return self::insert_before_first_paragraph($content, $insert_content);
                
            case 'insert_after_paragraph':
                return self::insert_after_first_paragraph($content, $insert_content);
                
            default:
                return $content;
        }
    }

    /**
     * Get enqueue hook for page-specific locations
     */
    public static function get_page_specific_enqueue_hook($location) {
        $frontend_header_locations = array(
            'before_content', 'insert_before_content', 'insert_before_post', 
            'insert_before_excerpt', 'insert_before_paragraph', 'before_post',
            // Advanced content insertion locations
            'insert_after_words', 'insert_every_words', 'insert_middle_content', 
            'insert_after_25', 'insert_after_33', 'insert_after_66', 'insert_after_75', 'insert_after_80'
        );
        $frontend_footer_locations = array(
            'after_content', 'insert_after_content', 'insert_after_post', 
            'insert_after_excerpt', 'insert_after_paragraph', 'after_post'
        );
        
        // Archive-based locations (between_posts) should use wp_head for CSS/JS
        $archive_header_locations = array('between_posts');

        if (in_array($location, $frontend_header_locations) || in_array($location, $archive_header_locations)) {
            return 'wp_head';
        } elseif (in_array($location, $frontend_footer_locations)) {
            return 'wp_footer';
        }

        return 'wp_head'; // Default
    }

    /**
     * Execute PHP code for page-specific locations
     */
    public static function execute_page_specific_php($snippet_id, $code, $location) {
        if (!self::is_page_specific_location($location)) {
            return false;
        }

        // Get priority from post meta, default to appropriate values
        $hook_priority = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-hooks-priority', true) : (is_array($code) && isset($code['hooks_priority']) ? $code['hooks_priority'] : 10);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Handle content-based insertions through content filter
        if (self::is_content_based_location($location) || 
            self::is_advanced_content_location($location)) {
            
            add_filter('the_content', function($content) use ($code, $snippet_id, $location) {
                // Only process on singular pages and in the main query
                if (!is_singular() || !in_the_loop() || !is_main_query()) {
                    return $content;
                }

                $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                if ($is_active != '1') {
                    return $content;
                }

                // Check PHP execution permission
                $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                if ($code_hidden_execute !== 'yes') {
                    return $content;
                }

                // Check schedule restrictions before executing
                if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                    return $content;
                }
                
                // Execute PHP and capture output
                ob_start();
                if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                    Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                }
                $insert_content = ob_get_clean();
                
                return self::handle_content_insertion($content, $insert_content, $location, $snippet_id);
            }, $priority);
            return true;
        }

        // Handle archive-based insertions (excerpts, between posts, before/after posts)
        if (self::is_archive_based_location($location)) {
            
            // Handle excerpt-based insertions on archive pages
            if (in_array($location, ['insert_before_excerpt', 'insert_after_excerpt'])) {
                add_filter('the_excerpt', function($excerpt) use ($code, $snippet_id, $location) {
                    // Only on archive or home pages
                    if (!is_archive() && !is_home()) {
                        return $excerpt;
                    }

                    $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                    if ($is_active != '1') {
                        return $excerpt;
                    }

                    // Check PHP execution permission
                    $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                    if ($code_hidden_execute !== 'yes') {
                        return $excerpt;
                    }

                    // Check schedule restrictions before executing
                    if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                        return $excerpt;
                    }

                    // Execute PHP and capture output
                    ob_start();
                    if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                        Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                    }
                    $insert_content = ob_get_clean();

                    if ($location === 'insert_before_excerpt') {
                        return $insert_content . $excerpt;
                    } elseif ($location === 'insert_after_excerpt') {
                        return $excerpt . $insert_content;
                    }

                    return $excerpt;
                }, $priority);
                return true;
            }

            // Handle loop-based insertions
            if (in_array($location, ['between_posts', 'before_post', 'before_x_post', 'after_x_post'])) {
                
                // Handle "before_post" - Execute before each post in loop
                if ($location === 'before_post') {
                    add_action('the_post', function($post_object, $query) use ($code, $snippet_id, $location) {
                        // Only on frontend archive or home pages
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        // Check if snippet is active
                        $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                        if ($is_active != '1') {
                            return;
                        }

                        // Check PHP execution permission
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute !== 'yes') {
                            return;
                        }

                        // Check schedule restrictions before executing
                        if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                            return;
                        }

                        // Execute PHP before each post
                        if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                            Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                        }
                    }, $priority, 2);
                }
                
                // Handle "before_x_post" - Execute before a specific post number
                elseif ($location === 'before_x_post') {
                    add_action('the_post', function($post_object, $query) use ($code, $snippet_id, $location) {
                        // Only on frontend archive or home pages
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        // Check if snippet is active
                        $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                        if ($is_active != '1') {
                            return;
                        }

                        // Check PHP execution permission
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute !== 'yes') {
                            return;
                        }

                        // Check schedule restrictions before executing
                        if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                            return;
                        }

                        // Get the target post number (1-indexed)
                        $target_post_number = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-post-number', true) : (is_array($code) && isset($code['post_number']) ? $code['post_number'] : 1);
                        $current_post_index = $query->current_post + 1; // Convert to 1-indexed

                        // Execute before the specific post number
                        if ($current_post_index == $target_post_number) {
                            if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                                Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                            }
                        }
                    }, $priority, 2);
                }
                
                // Handle "after_x_post" - Execute after a specific post number
                elseif ($location === 'after_x_post') {
                    // Use a static variable to track execution
                    static $executed_after_x_post = [];
                    
                    add_action('the_post', function($post_object, $query) use ($code, $snippet_id, $location, &$executed_after_x_post) {
                        // Only on frontend archive or home pages
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        // Check if snippet is active
                        $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                        if ($is_active != '1') {
                            return;
                        }

                        // Check PHP execution permission
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute !== 'yes') {
                            return;
                        }

                        // Check schedule restrictions before executing
                        if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                            return;
                        }

                        // Get the target post number (1-indexed)
                        $target_post_number = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-post-number', true) : (is_array($code) && isset($code['post_number']) ? $code['post_number'] : 1);
                        $current_post_index = $query->current_post + 1; // Convert to 1-indexed

                        // Execute after the specific post number (when next post is being processed)
                        if ($current_post_index == $target_post_number + 1) {
                            // Use a unique key to prevent multiple executions
                            $execution_key = $snippet_id . '_' . $target_post_number;
                            
                            if (!isset($executed_after_x_post[$execution_key])) {
                                $executed_after_x_post[$execution_key] = true;
                                
                                // Execute after the target post (during next post setup)
                                if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                                    Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                                }
                            }
                        }
                        // Handle edge case: if target post is the last post in the loop
                        elseif ($current_post_index == $target_post_number && ($query->current_post + 1) >= $query->post_count) {
                            // Use a unique key to prevent multiple executions
                            $execution_key = $snippet_id . '_' . $target_post_number . '_last';
                            
                            if (!isset($executed_after_x_post[$execution_key])) {
                                $executed_after_x_post[$execution_key] = true;
                                
                                // Use loop_end hook to execute after the last post
                                add_action('loop_end', function() use ($code, $snippet_id) {
                                    if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                                        Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                                    }
                                }, 10);
                            }
                        }
                    }, $priority + 1, 2); // Higher priority to run after post setup
                }
                
                // Handle "between_posts" - Execute between posts in loop
                elseif ($location === 'between_posts') {
                    add_action('the_post', function($post_object, $query) use ($code, $snippet_id, $location) {
                        // Only on frontend archive or home pages
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        // Check if snippet is active
                        $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                        if ($is_active != '1') {
                            return;
                        }

                        // Check PHP execution permission
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute !== 'yes') {
                            return;
                        }

                        // Check schedule restrictions before executing
                        if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                            return;
                        }

                        // If the current post is the first one in the list, skip
                        if ($query->current_post < 1) {
                            return;
                        }

                        // Execute PHP between posts
                        if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                            Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                        }
                    }, $priority, 2);
                }
                
                return true;
            }
        }
       
        // Handle post-based insertions (before/after post on singular pages)
        if (self::is_post_based_location($location)) {
            if ($location === 'insert_before_post') {
                add_action('the_post', function($post_object) use ($code, $snippet_id) {
                    // Only on singular pages and in main query for the current post
                    if (!is_singular() || !in_the_loop() || !is_main_query()) {
                        return;
                    }

                    // Ensure we're processing the correct post
                    if (get_the_ID() !== $post_object->ID) {
                        return;
                    }

                    $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                    if ($is_active != '1') {
                        return;
                    }

                    // Check PHP execution permission
                    $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                    if ($code_hidden_execute !== 'yes') {
                        return;
                    }

                    // Check schedule restrictions before executing
                    if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                        return;
                    }

                    // Execute PHP before post
                    if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                        Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                    }
                }, $priority);
                return true;
            } elseif ($location === 'insert_after_post') {
                add_filter('the_content', function($content) use ($code, $snippet_id) {
                    if (is_singular()) {
                        $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                        if ($is_active != '1') {
                            return $content;
                        }

                        // Check PHP execution permission
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute !== 'yes') {
                            return $content;
                        }

                        // Check schedule restrictions before executing
                        if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                            return $content;
                        }

                        if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                            ob_start();
                            Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id);
                            $php_output = ob_get_clean();
                            return $content . $php_output;
                        }
                    }
                    return $content;
                }, $priority);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if snippet should execute
     */
    private static function should_execute_snippet($snippet_id) {
        $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
        if ($is_active != '1') {
            return false;
        }

        // Check author permissions (security)
        $authorID = get_post_field('post_author', $snippet_id);
        $theAuthorDataRoles = get_userdata($authorID);
        $theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
        
        if (!in_array('administrator', $theRolesAuthor)) {
            return false;
        }

        // Check PHP execution permission
        $code_hidden_execute = get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true);
        return ($code_hidden_execute === 'yes');
    }

    /**
     * Execute PHP safely
     */
    private static function execute_php_safely($snippet_id, $code) {
        if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
            $result = Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id, false);
            if (is_wp_error($result)) {
                // Log the error if WP_DEBUG is enabled
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Nexter Extension] PHP snippet execution error in page-specific location: ' . $result->get_error_message());
                }
            }
        } else {
            // Fallback to direct execution if executor class not available
            $code = html_entity_decode(htmlspecialchars_decode($code));
            eval($code);
        }
    }

    /**
     * Insert content into post content
     */
    private static function insert_into_content($content, $insert_content, $location) {
        switch ($location) {
            case 'insert_before_content':
                return $insert_content . $content;
            case 'insert_after_content':
                return $content . $insert_content;
            case 'insert_before_paragraph':
                return self::insert_before_first_paragraph($content, $insert_content);
            case 'insert_after_paragraph':
                return self::insert_after_first_paragraph($content, $insert_content);
            default:
                return $content;
        }
    }

    /**
     * Register loop-based hooks
     */
    private static function register_loop_based_hooks($snippet_id, $code, $location, $priority) {
        static $post_counter = 0;
        
        switch ($location) {
            case 'between_posts':
                add_action('the_post', function() use ($snippet_id, $code, &$post_counter) {
                    if ($post_counter > 0 && self::should_execute_snippet($snippet_id)) {
                        echo "<!-- Nexter: Between Posts -->\n";
                        self::execute_php_safely($snippet_id, $code);
                    }
                    $post_counter++;
                }, $priority);
                break;
                
            case 'before_post':
                add_action('the_post', function() use ($snippet_id, $code) {
                    if (self::should_execute_snippet($snippet_id)) {
                        echo "<!-- Nexter: Before Post in Loop -->\n";
                        self::execute_php_safely($snippet_id, $code);
                    }
                }, $priority - 1);
                break;
                
            case 'after_post':
                add_action('the_post', function() use ($snippet_id, $code) {
                    if (self::should_execute_snippet($snippet_id)) {
                        echo "<!-- Nexter: After Post in Loop -->\n";
                        self::execute_php_safely($snippet_id, $code);
                    }
                }, $priority + 1);
                break;
        }
    }

    /**
     * Enqueue CSS for page-specific locations
     */
    public static function enqueue_page_specific_css($snippet_id, $css, $location) {
        if (!self::is_page_specific_location($location)) {
            return false;
        }

        // Get priority from post meta, default to appropriate values
        $hook_priority = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-hooks-priority', true) : (is_array($css) && isset($css['hooksPriority']) ? $css['hooksPriority'] : 10);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;
        $filter_priority = !empty($hook_priority) ? intval($hook_priority) : 9; // For content filters

        // For content-based locations, inject CSS directly into content
        if (self::is_content_based_location($location) || 
            self::is_advanced_content_location($location)) {
            
            add_filter('the_content', function($content) use ($css, $snippet_id, $location) {
                // Only process on singular pages and in the main query
                if (!is_singular() || !in_the_loop() || !is_main_query()) {
                    return $content;
                }

                $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($css) && isset($css['status']) ? $css['status'] : 0);
                if ($is_active != '1') {
                    return $content;
                }

                if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($css)){
                    $file_based = new Nexter_Code_Snippets_File_Based();
                    $file_path = isset($css['file_path']) ? $css['file_path'] : '';
                    $css = $file_based->parseBlock(file_get_contents($file_path), true);
                }else{
                    $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                    if ($compress) {
                        $css = self::compress_css($css);
                    }
                }

                // Wrap CSS in style tags for content injection
                $css_wrapped = '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';
                
                return self::handle_content_insertion($content, $css_wrapped, $location, $snippet_id);
            }, $priority);
            return true;
        }

        // For archive-based locations, inject CSS based on location
        if (self::is_archive_based_location($location)) {
            
            // Handle excerpt-based CSS insertions on archive pages
            if (in_array($location, ['insert_before_excerpt', 'insert_after_excerpt'])) {
                add_filter('the_excerpt', function($excerpt) use ($css, $snippet_id, $location) {
                    // Only on archive or home pages
                    if (!is_archive() && !is_home()) {
                        return $excerpt;
                    }

                    $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($css) && isset($css['status']) ? $css['status'] : 0);
                    if ($is_active != '1') {
                        return $excerpt;
                    }
                    if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($css)){
                        $file_based = new Nexter_Code_Snippets_File_Based();
                        $file_path = isset($css['file_path']) ? $css['file_path'] : '';
                        $css = $file_based->parseBlock(file_get_contents($file_path), true);
                    }else{
                        $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                        if ($compress) {
                            $css = self::compress_css($css);
                        }
                    }

                    // Wrap CSS in style tags for excerpt injection
                    $css_wrapped = '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';

                    if ($location === 'insert_before_excerpt') {
                        return $css_wrapped . $excerpt;
                    } elseif ($location === 'insert_after_excerpt') {
                        return $excerpt . $css_wrapped;
                    }

                    return $excerpt;
                }, $priority);
                return true;
            }

            // For other archive locations (between_posts, before_post, etc.), use header enqueue
            // as these locations don't have specific content to inject into
            $hook = self::get_page_specific_enqueue_hook($location);
            add_action($hook, function() use ($css, $snippet_id, $location) {
                // For archive-based locations, only enqueue on archive/home pages
                if (self::is_archive_based_location($location) && !is_archive() && !is_home()) {
                    return;
                }

                $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($css) && isset($css['status']) ? $css['status'] : 0);
                if ($is_active != '1') {
                    return;
                }
                if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($css)){
                    $file_based = new Nexter_Code_Snippets_File_Based();
                    $file_path = isset($css['file_path']) ? $css['file_path'] : '';
                    $css = $file_based->parseBlock(file_get_contents($file_path), true);
                }else{
                    $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                    if ($compress) {
                        $css = self::compress_css($css);
                    }
                }

                echo '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';
            }, $priority);
            return true;
        }

        // For other page-specific locations, use the appropriate enqueue hook
        $hook = self::get_page_specific_enqueue_hook($location);
        add_action($hook, function() use ($css, $snippet_id, $location) {
            $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($css) && isset($css['status']) ? $css['status'] : 0);
            if ($is_active != '1') {
                return;
            }
            if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($css)){
                $file_based = new Nexter_Code_Snippets_File_Based();
                $file_path = isset($css['file_path']) ? $css['file_path'] : '';
                $css = $file_based->parseBlock(file_get_contents($file_path), true);
            }else{
                $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                if ($compress) {
                    $css = self::compress_css($css);
                }
            }

            echo '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';
        }, $priority);

        return true;
    }

    
    /**
     * Enqueue JavaScript for page-specific locations
     */
    public static function enqueue_page_specific_js($snippet_id, $js, $location) {
        if (!self::is_page_specific_location($location)) {
            return false;
        }

        // Cache basic meta / flags
        $is_numeric_id = is_numeric($snippet_id);

        $priority = $is_numeric_id
            ? (intval(get_post_meta($snippet_id, 'nxt-code-hooks-priority', true)) ?: 10)
            : (intval(is_array($js) && isset($js['hooksPriority']) ? $js['hooksPriority'] : 10) ?: 10);

        /**
         * Helper: check if snippet is active
         */
        $is_active_snippet = function() use ($snippet_id, $js, $is_numeric_id) {
            if ($is_numeric_id) {
                $status = get_post_meta($snippet_id, 'nxt-code-status', true);
            } else {
                $status = (is_array($js) && isset($js['status'])) ? $js['status'] : 0;
            }

            return ($status === '1' || $status === 1);
        };

        /**
         * Helper: prepare JS code once (file-based or inline + compression).
         * Uses lazy initialization to avoid unnecessary file reads.
         */
        $prepared_js = null;

        $prepare_js = function() use (&$prepared_js, $snippet_id, $js, $is_numeric_id) {
            if ($prepared_js !== null) {
                return $prepared_js;
            }

            $code = '';

            // File-based snippets (fallback / file mode)
            if (class_exists('Nexter_Code_Snippets_File_Based') && is_array($js)) {
                $file_path = isset($js['file_path']) ? $js['file_path'] : '';

                if (!empty($file_path) && is_readable($file_path)) {
                    $file_based = new Nexter_Code_Snippets_File_Based();
                    $file_contents = file_get_contents($file_path);

                    if ($file_contents !== false) {
                        $code = $file_based->parseBlock($file_contents, true);
                    }
                }

            // Normal DB-based string JS
            } elseif (is_string($js) && $js !== '') {
                $code = $js;

                // Compression flag from post meta
                if ($is_numeric_id) {
                    $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                    if (!empty($compress)) {
                        $code = self::compress_js($code);
                    }
                }
            }

            $prepared_js = $code;
            return $prepared_js;
        };

        /**
         * Helper: get <script> tag wrapper (uses prepared JS).
         */
        $get_script_tag = function() use ($prepare_js, $snippet_id) {
            $code = $prepare_js();

            if ($code === '' || $code === null) {
                return '';
            }

            return '<script id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $code . '</script>';
        };

        /*
        * CONTENT-BASED LOCATIONS
        * -----------------------
        * Insert inside post content (single).
        */
        if (self::is_content_based_location($location) || self::is_advanced_content_location($location)) {

            add_filter('the_content', function($content) use ($snippet_id, $location, $is_active_snippet, $get_script_tag) {
                // Only process on singular pages and in the main query
                if (!is_singular() || !in_the_loop() || !is_main_query()) {
                    return $content;
                }

                if (!$is_active_snippet()) {
                    return $content;
                }

                $js_wrapped = $get_script_tag();
                if ($js_wrapped === '') {
                    return $content;
                }

                return self::handle_content_insertion($content, $js_wrapped, $location, $snippet_id);
            }, $priority);

            return true;
        }

        /*
        * ARCHIVE-BASED LOCATIONS
        * -----------------------
        * Handle excerpts / loop locations.
        */
        if (self::is_archive_based_location($location)) {

            // Excerpt-based JS insertion
            if (in_array($location, array('insert_before_excerpt', 'insert_after_excerpt'), true)) {

                add_filter('the_excerpt', function($excerpt) use ($location, $is_active_snippet, $get_script_tag) {
                    // Only on archive or home pages
                    if (!is_archive() && !is_home()) {
                        return $excerpt;
                    }

                    if (!$is_active_snippet()) {
                        return $excerpt;
                    }

                    $js_wrapped = $get_script_tag();
                    if ($js_wrapped === '') {
                        return $excerpt;
                    }

                    if ($location === 'insert_before_excerpt') {
                        return $js_wrapped . $excerpt;
                    } elseif ($location === 'insert_after_excerpt') {
                        return $excerpt . $js_wrapped;
                    }

                    return $excerpt;
                }, $priority);

                return true;
            }

            // Loop-based archive locations
            if (in_array($location, array('between_posts', 'before_post', 'before_x_post', 'after_x_post'), true)) {

                // before_post -> before every post in loop
                if ($location === 'before_post') {

                    add_action('the_post', function($post_object, $query) use ($is_active_snippet, $get_script_tag) {
                        // Only on frontend archive or home pages
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        if (!$is_active_snippet()) {
                            return;
                        }

                        $js_wrapped = $get_script_tag();
                        if ($js_wrapped === '') {
                            return;
                        }

                        echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }, $priority, 2);
                }

                // before_x_post -> before specific post number
                elseif ($location === 'before_x_post') {

                    add_action('the_post', function($post_object, $query) use ($snippet_id, $js, $is_numeric_id, $is_active_snippet, $get_script_tag) {
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        if (!$is_active_snippet()) {
                            return;
                        }

                        // Target post number (1-indexed)
                        $target_post_number = $is_numeric_id
                            ? get_post_meta($snippet_id, 'nxt-post-number', true)
                            : (is_array($js) && isset($js['post_number']) ? $js['post_number'] : 1);

                        $target_post_number = intval($target_post_number) ?: 1;

                        $current_post_index = $query->current_post + 1; // 1-indexed

                        if ($current_post_index === $target_post_number) {
                            $js_wrapped = $get_script_tag();
                            if ($js_wrapped === '') {
                                return;
                            }

                            echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        }
                    }, $priority, 2);
                }

                // after_x_post -> after specific post number
                elseif ($location === 'after_x_post') {
                    // Track if already executed for snippet+position
                    static $executed_after_x_post_js = array();

                    add_action('the_post', function($post_object, $query) use ($snippet_id, $js, $is_numeric_id, $is_active_snippet, $get_script_tag, &$executed_after_x_post_js) {

                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        if (!$is_active_snippet()) {
                            return;
                        }

                        // Target post number (1-indexed)
                        $target_post_number = $is_numeric_id
                            ? get_post_meta($snippet_id, 'nxt-post-number', true)
                            : (is_array($js) && isset($js['post_number']) ? $js['post_number'] : 1);

                        $target_post_number = intval($target_post_number) ?: 1;

                        $current_post_index = $query->current_post + 1; // 1-indexed

                        // When next post is being processed
                        if ($current_post_index === $target_post_number + 1) {
                            $execution_key = $snippet_id . '_' . $target_post_number;

                            if (!isset($executed_after_x_post_js[$execution_key])) {
                                $executed_after_x_post_js[$execution_key] = true;

                                $js_wrapped = $get_script_tag();
                                if ($js_wrapped === '') {
                                    return;
                                }

                                echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                        }
                        // Edge case: target is last post
                        elseif ($current_post_index === $target_post_number && ($query->current_post + 1) >= $query->post_count) {
                            $execution_key = $snippet_id . '_' . $target_post_number . '_last';

                            if (!isset($executed_after_x_post_js[$execution_key])) {
                                $executed_after_x_post_js[$execution_key] = true;

                                $js_wrapped = $get_script_tag();
                                if ($js_wrapped === '') {
                                    return;
                                }

                                add_action('loop_end', function() use ($js_wrapped, $snippet_id) {
                                    echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                }, 10);
                            }
                        }
                    }, $priority + 1, 2); // Slightly later than default
                }

                // between_posts -> between each post in loop (except before first)
                elseif ($location === 'between_posts') {

                    add_action('the_post', function($post_object, $query) use ($is_active_snippet, $get_script_tag) {
                        if (is_admin() || (!is_archive() && !is_home())) {
                            return;
                        }

                        if (!$is_active_snippet()) {
                            return;
                        }

                        // Skip first post
                        if ($query->current_post < 1) {
                            return;
                        }

                        $js_wrapped = $get_script_tag();
                        if ($js_wrapped === '') {
                            return;
                        }

                        echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }, $priority, 2);
                }

                return true;
            }

            // Other archive locations use a normal enqueue hook (header/footer etc.)
            $hook = self::get_page_specific_enqueue_hook($location);
            if (empty($hook)) {
                return false;
            }

            add_action($hook, function() use ($location, $is_active_snippet, $get_script_tag) {
                if (self::is_archive_based_location($location) && !is_archive() && !is_home()) {
                    return;
                }

                if (!$is_active_snippet()) {
                    return;
                }

                $js_wrapped = $get_script_tag();
                if ($js_wrapped === '') {
                    return;
                }

                echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }, $priority);

            return true;
        }

        /*
        * OTHER PAGE-SPECIFIC LOCATIONS (non-archive, non-content)
        * --------------------------------------------------------
        */
        $hook = self::get_page_specific_enqueue_hook($location);
        if (empty($hook)) {
            return false;
        }

        add_action($hook, function() use ($is_active_snippet, $get_script_tag) {
            if (!$is_active_snippet()) {
                return;
            }

            $js_wrapped = $get_script_tag();
            if ($js_wrapped === '') {
                return;
            }

            echo $js_wrapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }, $priority);

        return true;
    }


    /**
     * Output HTML for page-specific locations
     *
     * @param int|mixed  $snippet_id
     * @param mixed      $html
     * @param string     $location
     *
     * @return bool
     */
    public static function output_page_specific_html( $snippet_id, $html, $location ) {
        if ( ! self::is_page_specific_location( $location ) ) {
            return false;
        }

        $is_numeric_id = is_numeric( $snippet_id );

        // Helper: get active flag for this snippet
        $is_snippet_active = function () use ( $snippet_id, $html, $is_numeric_id ) {
            if ( $is_numeric_id ) {
                $status = get_post_meta( $snippet_id, 'nxt-code-status', true );
                return ( '1' === $status );
            }

            if ( is_array( $html ) && isset( $html['status'] ) ) {
                return ( '1' === (string) $html['status'] );
            }

            return false;
        };

        // Helper: resolve HTML output (file-based or direct)
        $resolve_html = function () use ( $html ) {
            // If array, try to load from file_path
            if ( is_array( $html ) ) {
                $file_data = $html;

                if ( ! empty( $file_data['file_path'] ) && file_exists( $file_data['file_path'] ) ) {
                    ob_start();
                    // Use safe file execution method
                    if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                        Nexter_Code_Snippets_File_Based::safe_include_file( $file_data['file_path'] );
                    } else {
                        // Fallback: basic validation
                        $file_path = wp_normalize_path( $file_data['file_path'] );
                        $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                        if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                            require_once $file_path;
                        }
                    }
                    $snippet_html = ob_get_clean();
                    return $snippet_html;
                }

                // Avoid "Array" output, fallback to empty string
                return '';
            }

            // If string, just use as is
            if ( is_string( $html ) ) {
                return $html;
            }

            return '';
        };

        // Helper: get target post number
        $get_target_post_number = function () use ( $snippet_id, $html, $is_numeric_id ) {
            if ( $is_numeric_id ) {
                $post_number = get_post_meta( $snippet_id, 'nxt-post-number', true );
                return $post_number ? (int) $post_number : 1;
            }

            if ( is_array( $html ) && isset( $html['post_number'] ) ) {
                return (int) $html['post_number'];
            }

            return 1;
        };

        // Get priority from post meta, default to 10
        $hook_priority_meta = $is_numeric_id
            ? get_post_meta( $snippet_id, 'nxt-code-hooks-priority', true )
            : ( ( is_array( $html ) && isset( $html['hooksPriority'] ) ) ? $html['hooksPriority'] : 10 );

        $priority = ! empty( $hook_priority_meta ) ? (int) $hook_priority_meta : 10;

        /**
         * 1. Content-based locations (inside post content)
         */
        if ( self::is_content_based_location( $location ) || self::is_advanced_content_location( $location ) ) {

            add_filter(
                'the_content',
                function ( $content ) use ( $html, $snippet_id, $location, $is_snippet_active ) {
                    // Only process on singular main query in the loop
                    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
                        return $content;
                    }

                    if ( ! $is_snippet_active() ) {
                        return $content;
                    }

                    return self::handle_content_insertion( $content, $html, $location, $snippet_id );
                },
                $priority
            );

            return true;
        }

        /**
         * 2. Archive-based insertions (excerpts & loop)
         */
        if ( self::is_archive_based_location( $location ) ) {

            // 2.a Excerpt-based insertions on archive/home
            if ( in_array( $location, array( 'insert_before_excerpt', 'insert_after_excerpt' ), true ) ) {

                add_filter(
                    'the_excerpt',
                    function ( $excerpt ) use ( $html, $location, $is_snippet_active, $resolve_html ) {
                        // Only on archive or home pages
                        if ( ! is_archive() && ! is_home() ) {
                            return $excerpt;
                        }

                        if ( ! $is_snippet_active() ) {
                            return $excerpt;
                        }

                        $snippet_html = $resolve_html();

                        if ( $location === 'insert_before_excerpt' ) {
                            return $snippet_html . $excerpt;
                        }

                        if ( $location === 'insert_after_excerpt' ) {
                            return $excerpt . $snippet_html;
                        }

                        return $excerpt;
                    },
                    $priority
                );

                return true;
            }

            // 2.b Loop-based insertions
            if ( in_array( $location, array( 'between_posts', 'before_post', 'before_x_post', 'after_x_post' ), true ) ) {

                /**
                 * before_post – output before every post in archive/home loop
                 */
                if ( 'before_post' === $location ) {
                    add_action(
                        'the_post',
                        function ( $post_object, $query ) use ( $is_snippet_active, $resolve_html ) {
                            // Only on frontend archive/home main queries
                            if ( is_admin() || ( ! is_archive() && ! is_home() ) ) {
                                return;
                            }

                            if ( ! $is_snippet_active() ) {
                                return;
                            }

                            echo $resolve_html();
                        },
                        $priority,
                        2
                    );
                }

                /**
                 * before_x_post – output before a specific post number
                 */
                elseif ( 'before_x_post' === $location ) {
                    add_action(
                        'the_post',
                        function ( $post_object, $query ) use ( $is_snippet_active, $resolve_html, $get_target_post_number ) {
                            if ( is_admin() || ( ! is_archive() && ! is_home() ) ) {
                                return;
                            }

                            if ( ! $is_snippet_active() ) {
                                return;
                            }

                            $target_post_number = $get_target_post_number();
                            $current_post_index = (int) $query->current_post + 1; // 1-indexed

                            if ( $current_post_index === $target_post_number ) {
                                echo $resolve_html();
                            }
                        },
                        $priority,
                        2
                    );
                }

                /**
                 * after_x_post – output after a specific post number
                 */
                elseif ( 'after_x_post' === $location ) {
                    add_action(
                        'the_post',
                        function ( $post_object, $query ) use ( $snippet_id, $is_snippet_active, $resolve_html, $get_target_post_number ) {
                            if ( is_admin() || ( ! is_archive() && ! is_home() ) ) {
                                return;
                            }

                            if ( ! $is_snippet_active() ) {
                                return;
                            }

                            $target_post_number  = $get_target_post_number();
                            $current_post_index  = (int) $query->current_post + 1; // 1-indexed
                            $post_count          = (int) $query->post_count;

                            // Static per-request cache to avoid multiple executions
                            static $executed_after_x_post_html = array();

                            // Output after the specific post (during next post setup)
                            if ( $current_post_index === $target_post_number + 1 ) {
                                $execution_key = $snippet_id . '_' . $target_post_number;

                                if ( ! isset( $executed_after_x_post_html[ $execution_key ] ) ) {
                                    $executed_after_x_post_html[ $execution_key ] = true;
                                    echo $resolve_html();
                                }
                            }
                            // Edge case: target is the last post in loop
                            elseif ( $current_post_index === $target_post_number && $current_post_index >= $post_count ) {
                                $execution_key = $snippet_id . '_' . $target_post_number . '_last';

                                if ( ! isset( $executed_after_x_post_html[ $execution_key ] ) ) {
                                    $executed_after_x_post_html[ $execution_key ] = true;

                                    add_action(
                                        'loop_end',
                                        function () use ( $resolve_html ) {
                                            echo $resolve_html();
                                        },
                                        10
                                    );
                                }
                            }
                        },
                        $priority + 1,
                        2 // run slightly after default
                    );
                }

                /**
                 * between_posts – output between posts (not before the first)
                 */
                elseif ( 'between_posts' === $location ) {
                    add_action(
                        'the_post',
                        function ( $post_object, $query ) use ( $is_snippet_active, $resolve_html ) {
                            if ( is_admin() || ( ! is_archive() && ! is_home() ) ) {
                                return;
                            }

                            if ( ! $is_snippet_active() ) {
                                return;
                            }

                            // Skip before the first post
                            if ( (int) $query->current_post < 1 ) {
                                return;
                            }

                            echo $resolve_html();
                        },
                        $priority,
                        2
                    );
                }

                return true;
            }
        }

        /**
         * 3. Post-based insertions on singular pages (before/after post)
         */
        if ( self::is_post_based_location( $location ) ) {

            // 3.a insert_before_post
            if ( 'insert_before_post' === $location ) {

                add_action(
                    'the_post',
                    function ( $post_object ) use ( $snippet_id, $is_snippet_active, $resolve_html ) {
                        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
                            return;
                        }

                        if ( ! $is_snippet_active() ) {
                            return;
                        }

                        // Schedule restriction
                        if ( self::should_skip_due_to_schedule_restrictions( $snippet_id ) ) {
                            return;
                        }

                        echo $resolve_html();
                    },
                    $priority
                );

                return true;
            }

            // 3.b insert_after_post
            if ( 'insert_after_post' === $location ) {

                add_filter(
                    'the_content',
                    function ( $content ) use ( $snippet_id, $is_snippet_active, $resolve_html ) {
                        if ( ! is_singular() ) {
                            return $content;
                        }

                        if ( ! $is_snippet_active() ) {
                            return $content;
                        }

                        if ( self::should_skip_due_to_schedule_restrictions( $snippet_id ) ) {
                            return $content;
                        }

                        return $content . $resolve_html();
                    },
                    $priority
                );

                return true;
            }
        }

        return false;
    }


    /**
     * Check if HTML snippet should execute
     */
    private static function should_execute_html_snippet($snippet_id) {
        $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
        return ($is_active == '1');
    }

    /**
     * Register HTML loop-based hooks
     */
    private static function register_html_loop_hooks($snippet_id, $html, $location, $priority) {
        static $post_counter = 0;
        
        switch ($location) {
            case 'between_posts':
                add_action('the_post', function() use ($snippet_id, $html, &$post_counter) {
                    if ($post_counter > 0 && self::should_execute_html_snippet($snippet_id)) {
                        echo "<!-- Nexter HTML: Between Posts -->\n";
                        echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                    }
                    $post_counter++;
                }, $priority);
                break;
                
            case 'before_post':
                add_action('the_post', function() use ($snippet_id, $html) {
                    if (self::should_execute_html_snippet($snippet_id)) {
                        echo "<!-- Nexter HTML: Before Post in Loop -->\n";
                        echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                    }
                }, $priority - 1);
                break;
                
            case 'after_post':
                add_action('the_post', function() use ($snippet_id, $html) {
                    if (self::should_execute_html_snippet($snippet_id)) {
                        echo "<!-- Nexter HTML: After Post in Loop -->\n";
                        echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                    }
                }, $priority + 1);
                break;
        }
    }

    /**
     * Register archive-specific hooks for better live site compatibility
     */
    private static function register_archive_hooks($snippet_id, $html, $location, $priority) {
        switch ($location) {
            case 'insert_before_excerpt':
                add_filter('the_excerpt', function($excerpt) use ($snippet_id, $html) {
                    $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                    if ($is_active == '1') {
                        $insert_content = apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                        return $insert_content . $excerpt;
                    }
                    return $excerpt;
                }, $priority);
                break;
                
            case 'insert_after_excerpt':
                add_filter('the_excerpt', function($excerpt) use ($snippet_id, $html) {
                    $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                    if ($is_active == '1') {
                        $insert_content = apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                        return $excerpt . $insert_content;
                    }
                    return $excerpt;
                }, $priority);
                break;
                
            case 'between_posts':
            case 'before_post':
            case 'after_post':
                // Use loop_start and loop_end for better compatibility
                add_action('loop_start', function() use ($snippet_id, $html, $location, $priority) {
                    self::register_loop_hooks($snippet_id, $html, $location, $priority);
                }, 1);
                break;
        }
    }

    /**
     * Register loop-specific hooks
     */
    private static function register_loop_hooks($snippet_id, $html, $location, $priority) {
        static $post_counter = 0;
        
        switch ($location) {
            case 'between_posts':
                add_action('the_post', function() use ($snippet_id, $html, &$post_counter) {
                    if ($post_counter > 0) { // Not the first post
                        $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                        if ($is_active == '1') {
                            echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                        }
                    }
                    $post_counter++;
                }, $priority);
                break;
                
            case 'before_post':
                add_action('the_post', function() use ($snippet_id, $html) {
                    $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                    if ($is_active == '1') {
                        echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                    }
                }, $priority - 1); // Before the_post
                break;
                
            case 'after_post':
                add_action('the_post', function() use ($snippet_id, $html) {
                    $is_active = get_post_meta($snippet_id, 'nxt-code-status', true);
                    if ($is_active == '1') {
                        echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
                    }
                }, $priority + 1); // After the_post
                break;
        }
    }

    /**
     * Get the appropriate hook for singular post locations
     */
    private static function get_singular_hook_for_location($location) {
        switch ($location) {
            case 'insert_before_post':
                return 'the_post'; // Hook into the_post action for proper post element insertion
            case 'insert_after_post':
                return 'the_content'; // Hook into the_content filter for proper post element insertion
            default:
                return 'wp_head';
        }
    }

    /**
     * Insert content at advanced locations (word/percentage based)
     */
    private static function insert_content_at_advanced_location($content, $insert_content, $location, $snippet_id) {
        // All advanced content locations are Pro features
        // Route them through the Pro plugin filter system
        return apply_filters('nexter_process_pro_content_insertion', $content, $location, $insert_content, $snippet_id);
    }

    /**
     * Insert content at a specific percentage of the total content
     * This is a Pro feature - should only be called by Pro plugin
     */
    /* private static function insert_at_content_percentage($content, $insert_content, $percentage) {
        // This method should only be used by the Pro plugin
        // If we reach here without Pro plugin, return original content
        return $content;
    } */

    /**
     * Insert content after a specific word count
     */
    private static function insert_after_word_count($content, $insert_content, $target_words) {
        // Split content into words while preserving HTML structure
        $words = preg_split('/(\s+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current_word_count = 0;
        $insert_position = 0;
        
        foreach ($words as $index => $word) {
            if (trim($word) !== '' && !preg_match('/<[^>]*>/', $word)) {
                // This is an actual word (not whitespace or HTML tag)
                $word_count_in_segment = str_word_count(strip_tags($word));
                $current_word_count += $word_count_in_segment;
                
                if ($current_word_count >= $target_words) {
                    $insert_position = $index;
                    break;
                }
            }
        }
        
        if ($insert_position > 0) {
            // Insert content at the calculated position
            $before = implode('', array_slice($words, 0, $insert_position));
            $after = implode('', array_slice($words, $insert_position));
            return $before . $insert_content . $after;
        }
        
        // If target word count not reached, append at the end
        return $content . $insert_content;
    }

    /**
     * Insert content at regular word intervals throughout the content
     */
    private static function insert_every_word_count($content, $insert_content, $word_interval) {
        // Split content into words while preserving HTML structure
        $words = preg_split('/(\s+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current_word_count = 0;
        $insertions_made = 0;
        $result_words = [];
        
        foreach ($words as $index => $word) {
            $result_words[] = $word;
            
            if (trim($word) !== '' && !preg_match('/<[^>]*>/', $word)) {
                // This is an actual word (not whitespace or HTML tag)
                $word_count_in_segment = str_word_count(strip_tags($word));
                $current_word_count += $word_count_in_segment;
                
                // Check if we've reached the interval
                if ($current_word_count >= $word_interval * ($insertions_made + 1)) {
                    $result_words[] = $insert_content;
                    $insertions_made++;
                }
            }
        }
        
        return implode('', $result_words);
    }

    /**
     * Insert content before first paragraph
     */
    private static function insert_before_first_paragraph($content, $insert_content) {
        // Look for the first <p> tag
        if (preg_match('/<p\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insert_position = $matches[0][1];
            return substr($content, 0, $insert_position) . $insert_content . substr($content, $insert_position);
        }
        
        // If no paragraph found, prepend to content
        return $insert_content . $content;
    }

    /**
     * Insert content after first paragraph
     */
    private static function insert_after_first_paragraph($content, $insert_content) {
        // Look for the first complete <p>...</p> block
        if (preg_match('/<p\b[^>]*>.*?<\/p>/si', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insert_position = $matches[0][1] + strlen($matches[0][0]);
            return substr($content, 0, $insert_position) . $insert_content . substr($content, $insert_position);
        }
        
        // If no paragraph found, append to content
        return $content . $insert_content;
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
} 