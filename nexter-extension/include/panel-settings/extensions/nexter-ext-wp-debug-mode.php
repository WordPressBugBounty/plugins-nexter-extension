<?php 
/*
 * WP Debug Mode Extension
 * @since 4.3.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Wp_Debug_Mode {
    
    /**
     * Constructor
     */
    public function __construct() {
		$this->check_debug_mode();
    }

	public function check_debug_mode(){
		if(!defined('WP_DEBUG') ) {
            add_action('admin_notices', array( $this, 'show_error_init' ));
        }
        else if(!WP_DEBUG ) {
            add_action('admin_notices', array( $this, 'show_error_value' ));
        }
	}

	/**
     * Display notice if WP_DEBUG is not defined
     */
    public function show_error_init() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('The WP Debug Mode module failed to initialize.', 'nexter-extension'); ?></p>
            <p><?php esc_html_e('Please deactivate the module and activate it again to fix the issue.', 'nexter-extension'); ?></p>
        </div>
        <?php
    }

    /**
     * Display notice if WP_DEBUG is defined but set to false
     */
    public function show_error_value() {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php echo esc_html( sprintf(
                // Translators: %s is the WP_DEBUG constant.
                __( '%s is disabled, even though WP Debug Mode module is active. Maybe the configuration file was edited manually after module activation.', 'nexter-extension' ),
                'WP_DEBUG'
            ) ); ?></p>
            <p><?php esc_html_e('Please disable and then activate the module again.', 'nexter-extension'); ?></p>
        </div>
        <?php
    }
}

 new Nexter_Ext_Wp_Debug_Mode();