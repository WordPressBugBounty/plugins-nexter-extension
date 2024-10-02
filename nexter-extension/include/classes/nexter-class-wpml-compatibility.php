<?php
/**
 * Nexter Builder WPML Compatibility
 *
 * @package Nexter Extensions
 * @since 2.0.3
 */
if ( ! class_exists( 'Nexter_Builder_Wpml_Compatibility' ) ) {

	// @codingStandardsIgnoreStart
	class Nexter_Builder_Wpml_Compatibility { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	// @codingStandardsIgnoreEnd

		/**
		 * Instance
		 */
		private static $instance = null;

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
		 * Constructor
		 */
		private function __construct() {
			add_filter( 'nexter_get_sections_posts_by_conditions', array( $this, 'get_sections_posts_wpml_comp' ), 10, 2 );
		}

		/**
		 * Nexter Builder Current page display templates to WPML's filter translated.
		 * @since  2.0.3
		 */
		public function get_sections_posts_wpml_comp( $current_posts_data, $post_type ) {
			if ( $post_type === 'nxt_builder' ) {

				$wpml_posts = $current_posts_data;

				foreach ( $current_posts_data as $post_id => $post_data ) {

					$wpml_post_id = apply_filters( 'wpml_object_id', $post_id, 'nxt_builder', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

					if ( $wpml_post_id !== null ) {
						if ( $post_id !== $wpml_post_id && isset( $wpml_posts[ $post_id ] ) ) {
							$wpml_posts[ $wpml_post_id ] = $wpml_posts[ $post_id ];
							$wpml_posts[ $wpml_post_id ]['id'] = $wpml_post_id;

							unset( $wpml_posts[ $post_id ] );
						}
					}
				}

				$current_posts_data = $wpml_posts;
			}

			return $current_posts_data;
		}
	}
	
}

Nexter_Builder_Wpml_Compatibility::get_instance();
