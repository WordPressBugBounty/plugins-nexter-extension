<?php
/**
 * Content Fields → Elementor dynamic tags.
 *
 * Elementor Pro ships field integrations for ACF, Pods and Toolset but not for Content Fields, so
 * after migrating into this module every dynamic binding in an Elementor layout has nothing to
 * resolve against. These tags close that gap: one per Elementor value category, each listing the
 * group fields whose type can supply that category.
 *
 * @package Nexter
 * @since   4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Both halves have to be present. Elementor supplies the base classes; the Fields module ships
// only in some builds, and without it every tag would offer an empty list.
if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( 'Nxt_Content_Types_Definitions_Repository' ) ) {
	return;
}

if ( ! class_exists( 'Nexter_Fields_Dynamic_Tags' ) ) {

	/**
	 * Registers the tag group and its tags.
	 */
	class Nexter_Fields_Dynamic_Tags {

		/** Dynamic tag group id, shared by every tag below. */
		const GROUP = 'nexter-fields';

		/**
		 * Placeholder option value. Never resolves to a field.
		 *
		 * Declared here rather than in the trait: traits cannot hold constants before PHP 8.2 and
		 * this plugin supports 7.4.
		 */
		const NO_FIELD = '__nxt_none__';

		/**
		 * Instance.
		 *
		 * @var Nexter_Fields_Dynamic_Tags|null
		 */
		private static $instance = null;

		/**
		 * Initiator.
		 *
		 * @return Nexter_Fields_Dynamic_Tags
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			// 'register' replaced 'register_tags' in Elementor 3.5; the old hook only fires a
			// deprecation now, so binding to it would work today and break on the next major.
			add_action( 'elementor/dynamic_tags/register', array( $this, 'register' ) );
		}

		/**
		 * Register the group and every tag.
		 *
		 * @param \Elementor\Core\DynamicTags\Manager $tags Elementor's tag manager.
		 * @return void
		 */
		public function register( $tags ) {
			$tags->register_group(
				self::GROUP,
				array(
					'title' => __( 'Content Fields', 'nexter-extension' ),
				)
			);

			$dir = __DIR__ . '/dynamic-tags/';
			// The trait first: every tag uses it, and these files are required here rather than at
			// load time so Elementor's base classes are guaranteed to exist.
			require_once $dir . 'trait-nexter-fields-tag-shared.php';

			$tag_classes = array(
				'class-nexter-fields-tag-text.php'   => 'Nexter_Fields_Tag_Text',
				'class-nexter-fields-tag-number.php' => 'Nexter_Fields_Tag_Number',
				'class-nexter-fields-tag-url.php'    => 'Nexter_Fields_Tag_URL',
				'class-nexter-fields-tag-color.php'  => 'Nexter_Fields_Tag_Color',
				'class-nexter-fields-tag-image.php'  => 'Nexter_Fields_Tag_Image',
				'class-nexter-fields-tag-file.php'   => 'Nexter_Fields_Tag_File',
			);

			foreach ( $tag_classes as $file => $tag_class ) {
				require_once $dir . $file;
				if ( class_exists( $tag_class ) ) {
					$tags->register( new $tag_class() );
				}
			}
		}
	}

	Nexter_Fields_Dynamic_Tags::get_instance();
}
