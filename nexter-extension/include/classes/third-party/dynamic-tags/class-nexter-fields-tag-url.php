<?php
/**
 * Content Fields Tag URL
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

if ( ! class_exists( 'Nexter_Fields_Tag_URL' ) ) {

	/**
	 * Link targets: a URL field, a page link, or the file/image behind an attachment id.
	 */
	class Nexter_Fields_Tag_URL extends \Elementor\Core\DynamicTags\Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-url';
		}

		/**
		 * Title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field (URL)', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'url' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array( 'url', 'page_link', 'link', 'file', 'image', 'post_object' );
		}

		/**
		 * Render.
		 *
		 * @return void
		 */
		public function render() {
			$value = $this->nexter_value( true );

			// link fields format to an array, and image/file to an attachment id or array — all of
			// which have to collapse to one href.
			if ( is_array( $value ) ) {
				if ( isset( $value['url'] ) ) {
					$value = $value['url'];
				} elseif ( isset( $value['id'] ) ) {
					$value = wp_get_attachment_url( (int) $value['id'] );
				} else {
					$value = '';
				}
			} elseif ( is_numeric( $value ) ) {
				$value = wp_get_attachment_url( (int) $value );
			} elseif ( is_object( $value ) && isset( $value->ID ) ) {
				$value = get_permalink( $value->ID );
			}

			if ( ! is_string( $value ) || '' === $value ) {
				return;
			}
			echo esc_url( $value );
		}
	}
}
