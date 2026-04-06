<?php
/**
 * Image Processor
 *
 * Handles core image compression and format conversion (Imagick/GD).
 * Extracted from Nexter_Ext_Image_Upload_Optimization.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Image_Processor {

	/**
	 * Parent optimizer instance for shared data access.
	 *
	 * @var Nexter_Ext_Image_Upload_Optimization
	 */
	private $parent;

	/** @var array|null Cached Imagick format list per request. */
	private static $imagick_formats_cache = null;

	public function __construct( $parent ) {
		$this->parent = $parent;
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
	public static function calculate_dimensions( $width, $height, $max_width, $max_height ) {
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
			$base_output_path = $this->parent->get_output_path( $file_path );
			$result           = apply_filters( 'nexter_image_optimizer_process_pro_formats', null, $file_path, $settings, $base_output_path, $original_size );
			if ( is_array( $result ) && ! empty( $result['success'] ) ) {
				return $result;
			}
			return false;
		}
		$base_output_path = $this->parent->get_output_path( $file_path );
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
		$output_path   = $this->parent->get_output_path( $file_path );
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
	 * Process with Imagick: resize, strip exif, convert to webp/avif.
	 * Saves to nexter-optimizer/uploads (same relative path as uploads, with .webp/.avif extension).
	 *
	 * @param string $file_path         Full path to image.
	 * @param array  $settings          Settings from get_settings().
	 * @param int    $original_size     Pre-computed file size in bytes.
	 * @param string $base_output_path  Pre-computed output path (no extension).
	 * @return array|false
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
	 * @return array|false
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
}
