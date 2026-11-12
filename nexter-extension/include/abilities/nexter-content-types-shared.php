<?php
/**
 * Shared helpers for the Content Fields (Content Types) abilities.
 *
 * @package Nexter_Extension
 * @since   4.7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * REST namespace the content-types module registers its routes under.
 */
if ( ! defined( 'NEXTER_MCP_CT_NS' ) ) {
	define( 'NEXTER_MCP_CT_NS', '/content-types/v1' );
}

// The lifecycle read cache holds every nexter/get-* and nexter/list-* result for 15 minutes and is only
// invalidated when an ABILITY performs a write.
foreach ( array( 'nxt/definition_saved', 'nxt/definition_changed' ) as $nexter_ct_cache_hook ) {
	add_action(
		$nexter_ct_cache_hook,
		static function () {
			if ( ! function_exists( 'nexter_ext_ability_cache_version' ) ) {
				return;
			}
			nexter_ext_ability_cache_version( true );
		}
	);
}
unset( $nexter_ct_cache_hook );

/**
 * Whether the Fields / Content Types module is present in this build.
 *
 * @return bool
 */
function nexter_mcp_ct_module_active(): bool {
	return class_exists( 'Nxt_Content_Types_Admin_Logic' ) && class_exists( 'Nxt_Content_Types_CPT' );
}

/**
 * Uniform failure shape for this ability group.
 *
 * @param string $error Human-readable reason.
 * @param array  $extra Additional keys to merge into the response.
 * @return array
 */
function nexter_mcp_ct_fail( string $error, array $extra = array() ): array {
	// Every failure carries code + status so callers can branch without parsing the message.
	return array_merge(
		array(
			'success' => false,
			'error'   => $error,
			'code'    => 'nexter_invalid_input',
			'status'  => 400,
		),
		$extra
	);
}

/**
 * Refuse keys the definition does not know, since the sanitizer drops them silently.
 *
 * @param array  $data Definition payload.
 * @param string $kind cpt or taxonomy.
 * @return array|null Failure response, or null when every key is known.
 */
function nexter_mcp_ct_unknown_keys( array $data, string $kind ) {
	if ( ! class_exists( 'Nxt_Content_Types_Fields' ) ) {
		return null;
	}
	$known   = array_keys( 'cpt' === $kind ? Nxt_Content_Types_Fields::get_cpt_fields() : Nxt_Content_Types_Fields::get_taxonomy_fields() );
	$unknown = array_diff( array_keys( $data ), $known, array( 'id' ) );
	if ( empty( $unknown ) ) {
		return null;
	}
	return nexter_mcp_ct_fail( sprintf( 'Unknown key(s) in data: %s. Allowed: %s.', implode( ', ', $unknown ), implode( ', ', array_diff( $known, array( 'kind' ) ) ) ) );
}

/**
 * Dispatch an internal request against the content-types REST namespace.
 *
 * @param string $method HTTP verb (GET, POST, PUT, DELETE).
 * @param string $route  Route relative to the namespace, e.g. '/field-groups/12'.
 * @param array  $params Request parameters.
 * @return array ['success' => true, 'data' => mixed] or a failure shape carrying code/status.
 */
function nexter_mcp_ct_rest( string $method, string $route, array $params = array() ): array {
	if ( ! nexter_mcp_ct_module_active() ) {
		return nexter_mcp_ct_fail( 'The Content Fields (Content Types) module is not available on this site.' );
	}

	$request = new WP_REST_Request( $method, NEXTER_MCP_CT_NS . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		$error = $response->as_error();
		$data  = $error->get_error_data();
		$extra = array(
			'code'   => $error->get_error_code(),
			'status' => ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 500,
		);

		// Surface the module's own confirmation gate so the agent knows the write is recoverable
		// by re-sending with confirm => true, instead of reporting a hard failure.
		if ( is_array( $data ) && ! empty( $data['requires_confirmation'] ) ) {
			$extra['requires_confirmation'] = true;
		}

		return nexter_mcp_ct_fail( (string) $error->get_error_message(), $extra );
	}

	return array(
		'success' => true,
		'data'    => $response->get_data(),
	);
}

/**
 * Load one definition post (CPT, taxonomy or field group) with its stored meta.
 *
 * @param int $id Definition post ID.
 * @return array|null ['id' => int, 'post' => WP_Post, 'meta' => array, 'kind' => string] or null.
 */
function nexter_mcp_ct_definition( int $id ): ?array {
	if ( ! nexter_mcp_ct_module_active() || $id <= 0 ) {
		return null;
	}

	// Resolved through the model: there is no definition post to fetch, and get_post() on a derived id is
	// always null.
	$meta = Nxt_Content_Types_Model_Writer::definition( $id );
	if ( ! is_array( $meta ) ) {
		return null;
	}

	return array(
		'id'   => $id,
		'post' => null,
		'meta' => $meta,
		// Matches Nxt_Content_Types_Definitions_Repository: a blank kind means the oldest rows,
		// which were all post types before taxonomies and field groups existed.
		'kind' => ( isset( $meta['kind'] ) && '' !== $meta['kind'] ) ? (string) $meta['kind'] : 'cpt',
	);
}

/**
 * Add the key a save actually stored to that save's response.
 *
 * @param array $response Ability response to annotate.
 * @param int    $id        Definition id the save returned.
 * @param string $requested Key the caller sent, if any.
 * @return array
 */
function nexter_mcp_ct_with_stored_key( array $response, int $id, string $requested = '' ): array {
	$definition = nexter_mcp_ct_definition( $id );
	if ( ! $definition ) {
		return $response;
	}

	$meta = isset( $definition['meta'] ) && is_array( $definition['meta'] ) ? $definition['meta'] : array();
	foreach ( array( 'post_type_key', 'taxonomy_key', 'key' ) as $field ) {
		if ( ! empty( $meta[ $field ] ) ) {
			$response['key'] = (string) $meta[ $field ];
			// Tell the caller when the key was normalised (lowercased, trimmed or suffixed).
			if ( '' !== $requested && $requested !== $response['key'] ) {
				$response['notice'] = sprintf( 'Key "%1$s" was stored as "%2$s". Use the stored key from now on.', $requested, $response['key'] );
			}
			break;
		}
	}

	return $response;
}

/**
 * Add the id a definition answers to after a trash or restore, and the undo that addresses it.
 *
 * Trashing changes a definition's id, so the id that was sent is dead the moment the move lands.
 *
 * @param array      $response Ability response to annotate.
 * @param array|null $found    Nxt_Content_Types_Model_Writer::lookup() result taken BEFORE the move.
 * @param bool       $trashed  True after a trash, false after a restore.
 * @return array
 */
function nexter_mcp_ct_with_moved_id( array $response, $found, bool $trashed ): array {
	if ( ! is_array( $found ) || empty( $found['kind'] ) || ! isset( $found['key'] ) ) {
		return $response;
	}

	$moved = Nxt_Content_Types_Model_Writer::id_of( (string) $found['kind'], (string) $found['key'], $trashed );
	if ( $moved <= 0 ) {
		return $response;
	}

	$response[ $trashed ? 'trash_id' : 'restored_id' ] = $moved;
	$response['undo']                                  = array(
		'strategy' => 'call',
		'ability'  => $trashed ? 'nexter/restore-content-type' : 'nexter/delete-content-type',
		'input'    => array( 'id' => $moved ),
	);

	return $response;
}

/**
 * Resolve a field group by post ID or by its group key.
 *
 * @param mixed $group Post ID (int/numeric string) or group key.
 * @return array|null Definition array from nexter_mcp_ct_definition(), or null.
 */
function nexter_mcp_ct_locate_group( $group ): ?array {
	if ( is_int( $group ) || ( is_string( $group ) && ctype_digit( $group ) ) ) {
		$found = nexter_mcp_ct_definition( (int) $group );
		return ( $found && 'group_fields' === $found['kind'] ) ? $found : null;
	}

	$key = is_string( $group ) ? sanitize_key( $group ) : '';
	if ( '' === $key || ! nexter_mcp_ct_module_active() ) {
		return null;
	}

	foreach ( Nxt_Content_Types_Definitions_Repository::get_instance()->get_rows() as $row ) {
		if ( 'group_fields' !== $row['kind'] ) {
			continue;
		}
		if ( isset( $row['meta']['key'] ) && $key === $row['meta']['key'] ) {
			return nexter_mcp_ct_definition( (int) $row['id'] );
		}
	}

	return null;
}

/**
 * The meta field that holds a definition's key, which differs per kind.
 *
 * @param string $kind cpt|taxonomy|group_fields.
 * @return string Meta field name.
 */
function nexter_mcp_ct_key_field( string $kind ): string {
	if ( 'cpt' === $kind ) {
		return 'post_type_key';
	}
	if ( 'taxonomy' === $kind ) {
		return 'taxonomy_key';
	}
	return 'key';
}

/**
 * A definition's key, read from whichever meta field its kind uses.
 *
 * @param array  $meta Definition meta.
 * @param string $kind cpt|taxonomy|group_fields.
 * @return string
 */
function nexter_mcp_ct_key_of( array $meta, string $kind ): string {
	$field = nexter_mcp_ct_key_field( $kind );
	return isset( $meta[ $field ] ) ? (string) $meta[ $field ] : '';
}

/**
 * The `active` flag as the module reads it: absent means active.
 *
 * @param array $meta Definition meta.
 * @return bool
 */
function nexter_mcp_ct_is_active( array $meta ): bool {
	return ! isset( $meta['active'] ) || (bool) $meta['active'];
}

/**
 * Field types an editor can pick for a NEW field, grouped the way the UI groups them.
 *
 * @return array<string, array<string, string>> Group label => [ type => label ].
 */
function nexter_mcp_ct_field_type_catalog(): array {
	$catalog = array(
		'Basic'      => array(
			'text'     => 'Text',
			'textarea' => 'Textarea',
			'number'   => 'Number',
			'range'    => 'Range',
			'email'    => 'Email',
			'url'      => 'URL',
		),
		'Content'    => array(
			'image'   => 'Image',
			'file'    => 'File',
			'wysiwyg' => 'WYSIWYG Editor',
		),
		'Choice'     => array(
			'select'     => 'Select',
			'checkbox'   => 'Checkbox',
			'radio'      => 'Radio Button',
			'true_false' => 'True / False',
		),
		'Relational' => array(
			'link'        => 'Link',
			'post_object' => 'Post Object',
			'page_link'   => 'Page Link',
			'taxonomy'    => 'Taxonomy',
			'user'        => 'User',
		),
		'Advanced'   => array(
			'date'     => 'Date Picker',
			'time'     => 'Time Picker',
			'datetime' => 'Date Time Picker',
			'color'    => 'Color Picker',
		),
	);

	// Nexter Pro adds Relationship and Repeater here.
	return (array) apply_filters( 'nxt/field_type_catalog', $catalog );
}

/**
 * Types the renderer still understands but that are no longer offered for new fields.
 *
 * @return array<string, string>
 */
function nexter_mcp_ct_legacy_field_types(): array {
	return array( 'password' => 'Password (withdrawn: values were stored as cleartext meta, do not create new ones)' );
}

/**
 * Flat list of creatable field type slugs.
 *
 * @return string[]
 */
function nexter_mcp_ct_creatable_field_types(): array {
	$flat = array();
	foreach ( nexter_mcp_ct_field_type_catalog() as $types ) {
		foreach ( array_keys( $types ) as $slug ) {
			$flat[] = $slug;
		}
	}
	return $flat;
}

/**
 * Types whose configuration is driven by a newline-separated `choices` list.
 *
 * @return string[]
 */
function nexter_mcp_ct_choice_field_types(): array {
	return array( 'select', 'checkbox', 'radio' );
}

/**
 * Whether the current user may edit the object a field value is being read from or written to.
 *
 * @param string $object_type post|user|term|comment|option.
 * @param mixed  $object_id   Object ID, or 'option'.
 * @return bool
 */
function nexter_mcp_ct_can_edit_object( string $object_type, $object_id ): bool {
	switch ( $object_type ) {
		case 'user':
			return (int) $object_id > 0 && current_user_can( 'edit_user', (int) $object_id );
		case 'term':
			return (int) $object_id > 0 && current_user_can( 'edit_term', (int) $object_id );
		case 'comment':
			return (int) $object_id > 0 && current_user_can( 'edit_comment', (int) $object_id );
		case 'option':
			// Option-scoped values are site configuration; manage_options is the right primitive
			// and is already guaranteed by the ability's own permission callback.
			return current_user_can( 'manage_options' );
		default:
			return (int) $object_id > 0 && current_user_can( 'edit_post', (int) $object_id );
	}
}

/**
 * A capability-denied failure, worded so an agent reports the reason rather than retrying.
 *
 * @param string $object_type Object type that was refused.
 * @param mixed  $object_id   Object ID.
 * @param string $action      'read' or 'change'.
 * @return array
 */
function nexter_mcp_ct_denied( string $object_type, $object_id, string $action = 'change' ): array {
	return nexter_mcp_ct_fail(
		sprintf(
			'You do not have permission to %1$s custom field data on that %2$s (ID %3$s). This is a capability check on the specific object, not on the Content Fields module.',
			$action,
			$object_type,
			is_scalar( $object_id ) ? (string) $object_id : '0'
		),
		array(
			'code'   => 'nexter_object_forbidden',
			'status' => 403,
		)
	);
}

/**
 * Content Fields abilities that change something.
 *
 * @return string[]
 */
function nexter_mcp_ct_write_abilities(): array {
	return array(
		'nexter/create-cpt',
		'nexter/update-cpt',
		'nexter/create-taxonomy',
		'nexter/update-taxonomy',
		'nexter/toggle-content-type',
		'nexter/delete-content-type',
		'nexter/restore-content-type',
		'nexter/delete-content-type-permanently',
		'nexter/import-content-types',
		'nexter/create-field-group',
		'nexter/update-field-group',
		'nexter/delete-field-group',
		'nexter/toggle-field-group',
		'nexter/add-field-to-group',
		'nexter/update-field-in-group',
		'nexter/delete-field-from-group',
		'nexter/update-field-value',
		'nexter/delete-field-value',
		'nexter/scaffold-content-model',
		'nexter/duplicate-field-group',
		'nexter/reorder-fields-in-group',
		'nexter/repair-model',
		'nexter/import-from-source',
		// Listed so read-only mode refuses the batch up front. Every step is gated on its own too,
		// but without this the batch runs and fails 25 times to say one thing.
		'nexter/apply-changes',
	);
}

/**
 * Whether the Content Fields abilities are restricted to read-only.
 *
 * @return bool
 */
function nexter_mcp_ct_read_only(): bool {
	$read_only = defined( 'NEXTER_ABILITIES_READ_ONLY' ) && NEXTER_ABILITIES_READ_ONLY;

	if ( ! $read_only ) {
		$read_only = ! empty( get_option( 'nexter_abilities_read_only', false ) );
	}

	/**
	 * Filter whether Content Fields write abilities are refused.
	 *
	 * @param bool $read_only Whether writes are disabled.
	 */
	return (bool) apply_filters( 'nexter_abilities_read_only', $read_only );
}

// Enforce the read-only switch at the permission layer so it applies however the ability is invoked: MCP, the
// abilities REST route, or a direct execute() call.
add_filter(
	'wp_ability_permission_result',
	static function ( $permission, $name ) {
		if ( ! is_string( $name ) || ! in_array( $name, nexter_mcp_ct_write_abilities(), true ) ) {
			return $permission;
		}
		if ( ! nexter_mcp_ct_read_only() ) {
			return $permission;
		}
		return new WP_Error(
			'nexter_abilities_read_only',
			__( 'Content Fields abilities are in read-only mode on this site, so this change was refused. Reading definitions and field values still works.', 'nexter-extension' ),
			array( 'status' => 403 )
		);
	},
	10,
	2
);
