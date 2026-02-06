<?php 
/*
 * Clean Up Admin Bar Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Image_Upload_Optimization {
    
	private $orientation_fixed = [];

	public $transparent_png = false;

	private $previous_meta = [];

    /**
     * Constructor
     */
    public function __construct() {
		if ( extension_loaded( 'exif' ) && function_exists( 'exif_read_data' ) ) {
			add_filter( 'wp_handle_upload_prefilter', [ $this, 'prefilter_fix_image_orientation' ], 10, 1 );
			add_filter( 'wp_handle_upload', [ $this, 'fix_image_orientation_on_save' ], 1, 3 );
		}
		add_filter( 'wp_handle_upload', [ $this, 'image_upload_handler' ] );
    }

	private function nxt_get_post_order_settings(){

		$img_optimize = [];
		$option = get_option( 'nexter_site_performance' );
		
		if(!empty($option) && isset($option['image-upload-optimize']) && !empty($option['image-upload-optimize']['switch']) && !empty($option['image-upload-optimize']['values']) ){
			$img_optimize = (array) $option['image-upload-optimize']['values'];
		}

		return $img_optimize;
	}

	/**
	 * Pre-filter during file upload to correct image orientation.
	 */
	public function prefilter_fix_image_orientation( $file ) {
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		// Only process JPEG and TIFF images, which may contain EXIF orientation
		if ( in_array( $ext, [ 'jpg', 'jpeg', 'tiff' ], true ) ) {
			$this->correct_image_orientation( $file['tmp_name'] );
		}

		return $file;
	}

	/**
	 * Post-upload filter to fix image orientation after upload to the uploads directory.
	 */
	public function fix_image_orientation_on_save( $file ) {
		$ext = substr( $file['file'], strrpos( $file['file'], '.', -1 ) + 1 );

		if ( in_array( strtolower( $ext ), [ 'jpg', 'jpeg', 'tiff' ], true ) ) {
			$this->correct_image_orientation( $file['file'] );
		}

		return $file;
	}

	/**
	 * Core function to correct image orientation based on EXIF data.
	 */
	public function correct_image_orientation( $path ) {
		static $processed = [];

		// Skip if already processed or file is unreadable
		if ( isset( $this->orientation_fixed[ $path ] ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return;
		}

		// exif_read_data may not be available on all PHP installations
		if ( ! function_exists( 'exif_read_data' ) ) {
			return;
		}

		// Suppress EXIF warnings
		$exif = @exif_read_data( $path );

		// Only process if orientation is present and not already correct (1)
		if ( empty( $exif['Orientation'] ) || $exif['Orientation'] <= 1 ) {
			return;
		}

		// Determine flip/rotate operations needed
		$actions = $this->get_orientation_actions( $exif['Orientation'], $path );

		if ( $actions ) {
			$this->apply_orientation_actions( $path, $actions );
		}

	}

	/**
	 * Returns transformation actions (rotate, flip) based on EXIF orientation value.
	 */
	private function get_orientation_actions( $orientation, $path ) {
		$rotate = false;
		$flip   = false;
		$angle  = 0;

		// Orientation values: https://magnushoff.com/articles/jpeg-orientation/
		switch ( (int) $orientation ) {
			case 2: $flip = [ false, true ]; break;           // Horizontal flip
			case 3: $angle = -180; $rotate = true; break;     // Rotate 180
			case 4: $flip = [ true, false ]; break;           // Vertical flip
			case 5: $angle = -90; $rotate = true; $flip = [ false, true ]; break;
			case 6: $angle = -90; $rotate = true; break;      // Rotate 90 CW
			case 7: $angle = -270; $rotate = true; $flip = [ false, true ]; break;
			case 8:
			case 9:
				$angle = -270; $rotate = true; break;         // Rotate 90 CCW
			case 1:
			default:
				// No action needed
				$this->orientation_fixed[ $path ] = true;
				return false;
		}

		return compact( 'angle', 'rotate', 'flip' );
	}

	/**
	 * Applies the rotation and flipping to the image using WordPress image editor.
	 */
	private function apply_orientation_actions( $path, $actions ) {
		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return false;
		}

		// Backup original EXIF metadata for GD editor (it strips metadata)
		if ( 'WP_Image_Editor_GD' === get_class( $editor ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$this->previous_meta[ $path ] = wp_read_image_metadata( $path );
		}

		// Rotate if needed
		if ( $actions['rotate'] ) {
			$editor->rotate( $actions['angle'] );
		}

		// Flip if needed
		if ( $actions['flip'] ) {
			$editor->flip( $actions['flip'][0], $actions['flip'][1] );
		}

		// Save the modified image
		$editor->save( $path );

		// Mark as processed
		$this->orientation_fixed[ $path ] = true;

		// Restore EXIF metadata if needed
		add_filter( 'wp_read_image_metadata', [ $this, 'restore_image_meta' ], 10, 2 );

		return true;
	}

	/**
	 * Restores original EXIF metadata after editing, with orientation reset to 1.
	 */
	public function restore_image_meta( $meta, $path ) {
		if ( isset( $this->previous_meta[ $path ] ) ) {
			$meta = $this->previous_meta[ $path ];
			$meta['orientation'] = 1; // Mark as corrected
		}
		return $meta;
	}

	/**
	 * Handles image upload: convert BMP/PNG (no alpha) to JPG and resize if needed.
	 */
	public function image_upload_handler( $upload ) {
		$valid_mimes = [
			'image/bmp',
			'image/x-ms-bmp',
			'image/png',
			'image/jpeg',
			'image/jpg',
			'image/webp'
		];
        $disable_conversion = false;

		if ( false !== strpos( $upload['file'], '-ne.' ) ) {
			return $upload;
		}

		// Check if uploaded file is an image and not excluded with '-ne.' in filename
		if ( in_array( $upload['type'], $valid_mimes, true ) && strpos( $upload['file'], '-ne.' ) === false ) {

			if ( ! $disable_conversion ) {
				// Convert BMP to JPG
				if ( in_array( $upload['type'], [ 'image/bmp', 'image/x-ms-bmp' ], true ) ) {
					$upload = $this->maybe_convert_image( 'bmp', $upload );
				}

				// Convert non-transparent PNG to JPG
				if ( $upload['type'] === 'image/png' ) {
					$upload = $this->maybe_convert_image( 'png', $upload );
				}
			}

			// Resize JPEG, PNG, and WebP if necessary
			$resize_types = [ 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' ];

			$settings = $this->nxt_get_post_order_settings();

			if ( ! is_wp_error( $upload ) && in_array( $upload['type'], $resize_types, true ) && filesize( $upload['file'] ) > 0 ) {
				$editor = wp_get_image_editor( $upload['file'] );

				if ( ! is_wp_error( $editor ) ) {
					$size = $editor->get_size();
					
					$max_width = isset( $settings['max_width'] ) ? (int) $settings['max_width'] : 1920;
					$max_height = isset( $settings['max_height'] ) ? (int) $settings['max_height'] : 1920;
					$jpg_quality = (defined('NXT_PRO_EXT') && isset($settings['quality'])) ? intval($settings['quality']) : 85;

					// Resize if larger than max dimensions
					if (
						( isset( $size['width'] ) && $size['width'] > $max_width ) ||
						( isset( $size['height'] ) && $size['height'] > $max_height )
					) {
						$editor->resize( $max_width, $max_height, false );
					}

					// Set quality if JPEG
					if ( in_array( $upload['type'], [ 'image/jpeg', 'image/jpg' ], true ) ) {
						$editor->set_quality( $jpg_quality );
					}

					$editor->save( $upload['file'] );
				}
			}

		}

		return $upload;
    }

	/**
	 * Converts BMP or non-transparent PNG to JPEG.
	 */
	public function maybe_convert_image( $ext, $upload ) {
		$image_obj = null;
		$path = $upload['file'];

		if ( ! is_file( $path ) ) {
			return $upload;
		}

		// Create image object from BMP
		if ( $ext === 'bmp' ) {
			if ( function_exists( 'imagecreatefrombmp' ) ) {
				$image_obj = imagecreatefrombmp( $path );
			} else {
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/custom-fields/bmp-to-image-object.php';
				if ( function_exists( 'nxt_bmp_to_image_object' ) ) {
					$image_obj = nxt_bmp_to_image_object( $path );
				}
			}
		}

		// Handle PNG: check if transparent
		if ( $ext === 'png' ) {
			$this->transparent_png = false;

			if ( function_exists( 'imagecreatefrompng' ) ) {
				$image_obj = imagecreatefrompng( $path );

				list( $w, $h ) = getimagesize( $path );
				for ( $x = 0; $x < $w; $x++ ) {
					for ( $y = 0; $y < $h; $y++ ) {
						$color_idx = imagecolorat( $image_obj, $x, $y );
						$rgba = imagecolorsforindex( $image_obj, $color_idx );
						if ( $rgba['alpha'] > 0 ) {
							$this->transparent_png = true;
							break 2;
						}
					}
				}
			} elseif ( class_exists( 'Imagick' ) ) {
				$imagick = new Imagick();
				$imagick->readImage( $path );
				$alpha = $imagick->getImageChannelRange( Imagick::CHANNEL_ALPHA );
				$this->transparent_png = $alpha['minima'] < $alpha['maxima'];
			}

			// Abort if PNG is transparent
			if ( $this->transparent_png ) {
				return $upload;
			}
		}

		// Security: Validate file path
		$real_path = realpath( $path );
		if ( ! $real_path || ! file_exists( $real_path ) ) {
			return $upload;
		}
		
		// Convert to JPG
		$converted = false;
		$uploads = wp_upload_dir();
		$old_name = wp_basename( $path );
		$new_name = str_ireplace( '.' . $ext, '.jpg', $old_name );
		$new_name = sanitize_file_name( $new_name ); // Security: Sanitize filename
		$new_name = wp_unique_filename( dirname( $path ), $new_name );
		$new_path = $uploads['path'] . '/' . $new_name;
		$new_url = $uploads['url'] . '/' . $new_name;
		
		// Security: Verify new path is within uploads directory
		$real_new_path = realpath( dirname( $new_path ) );
		$real_uploads_path = realpath( $uploads['path'] );
		if ( ! $real_new_path || strpos( $real_new_path, $real_uploads_path ) !== 0 ) {
			return $upload;
		}

		if ( is_object( $image_obj ) ) {
			// Convert using GD
			$converted = imagejpeg( $image_obj, $new_path, 90 );
		} elseif ( class_exists( 'Imagick' ) ) {
			// Convert using Imagick
			$imagick = new Imagick();
			$imagick->readImage( $path );
			$imagick->setImageCompressionQuality( 90 );
			$imagick->setImageFormat( 'jpg' );
			$converted = $imagick->writeImage( $new_path );
			$imagick->clear();
			$imagick->destroy();
		}

		if ( $converted ) {
			// Security: Verify file exists and is writable before deletion
			if ( file_exists( $path ) && is_writable( $path ) ) {
				// Remove original file
				@unlink( $path );
			}

			// Update upload array
			$upload['file'] = $new_path;
			$upload['url'] = $new_url;
			$upload['type'] = 'image/jpeg';
		}

		return $upload;
	}

	/**
	 * Generate a WebP image from PNG or JPG using the GD library.
	 */
	public function gd_generate_webp( $file, $file_extension, $webp_path, $webp_quality ) {
		$image = null;

		// Create GD image resource based on file extension
		switch ( strtolower( $file_extension ) ) {
			case 'png':
				if ( function_exists( 'imagecreatefrompng' ) ) {
					$image = imagecreatefrompng( $file );

					if ( ! $image ) {
						error_log( 'GD: Failed to create image from PNG file: ' . $file );
						return false;
					}
					// Convert to true color if transparency is detected
					if ( $this->transparent_png ) {
						imagepalettetotruecolor( $image );
					}
				}
				break;

			case 'jpg':
			case 'jpeg':
				if ( function_exists( 'imagecreatefromjpeg' ) ) {
					$image = imagecreatefromjpeg( $file );
					if ( ! $image ) {
						error_log( 'GD: Failed to create image from JPEG file: ' . $file );
						return false;
					}
				}
				break;

			default:
				return false; // Unsupported format
		}

		// If image resource was successfully created, generate WebP
		if ( is_resource( $image ) || ( is_object( $image ) && get_resource_type( $image ) === 'gd' ) ) {
			if ( function_exists( 'imagewebp' ) ) {
				imagewebp( $image, $webp_path, $webp_quality );
				imagedestroy( $image );
				return true;
			}
		}

		return false;
	}

}

 new Nexter_Ext_Image_Upload_Optimization();