<?php
/**
 * Nexter Builder Hooks Render
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Nexter_Sections_Hooks_Render' ) ) {

	class Nexter_Sections_Hooks_Render {

		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 *  Initiator
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
			add_action( 'wp', array( $this, 'nexter_hooks_actions' ),2 );
		}

		public static function nexter_hooks_actions() {
			
			if(class_exists('Nexter_Pro_Maintenance_Mode')){
				$is_enabled = Nexter_Pro_Maintenance_Mode::check_maintenance_header_footer();
				if ( $is_enabled ) {
					return;
				}
			}
			
			$hooks_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'sections', 'hooks' );
			
			if( !empty( $hooks_actions ) ){
				foreach ( $hooks_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
					
						$hook_action = get_post_meta( $post_id, 'nxt-display-hooks-action', true );
						$priority = get_post_meta( $post_id, 'nxt-hooks-priority', true );
						
						add_action(
							$hook_action,
							function() use ( $post_id ) {

								Nexter_Builder_Sections_Conditional::get_instance()->get_action_content( $post_id );

							},
							$priority
						);
					
					}
				}
			}
		}

	}
}

Nexter_Sections_Hooks_Render::get_instance();