<?php
/**
 * Content Fields Tag Number
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

if ( ! class_exists( 'Nexter_Fields_Tag_Number' ) ) {

	/**
	 * Numeric fields, for controls that expect a number rather than text.
	 */
	class Nexter_Fields_Tag_Number extends \Elementor\Core\DynamicTags\Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-number';
		}

		/**
		 * Title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field (Number)', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'number' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array( 'number', 'range' );
		}

		/**
		 * Render.
		 *
		 * @return void
		 */
		public function render() {
			$value = $this->nexter_value( true );
			if ( ! is_scalar( $value ) || '' === $value ) {
				return;
			}
			echo esc_html( (string) $value );
		}
	}
}
