<?php 
/*
 * Limit Login Attempts Extension
 * @since 4.3.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Limit_Login_Attempt {
    
	public static $limit_login_opt = [];

	public static $custom_login_url = '';
    /**
     * Constructor
     */
    public function __construct() {
		$this->nxt_get_post_order_settings();

		add_filter( 'authenticate', [ $this, 'maybe_allow_login' ], 999, 3 );
		add_action( 'wp_login_errors', [ $this, 'handle_login_errors' ], 999, 2 );
		add_action( 'login_enqueue_scripts', [ $this, 'maybe_hide_login_form' ] );
		add_filter( 'login_message', [ $this, 'render_failed_login_notice' ] );
		add_action( 'wp_login_failed', [ $this, 'log_failed_login_attempt' ], 5 ); // Higher priority than one in Change Login URL
		
		add_action( 'wp_login', [ $this, 'reset_failed_login_log' ] );
		// Log table clean up
		/* add_action( 'added_option', [ $this, 'maybe_schedule_failed_login_cleanup' ] );
		add_action( 'updated_option', [ $this, 'maybe_schedule_failed_login_cleanup' ] );
		add_action( 'plugins_loaded', [ $this, 'schedule_failed_login_log_cleanup' ] );
		add_action( 'nxt_ext_login_log_cleanup', [ $this, 'clean_old_login_logs' ] ); */
		if( is_admin() ){
			add_action('wp_ajax_nexter_ext_get_login_attempt_data', [ $this, 'nexter_ext_get_login_attempt'] );
		}
    }

	private function nxt_get_post_order_settings(){
		
		if(isset(self::$limit_login_opt) && !empty(self::$limit_login_opt)){
			return self::$limit_login_opt;
		}

		$option = get_option( 'nexter_site_security' );
		
		if(!empty($option) && isset($option['custom_login_url']) && !empty($option['custom_login_url'])){
			self::$custom_login_url = $option['custom_login_url'];
		}

		if(!empty($option) && isset($option['limit-login-attempt']) && !empty($option['limit-login-attempt']['switch']) && !empty($option['limit-login-attempt']['values']) ){
			self::$limit_login_opt = (array) $option['limit-login-attempt']['values'];
		}

		return self::$limit_login_opt;
	}

	/**
	 * Conditionally allow user login based on failed login attempts and IP lockout rules.
	 */
	public function maybe_allow_login( $user_or_error, $username, $password ) {
		global $wpdb, $nxt_ext_limit_login;

		$table = $wpdb->prefix . 'nxtext_login_failed';

		// Check if the log table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

		if( !$table_exists ){
			if( !class_exists('Nexter_Ext_Activation') ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/class-activation.php';
			}
			if(class_exists('Nexter_Ext_Activation')){
				$activation = new Nexter_Ext_Activation();
				$activation->create_login_attempt_table();
			}
		}

		// Defaults
		$failed_limit  = isset(self::$limit_login_opt['failed_login']) ? self::$limit_login_opt['failed_login'] : 5;
		$lockout_limit = isset(self::$limit_login_opt['lockout_login']) ? self::$limit_login_opt['lockout_login'] : 5;
		$ip_list_raw   = isset(self::$limit_login_opt['ip_address_list']) ? self::$limit_login_opt['ip_address_list'] : '';
		$whitelist     = array_map( 'trim', explode( PHP_EOL, $ip_list_raw ) );

		$ip = $this->get_client_ip( 'ip', 'limit-login-attempt' );

		// Setup login config
		$nxt_ext_limit_login = array(
			'ip_address'               => $ip,
			'request_uri'              => sanitize_text_field( $_SERVER['REQUEST_URI'] ?? '' ),
			'ip_address_log'           => array(),
			'failed_count'               => 0,
			'lockout_count'            => 0,
			'maybe_lockout'           => false,
			'extended_lockout'         => false,
			'within_lockout_period'    => false,
			'lockout_period'           => 0,
			'lockout_period_remaining' => 0,
			'failed_login'             => $failed_limit,
			'lockout_login'            => $lockout_limit,
			'default_lockout_period'   => 15 * 60,
			'extended_lockout_period'  => 30 * 60,
			'custom_login_url'         => self::$custom_login_url  ?? '',
		);

		// Skip checks if IP is whitelisted
		if ( (defined('NXT_PRO_EXT') && in_array( $ip, $whitelist, true )) || ! $table_exists ) {
			return $user_or_error;
		}

		// Fetch login attempt data
		$log = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE `ip_address` = %s", $ip ),
			ARRAY_A
		);
 
		if ( $log ) {
			$nxt_ext_limit_login['ip_address_log'] = $log;
			$nxt_ext_limit_login['failed_count']     = (int) $log['failed_count'];
			$nxt_ext_limit_login['lockout_count']  = (int) $log['lockout_count'];
			$last_fail                             = (int) $log['unixtime'];
		}else{
			$last_fail                             = '';
		}
		
		// Check lockout trigger
		if ( $nxt_ext_limit_login['failed_count'] > 0 && ( $nxt_ext_limit_login['failed_count'] % $failed_limit === 0 ) ) {
			$nxt_ext_limit_login['maybe_lockout'] = true;

			$lockout_period = $nxt_ext_limit_login['lockout_count'] >= $lockout_limit
				? $nxt_ext_limit_login['extended_lockout_period']
				: $nxt_ext_limit_login['default_lockout_period'];

			$nxt_ext_limit_login['extended_lockout'] = $lockout_period === $nxt_ext_limit_login['extended_lockout_period'];
			$nxt_ext_limit_login['lockout_period']   = $lockout_period;

			$time_since_last_fail = time() - $last_fail;

			if ( $time_since_last_fail <= $lockout_period ) {
				$nxt_ext_limit_login['within_lockout_period']    = true;
				$nxt_ext_limit_login['lockout_period_remaining'] = $lockout_period - $time_since_last_fail;

				$remaining = $nxt_ext_limit_login['lockout_period_remaining'];
				if ( $remaining <= 60 ) {
					$readable = "$remaining seconds";
				} elseif ( $remaining <= 3600 ) {
					$readable = $this->format_seconds( $remaining, 'to-minutes-seconds' );
				} elseif ( $remaining <= 1800 ) {
					$readable = $this->format_seconds( $remaining, 'to-hours-minutes-seconds' );
				} else {
					$readable = $this->format_seconds( $remaining, 'to-days-hours-minutes-seconds' );
				}

				return new WP_Error(
					'ip_address_blocked',
					'<div style="text-align:center;font-size: 16px;line-height: 24px;font-weight: 400;"><strong>WARNING</strong></div><div style="text-align:center;font-size: 12px;line-height: 18px;color: #666666;"> You\'ve been locked out. You can login again in ' . esc_html( $readable ) . '.</div>'
				);
			}

			// Lockout expired, reset log
			if ( $nxt_ext_limit_login['lockout_count'] === $lockout_limit ) {
				$wpdb->delete( $table, array( 'ip_address' => $ip ), array( '%s' ) );
			}
		}

		return $user_or_error;
	}

	/**
	 * Retrieve the user's IP address securely.
	 */
	public function get_client_ip( $return = 'ip', $module = 'limit-login-attempt' ) {
		$header_key = '';

		if ( $module === 'limit-login-attempt' ) {
			$header_key = isset( self::$limit_login_opt['header_override'] )
				? trim( self::$limit_login_opt['header_override'] )
				: '';
		}

		if ( ! empty( $header_key ) && isset( $_SERVER[ $header_key ] ) ) {
			 $ip_raw = $_SERVER[ $header_key ];
			$ips = array_map( 'trim', explode( ',', $ip_raw ) );

			foreach ( $ips as $ip ) {
				if ( $return === 'ip' ) {
					return $this->is_valid_ip( $ip ) ? sanitize_text_field( $ip ) : '0.0.0.0';
				} else {
					return $header_key . ( count( $ips ) > 1 ? ' (multiple IPs)' : '' );
				}
			}
		}

		$remote_ip = '';
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        	$remote_ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$remote_ip = trim($ipList[0]);
		} else if (!empty($_SERVER['REMOTE_ADDR'])) {
			$remote_ip = $_SERVER['REMOTE_ADDR'];
		}

		if ( $return === 'ip' ) {
			return $this->is_valid_ip( $remote_ip ) ? sanitize_text_field( $remote_ip ) : '0.0.0.0';
		}

		return 'REMOTE_ADDR';
	}

	/**
	 * Validate IPv4 or IPv6 address.
	 */
	public function is_valid_ip( $ip ) {
		if ( empty( $ip ) ) {
			return false;
		}

		return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Convert seconds to human-readable time format.
	 * Used by features like Limit Login Attempts.
	 */
	public function format_seconds( $seconds, $format_type = 'to-days-hours-minutes-seconds' ) {
		$start = new \DateTime( '@0' );
		$end   = new \DateTime( "@$seconds" );
		$diff  = $start->diff( $end );

		switch ( $format_type ) {
			case 'to-hours-minutes-seconds':
				return $diff->format( '%h hours, %i minutes and %s seconds' );

			case 'to-minutes-seconds':
				return $diff->format( '%i minutes and %s seconds' );

			case 'to-days-hours-minutes-seconds':
			default:
				return $diff->format( '%a days, %h hours, %i minutes and %s seconds' );
		}
	}

	/**
	 * Customize login error messages to avoid leaking sensitive information.
	 */
	public function handle_login_errors( $errors, $redirect_to ) {
		global $nxt_ext_limit_login;

		if ( is_wp_error( $errors ) ) {
			foreach ( $errors->get_error_codes() as $code ) {
				if ( in_array( $code, [ 'invalid_username', 'incorrect_password' ], true ) ) {
					// Remove specific error messages that reveal login info
					$errors->remove( 'invalid_username' );
					$errors->remove( 'incorrect_password' );

					// Add a generic error message for better security
					$errors->add(
						'invalid_login',
						sprintf(
							'<strong>%s</strong> %s',
							esc_html__( 'Error:', 'nexter-extension' ),
							esc_html__( 'Invalid username/email or incorrect password.', 'nexter-extension' )
						)
					);
					break; // Only need to add once
				}
			}
		}

		return $errors;
	}

	/**
	 * Hide the login form and display a custom message if within the lockout period.
	 *
	 * @since 2.5.0
	 */
	public function maybe_hide_login_form() {
		global $nxt_ext_limit_login;

		if ( isset( $nxt_ext_limit_login['within_lockout_period'] ) && $nxt_ext_limit_login['within_lockout_period'] ) {
			// Display custom styling and hide login form
			?>
			<script>
				document.addEventListener("DOMContentLoaded", function() {
					var loginForm = document.getElementById("loginform");
					if (loginForm) {
						loginForm.remove();
					}
				});
			</script>
			<style type="text/css">
				body.login {
					background:rgb(195, 192, 241);
				}

				#login h1,
				#loginform,
				#login #nav,
				#backtoblog,
				.language-switcher {
					display: none;
				}
				#login_error.notice-error {
					border: 0;
					border-top: 5px solid #FF1400;
					border-radius: 5px;
				}
				@media screen and (max-height: 550px) {
					#login {
						padding: 50px 0 20px !important;
					}
				}
			</style>
			<?php
		} else {
			// Get options and check for reload condition
			$failed_login = isset( self::$limit_login_opt['failed_login'] ) ? (int) self::$limit_login_opt['failed_login'] : 5;
			$page_was_reloaded = isset( $_GET['ne'] ) && sanitize_text_field($_GET['ne']) == 1;

			if ( isset( $nxt_ext_limit_login['failed_count'] ) ) {
				$failed_count = (int) $nxt_ext_limit_login['failed_count'];

				// Check if fail count is close to the allowed login failures
				if ( in_array( $failed_count, range( $failed_login - 1, 6 * $failed_login - 1, $failed_login ) ) ) {
					if ( ! isset( self::$custom_login_url  ) || ! self::$custom_login_url ) {
						// If custom login URL is not set, reload the page to get updated data
						if ( ! $page_was_reloaded ) {
							?>
							<script>
								var url = new URL(window.location.href);
								url.searchParams.set('ne', '1');
								location.replace(url);
							</script>
							<?php
						}
					}
				}
			}
		}
	}

	/**
	 * Display a generic login error message above the login form after a failed attempt.
	 */
	public function render_failed_login_notice( $msg ) {
		global $nxt_ext_limit_login;

		$is_failed = isset( $_REQUEST['failed_login'] ) && sanitize_text_field( $_REQUEST['failed_login'] ) === 'true';

		if ( $is_failed && ! empty( $nxt_ext_limit_login ) && empty( $nxt_ext_limit_login['within_lockout_period'] ) ) {
			$msg = sprintf(
				'<div id="login_error" class="notice notice-error"><strong>%s</strong> %s</div>',
				esc_html__( 'Error:', 'nexter-extension' ),
				esc_html__( 'Invalid username/email or incorrect password.', 'nexter-extension' )
			);
		}

		return $msg;
	}

	/**
	 * Log failed login attempts by IP and username.
	 *
	 * @since 2.5.0
	 *
	 * @param string $username The username used in the failed login.
	 */
	public function log_failed_login_attempt( $username ) {
		global $wpdb, $nxt_ext_limit_login;

		$table = $wpdb->prefix . 'nxtext_login_failed';

		$ip         = $nxt_ext_limit_login['ip_address'] ?? '';
		$uri        = $nxt_ext_limit_login['request_uri'] ?? '';
		$max_fails  = $nxt_ext_limit_login['failed_login'] ?? 5;
		$max_blocks = $nxt_ext_limit_login['lockout_login'] ?? 5;

		// Fetch previous login attempts for IP
		$sql = $wpdb->prepare( "SELECT * FROM `" . $table . "` WHERE `ip_address` = %s", $ip );
		$record = $wpdb->get_results( $sql, ARRAY_A );

		if ( $record ) {
            $record_count = count( $record );
        } else {
            $record_count = 0;
        }

		if ( $record_count ) {
			$failed_count = (int) $record[0]['failed_count'] + 1;
		} else {
			$failed_count = 1;
		}

		$lockout_count  = $record_count ? (int) floor( $failed_count / $max_fails ) : 0;
		$last_fail_time = $record[0]['unixtime'] ?? 0;

		$timestamp  = time();
		$datetime   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $timestamp ) : date_i18n( 'Y-m-d H:i:s', $timestamp );

		$data = [
			'ip_address'    => $ip,
			'username'      => $username,
			'failed_count'    => $failed_count,
			'lockout_count' => $lockout_count,
			'request_uri'   => $uri,
			'unixtime'      => $timestamp,
			'cur_datetime'   => $datetime
		];

		$format = [ '%s', '%s', '%d', '%d', '%s', '%d', '%s' ];
		$where  = [ 'ip_address' => $ip ];
		$where_format = [ '%s' ];

		// Set current state to global
		$nxt_ext_limit_login['ip_address_log'] = $record ? $record : [];

		if ( $record_count == 0 ) {
			$wpdb->insert( $table, $data, $format );
		}else{
			$failed_count = (int) $record[0]['failed_count'];
			$lockout_count = $record[0]['lockout_count'];
			$last_fail_time = $record[0]['unixtime'];

			// If failed attempts match a lockout trigger
			if ( ! empty( $failed_count ) 
					&& $max_fails > 0 && $failed_count % $max_fails === 0 ) {
				$is_extended = $lockout_count >= $max_blocks;
				$lockout_duration = $is_extended
					? $nxt_ext_limit_login['extended_lockout_period'] ?? 1800
					: $nxt_ext_limit_login['default_lockout_period'] ?? 900;

				$nxt_ext_limit_login['extended_lockout'] = $is_extended;
				$nxt_ext_limit_login['lockout_period']   = $lockout_duration;

				// If still within lockout window, don't update record
				if ( ( time() - (int) $last_fail_time ) <= $lockout_duration ) {
					//do nothing
				}else if ( $lockout_count < $max_blocks ) {
						// Update existing data in the database
						$wpdb->update(
							$table,
							$data,
							$where,
							$format,
							$where_format
						);

					}
			}else{
				// Update failed login log
				$wpdb->update( $table, $data, $where, $format, $where_format );
			}
		}

	}

	/**
	 * Reset failed login attempts log after a successful login.
	 */
	public function reset_failed_login_log() {
		global $wpdb, $nxt_ext_limit_login;

		$table = $wpdb->prefix . 'nxtext_login_failed';
		$ip    = $nxt_ext_limit_login['ip_address'] ?? '';

		if ( empty( $ip ) ) {
			return; // Nothing to delete
		}

		$wpdb->delete(
			$table,
			[ 'ip_address' => $ip ],
			[ '%s' ]
		);
	}

	/**
	 * Trigger log cleanup scheduling when the corresponding option is updated.
	 */
	/* public function maybe_schedule_failed_login_cleanup( $option_name ) {
		if ( 'fail_login_schedule_cleanup' === $option_name ) {
			$this->schedule_failed_login_log_cleanup();
		}
	} */

	/**
	 * Schedule or clear the failed login attempts cleanup event based on settings.
	 */
	/* public function schedule_failed_login_log_cleanup() {
		$enabled = self::$limit_login_opt['fail_login_schedule_cleanup'] ?? false;

		$hook = 'nxt_ext_login_log_cleanup';

		if ( ! $enabled ) {
			// Unschedule if the feature is disabled
			wp_clear_scheduled_hook( $hook );
			return;
		}

		// Schedule cleanup event if not already scheduled
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'hourly', $hook );
		}
	} */

	/**
	 * Clean up old failed login attempts, keeping only the latest N entries.
	 */
	/* public function clean_old_login_logs() {
		global $wpdb;

		$enabled  = self::$limit_login_opt['fail_login_schedule_cleanup'] ?? false;
		$keep_max = 1000;

		if ( ! $enabled || ! is_numeric( $keep_max ) || $keep_max <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'nxtext_login_failed';

		// Run cleanup using a DELETE with JOIN to keep only recent records
		$cleanup_sql = "
			DELETE log_old FROM {$table} AS log_old
			JOIN (
				SELECT id FROM {$table}
				ORDER BY id DESC
				LIMIT 1 OFFSET %d
			) AS log_limit ON log_old.id <= log_limit.id
		";

		$wpdb->query( $wpdb->prepare( $cleanup_sql, $keep_max ) );
	} */

	public function nexter_ext_get_login_attempt() {
		global $wpdb;

		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized access.', 'nexter-extension' ), 403 );
		}

		$table = $wpdb->prefix . 'nxtext_login_failed';

		// Validate table name existence
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
			wp_send_json_error( __( 'Table not found.', 'nexter-extension' ), 404 );
		}

		$results = $wpdb->get_results(
			"SELECT ip_address, username, failed_count, lockout_count, cur_datetime FROM $table",
			ARRAY_A
		);

		if ( ! $results ) {
			wp_send_json_error( [] ); // Return empty array if no data
		}

		$data = array_map( function( $row ) {
			return [
				'ip_address' => esc_html( $row['ip_address'] ),
				'username'   => esc_html( $row['username'] ),
				'attempt'    => (int) $row['failed_count'],
				'lockouts'   => (int) $row['lockout_count'],
				'cur_time'   => mysql2date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$row['cur_datetime']
				),
			];
		}, $results );

		wp_send_json_success( $data );
	}


}

new Nexter_Ext_Limit_Login_Attempt();