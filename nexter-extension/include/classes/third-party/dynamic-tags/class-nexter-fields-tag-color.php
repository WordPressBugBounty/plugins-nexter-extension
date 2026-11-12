<?php
/**
 * Content Fields Tag Color
 *
 * Part of the Content Fields -> Elementor dynamic tags. Required from
 * class-nexter-fields-dynamic-tags.php inside Elementor's register hook, so Elementor's base
 * classes exist by the time this is parsed.
 *
 * @package Nexter
 * @since   4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Nexter_Fields_Tag_Color' ) ) {

	/**
	 * Colour fields, so a Nexter colour can drive an Elementor colour control.
	 */
	class Nexter_Fields_Tag_Color extends \Elementor\Core\DynamicTags\Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-color';
		}

		/**
		 * Title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field (Colour)', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'color' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array( 'color' );
		}

		/**
		 * Render.
		 *
		 * @return void
		 */
		public function render() {
			$value = $this->nexter_value( true );
			if ( ! is_string( $value ) || '' === $value ) {
				return;
			}
			echo esc_attr( $value );
		}
	}
}
