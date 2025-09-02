<?php
/**
 * MemberPress Hook Handler
 * 
 * Handles MemberPress-specific hooks and their execution
 * 
 * @package Nexter_Extension
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Nexter_MemberPress_Hook_Handler
 */
class Nexter_MemberPress_Hook_Handler {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Track executed locations to prevent duplicates
     */
    private static $executed_locations = array();

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize MemberPress hooks
     */
    public function init_hooks() {
        // Only initialize if MemberPress is active
        if (!class_exists('MeprAppCtrl')) {
            return;
        }

        // Prevent multiple initializations
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // Hook into actual MemberPress hooks that exist
        // Only register hooks that are known to exist in MemberPress
        if (has_action('mepr_before_account_subscriptions') !== false || 
            function_exists('MeprAppCtrl') || 
            class_exists('MeprAppCtrl')) {
            add_action('mepr_before_account_subscriptions', array($this, 'before_account_subscriptions'));
        }
        
        // Hook into MemberPress checkout form hooks (if they exist)
        if (has_action('mepr-above-checkout-form') !== false || 
            function_exists('MeprAppCtrl') || 
            class_exists('MeprAppCtrl')) {
            add_action('mepr-above-checkout-form', array($this, 'above_checkout_form'));
        }
        
        if (has_action('mepr-checkout-before-submit') !== false || 
            function_exists('MeprAppCtrl') || 
            class_exists('MeprAppCtrl')) {
            add_action('mepr-checkout-before-submit', array($this, 'before_checkout_submit'));
        }
        
        if (has_action('mepr-checkout-before-coupon-field') !== false || 
            function_exists('MeprAppCtrl') || 
            class_exists('MeprAppCtrl')) {
            add_action('mepr-checkout-before-coupon-field', array($this, 'before_checkout_coupon'));
        }
        
        // Login form hooks
        if (has_action('mepr-login-form-before-submit') !== false || 
            function_exists('MeprAppCtrl') || 
            class_exists('MeprAppCtrl')) {
            add_action('mepr-login-form-before-submit', array($this, 'login_before_submit'));
        }
        
        // Unauthorized message hooks (using filters)
        add_filter('mepr-unauthorized-message', array($this, 'before_unauthorized_message'));
        add_filter('mepr-unauthorized-message', array($this, 'after_unauthorized_message'));

        // Add custom hooks to MemberPress templates where needed
        $this->add_custom_template_hooks();
    }

    /**
     * Add custom hooks to MemberPress templates where MemberPress doesn't provide hooks
     */
    private function add_custom_template_hooks() {
        // Only add custom hooks where MemberPress doesn't provide native hooks
        // These are for locations where we need to inject our own hooks
        
        // Hook into MemberPress login form template to trigger our custom hook
        add_action('mepr_login_form_before_submit_button', array($this, 'trigger_login_before_submit'));
        
        // Add hooks for unauthorized message if MemberPress doesn't provide them
        add_action('mepr_unauthorized_message_before', array($this, 'trigger_unauthorized_message_before'));
        add_action('mepr_unauthorized_message_after', array($this, 'trigger_unauthorized_message_after'));
    }

    /**
     * Output snippets for above checkout form
     */
    public function above_checkout_form() {
        $this->output_location('mepr-above-checkout-form');
    }

    /**
     * Output snippets for before checkout submit
     */
    public function before_checkout_submit() {
        $this->output_location('mepr-checkout-before-submit');
    }

    /**
     * Output snippets for before checkout coupon field
     */
    public function before_checkout_coupon() {
        $this->output_location('mepr-checkout-before-coupon-field');
    }

    /**
     * Output snippets for before account subscriptions
     */
    public function before_account_subscriptions() {
        $this->output_location('mepr_before_account_subscriptions');
    }

    /**
     * Output snippets for login before submit
     */
    public function login_before_submit() {
        $this->output_location('mepr-login-form-before-submit');
    }

    /**
     * Add content before unauthorized message
     */
    public function before_unauthorized_message($message) {
        $before_content = $this->get_location_content('mepr_unauthorized_message_before');
        return $before_content . $message;
    }

    /**
     * Add content after unauthorized message
     */
    public function after_unauthorized_message($message) {
        $after_content = $this->get_location_content('mepr_unauthorized_message_after');
        return $message . $after_content;
    }

    /**
     * Trigger hooks for custom template integration (only where MemberPress doesn't provide hooks)
     */
    public function trigger_login_before_submit() {
        do_action('mepr-login-form-before-submit');
    }

    public function trigger_unauthorized_message_before() {
        do_action('mepr_unauthorized_message_before');
    }

    public function trigger_unauthorized_message_after() {
        do_action('mepr_unauthorized_message_after');
    }

    /**
     * Output snippets for a specific location
     */
    private function output_location($location) {
        // Prevent duplicate execution
        if (in_array($location, self::$executed_locations)) {
            return;
        }

        // Prevent infinite recursion
        static $recursion_depth = 0;
        if ($recursion_depth > 10) {
            error_log('Nexter Extension: Maximum recursion depth exceeded for location: ' . $location);
            return;
        }
        $recursion_depth++;

        // Check memory usage to prevent exhaustion
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        if ($memory_limit !== '-1') {
            $limit_bytes = $this->return_bytes($memory_limit);
            if ($memory_usage > ($limit_bytes * 0.8)) {
                error_log('Nexter Extension: Memory usage too high, skipping snippet execution for location: ' . $location);
                $recursion_depth--;
                return;
            }
        }
        
        // Get snippets from the eCommerce handler
        if (class_exists('Nexter_ECommerce_Code_Handler')) {
            $snippets = Nexter_ECommerce_Code_Handler::get_memberpress_snippets($location);
            
            if (!empty($snippets)) {
                // Mark this location as executed
                self::$executed_locations[] = $location;
                
                foreach ($snippets as $snippet) {
                    if ($snippet['type'] === 'html') {
                        echo $snippet['content'];
                    } elseif ($snippet['type'] === 'php') {
                        if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                            Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($snippet['code'], $snippet['id'], true);
                        }
                    } elseif ($snippet['type'] === 'css') {
                        echo '<style id="nexter-snippet-' . esc_attr($snippet['id']) . '">' . $snippet['content'] . '</style>';
                    } elseif ($snippet['type'] === 'js') {
                        echo '<script id="nexter-snippet-' . esc_attr($snippet['id']) . '">' . $snippet['content'] . '</script>';
                    }
                }
            }
        }
        
        // Decrement recursion depth
        $recursion_depth--;
    }

    /**
     * Get content for a specific location (for filters)
     */
    private function get_location_content($location) {
        $content = '';
        
        // Prevent infinite recursion
        static $recursion_depth = 0;
        if ($recursion_depth > 10) {
            error_log('Nexter Extension: Maximum recursion depth exceeded for location: ' . $location);
            return $content;
        }
        $recursion_depth++;
        
        // Get snippets from the eCommerce handler
        if (class_exists('Nexter_ECommerce_Code_Handler')) {
            $snippets = Nexter_ECommerce_Code_Handler::get_memberpress_snippets($location);
            
            if (!empty($snippets)) {
                foreach ($snippets as $snippet) {
                    if ($snippet['type'] === 'html') {
                        $content .= $snippet['content'];
                    } elseif ($snippet['type'] === 'css') {
                        $content .= '<style id="nexter-snippet-' . esc_attr($snippet['id']) . '">' . $snippet['content'] . '</style>';
                    } elseif ($snippet['type'] === 'js') {
                        $content .= '<script id="nexter-snippet-' . esc_attr($snippet['id']) . '">' . $snippet['content'] . '</script>';
                    }
                }
            }
        }

        // Decrement recursion depth
        $recursion_depth--;
        
        return $content;
    }

    /**
     * Convert memory limit string to bytes
     */
    private function return_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int)$val;
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }
}

// Initialize the MemberPress hook handler after WordPress is fully loaded
add_action('wp_loaded', function() {
    Nexter_MemberPress_Hook_Handler::get_instance();
}, 20); 