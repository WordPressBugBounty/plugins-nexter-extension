<?php
/**
 * Nexter Default Import Code Snippets
 *
 * @package Nexter Extensions
 * @since 4.1.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Code_Snippets_Import_Data' ) ) {

	class Nexter_Code_Snippets_Import_Data {

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 * Option name for tracking import status
		 *
		 * @var string
		 */
		private static $import_option = 'nexter_snippets_imported';

		/**
		 * Initiator
		 *
		 * @return Nexter_Code_Snippets_Import_Data
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		public function __construct() {
			// Hook later in the init process to ensure WordPress is fully loaded
			add_action( 'init', array( $this, 'maybe_import_data_code_snippet' ), 20 );
		}

		/**
		 * Check if WP_CONTENT_DIR is writable (pre-check for file operations)
		 *
		 * @return bool True if writable, false otherwise
		 */
		private static function check_content_dir_writable() {
			// Use is_writable() as wp_is_writable() may not exist in all WordPress versions
			if ( function_exists( 'wp_is_writable' ) ) {
				return wp_is_writable( WP_CONTENT_DIR );
			}
			return is_writable( WP_CONTENT_DIR );
		}

		/**
		 * Check if we need to import snippets and handle the import
		 */
		public function maybe_import_data_code_snippet() {
			// Check if snippets have already been imported
			if ( get_option( self::$import_option ) ) {
				return;
			}
			
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting any file operations
			if ( ! self::check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}
			
			// Make sure the file based class exists
			if ( ! class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
				$file_path = NEXTER_EXT_DIR . 'include/classes/load-code-snippet/nexter-code-file-snippet.php';
				if ( file_exists( $file_path ) ) {
					require_once $file_path;
				} else {
					return; // Exit if required file doesn't exist
				}
			}
			$this->import_data_code_snippet();
		}

		/**
		 * Import default code snippets
		 *
		 * @return void
		 */
		private function import_data_code_snippet() {
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file operations
			if ( ! self::check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}

			// Mark as imported before processing to prevent duplicate imports
			update_option( self::$import_option, true, 'yes' );

			$file_based = new Nexter_Code_Snippets_File_Based();
			$storage_dir = Nexter_Code_Snippets_File_Based::getfileDir();
			
			// Validate and ensure directory exists with proper permissions
			if ( ! $this->ensure_storage_directory( $storage_dir ) ) {
				// Log error but don't break execution
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Nexter Extension: Failed to create snippet storage directory' );
				}
				return;
			}

			// Get default snippets data (lazy load)
			$default_data = $this->get_default_snippets_data();

			if ( empty( $default_data ) || ! is_array( $default_data ) ) {
				return;
			}

			// Get initial file count efficiently
			$file_count = $this->get_existing_file_count( $storage_dir );

			// Pre-calculate environment data once to avoid repetitive calls
			$env_data = array(
				'user_id'        => absint( get_current_user_id() ) ?: 1,
				'date'           => gmdate( 'Y-m-d H:i:s' ),
				'db_created_msg' => 'Snippet Created @ ' . current_time( 'mysql' ),
			);

			$imported_count = 0;

			// Process snippets one at a time to reduce memory usage
			foreach ( $default_data as $snippet ) {
				$file_count++;
				if ( self::import_snippet_to_file( $snippet, $file_count, $storage_dir, $env_data ) ) {
					$imported_count++;
				}
				
				// Clear memory after each iteration
				unset( $snippet );
			}
			
			// Clear default data from memory
			unset( $default_data, $env_data );

			// Rebuild index only if snippets were imported
			if ( $imported_count > 0 ) {
				$file_based->snippetIndexData();
			}
		}

		/**
		 * Ensure storage directory exists and is writable
		 *
		 * @param string $storage_dir Directory path
		 * @return bool True if directory is ready, false otherwise
		 */
		private function ensure_storage_directory( $storage_dir ) {
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting any directory operations
			if ( ! self::check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}

			if ( empty( $storage_dir ) ) {
				return false;
			}

			$storage_dir = wp_normalize_path( $storage_dir );

			// Check if directory exists
			if ( ! is_dir( $storage_dir ) ) {
				// Check parent directory is writable before creating
				$parent_dir = dirname( $storage_dir );
				if ( ! is_writable( $parent_dir ) ) {
					return false;
				}

				// Create directory with proper permissions
				if ( ! wp_mkdir_p( $storage_dir ) ) {
					return false;
				}
			}

			// Verify directory is writable
			if ( ! is_writable( $storage_dir ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Get count of existing PHP files efficiently
		 *
		 * @param string $storage_dir Storage directory path
		 * @return int File count
		 */
		private function get_existing_file_count( $storage_dir ) {
			$storage_dir = wp_normalize_path( $storage_dir );
			
			if ( ! is_dir( $storage_dir ) || ! is_readable( $storage_dir ) ) {
				return 0;
			}

			$files = glob( $storage_dir . '/*.php' );
			
			return false !== $files ? count( $files ) : 0;
		}

		/**
		 * Get default snippets data
		 *
		 * @return array Array of default snippet data
		 */
		private function get_default_snippets_data() {
			return array(
				array(
					'title'        => esc_html__( 'Disable Emojis for Faster Loading', 'nexter-extension' ),
					'type'         => 'php',
					'code'         => "remove_action('wp_head', 'print_emoji_detection_script', 7);\n\tremove_action('wp_print_styles', 'print_emoji_styles');",
					'code-execute' => 'front-end',
					'desc'         => esc_html__( 'Disable WordPress emoji scripts to improve page speed.', 'nexter-extension' ),
					'tags'         => array( 'Performance', 'Optimization', 'Frontend' ),
				),
				array(
					'title'        => esc_html__( 'Add Google Analytics Tracking Code', 'nexter-extension' ),
					'type'         => 'php',
					'code'         => "add_action('wp_head', function() { ?>\n<!-- Replace with your Google Analytics Code -->\n<script async src='https://www.googletagmanager.com/gtag/js?id=YOUR-ID'></script>\n<script>\n\twindow.dataLayer = window.dataLayer || [];\n\tfunction gtag(){dataLayer.push(arguments);}\n\tgtag('js', new Date());\n\tgtag('config', 'YOUR-ID');\n</script>\n<?php });",
					'code-execute' => 'front-end',
					'desc'         => esc_html__( 'Insert Google Analytics script directly without extra plugins.', 'nexter-extension' ),
					'tags'         => array( 'Analytics', 'Tracking', 'Frontend' ),
				),
				array(
					'title'        => esc_html__( 'Limit Post Revisions to Optimize Database', 'nexter-extension' ),
					'type'         => 'php',
					'code'         => "define('WP_POST_REVISIONS', 5);",
					'code-execute' => 'global',
					'desc'         => esc_html__( 'Restrict number of saved post revisions to reduce database size.', 'nexter-extension' ),
					'tags'         => array( 'Database', 'Optimization', 'Performance' ),
				),
				array(
					'title'        => esc_html__( 'Customize Login Logo Link URL', 'nexter-extension' ),
					'type'         => 'php',
					'code'         => "function custom_login_url() {\n\treturn home_url();\n}\nadd_filter('login_headerurl', 'custom_login_url');",
					'code-execute' => 'front-end',
					'desc'         => esc_html__( 'Change WordPress login logo URL to your site homepage.', 'nexter-extension' ),
					'tags'         => array( 'Branding', 'Login-Page', 'Frontend' ),
				),
				array(
					'title'        => esc_html__( 'Disable Gutenberg Editor (Use Classic Editor)', 'nexter-extension' ),
					'type'         => 'php',
					'code'         => "add_filter('use_block_editor_for_post', '__return_false');",
					'code-execute' => 'front-end',
					'desc'         => esc_html__( 'Disable Gutenberg block editor and enable classic editor experience.', 'nexter-extension' ),
					'tags'         => array( 'Editor', 'Backend', 'Classic' ),
				),
			);
		}

		/**
		 * Import snippet to file
		 */
		private static function import_snippet_to_file( $snippet, $file_count, $storage_dir, $env_data ) {
			// Pre-check: Ensure WP_CONTENT_DIR is writable before attempting file write
			if ( ! self::check_content_dir_writable() ) {
				wp_die(
					'File-based snippets require write access. This environment restricts file creation.',
					'Nexter Snippets'
				);
			}

			// Validate input
			if ( empty( $snippet['title'] ) || empty( $snippet['code'] ) || ! is_string( $snippet['title'] ) || ! is_string( $snippet['code'] ) ) {
				return false;
			}

			$title = sanitize_text_field( $snippet['title'] );
			
			if ( empty( $title ) ) {
				return false;
			}

			// Generate safe filename
			$file_name = self::generate_safe_filename( $title, $file_count, $storage_dir );
			
			if ( empty( $file_name ) ) {
				return false;
			}

			$file_path = wp_normalize_path( $storage_dir . '/' . $file_name );

			// Security: Validate file path is within storage directory
			$normalized_storage = wp_normalize_path( $storage_dir );
			if ( strpos( $file_path, $normalized_storage ) !== 0 ) {
				return false;
			}

			// Construct metadata
			$meta_data = self::build_snippet_metadata( $snippet, $env_data );

			// Generate DocBlock
			$doc_block_string = self::parseInputMeta( $meta_data, true, $env_data['db_created_msg'] );

			if ( empty( $doc_block_string ) ) {
				return false;
			}

			// Prepare code with proper formatting
			$code = self::prepare_snippet_code( $snippet['code'], $meta_data['type'] );

			if ( empty( $code ) ) {
				return false;
			}
			
			// Write file with error handling
			$full_code = $doc_block_string . $code;
			$result = file_put_contents( $file_path, $full_code, LOCK_EX );

			// Clear variables from memory
			unset( $doc_block_string, $code, $full_code, $meta_data );

			return false !== $result;
		}

		/**
		 * Generate safe filename for snippet
		 */
		private static function generate_safe_filename( $title, $file_count, $storage_dir ) {
			// Get first 4 words of title for filename
			$name_arr = explode( ' ', $title, 5 );
			if ( count( $name_arr ) > 4 ) {
				array_pop( $name_arr ); // Remove 5th element if exists
			}
			$file_title = implode( ' ', $name_arr );

			$file_title = sanitize_title( $file_title, 'snippet' );
			
			if ( empty( $file_title ) ) {
				$file_title = 'snippet';
			}

			$file_name = absint( $file_count ) . '-' . $file_title . '.php';
			$file_name = sanitize_file_name( $file_name );
			
			$file_path = wp_normalize_path( $storage_dir . '/' . $file_name );

			// Check if file exists and generate unique name if needed
			$max_attempts = 10;
			$attempt = 0;
			
			while ( is_file( $file_path ) && $attempt < $max_attempts ) {
				$attempt++;
				$unique_suffix = bin2hex( random_bytes( 2 ) );
				$file_name = absint( $file_count ) . '-' . $file_title . '-' . $unique_suffix . '.php';
				$file_name = sanitize_file_name( $file_name );
				$file_path = wp_normalize_path( $storage_dir . '/' . $file_name );
			}

			if ( $attempt >= $max_attempts ) {
				return '';
			}

			return $file_name;
		}

		/**
		 * Build snippet metadata array
		 */
		private static function build_snippet_metadata( $snippet, $env_data ) {
			$type = isset( $snippet['type'] ) && ! empty( $snippet['type'] ) 
				? sanitize_text_field( $snippet['type'] ) 
				: 'php';

			$code_execute = isset( $snippet['code-execute'] ) && ! empty( $snippet['code-execute'] )
				? sanitize_text_field( $snippet['code-execute'] )
				: 'global';

			$meta_data = array(
				'name'        => sanitize_text_field( $snippet['title'] ),
				'description' => isset( $snippet['desc'] ) ? sanitize_textarea_field( $snippet['desc'] ) : '',
				'tags'        => isset( $snippet['tags'] ) && is_array( $snippet['tags'] ) 
					? array_map( 'sanitize_text_field', $snippet['tags'] ) 
					: array(),
				'type'        => $type,
				'status'      => 'publish',
				'created_by'  => absint( $env_data['user_id'] ),
				'created_at'  => sanitize_text_field( $env_data['date'] ),
				'updated_at'  => sanitize_text_field( $env_data['date'] ),
				'updated_by'  => absint( $env_data['user_id'] ),
				'condition'   => array(
					'status' => 0,
					'priority' => 10,
					'code-execute' => $code_execute,
				),
			);

			// PHP Hidden Execute (specific to PHP type)
			if ( 'php' == $type ) {
				$meta_data['condition']['php-hidden-execute'] = 'yes';
			}

			return $meta_data;
		}

		/**
		 * Prepare snippet code with proper formatting
		 */
		private static function prepare_snippet_code( $code, $type ) {
			if ( empty( $code ) || ! is_string( $code ) ) {
				return '';
			}

			// Ensure PHP code is properly formatted
			if ( 'php' === $type ) {
				// Remove <?php if present at start
				$code = preg_replace( '/^<\?php\s*/', '', $code );
				$code = ltrim( $code, "\r\n" );
				$code = '<?php' . PHP_EOL . $code;
			}

			return $code;
		}
		
		/**
		 * Sanitize meta value for docblock
		 */
		private static function sanitizeMetaValue( $value ) {
			if ( is_numeric( $value ) ) {
				return $value;
			}

			if ( empty( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) && false !== strpos( $value, '*/' ) ) {
				$value = str_replace( '*/', '', $value );
			}

			return $value;
		}

		/**
		 * Parse input metadata to docblock format
		 */
		private static function parseInputMeta( $meta_data, $convert_string = false, $default_name = '' ) {
			$meta_defaults = array(
				'name'         => ! empty( $default_name ) ? sanitize_text_field( $default_name ) : '',
				'description'  => '',
				'tags'         => '',
				'type'         => 'php',
				'status'       => 'draft',
				'created_by'   => 1,
				'created_at'   => '',
				'updated_at'   => '',
				'updated_by'   => 1,
				'condition'    => array(
					'status' => 0,
					'priority' => 10,
				),
			);

			$meta_data = wp_parse_args( $meta_data, $meta_defaults );

			if ( ! $convert_string ) {
				return $meta_data;
			}

			$doc_block_string = '<?php' . PHP_EOL . '// <Internal Start>' . PHP_EOL . '/*' . PHP_EOL . '*';

			foreach ( $meta_data as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$doc_block_string .= PHP_EOL . '* @' . sanitize_key( $key ) . ': ' . self::sanitizeMetaValue( $value );
			}

			$doc_block_string .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL . '<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL;

			return $doc_block_string;
		}
	}
}

Nexter_Code_Snippets_Import_Data::get_instance();
