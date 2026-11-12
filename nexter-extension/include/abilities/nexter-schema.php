<?php
/**
 * Two renderings of an ability's schema: lean for listing, rich for reading.
 *
 * A tool list that carries every description and every instruction costs a client thousands of
 * tokens before it has decided which tool it wants. Lean is what a list should carry; rich is what
 * the one chosen tool is worth.
 *
 * @package Nexter_Extension
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( ! function_exists( 'nexter_ext_ability_group' ) ) {
	/**
	 * Which group of the AI Access screen an ability belongs in.
	 *
	 * Derived from the file that registered it, not from a list kept by hand: an ability added to
	 * an existing file is grouped correctly by existing, and one in a brand-new file gets a label
	 * from the filename rather than disappearing into "Other".
	 *
	 * @param object $ability Registered ability.
	 * @return array{key:string,label:string}
	 */
	function nexter_ext_ability_group( $ability ): array {
		$meta = (array) $ability->get_meta();
		$file = isset( $meta['source_file'] ) ? (string) $meta['source_file'] : '';

		// Files that belong together under one heading. Anything absent keeps its own file as its
		// group, which is a worse label but never a wrong one.
		$grouped = array(
			'nexter-cpt'                             => 'content-types',
			'nexter-taxonomy'                        => 'content-types',
			'nexter-content-types'                   => 'content-types',
			'nexter-scaffold'                        => 'content-types',
			'nexter-field-groups'                    => 'field-groups',
			'nexter-fields'                          => 'field-groups',
			'nexter-field-values'                    => 'field-values',
			'nexter-content-types-model'             => 'model',
			'nexter-content-model'                   => 'guidance',
			'nexter-schema'                          => 'guidance',
			'nexter-docs'                            => 'guidance',
			'nexter-list-changes'                    => 'activity',
			'nexter-apply-changes'                   => 'activity',
			'nexter-create-snippet'                  => 'snippets',
			'nexter-update-snippet'                  => 'snippets',
			'nexter-delete-snippet'                  => 'snippets',
			'nexter-get-snippet'                     => 'snippets',
			'nexter-list-snippets'                   => 'snippets',
			'nexter-toggle-snippet'                  => 'snippets',
			'nexter-create-template'                 => 'templates',
			'nexter-update-template'                 => 'templates',
			'nexter-delete-template'                 => 'templates',
			'nexter-get-template'                    => 'templates',
			'nexter-list-templates'                  => 'templates',
			'nexter-toggle-template'                 => 'templates',
			'nexter-smtp-settings'                   => 'settings',
			'nexter-security-settings'               => 'settings',
			'nexter-performance-settings'            => 'settings',
			'nexter-custom-fonts'                    => 'settings',
			'nexter-cookie-consent-settings'         => 'settings',
			'nexter-cookie-consent-cookies'          => 'settings',
			'nexter-cookie-consent-compliance-check' => 'settings',
			'nexter-image-optimization'              => 'images',
			'nexter-bulk-image-optimizer'            => 'images',
			'nexter-list-extensions'                 => 'extensions',
			'nexter-toggle-extension'                => 'extensions',
		);

		$labels = array(
			'content-types' => __( 'Content Types', 'nexter-extension' ),
			'field-groups'  => __( 'Field Groups', 'nexter-extension' ),
			'field-values'  => __( 'Field Values', 'nexter-extension' ),
			'model'         => __( 'Model & Import', 'nexter-extension' ),
			'guidance'      => __( 'Guidance', 'nexter-extension' ),
			'activity'      => __( 'Activity', 'nexter-extension' ),
			'snippets'      => __( 'Snippets', 'nexter-extension' ),
			'templates'     => __( 'Templates', 'nexter-extension' ),
			'settings'      => __( 'Settings', 'nexter-extension' ),
			'images'        => __( 'Images', 'nexter-extension' ),
			'extensions'    => __( 'Extensions', 'nexter-extension' ),
		);

		if ( '' === $file ) {
			return array(
				'key'   => 'other',
				'label' => __( 'Other', 'nexter-extension' ),
			);
		}

		$key = isset( $grouped[ $file ] ) ? $grouped[ $file ] : $file;
		if ( isset( $labels[ $key ] ) ) {
			return array(
				'key'   => $key,
				'label' => $labels[ $key ],
			);
		}

		// "nexter-widget-things" → "Widget Things". Readable, and obviously derived.
		$derived = ucwords( str_replace( '-', ' ', preg_replace( '/^nexter-/', '', $key ) ) );
		return array(
			'key'   => $key,
			'label' => '' !== $derived ? $derived : __( 'Other', 'nexter-extension' ),
		);
	}
}

if ( ! function_exists( 'nexter_ext_schema_lean' ) ) {
	/**
	 * The smallest rendering that still lets a client choose.
	 *
	 * Argument NAMES and types are kept, because dropping them turns "which tool" into a guess.
	 * What goes is prose: the long per-argument descriptions and the instruction block.
	 *
	 * @param object $ability Registered ability.
	 * @return array
	 */
	function nexter_ext_schema_lean( $ability ): array {
		$name   = $ability->get_name();
		$schema = (array) $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		$args = array();
		foreach ( $props as $key => $spec ) {
			$type = isset( $spec['type'] ) ? $spec['type'] : 'string';
			$args[ $key ] = is_array( $type ) ? implode( '|', $type ) : (string) $type;
		}

		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		return array(
			'name'     => $name,
			'label'    => method_exists( $ability, 'get_label' ) ? $ability->get_label() : $name,
			'mode'     => nexter_ext_ability_mode( $name ),
			'args'     => $args,
			'required' => array_values( $required ),
		);
	}
}

if ( ! function_exists( 'nexter_ext_schema_rich' ) ) {
	/**
	 * Everything a client needs to call this one ability correctly, and nothing about any other.
	 *
	 * The safety facts are read from the schema, not restated: a `dry_run` reported here is one the
	 * ability really accepts, which is the same rule the standing sentence and the briefing follow.
	 *
	 * @param object $ability Registered ability.
	 * @return array
	 */
	function nexter_ext_schema_rich( $ability ): array {
		$name   = $ability->get_name();
		$schema = (array) $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();
		$meta   = (array) $ability->get_meta();

		$out = nexter_ext_schema_lean( $ability );

		$out['description']  = method_exists( $ability, 'get_description' ) ? $ability->get_description() : '';
		$out['input_schema'] = $schema;
		$out['instructions'] = isset( $meta['annotations']['instructions'] ) ? (string) $meta['annotations']['instructions'] : '';

		if ( 'write' === $out['mode'] ) {
			$undo = nexter_ext_ability_reverses( $name );
			$out['safety'] = array(
				'dry_run'    => in_array( 'dry_run', $props, true ),
				'if_match'   => in_array( 'if_match', $props, true ),
				'confirm'    => in_array( 'confirm', $props, true ),
				'reversible' => '' !== $undo,
				'undo_with'  => '' !== $undo ? $undo : null,
			);
		}

		return $out;
	}
}

if ( ! function_exists( 'nexter_mcp_list_abilities' ) ) {
	/**
	 * Execute callback for nexter/list-abilities.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	function nexter_mcp_list_abilities( array $input ): array {
		$detail = isset( $input['detail'] ) ? (string) $input['detail'] : 'lean';
		$wanted = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		$mode   = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		// Naming one ability is a request for detail; making the caller also remember to say so
		// earned a second round trip for nothing.
		if ( '' !== $wanted && ! isset( $input['detail'] ) ) {
			$detail = 'rich';
		}

		$items = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( ! nexter_ext_ability_is_nexter( $name ) ) {
				continue;
			}
			if ( '' !== $wanted && $name !== $wanted ) {
				continue;
			}
			if ( '' !== $mode && nexter_ext_ability_mode( $name ) !== $mode ) {
				continue;
			}

			$items[] = ( 'rich' === $detail )
				? nexter_ext_schema_rich( $ability )
				: nexter_ext_schema_lean( $ability );
		}

		if ( '' !== $wanted && ! $items ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No Nexter ability is named "%s". Call this without the ability argument to see what there is.', $wanted ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'success'   => true,
			'detail'    => $detail,
			'total'     => count( $items ),
			'abilities' => $items,
		);
	}
}

nexter_ext_register_ability(
	'nexter/list-abilities',
	array(
		'label'            => __( 'List Nexter Abilities', 'nexter-extension' ),
		'description'      => __(
			'Lists the Nexter abilities with their arguments. Returns a compact form by default, and the full schema, instructions and safety features for one named ability.',
			'nexter-extension'
		),
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'detail'  => array(
					'type'        => 'string',
					'description' => 'lean returns names, arguments and types only. rich adds the full schema, the instructions and what safety arguments the ability accepts.',
					'enum'        => array( 'lean', 'rich' ),
					'default'     => 'lean',
				),
				'ability' => array(
					'type'        => 'string',
					'description' => 'One ability name, e.g. nexter/create-cpt. Implies rich unless detail says otherwise.',
				),
				'mode'    => array(
					'type'        => 'string',
					'description' => 'Only abilities of this kind.',
					'enum'        => array( 'read', 'write' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'detail'    => array( 'type' => 'string' ),
				'total'     => array( 'type' => 'integer' ),
				'abilities' => array( 'type' => 'array' ),
			),
		),
		'execute_callback' => 'nexter_mcp_list_abilities',
		'meta'             => array(
			'mode'           => 'read',
			'targets_object' => false,
			'show_in_rest'   => true,
			'mcp'            => array( 'public' => true ),
			'annotations'    => array(
				'instructions' => implode(
					"\n",
					array(
						'Use the lean form to decide which ability you want; it is a fraction of the size of the full schemas.',
						'Then call again naming that one ability to get its schema, its instructions and which of dry_run, if_match and confirm it accepts.',
						'The safety block is read from the live schema, so it never names an argument this site would reject.',
						'For how to drive the writes in general, call nexter/get-content-model instead — this ability describes shapes, not procedure.',
					)
				),
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);
