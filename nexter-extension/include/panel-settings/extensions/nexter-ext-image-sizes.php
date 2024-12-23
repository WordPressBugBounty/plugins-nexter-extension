<?php
/*
 * Manage Image Extension
 * @since
 */
defined('ABSPATH') or die();

class Nexter_Ext_Image_Size {

	public function __construct() {
        if(is_admin()){
            add_action( 'wp_ajax_nexter_ext_delete_image_size', [ $this, 'nexter_ext_delete_image_size_ajax'] );
            //regenerate_thumbnails
            
            add_action( 'wp_ajax_nexter_regenerate_image_thumbnails', [ $this, 'nexter_ext_regenerate_image_thumbnails'] );
            add_action( 'wp_ajax_nexter_regenerate_image_thumbnail_by_id', [ $this, 'nexter_ext_regenerate_image_thumbnail_by_id'] );
        }
		add_action( 'init', [ $this, 'nexter_register_custom_image_sizes'] );
        add_filter( 'init', [ $this, 'nexter_manage_image_sizes'] );
	}
	public function nexter_ext_regenerate_image_thumbnail_by_id(){
		check_admin_referer('nexter_admin_nonce','nexter_nonce');

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$id = ( isset( $_POST['thumbnail_id'] ) ) ? sanitize_text_field(  wp_unslash($_POST['thumbnail_id'])  ) : '';
        $image_sizes_to_be_generated =  ( isset( $_POST['image_sizes_to_be_generated'] ) ) ? sanitize_text_field(  wp_unslash($_POST['image_sizes_to_be_generated'])  ) : '' ;
        $image_sizes_to_be_generated = explode(',',$image_sizes_to_be_generated);
        $fullsizepath = get_attached_file( $id );

        if ( FALSE !== $fullsizepath && @file_exists( $fullsizepath ) ) {
            set_time_limit( 60 );
            $updated_metadata = $this->custom_metadata( $id, $fullsizepath, $image_sizes_to_be_generated );
            $status = wp_update_attachment_metadata( $id, $updated_metadata );
            $result = array( 'content'	=> $status,);
            wp_send_json_success($result);
        }
	}

	public function nexter_ext_regenerate_image_thumbnails(){

		check_admin_referer('nexter_admin_nonce','nexter_nonce');
		
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-extension' ),
				)
			);
		}

        $output = array();
		$args = array (
			'post_type'=>'attachment',
			'numberposts'=>null,
			'post_status'=>null,
			'posts_per_page'=> -1,
			'fields' => 'ids',
			'post_mime_type' => array( 'image/jpeg', 'image/gif', 'image/png', 'image/bmp', 'image/tiff', 'image/x-icon' ),
        );
		$attachments = get_posts ($args);
		$output ['attachment_ids'] = $attachments;
		$output['total_images_to_regenerate'] = count($output['attachment_ids']);

		wp_send_json_success(
			array(
				'content'	=> $output,
			)
		);

	}


	public function nexter_ext_delete_image_size_ajax() {
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$image_size_name = ( isset( $_POST['image_size_name'] ) ) ? sanitize_text_field(  wp_unslash($_POST['image_size_name'])  ) : '';
		
        $custom_sizes = get_option('nexter_custom_image_sizes',array());
		foreach ($custom_sizes as $cs) {
            $normalized_cs_name = preg_replace('/\s+/', ' ', trim($cs['name']));
            if ($normalized_cs_name === $image_size_name) {
                unset($custom_sizes[$cs['name']]);
            }
		}
		$is_image_size_updated = update_option('nexter_custom_image_sizes', $custom_sizes);
		if($is_image_size_updated){
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	public function nexter_register_custom_image_sizes(){
		$custom_sizes = get_option('nexter_custom_image_sizes');
		if( !empty( $custom_sizes ) ){
			foreach($custom_sizes as $cs){
				if ($cs['crop'] == 0 ){
					$cs['crop'] == false;
				}else if ($cs['crop'] == 1 ) {
					$cs['crop'] == true;
				} else {
					$crop_name = $this->get_image_crop_name($cs['crop']);{
						if(isset($crop_name['x']) && isset($crop_name['y'])){
							$cs['crop'] = array();
							array_push($cs['crop'],$crop_name['x'],$crop_name['y']);
						}
					}
				}
				if(!isset($cs['width'])){
					$cs['width'] = 0;
				}
				if(!isset($cs['height'])){
					$cs['height'] = 0;
				}
				add_image_size($cs['name'],$cs['width'],$cs['height'],$cs['crop']);
			}
		}
	}

	public function nexter_manage_image_sizes( $sizes ){
		
		$disabled_is = get_option('nexter_disabled_images');
		if(is_array($disabled_is)){
			foreach ( get_intermediate_image_sizes() as $size ) {
				if ( in_array( $size, $disabled_is ) ) {
					remove_image_size( $size );
				}
			}
		}
	}

    function custom_metadata( $thumbnail_id, $thumbnail, $image_sizes_to_be_generated = NULL ) {
        $attachment = get_post( $thumbnail_id );
        $thumbnail_metadata = array();
        if ( preg_match( '!^image/!', get_post_mime_type( $attachment ) ) && file_is_displayable_image( $thumbnail ) ) {
            $imagesize = getimagesize( $thumbnail );
            $thumbnail_metadata['width'] = $imagesize[0];
            $thumbnail_metadata['height'] = $imagesize[1];
            list($uwidth, $uheight) = wp_constrain_dimensions($thumbnail_metadata['width'], $thumbnail_metadata['height'], 128, 96);
            $thumbnail_metadata['hwstring_small'] = sprintf( "height='%s' width='%s'", $uheight, $uwidth );
            $thumbnail_metadata['file'] = _wp_relative_upload_path( $thumbnail );
            $sizes = $this->image_sizes();
            foreach ( $sizes as $size => $size_data ) {
                if( isset( $image_sizes_to_be_generated ) && ! in_array( $size, $image_sizes_to_be_generated ) ) {
                    $intermediate_size = image_get_intermediate_size( $thumbnail_id, $size_data['name'] );
                }
                else {
                    $intermediate_size = image_make_intermediate_size( $thumbnail, $size_data['width'], $size_data['height'], $size_data['crop'] );
                }
                if ( $intermediate_size ) {
                    $thumbnail_metadata['sizes'][$size] = $intermediate_size;
                }
            }
            $image_meta = wp_read_image_metadata( $thumbnail );
            if ( $image_meta ) {
                $thumbnail_metadata['image_meta'] = $image_meta;
            }
        }
        return apply_filters( 'wp_generate_attachment_metadata', $thumbnail_metadata, $thumbnail_id );
    }

    function image_sizes() {
        global $_wp_additional_image_sizes;
        $sizes = array();
        foreach ( get_intermediate_image_sizes() as $size ) {
            $sizes[$size] = array(
                'name'   => '',
                'width'  => '',
                'height' => '',
                'crop'   => FALSE
            );
            $sizes[$size]['name'] = $size;
            if ( isset( $_wp_additional_image_sizes[$size]['width'] ) ) {
                $sizes[$size]['width'] = intval( $_wp_additional_image_sizes[$size]['width'] );
            }
            else {
                $sizes[$size]['width'] = get_option( "{$size}_size_w" );
            }

            if ( isset( $_wp_additional_image_sizes[$size]['height'] ) ) {
                $sizes[$size]['height'] = intval( $_wp_additional_image_sizes[$size]['height'] );
            }
            else {
                $sizes[$size]['height'] = get_option( "{$size}_size_h" );
            }

            if ( isset( $_wp_additional_image_sizes[$size]['crop'] ) ) {
                if( ! is_array( $sizes[$size]['crop'] ) ) {
                    $sizes[$size]['crop'] = intval( $_wp_additional_image_sizes[$size]['crop'] );
                }
                else {
                    $sizes[$size]['crop'] = $_wp_additional_image_sizes[$size]['crop'];
                }
            }
            else {
                $sizes[$size]['crop'] = get_option( "{$size}_crop" );
            }
        }

        $sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

        return $sizes;
    }

    private function get_image_crop_name($crop){
        $name = array();
        switch ($crop){
            case 2:
                $name['x'] =  'left';
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