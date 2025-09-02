<?php
/**
 * Activate Plugin Nexter Extension
 * @since 4.3.0
 */

defined( 'ABSPATH' ) || exit;

class Nexter_Ext_Activation {

    /**
     * Creates the login attempt table for the Limit Login Attempts feature.
     *
     * @return void
     */
    public function create_login_attempt_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'nxtext_login_failed';

        // Prepare charset and collation
        $charset_collate = $wpdb->get_charset_collate();

        // Drop the existing table (optional: comment out in production)
        $wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );

        // SQL to create the table
        $sql = "
            CREATE TABLE $table_name (
                id              INT(6) UNSIGNED NOT NULL AUTO_INCREMENT,
                ip_address      VARCHAR(40) NOT NULL DEFAULT '',
                username        VARCHAR(64) NOT NULL DEFAULT '',
                failed_count    INT(10) NOT NULL DEFAULT 0,
                lockout_count   INT(10) NOT NULL DEFAULT 0,
                request_uri     VARCHAR(255) NOT NULL DEFAULT '',
                unixtime        INT(10) NOT NULL DEFAULT 0,
                cur_datetime    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY ip_address (ip_address),
                PRIMARY KEY (id)
            ) $charset_collate;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}