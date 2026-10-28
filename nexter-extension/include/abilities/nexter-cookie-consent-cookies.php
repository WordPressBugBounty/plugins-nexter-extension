<?php
/**
 * Ability: Read the declared cookies list (scanned + manual) for the Cookie
 * Declaration shortcode / a future admin screen.
 *
 * @package Nexter_Extension
 */

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// The Abilities API arrived in WordPress 6.9; older sites skip this file.
if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

// Scanner writes (passive scan, active scan, manual add/edit) all happen through their
// own AJAX endpoints, never through an ability — so the lifecycle cache-version bump in
// lifecycle-filters.php never fires for them. Passive scanning in particular can update
// this data every hour per admin browsing the site; a 15-minute cached read would show
// a newly-scanned cookie as still missing for up to that long. Opted out entirely, same
// precedent as nexter/get-field-value(s).
add_filter(
	'nexter_ability_cache_ttl',
	static function ( $ttl, $name ) {
		if ( 'nexter/get-cookie-consent-cookies' === $name ) {
			return 0;
		}
		return $ttl;
	},
	10,
	2
);

wp_register_ability(
	'nexter/get-cookie-consent-cookies',
	array(
		'category'            => 'nexter-extension',
		'permission_callback' => 'nexter_mcp_permission_callback',
		'label'               => __( 'Get Declared Cookies', 'nexter-extension' ),
		'description'         => __( 'Returns every declared cookie (scanned or manually added) with its provider, category, purpose and duration, for the Cookie Declaration shortcode.', 'nexter-extension' ),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_get_cookie_consent_cookies',
		'meta'                => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'A blank `purpose` means the cookie was detected but never matched a starter description or a manual edit — display it as "Unrecognised", never invent a purpose for it.',
						'`edited: true` means an admin manually corrected this entry; never suggest overwriting it from a re-scan.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

/**
 * Execute callback for nexter/get-cookie-consent-cookies.
 *
 * @param array $input Ability input arguments (unused — no parameters accepted).
 * @return array
 */
function nexter_mcp_get_cookie_consent_cookies( array $input ): array {
	if ( ! class_exists( 'Nxt_Cookie_Consent' ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/cookie-consent/class-nxt-cookie-consent.php';
	}

	$cookies = Nxt_Cookie_Consent::get_declared_cookies();

	return array(
		'success' => true,
		'cookies' => $cookies,
		'total'   => count( $cookies ),
	);
}
