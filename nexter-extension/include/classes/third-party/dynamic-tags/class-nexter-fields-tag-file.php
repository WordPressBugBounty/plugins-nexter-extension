<?php
/**
 * Content Fields Tag File
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

if ( ! class_exists( 'Nexter_Fields_Tag_File' ) ) {

	/**
	 * File fields, for Elementor's media category (downloads, video sources).
	 */
	class Nexter_Fields_Tag_File extends \Elementor\Core\DynamicTags\Data_Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-file';
		}

		/**
		 * Title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field (File)', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'media' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array( 'file' );
		}

		/**
		 * Attachment id and url. Raw value, for the same reason as the image tag.
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
				'url' => (string) wp_get_attachment_url( $id ),
			);
		}
	}
}
