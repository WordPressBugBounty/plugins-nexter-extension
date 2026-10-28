<?php
/**
 * Ability: Cookie Consent Compliance Check.
 *
 * Named 'get-' per the read/write contract in lifecycle-filters.php — this is a pure
 * read of the live config, so it is safe to cache like every other 'get-' ability.
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

wp_register_ability(
	'nexter/get-cookie-consent-compliance-check',
	array(
		'category'            => 'nexter-extension',
		'permission_callback' => 'nexter_mcp_permission_callback',
		'label'               => __( 'Get Cookie Consent Compliance Check', 'nexter-extension' ),
		'description'         => __( 'Reads the live Cookie Consent configuration and reports pass/warn/fail/unavailable for each documented check. Never reports a false pass.', 'nexter-extension' ),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_get_cookie_consent_compliance_check',
		'meta'                => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Each check is pass, warn, fail, or unavailable — unavailable means this codebase cannot verify it yet (e.g. banner accessibility lives in Nexter Blocks, not here).',
						'Never present an unavailable check as a pass when summarising results to a user.',
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
 * Execute callback for nexter/get-cookie-consent-compliance-check.
 *
 * @param array $input Ability input arguments (unused — no parameters accepted).
 * @return array
 */
function nexter_mcp_get_cookie_consent_compliance_check( array $input ): array {
	if ( ! class_exists( 'Nxt_Cookie_Consent' ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/cookie-consent/class-nxt-cookie-consent.php';
	}

	$checks = Nxt_Cookie_Consent::run_compliance_check();

	$counts = array(
		'pass'        => 0,
		'warn'        => 0,
		'fail'        => 0,
		'unavailable' => 0,
	);
	foreach ( $checks as $check ) {
		if ( isset( $counts[ $check['status'] ] ) ) {
			++$counts[ $check['status'] ];
		}
	}

	return array(
		'success' => true,
		'checks'  => $checks,
		'summary' => $counts,
	);
}
