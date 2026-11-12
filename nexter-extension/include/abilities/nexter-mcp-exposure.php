<?php
/**
 * MCP exposure presets: which abilities an assistant can reach on this site.
 *
 * The filter in lifecycle-filters.php decides exposure. This is what a site sets without writing
 * one — a stored preset plus per-ability overrides, resolved through that same filter.
 *
 * @package Nexter_Extension
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

const NEXTER_MCP_PRESET_OPTION    = 'nexter_mcp_exposure_preset';
const NEXTER_MCP_OVERRIDES_OPTION = 'nexter_mcp_exposure_overrides';

if ( ! function_exists( 'nexter_ext_mcp_presets' ) ) {
	/**
	 * The presets, each described by what it lets through rather than by a list of names.
	 *
	 * A hardcoded list of abilities per preset would be wrong the day an ability is added — the new
	 * one would silently fall outside every preset. These are rules, so a new ability lands in the
	 * right place by virtue of what it declares.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	function nexter_ext_mcp_presets(): array {
		return array(
			'closed'     => array(
				'label'       => __( 'Closed', 'nexter-extension' ),
				'description' => __( 'Nothing is reachable. Use this while you decide.', 'nexter-extension' ),
			),
			'reads'      => array(
				'label'       => __( 'Read only', 'nexter-extension' ),
				'description' => __( 'An assistant can look at how the site is configured, and change nothing. This is the default.', 'nexter-extension' ),
			),
			'reversible' => array(
				'label'       => __( 'Reads and reversible writes', 'nexter-extension' ),
				// Moving something to the Trash IS reversible — restore puts it back — so this preset
				// allows it. What it keeps out is the permanent delete, the import and the settings.
				'description' => __( 'Adds the writes that return an undo you can replay, including moving something to the Trash. Permanent deletes, imports, settings and snippet edits stay closed.', 'nexter-extension' ),
			),
			'open'       => array(
				'label'       => __( 'Everything', 'nexter-extension' ),
				'description' => __( 'Every ability, including deletes and imports. Only for a site you are willing to rebuild.', 'nexter-extension' ),
			),
		);
	}
}

if ( ! function_exists( 'nexter_ext_mcp_preset' ) ) {
	/**
	 * The stored preset, falling back to the default this plugin ships with.
	 *
	 * @return string
	 */
	function nexter_ext_mcp_preset(): string {
		$stored = (string) get_option( NEXTER_MCP_PRESET_OPTION, 'reads' );
		return isset( nexter_ext_mcp_presets()[ $stored ] ) ? $stored : 'reads';
	}
}

if ( ! function_exists( 'nexter_ext_mcp_overrides' ) ) {
	/**
	 * Per-ability answers that win over the preset. { ability name => bool }.
	 *
	 * @return array<string,bool>
	 */
	function nexter_ext_mcp_overrides(): array {
		$stored = get_option( NEXTER_MCP_OVERRIDES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $name => $value ) {
			if ( is_string( $name ) && nexter_ext_ability_is_nexter( $name ) ) {
				$clean[ $name ] = (bool) $value;
			}
		}
		return $clean;
	}
}

if ( ! function_exists( 'nexter_ext_mcp_preset_allows' ) ) {
	/**
	 * Whether a preset lets one ability through.
	 *
	 * @param string $preset Preset key.
	 * @param string $name   Ability name.
	 * @param string $mode   Declared mode.
	 * @return bool
	 */
	function nexter_ext_mcp_preset_allows( string $preset, string $name, string $mode ): bool {
		if ( 'closed' === $preset ) {
			return false;
		}
		if ( 'open' === $preset ) {
			return true;
		}
		if ( 'write' !== $mode ) {
			return true;
		}
		if ( 'reversible' === $preset ) {
			// Declared, not guessed: the same map the undo block is built from. A write with no
			// declared reversal returns an honest "none", and that is exactly the write this preset
			// is meant to keep out.
			return '' !== nexter_ext_ability_reverses( $name );
		}

		// 'reads' and anything unrecognised.
		return false;
	}
}

// Priority 10 so a site's own filter at a later priority still has the last word — the preset is a
// convenience, not a ceiling.
add_filter(
	'nexter_ability_mcp_public',
	static function ( $public, $name, $mode ) {
		$overrides = nexter_ext_mcp_overrides();
		if ( array_key_exists( $name, $overrides ) ) {
			return $overrides[ $name ];
		}

		$preset = nexter_ext_mcp_preset();

		// The shipped default is writes-closed, which is what 'reads' already means. Leaving the
		// incoming value alone here keeps a site that never opened this screen on exactly the
		// behaviour it had before the screen existed.
		if ( 'reads' === $preset ) {
			return $public;
		}

		return nexter_ext_mcp_preset_allows( $preset, (string) $name, (string) $mode );
	},
	10,
	3
);
