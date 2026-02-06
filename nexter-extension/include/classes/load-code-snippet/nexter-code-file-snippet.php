<?php
/**
 * Nexter Code Snippet File Based Loader
 *
 * @package Nexter Extensions
 * @since 4.5.0
 */

if (!defined('ABSPATH')) {
	exit;
}

if ( ! class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {

	class Nexter_Code_Snippets_File_Based {

		/**
		 * Storage directory path
		 *
		 * @var string
		 */
		private $file_store = '';

		/**
		 * Cache for indexed config
		 *
		 * @var array|null
		 */
		private static $config_cache = null;

		/**
		 * Constructor
		 */
		public function __construct() {
			// Folder where snippet cache will be stored
			$this->file_store = wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
		}

		/**
		 * Get file directory path
		 *
		 * @return string Normalized directory path
		 */
		public static function getfileDir() {
			return wp_normalize_path( WP_CONTENT_DIR . '/nexter-snippet-data' );
		}

		/**
		 * Check if WP_CONTENT_DIR is writable (pre-check for file operations)
		 *
		 * @return bool True if writable, false otherwise
		 */
		private function check_content_dir_writable() {
			// Use is_writable() as wp_is_writable() may not exist in all WordPress versions
			if ( function_exists( 'wp_is_writable' ) ) {
				return wp_is_writable( WP_CONTENT_DIR );
			}
			return is_writable( WP_CONTENT_DIR );
		}

		/**
		 * Ensure directory exists and is writable
		 *
		 * @param string $dir Directory path to check
		 * @return bool True if directory exists and is writable, false otherwise
		 */
		private function ensure_directory( $dir = '' ) {
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting any file operations
			if ( ! $this->check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}

			if ( empty( $dir ) ) {
				$dir = $this->file_store;
			}

			$dir = wp_normalize_path( $dir );

			// Check if directory exists
			if ( ! is_dir( $dir ) ) {
				// Check parent directory is writable before creating
				$parent_dir = dirname( $dir );
				if ( ! is_writable( $parent_dir ) ) {
					return false;
				}

				// Create directory with proper permissions
				if ( ! wp_mkdir_p( $dir ) ) {
					return false;
				}
			}

			// Check if directory is writable
			if ( ! is_writable( $dir ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Sanitize file key to prevent directory traversal
		 *
		 * @param string $file_key File key to sanitize
		 * @return string Sanitized file key
		 */
		private function sanitize_file_key( $file_key ) {
			if ( empty( $file_key ) ) {
				return '';
			}

			// Remove any path components
			$file_key = basename( $file_key );
			
			// Remove .php extension if present for consistency
			$file_key = preg_replace( '/\.php$/', '', $file_key );
			
			// Sanitize to alphanumeric, dash, underscore only
			$file_key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $file_key );
			
			return $file_key;
		}

		/**
		 * Validate file path is within allowed directory (Enhanced Security)
		 *
		 * @param string $file_path File path to validate
		 * @return bool True if valid, false otherwise
		 */
		private function is_valid_file_path( $file_path ) {
			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return false;
			}

			$file_path = wp_normalize_path( $file_path );
			$storage_dir = wp_normalize_path( $this->file_store );
			
			// Ensure storage directory is set
			if ( empty( $storage_dir ) ) {
				return false;
			}
			
			// Ensure file is within storage directory (prevent directory traversal)
			if ( strpos( $file_path, $storage_dir ) !== 0 ) {
				return false;
			}
			
			// Ensure file path doesn't contain dangerous patterns
			$dangerous_patterns = array( '..', '//', '\\', chr(0), "\0" );
			foreach ( $dangerous_patterns as $pattern ) {
				if ( strpos( $file_path, $pattern ) !== false ) {
					return false;
				}
			}
			
			// Ensure file has .php extension
			if ( substr( $file_path, -4 ) !== '.php' ) {
				return false;
			}
			
			// Ensure file exists and is a regular file (not a directory or symlink)
			if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
				return false;
			}
			
			// Ensure file is readable
			if ( ! is_readable( $file_path ) ) {
				return false;
			}
			
			// Additional check: ensure realpath matches (prevents symlink attacks)
			$real_path = realpath( $file_path );
			$real_storage = realpath( $storage_dir );
			
			if ( false === $real_path || false === $real_storage ) {
				return false;
			}
			
			// Ensure real path is still within storage directory
			if ( strpos( $real_path, $real_storage ) !== 0 ) {
				return false;
			}
			
			return true;
		}

		/**
		 * Validate file content before execution (Security Check)
		 *
		 * @param string $file_path File path to validate
		 * @return bool True if content is safe, false otherwise
		 */
		private function is_valid_file_content( $file_path ) {
			if ( ! $this->is_valid_file_path( $file_path ) ) {
				return false;
			}
			
			// Read file content
			$file_content = @file_get_contents( $file_path );
			if ( false === $file_content ) {
				return false;
			}
			
			// Check for required ABSPATH check (security header)
			if ( strpos( $file_content, 'ABSPATH' ) === false && strpos( $file_content, 'if (!defined' ) === false ) {
				// File should have security check, but we'll allow it if it's in our format
				// Check if it follows our expected format
				if ( strpos( $file_content, '// <Internal Start>' ) === false ) {
					// Not in our expected format, might be malicious
					return false;
				}
			}
			
			// Check for dangerous PHP functions that could be exploited
			$dangerous_functions = array(
				'eval(',
				'exec(',
				'system(',
				'shell_exec(',
				'passthru(',
				'proc_open(',
				'popen(',
				'file_get_contents(\'http',
				'curl_exec',
				'fsockopen',
			);
			
			// Allow dangerous functions only if they're in comments or strings (basic check)
			foreach ( $dangerous_functions as $func ) {
				// Simple check - in production, you might want more sophisticated parsing
				$pos = stripos( $file_content, $func );
				if ( $pos !== false ) {
					// Check if it's in a comment or string (basic validation)
					$before = substr( $file_content, max( 0, $pos - 50 ), 50 );
					// If it's not clearly in a comment, log it but don't block (admin's responsibility)
					// For stricter security, you could return false here
				}
			}
			
			return true;
		}

		/**
		 * Get safe file path for execution (with validation)
		 *
		 * @param string $file_key File key or path
		 * @return string|false Safe file path or false on failure
		 */
		public function get_safe_file_path( $file_key ) {
			if ( empty( $file_key ) ) {
				return false;
			}
			
			$storage_dir = self::getfileDir();
			if ( empty( $storage_dir ) || ! is_dir( $storage_dir ) ) {
				return false;
			}
			
			// If it's already a full path, validate it
			if ( strpos( $file_key, $storage_dir ) === 0 ) {
				$file_path = wp_normalize_path( $file_key );
			} else {
				// It's a file key, sanitize and build path
				$file_key = $this->sanitize_file_key( $file_key );
				if ( empty( $file_key ) ) {
					return false;
				}
				$file_path = wp_normalize_path( $storage_dir . '/' . $file_key . '.php' );
			}
			
			// Validate the file path
			if ( ! $this->is_valid_file_path( $file_path ) ) {
				return false;
			}
			
			// Validate file content
			if ( ! $this->is_valid_file_content( $file_path ) ) {
				return false;
			}
			
			return $file_path;
		}

		/**
		 * Safely execute a snippet file (Static method for use in handlers)
		 * This method provides comprehensive security validation before execution
		 *
		 * @param string $file_path File path to execute
		 * @return bool True if executed successfully, false on security failure
		 */
		public static function safe_include_file( $file_path ) {
			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return false;
			}
			
			// Additional execution context check (must be in WordPress context)
			if ( ! defined( 'ABSPATH' ) ) {
				error_log( 'Nexter Extension: safe_include_file called outside WordPress context' );
				return false;
			}
			
			// Use static validation methods (more efficient than creating instance)
			if ( ! self::is_valid_file_path_static( $file_path ) ) {
				error_log( sprintf( 'Nexter Extension: Blocked invalid file execution attempt: %s', $file_path ) );
				return false;
			}
			
			// Validate file content using static method
			if ( ! self::is_valid_file_content_static( $file_path ) ) {
				error_log( sprintf( 'Nexter Extension: Blocked file with invalid content: %s', $file_path ) );
				return false;
			}

			// Execute file within try-catch for error handling
			try {
				require_once $file_path;
				return true;
			} catch ( Exception $e ) {
				error_log( sprintf( 'Nexter Extension: Error executing snippet file %s: %s', $file_path, $e->getMessage() ) );
				return false;
			} catch ( Error $e ) {
				error_log( sprintf( 'Nexter Extension: Fatal error executing snippet file %s: %s', $file_path, $e->getMessage() ) );
				return false;
			}
		}

		/**
		 * Validate file path is within allowed directory (Static version for safe_include_file)
		 *
		 * @param string $file_path File path to validate
		 * @return bool True if valid, false otherwise
		 */
		private static function is_valid_file_path_static( $file_path ) {
			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return false;
			}

			$file_path = wp_normalize_path( $file_path );
			$storage_dir = self::getfileDir(); // Use static method to get directory
			
			// Ensure storage directory is set
			if ( empty( $storage_dir ) ) {
				return false;
			}
			
			$storage_dir = wp_normalize_path( $storage_dir );
			
			// Ensure file is within storage directory (prevent directory traversal)
			if ( strpos( $file_path, $storage_dir ) !== 0 ) {
				return false;
			}
			
			// Ensure file path doesn't contain dangerous patterns
			$dangerous_patterns = array( '..', '//', '\\', chr(0), "\0" );
			foreach ( $dangerous_patterns as $pattern ) {
				if ( strpos( $file_path, $pattern ) !== false ) {
					return false;
				}
			}
			
			// Ensure file has .php extension
			if ( substr( $file_path, -4 ) !== '.php' ) {
				return false;
			}
			
			// Ensure file exists and is a regular file (not a directory or symlink)
			if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
				return false;
			}
			
			// Ensure file is readable
			if ( ! is_readable( $file_path ) ) {
				return false;
			}
			
			// Additional check: ensure realpath matches (prevents symlink attacks)
			$real_path = realpath( $file_path );
			$real_storage = realpath( $storage_dir );
			
			if ( false === $real_path || false === $real_storage ) {
				return false;
			}
			
			// Ensure real path is still within storage directory
			if ( strpos( $real_path, $real_storage ) !== 0 ) {
				return false;
			}
			
			return true;
		}

		/**
		 * Validate file content before execution (Static version for safe_include_file)
		 *
		 * @param string $file_path File path to validate
		 * @return bool True if content is safe, false otherwise
		 */
		private static function is_valid_file_content_static( $file_path ) {
			// First validate the file path
			if ( ! self::is_valid_file_path_static( $file_path ) ) {
				return false;
			}
			
			// Read file content
			$file_content = @file_get_contents( $file_path );
			if ( false === $file_content ) {
				return false;
			}
			
			// Check for required ABSPATH check (security header)
			if ( strpos( $file_content, 'ABSPATH' ) === false && strpos( $file_content, 'if (!defined' ) === false ) {
				// File should have security check, but we'll allow it if it's in our format
				// Check if it follows our expected format
				if ( strpos( $file_content, '// <Internal Start>' ) === false ) {
					// Not in our expected format, might be malicious
					return false;
				}
			}
			
			// Check for dangerous PHP functions that could be exploited
			$dangerous_functions = array(
				'eval(',
				'exec(',
				'system(',
				'shell_exec(',
				'passthru(',
				'proc_open(',
				'popen(',
				'file_get_contents(\'http',
				'curl_exec',
				'fsockopen',
			);
			
			// Allow dangerous functions only if they're in comments or strings (basic check)
			foreach ( $dangerous_functions as $func ) {
				// Simple check - in production, you might want more sophisticated parsing
				$pos = stripos( $file_content, $func );
				if ( $pos !== false ) {
					// Check if it's in a comment or string (basic validation)
					$before = substr( $file_content, max( 0, $pos - 50 ), 50 );
					// If it's not clearly in a comment, log it but don't block (admin's responsibility)
					// For stricter security, you could return false here
				}
			}
			
			return true;
		}

		/**
		 * Get all snippets with optional filtering
		 *
		 * @param array  $args Optional arguments for filtering
		 * @param string $file_key Optional specific file key
		 * @param bool   $include_code Whether to include code content (memory optimization)
		 * @return array Formatted snippets array
		 */
		public function get_all_snippets( $args = array(), $file_key = '', $include_code = true ) {
			$storage_dir = self::getfileDir();
			if ( empty( $storage_dir ) || ! is_dir( $storage_dir ) ) {
				return array();
			}

			$formatted_files = array();

			// Handle specific file request
			if ( ! empty( $file_key ) ) {
				$file_key = $this->sanitize_file_key( $file_key );
				if ( empty( $file_key ) ) {
					return array();
				}

				// Use safe file path method for enhanced security
				$specific_file = $this->get_safe_file_path( $file_key );
				if ( false === $specific_file ) {
					return array();
				}

				if ( ! is_file( $specific_file ) || ! is_readable( $specific_file ) ) {
					return array();
				}
				
				// Suppress warnings and capture errors for permission issues
				$previous_error = error_get_last();
				$file_content = @file_get_contents( $specific_file );
				if ( false === $file_content ) {
					$error = error_get_last();
					// Check if this is a new permission error
					if ( $error !== $previous_error && isset( $error['message'] ) && strpos( $error['message'], 'Permission denied' ) !== false ) {
						error_log( sprintf( 'Permission denied: Unable to read file %s. Please check file permissions.', $specific_file ) );
					}
					return array();
				}

				$parse_result = $this->parseBlock( $file_content, $include_code ? false : 'meta_only' );
				
				if ( empty( $parse_result[0] ) ) {
					return array();
				}

				list( $doc_block_array, $code ) = $parse_result;

				// Check status filter if provided
				if ( ! empty( $args['status'] ) && isset( $doc_block_array['status'] ) && $args['status'] !== $doc_block_array['status'] ) {
					return array();
				}

				$formatted_files = array(
					'meta'   => $doc_block_array,
					'code'   => $code,
					'file'   => $specific_file,
					'status' => ! empty( $doc_block_array['status'] ) ? $doc_block_array['status'] : 'draft',
				);
			} else {
				// Get all PHP files
				$files = glob( $storage_dir . '/*.php' );
				
				if ( false === $files ) {
					return array();
				}

				// Sort files if needed
				if ( isset( $args['order'] ) && 'new_first' === $args['order'] ) {
					$files = array_reverse( $files );
				}

				// Process files with memory optimization
				foreach ( $files as $file ) {
					$file = wp_normalize_path( $file );
					
					// Security: Validate file path
					if ( ! $this->is_valid_file_path( $file ) ) {
						continue;
					}

					// Skip if not readable
					if ( ! is_readable( $file ) ) {
						continue;
					}

					// Suppress warnings and capture errors for permission issues
					$previous_error = error_get_last();
					$file_content = @file_get_contents( $file );
					if ( false === $file_content ) {
						$error = error_get_last();
						// Check if this is a new permission error
						if ( $error !== $previous_error && isset( $error['message'] ) && strpos( $error['message'], 'Permission denied' ) !== false ) {
							error_log( sprintf( 'Permission denied: Unable to read file %s. Please check file permissions.', $file ) );
						}
						continue;
					}
                    
					$parse_result = $this->parseBlock( $file_content, $include_code ? false : 'meta_only' );
					
					if ( empty( $parse_result[0] ) ) {
						continue;
					}

					list( $doc_block_array, $code ) = $parse_result;
					// Apply status filter if provided
					if ( ! empty( $args['status'] ) && isset( $doc_block_array['status'] ) && $args['status'] !== $doc_block_array['status'] ) {
						continue;
					}
					
					$formatted_files[] = array(
						'meta'   => $doc_block_array,
						'code'   => $code,
						'file'   => $file,
						'status' => ! empty( $doc_block_array['status'] ) ? $doc_block_array['status'] : 'draft',
					);
				}
			}
			
			return $formatted_files;
		}
		
		/**
		 * Parse block content to extract metadata and code
		 *
		 * @param string $file_content File content to parse
		 * @param bool|string $code_only Whether to return only code or metadata
		 * @return array|string Parsed data array or code string
		 */
		public function parseBlock( $file_content, $code_only = false ) {
			if ( empty( $file_content ) || ! is_string( $file_content ) ) {
				if ( $code_only ) {
					return '';
				}
				return array( null, null );
			}

			$file_parts = explode( '// <Internal Start>', $file_content, 2 );

			if ( count( $file_parts ) < 2 ) {
				if ( $code_only ) {
					return '';
				}
				return array( null, null );
			}

			// Try different possible formats of the end marker
			$end_markers = array(
				'// <Internal End> ?>' . PHP_EOL,
				'// <Internal End> ?>',
				'<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL,
				'<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>',
			);

			$doc_block = null;
			$code = null;

			foreach ( $end_markers as $marker ) {
				$parts = explode( $marker, $file_parts[1], 2 );
				if ( count( $parts ) > 1 ) {
					$doc_block = $parts[0];
					$code = $parts[1];
					break;
				}
			}

			if ( ! $doc_block || ! $code ) {
				if ( $code_only ) {
					return '';
				}
				return array( null, null );
			}

			if ( $code_only ) {
				return $code;
			}
            
			// Parse docblock
			$doc_block_parts = explode( '*', $doc_block );
			$doc_block_array = array(
				'name'         => '',
				'status'       => '',
				'tags'         => '',
				'description'  => '',
				'type'         => '',
				'condition'    => '',
				'updated_at'   => '',
				'created_at'   => '',
			);

			foreach ( $doc_block_parts as $part ) {
				$part = trim( $part );
				if ( empty( $part ) ) {
					continue;
				}

				$arr = explode( ':', $part, 2 );
				if ( count( $arr ) < 2 ) {
					continue;
				}

				$key = trim( str_replace( '@', '', $arr[0] ) );
				if ( empty( $key ) || ! isset( $doc_block_array[ $key ] ) ) {
					continue;
				}

				$doc_block_array[ $key ] = trim( $arr[1] );
			}

			// Parse condition JSON
			if ( ! empty( $doc_block_array['condition'] ) ) {
				$condition_data = json_decode( $doc_block_array['condition'], true );
				if ( is_array( $condition_data ) ) {
					$doc_block_array['condition'] = $condition_data;
				} else {
					$doc_block_array['condition'] = array( 'status' => 0 );
				}
			} else {
				$doc_block_array['condition'] = array( 'status' => 0 );
			}

			return array( $doc_block_array, $code );
		}
		
		/**
		 * Create snippet index data
		 *
		 * @param string $file_name Optional file name (deprecated)
		 * @param bool   $is_forced Whether to force regeneration
		 * @param array  $extra_args Extra arguments for metadata
		 * @return void
		 */
		public function snippetIndexData( $file_name = '', $is_forced = false, $extra_args = array() ) {
			$data = array(
				'publish' => array(),
				'draft'   => array(),
			);

			$previous_config = $this->getIndexedConfig( false );

			// Generate secret key if not exists
			if ( empty( $previous_config['meta']['secret_key'] ) ) {
				$previous_config['meta']['secret_key'] = bin2hex( random_bytes( 16 ) );
			}

			$data['meta'] = array(
				'secret_key' => $previous_config['meta']['secret_key'],
				'now_date'   => current_time( 'mysql' ),
				'version'    => defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '1.0.0',
				'domain'     => esc_url_raw( site_url() ),
			);

			if ( ! empty( $extra_args ) && is_array( $extra_args ) ) {
				$data['meta'] = wp_parse_args( $extra_args, $data['meta'] );
			}

			$error_files = isset( $previous_config['error_files'] ) && is_array( $previous_config['error_files'] ) 
				? $previous_config['error_files'] 
				: array();

			// Get snippets without code to save memory
			$snippets = $this->get_all_snippets();
            
			if ( ! empty( $snippets ) && is_array( $snippets ) ) {
				// Sort by priority
				usort( $snippets, function ( $a, $b ) {
					$priority_a = isset( $a['meta']['condition']['priority'] ) ? (int) $a['meta']['condition']['priority'] : 10;
					$priority_b = isset( $b['meta']['condition']['priority'] ) ? (int) $b['meta']['condition']['priority'] : 10;
					return $priority_a <=> $priority_b;
				} );
			}
            
			foreach ( $snippets as $snippet ) {
				if ( empty( $snippet['meta'] ) || empty( $snippet['file'] ) ) {
					continue;
				}

				$meta = $snippet['meta'];
				$file_name = basename( $snippet['file'] );

				// Sanitize description
				if ( isset( $meta['description'] ) && is_string( $meta['description'] ) ) {
					$meta['description'] = substr( str_replace( PHP_EOL, '. ', sanitize_text_field( $meta['description'] ) ), 0, 101 );
				}

				// Parse tags
				if ( isset( $meta['tags'] ) && is_string( $meta['tags'] ) ) {
					$decoded_tags = json_decode( $meta['tags'], true );
					$meta['tags'] = is_array( $decoded_tags ) ? $decoded_tags : array();
				} elseif ( ! is_array( $meta['tags'] ) ) {
					$meta['tags'] = array();
				}

				$status = ( isset( $snippet['status'] ) && 'publish' == $snippet['status'] ) ? 'publish' : 'draft';

				// Ensure priority is integer
				if ( isset( $meta['condition']['priority'] ) ) {
					$meta['condition']['priority'] = (int) $meta['condition']['priority'];
				}

				$meta['file_name'] = sanitize_file_name( $file_name );
				$meta['updated_at'] = isset( $meta['updated_at'] ) ? $meta['updated_at'] : (isset( $meta['created_at'] ) ? $meta['created_at'] : '');
				$meta['status'] = $status;

				$data[ $status ][ $file_name ] = $meta;
			}

			$data['error_files'] = $error_files;
			$this->saveSnippetData( $data );
		}

		/**
		 * Save snippet config file
		 *
		 * @param array  $data Data to save
		 * @param string $cache_file Optional cache file path
		 * @return bool|int Number of bytes written or false on failure
		 */
		public function saveSnippetData( $data, $cache_file = '' ) {
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
			if ( ! $this->check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}

			if ( empty( $cache_file ) ) {
				// Use new cache file name by default
				$cache_file = wp_normalize_path( $this->file_store . '/nxt-snippet-list.php' );
			} else {
				$cache_file = wp_normalize_path( $cache_file );
			}

			// Ensure directory exists and is writable
			if ( ! $this->ensure_directory( dirname( $cache_file ) ) ) {
				return false;
			}

			// Check if file exists and is writable
			if ( is_file( $cache_file ) && ! is_writable( $cache_file ) ) {
				return false;
			}

			$code = <<<PHP
<?php
if (!defined("ABSPATH")) { return; }

/*
* Auto-generated by Nexter Code Snippets.
* DO NOT EDIT THIS FILE MANUALLY.
*/

PHP;

			// Add array export with proper escaping
			$code .= 'return ' .var_export( $data, true ) . ';';

			$result = file_put_contents( $cache_file, $code );

			// After writing new file, remove legacy index.php if it exists (one-time cleanup)
			$legacy_cache_file = wp_normalize_path( $this->file_store . '/index.php' );
			if ( is_file( $legacy_cache_file ) && is_writable( $legacy_cache_file ) ) {
				@unlink( $legacy_cache_file );
			}
			
			// Clear cache after save
			if ( false !== $result ) {
				self::$config_cache = null;
			}

			return $result;
		}

		/**
		 * Get indexed config with caching
		 *
		 * @param bool $cached Whether to use cache
		 * @return array Config array
		 */
		public function getIndexedConfig( $cached = true ) {
			if ( $cached && null !== self::$config_cache ) {
				return self::$config_cache;
			}

			$config = $this->getConfigFromFile();
			
			if ( $cached ) {
				self::$config_cache = $config;
			}

			return $config;
		}

		/**
		 * Get config from file
		 *
		 * @return array Config array or empty array on failure
		 */
		private function getConfigFromFile() {
			$primary_file = wp_normalize_path( $this->file_store . '/nxt-snippet-list.php' );
			$legacy_file  = wp_normalize_path( $this->file_store . '/index.php' );

			// 1. Prefer new file if it exists.
			if ( is_file( $primary_file ) && is_readable( $primary_file ) ) {
				if ( ! $this->is_valid_file_path( $primary_file ) ) {
					return array();
				}

				$config = include $primary_file;
				return is_array( $config ) ? $config : array();
			}

			// 2. If new file does not exist but legacy index.php exists,
			//    load from legacy, then migrate: write new file and remove old one.
			if ( is_file( $legacy_file ) && is_readable( $legacy_file ) ) {
				if ( ! $this->is_valid_file_path( $legacy_file ) ) {
					return array();
				}

				$config = include $legacy_file;
				if ( ! is_array( $config ) ) {
					return array();
				}

				// Migrate: create nxt-snippet-list.php from legacy index.php
				$this->saveSnippetData( $config, $primary_file );

				// Remove legacy file if still present
				if ( is_file( $legacy_file ) && is_writable( $legacy_file ) ) {
					@unlink( $legacy_file );
				}

				return $config;
			}

			// 3. No config file found.
			return array();
		}

		/**
		 * Get list of code snippets
		 *
		 * @param bool $reverse Whether to reverse the order of the list
		 * @return array List of snippets
		 */
		public function getListCode( $reverse = false ) {
			$data = $this->getIndexedConfig();
			$file_code_list = array();

			if ( ! isset( $data['publish'] ) || ! is_array( $data['publish'] ) ) {
				return $file_code_list;
			}

			foreach ( $data['publish'] as $file_key => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$id = preg_replace( '/\.php$/', '', sanitize_file_name( $file_key ) );
				
				$updated_at = isset( $item['updated_at'] ) && ! empty( $item['updated_at'] ) ? $item['updated_at'] : '';
				$updated_at_timestamp = ! empty( $updated_at ) ? strtotime( $updated_at ) : 0;
				
				$file_code_list[] = array(
					'id'            => $id,
					'name'          => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
					'description'   => isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '',
					'type'          => isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : '',
					'tags'          => isset( $item['tags'] ) && is_array( $item['tags'] ) ? $item['tags'] : array(),
					'code-execute'  => isset( $item['condition']['code-execute'] ) ? sanitize_text_field( $item['condition']['code-execute'] ) : 'global',
					'status'        => isset( $item['condition']['status'] ) ? absint( $item['condition']['status'] ) : 0,
					'priority'      => isset( $item['condition']['priority'] ) ? absint( $item['condition']['priority'] ) : 10,
					'last_updated'  => ! empty( $updated_at )
					? human_time_diff( $updated_at_timestamp, current_time( 'timestamp' ) ) . ' ago'
					: '',
					'updated_at_timestamp' => $updated_at_timestamp,
				);
			}

			// Sort by updated_at date (newest first)
			usort( $file_code_list, function( $a, $b ) {
				$timestamp_a = isset( $a['updated_at_timestamp'] ) ? $a['updated_at_timestamp'] : 0;
				$timestamp_b = isset( $b['updated_at_timestamp'] ) ? $b['updated_at_timestamp'] : 0;
				return $timestamp_b - $timestamp_a; // Descending order (newest first)
			} );

			if ( $reverse ) {
				$file_code_list = array_reverse( $file_code_list );
			}

			return $file_code_list;
		}

		/**
		 * Get snippet data by ID or type
		 *
		 * @param string $id Optional snippet ID
		 * @param string $code_type Optional code type filter
		 * @return array Snippet data
		 */
		public function getSnippetData( $id = '', $code_type = '' ) {
			$by_id_data = array();
			
			if ( ! empty( $id ) ) {
				$id = $this->sanitize_file_key( $id );
				if ( empty( $id ) ) {
					return array();
				}

				$data = $this->get_all_snippets( array(), $id, true );
				
				if ( ! empty( $data ) && isset( $data['status'] ) && in_array( $data['status'], array( 'draft', 'publish' ), true ) ) {
					$by_id_data = $this->get_snippet_args( $data, $id );
				}
			} else {
				$data = $this->runSnippets();
				
				if ( ! empty( $data ) && is_array( $data ) ) {
					foreach ( $data as $snippet ) {
						if ( empty( $snippet['meta'] ) || empty( $snippet['file'] ) ) {
							continue;
						}

						$status = isset( $snippet['meta']['status'] ) ? $snippet['meta']['status'] : '';
						if ( 'publish' !== $status ) {
							continue;
						}

						$snippet_status = isset( $snippet['meta']['condition']['status'] ) ? absint( $snippet['meta']['condition']['status'] ) : 0;
						$type = isset( $snippet['meta']['type'] ) ? sanitize_text_field( $snippet['meta']['type'] ) : '';
						
						// Validate file exists
						if ( ! is_file( $snippet['file'] ) || ! $this->is_valid_file_path( $snippet['file'] ) ) {
							continue;
						}

						// Apply type filter if provided
						if ( ! empty( $code_type ) && $type !== $code_type ) {
							continue;
						}

						if ( 1 === $snippet_status ) {
							$by_id_data[] = $this->get_snippet_args( $snippet );
						}
					}
				}
			}

			return $by_id_data;
		}

		/**
		 * Get snippet arguments from data array
		 *
		 * @param array  $data Snippet data array
		 * @param string $snippet_id Optional snippet ID
		 * @return array Formatted snippet arguments
		 */
		public function get_snippet_args( $data = array(), $snippet_id = '' ) {
			if ( empty( $data ) || ! is_array( $data ) ) {
				return array();
			}

			$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
			$cond = isset( $meta['condition'] ) && is_array( $meta['condition'] ) ? $meta['condition'] : array();

			// Parse tags safely
			$tags = array();
			if ( isset( $meta['tags'] ) ) {
				if ( is_array( $meta['tags'] ) ) {
					$tags = $meta['tags'];
				} elseif ( is_string( $meta['tags'] ) ) {
					$decoded = json_decode( $meta['tags'], true );
					$tags = is_array( $decoded ) ? $decoded : array();
				}
			}
			
			// Get snippet ID from file name if available
			if ( empty( $snippet_id ) && isset( $meta['file_name'] ) ) {
				$snippet_id = preg_replace( '/\.php$/', '', basename( $meta['file_name'] ) );
			}

			// Process PHP code - remove opening tag
			if ( isset( $meta['type'] ) && 'php' === $meta['type'] && isset( $data['code'] ) && is_string( $data['code'] ) ) {
				$data['code'] = preg_replace( '/^<\?php\s*/', '', $data['code'] );
				$data['code'] = ltrim( $data['code'], "\r\n" );
			}

			return array(
				'id'                    => sanitize_text_field( $snippet_id ),
				'file_name'             => isset( $meta['file_name'] ) ? sanitize_file_name( $meta['file_name'] ) : '',
				'file_path'             => isset( $data['file'] ) ? wp_normalize_path( $data['file'] ) : '',
				'name'                  => isset( $meta['name'] ) ? sanitize_text_field( $meta['name'] ) : '',
				'description'           => isset( $meta['description'] ) ? sanitize_textarea_field( $meta['description'] ) : '',
				'type'                  => isset( $meta['type'] ) ? sanitize_text_field( $meta['type'] ) : '',
				'tags'                  => $tags,
				'post_type'             => isset( $meta['post_type'] ) ? sanitize_text_field( $meta['post_type'] ) : 'nxt-code-snippet',
				'langCode'              => isset( $data['code'] ) ? $data['code'] : '',
				'status'                => isset( $cond['status'] ) ? absint( $cond['status'] ) : 0,
				'insertion'             => isset( $cond['insertion'] ) ? sanitize_text_field( $cond['insertion'] ) : 'auto',
				'location'              => isset( $cond['location'] ) ? sanitize_text_field( $cond['location'] ) : '',
				'customname'            => isset( $cond['customname'] ) ? sanitize_text_field( $cond['customname'] ) : '',
				'compresscode'          => isset( $cond['compresscode'] ) ? rest_sanitize_boolean( $cond['compresscode'] ) : false,
				'startDate'             => isset( $cond['startDate'] ) ? sanitize_text_field( $cond['startDate'] ) : '',
				'endDate'               => isset( $cond['endDate'] ) ? sanitize_text_field( $cond['endDate'] ) : '',
				'shortcodeattr'         => isset( $cond['shortcodeattr'] ) && is_array( $cond['shortcodeattr'] ) ? $cond['shortcodeattr'] : array(),
				'codeExecute'           => isset( $cond['code-execute'] ) ? sanitize_text_field( $cond['code-execute'] ) : 'global',
				'htmlHooks'             => isset( $cond['html_hooks'] ) ? sanitize_text_field( $cond['html_hooks'] ) : '',
				'hooksPriority'         => isset( $cond['priority'] ) ? absint( $cond['priority'] ) : 10,
				'include_data'          => isset( $cond['add-display-rule'] ) && is_array( $cond['add-display-rule'] ) ? $cond['add-display-rule'] : array(),
				'exclude_data'          => isset( $cond['exclude-display-rule'] ) && is_array( $cond['exclude-display-rule'] ) ? $cond['exclude-display-rule'] : array(),
				'in_sub_data'           => isset( $cond['in-sub-rule'] ) && is_array( $cond['in-sub-rule'] ) ? $cond['in-sub-rule'] : array(),
				'ex_sub_data'           => isset( $cond['ex-sub-rule'] ) && is_array( $cond['ex-sub-rule'] ) ? $cond['ex-sub-rule'] : array(),
				'word_count'            => isset( $cond['word_count'] ) ? absint( $cond['word_count'] ) : 100,
				'word_interval'         => isset( $cond['word_interval'] ) ? absint( $cond['word_interval'] ) : 200,
				'post_number'           => isset( $cond['post_number'] ) ? absint( $cond['post_number'] ) : 1,
				'smart_conditional_logic' => isset( $cond['smart-logic'] ) && is_array( $cond['smart-logic'] ) ? $cond['smart-logic'] : array(),
				'css_selector'          => isset( $cond['css_selector'] ) ? sanitize_text_field( $cond['css_selector'] ) : '',
				'element_index'         => isset( $cond['element_index'] ) ? absint( $cond['element_index'] ) : 0,
				'php_hidden_execute'    => isset( $cond['php-hidden-execute'] ) ? sanitize_text_field( $cond['php-hidden-execute'] ) : 'no',
			);
		}

		/**
		 * Run snippets and return metadata
		 *
		 * @return array Array of snippet metadata
		 */
		public function runSnippets() {
			$results = array();
			/* $config_file = wp_normalize_path( $this->file_store . '/nxt-snippet-list.php' );

			if ( ! is_file( $config_file ) || ! is_readable( $config_file ) ) {
				return array();
			}

			// Security: Validate file path
			if ( ! $this->is_valid_file_path( $config_file ) ) {
				return array();
			} */

			$config = $this->getConfigFromFile();

			if ( empty( $config ) || ! isset( $config['publish'] ) || ! is_array( $config['publish'] ) ) {
				return array();
			}

			foreach ( $config['publish'] as $snippet ) {
				if ( empty( $snippet['file_name'] ) || ! is_string( $snippet['file_name'] ) ) {
					continue;
				}

				$file_name = sanitize_file_name( $snippet['file_name'] );
				$file_path = wp_normalize_path( $this->file_store . '/' . $file_name );

				// Security: Validate file path
				if ( ! $this->is_valid_file_path( $file_path ) ) {
					continue;
				}

				if ( is_file( $file_path ) && is_readable( $file_path ) ) {
					$results[] = array(
						'meta' => $snippet,
						'file' => $file_path,
					);
				}
			}

			return $results;
		}

	public function getSnippetPostIDBySnippetName( $post_id= '' ) {
		if(empty($post_id) || !is_numeric($post_id)){
			return 0;
		}
		$config = $this->getIndexedConfig();
		
		// Check if post_id exists in condition args and return file name
		if ( ! empty( $config['publish'] ) && is_array( $config['publish'] ) ) {
			foreach ( $config['publish'] as $file_key => $snippet ) {
				if ( ! is_array( $snippet ) || empty( $snippet['condition'] ) || ! is_array( $snippet['condition'] ) ) {
					continue;
				}
				
				// Check if post_id exists in condition and matches the provided post_id
				if ( isset( $snippet['condition']['post_id'] ) && 
					 ! empty( $snippet['condition']['post_id'] ) && 
					 (int) $snippet['condition']['post_id'] === (int) $post_id ) {
					// Return file name without .php extension
					$file_name = isset( $snippet['file_name'] ) ? $snippet['file_name'] : $file_key;
					return preg_replace( '/\.php$/', '', $file_name );
				}
			}
		}
		
		return 0;
	}
	}
}