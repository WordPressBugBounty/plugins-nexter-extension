<?php
/**
 * Abilities: Get/Update Cookie Consent settings.
 *
 * Named per the read/write contract in lifecycle-filters.php — 'get-' so a read is
 * cached, 'update-' so a write bumps the global cache generation on success.
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
	'nexter/get-cookie-consent-settings',
	array(
		'category'            => 'nexter-extension',
		'permission_callback' => 'nexter_mcp_permission_callback',
		'label'               => __( 'Get Cookie Consent Settings', 'nexter-extension' ),
		'description'         => __( 'Retrieves the Cookie Consent module settings: switch state, categories, script-blocking rules, consent-record options and legal URLs.', 'nexter-extension' ),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_get_cookie_consent_settings',
		'meta'                => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Returns the full merged settings tree (defaults + stored option).',
						'Category IDs (necessary/functional/analytics/marketing) are frozen — never propose renaming one.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

wp_register_ability(
	'nexter/update-cookie-consent-settings',
	array(
		'category'            => 'nexter-extension',
		'permission_callback' => 'nexter_mcp_permission_callback',
		'label'               => __( 'Update Cookie Consent Settings', 'nexter-extension' ),
		'description'         => __( 'Updates Cookie Consent settings. A category or blocking-rule change automatically bumps the config version, which re-prompts every visitor; cosmetic settings never do.', 'nexter-extension' ),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type'        => 'object',
					'description' => 'Partial or full settings object. Merged over the current stored settings, then sanitized against the same allowlist the admin Save button uses.',
				),
				'dry_run' => nexter_ext_dry_run_arg( 'the cookie consent save' ),
			),
			'required'             => array( 'settings' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_update_cookie_consent_settings',
		'meta'                => array(
			'mode'           => 'write',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => false ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Use nexter/get-cookie-consent-settings first to see the current shape before updating.',
						'Never attempt to rename a category id (necessary/functional/analytics/marketing) — labels are editable, ids are not.',
					)
				),
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

/**
 * Execute callback for nexter/get-cookie-consent-settings.
 *
 * @param array $input Ability input arguments (unused — no parameters accepted).
 * @return array
 */
function nexter_mcp_get_cookie_consent_settings( array $input ): array {
	if ( ! class_exists( 'Nxt_Cookie_Consent' ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/cookie-consent/class-nxt-cookie-consent.php';
	}

	return array(
		'success'  => true,
		'settings' => Nxt_Cookie_Consent::get_settings(),
	);
}

/**
 * Execute callback for nexter/update-cookie-consent-settings.
 *
 * Reuses the exact same sanitize_settings()/save path the admin AJAX handler runs —
 * per CLAUDE.md this must enter at the seam, never reimplement the write.
 *
 * @param array $input Ability input arguments.
 * @return array
 */
function nexter_mcp_update_cookie_consent_settings( array $input ): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array(
			'success' => false,
			'error'   => __( 'Insufficient permissions.', 'nexter-extension' ),
		);
	}

	if ( ! class_exists( 'Nxt_Cookie_Consent' ) ) {
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/cookie-consent/class-nxt-cookie-consent.php';
	}

	$incoming = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	// Not array_replace_recursive(): it merges lists by index, so a trimmed blocking-rule list
	// would be topped up from the stored one and the deleted rules would come straight back.
	$merged   = Nxt_Cookie_Consent::merge_with_defaults( Nxt_Cookie_Consent::get_settings(), $incoming );

	// Same sanitizer the admin AJAX handler calls, exposed as a public method so this
	// ability never reimplements the allowlist logic.
	$sanitized = Nxt_Cookie_Consent::sanitize_settings_for_ability( $merged );

	// Below the sanitiser, which is the point: this is where a caller sees what the allowlist
	// dropped or rewrote before anything is stored.
	if ( ! empty( $input['dry_run'] ) ) {
		$stored = Nxt_Cookie_Consent::get_settings();
		// 'switch' lives in the shared extra-options tree and is never something this ability
		// stores; the sanitiser leaves its default (false) while get_settings() reports the real
		// one, so comparing them called every no-op an "update" and listed a phantom change.
		$after           = $sanitized;
		$after['switch'] = $stored['switch'];
		return nexter_ext_dry_run(
			wp_json_encode( $stored ) === wp_json_encode( $after ) ? 'no change' : 'update',
			array(
				'option'   => 'nexter_cookie_consent_settings',
				'settings' => $after,
				'changes'  => nexter_ext_dry_run_changes( (array) $stored, (array) $after ),
			)
		);
	}

	Nxt_Cookie_Consent::store_settings( $sanitized );
	Nxt_Options::flush( 'nexter_cookie_consent_settings' );
	nexter_ext_ability_cache_version( true );

	return array(
		'success'  => true,
		'settings' => $sanitized,
	);
}
