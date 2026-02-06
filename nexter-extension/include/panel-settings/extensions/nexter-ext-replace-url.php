<?php
if( !function_exists('nxt_replace_url')){
	function nxt_replace_url() {
		// Security: Require authentication
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Authentication required.', 'nexter-extension' ),
				)
			);
		}
		
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		
		// Security: Require administrator role
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Insufficient permissions.', 'nexter-extension' ),
				)
			);
		}
		
		$user = wp_get_current_user();
		$allowed_roles = array( 'administrator' );
		if ( !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ) {
			$from = ( isset($_POST['from']) && !empty( $_POST['from'] ) ) ? sanitize_text_field( wp_unslash($_POST['from']) ) : '';
			$to = ( isset($_POST['to']) && !empty( $_POST['to'] ) ) ? sanitize_text_field( wp_unslash($_POST['to']) ) : '';
			
			$case = ( isset($_POST['case']) && !empty( $_POST['case'] ) ) ? sanitize_text_field( wp_unslash($_POST['case']) ) : '';
			$guidV = ( isset($_POST['guid']) && !empty( $_POST['guid'] ) ) ? sanitize_text_field( wp_unslash($_POST['guid']) ) : '';
			$limitV = ( isset($_POST['limit']) && !empty( $_POST['limit'] ) ) ? absint( wp_unslash( $_POST['limit'] ) ) : 20000;

			// Security: Validate and sanitize table names more strictly
			$selTables = isset( $_POST['tables'] ) ? wp_unslash( $_POST['tables'] ) : '';
			if ( is_string( $selTables ) ) {
				$selTables = json_decode( $selTables, true );
			}
			$selTables = is_array( $selTables ) ? array_map( function( $table ) {
				// Only allow alphanumeric, underscore, and dollar sign for table names
				return preg_replace( '/[^a-zA-Z0-9_$]/', '', sanitize_text_field( $table ) );
			}, $selTables ) : [];
			$selTables = array_filter( $selTables ); // Remove empty values

			$from = trim( $from ); $to = trim( $to );

			if ( $from === $to ) {
				wp_send_json_error(
					array(
						'success' => false,
						'message' => __( 'The "OLD" and "NEW" URLs must be different', 'nexter-extension' ),
					)
				);
			}
				
			$rows_affected = 0;
			if(!empty($selTables)){
				$replaceValue = false;
				$rows_affected = nxt_search_replace($selTables, $from, $to, $case,$guidV,$limitV,$replaceValue);
			}else{
				wp_send_json_error(
					array(
						'success' => false,
						'message' => __( 'Select any table before replace', 'nexter-extension' ),
					)
				);
			}
			
			wp_send_json_success(
				array(
					'result' => $rows_affected,
				)
			);
		}else{
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Only Admin can run this.', 'nexter-extension' ),
				)
			);
		}
	}
	add_action( 'wp_ajax_nxt_replace_url', 'nxt_replace_url' );
	// Removed unauthenticated access to prevent security vulnerability
	// add_action('wp_ajax_nopriv_nxt_replace_url', 'nxt_replace_url' );
}

if( !function_exists('nxt_replace_confirm_url')){
	function nxt_replace_confirm_url() {
		// Security: Require authentication
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Authentication required.', 'nexter-extension' ),
				)
			);
		}
		
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		
		// Security: Require administrator role
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Insufficient permissions.', 'nexter-extension' ),
				)
			);
		}
		
		$user = wp_get_current_user();
		$allowed_roles = array( 'administrator' );
		if ( !empty($user) && isset($user->roles) && array_intersect( $allowed_roles, $user->roles ) ) {
			$from = !empty( $_POST['from'] ) ? sanitize_text_field( wp_unslash($_POST['from']) ) : '';
			$to = !empty( $_POST['to'] ) ? sanitize_text_field( wp_unslash($_POST['to']) ) : '';
			
			$case = ( isset($_POST['case']) && !empty( $_POST['case'] ) ) ? sanitize_text_field( wp_unslash($_POST['case']) ) : '';
			$guidV = ( isset($_POST['guid']) && !empty( $_POST['guid'] ) ) ? sanitize_text_field( wp_unslash($_POST['guid']) ) : '';
			$limitV = ( isset($_POST['limit']) && !empty( $_POST['limit'] ) ) ? absint( wp_unslash( $_POST['limit'] ) ) : 20000;
			
			$from = trim( $from ); $to = trim( $to );
		
			$rows_affected = 0;
			// Security: Validate and sanitize table names more strictly
			$selTables = isset( $_POST['tables'] ) ? wp_unslash( $_POST['tables'] ) : '';
			if ( is_string( $selTables ) ) {
				$selTables = json_decode( $selTables, true );
			}
			$selTables = is_array( $selTables ) ? array_map( function( $table ) {
				// Only allow alphanumeric, underscore, and dollar sign for table names
				return preg_replace( '/[^a-zA-Z0-9_$]/', '', sanitize_text_field( $table ) );
			}, $selTables ) : [];
			$selTables = array_filter( $selTables ); // Remove empty values
		
			if(!empty($selTables)){
				$replaceValue = true;
				$rows_affected = nxt_search_replace($selTables, $from, $to, $case,$guidV, $limitV, $replaceValue);
			}else{
				wp_send_json_error(
					array(
						'success' => false,
						'message' => __( 'Select any table before replace', 'nexter-extension' ),
					)
				);
			}
			
			wp_send_json_success(
				array(
					'result' => $rows_affected,
				)
			);
		}else{
			wp_send_json_error(
				array(
					'success' => false,
					'message' => __( 'Only Admin can run this.', 'nexter-extension' ),
				)
			);
		}
	}
	add_action( 'wp_ajax_nxt_replace_confirm_url', 'nxt_replace_confirm_url' );
	// Removed unauthenticated access to prevent security vulnerability
	// add_action('wp_ajax_nopriv_nxt_replace_confirm_url', 'nxt_replace_confirm_url' );
}

if( !function_exists('nxt_get_columns')){
	function nxt_get_columns( $table ) {
		global $wpdb;
		$primKey = null; $columns = array();
	
		// Security: Validate and sanitize table name to prevent SQL injection
		$table = preg_replace( '/[^a-zA-Z0-9_$]/', '', $table );
		if ( empty( $table ) ) {
			return array( null, array() );
		}
		
		// Security: Table names cannot be prepared, so we validate and escape separately
		$table_escaped = esc_sql( $table );
		$fields = $wpdb->get_results( "DESCRIBE `{$table_escaped}`" );
	
		if ( is_array( $fields ) ) {
			foreach ( $fields as $column ) {
				$columns[] = $column->Field;
				if ( $column->Key == 'PRI' ) {
					$primKey = $column->Field;
				}
			}
		}
	
		return array( $primKey, $columns );
	}
}

if( !function_exists('mysql_escape_mimic')){
	function mysql_escape_mimic( $input ) {
		if ( is_array( $input ) ) {
			return array_map( __METHOD__, $input );
		}
		if ( ! empty( $input ) && is_string( $input ) ) {
			return str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $input );
		}
	
		return $input;
	}
}

if( !function_exists('nxt_unserialize_replace')){
	function nxt_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false, $case = false ) {
		// Security: Prevent PHP object injection by using a safer unserialize approach
		// Only unserialize data that we control (from database, not user input)
		
		if ( is_string( $data ) && !is_serialized_string( $data ) && is_serialized( $data )) {
			$unserialized = false;
			if ( ! is_serialized( $data ) ) {
				$unserialized = false;
			}else{
				$serialized_string = trim( $data );
				
				// Security: Use allowed_classes parameter to prevent arbitrary class instantiation
				// This ensures that only arrays and primitives are deserialized, preventing PHP Object Injection attacks
				if ( version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
					// Secure version - PHP 7.0+
					$unserialized = @unserialize( $serialized_string, array( 'allowed_classes' => false ) );
				} else {
					// For PHP 5.6, check for object notation before unserializing
					// Reject any serialized data containing objects to prevent PHP object injection
					if ( preg_match( '/O:\d+:"/', $serialized_string ) ) {
						// Contains object - reject for security to prevent PHP object injection
						return $data;
					}
					$unserialized = @unserialize( $serialized_string );
				}
			}
			if ( $unserialized !== false ) {
				$data = nxt_unserialize_replace( $from, $to, $unserialized, true, $case );
			}
		}elseif ( is_array( $data ) ) {
			$_temp = array( );
			foreach ( $data as $key => $value ) {
				$_temp[ $key ] = nxt_unserialize_replace( $from, $to, $value, false, $case );
			}
	
			$data = $_temp;
			unset( $_temp );
		}elseif ( is_object( $data ) ) {
			// Security: Prevent unserialization of potentially dangerous objects
			if ('__PHP_Incomplete_Class' !== get_class($data)) {
				$_temp = $data;
				$props = get_object_vars( $data );
				foreach ( $props as $key => $value ) {
					$_temp->$key = nxt_unserialize_replace( $from, $to, $value, false, $case );
				}
	
				$data = $_temp;
				unset( $_temp );
			}
		}elseif ( is_serialized_string( $data ) ) {
			$unserialized = false;
	
			if ( ! is_serialized( $data ) ) {
				$unserialized = false;
			}else{
				$serialized_string = trim( $data );
				
				// Security: Use allowed_classes parameter to prevent arbitrary class instantiation
				// This ensures that only arrays and primitives are deserialized, preventing PHP Object Injection attacks
				if ( version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
					// Secure version - PHP 7.0+
					$unserialized = @unserialize( $serialized_string, array( 'allowed_classes' => false ) );
				} else {
					// For PHP 5.6, check for object notation before unserializing
					// Reject any serialized data containing objects to prevent PHP object injection
					if ( preg_match( '/O:\d+:"/', $serialized_string ) ) {
						// Contains object - reject for security to prevent PHP object injection
						return $data;
					}
					$unserialized = @unserialize( $serialized_string );
				}
			}
	
			if ( $unserialized !== false ) {
				$data = nxt_unserialize_replace( $from, $to, $unserialized, true, $case );
			}
		}else {
			if ( is_string( $data ) ) {
				if ( 'yes' === $case ) {
					$data = str_ireplace( $from, $to, $data );
				} else {
					$data = str_replace( $from, $to, $data );
				}
			}
		}
		if ( $serialised ) {
			return serialize( $data );
		}
		return $data;
	}
}

if( !function_exists('nxt_search_replace')){
	function nxt_search_replace($selTables, $from, $to, $case, $guidV, $limitV, $replaceValue){
		global $wpdb;
		$changes = $off = 0;

		if(!empty($selTables)){
			foreach ($selTables as $table) {
				// Security: Validate and sanitize table name
				$table = preg_replace( '/[^a-zA-Z0-9_$]/', '', $table );
				if ( empty( $table ) ) {
					continue;
				}
				
				list( $primKey, $columns ) = nxt_get_columns( $table );
				
				// Security: Use prepared statement with proper type casting
				// Note: Table names cannot be prepared, so we validate them separately
				$limitV = absint( $limitV );
				$off = absint( $off );
				$table_escaped = esc_sql( $table ); // Additional escaping for table name
				$data = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table_escaped}` LIMIT %d, %d", $off, $limitV ), ARRAY_A );
				foreach ( $data as $row ) {
					$update_data = array();
					$where_data = array();

					foreach( $columns as $column ) {
						// Security: Validate column name to prevent SQL injection
						$column = preg_replace( '/[^a-zA-Z0-9_$]/', '', $column );
						if ( empty( $column ) || ! isset( $row[ $column ] ) ) {
							continue;
						}
						
						$data_to_fix = $row[ $column ];
						if ( $column == $primKey ) {
							// Security: Use wpdb->prepare with proper escaping
							// Note: Column names cannot be prepared, so we validate them separately
							$column_escaped = esc_sql( $column );
							$where_data[] = $wpdb->prepare( "`{$column_escaped}` = %s", $data_to_fix );
							continue;
						}

						/** Condition to skip GUID Column in table */
						if ( !empty($guidV) && $guidV=='no' && $column=='guid' ) {
							continue;
						}
						$replaced_data = nxt_unserialize_replace( $from, $to, $data_to_fix, false, $case );

						if ( $replaced_data != $data_to_fix ) {
							$changes++;
							// Security: Use wpdb->prepare with proper escaping
							// Note: Column names cannot be prepared, so we validate them separately
							$column_escaped = esc_sql( $column );
							$update_data[] = $wpdb->prepare( "`{$column_escaped}` = %s", $replaced_data );
						}
					}

					if(!empty($replaceValue) && $replaceValue == true && !empty($update_data)){
						// Security: Use prepared statements instead of string concatenation
						// Note: This is a complex query, but we've already sanitized table name and data
						$table_escaped = esc_sql( $table );
						$set_clause = implode( ', ', $update_data );
						$where_clause = implode( ' AND ', array_filter( $where_data ) );
						
						// Security: Validate that we have valid data before executing
						if ( ! empty( $set_clause ) && ! empty( $where_clause ) ) {
							// Note: update_data and where_data already use wpdb->prepare, so they're safe
							$sqlQuery = "UPDATE `{$table_escaped}` SET {$set_clause} WHERE {$where_clause}";
							$wpdb->query( $sqlQuery );
						}
					}
				}
			}
		}
		return $changes;
	}
}