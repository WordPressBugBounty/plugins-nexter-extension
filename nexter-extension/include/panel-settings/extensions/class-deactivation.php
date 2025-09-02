<?php
/**
 * Deactivate Plugin Nexter Extension
 * @since 4.3.0
 */

defined( 'ABSPATH' ) || exit;

class Nexter_Ext_Deactivation {

    /**
     * Drops the login attempt log table used for Limit Login Attempts feature.
     *
     * @return void
     */
    public function remove_login_attempt_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'nxtext_login_failed';
        $table_name = esc_sql( $table_name );
        // Use $wpdb->query safely to drop the table if it exists
        $wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );
    }
}