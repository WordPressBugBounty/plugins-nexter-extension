<?php
/**
 * Abilities: Content Fields — field group CRUD plus the active toggle.
 *
 * @package Nexter_Extension
 * @since   4.7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( nexter_mcp_ct_module_active() ) {

	nexter_ext_register_ability(
		'nexter/list-field-groups',
		array(
			'label'               => __( 'List Field Groups', 'nexter-extension' ),
			'description'         => __(
				'Lists Content Fields field groups with their key, status, active flag, location rules and field count.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'active_only' => array(
						'type'        => 'boolean',
						'description' => 'Only groups that are actually in effect (published and active).',
						'default'     => false,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'total'   => array( 'type' => 'integer' ),
					'groups'  => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_list_field_groups',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Start here to discover group IDs, group keys and field names before reading or writing any field value.',
							'The group key is the stable identifier — it forms part of every stored meta key, so renaming it orphans saved values.',
							'Leave active_only false (the default) when you need to see disabled groups too, e.g. to switch one back on.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	nexter_ext_register_ability(
		'nexter/get-field-group',
		array(
			'label'               => __( 'Get Field Group', 'nexter-extension' ),
			'description'         => __(
				'Retrieves one field group by ID or key, including every field definition and location rule.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
				),
				'required'             => array( 'group' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_get_field_group',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Accepts either the numeric ID or the group key, so you can address a group by name.',
							'Returns data.fields (the field definitions, including nested repeater subfields) and data.location_rules.',
							'Read this before adding or editing a field so you can see the existing field names and avoid a collision.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);

	nexter_ext_register_ability(
		'nexter/create-field-group',
		array(
			'label'               => __( 'Create Field Group', 'nexter-extension' ),
			'description'         => __(
				'Creates a Content Fields field group with its location rules and, optionally, its initial fields.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'data'    => array(
						'type'        => 'object',
						'description' => 'Group configuration: title, key, description, fields, location_rules, position, style, label_placement, instruction_placement, order_no, active, show_in_rest.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check this save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_create_field_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Minimum viable payload: { "data": { "title": "Book Details" } } — a group key is generated for you if you leave it blank, and it defaults to showing on posts.',
							'location_rules decides where the fields appear. Default is [{"param":"post_type","operator":"==","value":"post"}].',
							'Rules in one array are ANDed. Nest arrays to OR the groups: [[ruleA, ruleB], [ruleC]] means (A AND B) OR C.',
							'Call nexter/get-location-choices to discover the valid values for a rule param before writing one.',
							'fields is an array of field definitions, each needing at minimum name, label and type. Use nexter/list-field-types for the valid types, or create the group empty and add fields with nexter/add-field-to-group.',
							'Field names must be unique inside the group; duplicates are auto-renamed to prevent meta key collisions.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
				),
			),
		)
	);

	nexter_ext_register_ability(
		'nexter/update-field-group',
		array(
			'label'               => __( 'Update Field Group', 'nexter-extension' ),
			'description'         => __(
				'Updates a field group: its title, location rules, display options, or its whole field list.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => 'Field group ID.',
						'minimum'     => 1,
					),
					'data'    => array(
						'type'        => 'object',
						'description' => 'Keys to change. Merged over the stored configuration, so you can send only what you want to update.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check this save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'id', 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_update_field_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Unlike the post type and taxonomy abilities, this one MERGES: keys you omit keep their stored value. Send just { "data": { "title": "New title" } } to rename.',
							'The one exception is fields — it is a single value, so sending it replaces the entire field list. To change one field, use nexter/update-field-in-group instead.',
							'Renaming the group key, or a field name, is blocked once values have been saved against it — the call fails rather than orphaning data. Relay that error; do not try to work around it.',
							'To only switch the group on or off, prefer nexter/toggle-field-group.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
				),
			),
		)
	);

	nexter_ext_register_ability(
		'nexter/delete-field-group',
		array(
			'label'               => __( 'Delete Field Group (Trash)', 'nexter-extension' ),
			'description'         => __(
				'Moves a field group to the Trash. Saved field values stay in the database and come back if the group is restored.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Field group ID to trash.',
						'minimum'     => 1,
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the trash' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_delete_field_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Reversible: the group is trashed, not deleted. Restore it with nexter/restore-content-type.',
							'The fields stop appearing in the editor immediately and nxt_field_value() stops returning their values, but nothing is erased.',
							'If the user only wants the fields hidden for now, nexter/toggle-field-group is the gentler option.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
				),
			),
		)
	);

	nexter_ext_register_ability(
		'nexter/toggle-field-group',
		array(
			'label'               => __( 'Activate or Deactivate Field Group', 'nexter-extension' ),
			'description'         => __(
				'Switches a field group on or off, leaving its fields, location rules and saved values untouched.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Field group ID.',
						'minimum'     => 1,
					),
					'active' => array(
						'type'        => 'boolean',
						'description' => 'Target state. Omit to flip whatever the current state is.',
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the group save' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_toggle_field_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'An inactive group renders nowhere and its fields resolve to null through nxt_field_value(), but every saved value is preserved and returns when it is switched back on.',
							'This is the safe way to take a set of fields out of service without risking data.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);
}

/**
 * List field groups.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_list_field_groups( array $input ): array {
	$result = nexter_mcp_ct_rest(
		'GET',
		'/field-groups',
		array( 'active_only' => ! empty( $input['active_only'] ) )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$items = isset( $result['data']['items'] ) && is_array( $result['data']['items'] ) ? $result['data']['items'] : array();

	$groups = array();
	foreach ( $items as $item ) {
		$meta     = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();
		$fields   = isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? $meta['fields'] : array();
		$groups[] = array(
			'id'             => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'title'          => isset( $item['title'] ) ? (string) $item['title'] : '',
			'key'            => isset( $meta['key'] ) ? (string) $meta['key'] : '',
			'status'         => isset( $item['status'] ) ? (string) $item['status'] : '',
			'active'         => nexter_mcp_ct_is_active( $meta ),
			'field_count'    => count( $fields ),
			'field_names'    => nexter_mcp_ct_field_names( $fields ),
			'location_rules' => isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
			'last_update'    => isset( $item['last_update'] ) ? (string) $item['last_update'] : '',
		);
	}

	return array(
		'success' => true,
		'total'   => count( $groups ),
		'groups'  => $groups,
	);
}

/**
 * Top-level field names in a field list (repeater subfields are nested, not listed here).
 *
 * @param array $fields Field definitions.
 * @return string[]
 */
function nexter_mcp_ct_field_names( array $fields ): array {
	$names = array();
	foreach ( $fields as $field ) {
		if ( is_array( $field ) && ! empty( $field['name'] ) ) {
			$names[] = (string) $field['name'];
		}
	}
	return $names;
}

/**
 * Get one field group by ID or key.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_get_field_group( array $input ): array {
	$group = isset( $input['group'] ) ? $input['group'] : '';

	$definition = nexter_mcp_ct_locate_group( $group );
	if ( ! $definition ) {
		return nexter_mcp_ct_group_not_found( is_scalar( $group ) ? (string) $group : '' );
	}

	$result = nexter_mcp_ct_rest( 'GET', '/field-groups/' . $definition['id'] );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array(
		'success' => true,
		'group'   => $result['data'],
	);
}

/**
 * Create a field group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_create_field_group( array $input ): array {
	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return nexter_mcp_ct_fail( 'data is required, e.g. { "title": "Book Details" }.' );
	}

	$invalid = nexter_mcp_ct_invalid_field_types( isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array() );
	if ( ! empty( $invalid ) ) {
		return nexter_mcp_ct_field_types_fail( $invalid );
	}

	if ( ! isset( $data['title'] ) || '' === trim( (string) $data['title'] ) ) {
		return nexter_mcp_ct_fail( 'data.title is required and cannot be blank, e.g. { "title": "Book Details" }.' );
	}

	$problems = nexter_mcp_ct_group_problems( $data );
	if ( ! empty( $problems ) ) {
		return nexter_mcp_ct_fail( 'The field group was not saved: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$dry_run = ! empty( $input['dry_run'] );

	$result = nexter_mcp_ct_rest( 'POST', '/field-groups', array( 'data' => $data, 'dry_run' => $dry_run ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// A dry run returns its own report, not a stored group.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success' => true,
		'group'   => $result['data'],
	);
}

/**
 * Not-found failure for a field group reference.
 *
 * @param mixed $group Group id or key as given.
 * @return array
 */
function nexter_mcp_ct_group_not_found( $group ): array {
	return nexter_mcp_ct_fail(
		sprintf( 'No field group found for "%s". Call nexter/list-field-groups to see the available IDs and keys.', is_scalar( $group ) ? (string) $group : '' ),
		array(
			'code'   => 'nexter_not_found',
			'status' => 404,
		)
	);
}

/**
 * Failure for unusable field types; locked Pro types are named as such.
 *
 * @param string[] $invalid Type slugs from nexter_mcp_ct_invalid_field_types().
 * @return array
 */
function nexter_mcp_ct_field_types_fail( array $invalid ): array {
	$locked = function_exists( 'nxt_field_type_is_locked' ) ? array_values( array_filter( $invalid, 'nxt_field_type_is_locked' ) ) : array();
	if ( $locked && count( $locked ) === count( $invalid ) ) {
		return nexter_mcp_ct_fail(
			sprintf( 'Field type(s) %s require Nexter Extension Pro.', implode( ', ', $locked ) ),
			array(
				'code'   => 'nexter_field_locked',
				'status' => 409,
			)
		);
	}
	return nexter_mcp_ct_fail( sprintf( 'Unknown field type(s): %s. Call nexter/list-field-types for the supported list.', implode( ', ', $invalid ) ) );
}

/**
 * Field types in a field list that this build does not render.
 *
 * @param array $fields Field definitions.
 * @return string[] Unknown type slugs.
 */
function nexter_mcp_ct_invalid_field_types( array $fields ): array {
	$known = array_merge(
		nexter_mcp_ct_creatable_field_types(),
		array_keys( nexter_mcp_ct_legacy_field_types() )
	);

	$invalid = array();
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		if ( '' !== $type && ! in_array( $type, $known, true ) && ! in_array( $type, $invalid, true ) ) {
			$invalid[] = $type;
		}
		// A repeater nests its subfields under 'fields' (not 'sub_fields' — see the 'repeater'
		// branch of Nxt_Content_Types_Field_Groups_Render::render_field).
		if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
			foreach ( nexter_mcp_ct_invalid_field_types( $field['fields'] ) as $nested ) {
				if ( ! in_array( $nested, $invalid, true ) ) {
					$invalid[] = $nested;
				}
			}
		}
	}

	return $invalid;
}

/**
 * Structural problems in a field list that the group route would otherwise store as sent.
 *
 * @param array  $fields Field definitions as the caller sent them.
 * @param array  $stored Definitions already stored at this level; a field found there keeps its name and type.
 * @param string $path   Message prefix when recursing into a repeater.
 * @return string[] One sentence per problem, empty when the list is sound.
 */
function nexter_mcp_ct_field_problems( array $fields, array $stored = array(), string $path = '' ): array {
	$legacy   = array_keys( nexter_mcp_ct_legacy_field_types() );
	$problems = array();

	$known = array();
	foreach ( $stored as $field ) {
		if ( is_array( $field ) && isset( $field['name'] ) && is_scalar( $field['name'] ) ) {
			$known[ (string) $field['name'] ] = $field;
		}
	}

	foreach ( array_values( $fields ) as $index => $field ) {
		$where = sprintf( '%sfield #%d', $path, $index + 1 );

		if ( ! is_array( $field ) ) {
			$problems[] = $where . ' is not an object.';
			continue;
		}

		$name = isset( $field['name'] ) && is_scalar( $field['name'] ) ? (string) $field['name'] : '';
		if ( '' === $name ) {
			$problems[] = $where . ' has no name.';
			continue;
		}

		// A stored field is left alone: renaming it is refused once it holds values.
		$is_new = ! isset( $known[ $name ] );
		$where  = sprintf( '%s"%s"', $path, $name );

		if ( $is_new && sanitize_key( $name ) !== $name ) {
			$problems[] = $where . ' is not a valid field name: use lowercase letters, numbers, underscores and hyphens only.';
		}
		if ( $is_new && isset( $field['type'] ) && in_array( (string) $field['type'], $legacy, true ) ) {
			$problems[] = sprintf( '%s uses the withdrawn type "%s", which cannot be given to a new field.', $where, (string) $field['type'] );
		}
		// The renderer reads "fields"; a repeater sent with "sub_fields" saves with no subfields at all.
		if ( isset( $field['sub_fields'] ) ) {
			$problems[] = $where . ' nests its subfields under "sub_fields"; a repeater holds them under "fields".';
		}
		// A bare "post_type" / "taxonomy" is stored and ignored, so the field quietly offers Posts only
		// and every value pointing at the intended type is sanitised to empty.
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		if ( $is_new && in_array( $type, array( 'post_object', 'relationship', 'page_link' ), true )
			&& ( isset( $field['post_type'] ) || isset( $field['post_types'] ) ) && ! isset( $field[ $type . '_post_types' ] ) ) {
			$problems[] = sprintf( '%1$s limits its post types with "post_type", which is ignored; send "%2$s_post_types" as an array of post type keys.', $where, $type );
		}
		if ( $is_new && 'taxonomy' === $type && isset( $field['taxonomy'] ) && ! isset( $field['taxonomy_name'] ) ) {
			$problems[] = $where . ' names its taxonomy with "taxonomy", which is ignored; send "taxonomy_name".';
		}
		if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
			$inner    = ( ! $is_new && isset( $known[ $name ]['fields'] ) && is_array( $known[ $name ]['fields'] ) ) ? $known[ $name ]['fields'] : array();
			$problems = array_merge( $problems, nexter_mcp_ct_field_problems( $field['fields'], $inner, $where . ' > ' ) );
		}
	}

	return $problems;
}

/**
 * Location rules the matcher cannot evaluate, which would leave the group attached nowhere.
 *
 * @param mixed $rules Rules as sent: a flat AND list, or a list of AND lists that are ORed.
 * @return string[] One sentence per problem.
 */
function nexter_mcp_ct_location_problems( $rules ): array {
	if ( ! is_array( $rules ) ) {
		return array( 'location_rules must be an array of { param, operator, value } rules.' );
	}

	// Read from the live schema so this list cannot drift from what nexter/get-location-choices offers.
	$params  = array();
	$choices = wp_get_ability( 'nexter/get-location-choices' );
	if ( $choices ) {
		$schema = $choices->get_input_schema();
		$params = isset( $schema['properties']['param']['enum'] ) ? (array) $schema['properties']['param']['enum'] : array();
	}
	$operators = array( '==', '=', '!=', '>', '<', 'contains', 'empty', '!empty' );

	$first  = isset( $rules[0] ) ? $rules[0] : null;
	$nested = is_array( $first ) && ! isset( $first['param'] ) && ! isset( $first['operator'] );

	$problems = array();
	foreach ( ( $nested ? $rules : array( $rules ) ) as $group ) {
		foreach ( (array) $group as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['param'] ) || ! is_scalar( $rule['param'] ) ) {
				$problems[] = 'Every location rule needs a param.';
				continue;
			}
			$param = (string) $rule['param'];
			if ( ! empty( $params ) && ! in_array( $param, $params, true ) ) {
				$problems[] = sprintf( 'Location param "%s" is not supported. Valid params: %s.', sanitize_text_field( $param ), implode( ', ', $params ) );
			}
			// An unknown operator is evaluated as "==", so the rule would match something other than what was written.
			if ( isset( $rule['operator'] ) && ! in_array( $rule['operator'], $operators, true ) ) {
				$problems[] = sprintf( 'Location operator "%s" is not supported. Use == or !=.', is_scalar( $rule['operator'] ) ? sanitize_text_field( (string) $rule['operator'] ) : '' );
			}
		}
	}

	return array_values( array_unique( $problems ) );
}

/**
 * Everything wrong with a group payload's fields and location rules, checked before the route sees it.
 *
 * @param array $data   Group payload.
 * @param array $stored Field definitions already stored in the group being updated.
 * @return string[]
 */
function nexter_mcp_ct_group_problems( array $data, array $stored = array() ): array {
	$problems = array();

	// Unknown keys (e.g. "location" instead of "location_rules") were silently dropped by the sanitizer.
	if ( class_exists( 'Nxt_Content_Types_Field_Groups_Fields' ) ) {
		$known   = array_merge( array_keys( Nxt_Content_Types_Field_Groups_Fields::get_fields() ), array( 'id', 'kind' ) );
		$unknown = array_diff( array_keys( $data ), $known );
		if ( ! empty( $unknown ) ) {
			$problems[] = sprintf( 'Unknown group key(s): %s. Allowed: %s.', implode( ', ', $unknown ), implode( ', ', array_diff( $known, array( 'id', 'kind' ) ) ) );
		}
	}

	if ( array_key_exists( 'fields', $data ) ) {
		$problems = array_merge(
			$problems,
			is_array( $data['fields'] )
				? nexter_mcp_ct_field_problems( $data['fields'], $stored )
				: array( 'fields must be an array of field definitions.' )
		);
	}
	if ( array_key_exists( 'location_rules', $data ) ) {
		$problems = array_merge( $problems, nexter_mcp_ct_location_problems( $data['location_rules'] ) );
	}

	return $problems;
}

/**
 * Update a field group (merge semantics).
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_update_field_group( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive field group id is required.' );
	}

	$definition = nexter_mcp_ct_definition( $id );
	if ( ! $definition || 'group_fields' !== $definition['kind'] ) {
		return nexter_mcp_ct_fail( sprintf( 'No field group exists with ID %d.', $id ), array( 'code' => 'nexter_not_found', 'status' => 404 ) );
	}

	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return nexter_mcp_ct_fail( 'data is required and must contain at least one key to change.' );
	}

	$invalid = nexter_mcp_ct_invalid_field_types( isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array() );
	if ( ! empty( $invalid ) ) {
		return nexter_mcp_ct_field_types_fail( $invalid );
	}

	if ( array_key_exists( 'title', $data ) && '' === trim( (string) $data['title'] ) ) {
		return nexter_mcp_ct_fail( 'data.title cannot be blank. Leave the key out to keep the current title.' );
	}

	$stored   = ( isset( $definition['meta']['fields'] ) && is_array( $definition['meta']['fields'] ) ) ? $definition['meta']['fields'] : array();
	$problems = nexter_mcp_ct_group_problems( $data, $stored );
	if ( ! empty( $problems ) ) {
		return nexter_mcp_ct_fail( 'The field group was not saved: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$dry_run = ! empty( $input['dry_run'] );

	$result = nexter_mcp_ct_rest( 'PUT', '/field-groups/' . $id, array( 'data' => $data, 'dry_run' => $dry_run ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// Flat, like every other dry run. Nesting it under `group` hides the dry_run flag from the top
	// level, and the lifecycle reads that flag to decide whether to attach an undo block — so a
	// nested report earns an undo descriptor for a write that never happened.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success' => true,
		'group'   => $result['data'],
	);
}

/**
 * Trash a field group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_delete_field_group( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive field group id is required.' );
	}

	$definition = nexter_mcp_ct_definition( $id );
	if ( ! $definition || 'group_fields' !== $definition['kind'] ) {
		return nexter_mcp_ct_fail( sprintf( 'No field group exists with ID %d.', $id ), array( 'code' => 'nexter_not_found', 'status' => 404 ) );
	}

	$found = Nxt_Content_Types_Model_Writer::lookup( $id );

	$result = nexter_mcp_ct_rest(
		'DELETE',
		'/field-groups/' . $id,
		array( 'dry_run' => ! empty( $input['dry_run'] ) )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The route's own report already carries dry_run and would; merging it in keeps both at the
	// top level, and drops the "moved to Trash" message that nothing did.
	if ( ! empty( $input['dry_run'] ) ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return nexter_mcp_ct_with_moved_id(
		array_merge(
			array(
				'success' => true,
				'message' => 'Field group moved to Trash. Restore it with nexter/restore-content-type, passing trash_id.',
			),
			is_array( $result['data'] ) ? $result['data'] : array()
		),
		$found,
		true
	);
}

/**
 * Activate or deactivate a field group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_toggle_field_group( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive field group id is required.' );
	}

	$definition = nexter_mcp_ct_definition( $id );
	if ( ! $definition || 'group_fields' !== $definition['kind'] ) {
		return nexter_mcp_ct_fail( sprintf( 'No field group exists with ID %d.', $id ), array( 'code' => 'nexter_not_found', 'status' => 404 ) );
	}

	$was_active = nexter_mcp_ct_is_active( $definition['meta'] );
	$target     = array_key_exists( 'active', $input ) ? (bool) $input['active'] : ! $was_active;

	if ( $target === $was_active ) {
		return array(
			'success' => true,
			'id'      => $id,
			'active'  => $target,
			'message' => sprintf( 'Already %s; nothing changed.', $target ? 'active' : 'inactive' ),
			// Stated here, or the lifecycle attaches toggle-back to a call that changed nothing.
			'undo'    => array(
				'strategy' => 'none',
				'reason'   => 'Nothing changed, so there is nothing to reverse.',
			),
		);
	}

	// The field-group PUT merges into stored meta, so a single key is enough here — no need to
	// round-trip the whole configuration the way the post type / taxonomy toggle must.
	$result = nexter_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $id,
		array(
			'data'    => array( 'active' => $target ),
			'dry_run' => ! empty( $input['dry_run'] ),
		)
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	if ( ! empty( $input['dry_run'] ) ) {
		return nexter_ext_dry_run(
			'update',
			array(
				'id'     => $id,
				'active' => $target,
				'from'   => $was_active,
			)
		);
	}

	return array(
		'success' => true,
		'id'      => $id,
		'active'  => $target,
		'group'   => $result['data'],
	);
}

/**
 * Duplicate a field group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_duplicate_field_group( array $input ): array {
	$definition = nexter_mcp_ct_locate_group( isset( $input['group'] ) ? $input['group'] : '' );
	if ( ! $definition ) {
		return nexter_mcp_ct_group_not_found( isset( $input['group'] ) && is_scalar( $input['group'] ) ? (string) $input['group'] : '' );
	}

	$data = isset( $definition['meta'] ) && is_array( $definition['meta'] ) ? $definition['meta'] : array();
	if ( empty( $data ) ) {
		return nexter_mcp_ct_fail( 'That field group has no stored configuration to copy.' );
	}

	$source_title = (string) ( $data['title'] ?? '' );
	$source_key   = (string) ( $data['key'] ?? '' );

	$title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';
	if ( '' === $title ) {
		/* translators: %s: source field group title. */
		$title = sprintf( __( '%s (Copy)', 'nexter-extension' ), '' !== $source_title ? $source_title : __( 'Field Group', 'nexter-extension' ) );
	}
	$data['title'] = $title;

	// The controller auto-suffixes a key that is already taken, so a requested key that collides still lands —
	// but under a name the caller did not choose.
	$key = isset( $input['key'] ) ? sanitize_key( (string) $input['key'] ) : '';
	if ( '' === $key ) {
		$key = '' !== $source_key ? $source_key . '_copy' : '';
	}
	if ( '' !== $key ) {
		$data['key'] = $key;
	} else {
		unset( $data['key'] );
	}

	// A copy starts inactive unless asked otherwise. Two active groups on the same location rules
	// both render, which on a duplicate is almost never what was wanted.
	$data['active'] = ! empty( $input['active'] );

	// Straight through the create ability, so the copy is validated exactly as a hand-built group would be,
	// including the unknown-field-type check.
	$created = nexter_mcp_create_field_group(
		array(
			'data'    => $data,
			'dry_run' => ! empty( $input['dry_run'] ),
		)
	);
	if ( empty( $created['success'] ) ) {
		return $created;
	}

	return array_merge(
		$created,
		array(
			'duplicated_from' => array(
				'id'    => (int) $definition['id'],
				'key'   => $source_key,
				'title' => $source_title,
			),
		)
	);
}

if ( nexter_mcp_ct_module_active() ) {

	nexter_ext_register_ability(
		'nexter/duplicate-field-group',
		array(
			'label'               => __( 'Duplicate Field Group', 'nexter-extension' ),
			'description'         => __(
				'Copies a Content Fields group — every field, location rule and setting — into a new inactive group.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group'  => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key to copy.',
					),
					'title'  => array(
						'type'        => 'string',
						'description' => 'Title for the copy. Defaults to "<original> (Copy)".',
					),
					'key'    => array(
						'type'        => 'string',
						'description' => 'Group key for the copy. Defaults to "<original key>_copy".',
					),
					'active' => array(
						'type'        => 'boolean',
						'description' => 'Activate the copy immediately. Defaults to false, so it does not render alongside the original.',
						'default'     => false,
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the copy' ),
				),
				'required'             => array( 'group' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'duplicated_from' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_duplicate_field_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'The copy is created INACTIVE on purpose — it shares the original\'s location rules, and two active groups on the same location both render.',
							'Read the "key" in the response rather than assuming the one you asked for: a key already in use is suffixed.',
							'Values are not copied. A duplicated group starts empty on every object.',
						)
					),
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
				),
			),
		)
	);
}
