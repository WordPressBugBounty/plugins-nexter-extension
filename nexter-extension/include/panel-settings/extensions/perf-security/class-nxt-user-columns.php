<?php
/**
 * User Columns Module
 * Handles: User registration date column, last login column
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_User_Columns {

	/**
	 * @param array $adv_sec_opt Advance security values.
	 */
	public function __construct( $adv_sec_opt ) {

		if ( is_array( $adv_sec_opt ) && in_array( 'user_register_date_time', $adv_sec_opt, true ) ) {
			add_filter( 'manage_users_columns', [ $this, 'add_registered_column' ] );
			add_filter( 'manage_users_custom_column', [ $this, 'render_registered_column' ], 10, 3 );
		}

		if ( is_array( $adv_sec_opt ) && in_array( 'user_last_login_display', $adv_sec_opt, true ) ) {
			add_action( 'wp_login', [ $this, 'update_last_login' ], 3, 1 );
			add_filter( 'manage_users_columns', [ $this, 'add_last_login_column' ] );
			add_filter( 'manage_users_custom_column', [ $this, 'render_last_login_column' ], 10, 3 );
		}
	}

	public function add_registered_column( $columns ) {
		$columns['nxt_registered_date'] = __( 'Registered', 'nexter-extension' );
		return $columns;
	}

	public function render_registered_column( $output, $column_name, $user_id ) {
		if ( 'nxt_registered_date' === $column_name ) {
			$user = get_userdata( $user_id );
			$user_registered_date = strtotime( $user->user_registered );
			$date_format = get_option( 'date_format', 'F j, Y' );
			$time_format = get_option( 'time_format', 'g:i a' );
			$output = function_exists( 'wp_date' )
				? wp_date( "$date_format $time_format", $user_registered_date )
				: date_i18n( "$date_format $time_format", $user_registered_date );
		}
		return $output;
	}

	public function update_last_login( $user_login ) {
		$user = get_user_by( 'login', $user_login );
		if ( is_object( $user ) && property_exists( $user, 'ID' ) ) {
			update_user_meta( $user->ID, 'nxt_last_login_on', time() );
		}
	}

	public function add_last_login_column( $columns ) {
		$columns['nxt_last_login_on'] = __( 'Last Login', 'nexter-extension' );
		return $columns;
	}

	public function render_last_login_column( $output, $column_name, $user_id ) {
		if ( 'nxt_last_login_on' === $column_name ) {
			$nxt_last_login_on = (int) get_user_meta( $user_id, 'nxt_last_login_on', true );
			if ( ! empty( $nxt_last_login_on ) ) {
				$format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
				$output = function_exists( 'wp_date' )
					? wp_date( $format, $nxt_last_login_on )
					: date_i18n( $format, $nxt_last_login_on );
			} else {
				$output = __( 'Never', 'nexter-extension' );
			}
		}
		return $output;
	}
}
