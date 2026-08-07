<?php 
/**
 * Plugin Name: Nexter Extension
 * Plugin URI: https://nexterwp.com
 * Description: Nexter Extension adds lightweight performance, security, and admin features to WordPress so you can improve and manage your website without installing many plugins.
 * Version: 4.7.5
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
define( 'NEXTER_EXT_VER', '4.7.5' );
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

/*
 * POSIMYTH Analytics SDK — registered, not required (E6).
 *
 * The shared classes used to be require_once'd right here, each behind a class_exists guard, so
 * whichever POSIMYTH plugin WordPress included FIRST supplied its copy to the whole suite — an
 * outdated sibling silently downgraded everyone (an older do_request() without $bypass_consent
 * dropped the survey's third argument, so submitted feedback never sent). Now every plugin only
 * registers its bundled copy, and the loader requires the NEWEST one at plugins_loaded priority 0,
 * before any consumer runs. Our per-product subclass loads inside the consumers below.
 */
require_once NEXTER_EXT_DIR . 'include/posimyth-sdk/posimyth-sdk-loader.php';
posimyth_sdk_register( NEXTER_EXT_DIR . 'include/posimyth-sdk' );
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
	/*
	 * The stored brand IS the evidence, not the presence of Pro (A8).
	 *
	 * This used to require NXT_PRO_EXT or TPGBP_VERSION first, on the reasoning that only Pro can
	 * configure white-labelling. True — but the setting outlives Pro: deactivate Pro on a rebranded
	 * install and both constants vanish, so the site was suddenly judged "not white-labelled",
	 * POSIMYTH-branded UI reappeared in someone else's admin and the pings resumed. A brand name that
	 * is still stored still means "do not surface POSIMYTH here". A Free-only site that never had Pro
	 * has nothing stored, so it is unaffected.
	 */
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

	// The shared base was loaded by the SDK loader at plugins_loaded priority 0 (newest bundled
	// copy wins); the per-product subclass is ours alone and loads here, after the winner exists.
	require_once NEXTER_EXT_DIR . 'include/posimyth-sdk/class-posimyth-tracker-ne.php';

	/*
	 * One-time migration: consolidate this plugin's legacy standalone opt-in into the shared
	 * Nexter-family consent (Nexter Extension + Nexter Blocks share one decision now). Only fires if
	 * the shared option has literally never been set (get_site_option's `false` fallback), so an
	 * explicit opt-out (stored as 0) is never overwritten.
	 *
	 * Marker-guarded (C4): the legacy option is not autoloaded, so on a site where the shared option
	 * was never set this ran an uncached query on EVERY request, front end included. The marker is
	 * autoloaded, so after the first pass this costs nothing.
	 *
	 * Written with update_site_option and left autoloaded: has_consent() reads it on every request,
	 * so a non-autoloaded consent option (the old `false` third argument) meant a query each time.
	 */
	if ( ! get_site_option( 'posimyth_ne_legacy_consent_migrated' ) ) {
		if ( false === get_site_option( 'posimyth_nexter_share_analytics', false ) && get_option( 'nexter_ext_share_analytics', false ) ) {
			update_site_option( 'posimyth_nexter_share_analytics', 1 );
		}
		update_site_option( 'posimyth_ne_legacy_consent_migrated', 1 );
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
		// Site option + autoloaded, matching the notice and has_consent() (A6, C4). The old
		// update_option( …, false ) wrote a per-blog, non-autoloaded value, so on multisite the
		// dashboard toggle and the notice were answering two different questions.
		update_site_option( 'posimyth_nexter_share_analytics', $enabled ? 1 : 0 );

		/*
		 * Answering here counts as answering the consent notice, either way.
		 *
		 * Turning sharing ON already silences the notice on its own (should_show() sees the option).
		 * Saying NO did not: the option was set to 0, which is exactly the state the notice looks for,
		 * so the very next admin page load asked again — the user had just declined in Onboarding or in
		 * Dashboard → Settings and was immediately re-prompted. Record the decision so the ask stops.
		 */
		if ( class_exists( 'Posimyth_Consent_Notice' ) ) {
			// Site option, matching the notice's own writes (A6).
			update_site_option( 'posi_consent_dismissed_nexter_suite', 1 );
		}

		if ( $enabled && class_exists( 'Posimyth_Tracker_NE' ) ) {
			Posimyth_Tracker_NE::send_first_ping();
		}
		wp_send_json_success( array( 'enabled' => $enabled ? 1 : 0 ) );
	} );

	// (The white-label gate now runs at the very top of this callback, so everything here —
	// tracker included — is already known to be non-rebranded.)

	// The constructor registers its own hooks; nothing else needs the instance.
	new Posimyth_Consent_Notice( array(
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

	// No "first successful use" gate any more: the consent notice now shows from activation and
	// keeps asking (snoozed by Dismiss, never silenced) until the user decides — here, in Onboarding, or
	// in Dashboard → Settings. Requiring a saved setting first meant a fresh install was never asked at
	// all unless the user happened to save something. See Posimyth_Consent_Notice::should_show().


	// "Why are you leaving?" survey — reason sent only if the user opted in.
	new Posimyth_Deactivation_Survey( array(
		'plugin_name'   => 'Nexter Extension',
		'plugin_slug'   => 'nexter-extension',
		// Matched against the Deactivate link's href, which is locale-proof (the row's data-slug is
		// sanitize_title of the TRANSLATED plugin name, so it broke on non-English locales).
		'plugin_file'   => plugin_basename( __FILE__ ),
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
	// During that same activation request the plugins_loaded callback above never ran either, so the
	// subclass has to be loaded here; the loader already loaded the shared base immediately (its
	// did_action( 'plugins_loaded' ) branch) when this file was included.
	require_once NEXTER_EXT_DIR . 'include/posimyth-sdk/class-posimyth-tracker-ne.php';
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

	// Stop the weekly analytics heartbeat. init() scheduled it but nothing ever cleared it, so a
	// deactivated plugin left a recurring event behind in WordPress permanently. Consent itself is
	// left in place — the user may reactivate, and re-asking someone who already answered is worse
	// than remembering. Deleting the plugin clears consent too; see uninstall.php.
	if ( class_exists( 'Posimyth_Tracker_NE' ) ) {
		Posimyth_Tracker_NE::unschedule();
	} else {
		wp_clear_scheduled_hook( 'posimyth_heartbeat_ne' );
	}
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
