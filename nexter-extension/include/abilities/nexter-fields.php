<?php
/**
 * Abilities: Content Fields — the individual fields inside a group, plus the discovery reads an agent needs
 * before it can write a valid field or location rule.
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
		'nexter/list-field-types',
		array(
			'label'               => __( 'List Field Types', 'nexter-extension' ),
			'description'         => __(
				'Lists every field type Content Fields supports, grouped as the editor presents them, with the types that are no longer offered for new fields.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_list_field_types',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Call this before creating a field: an unrecognised type is stored as-is but renders nothing, so the field silently never appears.',
							'choice_types need a "choices" value: one "value : Label" pair per line.',
							'The repeater type nests its subfields under a "fields" array on the field itself, not "sub_fields".',
							'deprecated_types must never be used for a new field — they exist only so already-saved data keeps rendering.',
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
		'nexter/get-location-choices',
		array(
			'label'               => __( 'Get Location Rule Choices', 'nexter-extension' ),
			'description'         => __(
				'Returns the valid values for one field group location rule parameter — the post types, templates, roles, taxonomies or menus that can be selected.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'param' => array(
						'type'        => 'string',
						'description' => 'Location rule parameter to enumerate.',
						'enum'        => array(
							'post_type',
							'post',
							'page',
							'post_template',
							'page_template',
							'post_status',
							'post_format',
							'post_category',
							'post_taxonomy',
							'taxonomy',
							'page_type',
							'page_parent',
							'user_role',
							'current_user_role',
							'current_user',
							'user_form',
							'attachment',
							'comment',
							'widget',
							'nav_menu',
							'nav_menu_item',
						),
					),
				),
				'required'             => array( 'param' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_get_location_choices',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Always call this before writing a location rule — a rule whose value does not exist matches nothing, so the field group appears nowhere and there is no error to see.',
							'A location rule is { "param": <one of the enum values>, "operator": "==" or "!=", "value": <a value from this list> }.',
							'Rules in one array are ANDed; nesting arrays ORs the groups.',
							'A large site can exceed the choice limit — check the truncated flag before assuming the list is complete.',
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
		'nexter/get-post-object-terms',
		array(
			'label'               => __( 'Get Post Object and Term Choices', 'nexter-extension' ),
			'description'         => __(
				'Returns the post types and taxonomies available for configuring post_object, page_link, relationship and taxonomy fields.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_get_post_object_terms',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Use this to fill the post_type / taxonomy filters on a relational field, so the field only offers valid targets.',
							'Relevant to the field types post_object, page_link, relationship and taxonomy.',
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
		'nexter/add-field-to-group',
		array(
			'label'               => __( 'Add Field to Group', 'nexter-extension' ),
			'description'         => __(
				'Adds one field definition to an existing Content Fields group, leaving the other fields and their saved values untouched.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group'    => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field'    => array(
						'type'        => 'object',
						'description' => 'Field definition. Requires name and label; type defaults to text.',
					),
					'position' => array(
						'type'        => 'integer',
						'description' => 'Zero-based index to insert at. Omit to append to the end.',
						'minimum'     => 0,
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_add_field_to_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Prefer this over nexter/update-field-group when adding a field — that ability replaces the whole field list, so a mistake there drops every other field.',
							'Minimum viable field: { "name": "isbn", "label": "ISBN", "type": "text" }.',
							'name becomes part of the stored meta key, so it must be lowercase letters/numbers/underscores and unique within the group. The call is refused if the name is already taken.',
							'Useful per-field options: instructions, required, default_value, placeholder, choices (for select/checkbox/radio), conditional_logic, wrapper width.',
							'For a repeater, put its subfield definitions in a "fields" array on the field itself.',
							'Call nexter/list-field-types first if you are unsure of the type slug.',
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
		'nexter/update-field-in-group',
		array(
			'label'               => __( 'Update Field in Group', 'nexter-extension' ),
			'description'         => __(
				'Changes one field definition inside a Content Fields group without rewriting the rest of the field list.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field' => array(
						'type'        => 'string',
						'description' => 'Name (or key) of the field to change.',
					),
					'data'  => array(
						'type'        => 'object',
						'description' => 'Field properties to change. Merged over the existing definition.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field', 'data' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_update_field_in_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Merges: send only the properties you want to change, e.g. { "data": { "label": "ISBN-13", "required": true } }.',
							'Renaming a field via data.name is refused once values have been saved against the old name — the stored meta key is built from it, so a rename would orphan the data. Relay the error instead of forcing it.',
							'Changing type on a field that already holds values can make those values unreadable to the new type. Warn the user first.',
							'To reorder fields, use nexter/update-field-group with a full fields array.',
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
		'nexter/delete-field-from-group',
		array(
			'label'               => __( 'Delete Field from Group', 'nexter-extension' ),
			'description'         => __(
				'Removes one field definition from a Content Fields group. Values already saved for that field are left in the database as orphaned meta.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'field' => array(
						'type'        => 'string',
						'description' => 'Name (or key) of the field to remove.',
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'field' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_delete_field_from_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'The field stops rendering and nxt_field_value() stops returning it immediately.',
							'Values already saved are NOT deleted — they stay as orphaned post/term/user meta. Re-adding a field with the same name in the same group brings them back.',
							'Because the removal itself is easy to undo but the confusion is not, confirm with the user before deleting a field that holds data.',
							'Removing a repeater removes its subfields with it.',
						)
					),
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
				),
			),
		)
	);
}

/**
 * List supported field types.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function nexter_mcp_list_field_types( array $input ): array {
	unset( $input );

	$catalog = nexter_mcp_ct_field_type_catalog();

	return array(
		'success'          => true,
		'total'            => count( nexter_mcp_ct_creatable_field_types() ),
		'groups'           => $catalog,
		'types'            => nexter_mcp_ct_creatable_field_types(),
		'choice_types'     => nexter_mcp_ct_choice_field_types(),
		'deprecated_types' => nexter_mcp_ct_legacy_field_types(),
		'notes'            => array(
			'choices'  => 'select, checkbox and radio read a newline-separated "value : Label" list from the field\'s choices property.',
			'repeater' => 'A repeater holds its subfield definitions in a "fields" array on the field itself.',
			// Stated because a wrong key is stored and ignored: the field then offers Posts only.
			'relational' => 'Settings are prefixed with the field type: post_object_post_types, relationship_post_types and page_link_post_types are arrays of post type keys (default ["post"]); a taxonomy field names its taxonomy in taxonomy_name; a user field filters with user_role_filter. A value outside those limits is sanitised to empty.',
		),
	);
}

/**
 * Location rule choices for one parameter.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_get_location_choices( array $input ): array {
	$param = isset( $input['param'] ) ? sanitize_key( (string) $input['param'] ) : '';
	if ( '' === $param ) {
		return nexter_mcp_ct_fail( 'param is required, e.g. "post_type".' );
	}

	$result = nexter_mcp_ct_rest( 'GET', '/location-choices', array( 'param' => $param ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge(
		array(
			'success' => true,
			'param'   => $param,
		),
		is_array( $result['data'] ) ? $result['data'] : array()
	);
}

/**
 * Post type / taxonomy choices for relational fields.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function nexter_mcp_get_post_object_terms( array $input ): array {
	unset( $input );

	$result = nexter_mcp_ct_rest( 'GET', '/post-object-terms' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
}

/**
 * Resolve a group plus its current field list, or a failure shape.
 *
 * @param mixed $group Group ID or key.
 * @return array ['definition' => array, 'fields' => array] on success, or a failure shape.
 */
function nexter_mcp_ct_group_fields( $group ): array {
	$definition = nexter_mcp_ct_locate_group( $group );
	if ( ! $definition ) {
		return nexter_mcp_ct_group_not_found( is_scalar( $group ) ? (string) $group : '' );
	}

	$fields = isset( $definition['meta']['fields'] ) && is_array( $definition['meta']['fields'] )
		? $definition['meta']['fields']
		: array();

	return array(
		'success'    => true,
		'definition' => $definition,
		'fields'     => $fields,
	);
}

/**
 * Index of a field within a field list, matched on name then key.
 *
 * @param array  $fields   Field definitions.
 * @param string $selector Field name or key.
 * @return int|null
 */
function nexter_mcp_ct_field_index( array $fields, string $selector ): ?int {
	foreach ( $fields as $index => $field ) {
		if ( is_array( $field ) && isset( $field['name'] ) && $selector === (string) $field['name'] ) {
			return (int) $index;
		}
	}
	foreach ( $fields as $index => $field ) {
		if ( is_array( $field ) && isset( $field['key'] ) && $selector === (string) $field['key'] ) {
			return (int) $index;
		}
	}
	return null;
}

/**
 * Add one field to a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_add_field_to_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = nexter_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$field = isset( $input['field'] ) && is_array( $input['field'] ) ? $input['field'] : array();
	if ( empty( $field['name'] ) ) {
		return nexter_mcp_ct_fail( 'field.name is required, e.g. { "name": "isbn", "label": "ISBN", "type": "text" }.' );
	}

	$name = sanitize_key( (string) $field['name'] );
	if ( '' === $name ) {
		return nexter_mcp_ct_fail( 'field.name must contain lowercase letters, numbers or underscores.' );
	}
	// Refuse rather than store the sanitised form: the caller would go on to address a name that does not exist.
	if ( $name !== (string) $field['name'] ) {
		return nexter_mcp_ct_fail(
			sprintf( 'field.name "%s" is not valid: use lowercase letters, numbers, underscores and hyphens only. The closest valid name is "%s".', sanitize_text_field( (string) $field['name'] ), $name )
		);
	}
	$field['name'] = $name;

	if ( empty( $field['type'] ) ) {
		$field['type'] = 'text';
	}
	if ( empty( $field['label'] ) ) {
		$field['label'] = ucwords( str_replace( '_', ' ', $name ) );
	}

	$invalid = nexter_mcp_ct_invalid_field_types( array( $field ) );
	if ( ! empty( $invalid ) ) {
		return nexter_mcp_ct_field_types_fail( $invalid );
	}

	$problems = nexter_mcp_ct_field_problems( array( $field ) );
	if ( ! empty( $problems ) ) {
		return nexter_mcp_ct_fail( 'The field was not added: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$fields = $resolved['fields'];

	// Refuse a duplicate rather than letting finalize_group_meta() auto-rename it: a silent rename returns a field
	// whose name is not the one the caller asked for.
	if ( null !== nexter_mcp_ct_field_index( $fields, $name ) ) {
		return nexter_mcp_ct_fail(
			sprintf( 'The field group already has a field named "%s". Use nexter/update-field-in-group to change it.', $name )
		);
	}

	$position = ( isset( $input['position'] ) && is_numeric( $input['position'] ) )
		? max( 0, min( count( $fields ), (int) $input['position'] ) )
		: count( $fields );

	array_splice( $fields, $position, 0, array( $field ) );

	$result = nexter_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'field'    => $name,
		'position' => $position,
		'message'  => sprintf( 'Field "%s" added.', $name ),
	);
}

/**
 * Update one field inside a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_update_field_in_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = nexter_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$selector = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $selector ) {
		return nexter_mcp_ct_fail( 'field is required: the name (or key) of the field to change.' );
	}

	$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
	if ( empty( $data ) ) {
		return nexter_mcp_ct_fail( 'data is required and must contain at least one property to change.' );
	}

	$fields = $resolved['fields'];
	$index  = nexter_mcp_ct_field_index( $fields, $selector );
	if ( null === $index ) {
		$existing = implode( ', ', nexter_mcp_ct_field_names( $fields ) );
		return nexter_mcp_ct_fail(
			sprintf(
				'No field named "%s" in this group. Existing fields: %s.',
				$selector,
				'' !== $existing ? $existing : 'none'
			)
		);
	}

	if ( isset( $data['name'] ) ) {
		$data['name'] = sanitize_key( (string) $data['name'] );
		if ( '' === $data['name'] ) {
			return nexter_mcp_ct_fail( 'data.name must contain lowercase letters, numbers or underscores.' );
		}
		$clash = nexter_mcp_ct_field_index( $fields, $data['name'] );
		if ( null !== $clash && $clash !== $index ) {
			return nexter_mcp_ct_fail( sprintf( 'Another field in this group is already named "%s".', $data['name'] ) );
		}
	}

	$merged = array_merge( $fields[ $index ], $data );

	$invalid = nexter_mcp_ct_invalid_field_types( array( $merged ) );
	if ( ! empty( $invalid ) ) {
		return nexter_mcp_ct_field_types_fail( $invalid );
	}

	// A stored field keeps its name and type unchallenged, so a change INTO a withdrawn type is checked here.
	$legacy = array_keys( nexter_mcp_ct_legacy_field_types() );
	if ( isset( $data['type'] ) && in_array( (string) $data['type'], $legacy, true ) && (string) ( $fields[ $index ]['type'] ?? '' ) !== (string) $data['type'] ) {
		return nexter_mcp_ct_fail( sprintf( 'The type "%s" is withdrawn and cannot be given to a field.', (string) $data['type'] ) );
	}

	$problems = nexter_mcp_ct_field_problems( array( $merged ), $fields );
	if ( ! empty( $problems ) ) {
		return nexter_mcp_ct_fail( 'The field was not changed: ' . implode( ' ', $problems ), array( 'problems' => $problems ) );
	}

	$fields[ $index ] = $merged;

	// Goes through the group PUT so the rename lock runs: renaming a field that already holds
	// saved values is refused there rather than silently orphaning the stored meta.
	$result = nexter_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'field'    => isset( $merged['name'] ) ? (string) $merged['name'] : $selector,
		'changed'  => array_keys( $data ),
	);
}

/**
 * Remove one field from a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_delete_field_from_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = nexter_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$selector = isset( $input['field'] ) ? trim( (string) $input['field'] ) : '';
	if ( '' === $selector ) {
		return nexter_mcp_ct_fail( 'field is required: the name (or key) of the field to remove.' );
	}

	$fields = $resolved['fields'];
	$index  = nexter_mcp_ct_field_index( $fields, $selector );
	if ( null === $index ) {
		$existing = implode( ', ', nexter_mcp_ct_field_names( $fields ) );
		return nexter_mcp_ct_fail(
			sprintf(
				'No field named "%s" in this group. Existing fields: %s.',
				$selector,
				'' !== $existing ? $existing : 'none'
			)
		);
	}

	$removed = isset( $fields[ $index ]['name'] ) ? (string) $fields[ $index ]['name'] : $selector;

	array_splice( $fields, $index, 1 );

	$result = nexter_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $fields ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'   => true,
		'group_id'  => $resolved['definition']['id'],
		'field'     => $removed,
		'remaining' => nexter_mcp_ct_field_names( $fields ),
		'message'   => sprintf(
			'Field "%s" removed from the group. Any values already saved for it remain in the database as orphaned meta.',
			$removed
		),
	);
}

/**
 * Reorder the fields in a group.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_reorder_fields_in_group( array $input ): array {
	$dry_run = ! empty( $input['dry_run'] );
	$resolved = nexter_mcp_ct_group_fields( isset( $input['group'] ) ? $input['group'] : '' );
	if ( empty( $resolved['success'] ) ) {
		return $resolved;
	}

	$fields = $resolved['fields'];
	if ( count( $fields ) < 2 ) {
		return nexter_mcp_ct_fail( 'This group has fewer than two fields, so there is nothing to reorder.' );
	}

	$order = isset( $input['order'] ) && is_array( $input['order'] ) ? array_values( $input['order'] ) : array();
	if ( empty( $order ) ) {
		return nexter_mcp_ct_fail(
			sprintf(
				'order is required: every field name in the new order. This group has: %s.',
				implode( ', ', nexter_mcp_ct_field_names( $fields ) )
			)
		);
	}

	// A partial order is refused rather than "the rest, appended".
	$indexes = array();
	$unknown = array();
	foreach ( $order as $selector ) {
		$index = nexter_mcp_ct_field_index( $fields, trim( (string) $selector ) );
		if ( null === $index ) {
			$unknown[] = (string) $selector;
			continue;
		}
		$indexes[] = $index;
	}
	if ( ! empty( $unknown ) ) {
		return nexter_mcp_ct_fail(
			sprintf(
				'Unknown field(s) in order: %s. This group has: %s.',
				implode( ', ', $unknown ),
				implode( ', ', nexter_mcp_ct_field_names( $fields ) )
			)
		);
	}

	$unique = array_unique( $indexes );
	if ( count( $unique ) !== count( $indexes ) ) {
		return nexter_mcp_ct_fail( 'order lists the same field more than once.' );
	}
	if ( count( $unique ) !== count( $fields ) ) {
		return nexter_mcp_ct_fail(
			sprintf(
				'order must list every field in the group. Expected %d, got %d — missing: %s.',
				count( $fields ),
				count( $unique ),
				implode( ', ', array_values( array_diff( nexter_mcp_ct_field_names( $fields ), array_map( 'strval', $order ) ) ) )
			)
		);
	}

	$reordered = array();
	foreach ( $indexes as $index ) {
		$reordered[] = $fields[ $index ];
	}

	// Through the group PUT like every other field write, so the rename lock and the group's own
	// sanitisation run. Only the order changed, so no field can be renamed by this call.
	$result = nexter_mcp_ct_rest(
		'PUT',
		'/field-groups/' . $resolved['definition']['id'],
		array( 'data' => array( 'fields' => $reordered ), 'dry_run' => $dry_run )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	// The group's own dry run already reported what it checked; adding our own summary on top would
	// describe a write that did not happen.
	if ( $dry_run ) {
		return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
	}

	return array(
		'success'  => true,
		'group_id' => $resolved['definition']['id'],
		'order'    => nexter_mcp_ct_field_names( $reordered ),
	);
}

if ( nexter_mcp_ct_module_active() ) {

	nexter_ext_register_ability(
		'nexter/reorder-fields-in-group',
		array(
			'label'               => __( 'Reorder Fields In Group', 'nexter-extension' ),
			'description'         => __(
				'Changes the order of the fields in a Content Fields group without touching the fields themselves or any stored value.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'group' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Field group ID or group key.',
					),
					'order' => array(
						'type'        => 'array',
						'description' => 'Every field name in the group, in the order wanted. Must list all of them.',
						'items'       => array( 'type' => 'string' ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Run every check the group save performs and report the result, storing nothing.',
						'default'     => false,
					),
				),
				'required'             => array( 'group', 'order' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'group_id' => array( 'type' => 'integer' ),
					'order'    => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_reorder_fields_in_group',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Call nexter/get-field-group first to read the current field names — order must list every one of them.',
							'A partial order is refused, not appended: the caller must decide where every field goes.',
							'This changes display order only. No field is renamed and no stored value moves.',
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
