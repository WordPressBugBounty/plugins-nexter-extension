<?php
/**
 * Load Pages (Singluar/Archives) Templates
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Load_Singular_Archives' ) ) {

	class Nexter_Load_Singular_Archives {


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
		 * Constructor
		 */
		public function __construct() {
			$load_pages_uri = NEXTER_EXT_DIR . 'include/classes/load-pages/';
			
			require_once $load_pages_uri . 'nexter-pages-loader.php';
			require_once $load_pages_uri . 'nexter-pages-conditional.php';
		}
	}

	Nexter_Load_Singular_Archives::get_instance();
}