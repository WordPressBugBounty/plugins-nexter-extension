<?php
/**
 * eCommerce Code Handler
 * Handles eCommerce-related code snippet execution (WooCommerce, EDD, MemberPress)
 * 
 * @since 1.0.0
 * @package Nexter Extensions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Nexter_ECommerce_Code_Handler {
    
    /**
     * MemberPress snippets storage
     */
    private static $memberpress_snippets = array();
    
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
     * Get eCommerce location to hook mappings
     */
    public static function get_ecommerce_location_hooks() {
        return [
            // eCommerce - WooCommerce
            'wc_before_list_products' => 'woocommerce_before_shop_loop',
            'wc_after_list_products' => 'woocommerce_after_shop_loop',
            'wc_before_single_product' => 'woocommerce_before_single_product',
            'wc_after_single_product' => 'woocommerce_after_single_product',
            'wc_before_single_product_summary' => 'woocommerce_before_single_product_summary',
            'wc_after_single_product_summary' => 'woocommerce_after_single_product_summary',
            'wc_before_cart' => 'woocommerce_before_cart',
            'wc_after_cart' => 'woocommerce_after_cart',
            'wc_before_checkout_form' => 'woocommerce_before_checkout_form',
            'wc_after_checkout_form' => 'woocommerce_after_checkout_form',
            'wc_before_checkout_button' => 'woocommerce_review_order_before_submit',
            'wc_after_checkout_button' => 'woocommerce_review_order_after_submit',
            'wc_before_thank_you_page' => 'woocommerce_before_thankyou',
            
            // Easy Digital Downloads
            'edd_purchase_link_top' => 'edd_purchase_link_top',
            'edd_purchase_link_end' => 'edd_purchase_link_end',
            'edd_before_download_content' => 'edd_before_download_content',
            'edd_after_download_content' => 'edd_after_download_content',
            'edd_before_checkout_cart' => 'edd_before_checkout_cart',
            'edd_after_checkout_cart' => 'edd_after_checkout_cart',
            'edd_before_purchase_form' => 'edd_before_purchase_form',
            'edd_after_purchase_form' => 'edd_after_purchase_form',
            
            // MemberPress
            'mepr-above-checkout-form' => 'mepr-above-checkout-form',
            'mepr-checkout-before-submit' => 'mepr-checkout-before-submit',
            'mepr-checkout-before-coupon-field' => 'mepr-checkout-before-coupon-field',
            'mepr_before_account_subscriptions' => 'mepr_before_account_subscriptions',
            'mepr-login-form-before-submit' => 'mepr-login-form-before-submit',
            'mepr_unauthorized_message_before' => 'mepr_unauthorized_message_before',
            'mepr_unauthorized_message_after' => 'mepr_unauthorized_message_after',
        ];
    }

    /**
     * Get WooCommerce-specific locations
     */
    public static function get_woocommerce_locations() {
        return [
            'wc_before_list_products',
            'wc_after_list_products',
            'wc_before_single_product',
            'wc_after_single_product',
            'wc_before_single_product_summary',
            'wc_after_single_product_summary',
            'wc_before_cart',
            'wc_after_cart',
            'wc_before_checkout_form',
            'wc_after_checkout_form',
            'wc_before_checkout_button',
            'wc_after_checkout_button',
            'wc_before_thank_you_page',
        ];
    }

    /**
     * Get EDD-specific locations
     */
    public static function get_edd_locations() {
        return [
            'edd_purchase_link_top',
            'edd_purchase_link_end',
            'edd_before_download_content',
            'edd_after_download_content',
            'edd_before_checkout_cart',
            'edd_after_checkout_cart',
            'edd_before_purchase_form',
            'edd_after_purchase_form',
        ];
    }

    /**
     * Get MemberPress-specific locations
     */
    public static function get_memberpress_locations() {
        return [
            'mepr-above-checkout-form',
            'mepr-checkout-before-submit',
            'mepr-checkout-before-coupon-field',
            'mepr_before_account_subscriptions',
            'mepr-login-form-before-submit',
            'mepr_unauthorized_message_before',
            'mepr_unauthorized_message_after',
        ];
    }

    /**
     * Check if location is eCommerce-related
     */
    public static function is_ecommerce_location($location) {
        $ecommerce_locations = array_keys(self::get_ecommerce_location_hooks());
        return in_array($location, $ecommerce_locations);
    }

    /**
     * Check if WooCommerce is active and location is WooCommerce-related
     */
    public static function is_woocommerce_location($location) {
        return in_array($location, self::get_woocommerce_locations()) && self::is_woocommerce_active();
    }

    /**
     * Check if EDD is active and location is EDD-related
     */
    public static function is_edd_location($location) {
        return in_array($location, self::get_edd_locations()) && self::is_edd_active();
    }

    /**
     * Check if MemberPress is active and location is MemberPress-related
     */
    public static function is_memberpress_location($location) {
        return in_array($location, self::get_memberpress_locations()) && self::is_memberpress_active();
    }

    /**
     * Check if WooCommerce is active
     */
    public static function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Check if Easy Digital Downloads is active
     */
    public static function is_edd_active() {
        return class_exists('Easy_Digital_Downloads');
    }

    /**
     * Check if MemberPress is active
     */
    public static function is_memberpress_active() {
        return class_exists('MeprAppCtrl');
    }

    /**
     * Get enqueue hook for eCommerce locations
     * For backwards compatibility with the main render system
     */
    public static function get_ecommerce_enqueue_hook($location) {
        // For eCommerce locations, we now use the specific hooks directly
        // But for compatibility, return the hook that would be used for enqueuing
        if (self::is_ecommerce_location($location)) {
            return 'wp_head'; // Default fallback for compatibility
        }
        return 'wp_head';
    }

    /**
     * Execute PHP code for eCommerce locations
     */
    public static function execute_ecommerce_php($snippet_id, $code, $location) {
        if (!self::is_ecommerce_location($location)) {
            return false;
        }

        // Check if the required plugin is active
        if (self::is_woocommerce_location($location) && !self::is_woocommerce_active()) {
            return false; // WooCommerce not active
        }
        if (self::is_edd_location($location) && !self::is_edd_active()) {
            return false; // EDD not active
        }
        if (self::is_memberpress_location($location) && !self::is_memberpress_active()) {
            return false; // MemberPress not active
        }

        // For MemberPress locations, use the dedicated handler
        if (self::is_memberpress_location($location)) {
            return self::execute_memberpress_php($snippet_id, $code, $location);
        }

        // Get priority from post meta, default to 10
        $hook_priority = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-hooks-priority', true) : (is_array($code) && isset($code['hooksPriority']) ? $code['hooksPriority'] : 10);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Get the hook name
        $hook_name = self::get_ecommerce_location_hooks()[$location];
        
        // Use template_redirect for better compatibility on live sites
        add_action('template_redirect', function() use ($snippet_id, $code, $hook_name, $priority, $location) {
            // Double-check context is appropriate
            if (!self::should_load_on_current_page($location)) {
                return;
            }

            // Register the actual hook
            add_action($hook_name, function() use ($snippet_id, $code) {
                $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($code) && isset($code['status']) ? $code['status'] : 0);
                if ($is_active == '1') {
                    $authorID = get_post_field('post_author', $snippet_id);
                    $theAuthorDataRoles = get_userdata($authorID);
                    $theRolesAuthor = isset($theAuthorDataRoles->roles) ? $theAuthorDataRoles->roles : [];
                    
                    if ((is_numeric($snippet_id) && in_array('administrator', $theRolesAuthor)) || (is_array($code) && isset($code['php_hidden_execute']) && $code['php_hidden_execute'] === 'yes')) {
                        $code_hidden_execute = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-php-hidden-execute', true) : (is_array($code) && isset($code['php_hidden_execute']) ? $code['php_hidden_execute'] : 'no');
                        if ($code_hidden_execute === 'yes') {
                            // Check schedule restrictions before executing
                            if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                                return;
                            }
                            
                            // Execute PHP code properly instead of echoing it as text
                            if (class_exists('Nexter_Builder_Code_Snippets_Executor')) {
                                $result = Nexter_Builder_Code_Snippets_Executor::get_instance()->execute_php_snippet($code, $snippet_id, false);
                                if (is_wp_error($result)) {
                                    // Log the error if WP_DEBUG is enabled
                                    if (defined('WP_DEBUG') && WP_DEBUG) {
                                        error_log('[Nexter Extension] PHP snippet execution error in eCommerce location: ' . $result->get_error_message());
                                    }
                                }
                            } else {
                                if(is_array($code) && class_exists('Nexter_Code_Snippets_File_Based')){
                                    if( isset($code['file_path']) && !empty($code['file_path']) && file_exists($code['file_path'])){
                                        // Use safe file execution method
                                        Nexter_Code_Snippets_File_Based::safe_include_file( $code['file_path'] );
                                    }
                                }else{
                                    // Fallback to direct execution if executor class not available
                                    $code = html_entity_decode(htmlspecialchars_decode($code));
                                    eval($code);
                                }
                                
                            }
                        }
                    }
                }
            }, $priority);
        }, 1);

        return true;
    }

    /**
     * Execute PHP code for MemberPress locations using the dedicated handler
     */
    private static function execute_memberpress_php($snippet_id, $code, $location) {
        // Store the snippet data for the MemberPress handler to use
        if (!isset(self::$memberpress_snippets)) {
            self::$memberpress_snippets = array();
        }
        
        self::$memberpress_snippets[$location][] = array(
            'id' => $snippet_id,
            'code' => $code,
            'type' => 'php'
        );
        
        return true;
    }

    /**
     * Get MemberPress snippets for a specific location
     */
    public static function get_memberpress_snippets($location) {
        if (!isset(self::$memberpress_snippets) || !isset(self::$memberpress_snippets[$location])) {
            return array();
        }
        
        return self::$memberpress_snippets[$location];
    }

    /**
     * Enqueue CSS for MemberPress locations using the dedicated handler
     */
    private static function enqueue_memberpress_css($snippet_id, $css, $location) {
        // Store the snippet data for the MemberPress handler to use
        if (!isset(self::$memberpress_snippets)) {
            self::$memberpress_snippets = array();
        }
        
        if(is_numeric($snippet_id) && is_array($css) && isset($css['file_path']) && file_exists($css['file_path'])) {
            ob_start();
            // Use safe file execution method
            if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                Nexter_Code_Snippets_File_Based::safe_include_file( $css['file_path'] );
            } else {
                // Fallback: basic validation
                $file_path = wp_normalize_path( $css['file_path'] );
                $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                    require_once $file_path;
                }
            }
            $css = ob_get_clean();
            $css = '<style id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $css . '</style>';
        }

        self::$memberpress_snippets[$location][] = array(
            'id' => $snippet_id,
            'content' => $css,
            'type' => 'css'
        );
        
        return true;
    }

    /**
     * Enqueue JavaScript for MemberPress locations using the dedicated handler
     */
    private static function enqueue_memberpress_js($snippet_id, $js, $location) {
        // Store the snippet data for the MemberPress handler to use
        if (!isset(self::$memberpress_snippets)) {
            self::$memberpress_snippets = array();
        }
        
        if(is_numeric($snippet_id) && is_array($js) && isset($js['file_path']) && file_exists($js['file_path'])) {
            ob_start();
            // Use safe file execution method
            if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                Nexter_Code_Snippets_File_Based::safe_include_file( $js['file_path'] );
            } else {
                // Fallback: basic validation
                $file_path = wp_normalize_path( $js['file_path'] );
                $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                    require_once $file_path;
                }
            }
            $js = ob_get_clean();
            $js = '<script id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $js . '</script>';
        }

        self::$memberpress_snippets[$location][] = array(
            'id' => $snippet_id,
            'content' => $js,
            'type' => 'js'
        );
        
        return true;
    }

    /**
     * Output HTML for MemberPress locations using the dedicated handler
     */
    private static function output_memberpress_html($snippet_id, $html, $location) {
        // Store the snippet data for the MemberPress handler to use
        if (!isset(self::$memberpress_snippets)) {
            self::$memberpress_snippets = array();
        }
        
        if(is_numeric($snippet_id) && is_array($html) && isset($html['file_path']) && file_exists($html['file_path'])) {
            ob_start();
            // Use safe file execution method
            if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                Nexter_Code_Snippets_File_Based::safe_include_file( $html['file_path'] );
            } else {
                // Fallback: basic validation
                $file_path = wp_normalize_path( $html['file_path'] );
                $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                    require_once $file_path;
                }
            }
            $html = ob_get_clean();
        }

        self::$memberpress_snippets[$location][] = array(
            'id' => $snippet_id,
            'content' => $html,
            'type' => 'html'
        );
        
        return true;
    }

    /**
     * Enqueue CSS for eCommerce locations
     */
    public static function enqueue_ecommerce_css($snippet_id, $css, $location) {
        if (!self::is_ecommerce_location($location)) {
            return false;
        }

        // Check if the required plugin is active
        if (self::is_woocommerce_location($location) && !self::is_woocommerce_active()) {
            return false;
        }
        if (self::is_edd_location($location) && !self::is_edd_active()) {
            return false;
        }
        if (self::is_memberpress_location($location) && !self::is_memberpress_active()) {
            return false;
        }

        // For MemberPress locations, use the dedicated handler
        if (self::is_memberpress_location($location)) {
            return self::enqueue_memberpress_css($snippet_id, $css, $location);
        }

        // Get priority from post meta, default to 10
        $hook_priority = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-hooks-priority', true) : (is_array($css) && isset($css['hooksPriority']) ? $css['hooksPriority'] : 10);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Get the hook name
        $hook_name = self::get_ecommerce_location_hooks()[$location];
        
        // Register the hook directly (no template_redirect wrapper needed)
        add_action($hook_name, function() use ($snippet_id, $css, $location) {
            // Double-check context is appropriate
            if (!self::should_load_on_current_page($location)) {
                return;
            }

            $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($css) && isset($css['status']) ? $css['status'] : 0);
            if ($is_active == '1') {
                // Check schedule restrictions before executing
                if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
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
            }
        }, $priority);

        return true;
    }

    /**
     * Enqueue JavaScript for eCommerce locations
     */
    public static function enqueue_ecommerce_js($snippet_id, $js, $location) {
        if (!self::is_ecommerce_location($location)) {
            return false;
        }

        // Check if the required plugin is active
        if (self::is_woocommerce_location($location) && !self::is_woocommerce_active()) {
            return false;
        }
        if (self::is_edd_location($location) && !self::is_edd_active()) {
            return false;
        }
        if (self::is_memberpress_location($location) && !self::is_memberpress_active()) {
            return false;
        }

        // For MemberPress locations, use the dedicated handler
        if (self::is_memberpress_location($location)) {
            return self::enqueue_memberpress_js($snippet_id, $js, $location);
        }

        // Get priority from post meta, default to 10
        $hook_priority = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-hooks-priority', true) : (is_array($js) && isset($js['hooksPriority']) ? $js['hooksPriority'] : 10);
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Get the hook name
        $hook_name = self::get_ecommerce_location_hooks()[$location];
        
        // Register the hook directly (no template_redirect wrapper needed)
        add_action($hook_name, function() use ($snippet_id, $js, $location) {
            // Double-check context is appropriate
            if (!self::should_load_on_current_page($location)) {
                return;
            }

            $is_active = is_numeric($snippet_id) ? get_post_meta($snippet_id, 'nxt-code-status', true) : (is_array($js) && isset($js['status']) ? $js['status'] : 0);
            if ($is_active == '1') {
                // Check schedule restrictions before executing
                if (self::should_skip_due_to_schedule_restrictions($snippet_id)) {
                    return;
                }
                
                if(class_exists('Nexter_Code_Snippets_File_Based') && is_array($js)){
                    $file_based = new Nexter_Code_Snippets_File_Based();
                    $file_path = isset($js['file_path']) ? $js['file_path'] : '';
                    $js = $file_based->parseBlock(file_get_contents($file_path), true);
                }else{
                    $compress = get_post_meta($snippet_id, 'nxt-code-compresscode', true);
                    if ($compress) {
                        $js = self::compress_js($js);
                    }
                }

                echo '<script id="nexter-snippet-' . esc_attr($snippet_id) . '">' . $js . '</script>';
            }
        }, $priority);

        return true;
    }

    /**
     * Output HTML for eCommerce locations
     */
    public static function output_ecommerce_html($snippet_id, $html, $location) {
        if (!self::is_ecommerce_location($location)) {
            return false;
        }

        // Check if the required plugin is active
        if (self::is_woocommerce_location($location) && !self::is_woocommerce_active()) {
            return false; // WooCommerce not active
        }
        if (self::is_edd_location($location) && !self::is_edd_active()) {
            return false; // EDD not active
        }
        if (self::is_memberpress_location($location) && !self::is_memberpress_active()) {
            return false; // MemberPress not active
        }

        // For MemberPress locations, use the dedicated handler
        if (self::is_memberpress_location($location)) {
            return self::output_memberpress_html($snippet_id, $html, $location);
        }

        // Get priority from post meta, default to 10
        $hook_priority = is_numeric( $snippet_id )
        ? get_post_meta( $snippet_id, 'nxt-code-hooks-priority', true )
        : ( ( is_array( $html ) && isset( $html['hooksPriority'] ) ) ? $html['hooksPriority'] : 10 );
        $priority = !empty($hook_priority) ? intval($hook_priority) : 10;

        // Get the hook name
        $hook_name = self::get_ecommerce_location_hooks()[$location];
        
        // Register the hook directly (no template_redirect wrapper needed)
        add_action($hook_name, function() use ($snippet_id, $html, $location) {
            // Double-check context is appropriate
            if (!self::should_load_on_current_page($location)) {
                return;
            }

            $is_active = is_numeric( $snippet_id ) ? get_post_meta($snippet_id, 'nxt-code-status', true) : ( ( is_array( $html ) && isset( $html['status'] ) ) ? $html['status'] : '0' );
            if ($is_active == '1') {
                if (is_array($html) && isset($html['file_path']) && file_exists($html['file_path'])) {
                    ob_start();
                    // Use safe file execution method
                    if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
                        Nexter_Code_Snippets_File_Based::safe_include_file( $html['file_path'] );
                    } else {
                        // Fallback: basic validation
                        $file_path = wp_normalize_path( $html['file_path'] );
                        $storage_dir = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
                        if ( strpos( $file_path, $storage_dir ) === 0 && substr( $file_path, -4 ) === '.php' ) {
                            require_once $file_path;
                        }
                    }
                    $html = ob_get_clean();
                }
                echo apply_filters('nexter_html_snippets_executed', $html, $snippet_id);
            }
        }, $priority);

        return true;
    }

    /**
     * Check if snippet should load on current page
     */
    private static function should_load_on_current_page($location) {
        // WooCommerce page checks
        if (self::is_woocommerce_location($location)) {
            if (strpos($location, 'shop') !== false || strpos($location, 'list_products') !== false) {
                return (function_exists('is_shop') && is_shop()) || 
                       (function_exists('is_product_category') && is_product_category()) || 
                       (function_exists('is_product_tag') && is_product_tag());
            }
            if (strpos($location, 'single_product') !== false) {
                return function_exists('is_product') && is_product();
            }
            if (strpos($location, 'cart') !== false) {
                return function_exists('is_cart') && is_cart();
            }
            if (strpos($location, 'checkout') !== false) {
                return function_exists('is_checkout') && is_checkout();
            }
        }

        // EDD page checks
        if (self::is_edd_location($location)) {
            if (strpos($location, 'download') !== false) {
                return function_exists('is_singular') && is_singular('download');
            }
            if (strpos($location, 'cart') !== false || strpos($location, 'checkout') !== false) {
                return function_exists('edd_is_checkout') && edd_is_checkout();
            }
        }

        // MemberPress page checks
        if (self::is_memberpress_location($location)) {
            if (strpos($location, 'checkout') !== false) {
                return function_exists('is_page') && function_exists('get_query_var') && 
                       is_page() && get_query_var('action') === 'checkout';
            }
            if (strpos($location, 'account') !== false) {
                return function_exists('is_page') && function_exists('get_query_var') && 
                       is_page() && get_query_var('action') === 'account';
            }
            if (strpos($location, 'login') !== false) {
                return function_exists('is_page') && function_exists('get_query_var') && 
                       is_page() && get_query_var('action') === 'login';
            }
            // Always load unauthorized message hooks as they're applied via filter
            if (strpos($location, 'unauthorized') !== false) {
                return true;
            }
        }

        return true; // Default to load
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