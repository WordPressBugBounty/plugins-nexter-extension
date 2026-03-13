<?php
/**
 * Image Upload Optimisation Extension
 *
 * @since 4.2.0
 */
defined( 'ABSPATH' ) || exit;

/**
 * Class Nexter_Ext_Image_Upload_Optimization
 */
class Nexter_Ext_Image_Upload_Optimization {

	/** Singleton instance */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Nexter_Ext_Image_Upload_Optimization
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Pending upload metadata keyed by original file path (for wp_generate_attachment_metadata). */
	private static $pending_upload_metadata = array();

	/** Cached settings per request to avoid repeated get_option() and parsing. */
	private static $settings_cache = null;

	/** Whether optimiser folders have been created this request. */
	private static $optimizer_folders_created = false;

	/** Cached Imagick format list per request. */
	private static $imagick_formats_cache = null;

	/** Per-request cache: "attachment_id" or "attachment_id_size" => optimised URL (or false). */
	private static $attachment_url_cache = array();

	/** Cached upload dir. */
	private static $upload_dir_cache = null;

	/** Whether the current run is bulk convert (AJAX), for internal use. */
	public $is_bulk_run = false;

	/**
	 * Get upload dir with caching.
	 *
	 * @return array
	 */
	public static function get_upload_dir() {
		if ( null === self::$upload_dir_cache ) {
			self::$upload_dir_cache = wp_get_upload_dir();
		}
		return self::$upload_dir_cache;
	}

	/** Direct Replacement: cache URL => optimised URL for HTML replacement. */
	private static $direct_replacement_cache = array();

	/** Direct Replacement: browser Accept header support (avif, webp). */
	private static $browser_support = null;

	/**
	 * Default values matching performance.jsx image-upload-optimise defaults.
	 *
	 * @return array
	 */
	public static function get_default_values() {
		return array(
			'max_width'        => 1920,
			'max_height'       => 1920,
			'image_format'     => 'webp',
			'quality_mode'     => 'balanced',
			'auto_convert'     => false,
			'exif_data'        => 'strip',
			'resize_large'     => false,
			'processing_speed' => 'fast',
			'avoid_larger'      => false,
			'exclude_paths'     => array(),
			'run_in_background' => false,
		);
	}

	/**
	 * Clear settings cache (e.g. when options are updated).
	 */
	public static function clear_settings_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Get normalized settings from nexter_site_performance for image processing.
	 * Cached per request to reduce get_option() and parsing.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$option = get_option( 'nexter_site_performance', array() );
		$raw    = isset( $option['image-upload-optimize'] ) ? (array) $option['image-upload-optimize'] : array();
		$switch = ! empty( $raw['switch'] );
		$values = isset( $raw['values'] ) ? (array) $raw['values'] : array();
		
		$values = wp_parse_args( $values, self::get_default_values() );

		$max_width  = isset( $values['max_width'] ) ? max( 1, (int) $values['max_width'] ) : 1920;
		$max_height = isset( $values['max_height'] ) ? max( 1, (int) $values['max_height'] ) : 1920;

		$allowed_formats = apply_filters( 'nexter_image_optimizer_allowed_formats', array( 'webp', 'original' ) );
		$allowed_formats = is_array( $allowed_formats ) ? $allowed_formats : array( 'webp', 'original' );
		// Merge Pro formats (smart, avif) so they work on AJAX even if Pro's allowed_formats filter ran after us.
		$pro_formats = apply_filters( 'nexter_image_optimizer_pro_formats', array() );
		if ( is_array( $pro_formats ) && ! empty( $pro_formats ) ) {
			$allowed_formats = array_merge( $allowed_formats, $pro_formats );
		}
		$allowed_formats = array_unique( array_values( $allowed_formats ) );
		if ( empty( $allowed_formats ) ) {
			$allowed_formats = array( 'webp', 'original' );
		}

		$raw_format = isset( $values['image_format'] ) ? $values['image_format'] : 'webp';
		$image_format = in_array( $raw_format, $allowed_formats, true ) ? $raw_format : ( in_array( 'webp', $allowed_formats, true ) ? 'webp' : 'original' );

		$quality_mode = isset( $values['quality_mode'] ) && in_array( $values['quality_mode'], array( 'balanced', 'lossless', 'aggressive' ), true )
			? $values['quality_mode']
			: 'balanced';

		$quality_map   = array( 'balanced' => 80, 'lossless' => 90, 'aggressive' => 70 );
		$quality_value = isset( $quality_map[ $quality_mode ] ) ? $quality_map[ $quality_mode ] : 80;

		$exif_data = isset( $values['exif_data'] ) && in_array( $values['exif_data'], array( 'strip', 'keep' ), true )
			? $values['exif_data']
			: 'strip';

		$webp_enabled = ( 'original' !== $image_format && ( 'webp' === $image_format || 'smart' === $image_format ) );
		$avif_enabled = ( 'original' !== $image_format && ( 'avif' === $image_format || 'smart' === $image_format ) );

		$exclude_paths = array();
		if ( isset( $values['exclude_paths'] ) ) {
			if ( is_array( $values['exclude_paths'] ) ) {
				$exclude_paths = $values['exclude_paths'];
			} elseif ( is_string( $values['exclude_paths'] ) ) {
				$exclude_paths = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $values['exclude_paths'] ) ) );
			}
		}

		$result = array(
			'enabled'          => $switch,
			'output_format'     => $image_format,
			'webp_enabled'      => $webp_enabled,
			'avif_enabled'      => $avif_enabled,
			'auto_convert'      => ! empty( $values['auto_convert'] ),
			'max_width'         => $max_width,
			'max_height'        => $max_height,
			'resize_large'      => ! empty( $values['resize_large'] ),
			'quality_mode'      => $quality_mode,
			'webp_quality'      => $quality_value,
			'avif_quality'      => $quality_value,
			'exif_data'         => $exif_data,
			'avoid_larger'      => ! empty( $values['avoid_larger'] ),
			'processing_speed'  => isset( $values['processing_speed'] ) && in_array( $values['processing_speed'], array( 'fast', 'balanced', 'slow' ), true ) ? $values['processing_speed'] : 'fast',
			'exclude_paths'       => $exclude_paths,
			'run_in_background'   => ! empty( $values['run_in_background'] ),
			'exclude_png_webp'    => false,
			'exclude_png_avif'    => false,
		);
		self::$settings_cache = $result;
		return $result;
	}

	/**
	 * Check if upload path is excluded.
	 *
	 * @param string $file_path Full path to file.
	 * @param array  $exclude_paths List of path patterns (relative to uploads).
	 * @return bool
	 */
	public function is_path_excluded( $file_path, $exclude_paths ) {
		if ( empty( $exclude_paths ) || ! is_array( $exclude_paths ) ) {
			return false;
		}
	
		$upload_dir = wp_upload_dir();
		$base       = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
	
		$file_path  = wp_normalize_path( $file_path );
	
		// Convert to relative uploads path
		$relative = $base ? str_replace( $base . '/', '', $file_path ) : $file_path;
		$relative = ltrim( $relative, '/' );
	
		foreach ( $exclude_paths as $exclude ) {
	
			$exclude = trim( str_replace( '\\', '/', $exclude ) );
			$exclude = ltrim( $exclude, '/' );
	
			if ( empty( $exclude ) ) {
				continue;
			}
	
			// Remove uploads/ prefix
			if ( 0 === strpos( $exclude, 'uploads/' ) ) {
				$exclude = substr( $exclude, 8 );
			}
	
			// 1️⃣ Exact file match
			if ( $relative === $exclude ) {
				return true;
			}
	
			// 2️⃣ Folder match
			if ( strpos( $relative, $exclude . '/' ) === 0 ) {
				return true;
			}
	
			// 3️⃣ Wildcard support (*)
			if ( strpos( $exclude, '*' ) !== false ) {
				$pattern = '#^' . str_replace('\*', '.*', preg_quote($exclude, '#')) . '$#i';
				if ( preg_match( $pattern, $relative ) ) {
					return true;
				}
			}
	
			// 4️⃣ Extension match (*.png)
			if ( strpos( $exclude, '*.' ) === 0 ) {
				$ext = substr( $exclude, 2 );
				if ( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) === strtolower( $ext ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Calculate dimensions keeping aspect ratio within max width/height.
	 *
	 * @param int $width  Original width.
	 * @param int $height Original height.
	 * @param int $max_width  Max width.
	 * @param int $max_height Max height.
	 * @return array { width, height }
	 */
	private static function calculate_dimensions( $width, $height, $max_width, $max_height ) {
		if ( $width <= $max_width && $height <= $max_height ) {
			return array( 'width' => $width, 'height' => $height );
		}
		$aspect_ratio = $width / $height;
		$new_width    = $width;
		$new_height   = $height;
		if ( $new_width > $max_width ) {
			$new_width  = $max_width;
			$new_height = (int) ( $new_width / $aspect_ratio );
		}
		if ( $new_height > $max_height ) {
			$new_height = $max_height;
			$new_width  = (int) ( $new_height * $aspect_ratio );
		}
		return array(
			'width'  => max( 1, $new_width ),
			'height' => max( 1, $new_height ),
		);
	}

	/**
	 * Get output path for optimised file
	 *
	 * @param string $original_path Full path to original file in uploads.
	 * @return string Full path in nexter-optimizer/uploads (same relative path as uploads).
	 */
	private function get_output_path( $original_path ) {
		$upload_dir   = self::get_upload_dir();
		$basedir      = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
		$original_path = wp_normalize_path( str_replace( '\\', '/', $original_path ) );
		$relative     = '';

		if ( $basedir && strpos( $original_path, $basedir ) === 0 ) {
			$relative = trim( substr( $original_path, strlen( $basedir ) ), '/\\' );
		}
		// Fallback: extract path after uploads/ if basedir didn't match (e.g. custom upload path).
		if ( '' === $relative ) {
			$norm = str_replace( '\\', '/', $original_path );
			if ( preg_match( '#[/\\\\]uploads[/\\\\](.+)$#', $norm, $m ) ) {
				$relative = trim( $m[1], '/' );
			} else {
				$relative = basename( dirname( $original_path ) ) . '/' . basename( $original_path );
			}
		}
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		return wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $relative );
	}

	/**
	 * Ensure nexter-optimiser folders and .htaccess exist (same as webp-optimizer).
	 * Runs once per request to avoid repeated filesystem checks.
	 */
	public function create_optimizer_folders() {
		if ( self::$optimizer_folders_created ) {
			return;
		}
		$root = WP_CONTENT_DIR . '/nexter-optimizer';
		// Optional: require minimum free disk space (bytes). Filter 0 = skip check.
		$min_free = apply_filters( 'nexter_image_optimizer_min_disk_free_bytes', 5 * 1024 * 1024 ); // 5MB default.
		if ( $min_free > 0 && function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( dirname( $root ) );
			if ( false !== $free && $free < $min_free ) {
				return;
			}
		}
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		$uploads_folder = WP_CONTENT_DIR . '/nexter-optimizer/uploads';
		if ( ! is_dir( $uploads_folder ) ) {
			wp_mkdir_p( $uploads_folder );
		}
		$backups_folder = WP_CONTENT_DIR . '/nexter-optimizer/backups';
		if ( ! is_dir( $backups_folder ) ) {
			wp_mkdir_p( $backups_folder );
		}
		$htaccess = WP_CONTENT_DIR . '/nexter-optimizer/.htaccess';
		$lines    = array(
			'<IfModule mod_mime.c>',
			'AddType image/avif .avif',
			'AddType image/webp .webp',
			'</IfModule>',
			'',
			'<IfModule mod_expires.c>',
			'ExpiresActive On',
			'ExpiresByType image/avif "access plus 1 year"',
			'ExpiresByType image/webp "access plus 1 year"',
			'</IfModule>',
			'Options -Indexes',
		);
		if ( function_exists( 'insert_with_markers' ) ) {
			insert_with_markers( $htaccess, 'Nexter Image Optimiser', $lines );
		} elseif ( ! file_exists( $htaccess ) ) {
			$content = '# BEGIN Nexter Image Optimiser' . "\n" . implode( "\n", $lines ) . "\n" . '# END Nexter Image Optimiser' . "\n";
			file_put_contents( $htaccess, $content );
		}
		self::$optimizer_folders_created = true;
	}

	/**
	 * Resolve path from metadata (relative to wp-content or uploads) to absolute path.
	 *
	 * @param string $path Path from metadata (relative or absolute).
	 * @return string|false Absolute path.
	 */
	public static function get_absolute_path( $path ) {
		if ( empty( $path ) || ! is_string( $path ) ) {
			return false;
		}
		$normalized = wp_normalize_path( $path );
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( strpos( $normalized, $content_dir ) === 0 && file_exists( $normalized ) ) {
			return $normalized;
		}
		$relative = ltrim( str_replace( '\\', '/', $path ), '/' );
		$by_content = $content_dir . '/' . $relative;
		if ( file_exists( $by_content ) ) {
			return wp_normalize_path( $by_content );
		}
		$upload_dir = self::get_upload_dir();
		$by_uploads = wp_normalize_path( $upload_dir['basedir'] . '/' . $relative );
		if ( file_exists( $by_uploads ) ) {
			return $by_uploads;
		}
		return wp_normalize_path( $by_content );
	}

	/**
	 * Get path relative to uploads basedir from absolute path.
	 *
	 * @param string $absolute_path Full filesystem path.
	 * @return string Relative path (e.g. 2025/02/file.jpg) or empty.
	 */
	public static function absolute_to_relative_uploads( $absolute_path ) {
		if ( empty( $absolute_path ) || ! is_string( $absolute_path ) ) {
			return '';
		}
		$upload_dir = self::get_upload_dir();
		$basedir    = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
		$path       = wp_normalize_path( str_replace( '\\', '/', $absolute_path ) );
		if ( $basedir && strpos( $path, $basedir ) === 0 ) {
			$rel = trim( substr( $path, strlen( $basedir ) ), '/\\' );
			return ltrim( str_replace( '\\', '/', $rel ), '/' );
		}
		return '';
	}

	/**
	 * Convert absolute path to content-relative path for storing in metadata.
	 *
	 * @param string $absolute_path Full filesystem path.
	 * @return string Relative path (e.g. nexter-optimizer/uploads/2025/02/file.webp).
	 */
	public static function absolute_to_relative_content( $absolute_path ) {
		if ( empty( $absolute_path ) || ! is_string( $absolute_path ) ) {
			return '';
		}
		$path = wp_normalize_path( $absolute_path );
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( strpos( $path, $content_dir ) === 0 ) {
			$rel = ltrim( str_replace( $content_dir, '', $path ), '/' );
			return str_replace( '\\', '/', $rel );
		}
		return ltrim( str_replace( '\\', '/', $absolute_path ), '/' );
	}

	public function __construct() {
		self::$instance = $this;
		add_action( 'update_option_nexter_site_performance', array( __CLASS__, 'clear_settings_cache' ) );
		
		// Initialize Cron
		require_once plugin_dir_path( __FILE__ ) . 'class-nexter-ext-image-cron.php';
		new Nexter_Ext_Image_Cron();

		require_once plugin_dir_path( __FILE__ ) . 'class-nexter-ext-image-optimization-limit.php';
		Nexter_Ext_Image_Optimization_Limit::get_instance();

		if ( extension_loaded( 'exif' ) && function_exists( 'exif_read_data' ) ) {
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'prefilter_fix_orientation' ), 10, 1 );
			add_filter( 'wp_handle_upload', array( $this, 'fix_orientation_on_save' ), 1, 3 );
		}
		add_filter( 'wp_handle_upload', array( $this, 'image_upload_handler' ), 20, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'inject_upload_metadata' ), 10, 2 );
		add_action( 'wp_ajax_nxt_ext_image_restore_originals', array( $this, 'ajax_restore_originals' ) );
		add_action( 'wp_ajax_nxt_ext_image_convert_attachment', array( $this, 'ajax_convert_attachment' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_convert_button' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_convert_script' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_media_convert_script_elementor_editor' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( $this, 'enqueue_media_convert_script_elementor_preview' ) );
		// Serve optimised URL when nxt_optimized_file exists
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), 10, 4 );
		// Media Library grid and REST: rewrite attachment data so thumbnails
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'filter_attachment_for_js' ), 10, 3 );
		add_filter( 'rest_prepare_attachment', array( $this, 'filter_rest_attachment' ), 10, 3 );
		// Direct Replacement : replace img/background URLs in content with .webp/.avif.
		add_action( 'init', array( $this, 'register_direct_replacement_hooks' ), 20 );

		// Media Library List View Customization
		add_filter( 'manage_media_columns', array( $this, 'add_optimize_column_header' ) );
		add_action( 'manage_media_custom_column', array( $this, 'add_optimize_column_content' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'add_admin_list_styles' ) );
	}

	/**
	 * Register the_content / post_thumbnail_html / wp_get_attachment_image and output buffer for Direct Replacement (front-end only).
	 * Direct Replacement - Always on when image optimisation enabled.
	 */
	public function register_direct_replacement_hooks() {
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		add_filter( 'the_content', array( $this, 'replace_img_with_webp' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'replace_img_with_webp' ), 999 );
		add_filter( 'wp_get_attachment_image', array( $this, 'replace_img_with_webp' ), 999 );
		add_action( 'template_redirect', array( $this, 'start_direct_replacement_buffer' ), 0 );
	}

	/**
	 * Start output buffer on front-end to replace img/background URLs in full HTML (e.g. inline styles).
	 */
	public function start_direct_replacement_buffer() {
		if ( is_admin() ) {
			return;
		}
		ob_start( array( $this, 'filter_global_buffer' ) );
	}

	/**
	 * Get optimised URL for a given original image URL if optimised file exists (Direct Replacement).
	 * Prefers AVIF if browser supports it, else WebP.
	 *
	 * @param string $url Original image URL (e.g. from uploads).
	 * @return string|false Optimised URL or false.
	 */
	private function get_optimized_url_for_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}
		
		$upload_dir = self::get_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_path  = wp_normalize_path( $upload_dir['basedir'] );
		
		// Only process URLs from uploads directory
		if ( strpos( $url, $base_url ) === false ) {
			return false;
		}
		
		// Check browser support for optimised formats (avif/webp); original format works in all browsers.
		if ( null === self::$browser_support ) {
			$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
			self::$browser_support = array(
				'avif' => ( strpos( $accept, 'image/avif' ) !== false ),
				'webp' => ( strpos( $accept, 'image/webp' ) !== false ),
			);
		}
		
		// Extract relative path from URL
		$rel = str_replace( $base_url, '', $url );
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		// Remove query strings and fragments
		$rel = preg_replace( '/[?#].*$/', '', $rel );
		
		// Skip if empty after cleaning
		if ( empty( $rel ) ) {
			return false;
		}
		
		// Convert to absolute path
		$abs = wp_normalize_path( $base_path . '/' . $rel );
		
		// Check if original file exists
		if ( ! file_exists( $abs ) ) {
			return false;
		}
		
		// Check cache, but verify file still exists before returning cached URL
		// This handles cases where optimised files were deleted after restore
		$opt_base = $this->get_output_path( $abs );
		if ( isset( self::$direct_replacement_cache[ $url ] ) ) {
			$cached_url = self::$direct_replacement_cache[ $url ];
			if ( false !== $cached_url ) {
				// Verify the cached optimised file still exists
				$format = preg_match( '/\.(webp|avif)$/i', $cached_url, $format_match ) ? strtolower( $format_match[1] ) : '';
				if ( 'avif' === $format && file_exists( $opt_base . '.avif' ) ) {
					return $cached_url;
				} elseif ( 'webp' === $format && file_exists( $opt_base . '.webp' ) ) {
					return $cached_url;
				} elseif ( '' === $format && file_exists( $opt_base ) ) {
					return $cached_url;
				}
				// Cached file no longer exists, clear cache and continue to check
				unset( self::$direct_replacement_cache[ $url ] );
			} else {
				// Cached as false (no optimised version), return false
				return false;
			}
		}

		$new_url = false;

		// Check for optimised versions (prefer AVIF, fallback to WebP, then original format in nexter-optimizer/uploads)
		if ( self::$browser_support['avif'] && file_exists( $opt_base . '.avif' ) ) {
			$new_url = $this->get_output_url( $abs ) . '.avif';
		} elseif ( self::$browser_support['webp'] && file_exists( $opt_base . '.webp' ) ) {
			$new_url = $this->get_output_url( $abs ) . '.webp';
		} elseif ( file_exists( $opt_base ) ) {
			$new_url = $this->get_output_url( $abs );
		}
		
		// Cache result (even if false to avoid repeated file checks)
		self::$direct_replacement_cache[ $url ] = $new_url;
		
		return $new_url;
	}

	/**
	 * Replace img src and background-image URLs in content with optimised .webp/.avif (Direct Replacement).
	 *
	 * @param string $content HTML/content.
	 * @return string
	 */
	public function replace_img_with_webp( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}
		
		// First pass: Replace src and srcset for images with original formats (jpg/jpeg/png)
		$img_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_pattern, array( $this, 'direct_replacement_callback' ), $content );
		
		// Second pass: Replace srcset for images that already have optimised src (webp/avif) but srcset still has original formats
		// This handles cases where WordPress filters already optimised the src but not the srcset
		$img_with_srcset_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(webp|avif)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_with_srcset_pattern, array( $this, 'optimize_srcset_callback' ), $content );
		
		// Third pass: Revert optimised URLs back to original if optimised files don't exist
		// This handles cases after restore where optimised files are deleted but HTML still has optimised URLs
		$img_optimized_pattern = '/<img([^>]*?)src=["\']([^"\']*nexter-optimizer[^"\']+)\.(webp|avif|jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_optimized_pattern, array( $this, 'revert_optimized_url_callback' ), $content );

		// Replace background-image URLs
		$bg_pattern = '/url\(\s*["\']?([^"\'\)]+)\.(jpg|jpeg|png)([^"\'\)]*)["\']?\s*\)/i';
		$content = preg_replace_callback( $bg_pattern, array( $this, 'background_replacement_callback' ), $content );
		
		return $content;
	}

	/**
	 * Callback for img tag URL replacement (and srcset in same tag).
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function direct_replacement_callback( $matches ) {
		$full_path = $matches[2] . '.' . $matches[3] . $matches[4];
		$new_url = $this->get_optimized_url_for_url( $full_path );
		if ( ! $new_url ) {
			return $matches[0];
		}
		$tag = str_replace( $full_path, $new_url, $matches[0] );
		// Replace srcset URLs with optimised versions
		$tag = preg_replace_callback(
			'/srcset=["\']([^"\']+)["\']/i',
			array( $this, 'replace_srcset_urls' ),
			$tag
		);
		return $tag;
	}

	/**
	 * Callback for img tags that already have optimised src but need srcset replacement.
	 * This handles cases where WordPress filters already optimised the src but not the srcset.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function optimize_srcset_callback( $matches ) {
		$tag = $matches[0];
		// Only process if srcset exists and contains original format URLs
		if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $tag, $srcset_match ) ) {
			$srcset_content = $srcset_match[1];
			// Check if srcset contains original formats (jpg/jpeg/png) that need optimisation
			if ( preg_match( '/\.(jpg|jpeg|png)(\?|$|\s)/i', $srcset_content ) ) {
				// Replace srcset URLs with optimised versions
				$tag = preg_replace_callback(
					'/srcset=["\']([^"\']+)["\']/i',
					array( $this, 'replace_srcset_urls' ),
					$tag
				);
			}
		}
		return $tag;
	}

	/**
	 * Callback to revert optimised URLs back to original if optimised files don't exist.
	 * This handles cases after restore where optimised files are deleted but HTML still has optimised URLs.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function revert_optimized_url_callback( $matches ) {
		$optimized_url = $matches[2] . '.' . $matches[3] . $matches[4];
		$tag = $matches[0];
		$format = strtolower( $matches[3] );
		
		$upload_dir = self::get_upload_dir();
		$content_url = content_url();
		
		// Extract relative path from optimised URL
		// e.g., http://localhost/testimport/wp-content/nexter-optimizer/uploads/2026/02/image.jpg.webp
		// -> 2026/02/image.jpg.webp
		$rel_path = '';
		if ( strpos( $optimized_url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $optimized_url );
		} elseif ( strpos( $optimized_url, '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $optimized_url );
		} else {
			return $tag; // Not an optimised URL we handle
		}
		
		// Remove query strings
		$rel_path = preg_replace( '/\?.*$/', '', $rel_path );
		
		// Remove .webp, .avif, or keep as-is for jpg/jpeg/png (original format: same path in nexter-optimizer)
		$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );
		
		// Check if optimised file exists
		$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );
		if ( ! file_exists( $opt_abs_path ) ) {
			// Optimised file doesn't exist, revert to original URL
			$original_url = $upload_dir['baseurl'] . '/' . $original_rel_path;
			$tag = str_replace( $optimized_url, $original_url, $tag );
			
			// Also revert srcset if it contains optimised URLs
			$tag = preg_replace_callback(
				'/srcset=["\']([^"\']+)["\']/i',
				array( $this, 'revert_srcset_urls' ),
				$tag
			);
		}
		
		return $tag;
	}

	/**
	 * Callback to revert srcset URLs from optimised back to original if files don't exist.
	 *
	 * @param array $matches Regex matches from srcset pattern.
	 * @return string
	 */
	private function revert_srcset_urls( $matches ) {
		$srcset = $matches[1];
		$parts = explode( ',', $srcset );
		$new_parts = array();
		$upload_dir = self::get_upload_dir();
		$content_url = content_url();
		
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			// Split URL and descriptor
			$bits = preg_split( '/\s+/', $part, 2 );
			$url = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';
			
			// Check if this is an optimised URL that needs reverting
			$rel_path = '';
			if ( strpos( $url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $url );
			} elseif ( strpos( $url, '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $url );
			}
			
			if ( ! empty( $rel_path ) ) {
				// Remove query strings
				$rel_path = preg_replace( '/\?.*$/', '', $rel_path );
				$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );
				if ( ! file_exists( $opt_abs_path ) ) {
					// Optimised file doesn't exist, revert to original URL
					$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );
					$url = $upload_dir['baseurl'] . '/' . $original_rel_path;
				}
			}
			
			$new_parts[] = $url . $descriptor;
		}
		
		return 'srcset="' . implode( ', ', $new_parts ) . '"';
	}

	/**
	 * Callback to replace URLs in srcset attribute with optimised versions.
	 *
	 * @param array $matches Regex matches from srcset pattern.
	 * @return string
	 */
	private function replace_srcset_urls( $matches ) {
		$srcset = $matches[1];
		$parts = explode( ',', $srcset );
		$new_parts = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			// Split URL and descriptor (e.g., "url 272w" or "url 2x")
			$bits = preg_split( '/\s+/', $part, 2 );
			$url = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';
			
			// Remove query strings and fragments for matching
			$clean_url = preg_replace( '/[?#].*$/', '', $url );
			
			// Try to get optimised URL
			$opt_url = $this->get_optimized_url_for_url( $clean_url );
			if ( $opt_url ) {
				// Preserve query strings and fragments if they existed
				$query_fragment = '';
				if ( preg_match( '/[?#].*$/', $url, $qf_matches ) ) {
					$query_fragment = $qf_matches[0];
				}
				$url = $opt_url . $query_fragment;
			}
			$new_parts[] = $url . $descriptor;
		}
		return 'srcset="' . implode( ', ', $new_parts ) . '"';
	}

	/**
	 * Callback for background-image url() replacement.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function background_replacement_callback( $matches ) {
		$full_url = $matches[1] . '.' . $matches[2] . $matches[3];
		$new_url = $this->get_optimized_url_for_url( $full_url );
		if ( ! $new_url ) {
			return $matches[0];
		}
		return str_replace( $full_url, $new_url, $matches[0] );
	}

	/**
	 * Output buffer callback: replace img/background URLs in full HTML (e.g. inline styles in body).
	 *
	 * @param string $buffer Page HTML.
	 * @return string
	 */
	public function filter_global_buffer( $buffer ) {
		if ( empty( $buffer ) || ! is_string( $buffer ) ) {
			return $buffer;
		}
		if ( stripos( $buffer, '<html' ) === false ) {
			return $buffer;
		}
		return $this->replace_img_with_webp( $buffer );
	}

	/**
	 * Inject nxt_* metadata when attachment is created from an upload we optimised.
	 *
	 * @param array $metadata       Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function inject_upload_metadata( $metadata, $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return $metadata;
		}
		$file_path = wp_normalize_path( $file_path );
		if ( empty( self::$pending_upload_metadata[ $file_path ] ) ) {
			return $metadata;
		}
		$pending = self::$pending_upload_metadata[ $file_path ];
		unset( self::$pending_upload_metadata[ $file_path ] );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$metadata['nxt_main_original_size']  = $pending['original_size'];
		$metadata['nxt_main_optimized_size']  = $pending['optimized_size'];
		$metadata['nxt_original_size']       = $pending['original_size'];
		$metadata['nxt_optimized_size']      = $pending['optimized_size'];
		$metadata['nxt_original_file']       = $pending['original_relative'];
		$metadata['nxt_optimized_file']      = $pending['optimized_relative'];
		$metadata['nxt_optimized_format']     = $pending['format'];
		$metadata['nxt_original_mime']        = $pending['original_mime'];
		if ( ! empty( $pending['backup_relative'] ) ) {
			$metadata['nxt_backup_file'] = $pending['backup_relative'];
		}

		// Process all thumbnail sizes (medium, large, thumbnail, etc.).
		// Credit counting: record once after sizes with credits = 1 (original) + number of sizes optimized.
		$settings = $this->get_settings();
		$base_dir = dirname( $file_path );
		$upload_dir = self::get_upload_dir();
		$basedir_normalized = wp_normalize_path( $upload_dir['basedir'] );
		$backup_dir = WP_CONTENT_DIR . '/nexter-optimizer/backups';
		$total_original = $pending['original_size'];
		$total_optimized = $pending['optimized_size'];

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$metadata['nxt_optimized_sizes'] = isset( $metadata['nxt_optimized_sizes'] ) ? $metadata['nxt_optimized_sizes'] : array();
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_file_path = wp_normalize_path( $base_dir . '/' . $size_data['file'] );
				if ( ! file_exists( $size_file_path ) ) {
					continue;
				}
				$size_mime = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $size_file_path, null ) : null;
				$size_mime = is_array( $size_mime ) && ! empty( $size_mime['type'] ) ? $size_mime['type'] : '';
				$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
				if ( ! in_array( $size_mime, $valid_mimes, true ) ) {
					continue;
				}
				if ( $this->is_path_excluded( $size_file_path, $settings['exclude_paths'] ) ) {
					continue;
				}
				// Backup this size.
				$size_relative = str_replace( $basedir_normalized . '/', '', wp_normalize_path( str_replace( '\\', '/', $size_file_path ) ) );
				$size_relative = ltrim( $size_relative, '/' );
				$size_backup_path = wp_normalize_path( $backup_dir . '/' . $size_relative );
				$size_backup_parent = dirname( $size_backup_path );
				if ( ! is_dir( $size_backup_parent ) ) {
					wp_mkdir_p( $size_backup_parent );
				}
				if ( ! file_exists( $size_backup_path ) ) {
					@copy( $size_file_path, $size_backup_path );
				}
				$size_backup_relative = file_exists( $size_backup_path ) ? self::absolute_to_relative_content( $size_backup_path ) : '';
				$size_result = $this->process_image( $size_file_path, $settings );
				if ( $size_result && ! empty( $size_result['success'] ) && ! empty( $size_result['file'] ) ) {
					$size_orig = (int) $size_result['original_size'];
					$size_opt  = (int) $size_result['optimized_size'];
					$total_original += $size_orig;
					$total_optimized += $size_opt;
					$metadata['nxt_optimized_sizes'][ $size_name ] = array(
						'file'           => self::absolute_to_relative_content( $size_result['file'] ),
						'format'         => $size_result['format'],
						'original_size'  => $size_orig,
						'optimized_size' => $size_opt,
						'backup_file'    => $size_backup_relative,
					);
				}
			}
			$metadata['nxt_original_size']  = $total_original;
			$metadata['nxt_optimized_size'] = $total_optimized;
		}

		// Record optimization credits once: 1 (original) + number of thumbnail sizes optimized = total credits used
		if ( ! empty( $pending['needs_limit_increment'] ) ) {
			$credit_count = 1 + ( isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ? count( $metadata['nxt_optimized_sizes'] ) : 0 );
			Nexter_Ext_Image_Optimization_Limit::get_instance()->record_optimization( $attachment_id, (int) $total_original, (int) $total_optimized, $credit_count );
		}

		return $metadata;
	}

	/**
	 * Add "Convert to Optimised Format" button and Optimisation details to attachment edit (Media).
	 *
	 * @param array   $form_fields Form fields.
	 * @param WP_Post $post        Attachment post.
	 * @return array
	 */
	public function add_convert_button( $form_fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$file_path = get_attached_file( $post->ID );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $form_fields;
		}

		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return $form_fields;
		}

		$mime_type = get_post_mime_type( $post->ID );
		if ( 'image/webp' === $mime_type || 'image/avif' === $mime_type ) {
			return $form_fields;
		}

		$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp' );
		if ( ! in_array( $mime_type, $valid_mimes, true ) ) {
			return $form_fields;
		}

		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );
		$has_webp    = $has_gd && function_exists( 'imagewebp' );
		$can_optimize = $has_imagick || $has_webp || ( $has_gd && 'original' === $settings['output_format'] );
		if ( ! $can_optimize ) {
			return $form_fields;
		}

		wp_cache_delete( $post->ID, 'post_meta' );
		$metadata = wp_get_attachment_metadata( $post->ID );
		if ( ! is_array( $metadata ) ) {
			$metadata = get_post_meta( $post->ID, '_wp_attachment_metadata', true );
		}
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$optimized_path_abs = ! empty( $metadata['nxt_optimized_file'] ) ? self::get_absolute_path( $metadata['nxt_optimized_file'] ) : null;
		$is_optimized       = ( $optimized_path_abs && file_exists( $optimized_path_abs ) );
		
		$html = '';

		// Shared Styles
		$html .= '<style>
			.media-types.media-types-required-info {display: none;}
			.nxt-opt-card { background: #F5F7FE; border: 1px solid #1717CC; border-radius: 8px; padding: 16px; max-width: 100%; box-sizing: border-box; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
			.nxt-opt-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 20px; }
			.nxt-opt-icon { background: #1717CC; color: white; width: 21px; height: 21px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
			.nxt-opt-icon svg { width: 12px; height: 12px; }
			.nxt-opt-title-grp { flex-grow: 1; }
			.nxt-opt-title-grp h3 { margin: 0 0 5px 0; font-weight: 500; font-size: 14px; line-height: 17px; color: #1A1A1A; }
			.nxt-opt-desc { margin: 0; font-size: 12px; color: #666666; line-height: 18px; }
			.nxt-opt-stats { background: #fff; border-radius: 4px; padding: 12px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
			.nxt-opt-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 10px; color: #666; align-items: center; }
			.nxt-opt-row:last-child { margin-bottom: 0; }
			.nxt-opt-val-green { color: #058645;}
			.nxt-opt-divider { height: 1px; background: #D1D1D6; margin: 12px 0; }
			.nxt-opt-btn-wrap { position: relative; }
			
			/* Default Outline Button (Re-convert) */
			.nxt-opt-btn-wrap button.nxt-ext-image-convert-btn,.nxt-ext-image-upgrade-btn { width: 100%; background: transparent; border: 1px solid #1717CC; color: #1717CC; border-radius: 5px; padding: 0 12px; cursor: pointer; transition: all 0.2s; height: 36px; line-height: 18px; font-size: 12px; text-align: center; display: inline-block; text-decoration: none; }
			.nxt-ext-image-upgrade-btn{display: flex;text-decoration: none;align-items: center;justify-content: center;width: auto;height: 34px;}
			.nxt-opt-btn-wrap button.nxt-ext-image-convert-btn:hover,.nxt-ext-image-upgrade-btn:hover { background: #1717CC; color: #fff; border-color: #1717CC; }
			
			/* Primary Filled Button (Optimise) */
			.nxt-opt-btn-wrap button.nxt-ext-image-convert-btn.nxt-opt-primary-btn,.nxt-ext-image-upgrade-btn.nxt-opt-primary-btn { background: #1717CC; color: #fff; }
			.nxt-opt-btn-wrap button.nxt-ext-image-convert-btn.nxt-opt-primary-btn:hover,.nxt-ext-image-upgrade-btn.nxt-opt-primary-btn:hover { background: #1010A0; border-color: #1010A0; }
			
			.nxt-ext-image-convert-spinner { float: none; vertical-align: middle; margin-left: 5px; }

			.nxt-opt-usage-wrap { margin-top: 20px;}
			.nxt-opt-usage-hdr { display: flex; justify-content: space-between; font-size: 12px; line-height: 18px; color: #1A1A1A; margin-bottom: 12px; font-weight: 400;}
			.nxt-opt-progress-bar { height: 6px; background: #D1D1D6; border-radius: 10px; overflow: hidden; margin-bottom: 12px;}
			.nxt-opt-progress-fill { height: 100%; background: #1717CC; border-radius: 10px; transition: width 0.3s ease;}
			.nxt-opt-reset-days { display: flex; align-items: center; gap: 6px; color: #666; font-size: 12px;line-height: 1; }
			.nxt-opt-reset-days svg { width: 14px; height: 14px; }
		</style>';

		$stats = Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats();
		$show_usage = empty( $stats['is_pro'] );

		$usage_count = (int) ( $stats['monthly_count'] ?? 0 );
		$usage_limit = (int) ( $stats['monthly_limit'] ?? 500 );
		$limit_reached = ( ! empty( $show_usage ) && $usage_count >= $usage_limit );

		$usage_html = '';
		if ( $show_usage ) {
			$usage_pct   = $usage_limit > 0 ? min( 100, ( $usage_count / $usage_limit ) * 100 ) : 0;
			$reset_days  = (int) $stats['resets_in_days'];
			$fill_color  = $limit_reached ? '#FF1400' : '#1717CC';
			
			$usage_html .= '<div class="nxt-opt-usage-wrap">';
			$usage_html .= '<div class="nxt-opt-usage-hdr"><span>' . esc_html__( 'Monthly Usage', 'nexter-extension' ) . '</span><span>' . $usage_count . ' / ' . $usage_limit . '</span></div>';
			$usage_html .= '<div class="nxt-opt-progress-bar"><div class="nxt-opt-progress-fill" style="width: ' . $usage_pct . '%; background: ' . $fill_color . ';"></div></div>';
			$usage_html .= '<div class="nxt-opt-reset-days"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#666" clip-path="url(#aasdda)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333Z"/><path stroke-linecap="round" d="M8 4.445v3.556l2.222 2.222"/></g><defs><clipPath id="aasdda"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>' . sprintf( esc_html__( 'Resets in %d days', 'nexter-extension' ), $reset_days ) . '</div>';
			$usage_html .= '</div>';
		}

		if ( $is_optimized ) {
			$original_size    = isset( $metadata['nxt_main_original_size'] ) ? (int) $metadata['nxt_main_original_size'] : 0;
			$optimized_size   = isset( $metadata['nxt_main_optimized_size'] ) ? (int) $metadata['nxt_main_optimized_size'] : 0;
			$format           = isset( $metadata['nxt_optimized_format'] ) ? $metadata['nxt_optimized_format'] : 'webp';
			$saved            = $original_size - $optimized_size;
			$saved_pct        = $original_size > 0 ? round( ( $saved / $original_size ) * 100, 2 ) : 0;
			$thumbnails_count = isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ? count( $metadata['nxt_optimized_sizes'] ) : 0;

			$format_upper = strtoupper( $format );

			$html .= '<div class="nxt-opt-card">';
			$html .= '<div class="nxt-opt-header">';
			$html .= '<div class="nxt-opt-icon"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="12" fill="none" viewBox="0 0 11 12"><path stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.05" d="M1.052 6.827a.525.525 0 0 1-.41-.856L5.84.616a.263.263 0 0 1 .451.242l-1.008 3.16a.525.525 0 0 0 .494.709h3.675a.525.525 0 0 1 .41.856l-5.198 5.355a.262.262 0 0 1-.452-.242l1.008-3.16a.525.525 0 0 0-.493-.71z"/></svg></div>';
			$html .= '<div class="nxt-opt-title-grp">';
			$html .= '<h3>' . esc_html__( 'Image Successfully Optimised', 'nexter-extension' ) . '</h3>';
			$html .= '<p class="nxt-opt-desc">' . sprintf( esc_html__( 'Your image has been optimised and converted to %s format, reducing file size by %s%%.', 'nexter-extension' ), esc_html( $format_upper ), esc_html( number_format_i18n( $saved_pct, 2 ) ) ) . '</p>';
			$html .= '</div>'; // nxt-opt-title-grp
			$html .= '</div>'; // nxt-opt-header

			$html .= '<div class="nxt-opt-stats">';
			$html .= '<div class="nxt-opt-row"><span>' . esc_html__( 'Original Size', 'nexter-extension' ) . '</span><span style="color: #1A1A1A">' . esc_html( size_format( $original_size, 2 ) ) . '</span></div>';
			$html .= '<div class="nxt-opt-row"><span>' . esc_html__( 'Optimised Size', 'nexter-extension' ) . '</span><span class="nxt-opt-val-green">' . esc_html( size_format( $optimized_size, 2 ) ) . '</span></div>';
			$html .= '</div>'; // nxt-opt-stats

			$html .= '<div class="nxt-opt-btn-wrap">';
			if ( $limit_reached ) {
				$html .= '<a href="https://nexterwp.com/pricing/" target="_blank" class="nxt-ext-image-upgrade-btn nxt-opt-primary-btn">' . esc_html__( 'Upgrade Pro', 'nexter-extension' ) . '</a>';
			} else {
				$html .= '<button type="button" class="button nxt-ext-image-convert-btn" data-attachment-id="' . esc_attr( $post->ID ) . '">';
				$html .= esc_html__( 'Re-convert Image', 'nexter-extension' );
				$html .= '</button>';
				$html .= '<span class="nxt-ext-image-convert-spinner spinner" style="display:none;position:absolute;right:10px;top:8px;"></span>';
				$html .= '<div class="nxt-ext-image-convert-message"></div>';
			}
			$html .= '</div>'; // nxt-opt-btn-wrap

			$html .= $usage_html;

			$html .= '</div>'; // nxt-opt-card
		} else {
			// Unoptimised state: New Card Design
			$html .= '<div class="nxt-opt-card">';
			$html .= '<div class="nxt-opt-header">';
			$html .= '<div class="nxt-opt-icon"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="12" fill="none" viewBox="0 0 11 12"><path stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.05" d="M1.052 6.827a.525.525 0 0 1-.41-.856L5.84.616a.263.263 0 0 1 .451.242l-1.008 3.16a.525.525 0 0 0 .494.709h3.675a.525.525 0 0 1 .41.856l-5.198 5.355a.262.262 0 0 1-.452-.242l1.008-3.16a.525.525 0 0 0-.493-.71z"/></svg></div>';
			$html .= '<div class="nxt-opt-title-grp">';
			$html .= '<h3>' . esc_html__( 'Image Needs To Be Optimised', 'nexter-extension' ) . '</h3>';
			$html .= '<p class="nxt-opt-desc">' . esc_html__( 'Optimising this image will reduce its file size and help the page load faster with Nexter Image Optimiser.', 'nexter-extension' ) . '</p>';
			$html .= '</div>'; // nxt-opt-title-grp
			$html .= '</div>'; // nxt-opt-header

			$html .= '<div class="nxt-opt-btn-wrap">';
			if ( $limit_reached ) {
				$html .= '<a href="https://nexterwp.com/pricing/" target="_blank" class="button nxt-ext-image-upgrade-btn nxt-opt-primary-btn" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">' . esc_html__( 'Upgrade Pro', 'nexter-extension' ) . '</a>';
			} else {
				$html .= '<button type="button" class="button nxt-ext-image-convert-btn nxt-opt-primary-btn" data-attachment-id="' . esc_attr( $post->ID ) . '">';
				$html .= esc_html__( 'Optimise Image', 'nexter-extension' );
				$html .= '</button>';
				$html .= '<span class="nxt-ext-image-convert-spinner spinner" style="display:none;position:absolute;right:10px;top:8px;"></span>';
				$html .= '<div class="nxt-ext-image-convert-message"></div>';
			}
			$html .= '</div>'; // nxt-opt-btn-wrap

			$html .= $usage_html;

			$html .= '</div>'; // nxt-opt-card
		}

		$new_form_fields = array();
		
		// Rebuild fields, substituting URL if optimised
		foreach ( $form_fields as $key => $field ) {
			if ( 'nxt_ext_image_convert' === $key || 'nxt_ext_image_optimization' === $key ) {
				continue;
			}
			if ( $is_optimized && ( 'url' === $key || 'file' === $key ) && isset( $field['value'] ) ) {
				$optimized_url = $this->get_optimized_attachment_url( $post->ID );
				if ( false !== $optimized_url && $optimized_url !== $field['value'] ) {
					$field['value'] = $optimized_url;
				}
			}
			$new_form_fields[ $key ] = $field;
		}

		// Inject our field
		if ( $is_optimized ) {
			$new_form_fields['nxt_ext_image_optimization'] = array(
				'label' => '',
				'input' => 'html', // Media Modal uses 'html' input type to render raw HTML
				'html'  => $html,
				'value' => '',
			);
		} else {
			$new_form_fields['nxt_ext_image_convert'] = array(
				'label' => '', // No label for button
				'input' => 'html',
				'html'  => $html,
				'value' => '', // value not used for html input
			);
		}

		return $new_form_fields;
	}

	/**
	 * Enqueue script for Media convert button (post.php, upload.php).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_media_convert_script( $hook_suffix ) {
		$allowed = array( 'post.php', 'upload.php' );
		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}
		$this->enqueue_media_convert_script_assets();
	}

	/**
	 * Enqueue media convert script and localized data (shared by admin, Elementor editor, and preview).
	 */
	private function enqueue_media_convert_script_assets() {
		wp_enqueue_script(
			'nxt-ext-image-convert',
			NEXTER_EXT_URL . 'assets/js/admin/nxt-ext-image-convert.js',
			array( 'jquery' ),
			NEXTER_EXT_VER,
			true
		);
		wp_localize_script( 'nxt-ext-image-convert', 'nxtExtImageOptimise', $this->get_media_convert_localize_data() );
	}

	/**
	 * Localized data for the media convert script.
	 *
	 * @return array<string, string>
	 */
	private function get_media_convert_localize_data() {
		return array(
			'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'nxt_ext_image_convert' ),
			'converting'          => __( 'Optimising...', 'nexter-extension' ),
			'success'             => __( 'Image converted successfully!', 'nexter-extension' ),
			'error'               => __( 'Conversion failed. Please try again.', 'nexter-extension' ),
			'successTitle'        => __( 'Image Successfully Optimised', 'nexter-extension' ),
			'successDesc'         => __( 'Your image has been optimised and converted to %s1 format, reducing file size by %s2%.', 'nexter-extension' ),
			'originalSizeLabel'   => __( 'Original Size', 'nexter-extension' ),
			'optimizedSizeLabel'  => __( 'Optimised Size', 'nexter-extension' ),
			'monthlyUsageLabel'   => __( 'Monthly Usage', 'nexter-extension' ),
			'resetsInDaysLabel'   => __( 'Resets in %d days', 'nexter-extension' ),
			'reconvertLabel'      => __( 'Re-convert Image', 'nexter-extension' ),
			'upgradeLabel'        => __( 'Upgrade Pro', 'nexter-extension' ),
			'upgradeUrl'          => 'https://nexterwp.com/pricing/',
			'imageOptimisedLabel' => __( 'Image Optimised', 'nexter-extension' ),
			'smallerLabel'        => __( '%s smaller', 'nexter-extension' ),
		);
	}

	/**
	 * Enqueue media convert script in Elementor editor (so convert button works in media modal opened from widgets).
	 */
	public function enqueue_media_convert_script_elementor_editor() {
		$this->enqueue_media_convert_script_assets();
	}

	/**
	 * Enqueue media convert script in Elementor editor preview (so convert button works in media modal).
	 */
	public function enqueue_media_convert_script_elementor_preview() {
		$this->enqueue_media_convert_script_assets();
	}

	/**
	 * Pre-filter to fix image orientation from EXIF before processing.
	 */
	public function prefilter_fix_orientation( $file ) {
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'tiff' ), true ) ) {
			$this->correct_orientation( $file['tmp_name'] );
		}
		return $file;
	}

	/**
	 * Fix orientation after upload (for EXIF-capable types).
	 */
	public function fix_orientation_on_save( $file ) {
		$ext = strtolower( pathinfo( $file['file'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'tiff' ), true ) ) {
			$this->correct_orientation( $file['file'] );
		}
		return $file;
	}

	/**
	 * Correct image orientation based on EXIF.
	 *
	 * @param string $path File path.
	 */
	private function correct_orientation( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) || ! function_exists( 'exif_read_data' ) ) {
			return;
		}
		$exif = @exif_read_data( $path );
		if ( empty( $exif['Orientation'] ) || (int) $exif['Orientation'] <= 1 ) {
			return;
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return;
		}
		$angle = 0;
		$flip  = false;
		switch ( (int) $exif['Orientation'] ) {
			case 2: $flip = array( false, true ); break;
			case 3: $angle = -180; break;
			case 4: $flip = array( true, false ); break;
			case 5: $angle = -90; $flip = array( false, true ); break;
			case 6: $angle = -90; break;
			case 7: $angle = -270; $flip = array( false, true ); break;
			case 8:
			case 9: $angle = -270; break;
			default: return;
		}
		if ( $angle !== 0 ) {
			$editor->rotate( $angle );
		}
		if ( $flip ) {
			$editor->flip( $flip[0], $flip[1] );
		}
		$editor->save( $path );
	}

	/**
	 * Main upload handler: optimise and optionally convert format.
	 *
	 * @param array  $upload Upload data from wp_handle_upload.
	 * @param string $context 'upload' or 'sideload'.
	 * @return array
	 */
	public function image_upload_handler( $upload, $context = 'upload' ) {
		if ( 'upload' !== $context && 'sideload' !== $context ) {
			return $upload;
		}
		if ( ! isset( $upload['file'], $upload['type'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}
		// Skip zero-byte or invalid size (corrupted / empty file).
		$file_size = filesize( $upload['file'] );
		if ( false === $file_size || $file_size < 1 ) {
			return $upload;
		}
		// Skip if over max optimisable size (avoids memory/timeout; filter 0 = no limit).
		$max_size = apply_filters( 'nexter_image_optimizer_max_file_size', 20 * 1024 * 1024 ); // 20MB default.
		if ( $max_size > 0 && $file_size > $max_size ) {
			return $upload;
		}
		
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] || ! $settings['auto_convert'] ) {
			return $upload;
		}

		// If background processing is enabled, skip immediate Optimisation.
		// The cron job will pick up unoptimised images later.
		/* if ( ! empty( $settings['run_in_background'] ) ) {
			return $upload;
		} */

		$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		if ( ! in_array( $upload['type'], $valid_mimes, true ) ) {
			return $upload;
		}

		if ( $this->is_path_excluded( $upload['file'], $settings['exclude_paths'] ) ) {
			return $upload;
		}

		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();
		// For new uploads we don't have attachment ID yet, so we use 0 or skip ID check.
		// image_upload_handler runs before attachment is created.
		if ( $limit_handler->is_limit_reached() ) {
			return $upload;
		}

		$this->create_optimizer_folders();
		$result = $this->process_image( $upload['file'], $settings );
		
		if ( $result && ! empty( $result['success'] ) && ! empty( $result['file'] ) ) {
			$original_path = $upload['file'];
			$optimized_path = $result['file'];
			$upload_dir = self::get_upload_dir();
			$basedir = wp_normalize_path( $upload_dir['basedir'] );
			$original_relative = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $original_path ) ) );
			$original_relative = ltrim( $original_relative, '/' );
			$optimized_relative = str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $optimized_path ) );
			$optimized_relative = ltrim( str_replace( '\\', '/', $optimized_relative ), '/' );

			// Backup original to nexter-optimizer/backups (same structure as uploads).
			$backup_dir = WP_CONTENT_DIR . '/nexter-optimizer/backups';
			$backup_path = wp_normalize_path( $backup_dir . '/' . $original_relative );
			$backup_parent = dirname( $backup_path );
			if ( ! is_dir( $backup_parent ) ) {
				wp_mkdir_p( $backup_parent );
			}
			if ( ! file_exists( $backup_path ) && file_exists( $original_path ) ) {
				@copy( $original_path, $backup_path );
			}
			$backup_relative = '';
			if ( file_exists( $backup_path ) ) {
				$backup_relative = str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $backup_path ) );
				$backup_relative = ltrim( str_replace( '\\', '/', $backup_relative ), '/' );
			}

			// Keep original in uploads; store pending metadata for wp_generate_attachment_metadata.
			self::$pending_upload_metadata[ wp_normalize_path( $original_path ) ] = array(
				'original_size'     => $result['original_size'],
				'optimized_size'    => $result['optimized_size'],
				'original_relative' => $original_relative,
				'optimized_relative' => $optimized_relative,
				'format'            => $result['format'],
				'original_mime'     => isset( $result['original_mime'] ) ? $result['original_mime'] : $upload['type'],
				'backup_relative'   => $backup_relative,
				'needs_limit_increment' => true,
			);
			// Do not change $upload['file'] or $upload['url'] — attachment stays as original; rewrite will serve optimised.
		}
		return $upload;
	}

	/**
	 * Process image in original format (compress, no format conversion).
	 * Same flow as WebP: backup to nexter-optimizer/backups, output to nexter-optimizer/uploads.
	 * Uses Imagick or GD extension directly for Optimisation.
	 *
	 * @param string $file_path Full path to image.
	 * @param array  $settings  Settings from get_settings().
	 * @return array|false Result with success, file, format, original_size, optimized_size; or false.
	 */
	public function process_image_original( $file_path, $settings ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		$original_size = filesize( $file_path );
		if ( false === $original_size || $original_size < 1 ) {
			return false;
		}
		$output_path   = $this->get_output_path( $file_path );
		$out_dir       = dirname( $output_path );
		if ( ! is_dir( $out_dir ) ) {
			wp_mkdir_p( $out_dir );
		}
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		if ( $has_imagick ) {
			$result = $this->process_image_original_imagick( $file_path, $settings, $original_size, $output_path );
			if ( $result ) {
				return $result;
			}
		}
		if ( $has_gd ) {
			return $this->process_image_original_gd( $file_path, $settings, $original_size, $output_path );
		}
		return false;
	}

	/**
	 * Process original format with Imagick: resize, strip exif, compress in same format.
	 *
	 * @param string $file_path       Full path to image.
	 * @param array  $settings        Settings from get_settings().
	 * @param int    $original_size   Pre-computed file size in bytes.
	 * @param string $output_path     Full output path (same extension as original).
	 * @return array|false Result array or false.
	 */
	private function process_image_original_imagick( $file_path, $settings, $original_size, $output_path ) {
		$file_info  = pathinfo( $file_path );
		$extension  = isset( $file_info['extension'] ) ? strtolower( $file_info['extension'] ) : '';
		$supported  = array( 'jpg', 'jpeg', 'png', 'gif' );
		if ( ! in_array( $extension, $supported, true ) ) {
			return false;
		}
		try {
			@set_time_limit( 300 );
			$imagick = new Imagick();
			$imagick->readImage( $file_path );
			$num_frames      = (int) $imagick->getNumberImages();
			$is_animated_gif = ( 'gif' === $extension && $num_frames > 1 );

			$imagick->setCompressionQuality( $settings['webp_quality'] );

			$original_width  = $imagick->getImageWidth();
			$original_height = $imagick->getImageHeight();
			$this->fix_imagick_orientation( $imagick );

			$dims        = self::calculate_dimensions( $original_width, $original_height, $settings['max_width'], $settings['max_height'] );
			$needs_resize = $settings['resize_large'] && ( $dims['width'] !== $original_width || $dims['height'] !== $original_height );
			if ( ! $is_animated_gif ) {
				if ( 'strip' === $settings['exif_data'] ) {
					$imagick->stripImage();
				}
				if ( $needs_resize ) {
					$imagick->resizeImage( $dims['width'], $dims['height'], Imagick::FILTER_LANCZOS, 1, true );
				}
			}

			$format_map = array(
				'jpg'  => 'JPEG',
				'jpeg' => 'JPEG',
				'png'  => 'PNG',
				'gif'  => 'GIF',
			);
			$imagick_format = isset( $format_map[ $extension ] ) ? $format_map[ $extension ] : 'JPEG';
			if ( $is_animated_gif ) {
				$imagick = $imagick->coalesceImages();
				$n = $imagick->getNumberImages();
				for ( $i = 0; $i < $n; $i++ ) {
					$imagick->setImageIndex( $i );
					$imagick->setImageFormat( $imagick_format );
					$imagick->setImageCompressionQuality( $settings['webp_quality'] );
				}
				$imagick->writeImages( $output_path, true );
			} else {
				$imagick->setImageFormat( $imagick_format );
				if ( 'PNG' === $imagick_format ) {
					$imagick->setImageCompressionQuality( (int) round( ( 100 - $settings['webp_quality'] ) * 9 / 100 ) );
				} else {
					$imagick->setImageCompressionQuality( $settings['webp_quality'] );
				}
				$imagick->writeImage( $output_path );
			}
			$imagick->clear();
			$imagick->destroy();

			if ( ! file_exists( $output_path ) ) {
				return false;
			}
			$optimized_size = filesize( $output_path );
			if ( $settings['avoid_larger'] && $optimized_size >= $original_size ) {
				@unlink( $output_path );
				return array(
					'success' => false,
					'message' => __( 'Optimized file was larger than or equal to the original, so it was skipped because "Avoid Larger Files" is enabled.', 'nexter-extension' ),
				);
			}
			$original_mime = 'image/jpeg';
			if ( function_exists( 'wp_check_filetype' ) ) {
				$ft = wp_check_filetype( $file_path );
				if ( ! empty( $ft['type'] ) ) {
					$original_mime = $ft['type'];
				}
			}
			return array(
				'success'        => true,
				'file'           => $output_path,
				'format'         => 'original',
				'original_size'  => $original_size,
				'optimized_size' => $optimized_size,
				'original_mime'  => $original_mime,
			);
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Nexter Ext Image Upload Optimise (Imagick original): ' . $e->getMessage() );
			}
			if ( isset( $imagick ) && $imagick instanceof Imagick ) {
				$imagick->clear();
				$imagick->destroy();
			}
		}
		return false;
	}

	/**
	 * Process original format with GD: resize, compress in same format.
	 * GD does not preserve EXIF; output is always stripped.
	 *
	 * @param string $file_path       Full path to image.
	 * @param array  $settings        Settings from get_settings().
	 * @param int    $original_size   Pre-computed file size in bytes.
	 * @param string $output_path     Full output path (same extension as original).
	 * @return array|false Result array or false.
	 */
	private function process_image_original_gd( $file_path, $settings, $original_size, $output_path ) {
		$file_info = pathinfo( $file_path );
		$extension = isset( $file_info['extension'] ) ? strtolower( $file_info['extension'] ) : '';

		$image = null;
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$image = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $file_path ) : null;
				break;
			case 'png':
				$image = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $file_path ) : null;
				break;
			case 'gif':
				$image = function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $file_path ) : null;
				break;
			default:
				return false;
		}
		if ( ! $image ) {
			return false;
		}

		$original_width  = imagesx( $image );
		$original_height = imagesy( $image );
		$dims            = self::calculate_dimensions( $original_width, $original_height, $settings['max_width'], $settings['max_height'] );
		$needs_resize    = $settings['resize_large'] && ( $dims['width'] !== $original_width || $dims['height'] !== $original_height );

		if ( $needs_resize ) {
			$resized = imagecreatetruecolor( $dims['width'], $dims['height'] );
			if ( 'png' === $extension ) {
				imagealphablending( $resized, false );
				imagesavealpha( $resized, true );
				$transparent = imagecolorallocatealpha( $resized, 255, 255, 255, 127 );
				imagefilledrectangle( $resized, 0, 0, $dims['width'], $dims['height'], $transparent );
			}
			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $dims['width'], $dims['height'], $original_width, $original_height );
			imagedestroy( $image );
			$image = $resized;
		}

		$saved = false;
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$saved = function_exists( 'imagejpeg' ) && @imagejpeg( $image, $output_path, $settings['webp_quality'] );
				break;
			case 'png':
				$png_quality = (int) round( ( 100 - $settings['webp_quality'] ) * 9 / 100 );
				$saved       = function_exists( 'imagepng' ) && @imagepng( $image, $output_path, min( 9, max( 0, $png_quality ) ) );
				break;
			case 'gif':
				$saved = function_exists( 'imagegif' ) && @imagegif( $image, $output_path );
				break;
		}
		imagedestroy( $image );

		if ( ! $saved || ! file_exists( $output_path ) ) {
			return false;
		}
		$optimized_size = filesize( $output_path );
		if ( $settings['avoid_larger'] && $optimized_size >= $original_size ) {
			@unlink( $output_path );
			return array(
				'success' => false,
				'message' => __( 'Optimized file was larger than or equal to the original, so it was skipped because "Avoid Larger Files" is enabled.', 'nexter-extension' ),
			);
		}
		$original_mime = 'image/jpeg';
		if ( function_exists( 'wp_check_filetype' ) ) {
			$ft = wp_check_filetype( $file_path );
			if ( ! empty( $ft['type'] ) ) {
				$original_mime = $ft['type'];
			}
		}
		return array(
			'success'        => true,
			'file'           => $output_path,
			'format'         => 'original',
			'original_size'  => $original_size,
			'optimized_size' => $optimized_size,
			'original_mime'  => $original_mime,
		);
	}

	/**
	 * Process image: convert to webp/avif.
	 * Writes optimised file next to original (same dir, new extension) and returns path to optimised file.
	 *
	 * @param string $file_path Full path to image.
	 * @param array  $settings  Settings from get_settings().
	 * @return array|false Result with success, file, mime_type, format, original_size, optimised_size; or false.
	 */
	public function process_image( $file_path, $settings ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		$original_size = filesize( $file_path );
		if ( false === $original_size || $original_size < 1 ) {
			return false;
		}
		$max_size = apply_filters( 'nexter_image_optimizer_max_file_size', 20 * 1024 * 1024 );
		if ( $max_size > 0 && $original_size > $max_size ) {
			return false;
		}

		$output_format = isset( $settings['output_format'] ) ? $settings['output_format'] : 'webp';
		if ( 'original' === $output_format ) {
			return $this->process_image_original( $file_path, $settings );
		}
		
		// Smart and AVIF are Pro-only; delegate to Pro plugin via filter.
		if ( in_array( $output_format, array( 'smart', 'avif' ), true ) ) {
			$base_output_path = $this->get_output_path( $file_path );
			$result           = apply_filters( 'nexter_image_optimizer_process_pro_formats', null, $file_path, $settings, $base_output_path, $original_size );
			if ( is_array( $result ) && ! empty( $result['success'] ) ) {
				return $result;
			}
			return false;
		}
		$base_output_path = $this->get_output_path( $file_path );
		$out_dir         = dirname( $base_output_path );
		if ( ! is_dir( $out_dir ) ) {
			wp_mkdir_p( $out_dir );
		}
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		if ( $has_imagick ) {
			$result = $this->process_image_imagick( $file_path, $settings, $original_size, $base_output_path );
			if ( $result ) {
				return $result;
			}
		}
		if ( $has_gd ) {
			return $this->process_image_gd( $file_path, $settings, $original_size, $base_output_path );
		}
		return false;
	}

	/**
	 * Process with Imagick: resize, strip exif, convert to webp/avif.
	 * Saves to nexter-optimizer/uploads (same relative path as uploads, with .webp/.avif extension).
	 *
	 * @param string $file_path         Full path to image.
	 * @param array  $settings          Settings from get_settings().
	 * @param int    $original_size     Pre-computed file size in bytes.
	 * @param string $base_output_path  Pre-computed output path (no extension).
	 */
	private function process_image_imagick( $file_path, $settings, $original_size, $base_output_path ) {
		$file_info = pathinfo( $file_path );
		$ext      = isset( $file_info['extension'] ) ? strtolower( $file_info['extension'] ) : '';
		$is_png   = ( 'png' === $ext );
		$is_gif   = ( 'gif' === $ext );

		try {
			@set_time_limit( 300 );
			$imagick = new Imagick();
			$imagick->readImage( $file_path );
			$num_frames       = (int) $imagick->getNumberImages();
			$is_animated_gif  = $is_gif && $num_frames > 1;

			$imagick->setCompressionQuality( max( $settings['webp_quality'], $settings['avif_quality'] ) );

			$original_width  = $imagick->getImageWidth();
			$original_height = $imagick->getImageHeight();
			$this->fix_imagick_orientation( $imagick );

			$dims        = self::calculate_dimensions( $original_width, $original_height, $settings['max_width'], $settings['max_height'] );
			$needs_resize = $settings['resize_large'] && ( $dims['width'] !== $original_width || $dims['height'] !== $original_height );
			if ( ! $is_animated_gif ) {
				if ( 'strip' === $settings['exif_data'] ) {
					$imagick->stripImage();
				}
				if ( $needs_resize ) {
					$imagick->resizeImage( $dims['width'], $dims['height'], Imagick::FILTER_LANCZOS, 1, true );
				}
			}

			$output_format = $settings['output_format'];
			// Extension handles WebP only; Smart and AVIF are processed by Pro plugin.
			if ( null === self::$imagick_formats_cache ) {
				self::$imagick_formats_cache = $imagick->queryFormats();
			}
			$webp_ok = in_array( 'WEBP', self::$imagick_formats_cache, true );
			$try_webp = ( 'webp' === $output_format ) && $settings['webp_enabled'] && $webp_ok && ! ( $is_png && $settings['exclude_png_webp'] );

			$webp_path = $base_output_path . '.webp';
			$webp_size = null;

			if ( $try_webp ) {
				if ( $is_animated_gif ) {
					// Preserve animation: coalesce frames then write as animated WebP.
					$imagick = $imagick->coalesceImages();
					$n = $imagick->getNumberImages();
					for ( $i = 0; $i < $n; $i++ ) {
						$imagick->setImageIndex( $i );
						$imagick->setImageFormat( 'webp' );
						$imagick->setImageCompressionQuality( $settings['webp_quality'] );
					}
					$imagick->writeImages( $webp_path, true );
				} else {
					$imagick->setImageFormat( 'webp' );
					$imagick->setImageCompressionQuality( $settings['webp_quality'] );
					$imagick->writeImage( $webp_path );
				}
				if ( file_exists( $webp_path ) ) {
					$webp_size = filesize( $webp_path );
					if ( $settings['avoid_larger'] && $webp_size >= $original_size ) {
						@unlink( $webp_path );
						$webp_path = null;
						$webp_size = null;
					}
				}
			}

			$best_path   = null;
			$best_format = null;
			$best_size   = $original_size;
			$best_mime   = 'image/jpeg';

			if ( $webp_path && file_exists( $webp_path ) ) {
				$best_path   = $webp_path;
				$best_format = 'webp';
				$best_size   = $webp_size;
				$best_mime   = 'image/webp';
			}

			$imagick->clear();
			$imagick->destroy();

			if ( $best_path && file_exists( $best_path ) ) {
				$original_mime = 'image/jpeg';
				if ( function_exists( 'wp_check_filetype' ) ) {
					$ft = wp_check_filetype( $file_path );
					if ( ! empty( $ft['type'] ) ) {
						$original_mime = $ft['type'];
					}
				}
				return array(
					'success'         => true,
					'file'            => $best_path,
					'original_file'   => $file_path,
					'mime_type'       => $best_mime,
					'format'          => $best_format,
					'original_size'   => $original_size,
					'optimized_size'  => $best_size,
					'original_mime'   => $original_mime,
				);
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Nexter Ext Image Upload Optimise (Imagick): ' . $e->getMessage() );
			}
			if ( isset( $imagick ) && $imagick instanceof Imagick ) {
				$imagick->clear();
				$imagick->destroy();
			}
		}
		return false;
	}

	/**
	 * Process with GD (WebP only; GD does not support AVIF).
	 * Saves to nexter-optimizer/uploads.
	 *
	 * @param string $file_path         Full path to image.
	 * @param array  $settings          Settings from get_settings().
	 * @param int    $original_size     Pre-computed file size in bytes.
	 * @param string $base_output_path  Pre-computed output path (no extension).
	 */
	private function process_image_gd( $file_path, $settings, $original_size, $base_output_path ) {
		$file_info = pathinfo( $file_path );
		$extension = isset( $file_info['extension'] ) ? strtolower( $file_info['extension'] ) : '';

		$image = null;
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$image = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $file_path ) : null;
				break;
			case 'png':
				$image = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $file_path ) : null;
				break;
			case 'gif':
				$image = function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $file_path ) : null;
				break;
			default:
				return false;
		}
		if ( ! $image ) {
			return false;
		}

		$original_width  = imagesx( $image );
		$original_height = imagesy( $image );
		$dims            = self::calculate_dimensions( $original_width, $original_height, $settings['max_width'], $settings['max_height'] );
		$needs_resize    = $settings['resize_large'] && ( $dims['width'] !== $original_width || $dims['height'] !== $original_height );

		if ( $needs_resize ) {
			$resized = imagecreatetruecolor( $dims['width'], $dims['height'] );
			if ( 'png' === $extension ) {
				imagealphablending( $resized, false );
				imagesavealpha( $resized, true );
				$transparent = imagecolorallocatealpha( $resized, 255, 255, 255, 127 );
				imagefilledrectangle( $resized, 0, 0, $dims['width'], $dims['height'], $transparent );
			}
			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $dims['width'], $dims['height'], $original_width, $original_height );
			imagedestroy( $image );
			$image = $resized;
		}

		$output_format = $settings['output_format'];
		$try_webp      = ( 'webp' === $output_format || 'smart' === $output_format ) && $settings['webp_enabled'] && function_exists( 'imagewebp' );
		// GD doesn't support AVIF; when AVIF is selected, fall back to WebP
		if ( 'avif' === $output_format && function_exists( 'imagewebp' ) ) {
			$try_webp = true;
		}
		$webp_path     = $base_output_path . '.webp';
		$best_path     = null;
		$best_size     = $original_size;
		$best_mime     = 'image/webp';

		if ( $try_webp ) {
			if ( @imagewebp( $image, $webp_path, $settings['webp_quality'] ) && file_exists( $webp_path ) ) {
				$webp_size = filesize( $webp_path );
				if ( ! ( $settings['avoid_larger'] && $webp_size >= $original_size ) ) {
					$best_path = $webp_path;
					$best_size = $webp_size;
				} else {
					@unlink( $webp_path );
				}
			}
		}

		imagedestroy( $image );

		if ( $best_path ) {
			$original_mime = 'image/jpeg';
			if ( function_exists( 'wp_check_filetype' ) ) {
				$ft = wp_check_filetype( $file_path );
				if ( ! empty( $ft['type'] ) ) {
					$original_mime = $ft['type'];
				}
			}
			return array(
				'success'         => true,
				'file'            => $best_path,
				'original_file'   => $file_path,
				'mime_type'       => $best_mime,
				'format'          => 'webp',
				'original_size'   => $original_size,
				'optimized_size'  => $best_size,
				'original_mime'   => $original_mime,
			);
		}
		return false;
	}

	/**
	 * Fix orientation for Imagick.
	 *
	 * @param Imagick $imagick Imagick instance.
	 */
	private function fix_imagick_orientation( $imagick ) {
		$orientation = $imagick->getImageOrientation();
		switch ( $orientation ) {
			case Imagick::ORIENTATION_BOTTOMRIGHT:
				$imagick->rotateImage( new ImagickPixel( 'none' ), 180 );
				break;
			case Imagick::ORIENTATION_RIGHTTOP:
				$imagick->rotateImage( new ImagickPixel( 'none' ), 90 );
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM:
				$imagick->rotateImage( new ImagickPixel( 'none' ), -90 );
				break;
		}
		$imagick->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * Convert file path to URL (uploads dir).
	 *
	 * @param string $file_path Absolute path.
	 * @return string|false
	 */
	private function path_to_url( $file_path ) {
		$upload_dir = self::get_upload_dir();
		$basedir    = wp_normalize_path( $upload_dir['basedir'] );
		$file_path  = wp_normalize_path( $file_path );
		if ( strpos( $file_path, $basedir ) === 0 ) {
			$rel = ltrim( str_replace( $basedir, '', $file_path ), '/' );
			return $upload_dir['baseurl'] . '/' . str_replace( '\\', '/', $rel );
		}
		return false;
	}

	/**
	 * Output URL for optimised file (nexter-optimizer path to content URL).
	 *
	 * @param string $original_path Original file path in uploads.
	 * @return string URL base for optimised file (without .webp/.avif).
	 */
	private function get_output_url( $original_path ) {
		$upload_dir = self::get_upload_dir();
		$basedir    = wp_normalize_path( $upload_dir['basedir'] );
		$original_path = wp_normalize_path( $original_path );
		$relative   = str_replace( $basedir . '/', '', str_replace( '\\', '/', $original_path ) );
		$relative   = ltrim( $relative, '/' );
		return content_url( '/nexter-optimizer/uploads/' . $relative );
	}

	/**
	 * Get optimised URL for an attachment (and optionally a specific size); uses per-request cache.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|array $size         Optional. Image size name ('full', 'medium', etc.) or array; default 'full'.
	 * @return string|false Optimised URL or false if not available.
	 */
	private function get_optimized_attachment_url( $attachment_id, $size = 'full' ) {
		$size_name = is_array( $size ) ? 'full' : $size;
		$cache_key = $attachment_id . ( 'full' !== $size_name ? '_' . $size_name : '' );
		if ( array_key_exists( $cache_key, self::$attachment_url_cache ) ) {
			return self::$attachment_url_cache[ $cache_key ];
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) ) {
			self::$attachment_url_cache[ $cache_key ] = false;
			return false;
		}
		$original_file = get_attached_file( $attachment_id );
		if ( ! $original_file ) {
			self::$attachment_url_cache[ $cache_key ] = false;
			return false;
		}
		// Full size: use main optimised file.
		if ( 'full' === $size_name || ! isset( $metadata['sizes'][ $size_name ] ) ) {
			if ( empty( $metadata['nxt_optimized_file'] ) ) {
				self::$attachment_url_cache[ $cache_key ] = false;
				return false;
			}
			$optimized_path = self::get_absolute_path( $metadata['nxt_optimized_file'] );
			if ( ! $optimized_path || ! file_exists( $optimized_path ) ) {
				self::$attachment_url_cache[ $cache_key ] = false;
				return false;
			}
			$format = isset( $metadata['nxt_optimized_format'] ) ? $metadata['nxt_optimized_format'] : 'webp';
			// For original format, optimised file has same extension (e.g. .jpg) in nexter-optimizer/uploads.
			$optimized_url = ( 'original' === $format )
				? $this->get_output_url( $original_file )
				: $this->get_output_url( $original_file ) . '.' . $format;
			self::$attachment_url_cache[ $cache_key ] = $optimized_url;
			return $optimized_url;
		}
		// Thumbnail size: use nxt_optimized_sizes if available.
		// For original format, serve thumbnails from uploads (they always exist); only full size uses nexter-optimizer.
		$format = isset( $metadata['nxt_optimized_format'] ) ? $metadata['nxt_optimized_format'] : 'webp';
		if ( 'original' === $format ) {
			self::$attachment_url_cache[ $cache_key ] = false;
			return false;
		}
		if ( empty( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] ) ) {
			self::$attachment_url_cache[ $cache_key ] = false;
			return false;
		}
		$size_opt_path = self::get_absolute_path( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] );
		if ( ! $size_opt_path || ! file_exists( $size_opt_path ) ) {
			self::$attachment_url_cache[ $cache_key ] = false;
			return false;
		}
		$size_data = $metadata['sizes'][ $size_name ];
		$base_dir = dirname( $original_file );
		$size_file_path = wp_normalize_path( $base_dir . '/' . $size_data['file'] );
		$size_format = isset( $metadata['nxt_optimized_sizes'][ $size_name ]['format'] ) ? $metadata['nxt_optimized_sizes'][ $size_name ]['format'] : 'webp';
		$optimized_url = $this->get_output_url( $size_file_path ) . '.' . $size_format;
		self::$attachment_url_cache[ $cache_key ] = $optimized_url;
		return $optimized_url;
	}

	/**
	 * Filter attachment URL to return optimised version when available (same as webp-optimizer).
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		$optimized_url = $this->get_optimized_attachment_url( $attachment_id, 'full' );
		return false !== $optimized_url ? $optimized_url : $url;
	}

	/**
	 * Filter attachment image src to return optimised URL (same as webp-optimizer).
	 *
	 * @param array|false  $image          Image array (url, width, height, is_intermediate) or false.
	 * @param int          $attachment_id  Attachment ID.
	 * @param string|array $size           Size.
	 * @param bool         $icon           Icon.
	 * @return array|false
	 */
	public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! $image || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		$optimized_url = $this->get_optimized_attachment_url( $attachment_id, $size );
		if ( false !== $optimized_url ) {
			$image[0] = $optimized_url;
		} elseif ( $size && 'full' !== $size && ! is_array( $size ) ) {
			// For original format, image_downsize derives size URLs
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) && ! empty( $metadata['nxt_optimized_format'] ) && 'original' === $metadata['nxt_optimized_format'] ) {
				$original_file = get_attached_file( $attachment_id );
				if ( $original_file && ! empty( $metadata['sizes'][ $size ]['file'] ) ) {
					$base_dir       = dirname( $original_file );
					$size_file_path = wp_normalize_path( $base_dir . '/' . $metadata['sizes'][ $size ]['file'] );
					$uploads_url    = $this->path_to_url( $size_file_path );
					if ( $uploads_url ) {
						$image[0] = $uploads_url;
					}
				}
			}
		}
		return $image;
	}

	/**
	 * Filter attachment data for JS (Media Library grid/list). Rewrites url and sizes[].url to optimised .webp/.avif.
	 * thumbnails load in admin.
	 *
	 * @param array      $response   Attachment data for JS.
	 * @param WP_Post    $attachment Attachment post.
	 * @param array|false $meta      Attachment metadata (optional).
	 * @return array
	 */
	public function filter_attachment_for_js( $response, $attachment, $meta ) {
		if ( ! isset( $response['url'] ) ) {
			return $response;
		}
		wp_cache_delete( $attachment->ID, 'post_meta' );
		$metadata = wp_get_attachment_metadata( $attachment->ID );
		if ( ! is_array( $metadata ) ) {
			$metadata = get_post_meta( $attachment->ID, '_wp_attachment_metadata', true );
		}
		if ( is_array( $meta ) && ! empty( $meta['nxt_optimized_file'] ) ) {
			$metadata = $meta;
		}
		if ( ! is_array( $metadata ) || empty( $metadata['nxt_optimized_file'] ) ) {
			return $response;
		}
		$optimized_path = self::get_absolute_path( $metadata['nxt_optimized_file'] );
		if ( ! $optimized_path || ! file_exists( $optimized_path ) ) {
			return $response;
		}
		$original_file = get_attached_file( $attachment->ID );
		if ( ! $original_file ) {
			return $response;
		}
		
		$format = isset( $metadata['nxt_optimized_format'] ) ? $metadata['nxt_optimized_format'] : 'webp';
		$optimized_url = ( 'original' === $format )
			? $this->get_output_url( $original_file )
			: $this->get_output_url( $original_file ) . '.' . $format;
		$response['url'] = $optimized_url;
		if ( isset( $response['icon'] ) ) {
			$response['icon'] = $optimized_url;
		}

		// Update response with optimized image data (mime, filesize, dimensions) for Media Library.
		$response['filename'] = wp_basename( $optimized_path );
		$optimized_mimes = array(
			'webp'    => 'image/webp',
			'avif'    => 'image/avif',
			'original' => ( isset( $response['mime'] ) && is_string( $response['mime'] ) ) ? $response['mime'] : 'image/jpeg',
		);
		$response['mime']  = isset( $optimized_mimes[ $format ] ) ? $optimized_mimes[ $format ] : 'image/webp';
		$response['type']  = 'image';
		$response['subtype'] = ( 'original' === $format && isset( $response['subtype'] ) ) ? $response['subtype'] : $format;
		$optimized_size_bytes = (int) ( $metadata['nxt_main_optimized_size'] ?? $metadata['nxt_optimized_size'] ?? 0 );
		if ( $optimized_size_bytes <= 0 && $optimized_path && file_exists( $optimized_path ) ) {
			$optimized_size_bytes = (int) filesize( $optimized_path );
		}
		if ( $optimized_size_bytes > 0 ) {
			$response['filesizeInBytes']        = $optimized_size_bytes;
			$response['filesizeHumanReadable']  = size_format( $optimized_size_bytes, 2 );
		}
		if ( $optimized_path && file_exists( $optimized_path ) && function_exists( 'getimagesize' ) ) {
			$dims = @getimagesize( $optimized_path );
			if ( ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
				$response['width']  = (int) $dims[0];
				$response['height'] = (int) $dims[1];
			}
		}

		// Rewrite each size URL so Media Library thumbnails use .webp/.avif.
		// For original format, explicitly set thumbnail URLs to uploads (image_downsize derives wrong nexter-optimizer URLs).
		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			$base_dir = dirname( $original_file );
			if ( 'original' === $format ) {
				foreach ( $response['sizes'] as $size_name => $size_data ) {
					if ( empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
						continue;
					}
					$size_file_path = wp_normalize_path( $base_dir . '/' . $metadata['sizes'][ $size_name ]['file'] );
					$uploads_url    = $this->path_to_url( $size_file_path );
					if ( $uploads_url ) {
						$response['sizes'][ $size_name ]['url'] = $uploads_url;
					}
				}
			} elseif ( ! empty( $metadata['nxt_optimized_sizes'] ) ) {
				foreach ( $response['sizes'] as $size_name => $size_data ) {
					if ( empty( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] ) || empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
						continue;
					}
					$size_opt_path = self::get_absolute_path( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] );
					if ( ! $size_opt_path || ! file_exists( $size_opt_path ) ) {
						continue;
					}
					$size_file_path = wp_normalize_path( $base_dir . '/' . $metadata['sizes'][ $size_name ]['file'] );
					$size_format    = isset( $metadata['nxt_optimized_sizes'][ $size_name ]['format'] ) ? $metadata['nxt_optimized_sizes'][ $size_name ]['format'] : 'webp';
					$response['sizes'][ $size_name ]['url'] = $this->get_output_url( $size_file_path ) . '.' . $size_format;
				}
			}
		}
		return $response;
	}

	/**
	 * Add "Optimise" column header to Media Library.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_optimize_column_header( $columns ) {
		$columns['nxt_optimize'] = __( 'Optimise', 'nexter-extension' );
		return $columns;
	}

	/**
	 * Render content for "Optimise" column in Media Library.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function add_optimize_column_content( $column_name, $post_id ) {
		if ( 'nxt_optimize' !== $column_name ) {
			return;
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			echo '—';
			return;
		}

		$mime_type = get_post_mime_type( $post_id );
		if ( 'image/webp' === $mime_type || 'image/avif' === $mime_type ) {
			echo '—';
			return;
		}
		
		// Check exclusions
		$settings = $this->get_settings();
		$file_path = get_attached_file( $post_id );
		if ( $file_path && $this->is_path_excluded( $file_path, $settings['exclude_paths'] ) ) {
			echo '<span class="dashicons dashicons-no-alt" title="' . esc_attr__( 'Excluded via settings', 'nexter-extension' ) . '"></span>';
			return;
		}

		$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
		if ( ! in_array( $mime_type, $valid_mimes, true ) ) {
			echo '—';
			return;
		}

		$metadata = wp_get_attachment_metadata( $post_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = get_post_meta( $post_id, '_wp_attachment_metadata', true );
		}
		
		$is_optimized = false;
		if ( is_array( $metadata ) && ! empty( $metadata['nxt_optimized_file'] ) ) {
			$opt_path = self::get_absolute_path( $metadata['nxt_optimized_file'] );
			if ( $opt_path && file_exists( $opt_path ) ) {
				$is_optimized = true;
			}
		}

		if ( $is_optimized ) {
			echo '<div class="nxt-opt-status-wrap">';
			// Success Text
			echo '<div style="font-size:13px;color:#1a1a1a;margin-bottom:2px;">' . esc_html__( 'Image Optimised', 'nexter-extension' ) . '</div>';
			
			// Details
			$original_size  = isset( $metadata['nxt_main_original_size'] ) ? (int) $metadata['nxt_main_original_size'] : 0;
			$optimized_size = isset( $metadata['nxt_main_optimized_size'] ) ? (int) $metadata['nxt_main_optimized_size'] : 0;
			if ( $original_size > 0 ) {
				$saved = $original_size - $optimized_size;
				$saved_pct = round( ( $saved / $original_size ) * 100, 2 );
				
				// Percentage in Blue
				echo '<div style="font-size:13px;color:#1717cc;">' . sprintf( esc_html__( '%s smaller', 'nexter-extension' ), number_format_i18n( $saved_pct, 2 ) . '%' ) . '</div>';
			}
			echo '</div>';
		} else {
			// Button for Optimisation (Blue filled button with lightning icon)
			echo '<div class="nxt-ext-image-convert-wrapper">';
			echo '<button type="button" class="nxt-ext-image-convert-btn" data-attachment-id="' . esc_attr( $post_id ) . '" style="background: #1717CC; border-radius: 4px; border: none; padding: 6px 12px; color: #fff; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; transition: background 0.2s;width:100%;justify-content:center;">';
			echo '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="12" fill="none" viewBox="0 0 10 12"><path d="M4.333 11.333L9 5.333H5.667L6.333 0.666992L1.667 6.66699H5L4.333 11.333Z" stroke="white" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			echo esc_html__( 'Optimise Image', 'nexter-extension' );
			echo '</button>';
			echo '<span class="nxt-ext-image-convert-spinner spinner" style="display:none;float:none;margin:0 5px;"></span>';
			echo '<span class="nxt-ext-image-convert-message"></span>';
			echo '</div>';
		}
	}

	/**
	 * Add Admin CSS for Media Library List View customization.
	 */
	public function add_admin_list_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.column-nxt_optimize { width: 140px; }
			.nxt-opt-title-icon svg { width: 14px; height: 14px; fill: blue; }
		</style>
		<?php
	}

	/**
	 * Filter REST API attachment response so Media Library (REST) gets optimised .webp/.avif URLs.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param WP_Post          $post    Attachment post.
	 * @param WP_REST_Request  $request Request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_attachment( $response, $post, $request ) {
		if ( ! isset( $response->data['source_url'] ) ) {
			return $response;
		}
		wp_cache_delete( $post->ID, 'post_meta' );
		$metadata = wp_get_attachment_metadata( $post->ID );
		if ( ! is_array( $metadata ) ) {
			$metadata = get_post_meta( $post->ID, '_wp_attachment_metadata', true );
		}
		if ( ! is_array( $metadata ) || empty( $metadata['nxt_optimized_file'] ) ) {
			return $response;
		}
		$optimized_path = self::get_absolute_path( $metadata['nxt_optimized_file'] );
		if ( ! $optimized_path || ! file_exists( $optimized_path ) ) {
			return $response;
		}
		$original_file = get_attached_file( $post->ID );
		if ( ! $original_file ) {
			return $response;
		}
		$format = isset( $metadata['nxt_optimized_format'] ) ? $metadata['nxt_optimized_format'] : 'webp';
		$optimized_full_url = ( 'original' === $format )
			? $this->get_output_url( $original_file )
			: $this->get_output_url( $original_file ) . '.' . $format;
		$response->data['source_url'] = $optimized_full_url;
		if ( isset( $response->data['url'] ) ) {
			$response->data['url'] = $optimized_full_url;
		}
		if ( isset( $response->data['link'] ) ) {
			$response->data['link'] = $optimized_full_url;
		}
		if ( isset( $response->data['media_details']['file'] ) ) {
			$response->data['media_details']['file'] = basename( $optimized_full_url );
		}
		if ( isset( $response->data['mime_type'] ) && 'original' !== $format ) {
			$response->data['mime_type'] = 'image/' . $format;
		}
		// Update each size in media_details so REST media library gets correct thumbnail URLs.
		// For original format, explicitly set source_url to uploads (image_downsize derives wrong nexter-optimizer URLs).
		if ( ! empty( $response->data['media_details']['sizes'] ) && is_array( $response->data['media_details']['sizes'] ) ) {
			$base_dir = dirname( $original_file );
			if ( 'original' === $format ) {
				foreach ( $response->data['media_details']['sizes'] as $size_name => $size_data ) {
					if ( empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
						continue;
					}
					$size_file_path = wp_normalize_path( $base_dir . '/' . $metadata['sizes'][ $size_name ]['file'] );
					$uploads_url    = $this->path_to_url( $size_file_path );
					if ( $uploads_url ) {
						$response->data['media_details']['sizes'][ $size_name ]['source_url'] = $uploads_url;
					}
				}
			} elseif ( ! empty( $metadata['nxt_optimized_sizes'] ) ) {
				foreach ( $response->data['media_details']['sizes'] as $size_name => $size_data ) {
					if ( empty( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] ) || empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
						continue;
					}
					$size_opt_path = self::get_absolute_path( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] );
					if ( ! $size_opt_path || ! file_exists( $size_opt_path ) ) {
						continue;
					}
					$size_file_path = wp_normalize_path( $base_dir . '/' . $metadata['sizes'][ $size_name ]['file'] );
					$size_format    = isset( $metadata['nxt_optimized_sizes'][ $size_name ]['format'] ) ? $metadata['nxt_optimized_sizes'][ $size_name ]['format'] : 'webp';
					$size_full_url  = $this->get_output_url( $size_file_path ) . '.' . $size_format;
					$response->data['media_details']['sizes'][ $size_name ]['source_url'] = $size_full_url;
					if ( isset( $response->data['media_details']['sizes'][ $size_name ]['file'] ) ) {
						$response->data['media_details']['sizes'][ $size_name ]['file'] = basename( $size_full_url );
					}
					if ( isset( $response->data['media_details']['sizes'][ $size_name ]['mime-type'] ) ) {
						$response->data['media_details']['sizes'][ $size_name ]['mime-type'] = 'image/' . $size_format;
					}
				}
			}
		}
		return $response;
	}

	/**
	 * AJAX: Convert a single attachment to optimised format (WebP/AVIF) from Media edit.
	 */
	public function get_optimization_skip_reason( $attachment_id ) {
		if ( ! $attachment_id ) {
			return array( 'skip' => true, 'message' => __( 'Invalid attachment ID.', 'nexter-extension' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array( 'skip' => true, 'message' => __( 'File not found.', 'nexter-extension' ) );
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size || $file_size < 1 ) {
			return array( 'skip' => true, 'message' => __( 'File is empty or invalid. Skipped.', 'nexter-extension' ) );
		}

		$max_size = apply_filters( 'nexter_image_optimizer_max_file_size', 20 * 1024 * 1024 );
		if ( $max_size > 0 && $file_size > $max_size ) {
			return array( 'skip' => true, 'message' => sprintf( __( 'File exceeds maximum size for optimisation (%s). Skipped.', 'nexter-extension' ), size_format( $max_size ) ) );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( 'image/webp' === $mime_type || 'image/avif' === $mime_type ) {
			return array( 'skip' => true, 'message' => __( 'Already optimised.', 'nexter-extension' ) );
		}

		$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
		if ( ! in_array( $mime_type, $valid_mimes, true ) ) {
			return array( 'skip' => true, 'message' => __( 'Unsupported image format. Skipped.', 'nexter-extension' ) );
		}

		$settings = $this->get_settings();
		if ( $file_path && $this->is_path_excluded( $file_path, $settings['exclude_paths'] ) ) {
			return array( 'skip' => true, 'message' => __( 'Image path is excluded from optimisation in settings. Skipped.', 'nexter-extension' ) );
		}

		if ( ! $settings['enabled'] ) {
			return array( 'skip' => true, 'message' => __( 'Image Optimisation is disabled.', 'nexter-extension' ) );
		}

		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );
		$has_webp    = $has_gd && function_exists( 'imagewebp' );
		$can_optimize = $has_imagick || $has_webp || ( $has_gd && 'original' === ( isset( $settings['output_format'] ) ? $settings['output_format'] : 'webp' ) );
		if ( ! $can_optimize ) {
			return array( 'skip' => true, 'message' => __( 'Imagick or GD is required for image optimisation. Skipped.', 'nexter-extension' ) );
		}

		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();
		if ( ! $limit_handler->can_optimize( $attachment_id ) ) {
			return array( 'skip' => true, 'message' => sprintf( __( 'Monthly Optimisation limit reached (%d images). Upgrade to Pro for unlimited Optimisation.', 'nexter-extension' ), $limit_handler->get_monthly_limit() ) );
		}

		return array( 'skip' => false );
	}

	/**
	 * AJAX: Convert a single attachment to optimised format (WebP/AVIF) from Media edit.
	 */
	public function ajax_convert_attachment() {
		check_ajax_referer( 'nxt_ext_image_convert', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nexter-extension' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$skip_reason = $this->get_optimization_skip_reason( $attachment_id );
		if ( ! empty( $skip_reason['skip'] ) && ! empty( $skip_reason['message'] ) ) {
			wp_send_json_error( array( 'message' => $skip_reason['message'] ) );
		}

		$file_path = get_attached_file( $attachment_id );
		$mime_type = get_post_mime_type( $attachment_id );
		$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
		$settings = $this->get_settings();
		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();

		$this->create_optimizer_folders();
		$result = $this->process_image( $file_path, $settings );

		if ( ! $result || empty( $result['success'] ) || empty( $result['file'] ) ) {
			$error_message = ( is_array( $result ) && ! empty( $result['message'] ) )
				? $result['message']
				: __( 'Optimisation failed. The image may be in an unsupported format or state, or the server could not process it.', 'nexter-extension' );
			wp_send_json_error( array( 'message' => $error_message ) );
		}
		if ( ! file_exists( $result['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Optimised file was not saved. Check folder permissions for wp-content/nexter-optimizer.', 'nexter-extension' ) ) );
		}

		$upload_dir         = self::get_upload_dir();
		$basedir            = wp_normalize_path( $upload_dir['basedir'] );
		$original_path      = $file_path;
		$optimized_path     = $result['file'];
		$original_relative  = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $original_path ) ) );
		$original_relative  = ltrim( $original_relative, '/' );
		$optimized_relative = self::absolute_to_relative_content( $optimized_path );

		$backup_dir   = WP_CONTENT_DIR . '/nexter-optimizer/backups';
		$backup_path  = wp_normalize_path( $backup_dir . '/' . $original_relative );
		$backup_parent = dirname( $backup_path );
		if ( ! is_dir( $backup_parent ) ) {
			wp_mkdir_p( $backup_parent );
		}
		if ( ! file_exists( $backup_path ) && file_exists( $original_path ) ) {
			@copy( $original_path, $backup_path );
		}
		$backup_relative = file_exists( $backup_path ) ? self::absolute_to_relative_content( $backup_path ) : '';

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$metadata['nxt_main_original_size']  = $result['original_size'];
		$metadata['nxt_main_optimized_size']  = $result['optimized_size'];
		$metadata['nxt_original_size']       = $result['original_size'];
		$metadata['nxt_optimized_size']      = $result['optimized_size'];
		$metadata['nxt_original_file']       = $original_relative;
		$metadata['nxt_optimized_file']      = $optimized_relative;
		$metadata['nxt_optimized_format']    = $result['format'];
		$metadata['nxt_original_mime']      = isset( $result['original_mime'] ) ? $result['original_mime'] : $mime_type;
		if ( $backup_relative ) {
			$metadata['nxt_backup_file'] = $backup_relative;
		}

		// Convert all thumbnail sizes.
		$total_original  = $result['original_size'];
		$total_optimized  = $result['optimized_size'];
		$sizes_converted = 0;
		$base_dir        = dirname( $file_path );

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$metadata['nxt_optimized_sizes'] = isset( $metadata['nxt_optimized_sizes'] ) ? $metadata['nxt_optimized_sizes'] : array();
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_file_path = wp_normalize_path( $base_dir . '/' . $size_data['file'] );
				if ( ! file_exists( $size_file_path ) ) {
					continue;
				}
				$ft = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $size_file_path, null ) : null;
				$size_mime = is_array( $ft ) && ! empty( $ft['type'] ) ? $ft['type'] : '';
				if ( ! in_array( $size_mime, $valid_mimes, true ) ) {
					continue;
				}
				if ( $this->is_path_excluded( $size_file_path, $settings['exclude_paths'] ) ) {
					continue;
				}
				$size_relative = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $size_file_path ) ) );
				$size_relative = ltrim( $size_relative, '/' );
				$size_backup_path = wp_normalize_path( $backup_dir . '/' . $size_relative );
				$size_backup_parent = dirname( $size_backup_path );
				if ( ! is_dir( $size_backup_parent ) ) {
					wp_mkdir_p( $size_backup_parent );
				}
				if ( ! file_exists( $size_backup_path ) ) {
					@copy( $size_file_path, $size_backup_path );
				}
				$size_backup_rel = file_exists( $size_backup_path ) ? self::absolute_to_relative_content( $size_backup_path ) : '';
				$size_result = $this->process_image( $size_file_path, $settings );
				if ( $size_result && ! empty( $size_result['success'] ) && ! empty( $size_result['file'] ) ) {
					$total_original += $size_result['original_size'];
					$total_optimized += $size_result['optimized_size'];
					$sizes_converted++;
					$metadata['nxt_optimized_sizes'][ $size_name ] = array(
						'file'           => self::absolute_to_relative_content( $size_result['file'] ),
						'format'         => $size_result['format'],
						'original_size'  => $size_result['original_size'],
						'optimized_size' => $size_result['optimized_size'],
						'backup_file'    => $size_backup_rel,
					);
				}
			}
			$metadata['nxt_original_size']  = $total_original;
			$metadata['nxt_optimized_size'] = $total_optimized;
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
		unset( self::$attachment_url_cache[ $attachment_id ] );
		wp_cache_delete( $attachment_id, 'post_meta' );
		clean_post_cache( $attachment_id );

		// Record optimisation credits: 1 (original) + number of thumbnail sizes optimized
		$credit_count = 1 + ( isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ? count( $metadata['nxt_optimized_sizes'] ) : 0 );
		$limit_handler->record_optimization( $attachment_id, (int) $total_original, (int) $total_optimized, $credit_count );

		$this->is_bulk_run = false;

		$saved = $result['original_size'] - $result['optimized_size'];
		$saved_pct = $result['original_size'] > 0 ? round( ( $saved / $result['original_size'] ) * 100, 2 ) : 0;
		$sizes_count = $sizes_converted + 1;
		$data = array(
			'message'         => sprintf( __( 'Image Optimised (%d sizes converted).', 'nexter-extension' ), $sizes_count ),
			'format'          => isset( $result['format'] ) ? $result['format'] : 'webp',
			'original_size'   => $result['original_size'],
			'optimized_size'  => $result['optimized_size'],
			'saved_percent'   => $saved_pct,
			'sizes_converted' => $sizes_count,
		);
		
		// Always include fresh stats for UI update
		$data['stats'] = $limit_handler->get_ui_stats();
		
		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Restore original images from backup
	 */
	public function ajax_restore_originals() {
		check_ajax_referer( 'nexter_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nexter-extension' ) ) );
		}

		$args = array(
			'post_type'               => 'attachment',
			'post_mime_type'          => 'image',
			'post_status'             => 'inherit',
			'posts_per_page'          => -1,
			'fields'                  => 'ids',
			'no_found_rows'           => true,
			'update_post_meta_cache'  => false,
			'update_post_term_cache'  => false,
		);
		$query   = new WP_Query( $args );
		$restored = 0;
		$failed   = 0;

		foreach ( $query->posts as $attachment_id ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( empty( $metadata['nxt_optimized_file'] ) ) {
				continue;
			}

			$restore_target = get_attached_file( $attachment_id );
			if ( ! $restore_target ) {
				$failed++;
				continue;
			}
			$restore_target = wp_normalize_path( $restore_target );
			$backup_path   = ! empty( $metadata['nxt_backup_file'] ) ? self::get_absolute_path( $metadata['nxt_backup_file'] ) : null;

			if ( $backup_path && file_exists( $backup_path ) ) {
				$target_dir = dirname( $restore_target );
				if ( ! is_dir( $target_dir ) ) {
					wp_mkdir_p( $target_dir );
				}
				if ( ! @copy( $backup_path, $restore_target ) ) {
					$failed++;
					continue;
				}
			}
			// If no backup, original file is already at restore_target (we never replace it).

			$optimized_path = self::get_absolute_path( $metadata['nxt_optimized_file'] );
			if ( $optimized_path && file_exists( $optimized_path ) ) {
				@unlink( $optimized_path );
			}

			// Restore all thumbnail sizes from backup and delete optimised size files.
			$base_dir = dirname( $restore_target );
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && ! empty( $metadata['nxt_optimized_sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$size_target_path = wp_normalize_path( $base_dir . '/' . $size_data['file'] );
					$size_backup_path = null;
					if ( ! empty( $metadata['nxt_optimized_sizes'][ $size_name ]['backup_file'] ) ) {
						$size_backup_path = self::get_absolute_path( $metadata['nxt_optimized_sizes'][ $size_name ]['backup_file'] );
					}
					if ( $size_backup_path && file_exists( $size_backup_path ) ) {
						$size_target_dir = dirname( $size_target_path );
						if ( ! is_dir( $size_target_dir ) ) {
							wp_mkdir_p( $size_target_dir );
						}
						@copy( $size_backup_path, $size_target_path );
					}
					if ( ! empty( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] ) ) {
						$size_opt_path = self::get_absolute_path( $metadata['nxt_optimized_sizes'][ $size_name ]['file'] );
						if ( $size_opt_path && file_exists( $size_opt_path ) ) {
							@unlink( $size_opt_path );
						}
					}
				}
			}

			$new_metadata = $metadata;
			unset( $new_metadata['nxt_original_size'], $new_metadata['nxt_optimized_size'], $new_metadata['nxt_main_original_size'], $new_metadata['nxt_main_optimized_size'], $new_metadata['nxt_original_file'], $new_metadata['nxt_original_mime'], $new_metadata['nxt_backup_file'], $new_metadata['nxt_optimized_file'], $new_metadata['nxt_optimized_format'], $new_metadata['nxt_optimized_sizes'] );
			wp_update_attachment_metadata( $attachment_id, $new_metadata );
			unset( self::$attachment_url_cache[ $attachment_id ] );
			// Clear any size-specific cache keys for this attachment.
			foreach ( array_keys( self::$attachment_url_cache ) as $key ) {
				if ( (string) $attachment_id === $key || 0 === strpos( $key, $attachment_id . '_' ) ) {
					unset( self::$attachment_url_cache[ $key ] );
				}
			}
			wp_cache_delete( $attachment_id, 'post_meta' );
			clean_post_cache( $attachment_id );

			// Record restoration in statistics
			$orig_size = (int) ( $metadata['nxt_main_original_size'] ?? 0 );
			$opt_size  = (int) ( $metadata['nxt_main_optimized_size'] ?? 0 );
			Nexter_Ext_Image_Optimization_Limit::get_instance()->record_restoration( $attachment_id, $orig_size, $opt_size );

			$restored++;
		}

		// Clear direct replacement cache after restore to ensure frontend uses original URLs
		// This prevents stale optimised URLs from being used after optimised files are deleted
		self::$direct_replacement_cache = array();

		wp_send_json_success( array(
			'restored' => $restored,
			'failed'   => $failed,
			'message'  => sprintf( __( 'Restored %d images. %d failed.', 'nexter-extension' ), $restored, $failed ),
		) );
	}
}

new Nexter_Ext_Image_Upload_Optimization();
