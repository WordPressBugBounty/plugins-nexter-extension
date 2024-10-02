<?php
/*
 * Manage Image Extension
 * @since
 */
defined('ABSPATH') or die();

class Nexter_Ext_Image_Size {

	public function __construct() {
        if(is_admin()){
            add_action( 'wp_ajax_nexter_ext_images_size', [ $this, 'nexter_ext_image_size_ajax'] );
            add_action( 'wp_ajax_nexter_ext_custom_image_sizes', [ $this, 'nexter_ext_custom_image_size_ajax'] );
            add_action( 'wp_ajax_nexter_ext_delete_image_size', [ $this, 'nexter_ext_delete_image_size_ajax'] );
            //regenerate_thumbnails
            //PopUp Modal
            add_action( 'wp_ajax_nexter_regenerate_thumbnails', [ $this, 'nexter_ext_regenerate_extension'] );
            add_filter( 'nexter-extension-extra-option-config', [$this, 'nexter_ext_add_config'], 10, 1 );
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

		$id = ( isset( $_POST['thumbnail_id'] ) ) ? sanitize_text_field(  $_POST['thumbnail_id']  ) : '';
        $image_sizes_to_be_generated =  ( isset( $_POST['image_sizes_to_be_generated'] ) ) ? sanitize_text_field(  $_POST['image_sizes_to_be_generated']  ) : '' ;
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
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
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

	public function nexter_ext_add_config($config = [] ){
		
		if( !defined('NXT_VERSION') || (defined('NXT_VERSION') && version_compare( NXT_VERSION, '2.0.4', '>' )) ){
			$config['regenerate-thumbnails'] = [
				'title' => esc_html__( 'Regenerate Thumbnails', 'nexter-ext' ),
				'description' => esc_html__( 'Quickly recreate the image thumbnails on your website to ensure they are properly sized and optimized.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/regenerate-thumbnails.svg',
				'priority' => 8,
				'button' => false,
				'beta' => true
			];
		}

        return $config;
	}

	public function nexter_ext_delete_image_size_ajax() {
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$image_size_name = ( isset( $_POST['image_size_name'] ) ) ? sanitize_text_field(  $_POST['image_size_name']  ) : '';
		$custom_sizes = get_option('nexter_custom_image_sizes',array());
		foreach ($custom_sizes as $cs) {
			if ($cs['name'] == $image_size_name) {
				unset($custom_sizes[$image_size_name]);
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

    public function nexter_ext_image_size_ajax(){
        check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
        $ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
        if( !empty($ext) && $ext == 'disabled-image-sizes'){
            $output = '<div class="nxt-ext-modal-content">';
                $output .= '<div class="nxt-modal-title-wrap">';
                    $output .= '<div class="nxt-modal-title">'.esc_html__('Disable Image Sizes','nexter-ext').'</div>';
                $output .= '</div>';
                $output .= '<div class="nxt-disable-admin-wrap">';
                $enabled_is = get_option('nexter_disabled_images',array());
				$get_image_sizes = array_unique(array_merge(get_intermediate_image_sizes(), $enabled_is));
                foreach ($get_image_sizes as $is ){
                    $output .= '<div class="nxt-option-switcher">';
                    $output .= '<span class="nxt-option-check-title">'.$is.'</span>';
                    $output .= '<span class="nxt-option-checkbox-label">';
                    $output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($is).'" name="images_sizes[]" value="'.esc_attr($is).'" '.( in_array($is,$enabled_is) ? "checked" : "" ).'/>';
                    $output .= '<label for="'.esc_attr($is).'"></label>';
                    $output .= '</span>';
                    $output .= '</div>';
                }
                $output .= '</div>';
                $output .= '<button type="button" class="nxt-save-images-sizes"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
            $output .= '</div>';

            wp_send_json_success(
                array(
                    'content'	=> $output,
                )
            );
        }
        wp_send_json_error();
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

	public function nexter_ext_custom_image_size_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		if(!empty($ext) && $ext == 'nexter-custom-image-sizes'){
			$custom_registered_images  = get_option('nexter_custom_image_sizes',array());
            $registered_image = '<div class="">';

				$registered_image .= '<div class="nxt-custom-images-sizes-accordion" id="custom-image-size-accordion" accordion-state="expanded">';
					$registered_image .= '<div class="nxt-custom-images-sizes-list-title">' . esc_html__('List of Custom Image Sizes','nexter-ext') . '<img id="accordion-controller" src="' . esc_url(NEXTER_EXT_URL.'assets/images/panel-icon/accordion-open.svg') . '" />' ;
				$registered_image .= '</div>';
            $table = '<table class="custom-image-size" id="custom-image-size-table">';
					//table to display all registered Custom Image Sizes
							$table .= '<thead id="custom-image-size-thead">';
           						$table .='<tr>';
								$table .= '<th>'. esc_html__('Image Name','nexter-ext') .'</th>';
								$table .= '<th>'. esc_html__('Image Width','nexter-ext') .'</th>';
								$table .= '<th>'. esc_html__('Image Height','nexter-ext') .'</th>';
								$table .= '<th>'. esc_html__('Image Crop','nexter-ext') .'</th>';
								$table .= '<th>'. esc_html__('Actions','nexter-ext') .'</th>';
								$table .='</tr>';
							$table .= '</thead>';
							$table .= '<tbody id="custom-image-size-tbody">';
							if (!empty($custom_registered_images)){
								foreach ($custom_registered_images as $cs){
									$table .= '<tr class="custom-image-size">';
									$table .= '<td>'. esc_html( $cs['name'])   .'</td>';
									$table .= '<td>'. esc_html( $cs['width'])   .'</td>';
									$table .= '<td>'. esc_html( $cs['height'])   .'</td>';
									$table .= '<td>'. esc_html( $cs['crop'])   .'</td>';
									$table .= '<td class="delete-custom-image-size" imagesizename="'.esc_attr($cs['name']).'"> ';
									$table .=  '<img class="delete-custom-image-icon" src="'.esc_url(NEXTER_EXT_URL."assets/images/panel-icon/remove-custom-image.svg").'" />'. esc_html__( 'Delete','nexter-ext') . '</td>';
									$table .= '</tr>';
								}
							} else {
								$table .= '<tr id="no-custom-image"><td colspan="5">' . esc_html__('Sorry!! You’ve not saved any custom image size','nexter-ext') . '</td></tr>';
							}
						$table .= '</tbody>';
					$table .= '</table>';
				$registered_image .= $table;
				$registered_image .= '</div>';
			$registered_image .= '</div>';

            $output = '<div class="nxt-ext-modal-content">';
			$output .= '<div class="nxt-modal-title-wrap">';
			$output .= '<div class="nxt-modal-title">'.esc_html__('Register Custom Image Sizes','nexter-ext'). '</div>';
			$output .= '</div>';
				$output .= '<div class="nxt-imagesize-admin-wrap">';
				$output .= '<div class="nxt-newimagesize-admin-wrap">';
					$output .= '<label class="new-image-size">'.esc_html__('Image Name','nexter-ext').'<input id="image_size_name" type="text" class="nxt-old-url" placeholder="'.esc_attr__('Enter Image Size Name','nexter-ext').'" required></label>';
					$output .= '<label class="new-image-size">'. esc_html__('Width','nexter-ext').'<input id="image_size_width" type="number" class="nxt-old-url" placeholder="'.esc_attr__('Enter Image Width','nexter-ext').'" min="1" required></label>';
					$output .= '<label class="new-image-size">'.esc_html__('Height','nexter-ext').'<input id="image_size_height" type="number" class="nxt-old-url" placeholder="'.esc_attr__('Enter Image Height','nexter-ext').'" min="1" required></label>';
					$output .= '<label class="new-image-size">'.esc_html__('Crop','nexter-ext');
					$output .= '<select class="" id="image_size_crop">
									<option value="1" selected="">'.esc_html__('Default crop','nexter-ext').'</option>
									<option value="0" >'.esc_html__('No crop','nexter-ext').'</option>
									<option value="2">'.esc_html__('Left Top','nexter-ext').'</option>
									<option value="3">'.esc_html__('Center Top','nexter-ext').'</option>
									<option value="4">'.esc_html__('Right Top','nexter-ext') .'</option>
									<option value="5">'.esc_html__('Left Center','nexter-ext').'</option>
									<option value="6">'.esc_html__('Center Center','nexter-ext').'</option>
									<option value="7">'.esc_html__('Right Center','nexter-ext').'</option>
									<option value="8">'.esc_html__('Left Bottom','nexter-ext').'</option>
									<option value="9">'.esc_html__('Center Bottom','nexter-ext').'</option>
									<option value="10">'.esc_html__('Right Bottom','nexter-ext').'</option>
							</select>';
					$output .= '</label>';
				$output .= '</div>';
				$output .= '<div class="nxt-imagesize-seperator"></div>';
				$output .= $registered_image;

			$output .= '</div>';
			$output .= '<button type="button" class="nxt-save-custom-images-sizes"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';

			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
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


	public function nexter_ext_regenerate_extension(){
		check_admin_referer('nexter_admin_nonce','nexter_nonce');
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';

		if(!empty($ext) && $ext == 'regenerate-thumbnails'){
            $output = '<div class="nxt-ext-modal-content">';
			$output .= '<div class="nxt-modal-title-wrap">';
			$output .= '<div class="nxt-modal-title">'. esc_html__('Regenerate Thumbnails', 'nexter-ext').'</div>';
			$output .= '</div>';
			$output .= '<div class="nxt-disable-admin-wrap">';
			$enabled_is = get_option('nexter_disabled_images',array());
			foreach (get_intermediate_image_sizes() as $is ){
                $checked = 'checked';
                if(in_array($is,$enabled_is)){
                    $checked = '';
                }
				$output .= '<div class="nxt-option-switcher">';
				$output .= '<span class="nxt-option-check-title">'.esc_html($is).'</span>';
				$output .= '<span class="nxt-option-checkbox-label">';
				$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($is).'" name="regenerate_this_size" value="'.esc_attr($is).'"' . $checked . '/>';
				$output .= '<label for="'.esc_attr($is).'"></label>';
				$output .= '</span>';
				$output .= '</div>';
			}
			$output .= '</div>';
			$output .= '<button type="button" id="nxt-regenerate-images-sizes"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Regenerate','nexter-ext').'</button>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);

		}
		wp_send_json_error();
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