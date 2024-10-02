<?php
/**
 * Nexter Builder Code Snippets Render
 *
 * @package Nexter Extensions
 * @since 1.0.4
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nexter_Builder_Code_Snippets_Render' ) ) {

	class Nexter_Builder_Code_Snippets_Render {

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
			add_action( 'wp', array( $this, 'nexter_code_html_hooks_actions' ),2 );
			if(!is_admin()){
				add_action( 'wp_enqueue_scripts', array( $this, 'nexter_code_snippets_css_js' ),2 );
			}
		}

		/*
		 * Nexter Builder Code Snippets Css/Js Enqueue
		 */
		public static function nexter_code_snippets_css_js() {
			
			$css_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'css' );
			if( !empty( $css_actions ) ){
				foreach ( $css_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
						$css_code = get_post_meta( $post_id, 'nxt-code-css-snippet', true );
						$css_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if(!empty($css_code) && ( empty($css_code_execute) || (!empty($css_code_execute) && $css_code_execute=='yes') ) ){
							wp_add_inline_style( 'nexter-style', wp_specialchars_decode($css_code) );
						}
					}
				}
			}
			$javascript_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'javascript' );
			if( !empty( $javascript_actions ) ){
				foreach ( $javascript_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
						$javascript_code = get_post_meta( $post_id, 'nxt-code-javascript-snippet', true );
						$js_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if(!empty($javascript_code) && ( empty($js_code_execute) || (!empty($js_code_execute) && $js_code_execute=='yes' ) ) ){
							wp_add_inline_script( 'jquery', html_entity_decode($javascript_code, ENT_QUOTES) );
						}
					}
				}
			}
		}
		
		/*
		 * Nexter Builder Code Snippets Html Hooks
		 */
		public static function nexter_code_html_hooks_actions() {
			
			$html_actions = Nexter_Builder_Sections_Conditional::nexter_sections_condition_hooks( 'code_snippet', 'html' );
			
			if( !empty( $html_actions ) ){
				foreach ( $html_actions as $post_id) {
					$post_type = get_post_type();

					if ( NXT_BUILD_POST != $post_type ) {
					
						$hook_action = get_post_meta( $post_id, 'nxt-code-hooks-action', true );
						$html_code_execute = get_post_meta( $post_id, 'nxt-code-snippet-secure-executed', true );
						if( empty($html_code_execute) || ( !empty($html_code_execute) && $html_code_execute=='yes' ) ){
							add_action(
								$hook_action,
								function() use ( $post_id ) {
									$html_code = get_post_meta( $post_id, 'nxt-code-htmlmixed-snippet', true );
									echo $html_code;
								},
								10
							);
						}
					}
				}
			}
		}
		
	}
}
Nexter_Builder_Code_Snippets_Render::get_instance();