<?php 
/*
 * Clean Up Admin Bar Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_CleanUp_Admin_Bar {
    
	public static $clean_up_opt = [];
	private static $admin_bar_nodes_cache = null;
	private static $collecting_nodes = false;

    /**
     * Constructor
     */
    public function __construct() {
		$this->nxt_get_clean_admin_bar_settings();

		add_action( 'admin_bar_menu', [$this, 'capture_admin_bar_nodes'], PHP_INT_MAX - 200 );
		add_action( 'admin_bar_menu', [$this, 'remove_admin_bar_nodes'], PHP_INT_MAX - 100 );
		add_action( 'admin_bar_menu', [$this, 'remove_howdy'], PHP_INT_MAX - 90 );
		if ( !empty(self::$clean_up_opt) && in_array("adminbar-help-tab",self::$clean_up_opt) ) {
			add_action( 'admin_head', [$this, 'hide_help_drawer'] );
		}
		if ( is_admin() ) {
			add_filter( 'nxt_dashboard_localize_data', [ $this, 'add_adminbar_nodes_to_localize' ] );
		}
    }

	private function nxt_get_clean_admin_bar_settings(){

		if(isset(self::$clean_up_opt) && !empty(self::$clean_up_opt)){
			return self::$clean_up_opt;
		}

		$option = Nxt_Options::extra_ext();
		
		if(!empty($option) && isset($option['clean-up-admin-bar']) && !empty($option['clean-up-admin-bar']['switch']) && !empty($option['clean-up-admin-bar']['values']) ){
			self::$clean_up_opt = $option['clean-up-admin-bar']['values'];
		}

		return self::$clean_up_opt;
	}

	public function remove_admin_bar_nodes( $wp_admin_bar ) {
		if ( self::$collecting_nodes ) {
			return;
		}
		$disabled_nodes = $this->get_disabled_admin_bar_nodes();
		if ( empty( $disabled_nodes ) ) {
			return;
		}
		foreach ( $disabled_nodes as $node_id ) {
			if ( empty( $node_id ) || in_array( $node_id, [ 'adminbar-howdy', 'adminbar-help-tab' ], true ) ) {
				continue;
			}
			$wp_admin_bar->remove_node( $node_id );
		}
    }

	public function remove_howdy( $wp_admin_bar ) {
        // Hide 'Howdy' text
        if ( self::$collecting_nodes ) {
			return;
		}
		if ( !empty(self::$clean_up_opt) && in_array("adminbar-howdy",self::$clean_up_opt) ) {
            remove_action( 'admin_bar_menu', 'wp_admin_bar_my_account_item', 7 );
            // Up to WP v6.5.5
            remove_action( 'admin_bar_menu', 'wp_admin_bar_my_account_item', 9991 );
            // Since WP v6.6
            $current_user = wp_get_current_user();
            $user_id = get_current_user_id();
            $profile_url = get_edit_profile_url( $user_id );
            $avatar = get_avatar( $user_id, 26 );
            // size 26x26 pixels
            $display_name = $current_user->display_name;
            $class = ( $avatar ? 'with-avatar' : 'no-avatar' );
            $wp_admin_bar->add_menu( array(
                'id'     => 'my-account',
                'parent' => 'top-secondary',
                'title'  => $display_name . $avatar,
                'href'   => $profile_url,
                'meta'   => array(
                    'class' => $class,
                ),
            ) );
        }
    }

	public function hide_help_drawer() {
        if ( is_admin() ) {
            $screen = get_current_screen();
            $screen->remove_help_tabs();
        }
    }

	public function capture_admin_bar_nodes( $wp_admin_bar ) {
		if ( self::$collecting_nodes || ! is_admin() ) {
			return;
		}

		$items = self::build_admin_bar_nodes_list( $wp_admin_bar );
		if ( empty( $items ) ) {
			return;
		}

		self::$admin_bar_nodes_cache = $items;
		$stored = get_option( 'nexter_admin_bar_nodes', [] );
		if ( $stored !== $items ) {
			update_option( 'nexter_admin_bar_nodes', $items );
		}
	}

	private function get_disabled_admin_bar_nodes() {
		$disabled = self::$clean_up_opt;
		if ( ! is_array( $disabled ) ) {
			$disabled = [];
		}

		$legacy_map = [
			'adminbar-wp-logo' => 'wp-logo',
			'adminbar-site-name' => 'site-name',
			'adminbar-customize-menu' => 'customize',
			'adminbar-updates-link' => 'updates',
			'adminbar-comments-link' => 'comments',
			'adminbar-new-content' => 'new-content',
		];

		foreach ( $legacy_map as $legacy_id => $node_id ) {
			if ( in_array( $legacy_id, $disabled, true ) && ! in_array( $node_id, $disabled, true ) ) {
				$disabled[] = $node_id;
			}
		}

		return array_values( array_unique( $disabled ) );
	}

	public function add_adminbar_nodes_to_localize( $data ) {
		if ( ! isset( $data['dashData'] ) || ! is_array( $data['dashData'] ) ) {
			$data['dashData'] = [];
		}
		if ( null !== self::$admin_bar_nodes_cache ) {
			$data['dashData']['adminBarNodes'] = self::$admin_bar_nodes_cache;
		} else {
			$stored_nodes = get_option( 'nexter_admin_bar_nodes', [] );
			$data['dashData']['adminBarNodes'] = ! empty( $stored_nodes ) ? $stored_nodes : self::get_adminbar_nodes_list();
		}
		return $data;
	}

	public static function get_adminbar_nodes_list() {
		if ( ! is_user_logged_in() ) {
			return [];
		}

		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}

		$admin_bar = new WP_Admin_Bar();
		$admin_bar->initialize();

		$old_admin_bar = isset( $GLOBALS['wp_admin_bar'] ) ? $GLOBALS['wp_admin_bar'] : null;
		$GLOBALS['wp_admin_bar'] = $admin_bar;

		self::$collecting_nodes = true;
		do_action( 'admin_bar_menu', $admin_bar );
		self::$collecting_nodes = false;

		$GLOBALS['wp_admin_bar'] = $old_admin_bar;

		return self::build_admin_bar_nodes_list( $admin_bar );
	}

	private static function build_admin_bar_nodes_list( $admin_bar ) {
		if ( ! $admin_bar || ! method_exists( $admin_bar, 'get_nodes' ) ) {
			return [];
		}

		$nodes = $admin_bar->get_nodes();
		if ( empty( $nodes ) ) {
			return [];
		}

		$items = [];
		foreach ( $nodes as $node ) {
			if ( empty( $node->id ) ) {
				continue;
			}
			if ( ! empty( $node->parent ) ) {
				continue;
			}
			$title = '';
			if ( isset( $node->title ) ) {
				$title = wp_strip_all_tags( $node->title );
				$title = html_entity_decode( $title, ENT_QUOTES, get_bloginfo( 'charset' ) );
				$title = trim( $title );
			}
			if ( '' === $title ) {
				$title = $node->id;
			}
			$items[ $node->id ] = [
				'id' => $node->id,
				'title' => $title,
			];
		}

		// remove legacy nodes
		unset($items['wp-logo']);
		unset($items['site-name']);
		unset($items['customize']);
		unset($items['updates']);
		unset($items['comments']);
		unset($items['new-content']);
		
		uasort( $items, function( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );

		return array_values( $items );
	}

}

new Nexter_Ext_CleanUp_Admin_Bar();