<?php
/**
 * Content Fields Tag Text
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

if ( ! class_exists( 'Nexter_Fields_Tag_Text' ) ) {

	/**
	 * Text, and the catch-all for anything that reads as text.
	 *
	 * Category strings are used rather than Elementor's Module constants: the literals are stable
	 * across versions, and a moved constant would fatal at declaration time.
	 */
	class Nexter_Fields_Tag_Text extends \Elementor\Core\DynamicTags\Tag {

		use Nexter_Fields_Tag_Shared;

		/**
		 * Tag name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'nexter-fields-text';
		}

		/**
		 * Title in the dynamic tag list.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Content Field', 'nexter-extension' );
		}

		/**
		 * Categories.
		 *
		 * @return string[]
		 */
		public function get_categories() {
			return array( 'text', 'post_meta' );
		}

		/**
		 * Nexter field types this tag can supply, mirroring ACF's nexter_supported_types().
		 *
		 * @return string[]
		 */
		protected function nexter_supported_types() {
			return array(
				'text',
				'textarea',
				'wysiwyg',
				'email',
				'url',
				'page_link',
				'select',
				'radio',
				'checkbox',
				'true_false',
				'number',
				'range',
				'date',
				'time',
				'datetime',
				'color',
				'taxonomy',
				'user',
				'post_object',
				'relationship',
			);
		}

		/**
		 * Render.
		 *
		 * @return void
		 */
		public function render() {
			$value = $this->nexter_value( true );
			if ( null === $value || '' === $value ) {
				return;
			}
			// Arrays reach here from checkbox / relationship / taxonomy fields; "Array" on the page
			// is never what the editor wanted, so they are joined instead.
			if ( is_array( $value ) ) {
				$flat = array();
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$flat[] = (string) $item;
					} elseif ( is_object( $item ) && isset( $item->post_title ) ) {
						$flat[] = $item->post_title;
					} elseif ( is_array( $item ) && isset( $item['name'] ) ) {
						$flat[] = (string) $item['name'];
					}
				}
				$value = implode( ', ', $flat );
			}
			// wysiwyg is stored as HTML and has already been through wp_kses_post on save.
			echo wp_kses_post( (string) $value );
		}
	}
}
