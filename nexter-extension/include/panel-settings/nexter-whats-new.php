<?php
/**
 * Nexter Extension Deactivate Survey
 *
 * @since 4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Nxt_Ext_Whats_New' ) ) {

	class Nxt_Ext_Whats_New {


        /**
		 * Member Variable
		 */
		private static $instance;

        const FEED_URL = 'https://nexterwp.com/wp-content/nxt-feed-cache.json';
        const TRANSIENT_ITEM = 'nxtext_latest_whats_new_item';
        const OPTION_NAME      = 'nxt_ext_menu_notice_count';
        const TRANSIENT_FEED   = 'nxtext_cached_feed_data';

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
			$this->initialize_notice_data();
			// Set feed cache first, then check new item
			add_action( 'admin_init', [ $this, 'nxtext_cache_whats_new_feed' ], 10 );
			add_action( 'admin_init', [ $this, 'check_and_store_latest_item' ], 15 );
			add_action( 'wp_ajax_nxtext_fetch_whats_new', [ $this, 'nxtext_fetch_whats_new_data' ] );
		}

        /**
		 * Initialize option with default values if not exists
		 */
		private function initialize_notice_data() {
			$data = get_option( self::OPTION_NAME );
			if ( ! is_array( $data ) ) {
				$data = [
					'menu_notice_count' => 0,
					'notice_flag'       => 1,
				];
				update_option( self::OPTION_NAME, $data );
			}
		}

        /**
		 * Unified function to fetch or return cached feed data
		 */
		private function get_or_fetch_feed_items() {
            
			$cached = get_transient( self::TRANSIENT_FEED );
			if ( $cached && is_array( $cached ) ) {
				return $cached;
			}

			$response = wp_remote_get( self::FEED_URL, [ 'timeout' => 15 ] );
			if ( is_wp_error( $response ) ) {
				return [];
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return [];
			}

			$results = isset($data['all']) ? $data['all'] : (isset($data['nexter-extension']) ? $data['nexter-extension'] : []);

			set_transient( self::TRANSIENT_FEED, $results, 4 * DAY_IN_SECONDS );

			return $results;
		}

        /**
		 * Caches the feed when the welcome page is accessed
		 */
		public function nxtext_cache_whats_new_feed() {
			if ( empty( $_GET['page'] ) || $_GET['page'] !== 'nexter_welcome' ) {
				return;
			}
			$this->get_or_fetch_feed_items(); // Will set the transient if not present
		}

        /**
		 * AJAX: Return cached feed data
		 */
		public function nxtext_fetch_whats_new_data() {
			check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error();
			}

			$cached = get_transient( self::TRANSIENT_FEED );
			if ( ! $cached || ! is_array( $cached ) ) {
				wp_send_json_error( 'No cached feed data found.' );
			}

			wp_send_json_success( $cached );
		}

        /**
		 * Weekly: Checks for new item and stores if different
		 */
		public function check_and_store_latest_item() {
			if ( get_transient( self::TRANSIENT_ITEM ) ) {
				return;
			}

			$cached = $this->get_or_fetch_feed_items();
			if ( empty( $cached[0] ) ) {
				return;
			}

			$new_item  = $cached[0];
			$new_title = $new_item['title'];
			$new_link  = $new_item['link'];
			$new_date  = strtotime( $new_item['date'] );

			$stored = get_transient( self::TRANSIENT_ITEM );
			if ( empty( $stored ) || ( isset( $stored['title'] ) && $stored['title'] !== $new_title ) ) {
				$latest = [
					'title' => $new_title,
					'link'  => $new_link,
					'date'  => $new_date,
				];
				set_transient( self::TRANSIENT_ITEM, $latest, 4 * DAY_IN_SECONDS );

				$data = get_option( self::OPTION_NAME, [] );
				if ( ! is_array( $data ) ) {
					$data = [
						'menu_notice_count' => 0,
						'notice_flag'       => 1,
					];
				}

				$data['notice_flag'] = isset( $data['notice_flag'] ) ? (int) $data['notice_flag'] + 1 : 1;
				update_option( self::OPTION_NAME, $data );
			}
		}

    }

    Nxt_Ext_Whats_New::get_instance();
}
