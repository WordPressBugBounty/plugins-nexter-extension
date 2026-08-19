<?php 
/**
 * Plugin Name: Nexter Extension
 * Plugin URI: https://nexterwp.com
 * Description: Nexter Extension adds lightweight performance, security, and admin features to WordPress so you can improve and manage your website without installing many plugins.
 * Version: 4.7.7
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
define( 'NEXTER_EXT_VER', '4.7.7' );
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
require_once NEXTER_EXT_DIR . 'include/classes/class-nxt-secret.php';

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
	//
	// Guarded on class_exists rather than assumed: the require_once above loads this product's own
	// subclass file, but declaring the class inside it can still fail if a sibling POSIMYTH plugin's
	// SDK copy won the shared loader with an incompatible Posimyth_Tracker_Base signature — the exact
	// multi-plugin case posimyth_sdk_register() exists for. Unlike the same bug class in WDesignKit
	// (admin-only), this call runs unconditionally above the is_admin() check below, so an unguarded
	// fatal here would take down the front end too, not just wp-admin.
	if ( class_exists( 'Posimyth_Tracker_NE' ) ) {
		Posimyth_Tracker_NE::init();
	}

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

	// Guarded on class_exists rather than assumed: the shared loader requires the three shared files
	// as a set from whichever registered copy wins, and a sibling plugin shipping an older/diverged
	// SDK revision can leave this class undefined. An unguarded `new` would fatal every wp-admin page.
	if ( class_exists( 'Posimyth_Consent_Notice' ) ) {
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
			// Legacy hook for Extension's own admin stylesheet, which has a `.nxt-notice-wrap` rule. The
			// SDK stylesheet no longer keys off it — it styles the stable `posi-*` classes — so this only
			// keeps that host rule matching.
			'css_prefix'       => 'nxt',
			// Passed explicitly even though it equals the SDK's neutral default. Branding must never be
			// inherited: one copy of the notice class serves every active POSIMYTH plugin, so a product
			// that passes nothing is painted by whatever that copy happens to default to. The value
			// travels inline as --posi-accent on this instance's own markup, so it cannot reach a sibling.
			'accent'           => '#1717CC',
		) );
	}

	// No "first successful use" gate any more: the consent notice now shows from activation and
	// keeps asking (snoozed by Dismiss, never silenced) until the user decides — here, in Onboarding, or
	// in Dashboard → Settings. Requiring a saved setting first meant a fresh install was never asked at
	// all unless the user happened to save something. See Posimyth_Consent_Notice::should_show().


	// "Why are you leaving?" survey — reason sent only if the user opted in.
	//
	// Guarded on class_exists for the same reason as Posimyth_Consent_Notice above: a diverged
	// sibling SDK copy can leave this class undefined, and an unguarded `new` would fatal every
	// wp-admin page (the Plugins screen included, so the admin could not even deactivate via the UI).
	if ( ! class_exists( 'Posimyth_Deactivation_Survey' ) ) {
		return;
	}

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
	// The same reason set, labels and icons Nexter Blocks shows. Extension and Blocks are one brand
	// with one dashboard, so an identical set is what a user expects to see, and it makes the two
	// products' reason charts on the hub directly comparable — the slugs match exactly.
	//
	// The icons are already #1717CC, which is both Nexter's brand and this SDK's default accent, so
	// there is no 'accent' key to set.
	//
	// A closure, not a literal array: this config is built at plugins_loaded, and calling __() there
	// trips WP 6.7's "translation loading was triggered too early" notice.
	'reasons'       => function () {
		return array(
			'just-debugging' => array(
				'label' => esc_html__( 'Just Debugging.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><g stroke="#1717CC" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.667" clip-path="url(#a)"><path d="M10 18.333a8.333 8.333 0 1 0 0-16.666 8.333 8.333 0 0 0 0 16.666ZM8.333 12.5v-5M11.667 12.5v-5"/></g><defs><clipPath id="a"><path fill="#fff" d="M0 0h20v20H0z"/></clipPath></defs></svg>',
			),
			'plugin-issues' => array(
				'label' => esc_html__( 'Plugin Issue.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M10.179 2.771a3.601 3.601 0 0 1 3.42 3.596l.113.007a.9.9 0 0 1 .273.08l2.73-1.745.08-.046a.9.9 0 0 1 .89 1.562L14.97 7.961c.244.623.391 1.283.428 1.956l.002.05h2.7l.092.004a.9.9 0 0 1 0 1.791l-.092.005h-2.7v.9l-.006.268a5.405 5.405 0 0 1-.172 1.103l2.44 1.457.076.05a.9.9 0 0 1-.918 1.537l-.082-.042-2.264-1.353a5.402 5.402 0 0 1-8.95.001L3.261 17.04l-.461-.773-.462-.772 2.44-1.457a5.403 5.403 0 0 1-.178-1.372v-.899H1.9a.901.901 0 0 1 0-1.8h2.7v-.05l.038-.42a6.301 6.301 0 0 1 .391-1.536L2.314 6.225l-.075-.054a.9.9 0 0 1 1.045-1.463l2.73 1.747a.9.9 0 0 1 .274-.081l.111-.007A3.602 3.602 0 0 1 10 2.767l.179.004ZM3.26 17.04a.9.9 0 0 1-.923-1.545l.923 1.545Zm3.652-8.873a4.499 4.499 0 0 0-.514 1.837v2.662a3.602 3.602 0 0 0 2.7 3.486v-4.385a.9.9 0 0 1 1.8 0v4.385a3.602 3.602 0 0 0 2.697-3.307l.004-.179V9.995a4.496 4.496 0 0 0-.514-1.829H6.913ZM10 4.566a1.802 1.802 0 0 0-1.8 1.8h3.6l-.009-.178a1.8 1.8 0 0 0-1.613-1.613L10 4.566Z"/></svg>',
			),
			'slow-performance' => array(
				'label' => esc_html__( 'Slow Performance.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M2.8 10.931c0 1.99.806 3.79 2.109 5.091l-1.272 1.272A8.972 8.972 0 0 1 1 10.931a9 9 0 0 1 9-9 9 9 0 0 1 6.364 15.364l-1.273-1.273A7.2 7.2 0 1 0 2.8 10.932Zm4.236-4.236 4.05 4.05-1.272 1.272-4.05-4.05 1.272-1.272Z"/></svg>',
			),
			'switched-alternative' => array(
				'label' => esc_html__( 'Switched to Alternative.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M5.532 9.195a.809.809 0 0 1 0 1.61l-.083.003H3.252a5.58 5.58 0 0 0 6.222 2.772l.352-.097a5.562 5.562 0 0 0 3.681-3.716.81.81 0 0 1 1.55.465 7.181 7.181 0 0 1-1.265 2.415l4.97 4.972.056.061a.81.81 0 0 1-1.137 1.14l-.062-.056-4.972-4.973a7.183 7.183 0 0 1-2.794 1.361v.001a7.199 7.199 0 0 1-7.236-2.406v.893a.808.808 0 1 1-1.617 0V10l.004-.083a.809.809 0 0 1 .805-.726h3.64l.083.004ZM6.506 1.2a7.199 7.199 0 0 1 7.235 2.406V2.72a.81.81 0 0 1 1.619 0v3.64a.81.81 0 0 1-.81.809h-3.64a.81.81 0 0 1 0-1.617h2.201a5.583 5.583 0 0 0-6.226-2.78h-.002a5.565 5.565 0 0 0-3.919 3.474l-.115.346a.81.81 0 0 1-1.551-.463l.071-.225a7.18 7.18 0 0 1 5.137-4.705v.001Z"/></svg>',
			),
			'no-longer-needed' => array(
				'label' => esc_html__( 'No Longer Needed.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M16.566 1.914a2.7 2.7 0 0 1 1.643 4.595c-.287.287-.633.5-1.009.633v8.259a2.701 2.701 0 0 1-2.7 2.7h-9a2.704 2.704 0 0 1-2.688-2.433L2.8 15.4V7.143a2.7 2.7 0 0 1-1.01-.634 2.701 2.701 0 0 1-.777-1.641L.999 4.6a2.702 2.702 0 0 1 2.7-2.7h12.6l.267.014ZM4.6 15.4l.004.089a.903.903 0 0 0 .896.811h9a.903.903 0 0 0 .9-.9V7.3H4.6v8.1Zm7.292-6.296a.9.9 0 0 1 0 1.791l-.092.005H8.2a.9.9 0 0 1 0-1.8h3.6l.092.004ZM3.699 3.701a.9.9 0 0 0-.9.9l.005.088a.902.902 0 0 0 .895.811h12.6l.09-.004A.901.901 0 0 0 17.2 4.6a.9.9 0 0 0-.811-.895l-.09-.004H3.7Z"/></svg>',
			),
			'compatibility-issues' => array(
				'label' => esc_html__( 'Compatibility Issue.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" fill-rule="evenodd" d="M19 10a9 9 0 0 1-9 9 9 9 0 0 1-9-9 9 9 0 0 1 9-9 9 9 0 0 1 9 9Zm-9 7.2a7.2 7.2 0 1 0 0-14.4 7.2 7.2 0 0 0 0 14.4Z" clip-rule="evenodd"/><path fill="#1717CC" fill-rule="evenodd" d="M16.036 4.414a.9.9 0 0 1 0 1.272l-10.35 10.35a.9.9 0 0 1-1.272-1.272l10.35-10.35a.9.9 0 0 1 1.272 0Z" clip-rule="evenodd"/></svg>',
			),
			'missing-feature' => array(
				'label' => esc_html__( 'Missing Feature.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M17.363 10a1.154 1.154 0 0 0-.263-.734l-.075-.084-1.377-1.376a1.636 1.636 0 0 1 .774-2.749l.157-.048a1.23 1.23 0 0 0 .408-.26l.11-.12a1.23 1.23 0 0 0-.093-1.633 1.228 1.228 0 0 0-2.012.426l-.049.155a1.638 1.638 0 0 1-2.585.919l-.164-.143-1.376-1.377a1.157 1.157 0 0 0-1.551-.077l-.085.077-1.378 1.376h.001l.184.05A2.864 2.864 0 1 1 4.404 7.99l-.051-.184-1.378 1.377a1.158 1.158 0 0 0-.338.818l.006.114a1.157 1.157 0 0 0 .331.703h.001l1.377 1.377.144.163a1.636 1.636 0 0 1-.92 2.585h.001a1.228 1.228 0 0 0-.024 2.381 1.228 1.228 0 0 0 1.504-.9 1.637 1.637 0 0 1 2.748-.775l1.377 1.376.085.077a1.16 1.16 0 0 0 .733.262l.113-.005a1.16 1.16 0 0 0 .705-.334l1.377-1.376a2.864 2.864 0 1 1 3.401-3.637l.05.183v.001h.002l1.377-1.377.075-.084a1.156 1.156 0 0 0 .263-.734ZM19 10a2.793 2.793 0 0 1-.634 1.771l-.185.204-1.377 1.375.001.001a1.638 1.638 0 0 1-2.75-.775v-.001a1.227 1.227 0 1 0-1.479 1.482l.207.064a1.637 1.637 0 0 1 .712 2.52l-.143.165-1.377 1.375a2.794 2.794 0 0 1-1.7.805l-.275.013a2.793 2.793 0 0 1-1.772-.633l-.203-.184-1.377-1.377v-.001a2.864 2.864 0 1 1-3.636-3.402l.184-.05-1.377-1.376v-.001a2.793 2.793 0 0 1-.805-1.701L1 10a2.793 2.793 0 0 1 .82-1.975l1.376-1.377a1.638 1.638 0 0 1 2.337.023c.202.21.344.47.412.753l.047.155a1.228 1.228 0 0 0 2.326-.776 1.227 1.227 0 0 0-.739-.81l-.155-.05a1.636 1.636 0 0 1-.776-2.748l1.377-1.376.203-.184a2.793 2.793 0 0 1 3.747.184l1.377 1.377.051-.185a2.861 2.861 0 0 1 4.759-1.171 2.864 2.864 0 0 1 .092 3.952l-.134.137a2.863 2.863 0 0 1-1.132.67l-.184.05 1.377 1.376.185.203A2.796 2.796 0 0 1 19 10Z"/></svg>',
			),
			'other' => array(
				'label' => esc_html__( 'Other Reasons.', 'nexter-extension' ),
				'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"><path fill="#1717CC" d="M10 1a9 9 0 0 1 9 9 9 9 0 0 1-9 9 9 9 0 0 1-9-9 9 9 0 0 1 9-9Zm0 1.8a7.2 7.2 0 1 0 0 14.4 7.2 7.2 0 0 0 0-14.4Zm0 10.8a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8Zm0-8.55a3.263 3.263 0 0 1 1.213 6.291.72.72 0 0 0-.274.18c-.04.046-.046.103-.045.163l.006.116a.9.9 0 0 1-1.794.105L9.1 11.8v-.225c0-1.038.837-1.66 1.444-1.904a1.463 1.463 0 1 0-2.007-1.358.9.9 0 1 1-1.8 0A3.262 3.262 0 0 1 10 5.05Z"/></svg>',
			),
		);
	},
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
