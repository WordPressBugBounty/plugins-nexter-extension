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

	/**
	 * Creates the Cookie Consent log table for the current site.
	 *
	 * Never DROP TABLE here — unlike the login-attempt table this holds the
	 * customer's proof of consent; dbDelta() alone is enough to create it once
	 * and to add columns on a future schema bump without touching existing rows.
	 *
	 * @param int|null $blog_id Site id to target ($wpdb->prefix is switched and restored),
	 *                          or null for the current site.
	 * @return void
	 */
	public function create_consent_log_table( $blog_id = null ) {
		global $wpdb;

		$switched = false;
		if ( null !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$table_name      = $wpdb->prefix . 'nexter_consent_log';
		$charset_collate = $wpdb->get_charset_collate();

		// Column widths/types mirror 02-DEVELOPER-BRIEF.md PART 4.1 exactly — do not resize
		// `categories`/`ip_stored` casually, they are sized for the documented value shapes.
		$sql = "
            CREATE TABLE $table_name (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                consent_id      CHAR(32) NOT NULL,
                created_at      DATETIME NOT NULL,
                categories      VARCHAR(191) NOT NULL,
                config_version  SMALLINT UNSIGNED NOT NULL,
                action          VARCHAR(20) NOT NULL,
                ip_stored       VARCHAR(64) NULL,
                country         CHAR(2) NULL,
                user_agent      VARCHAR(255) NULL,
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY consent_id (consent_id)
            ) $charset_collate;
        ";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $switched && function_exists( 'restore_current_blog' ) ) {
			restore_current_blog();
		}
	}
}