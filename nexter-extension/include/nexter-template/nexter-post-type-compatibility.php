<?php
/**
 * Nexter Builder Post Compatibility
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */

if ( ! class_exists( 'Nexter_Post_Type_Compatibility' ) ) {

	class Nexter_Post_Type_Compatibility {

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 * Initiator
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
			// Divi Builder.
			add_filter( 'et_builder_post_types', array( $this, 'divi_builder_compatibility' ) );
			//Beaver Builder
			add_filter( 'fl_builder_post_types', array( $this, 'bb_builder_compatibility' ), 10, 1 );
		}

		/**
		 * Add Divi Builder to Conditional Hooks post type.
		 *
		 * @param array $post_types Array of post types.
		 */
		public function divi_builder_compatibility( $post_types ) {
			$post_types[] = NXT_BUILD_POST;
			return $post_types;
		}
		
		/**
		 * Add Beaver Builder to Conditional Hooks post type.
		 *
		 * @param array $value Array of post types.
		 */
		public function bb_builder_compatibility( $value ) {
			$value[] = NXT_BUILD_POST;
			return $value;
		}

	}
}

Nexter_Post_Type_Compatibility::get_instance();