<?php
/*
 * Manage Image Extension
 * @since
 */
defined( 'ABSPATH' ) or die();

class Nexter_Ext_Image_Size {

	/**
	 * Cached image sizes list (populated on first call to image_sizes()).
	 *
	 * @var array|null
	 */
	private $cached_image_sizes = null;

	/**
	 * Cached list of disabled size names for this request.
	 *
	 * @var array|null
	 */
	private $cached_disabled_sizes = null;

	/**
	 * Largest batch a single regenerate request will accept.
	 */
	const REGENERATE_BATCH_LIMIT = 25;

	public function __construct() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_nexter_ext_delete_image_size', [ $this, 'nexter_ext_delete_image_size_ajax'] );
			//regenerate_thumbnails

			add_action( 'wp_ajax_nexter_regenerate_image_thumbnails', [ $this, 'nexter_ext_regenerate_image_thumbnails'] );
			add_action( 'wp_ajax_nexter_regenerate_image_thumbnail_by_id', [ $this, 'nexter_ext_regenerate_image_thumbnail_by_id'] );
		}
		add_action( 'init', [ $this, 'nexter_register_custom_image_sizes'] );
		// Fix: was add_filter() — 'init' is an action, not a filter.
		add_action( 'init', [ $this, 'nexter_manage_image_sizes'] );
		// remove_image_size() cannot touch thumbnail/medium/large, so the generation list is
		// filtered too - that is what actually stops the files being written.
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'nexter_filter_disabled_image_sizes' ], 99 );
		add_filter( 'intermediate_image_sizes', [ $this, 'nexter_filter_disabled_size_names' ], 99 );
	}

	/**
	 * Image types this feature will regenerate.
	 *
	 * @return array
	 */
	private function regenerable_mime_types() {
		// webp and avif were missing from the old list, so modern uploads were never regenerated.
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/x-icon', 'image/webp', 'image/avif' );
	}

	/**
	 * Reject the request unless it carries a valid nonce and the caller may manage options.
	 *
	 * @return void
	 */
	private function guard_regenerate_request() {
		// check_admin_referer() answers with an HTML page, which breaks the JSON the dashboard
		// expects and leaves the button stuck; check_ajax_referer() lets us reply in JSON.
		if ( ! check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce', false ) ) {
			wp_send_json_error( array( 'content' => __( 'Security check failed. Please reload the page and try again.', 'nexter-extension' ) ), 403 );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'nexter-extension' ) ), 403 );
		}
	}

	/**
	 * Regenerate a single attachment.
	 *
	 * @param int   $id    Attachment ID.
	 * @param array $sizes Size names to rebuild; empty rebuilds every registered size.
	 * @return true|WP_Error
	 */
	private function regenerate_single( $id, $sizes ) {
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'nxt_bad_attachment', __( 'Invalid attachment.', 'nexter-extension' ) );
		}

		$fullsizepath = get_attached_file( $id );
		if ( empty( $fullsizepath ) ) {
			return new WP_Error( 'nxt_no_file', __( 'Source file not found.', 'nexter-extension' ) );
		}

		// Keep regeneration inside the uploads directory.
		$real_file   = realpath( $fullsizepath );
		$uploads     = wp_upload_dir();
		$real_upload = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( ! $real_file || ! $real_upload || 0 !== strpos( $real_file, $real_upload ) || ! file_exists( $real_file ) ) {
			return new WP_Error( 'nxt_bad_path', __( 'Invalid file path.', 'nexter-extension' ) );
		}

		$metadata = $this->custom_metadata( $id, $fullsizepath, $sizes );

		// An empty result means the file is not a usable image; storing it would wipe the
		// attachment's existing metadata, which the old code did.
		if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
			return new WP_Error( 'nxt_not_image', __( 'File is not a regenerable image.', 'nexter-extension' ) );
		}

		// Return value is deliberately ignored: wp_update_attachment_metadata() reports false when
		// the metadata is unchanged, which is a successful no-op rather than a failure.
		wp_update_attachment_metadata( $id, $metadata );

		return true;
	}

	public function nexter_ext_regenerate_image_thumbnail_by_id(){
		$this->guard_regenerate_request();

		// A batch keeps the round trips down; a single id is still accepted for compatibility.
		$raw_ids = isset( $_POST['thumbnail_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['thumbnail_ids'] ) ) : '';
		$ids     = ! empty( $raw_ids ) ? array_map( 'absint', explode( ',', $raw_ids ) ) : array();
		if ( empty( $ids ) ) {
			$ids = array( isset( $_POST['thumbnail_id'] ) ? absint( wp_unslash( $_POST['thumbnail_id'] ) ) : 0 );
		}
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::REGENERATE_BATCH_LIMIT );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'content' => __( 'Invalid attachment ID.', 'nexter-extension' ) ) );
		}

		$image_sizes_raw = isset( $_POST['image_sizes_to_be_generated'] ) ? sanitize_text_field( wp_unslash( $_POST['image_sizes_to_be_generated'] ) ) : '';
		$requested_sizes = ! empty( $image_sizes_raw ) ? array_map( 'sanitize_key', array_filter( explode( ',', $image_sizes_raw ) ) ) : array();

		// Only ever act on sizes WordPress currently has registered.
		$requested_sizes = array_values( array_intersect( $requested_sizes, array_keys( $this->image_sizes() ) ) );

		// Allow more time for large images or sites with many registered sizes.
		@set_time_limit( 300 );

		$regenerated = array();
		$failed      = array();
		foreach ( $ids as $id ) {
			$result = $this->regenerate_single( $id, $requested_sizes );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'      => $id,
					'message' => $result->get_error_message(),
				);
			} else {
				$regenerated[] = $id;
			}
		}

		wp_send_json_success(
			array(
				'content' => array(
					'regenerated' => $regenerated,
					'failed'      => $failed,
				),
			)
		);
	}

	public function nexter_ext_regenerate_image_thumbnails(){
		$this->guard_regenerate_request();

		// fields=ids with the cache and count flags off keeps this cheap on large media libraries.
		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'post_mime_type'         => $this->regenerable_mime_types(),
			)
		);

		$attachments = array_map( 'absint', (array) $attachments );

		wp_send_json_success(
			array(
				'content' => array(
					'attachment_ids'             => $attachments,
					'total_images_to_regenerate' => count( $attachments ),
				),
			)
		);
	}


	public function nexter_ext_delete_image_size_ajax() {
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$image_size_name = ( isset( $_POST['image_size_name'] ) ) ? sanitize_text_field( wp_unslash( $_POST['image_size_name'] ) ) : '';

		$custom_sizes = get_option( 'nexter_custom_image_sizes',array() );
		foreach ( $custom_sizes as $cs ) {
			$normalized_cs_name = preg_replace( '/\s+/', ' ', trim( $cs['name'] ) );
			if ( $normalized_cs_name === $image_size_name ) {
				unset( $custom_sizes[ $cs['name'] ] );
			}
		}
		$is_image_size_updated = update_option( 'nexter_custom_image_sizes', $custom_sizes );
		if ( $is_image_size_updated ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	public function nexter_register_custom_image_sizes(){
		$get_performance    = Nxt_Options::performance();
		$enable_custom_size = true;
		if ( ! empty( $get_performance ) && isset( $get_performance['nexter-custom-image-sizes'] ) && isset( $get_performance['nexter-custom-image-sizes']['switch'] ) ) {
			$enable_custom_size = $get_performance['nexter-custom-image-sizes']['switch'];
		}
		$custom_sizes = get_option( 'nexter_custom_image_sizes' );
		if ( ! empty( $custom_sizes ) && ! empty( $enable_custom_size ) ) {
			foreach ( $custom_sizes as $cs ) {
				if ( $cs['crop'] == 0 ) {
					// Fix: was == (comparison, result discarded); must be = (assignment).
					$cs['crop'] = false;
				} else if ( $cs['crop'] == 1 ) {
					// Fix: was == (comparison, result discarded); must be = (assignment).
					$cs['crop'] = true;
				} else {
					$crop_name = $this->get_image_crop_name( $cs['crop'] );{
					if ( isset( $crop_name['x'] ) && isset( $crop_name['y'] ) ) {
						$cs['crop'] = array();
						array_push( $cs['crop'],$crop_name['x'],$crop_name['y'] );
					}
					}
				}
				if ( ! isset( $cs['width'] ) ) {
					$cs['width'] = 0;
				}
				if ( ! isset( $cs['height'] ) ) {
					$cs['height'] = 0;
				}
				add_image_size( $cs['name'],$cs['width'],$cs['height'],$cs['crop'] );
			}
		}
	}

	/**
	 * Size names the user has switched off, from the extension option or the legacy option.
	 *
	 * @return array
	 */
	private function get_disabled_image_sizes() {
		if ( null !== $this->cached_disabled_sizes ) {
			return $this->cached_disabled_sizes;
		}

		$get_performance = Nxt_Options::performance();
		$get_performance = is_object( $get_performance ) ? (array) $get_performance : $get_performance;

		$node = ( is_array( $get_performance ) && isset( $get_performance['disabled-image-sizes'] ) ) ? $get_performance['disabled-image-sizes'] : null;
		$node = is_object( $node ) ? (array) $node : $node;

		if ( is_array( $node ) && ! empty( $node['switch'] ) && isset( $node['values'] ) ) {
			$disabled = (array) $node['values'];
		} else {
			$disabled = get_option( 'nexter_disabled_images' );
		}

		$this->cached_disabled_sizes = is_array( $disabled ) ? array_values( array_filter( array_map( 'strval', $disabled ) ) ) : array();

		return $this->cached_disabled_sizes;
	}

	// Fix: removed unused $sizes parameter — this is an action callback, not a filter.
	public function nexter_manage_image_sizes(){
		// Only clears sizes added with add_image_size(); the core sizes are handled by the filters
		// below, which is where the generated files are actually decided.
		foreach ( $this->get_disabled_image_sizes() as $size ) {
			remove_image_size( $size );
		}
	}

	/**
	 * Drop disabled sizes from the list WordPress is about to generate.
	 *
	 * @param array $sizes Sizes keyed by name.
	 * @return array
	 */
	public function nexter_filter_disabled_image_sizes( $sizes ) {
		if ( ! is_array( $sizes ) ) {
			return $sizes;
		}

		foreach ( $this->get_disabled_image_sizes() as $size ) {
			unset( $sizes[ $size ] );
		}

		return $sizes;
	}

	/**
	 * Same, for callers that deal in a plain list of size names.
	 *
	 * @param array $sizes Size names.
	 * @return array
	 */
	public function nexter_filter_disabled_size_names( $sizes ) {
		$disabled = $this->get_disabled_image_sizes();

		return ( ! empty( $disabled ) && is_array( $sizes ) ) ? array_values( array_diff( $sizes, $disabled ) ) : $sizes;
	}

	/**
	 * Build attachment metadata, regenerating only the requested sizes (or all if none specified).
	 *
	 * @param int    $thumbnail_id                 Attachment ID.
	 * @param string $thumbnail                    Full path to the original image.
	 * @param array  $image_sizes_to_be_generated  Size names to regenerate; empty = regenerate all.
	 * @return array Attachment metadata array.
	 */
	function custom_metadata( $thumbnail_id, $thumbnail, $image_sizes_to_be_generated = array() ) {
		$attachment         = get_post( $thumbnail_id );
		$thumbnail_metadata = array();
		if ( preg_match( '!^image/!', get_post_mime_type( $attachment ) ) && file_is_displayable_image( $thumbnail ) ) {
			$imagesize                            = getimagesize( $thumbnail );
			$thumbnail_metadata['width']          = $imagesize[0];
			$thumbnail_metadata['height']         = $imagesize[1];
			list($uwidth, $uheight)               = wp_constrain_dimensions( $thumbnail_metadata['width'], $thumbnail_metadata['height'], 128, 96 );
			$thumbnail_metadata['hwstring_small'] = sprintf( "height='%s' width='%s'", $uheight, $uwidth );
			$thumbnail_metadata['file']           = _wp_relative_upload_path( $thumbnail );

			// Pre-load existing size metadata so sizes not being regenerated are preserved intact.
			$existing_metadata           = wp_get_attachment_metadata( $thumbnail_id );
			$thumbnail_metadata['sizes'] = ( ! empty( $existing_metadata['sizes'] ) && is_array( $existing_metadata['sizes'] ) )
				? $existing_metadata['sizes']
				: array();

			$sizes = $this->image_sizes();
			foreach ( $sizes as $size => $size_data ) {
				// Fix: was isset($image_sizes_to_be_generated) which is ALWAYS true (even for array()).
				// Use !empty() instead: when list is empty → regenerate all; when non-empty → skip sizes not listed.
				if ( ! empty( $image_sizes_to_be_generated ) && ! in_array( $size, $image_sizes_to_be_generated ) ) {
					// Not in the requested list — existing metadata already loaded above; nothing to do.
					continue;
				}
				$intermediate_size = image_make_intermediate_size( $thumbnail, $size_data['width'], $size_data['height'], $size_data['crop'] );
				if ( $intermediate_size ) {
					$thumbnail_metadata['sizes'][ $size ] = $intermediate_size;
				}
			}
			$image_meta = wp_read_image_metadata( $thumbnail );
			if ( $image_meta ) {
				$thumbnail_metadata['image_meta'] = $image_meta;
			}
		}
		return apply_filters( 'wp_generate_attachment_metadata', $thumbnail_metadata, $thumbnail_id );
	}

	/**
	 * Return all registered image sizes with their dimensions.
	 *
	 * Result is cached per request so repeated calls within one AJAX handler (e.g. when
	 * processing many attachments in a loop) never re-query the database.
	 *
	 * @return array Keyed by size name: { name, width, height, crop }.
	 */
	function image_sizes() {
		// Return cached result to avoid repeated DB queries.
		if ( null !== $this->cached_image_sizes ) {
			return $this->cached_image_sizes;
		}

		// wp_get_registered_image_subsizes() (WP 5.3+) replaces multiple get_option() calls
		// per size with a single, internally-cached read — much faster for large size lists.
		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			$registered = wp_get_registered_image_subsizes();
			$sizes      = array();
			foreach ( $registered as $size_name => $size_data ) {
				$sizes[ $size_name ] = array(
					'name'   => $size_name,
					'width'  => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
					'height' => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
					'crop'   => isset( $size_data['crop'] ) ? $size_data['crop'] : false,
				);
			}
			$sizes                    = apply_filters( 'intermediate_image_sizes_advanced', $sizes );
			$this->cached_image_sizes = $sizes;
			return $sizes;
		}

		// Fallback for WP < 5.3: build from global and per-option DB reads.
		global $_wp_additional_image_sizes;
		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			$sizes[ $size ] = array(
				'name'   => $size,
				'width'  => '',
				'height' => '',
				'crop'   => false,
			);
			if ( isset( $_wp_additional_image_sizes[ $size ]['width'] ) ) {
				$sizes[ $size ]['width'] = intval( $_wp_additional_image_sizes[ $size ]['width'] );
			} else {
				$sizes[ $size ]['width'] = get_option( "{$size}_size_w" );
			}

			if ( isset( $_wp_additional_image_sizes[ $size ]['height'] ) ) {
				$sizes[ $size ]['height'] = intval( $_wp_additional_image_sizes[ $size ]['height'] );
			} else {
				$sizes[ $size ]['height'] = get_option( "{$size}_size_h" );
			}

			if ( isset( $_wp_additional_image_sizes[ $size ]['crop'] ) ) {
				if ( ! is_array( $sizes[ $size ]['crop'] ) ) {
					$sizes[ $size ]['crop'] = intval( $_wp_additional_image_sizes[ $size ]['crop'] );
				} else {
					$sizes[ $size ]['crop'] = $_wp_additional_image_sizes[ $size ]['crop'];
				}
			} else {
				$sizes[ $size ]['crop'] = get_option( "{$size}_crop" );
			}
		}
		$sizes                    = apply_filters( 'intermediate_image_sizes_advanced', $sizes );
		$this->cached_image_sizes = $sizes;
		return $sizes;
	}

	private function get_image_crop_name($crop){
		$name = array();
		switch ( $crop ) {
			case 2:
				$name['x'] = 'left';
				$name['y'] = 'top';
				break;
			case 3:
				$name['x'] = 'center';
				$name['y'] = 'top';
				break;
			case 4:
				$name['x'] = 'right';
				$name['y'] = 'top';
				break;
			case 5:
				$name['x'] = 'left';
				$name['y'] = 'center';
				break;
			case 6:
				$name['x'] = 'center';
				$name['y'] = 'center';
				break;
			case 7:
				$name['x'] = 'right';
				$name['y'] = 'center';
				break;
			case 8:
				$name['x'] = 'left';
				$name['y'] = 'bottom';
				break;
			case 9:
				$name['x'] = 'center';
				$name['y'] = 'bottom';
				break;
			case 10:
				$name['x'] = 'right';
				$name['y'] = 'bottom';
				break;
		}
		return $name;
	}

}

new Nexter_Ext_Image_Size();
