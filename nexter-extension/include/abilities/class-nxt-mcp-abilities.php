<?php
/**
 * Bootstraps Nexter Extension MCP abilities and their category.
 *
 * @package Nexter Extension
 * @since   4.6.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nexter_mcp_permission_callback' ) ) {
	/**
	 * Permission callback for all Nexter Extension MCP abilities.
	 *
	 * Defined and owned by Nexter Extension so ability registration never depends on an
	 * external (e.g. SproutOS) helper existing. The WordPress Abilities API validates the
	 * callback at registration time via WP_Ability::prepare_properties(); when the referenced
	 * function is missing it throws, WP_Abilities_Registry::register() swallows it with a
	 * _doing_it_wrong() notice, and the ability is silently dropped. Every Nexter ability
	 * operates on site-wide configuration, executable code snippets, or theme-builder
	 * templates — all administrator-level — so a single manage_options check is the correct,
	 * consistent gate. The optional $input argument is accepted for forward compatibility.
	 *
	 * @param mixed $input Ability input arguments (unused).
	 * @return bool Whether the current user may use the ability.
	 */
	function nexter_mcp_permission_callback( $input = null ) {
		return current_user_can( 'manage_options' );
	}
}

/**
 * Registers the Nexter Extension ability category and loads each ability file.
 */
class Nxt_MCP_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var ?Nxt_MCP_Abilities
	 */
	private static $instance;

	/**
	 * Whether abilities API hooks have been registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Gets the singleton instance of the class.
	 *
	 * @return Nxt_MCP_Abilities
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Initializes MCP abilities if enabled in the plugin options and hooks
	 * registration callbacks into the abilities API.
	 */
	private function __construct() {
		$mcp_option        = get_option( 'tpgb_connection_data', array() );
		$mcp_ability_value = isset( $mcp_option['nxt_enable_mcp_abilities'] ) ? $mcp_option['nxt_enable_mcp_abilities'] : 'enable';
		if ( 'enable' !== $mcp_ability_value ) {
			return;
		}

		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers ability categories for Nexter Extension.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		if ( function_exists( 'wp_get_ability_category' ) && wp_get_ability_category( 'nexter-extension' ) ) {
			return;
		}

		wp_register_ability_category(
			'nexter-extension',
			array(
				'label'       => __( 'Nexter Extension', 'nexter-extension' ),
				'description' => __( 'Nexter Extension MCP abilities for templates, snippets, and site settings.', 'nexter-extension' ),
			)
		);
	}

	/**
	 * Registers MCP abilities by loading ability files from the abilities directory.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$base = NEXTER_EXT_DIR . 'include/abilities/';

		foreach ( array(
			'nexter-bulk-image-optimizer',
			'nexter-create-snippet',
			'nexter-create-template',
			'nexter-custom-fonts',
			'nexter-delete-snippet',
			'nexter-delete-template',
			'nexter-get-snippet',
			'nexter-get-template',
			'nexter-image-optimization',
			'nexter-list-extensions',
			'nexter-list-snippets',
			'nexter-list-templates',
			'nexter-performance-settings',
			'nexter-security-settings',
			'nexter-smtp-settings',
			'nexter-toggle-extension',
			'nexter-toggle-snippet',
			'nexter-toggle-template',
			'nexter-update-snippet',
			'nexter-update-template',
		) as $file ) {
			require_once $base . $file . '.php';
		}
	}
}
