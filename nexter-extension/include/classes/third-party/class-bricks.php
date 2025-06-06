<?php
/**
 * Nexter Bricks Compatibility
 *
 * @package Nexter
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Bricks_Builder' ) ) {

	class Nexter_Bricks_Builder extends Nexter_Builder_Compatibility {

		/**
		 * Instance
		 */
		private static $instance;
		
		/**
		 * Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		
		/**
		 * Render content for post.
		 */
		public function render_content( $post_id ) {
			if ( class_exists( 'Bricks\Database' ) && class_exists( 'Bricks\Frontend' ) ) {
				$template_type = get_post_meta( $post_id, '_bricks_template_type', true );
			
				if ( ! empty( $template_type ) ) {
					$bricks_data = Bricks\Database::get_data( $post_id, 'content' );

					if ( ! empty( $bricks_data ) ) {
						Bricks\Frontend::render_content( $bricks_data );
					}
				}
			}
		}

		/**
		 * Load enqueue styles and scripts.
		 */
		public function enqueue_scripts( $post_id ) {

			if ( $post_id !== '' && class_exists( 'Bricks\Database' ) && class_exists( 'Bricks\Templates' )) {
				$bricks_data = Bricks\Database::get_data( $post_id, 'content' );
				$template_inline_css = Bricks\Templates::generate_inline_css( $post_id, $bricks_data );

				$template_inline_css .= Bricks\Assets::$inline_css_dynamic_data;
				
				echo "<style data-template-id=\"{$post_id}\" id=\"bricks-inline-css-template-{$post_id}\">{$template_inline_css}</style>";
			} 
		}

	}

}