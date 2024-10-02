<?php
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'CMB2_Switch_Button' ) ) {
    /**
     * Class CMB2_Radio_Image
     */
    class CMB2_Switch_Button {
        public function __construct() {
            add_action( 'cmb2_render_nxt_switch', array( $this, 'callback' ), 10, 5 );
            add_action( 'admin_enqueue_scripts', array( $this, 'cmb_switch_admin_scripts' ) );
        }
        public function callback($field, $escaped_value, $object_id, $object_type, $field_type_object) {
           $field_name = $field->_name();
           
           $args = array(
                'type'  => 'checkbox',
                'id'	=> $field_name,
                'name'  => $field_name,
                'desc'	=> '',
                'value' => 'on',
            );
           if($escaped_value == 'on'){
           	  $args['checked'] = 'checked';
           }

           echo '<label class="cmb2-switch">';
           echo $field_type_object->input($args);	// phpcs:ignore 
           echo '<span class="nxt-cmb2-slider round"></span>';
           echo '</label>';
           $field_type_object->_desc( true, true );
        }

        public function cmb_switch_admin_scripts() {
			$asset_path = apply_filters( 'pw_cmb2_field_select2_asset_path', NEXTER_EXT_URL . 'include/custom-options/metabox/extension/cmb2-switch-button/' );
			wp_enqueue_style( 'cmb-switch', $asset_path . 'style.css', array(), NEXTER_EXT_VER );
        }
    }
    $cmb2_switch_button = new CMB2_Switch_Button();
}
