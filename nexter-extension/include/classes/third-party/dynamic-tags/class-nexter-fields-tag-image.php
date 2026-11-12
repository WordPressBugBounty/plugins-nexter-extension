<?php
/**
 * Content Fields Tag Image
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

if ( ! class_exists( 'Nexter_Fields_Tag_Image' ) ) {

	/**
	 * Image fields. A Data_Tag, because Elementor's image controls want { id, url } rather than
	 * rendered output.
	 */
	class Nexter_Fields_Tag_Image extends \Elementor\Core\DynamicTags\Data_Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-image';
		}

		/**
		 * Title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field (Image)', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'image' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array( 'image' );
		}

		/**
		 * Attachment id and url.
		 *
		 * The RAW value is read, not the formatted one: an image field's return_format can be set
		 * to url or id per field, and Elementor needs both halves regardless of that choice.
		 *
		 * @param array $options Unused.
		 * @return array
		 */
		public function get_value( array $options = array() ) {
			$raw = $this->nexter_value( false );
			$id  = 0;
			if ( is_numeric( $raw ) ) {
				$id = (int) $raw;
			} elseif ( is_array( $raw ) && isset( $raw['id'] ) ) {
				$id = (int) $raw['id'];
			}
			if ( ! $id ) {
				return array(
					'id'  => 0,
					'url' => '',
				);
			}
			return array(
				'id'  => $id,
				'url' => (string) wp_get_attachment_image_url( $id, 'full' ),
			);
		}
	}
}
