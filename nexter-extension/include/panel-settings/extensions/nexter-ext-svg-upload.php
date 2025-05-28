<?php 
/*
 * SVG Upload Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_SVG_Upload {
    
	public static $svg_upload_opt = [];

    /**
     * Constructor
     */
    public function __construct() {
		$this->nxt_get_svg_upload_settings();

		add_filter( 'upload_mimes', [$this, 'add_svg_mime'] );
		add_filter(
			'wp_check_filetype_and_ext',
			[$this, 'confirm_file_type_is_svg'],
			10,
			4
		);
		add_filter( 'wp_handle_sideload_prefilter', [$this, 'sanitize_and_maybe_allow_svg_upload'] );
		add_filter( 'wp_handle_upload_prefilter', [$this, 'sanitize_and_maybe_allow_svg_upload'] );
		add_action(
			'rest_insert_attachment',
			[$this, 'sanitize_after_upload'],
			10,
			3
		);
		add_filter(
			'wp_generate_attachment_metadata',
			[$this, 'generate_svg_metadata'],
			10,
			3
		);
		add_filter( 'wp_calculate_image_srcset', [$this, 'disable_svg_srcset'] );
		if ( !in_array( 'auto-sizes/auto-sizes.php', get_option( 'active_plugins', array() ) ) ) {
			// Only add this filter when https://wordpress.org/plugins/auto-sizes/ is not active to prevent PHP deprecation notice
			add_filter(
				'wp_calculate_image_sizes',
				[$this, 'remove_svg_responsive_image_attr'],
				10,
				3
			);
		}
		add_action( 'wp_ajax_svg_get_attachment_url', [$this, 'get_svg_attachment_url'] );
		add_filter( 'wp_prepare_attachment_for_js', [$this, 'get_svg_url_in_media_library'] );
    }

	private function nxt_get_svg_upload_settings(){

		if(isset(self::$svg_upload_opt) && !empty(self::$svg_upload_opt)){
			return self::$svg_upload_opt;
		}
		
		$option = get_option( 'nexter_site_security' );
		
		if(!empty($option) && isset($option['svg-upload']) && !empty($option['svg-upload']['switch']) && !empty( (array) $option['svg-upload']['values']) ){
			self::$svg_upload_opt = (array) $option['svg-upload']['values'];
		}
		return self::$svg_upload_opt;
	}

	/**
     * Add SVG mime type for media library uploads
     */
    public function add_svg_mime( $mimes ) {

        $current_user = wp_get_current_user();
        $current_user_roles = (array) $current_user->roles; // single dimensional array of role slugs

        if ( count( self::$svg_upload_opt ) > 0 ) {
            // Add mime type for user roles set to enable SVG upload
            foreach ( $current_user_roles as $role ) {
                if ( in_array( $role, self::$svg_upload_opt ) ) {
                    $mimes['svg'] = 'image/svg+xml';
                }
            }
        }

        return $mimes;

    }

	/**
	 * Confirm the real file type is SVG for allowed user roles.
	 */
	public function confirm_file_type_is_svg( $filetypes_extensions, $file, $filename, $mimes ) {
		$current_user_roles = (array) wp_get_current_user()->roles;

		if ( ! empty( self::$svg_upload_opt ) && str_ends_with( $filename, '.svg' ) ) {
			foreach ( $current_user_roles as $role ) {
				if ( in_array( $role, self::$svg_upload_opt, true ) ) {
					$filetypes_extensions['type'] = 'image/svg+xml';
					$filetypes_extensions['ext']  = 'svg';
					break;
				}
			}
		}

		return $filetypes_extensions;
	}

	/**
	 * Sanitize SVG file before upload.
	 */
	public function sanitize_and_maybe_allow_svg_upload( $file ) {
		if ( empty( $file['tmp_name'] ) ) {
			return $file;
		}

		$file_tmp_name = $file['tmp_name']; // full path
        $file_name = isset( $file['name'] ) ? $file['name'] : '';
        $file_type_ext = wp_check_filetype_and_ext( $file_tmp_name, $file_name );
        $file_type = ! empty( $file_type_ext['type'] ) ? $file_type_ext['type'] : '';
		
		if ( 'image/svg+xml' === $file_type ) {
			$original_svg = file_get_contents( $file_tmp_name );

			$sanitizer     = $this->get_svg_sanitizer();
			$sanitized_svg = $sanitizer->sanitize( $original_svg );

			if ( false === $sanitized_svg ) {
				$file['error'] = __( 'SVG could not be sanitized and was not uploaded for security reasons.', 'nexter-extension' );
			} else {
				file_put_contents( $file['tmp_name'], $sanitized_svg );
			}
		}

		return $file;
	}

	/**
	 * Get the SVG sanitizer instance.
	 */
	public function get_svg_sanitizer() {
		if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
			$base_dir = NEXTER_EXT_DIR . 'vendor/enshrined/svg-sanitize/src/';
			$files = [
				'data/AttributeInterface.php',
				'data/TagInterface.php',
				'data/AllowedAttributes.php',
				'data/AllowedTags.php',
				'data/XPath.php',
				'ElementReference/Resolver.php',
				'ElementReference/Subject.php',
				'ElementReference/Usage.php',
				'Exceptions/NestingException.php',
				'Helper.php',
				'Sanitizer.php',
			];
			foreach ( $files as $file ) {
				require_once $base_dir . $file;
			}
		}

		return new \enshrined\svgSanitize\Sanitizer();
	}

	/**
	 * Sanitize SVG after it has been uploaded (e.g. via REST API).
	 */
	public function sanitize_after_upload( $attachment, $request, $creating ) {
		if ( $creating && $attachment instanceof WP_Post ) {
			$file_path     = get_attached_file( $attachment->ID );
			$original_svg  = file_get_contents( $file_path );
			$sanitized_svg = $this->get_svg_sanitizer()->sanitize( $original_svg );

			if ( false !== $sanitized_svg ) {
				file_put_contents( $file_path, $sanitized_svg );
			}
		}
	}

	/**
	 * Generate metadata for an SVG attachment.
	 */
	public function generate_svg_metadata( $metadata, $attachment_id, $context ) {
		if ( get_post_mime_type( $attachment_id ) === 'image/svg+xml' ) {
			$svg_path = get_attached_file( $attachment_id );
			$svg      = simplexml_load_file( $svg_path );

			$width = $height = 0;

			if ( $svg && $svg->attributes() ) {
				$attrs = $svg->attributes();
				if ( isset( $attrs->width, $attrs->height ) ) {
					$width  = (int) $attrs->width;
					$height = (int) $attrs->height;
				} elseif ( isset( $attrs->viewBox ) ) {
					$viewBox = explode( ' ', (string) $attrs->viewBox );
					if ( count( $viewBox ) === 4 ) {
						$width  = (int) $viewBox[2];
						$height = (int) $viewBox[3];
					}
				}
			}

			$metadata['width']  = $width;
			$metadata['height'] = $height;

			$url_path          = str_replace( wp_upload_dir()['baseurl'] . '/', '', wp_get_original_image_url( $attachment_id ) );
			$metadata['file']  = $url_path;
		}

		return $metadata;
	}

	/**
	 * Disable srcset for SVG images to avoid incorrect responsive handling.
	 */
	public function disable_svg_srcset( $sources ) {
		$first = reset( $sources );
		if ( ! empty( $first['url'] ) && pathinfo( $first['url'], PATHINFO_EXTENSION ) === 'svg' ) {
			return [];
		}
		return $sources;
	}

	/**
	 * Remove responsive image `sizes` attribute for SVG images.
	 */
	public function remove_svg_responsive_image_attr( string $sizes, $size, $image_src = null ) {
		if ( pathinfo( $image_src, PATHINFO_EXTENSION ) === 'svg' ) {
			return '';
		}
		return $sizes;
	}

	/**
	 * Return SVG URL for preview in media library (AJAX callback).
	 */
	public function get_svg_attachment_url() {
		$attachment_id = isset( $_REQUEST['attachmentID'] ) ? $_REQUEST['attachmentID'] : '';

		if ( $attachment_id ) {
			echo esc_url( wp_get_attachment_url( (int) $attachment_id ) );
			wp_die();
		}
	}

	/**
	 * Modify SVG response to include a preview image for the media library.
	 */
	public function get_svg_url_in_media_library( $response ) {
		if ( $response['mime'] === 'image/svg+xml' ) {
			$response['image'] = [
				'src' => $response['url'],
			];
		}

		return $response;
	}
}

new Nexter_Ext_SVG_Upload();