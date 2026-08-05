<?php 
/**
 * Plugin Name: Nexter Extension
 * Plugin URI: https://nexterwp.com
 * Description: Nexter Extension adds lightweight performance, security, and admin features to WordPress so you can improve and manage your website without installing many plugins.
 * Version: 4.7.4
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 * Text Domain: nexter-extension
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 * Domain Path: /languages
 * @package Nexter Extensions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Define Constants */
define( 'NEXTER_EXT_FILE', __FILE__ );
define( 'NEXTER_EXT', 'nexter-extensions' );
define( 'NEXTER_EXT_BASE', plugin_basename( NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_DIR', plugin_dir_path( NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_URL', plugins_url( '/', NEXTER_EXT_FILE ) );
define( 'NEXTER_EXT_CPT', 'nxt_builder' );
define( 'NEXTER_EXT_VER', '4.7.4' );
// Rewrite-rules revision for the Theme Builder CPT. Bump this whenever the CPT's slug or
// rewrite args change to trigger a one-time automatic flush (see nxt_ext_flush_rewrite_rules
// / nxt_ext_maybe_flush_rewrite_rules). Mirrors Elementor's activation + admin_init upgrade
// flush model so users never have to open Settings → Permalinks.
define( 'NEXTER_EXT_REWRITE_VER', '1' );

if ( ! defined( 'NXT_BUILD_POST' ) ) {
	define( 'NXT_BUILD_POST', 'nxt_builder' );
}

/* Centralized settings cache — load once, before any module reads options. */
require_once NEXTER_EXT_DIR . 'include/classes/class-nxt-options.php';

/* POSIMYTH Analytics SDK (shared base + per-plugin subclass + consent notice + churn survey) */
$nxt_posimyth_sdk = NEXTER_EXT_DIR . 'include/posimyth-sdk/';
require_once $nxt_posimyth_sdk . 'class-posimyth-tracker-base.php';
require_once $nxt_posimyth_sdk . 'class-posimyth-tracker-ne.php';
require_once $nxt_posimyth_sdk . 'class-posimyth-consent-notice.php';
require_once $nxt_posimyth_sdk . 'class-posimyth-deactivation-survey.php';
unset( $nxt_posimyth_sdk );
/**
 * White-label (Pro): a rebranded install must never surface POSIMYTH-branded UI (consent notice /
 * deactivation survey) AND must never phone api.posimyth.com. Same rule the legacy deactivation
 * popup enforced before it was replaced by the SDK survey in 4.7.3.
 *
 * This has to be testable before the tracker is booted, not after. It previously sat further down
 * the bootstrap, below Posimyth_Tracker_NE::init(), so it only suppressed the visible UI: a
 * white-labelled site whose consent was already ON (inherited from before white-labelling was
 * configured, or from the legacy option migration below) kept sending activate / deactivate /
 * heartbeat pings with nothing in its own admin to indicate it.
 *
 * @return bool
 */
function nxt_posimyth_is_white_labelled() {
	if ( ! defined( 'NXT_PRO_EXT' ) && ! defined( 'TPGBP_VERSION' ) ) {
		return false; // White-label is a Pro feature; Free installs can never be rebranded.
	}
	if ( ! class_exists( 'Nxt_Options' ) ) {
		return false;
	}
	$nxt_wl = Nxt_Options::white_label();
	return is_array( $nxt_wl ) && ! empty( $nxt_wl['brand_name'] );
}

add_action( 'plugins_loaded', function () {
	// Nothing below may run on a rebranded install — not the tracker, not the AJAX consent toggle,
	// not the notice or the survey.
	if ( nxt_posimyth_is_white_labelled() ) {
		return;
	}

	// One-time migration: consolidate this plugin's legacy standalone opt-in into the
	// shared Nexter-family consent (Nexter Extension + Nexter Blocks share one decision now).
	// Only fires if the shared option has literally never been set (get_option's `false`
	// fallback), so an explicit opt-out (stored as 0) is never overwritten.
	if ( false === get_option( 'posimyth_nexter_share_analytics', false ) && get_option( 'nexter_ext_share_analytics', false ) ) {
		update_option( 'posimyth_nexter_share_analytics', 1, false );
	}

	// Registers activate / deactivate / weekly-heartbeat hooks + cron (all consent-gated).
	Posimyth_Tracker_NE::init();

	if ( ! is_admin() ) {
		return;
	}

	// Action trigger for the Dashboard toggle + Onboarding checkbox: flip the single shared
	// consent option (opt-in). Turning it ON sends the first ping immediately (same as the inline
	// notice's "Allow"); turning it OFF is fully reversible and sends nothing.
	add_action( 'wp_ajax_nxt_set_share_analytics', function () {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'nexter_admin_nonce', 'security', false ) ) {
			wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
		}
		$raw     = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0';
		$enabled = ! in_array( $raw, array( '', '0', 'false', 'off', 'no' ), true );
		update_option( 'posimyth_nexter_share_analytics', $enabled ? 1 : 0, false );
		if ( $enabled && class_exists( 'Posimyth_Tracker_NE' ) ) {
			Posimyth_Tracker_NE::send_first_ping();
		}
		wp_send_json_success( array( 'enabled' => $enabled ? 1 : 0 ) );
	} );

	// (The white-label gate now runs at the very top of this callback, so everything here —
	// tracker included — is already known to be non-rebranded.)

	$nxt_consent_notice = new Posimyth_Consent_Notice( array(
		'plugin_name'      => 'Nexter Extension',
		'plugin_slug'      => 'nexter-extension',
		'opt_in_option'    => 'posimyth_nexter_share_analytics',
		'ajax_action'      => 'posimyth_consent_ne',
		'installed_option' => 'posimyth_ne_first_use_at',
		'tracker_cb'       => array( 'Posimyth_Tracker_NE', 'send_first_ping' ),
		// Campaign is per placement so the docs page can tell which surface sent the reader
		// (notice vs Dashboard settings vs onboarding). Same scheme as Nexter's other admin CTAs.
		'docs_url'         => 'https://nexterwp.com/docs/data-sharing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=datasharingnotice',
		'suite_key'        => 'nexter_suite',
	) );

	// "First successful use" marker — the notice intentionally shows only after the user has
	// actually used the plugin, never on bare activation. This also covers existing installs
	// upgrading to this build, so they see the consent notice once too.
	// Cheap: runs only while the marker is unset, then never queries again.
	//
	// "Used" means a settings group contains a saved TOGGLE, not merely that the group is non-empty.
	// Non-empty was wrong: the code-snippets migration (admin_init priority 1) writes its own
	// bookkeeping flag into nexter_extra_ext_options as
	// ['code-snippets']['values']['migration'] = true, which made the group non-empty on the very
	// first admin screen after activation — so the notice appeared before the user had touched a
	// single setting, which is exactly what this gate exists to prevent.
	//
	// Every real extension entry is stored as `slug => array( 'switch' => bool, 'values' => ... )`.
	// Internal bookkeeping only ever writes under `values`, never a `switch`. Testing for the
	// presence of a `switch` key therefore distinguishes "the user saved something" from "the plugin
	// wrote to its own options", and keeps working if another migration adds more bookkeeping later.
	add_action( 'admin_init', function () use ( $nxt_consent_notice ) {
		if ( get_option( 'posimyth_ne_first_use_at', 0 ) ) {
			return;
		}
		$groups = array(
			class_exists( 'Nxt_Options' ) ? Nxt_Options::extra_ext()   : get_option( 'nexter_extra_ext_options', array() ),
			class_exists( 'Nxt_Options' ) ? Nxt_Options::performance() : get_option( 'nexter_site_performance', array() ),
			class_exists( 'Nxt_Options' ) ? Nxt_Options::security()    : get_option( 'nexter_site_security', array() ),
		);
		foreach ( $groups as $opts ) {
			if ( ! is_array( $opts ) ) {
				continue;
			}
			foreach ( $opts as $entry ) {
				// A saved toggle — including one saved OFF, which still means the user was there.
				if ( is_array( $entry ) && array_key_exists( 'switch', $entry ) ) {
					$nxt_consent_notice->mark_first_use();
					return;
				}
				// Legacy shape: some older builds stored the toggle as a bare scalar.
				if ( ! is_array( $entry ) && ! empty( $entry ) ) {
					$nxt_consent_notice->mark_first_use();
					return;
				}
			}
		}
	}, 20 );

	// "Why are you leaving?" survey — reason sent only if the user opted in.
	new Posimyth_Deactivation_Survey( array(
		'plugin_name'   => 'Nexter Extension',
		'plugin_slug'   => 'nexter-extension',
		'ajax_action'   => 'posimyth_ne_deact',
		'opt_in_option' => 'posimyth_nexter_share_analytics',
		// Full submit = consent → send the non-sensitive environment payload + reason.
		'tracker_cb'    => array( 'Posimyth_Tracker_NE', 'do_request' ),
	) );
} );

/**
 * Load Custom Login Redirect early if enabled.
 * Runs on plugins_loaded at priority 1 (before the main plugin bootstrap at priority 10) so
 * Nexter_Ext_Custom_Login_Redirect can attach init / setup_theme / wp_loaded handlers in time.
 *
 * @since 4.6.3
 */
function nexter_ext_early_custom_login_redirect() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	$security_option = Nxt_Options::security();
	if ( ! empty( $security_option ) && isset( $security_option['custom-login']['switch'] ) && ! empty( $security_option['custom-login']['switch'] ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-custom-login-redirect.php';
		new Nexter_Ext_Custom_Login_Redirect();
	}
}
add_action( 'plugins_loaded', 'nexter_ext_early_custom_login_redirect', 1 );

/**
 * Nexter Extension Plugins Loaded
 */
function nexter_extension_plugins_loaded() {
	load_plugin_textdomain( 'nexter-extension', false, NEXTER_EXT_DIR . 'languages' );

	if ( ! version_compare( PHP_VERSION, '7.4', '>=' ) ) {
		add_action( 'admin_notices', 'nexter_ext_php_version_notice' );
	} else {
		require_once NEXTER_EXT_DIR . 'include/class-nexter-load-ext.php';
	}
}
add_action( 'plugins_loaded', 'nexter_extension_plugins_loaded' );
/**
 * Handle plugin activation.
 */
function nxt_ext_activate() {

	if ( ! get_option( 'nexter-ext-install-data' ) ) {
		update_option(
			'nexter-ext-install-data',
			[
			"install-version" => NEXTER_EXT_VER, 
			'install-date'    => wp_date( 'd-m-Y' )
			 ] 
		);
	}

	require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/class-activation.php';
	if ( class_exists( 'Nexter_Ext_Activation' ) ) {
		$activation = new Nexter_Ext_Activation();
		$activation->create_login_attempt_table();
	}
	delete_transient( 'nxtext_cached_feed_data' );

	// Analytics activation ping. This MUST happen here rather than via the SDK's `activated_plugin`
	// hook: during our own activation request WordPress fires `plugins_loaded` before it includes
	// this file, so the SDK's init() never runs and nothing is listening when `activated_plugin`
	// fires — activations were silently never recorded while deactivations worked. Still fully
	// consent-gated inside on_self_activate(), so a fresh install sends nothing.
	// Rebranded installs must not phone home from here either — this runs during our own activation
	// request, so the plugins_loaded gate has not had a chance to short-circuit anything yet.
	if ( class_exists( 'Posimyth_Tracker_NE' ) && ! nxt_posimyth_is_white_labelled() ) {
		Posimyth_Tracker_NE::on_self_activate();
	}

	// Auto-flush rewrite rules on activation (same concept as Elementor's Maintenance::activation).
	nxt_ext_flush_rewrite_rules();
}

/**
 * Register the Theme Builder CPT and flush rewrite rules.
 *
 * Shared by activation and the admin_init upgrade check. The CPT is registered directly
 * because during plugin activation its own `init` hook has not fired yet, so without this
 * the flush would omit the CPT's rules. Flushing makes the CPT permalink — and therefore
 * Elementor's editor preview — work immediately, with no manual Settings → Permalinks save.
 *
 * @return void
 */
function nxt_ext_flush_rewrite_rules() {
	if ( ! post_type_exists( 'nxt_builder' ) ) {
		require_once NEXTER_EXT_DIR . 'include/nexter-template/nexter-template-function.php';
		if ( function_exists( 'nexter_builders_register_post' ) ) {
			nexter_builders_register_post();
		}
	}
	flush_rewrite_rules();
	update_option( 'nexter_ext_rewrite_ver', NEXTER_EXT_REWRITE_VER, true );
}

/**
 * Handle plugin deactivation.
 */
function nxt_ext_deactivate() {
	require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/class-deactivation.php';

	if ( is_admin() && class_exists( 'Nexter_Ext_Deactivation' ) ) {
		$deactivation = new Nexter_Ext_Deactivation();
		$deactivation->remove_login_attempt_table();
	}
	delete_transient( 'nxtext_cached_feed_data' );
}

// Plugin Activation and Deactivation Hooks
register_activation_hook( __FILE__, 'nxt_ext_activate' );
register_deactivation_hook( __FILE__, 'nxt_ext_deactivate' );

/**
 * Auto-flush rewrite rules after a plugin UPDATE or on an already-installed site.
 *
 * register_activation_hook() does not fire on plugin updates, so — exactly like Elementor's
 * version-gated Upgrade Manager — this runs on `admin_init` (admin screens only, NEVER on
 * front-end/visitor requests) and flushes only when the stored rewrite revision differs from
 * NEXTER_EXT_REWRITE_VER. After it flushes once it records the current revision and never runs
 * again until the constant is bumped. Front-end visitors trigger nothing, and no manual
 * Settings → Permalinks save is ever required.
 *
 * @return void
 */
function nxt_ext_maybe_flush_rewrite_rules() {
	if ( get_option( 'nexter_ext_rewrite_ver' ) === NEXTER_EXT_REWRITE_VER ) {
		return; // Already current — cheap autoloaded-option read, no flush.
	}
	nxt_ext_flush_rewrite_rules();
}
add_action( 'admin_init', 'nxt_ext_maybe_flush_rewrite_rules' );
/**
 * Nexter Ext notice for minimum PHP version.
 */
function nexter_ext_php_version_notice() {
	/* translators: %s: Php Required */
	$message      = sprintf( esc_html__( 'Nexter Extensions requires PHP version %s+, plugin is currently NOT RUNNING.', 'nexter-extension' ), '7.4' );
	$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
	echo wp_kses_post( $html_message );
}

add_action( 'upgrader_process_complete', 'nxt_ext_after_update', 10, 2 );
function nxt_ext_after_update( $upgrader_object, $options ) {

	if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {

		$plugin_slug = 'nexter-extension/nexter-extension.php';

		if ( isset( $options['plugins'] ) && in_array( $plugin_slug, $options['plugins'], true ) ) {
			delete_transient( 'nxtext_cached_feed_data' );
		}
	}
}
