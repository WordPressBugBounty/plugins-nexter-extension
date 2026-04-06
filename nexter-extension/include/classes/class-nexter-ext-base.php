<?php
/**
 * Extension Base Class
 *
 * Abstract foundation for individual Nexter extension modules.
 * Provides the common pattern shared by all files in
 * include/panel-settings/extensions/ — settings access, toggle check,
 * and AJAX nonce verification.
 *
 * Usage:
 *   class Nexter_Ext_My_Feature extends Nexter_Ext_Base {
 *
 *       protected function options_group(): string {
 *           return 'extra_ext'; // Nxt_Options method name.
 *       }
 *
 *       protected function settings_key(): string {
 *           return 'my-feature'; // Key inside the options array.
 *       }
 *
 *       public function __construct() {
 *           parent::__construct();
 *           if ( ! $this->is_enabled() ) {
 *               return;
 *           }
 *           add_action( 'init', [ $this, 'do_something' ] );
 *       }
 *   }
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

abstract class Nexter_Ext_Base {

	/**
	 * Cached settings for this extension (the 'values' sub-array).
	 *
	 * @var array|null
	 */
	protected $settings = null;

	/**
	 * Return the Nxt_Options getter name for the parent option group.
	 *
	 * Examples: 'extra_ext', 'security', 'performance'.
	 *
	 * @return string
	 */
	abstract protected function options_group(): string;

	/**
	 * Return the array key that holds this extension's config
	 * inside the options group.
	 *
	 * Example: 'limit-login-attempt', 'heartbeat-control'.
	 *
	 * @return string
	 */
	abstract protected function settings_key(): string;

	/**
	 * Constructor — loads settings into $this->settings.
	 *
	 * Subclasses should call parent::__construct() first, then
	 * check $this->is_enabled() before registering hooks.
	 */
	public function __construct() {
		$this->load_settings();
	}

	/**
	 * Whether this extension's toggle is on and settings are non-empty.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return ! empty( $this->settings );
	}

	/**
	 * Get the loaded settings array.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		if ( null === $this->settings ) {
			$this->load_settings();
		}
		return (array) $this->settings;
	}

	/**
	 * Verify the current AJAX request using the plugin-wide nonce.
	 *
	 * @param string $capability WordPress capability. Default 'manage_options'.
	 *
	 * @return bool
	 */
	protected function verify_ajax( $capability = 'manage_options' ): bool {
		if ( class_exists( 'Nxt_Ajax_Guard' ) ) {
			return Nxt_Ajax_Guard::verify( $capability );
		}

		// Inline fallback (matches the 51-occurrence pattern).
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'nexter-extension' ) ),
				403
			);
			return false;
		}
		return true;
	}

	// ── Internal ───────────────────────────────────────────────────

	/**
	 * Load settings from Nxt_Options using the declared group + key.
	 */
	private function load_settings(): void {
		$group  = $this->options_group();
		$key    = $this->settings_key();

		if ( ! class_exists( 'Nxt_Options' ) || ! method_exists( 'Nxt_Options', $group ) ) {
			$this->settings = [];
			return;
		}

		$option = Nxt_Options::$group();

		if (
			! empty( $option )
			&& isset( $option[ $key ]['switch'] )
			&& ! empty( $option[ $key ]['switch'] )
			&& isset( $option[ $key ]['values'] )
			&& ! empty( $option[ $key ]['values'] )
		) {
			$this->settings = (array) $option[ $key ]['values'];
		} else {
			$this->settings = [];
		}
	}
}
