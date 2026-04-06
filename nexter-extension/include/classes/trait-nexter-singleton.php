<?php
/**
 * Singleton Trait
 *
 * Provides a reusable singleton pattern for Nexter classes.
 * Eliminates the copy-paste boilerplate found in 44+ classes.
 *
 * Usage:
 *   class My_Module {
 *       use Nexter_Singleton;
 *
 *       protected function init() {
 *           // Register hooks here (called once from get_instance).
 *       }
 *   }
 *
 *   $instance = My_Module::get_instance();
 *
 * Notes:
 * - The constructor is intentionally `protected` so subclasses can
 *   still define their own constructors (calling parent::__construct()).
 * - Override `init()` for hook registration instead of the constructor
 *   to keep the singleton pattern clean.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

trait Nexter_Singleton {

	/** @var static|null */
	private static $instance = null;

	/**
	 * Return the single instance, creating it on first call.
	 *
	 * @return static
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize a singleton.' );
	}
}
