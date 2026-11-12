<?php
/**
 * Abilities: Content Fields — definition lifecycle shared by post types, taxonomies and field groups (list,
 * get, trash, restore, permanent delete, import, export, bootstrap).
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
		'nexter/list-content-types',
		array(
			'label'               => __( 'List Content Types', 'nexter-extension' ),
			'description'         => __(
				'Lists every Content Fields definition — custom post types, taxonomies and field groups — with its ID, key, kind, status and active flag.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'kind' => array(
						'type'        => 'string',
						'description' => 'Filter by definition kind. Leave empty for all three.',
						'enum'        => array( '', 'cpt', 'taxonomy', 'group_fields' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'total'   => array( 'type' => 'integer' ),
					'items'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_list_content_types',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Start here before any other Content Fields ability — it returns the IDs and keys everything else addresses.',
							'kind values: "cpt" = custom post type, "taxonomy" = custom taxonomy, "group_fields" = field group.',
							'status is the WordPress post status of the definition row (publish or draft); active is the module\'s own on/off flag.',
							'A definition can be published but inactive, in which case its post type or taxonomy is not registered and its content is hidden.',
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
		'nexter/get-content-type',
		array(
			'label'               => __( 'Get Content Type', 'nexter-extension' ),
			'description'         => __(
				'Retrieves one Content Fields definition (post type, taxonomy or field group) by ID, with its full stored configuration.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Definition ID from nexter/list-content-types.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_get_content_type',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Returns the complete definition under "data" — every option the admin UI exposes.',
							'For a post type or taxonomy the response also carries content_count: how many posts or terms are stored under its key.',
							'Read this before an update so you can send back the full configuration; a partial payload falls back to defaults for the keys you omit.',
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
		'nexter/get-content-types-bootstrap',
		array(
			'label'               => __( 'Get Content Types Bootstrap', 'nexter-extension' ),
			'description'         => __(
				'Returns every Content Fields definition in one call, already split into post types/taxonomies and field groups.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_get_content_types_bootstrap',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'One query for the whole module: cpt_tax_items plus field_group_items, each with full configuration.',
							'Cheaper than calling nexter/list-content-types then nexter/get-content-type per row — prefer this when you need to reason about the whole setup.',
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
		'nexter/delete-content-type',
		array(
			'label'               => __( 'Delete Content Type (Trash)', 'nexter-extension' ),
			'description'         => __(
				'Moves a Content Fields definition to the Trash. The stored posts, terms or field values are left untouched and the definition can be restored.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => 'Definition ID to trash.',
						'minimum'     => 1,
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => 'Acknowledge that trashing this definition hides existing content. Required when the first call returns requires_confirmation.',
						'default'     => false,
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the trash' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_delete_content_type',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Reversible: this trashes the definition, it does not delete data. Use nexter/restore-content-type to undo.',
							'If the definition still has content, the first call returns success=false with requires_confirmation=true and a message naming how much content will be hidden.',
							'Report that message to the user and only re-send with confirm=true once they agree.',
							'The posts/terms/field values stay in the database — they just stop being reachable while the definition is gone.',
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
		'nexter/list-content-types-trash',
		array(
			'label'               => __( 'List Trashed Content Types', 'nexter-extension' ),
			'description'         => __(
				'Lists Content Fields definitions currently in the Trash, i.e. what can still be restored.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'total'   => array( 'type' => 'integer' ),
					'items'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_list_content_types_trash',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Use this when a post type, taxonomy or field group "disappeared" — it was probably trashed rather than deleted.',
							'A trashed definition still holds its key, so creating a new definition with the same key will take that key over.',
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
		'nexter/restore-content-type',
		array(
			'label'               => __( 'Restore Content Type', 'nexter-extension' ),
			'description'         => __(
				'Restores a trashed Content Fields definition, bringing its post type, taxonomy or field group back.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Trashed definition ID from nexter/list-content-types-trash.',
						'minimum'     => 1,
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the restore' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_restore_content_type',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Restores the definition and reconnects its existing content.',
							'If a live definition has meanwhile taken over the same key, the restore succeeds but comes back inactive to avoid a key collision — check the returned message.',
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
		'nexter/delete-content-type-permanently',
		array(
			'label'               => __( 'Delete Content Type Permanently', 'nexter-extension' ),
			'description'         => __(
				'Permanently deletes a trashed Content Fields definition. This cannot be undone.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Trashed definition ID to delete for good.',
						'minimum'     => 1,
					),
					'dry_run' => nexter_ext_dry_run_arg( 'the permanent delete' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_delete_content_type_permanently',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => true,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'IRREVERSIBLE. The definition is gone and its key is released.',
							'Always confirm with the user before calling this, and prefer nexter/delete-content-type (trash) unless they explicitly asked to purge.',
							'Any posts, terms or field values that were stored under this definition survive in the database but become orphaned and unreachable.',
							'Export first with nexter/get-content-types-export if there is any chance the definition is wanted back.',
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
		'nexter/import-content-types',
		array(
			'label'               => __( 'Import Content Types', 'nexter-extension' ),
			'description'         => __(
				'Imports post type, taxonomy and field group definitions in bulk, upserting by key: an existing definition with the same key is updated, otherwise a new one is created.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'items'   => array(
						'type'        => 'array',
						'description' => 'Definitions to import (at most 200 per call). Each entry is { kind, data, title? } where kind is cpt, taxonomy or group_fields.',
						'items'       => array( 'type' => 'object' ),
						'maxItems'    => (int) apply_filters( 'nexter_ct_import_max_items', 200 ),
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => 'Report what each item would do, without writing anything.',
						'default'     => false,
					),
				),
				'required'             => array( 'items' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'nexter_mcp_import_content_types',
			'meta'                => array(
				'mode'           => 'write',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => false ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Feed this the exact shape nexter/get-content-types-export returns.',
							'ALWAYS call it once with dry_run=true first, show the user the plan, and only then repeat the call without it.',
							'Upsert is by key, not by ID, so re-importing the same payload updates in place instead of duplicating.',
							'Import auto-confirms destructive changes: unlike nexter/update-cpt it will NOT stop to ask before deactivating a post type or taxonomy that still holds content. The dry run is the only place that warning appears — it flags each such item as deactivates_content.',
							'The response carries created/updated counts plus a per-item error list — read it, because a partial import reports success overall.',
							'This overwrites the configuration of any existing definition sharing a key.',
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
		'nexter/get-content-types-export',
		array(
			'label'               => __( 'Export Content Types', 'nexter-extension' ),
			'description'         => __(
				'Exports all Content Fields definitions as a portable payload that nexter/import-content-types can read back.',
				'nexter-extension'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'kind' => array(
						'type'        => 'string',
						'description' => 'Export only one kind. Leave empty for everything.',
						'enum'        => array( '', 'cpt', 'taxonomy', 'group_fields' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'total'   => array( 'type' => 'integer' ),
					'items'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'nexter_mcp_get_content_types_export',
			'meta'                => array(
				'mode'           => 'read',
				'targets_object' => false,
				'show_in_rest'   => true,
				'mcp'            => array( 'public' => true ),
				'annotations'    => array(
					'instructions' => implode(
						"\n",
						array(
							'Exports definitions (the structure) only — it does NOT export the posts, terms or field values stored under them.',
							'Use it as a backup before a destructive change, or to copy a setup to another site via nexter/import-content-types.',
							'Named get-* rather than export-* so the result is treated as a cacheable read.',
						)
					),
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
				),
			),
		)
	);
}

/**
 * List all definitions, optionally filtered by kind.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_list_content_types( array $input ): array {
	$result = nexter_mcp_ct_rest( 'GET', '/list' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$items = isset( $result['data']['items'] ) && is_array( $result['data']['items'] ) ? $result['data']['items'] : array();
	$kind  = isset( $input['kind'] ) ? (string) $input['kind'] : '';

	$out = array();
	foreach ( $items as $item ) {
		$item_kind = isset( $item['kind'] ) && '' !== $item['kind'] ? (string) $item['kind'] : 'cpt';
		if ( '' !== $kind && $kind !== $item_kind ) {
			continue;
		}
		$meta  = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();
		$out[] = array(
			'id'          => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'title'       => isset( $item['title'] ) ? (string) $item['title'] : '',
			'kind'        => $item_kind,
			// Per-kind key field: a CPT stores post_type_key, a taxonomy taxonomy_key, only a
			// field group uses key.
			'key'         => nexter_mcp_ct_key_of( $meta, $item_kind ),
			'status'      => isset( $item['status'] ) ? (string) $item['status'] : '',
			'active'      => nexter_mcp_ct_is_active( $meta ),
			'last_update' => isset( $item['last_update'] ) ? (string) $item['last_update'] : '',
		);
	}

	return array(
		'success' => true,
		'total'   => count( $out ),
		'items'   => $out,
	);
}

/**
 * Get a single definition with its full configuration.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_get_content_type( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$result = nexter_mcp_ct_rest( 'GET', '/definitions/' . $id );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array(
		'success'    => true,
		'definition' => $result['data'],
	);
}

/**
 * Get every definition in one call, split by kind.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function nexter_mcp_get_content_types_bootstrap( array $input ): array {
	unset( $input );

	$result = nexter_mcp_ct_rest( 'GET', '/bootstrap' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$data = is_array( $result['data'] ) ? $result['data'] : array();

	return array(
		'success'           => true,
		'cpt_tax_items'     => isset( $data['cpt_tax_items'] ) ? $data['cpt_tax_items'] : array(),
		'field_group_items' => isset( $data['field_group_items'] ) ? $data['field_group_items'] : array(),
	);
}

/**
 * Trash a definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_delete_content_type( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$found = nexter_mcp_ct_module_active() ? Nxt_Content_Types_Model_Writer::lookup( $id ) : null;

	$result = nexter_mcp_ct_rest(
		'DELETE',
		'/definitions/' . $id,
		array(
			'confirm' => ! empty( $input['confirm'] ),
			'dry_run' => ! empty( $input['dry_run'] ),
		)
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$out = array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );

	return empty( $input['dry_run'] ) ? nexter_mcp_ct_with_moved_id( $out, $found, true ) : $out;
}

/**
 * List trashed definitions.
 *
 * @param array $input Ability input (unused).
 * @return array
 */
function nexter_mcp_list_content_types_trash( array $input ): array {
	unset( $input );

	$result = nexter_mcp_ct_rest( 'GET', '/trash' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$items = isset( $result['data']['items'] ) && is_array( $result['data']['items'] ) ? $result['data']['items'] : array();

	return array(
		'success' => true,
		'total'   => count( $items ),
		'items'   => $items,
	);
}

/**
 * Restore a trashed definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_restore_content_type( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive definition id is required.' );
	}

	$found = nexter_mcp_ct_module_active() ? Nxt_Content_Types_Model_Writer::lookup( $id ) : null;

	$result = nexter_mcp_ct_rest(
		'POST',
		'/definitions/' . $id . '/restore',
		array( 'dry_run' => ! empty( $input['dry_run'] ) )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$out = array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );

	return empty( $input['dry_run'] ) ? nexter_mcp_ct_with_moved_id( $out, $found, false ) : $out;
}

/**
 * Permanently delete a trashed definition.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_delete_content_type_permanently( array $input ): array {
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	if ( $id <= 0 ) {
		return nexter_mcp_ct_fail( 'A positive definition id is required.' );
	}

	// Only the Trash can be purged. The route deletes a live definition too, which would skip the
	// confirmation that trashing demands when the type still holds content.
	$found = nexter_mcp_ct_module_active() ? Nxt_Content_Types_Model_Writer::lookup( $id ) : null;
	if ( is_array( $found ) && empty( $found['trashed'] ) ) {
		return nexter_mcp_ct_fail(
			sprintf( 'Definition %d is live, not in the Trash. Trash it first with nexter/delete-content-type, then purge the trash_id that call returns.', $id ),
			array( 'code' => 'nexter_not_trashed' )
		);
	}

	$result = nexter_mcp_ct_rest(
		'DELETE',
		'/definitions/' . $id . '/permanent',
		array( 'dry_run' => ! empty( $input['dry_run'] ) )
	);
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	return array_merge( array( 'success' => true ), is_array( $result['data'] ) ? $result['data'] : array() );
}

/**
 * Why the import would refuse an item, or '' when it would not.
 *
 * @param string $kind cpt|taxonomy|group_fields.
 * @param string $key  Item key as sent.
 * @param array  $data Item data.
 * @return string
 */
function nexter_mcp_ct_import_item_problem( string $kind, string $key, array $data ): string {
	// Group configs import as stored, locked Pro types included.
	if ( 'group_fields' === $kind ) {
		return '';
	}
	if ( empty( $data['label'] ) ) {
		return 'Missing label.';
	}
	$clean = sanitize_key( $key );
	$max   = ( 'cpt' === $kind ) ? 20 : 32;
	if ( '' === $clean || strlen( $clean ) > $max ) {
		return sprintf( 'Key "%1$s" must be 1-%2$d lowercase letters, numbers or underscores.', $key, $max );
	}
	if ( '' !== Nxt_Content_Types_Admin_Logic::reserved_key_reason( $clean ) ) {
		return sprintf( 'Key "%s" is reserved by WordPress.', $clean );
	}
	return '';
}

/**
 * Bulk import definitions (upsert by key).
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_import_content_types( array $input ): array {
	$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();
	if ( empty( $items ) ) {
		return nexter_mcp_ct_fail( 'Nothing to import: "items" must be a non-empty array of { kind, data } entries.' );
	}

	if ( ! empty( $input['dry_run'] ) ) {
		return nexter_mcp_plan_content_types_import( $items );
	}

	$result = nexter_mcp_ct_rest( 'POST', '/import', array( 'items' => $items ) );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$data = is_array( $result['data'] ) ? $result['data'] : array();
	// Success means something landed; partial flags a batch where some items failed.
	$done   = ( isset( $data['created'] ) ? (int) $data['created'] : 0 ) + ( isset( $data['updated'] ) ? (int) $data['updated'] : 0 );
	$failed = isset( $data['failed'] ) ? (int) $data['failed'] : 0;
	if ( $failed > 0 && 0 === $done ) {
		return array_merge( nexter_mcp_ct_fail( 'No item was imported; see results for each reason.', array( 'code' => 'nexter_import_failed' ) ), $data );
	}

	return array_merge( array(
			'success' => true,
			'partial' => $failed > 0,
		), $data );
}

/**
 * Report what an import payload would do, without writing.
 *
 * @param array $items Import payload.
 * @return array
 */
function nexter_mcp_plan_content_types_import( array $items ): array {
	$plan     = array();
	$warnings = 0;

	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			$plan[] = array(
				'index'  => (int) $index,
				'action' => 'skip',
				'reason' => 'Not an object.',
			);
			continue;
		}

		// Mirrors the kind coercion in Admin_Logic::import_definitions() so the plan reports the
		// kind that would actually be used, not the one the payload claims.
		$raw_kind = isset( $item['kind'] ) ? (string) $item['kind'] : 'cpt';
		$kind     = ( 'taxonomy' === $raw_kind ) ? 'taxonomy' : ( ( 'group_fields' === $raw_kind ) ? 'group_fields' : 'cpt' );
		$data     = ( isset( $item['data'] ) && is_array( $item['data'] ) ) ? $item['data'] : array();

		$key_field = nexter_mcp_ct_key_field( $kind );
		$key       = nexter_mcp_ct_key_of( $data, $kind );

		$existing = $key ? (int) Nxt_Content_Types_Admin_Logic::find_definition_id_by_key( $kind, $key ) : 0;

		$row = array(
			'index'   => (int) $index,
			'kind'    => $kind,
			'key'     => $key,
			'action'  => $existing ? 'update' : 'create',
			'targets' => $existing ? $existing : null,
		);

		if ( '' === $key ) {
			$row['action'] = 'fail';
			$row['reason'] = sprintf( 'Missing %s.', $key_field );
			$plan[]        = $row;
			continue;
		}

		// The same refusals the real import makes, so the plan does not promise a write that fails.
		$problem = nexter_mcp_ct_import_item_problem( $kind, $key, $data );
		if ( '' !== $problem ) {
			$row['action'] = 'fail';
			$row['reason'] = $problem;
			$plan[]        = $row;
			continue;
		}

		// The destructive case worth surfacing: an update that flips an active definition off while
		// content still exists under its key.
		if ( $existing && 'group_fields' !== $kind ) {
			$stored = Nxt_Content_Types_Model_Writer::definition( $existing );
			$stored = is_array( $stored ) ? $stored : array();

			$was_active = nexter_mcp_ct_is_active( $stored );
			$now_active = nexter_mcp_ct_is_active( $data );

			if ( $was_active && ! $now_active ) {
				$count = (int) Nxt_Content_Types_Admin_Logic::content_count_for_key( $kind, $key );
				if ( $count > 0 ) {
					$row['deactivates_content'] = $count;
					$row['reason']              = sprintf(
						'Deactivates "%1$s", hiding %2$d existing %3$s. Import will NOT ask before doing this.',
						$key,
						$count,
						( 'taxonomy' === $kind ) ? 'term(s)' : 'post(s)'
					);
					++$warnings;
				}
			}
		}

		$plan[] = $row;
	}

	return array(
		'success'  => true,
		'dry_run'  => true,
		'total'    => count( $plan ),
		'warnings' => $warnings,
		'plan'     => $plan,
		'message'  => $warnings > 0
			? sprintf( 'Nothing was written. %d item(s) would hide existing content — show the user before re-running without dry_run.', $warnings )
			: 'Nothing was written. No item would hide existing content.',
	);
}

/**
 * Export definitions in the shape nexter/import-content-types accepts.
 *
 * @param array $input Ability input.
 * @return array
 */
function nexter_mcp_get_content_types_export( array $input ): array {
	$result = nexter_mcp_ct_rest( 'GET', '/list' );
	if ( empty( $result['success'] ) ) {
		return $result;
	}

	$rows = isset( $result['data']['items'] ) && is_array( $result['data']['items'] ) ? $result['data']['items'] : array();
	$kind = isset( $input['kind'] ) ? (string) $input['kind'] : '';

	$items = array();
	foreach ( $rows as $row ) {
		$row_kind = isset( $row['kind'] ) && '' !== $row['kind'] ? (string) $row['kind'] : 'cpt';
		if ( '' !== $kind && $kind !== $row_kind ) {
			continue;
		}
		$items[] = array(
			'kind'  => $row_kind,
			'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
			'data'  => isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array(),
		);
	}

	return array(
		'success' => true,
		'total'   => count( $items ),
		'items'   => $items,
		'note'    => 'Definitions only — posts, terms and stored field values are not included.',
	);
}
