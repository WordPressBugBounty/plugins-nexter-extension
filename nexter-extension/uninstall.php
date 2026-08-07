<?php
/**
 * Runs when Nexter Extension is DELETED (not merely deactivated).
 *
 * Its job is to make sure nothing about the analytics arrangement outlives the plugin. Previously
 * there was no uninstall handling at all, so the sharing consent, the notice's answer and the
 * heartbeat schedule all stayed in wp_options — which meant reinstalling picked straight back up
 * where it left off and never asked again. Removing the plugin is a withdrawal, so a reinstall has
 * to begin from an unanswered state.
 *
 * Deliberately narrow: this only clears analytics/consent state and the cron it created. The
 * plugin's feature settings (extensions, performance, security, SEO, snippets) are left alone, so
 * deleting and reinstalling does not wipe someone's configuration.
 *
 * @package NexterExtension
 */

// WordPress defines this when it invokes an uninstall script. Nothing else may run this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Is another product that shares this consent still installed?
 *
 * The opt-in option, the notice's answer and the onboarding flag are shared across the Nexter
 * family. Deleting them while a sibling is still present would silently reset that sibling's
 * consent and re-prompt a user who had already decided, so those are only removed once this is the
 * last participating product on the site.
 *
 * @return bool
 */
function nxt_ext_suite_sibling_present() {
	$siblings = array(
		'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php',
		'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php',
	);

	foreach ( $siblings as $sibling ) {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $sibling ) ) {
			return true;
		}
	}

	return false;
}

$nxt_ext_sdk_base = __DIR__ . '/include/posimyth-sdk/class-posimyth-tracker-base.php';
$nxt_ext_tracker  = __DIR__ . '/include/posimyth-sdk/class-posimyth-tracker-ne.php';

if ( file_exists( $nxt_ext_sdk_base ) && file_exists( $nxt_ext_tracker ) ) {
	require_once $nxt_ext_sdk_base;
	require_once $nxt_ext_tracker;
}

// method_exists too, not only class_exists: active siblings load before uninstall.php runs, so an
// OLDER sibling may already have defined Posimyth_Tracker_Base without purge_state() — our subclass
// then extends that copy, and calling the missing method would fatal mid-uninstall.
if ( class_exists( 'Posimyth_Tracker_NE' ) && method_exists( 'Posimyth_Tracker_NE', 'purge_state' ) ) {
	Posimyth_Tracker_NE::purge_state( ! nxt_ext_suite_sibling_present(), 'nexter_suite' );
} else {
	// Fall back to clearing by name, so a broken or partial install still cleans up after itself.
	wp_clear_scheduled_hook( 'posimyth_heartbeat_ne' );

	delete_option( 'posimyth_ne_install_time' );
	delete_option( 'posimyth_ne_usage' );
	delete_option( 'posimyth_ne_first_use_at' );

	if ( ! nxt_ext_suite_sibling_present() ) {
		// Site options first (that is how they are written), then the legacy per-blog shape.
		delete_site_option( 'posimyth_nexter_share_analytics' );
		delete_site_option( 'posi_consent_dismissed_nexter_suite' );
		delete_site_option( 'posi_consent_snoozed_until_nexter_suite' );
		// Start of the notice's post-install quiet period — see the same delete in
		// Posimyth_Tracker_Base::purge_state(). Left behind, a reinstall inherits an expired window and
		// is asked immediately instead of getting the quiet days a fresh install should get.
		delete_site_option( 'posi_consent_grace_start_nexter_suite' );
		delete_site_option( 'posimyth_ne_legacy_consent_migrated' );
		delete_option( 'posimyth_nexter_share_analytics' );
		delete_option( 'posi_consent_dismissed_nexter_suite' );
		delete_option( 'posi_consent_snoozed_until_nexter_suite' );
		delete_option( 'posi_consent_grace_start_nexter_suite' );
		delete_option( 'nxt_onboarding_done' );
	}
}
