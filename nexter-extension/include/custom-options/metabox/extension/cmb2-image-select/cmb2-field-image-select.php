<?php
/**
 * Class PR_CMB2_Image_Select_Field
 */
class PR_CMB2_Image_Select_Field {

	/**
	 * Current version number
	 */
	const VERSION = '1.0.6';

	/**
	 * Initialize the plugin by hooking into CMB2
	 */
	public function __construct() {
		add_filter( 'cmb2_render_image_select', array( $this, 'cmb2_render_image_select' ), 10, 5 );
	}

	/**
	 * Render Image Select Field
	 */
	public function cmb2_render_image_select( $field, $escaped_value, $object_id, $object_type, $field_type_object ) {
		$this->setup_admin_scripts();
		
		$conditional_value =(isset($field->args['attributes']['data-conditional-value'])? 'data-conditional-value="'.esc_attr($field->args['attributes']['data-conditional-value']).'"':'');
		$conditional_id =(isset($field->args['attributes']['data-conditional-id'])? ' data-conditional-id="'.esc_attr($field->args['attributes']['data-conditional-id']).'"':'');

		$classes = ( isset($field->args['attributes']['class']) ? ' class="'.esc_attr($field->args['attributes']['class']).'"' : '');

		$default_value = (isset($field->args['attributes']['default'])) ? $field->args['attributes']['default'] : '';  
		

		$image_select = '<ul id="'.$field->args['_id'].'" class="cmb2-image-select-list">';

		foreach ( $field->options() as $value => $item ) {

			$selected = ( $value === ( $escaped_value =='' ? $default_value : $escaped_value ) ) ? 'checked="checked"' : '';
			$class_args = (isset($field->args['class'])) ? $field->args['class'] : '';  
			$image_select .= '<li class="cmb2-image-select '.$classes.' '.($selected!= ''?'cmb2-image-select-selected':'').'">
				<label for="' . $field->args['_id'] . esc_attr( '_' ) . esc_attr( $value ) . '" class="' . $class_args . '">
				<input '.$conditional_value.$conditional_id.' type="radio" id="' . $field->args['_id'] . esc_attr( '_' ) . esc_attr( $value ) . '" name="' . $field->args['_name'] . '" value="' . esc_attr( $value ) . '" ' . $selected . ' class="cmb2-option">';
				//$image_select .= '<div class="cmb2-image-select-icon icon-' . $field->args['_id'] . esc_attr( '-' ) . esc_attr( $value ) . '" title="' . $item['alt'] . '"></div>';
				$image_select .= '<img style=" width: auto; " alt="' . $item['alt'] . '" src="' . $item['img'] . '">';
				$image_select .= '<br>
				<span>' . esc_html( $item['title'] ) . '</span>
				</label>
				</li>';
		}

		$image_select .= '</ul>';

		$image_select .= $field_type_object->_desc( true );

		echo $image_select; // phpcs:ignore 
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function setup_admin_scripts() {

		$asset_path = apply_filters( 'pr_cmb2_image_select_asset_path', NEXTER_EXT_URL.'include/custom-options/metabox/extension/cmb2-image-select/' );

		if (is_admin()) {
			wp_enqueue_style( 'cmb2_imgselect-css', $asset_path . 'css/style.css', array(), self::VERSION );
			wp_enqueue_script( 'cmb2_imgselect-js', $asset_path . 'js/style.js', array( 'cmb2-scripts' ), self::VERSION );
		}
	}

}

$pr_cmb2_image_select_field = new PR_CMB2_Image_Select_Field();