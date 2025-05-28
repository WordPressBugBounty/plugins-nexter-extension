<?php
/**
 * Nexter Extension Rollback Plugin Upgrader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom silent skin to suppress default "Activate Plugin" message.
 */
class Nxt_Ext_RB_Silent_Skin extends Plugin_Upgrader_Skin {
	public function after() {
		$this->decrement_update_count( 'plugin' );
		$this->feedback( __( 'Plugin Rolled back Successfully.', 'nexter-extension' ) );
	}
}

/**
 * Plugin rollback upgrader class.
 */
class Nxt_Ext_RB_Plugin_Upgrader extends Plugin_Upgrader {
	public function __construct( $skin = null ) {
		parent::__construct( $skin );
	}

	/**
	 * Executes plugin rollback.
	 *
	 * @param string $plugin Plugin file path (e.g. plugin-folder/plugin.php).
	 * @param array $args {
	 *     Optional. Additional arguments.
	 *
	 *     @type string $slug Plugin slug (same as WordPress.org slug).
	 *     @type string $version Version to roll back to.
	 *     @type bool   $clear_update_cache Whether to clear update cache. Default true.
	 * }
	 *
	 * @return bool|WP_Error
	 */
	public function nxt_ext_rollback_module( $plugin, $args = array() ) {
		$defaults = array(
			'slug'               => '',
			'version'            => '',
			'clear_update_cache' => true,
		);
		$args = wp_parse_args( $args, $defaults );

		if ( empty( $plugin ) || empty( $args['slug'] ) || empty( $args['version'] ) ) {
			$this->skin->before();
			$this->skin->set_result( false );
			$this->skin->error( 'Missing required rollback parameters.' );
			$this->skin->after();
			return false;
		}

		$this->init();
		$this->upgrade_strings();

		$package = "https://downloads.wordpress.org/plugin/{$args['slug']}.{$args['version']}.zip";
		$active  = is_plugin_active( $plugin );

		// Maintain plugin activation status.
		add_filter( 'upgrader_pre_install', array( $this, 'active_before' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( $this, 'active_after' ), 10, 2 );

		$this->run( array(
			'package'           => $package,
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'     => true,
			'hook_extra'        => array(
				'plugin' => $plugin,
				'type'   => 'plugin',
				'action' => 'update',
				'bulk'   => false,
			),
		) );

		remove_filter( 'upgrader_pre_install', array( $this, 'active_before' ) );
		remove_filter( 'upgrader_post_install', array( $this, 'active_after' ) );

		// Do NOT re-activate; plugin is already active
		return ( ! $this->result || is_wp_error( $this->result ) ) ? $this->result : true;
	}
}
