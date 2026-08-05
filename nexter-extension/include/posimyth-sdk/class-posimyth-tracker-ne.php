<?php
/**
 * POSIMYTH Analytics tracker — Nexter Extension.
 *
 * Requires class-posimyth-tracker-base.php to be loaded first.
 * Boot from the main plugin file:  Posimyth_Tracker_NE::init();
 *
 * Verified option keys (Nexter Extension 4.7.0.6 / Nexter Pro Extensions):
 *   - version constant : NEXTER_EXT_VER
 *   - pro detection    : NXT_PRO_EXT + class Nexter_Pro_Ext_Activate (older builds: NXT_PRO_EXT_FILE)
 *   - license option   : nxt_license_status (EDD SL response array)
 *   - feature toggles  : nexter_extra_ext_options, nexter_site_performance, nexter_site_security
 *                        (each stored as key => array( 'switch' => bool ))
 *   - extensions are toggle-only, so no content scan (used_features stays empty).
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-load the shared base so this subclass defines correctly no matter what order the main
// plugin file requires the SDK files in (prevents "class not found" fatals from a bootstrap that
// forgets to load the base first). Idempotent — the base file has its own class_exists guard.
if ( ! class_exists( 'Posimyth_Tracker_Base' ) ) {
	require_once __DIR__ . '/class-posimyth-tracker-base.php';
}

if ( ! class_exists( 'Posimyth_Tracker_NE' ) && class_exists( 'Posimyth_Tracker_Base' ) ) {

	/**
	 * Nexter Extension's tracker: supplies the product-specific identity, version, Pro/license
	 * state and feature toggles that the shared base assembles into a payload.
	 */
	class Posimyth_Tracker_NE extends Posimyth_Tracker_Base {

		const OPT_IN_OPTION = 'posimyth_nexter_share_analytics';

		/**
		 * Short internal id used for this product's own options (install time, usage cache).
		 *
		 * @return string
		 */
		protected static function id(): string {
			return 'ne';
		}

		/**
		 * Plugin slug reported to the hub.
		 *
		 * @return string
		 */
		protected static function slug(): string {
			return 'nexter-extension';
		}

		/**
		 * Name shown in WordPress's Privacy Policy suggestions.
		 *
		 * @return string
		 */
		protected static function display_name(): string {
			return 'Nexter Extension';
		}

		/**
		 * Option holding the suite-wide sharing consent.
		 *
		 * @return string
		 */
		protected static function opt_in_option(): string {
			return self::OPT_IN_OPTION;
		}

		/**
		 * Currently installed version of this plugin.
		 *
		 * @return string
		 */
		protected static function version(): string {
			return defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '';
		}

		/**
		 * Whether the Pro build is present.
		 *
		 * @return bool
		 */
		protected static function is_pro(): bool {
			// Current Pro builds define NXT_PRO_EXT + the activation class; older builds only
			// defined NXT_PRO_EXT_FILE. Accept either so detection never silently regresses.
			if ( defined( 'NXT_PRO_EXT' ) && class_exists( 'Nexter_Pro_Ext_Activate' ) ) {
				return true;
			}
			return defined( 'NXT_PRO_EXT_FILE' );
		}

		/**
		 * License status and plan, empty for Free installs.
		 *
		 * @return array{status:string,plan:string}
		 */
		protected static function license(): array {
			if ( ! self::is_pro() ) {
				return array(
					'status' => '',
					'plan'   => '',
				);
			}

			// EDD Software Licensing stores its activation response as an array.
			$raw = get_option( 'nxt_license_status', '' );

			if ( is_array( $raw ) ) {
				return array(
					'status' => (string) ( $raw['license'] ?? ( $raw['status'] ?? '' ) ),
					'plan'   => (string) ( $raw['item_name'] ?? ( $raw['price_id'] ?? '' ) ),
				);
			}

			return array(
				'status' => (string) $raw,
				'plan'   => '',
			);
		}

		/**
		 * Which Nexter Extension features are toggled ON.
		 *
		 * Nexter stores each extension as `key => array( 'switch' => bool )` inside three
		 * option arrays, so we read the `switch` sub-key (a plain `(bool) $val` on the option
		 * array would report every configured extension as enabled). Uses the Nxt_Options
		 * cache when available to avoid extra queries.
		 *
		 * @return array<string,bool>
		 */
		protected static function enabled_features(): array {
			$groups = array(
				'extra_ext'   => class_exists( 'Nxt_Options' ) ? Nxt_Options::extra_ext() : get_option( 'nexter_extra_ext_options', array() ),
				'performance' => class_exists( 'Nxt_Options' ) ? Nxt_Options::performance() : get_option( 'nexter_site_performance', array() ),
				'security'    => class_exists( 'Nxt_Options' ) ? Nxt_Options::security() : get_option( 'nexter_site_security', array() ),
			);

			$map = array();
			foreach ( $groups as $opts ) {
				if ( ! is_array( $opts ) ) {
					continue;
				}
				foreach ( $opts as $key => $val ) {
					$map[ sanitize_key( $key ) ] = is_array( $val )
						? ! empty( $val['switch'] )
						: (bool) $val;
				}
			}
			return $map;
		}
	}
}
