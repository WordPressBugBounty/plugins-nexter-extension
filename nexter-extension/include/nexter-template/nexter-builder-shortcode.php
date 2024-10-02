<?php
/**
 * Nexter Builder Shortcode
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Builder_Shortcode' ) ) {

	class Nexter_Builder_Shortcode {
		
		const NXT_SHORTCODE = 'nexter-builder';
		
		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 *  Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 *  Constructor
		 */
		public function __construct() {
			$this->add_actions_shortcode();
		}
		
		private function add_actions_shortcode(){
			if ( is_admin() ) {
				add_action( 'manage_' . NXT_BUILD_POST . '_posts_columns', [ $this, 'admin_columns_shortcode' ],15 );
				add_action( 'manage_' . NXT_BUILD_POST . '_posts_custom_column', [ $this, 'admin_columns_shortcode_content' ], 15, 2 );
			}

			add_shortcode( self::NXT_SHORTCODE, [ $this, 'create_shortcode' ] );
		}
		
		public function admin_columns_shortcode( $columns ) {
			$columns['nxt_shortcode'] = __( 'Shortcode', 'nexter-ext' );

			return $columns;
		}
	
		public function admin_columns_shortcode_content( $column, $post_id ) {
			if ( 'nxt_shortcode' === $column ) {
				//translator %s = shortcode, %d = post_id
				$shortcode = esc_attr( sprintf( '[%s id="%d"]', self::NXT_SHORTCODE, $post_id ) );
				printf( '<input type="text" class="nxt-shortcode-input" onfocus="this.select()" value="%s" readonly style="font-size: 12px;"/>', esc_attr($shortcode) );
			}
		}
		
		public function create_shortcode( $option = [] ) {
			if ( empty( $option['id'] ) ) {
				return '';
			}
			if(class_exists('Nexter_Gutenberg_Editor')){
				$load_css = new Nexter_Gutenberg_Editor();
				$load_css->enqueue_scripts($option['id']);
			}
			ob_start();
				Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $option['id'] );
			return ob_get_clean();
		}
	}
}

Nexter_Builder_Shortcode::get_instance();