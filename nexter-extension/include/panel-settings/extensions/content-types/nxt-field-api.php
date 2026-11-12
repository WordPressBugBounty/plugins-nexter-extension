<?php
/**
 * Nexter Field API – template functions for getting/setting field values.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse object_id into type and id. Supports: post (int), user_123, term_45, option, comment_12.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, comment_3, or false for current post.
 * @return array{type: string, id: int|string} ['type' => 'post'|'user'|'term'|'option'|'comment', 'id' => ...]
 */
function nxt_get_valid_object_id( $object_id = false ) {
	$type = 'post';
	$id   = 0;

	if ( false === $object_id || null === $object_id ) {
		$id = get_the_ID();
		if ( ! $id ) {
			return array(
				'type' => 'post',
				'id'   => 0,
			);
		}
		return array(
			'type' => 'post',
			'id'   => (int) $id,
		);
	}

	if ( is_numeric( $object_id ) ) {
		return array(
			'type' => 'post',
			'id'   => (int) $object_id,
		);
	}

	$str = (string) $object_id;
	if ( preg_match( '/^user_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'user',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '/^term_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'term',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '/^comment_(\d+)$/i', $str, $m ) ) {
		return array(
			'type' => 'comment',
			'id'   => (int) $m[1],
		);
	}
	if ( 'option' === $str || 'options' === $str ) {
		return array(
			'type' => 'option',
			'id'   => 'option',
		);
	}

	return array(
		'type' => 'post',
		'id'   => is_numeric( $str ) ? (int) $str : 0,
	);
}

/**
 * Get meta key prefix for object type (no leading underscore for option).
 *
 * @param string $object_type One of: post, user, term, option, comment.
 * @return string Prefix e.g. _nxt_gf_, _nxt_gf_term_, _nxt_gf_user_, nxt_option_, _nxt_gf_comment_
 */
function nxt_get_meta_key_prefix( $object_type ) {
	switch ( $object_type ) {
		case 'user':
			// wp_usermeta is network-wide, so an unscoped key made a user field on site A readable and writable on site B.
			return is_multisite() ? '_nxt_gf_user_' . get_current_blog_id() . '_' : '_nxt_gf_user_';
		case 'term':
			return '_nxt_gf_term_';
		case 'option':
			return 'nxt_option_';
		case 'comment':
			return '_nxt_gf_comment_';
		default:
			return '_nxt_gf_';
	}
}

/**
 * The pre-blog-scoping form of a user meta key (`_nxt_gf_user_{group}_{field}`).
 *
 * Returns the key unchanged on single site, or when it isn't a blog-scoped user key.
 *
 * @param string $meta_key A user field meta key.
 * @return string
 */
function nxt_legacy_user_meta_key( $meta_key ) {
	$meta_key = (string) $meta_key;
	if ( ! is_multisite() ) {
		return $meta_key;
	}
	$scoped_prefix = '_nxt_gf_user_' . get_current_blog_id() . '_';
	if ( 0 !== strpos( $meta_key, $scoped_prefix ) ) {
		return $meta_key;
	}
	return '_nxt_gf_user_' . substr( $meta_key, strlen( $scoped_prefix ) );
}

/**
 * Copy pre-existing unscoped user field values into this blog's scoped keys, once per blog.
 *
 * @return void
 */
function nxt_maybe_migrate_user_field_meta_to_blog_scope() {
	if ( ! is_multisite() || get_option( 'nxt_gf_user_meta_blog_scoped' ) ) {
		return;
	}
	if ( ! class_exists( 'Nxt_Content_Types_Definitions_Repository' ) ) {
		return;
	}

	global $wpdb;
	$blog_prefix = '_nxt_gf_user_' . get_current_blog_id() . '_';
	$groups      = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();

	foreach ( $groups['group_fields'] as $row ) {
		$meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
		$gk   = isset( $meta['key'] ) ? sanitize_key( (string) $meta['key'] ) : '';
		if ( '' === $gk ) {
			continue;
		}
		// A purely numeric group key would make the legacy LIKE pattern collide with another blog's
		// scoped keys (`_nxt_gf_user_2_…`). Skip it rather than risk claiming the wrong rows.
		if ( preg_match( '/^\d+$/', $gk ) ) {
			continue;
		}
		$object_types = nxt_group_meta_object_types( isset( $meta['location_rules'] ) ? $meta['location_rules'] : array() );
		if ( ! in_array( 'user', $object_types, true ) ) {
			continue;
		}

		$like   = $wpdb->esc_like( '_nxt_gf_user_' . $gk . '_' ) . '%';
		$legacy = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $legacy ) ) {
			continue;
		}
		foreach ( $legacy as $meta_row ) {
			$scoped = $blog_prefix . substr( $meta_row->meta_key, strlen( '_nxt_gf_user_' ) );
			if ( metadata_exists( 'user', (int) $meta_row->user_id, $scoped ) ) {
				continue; // Already migrated, or the site has since written its own value.
			}
			update_user_meta( (int) $meta_row->user_id, $scoped, maybe_unserialize( $meta_row->meta_value ) );
		}
	}

	update_option( 'nxt_gf_user_meta_blog_scoped', 1, false );
}
add_action( 'admin_init', 'nxt_maybe_migrate_user_field_meta_to_blog_scope' );

/**
 * Canonical meta-key builder for a group field value, used by BOTH the read and write paths so they can
 * never diverge.
 *
 * @param string $object_type post, user, term, option, comment.
 * @param string $group_key   Field group key.
 * @param string $field_name  Field name.
 * @return string Full meta key (or option name for the 'option' type).
 */
function nxt_build_meta_key( $object_type, $group_key, $field_name ) {
	return nxt_get_meta_key_prefix( $object_type ) . sanitize_key( (string) $group_key ) . '_' . sanitize_key( (string) $field_name );
}

/**
 * Get all field groups (optionally filtered by context). For option type, returns all group_fields.
 *
 * @param array $context Optional. Context for location matching. If type is option, all groups returned.
 * @return array List of group configs (id, key, title, fields, ...).
 */
function nxt_get_all_groups_for_context( $context = array() ) {
	if ( ! class_exists( 'Nxt_Content_Types_CPT' ) || ! class_exists( 'Nxt_Content_Types_Field_Groups_Location' ) ) {
		return array();
	}

	$screen = isset( $context['screen'] ) ? $context['screen'] : '';
	if ( 'option' === $screen ) {
		$groups = Nxt_Content_Types_Definitions_Repository::get_instance()->by_kind();
		$out    = array();
		foreach ( $groups['group_fields'] as $row ) {
			if ( 'publish' !== $row['status'] ) {
				continue;
			}
			$meta = $row['meta'];
			if ( isset( $meta['active'] ) && ! $meta['active'] ) {
				continue;
			}
			$out[] = array(
				'id'                    => $row['id'],
				'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : $row['title'],
				'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $row['id'],
				'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
				'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
				'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
				'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
				'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
				'order_no'              => isset( $meta['order_no'] ) ? (int) $meta['order_no'] : 0,
				// Needed by nxt_register_group_field_meta(): show_in_rest is the group's REST opt-out, and
				// location_rules decide which object types its value meta is registered for.
				'show_in_rest'          => ! array_key_exists( 'show_in_rest', $meta ) || (bool) $meta['show_in_rest'],
				'location_rules'        => isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				// order_no is always set by the array built above, so no fallback is reachable here.
				$ao = (int) $a['order_no'];
				$bo = (int) $b['order_no'];
				return $ao <=> $bo;
			}
		);
		return $out;
	}

	return Nxt_Content_Types_Field_Groups_Location::get_matching_groups( $context );
}

/**
 * Build context array from object type and id (for get_matching_groups).
 *
 * @param string     $object_type post, user, term, option, comment.
 * @param int|string $object_id   ID or 'option'.
 * @param array      $overrides   Screen-specific values merged last (e.g. user_form on the add-user
 *                                screen, where there is no object to read it from).
 * @return array Context for location matching.
 */
function nxt_build_context_for_object( $object_type, $object_id, $overrides = array() ) {
	$context = array();

	switch ( $object_type ) {
		case 'post':
			$context['post_id']   = (int) $object_id;
			$context['post_type'] = 'post';
			if ( $object_id ) {
				$post = get_post( $object_id );
				if ( $post ) {
					$context['post']          = $post;
					$context['post_type']     = $post->post_type;
					$context['post_status']   = $post->post_status;
					$template                 = get_post_meta( $object_id, '_wp_page_template', true );
					$resolved_template        = ( $template && 'default' !== $template ) ? $template : 'default';
					$context['post_template'] = $resolved_template;
					$context['page_template'] = $resolved_template;
					if ( 'page' === $post->post_type ) {
						if ( (int) get_option( 'page_on_front' ) === (int) $object_id ) {
							$context['page_type'] = 'front_page';
						} elseif ( (int) get_option( 'page_for_posts' ) === (int) $object_id ) {
							$context['page_type'] = 'posts_page';
						} elseif ( $post->post_parent > 0 ) {
								$context['page_type'] = 'child';
						} else {
							// A top-level page that has children is 'parent', not 'top_level'.
							$children             = get_children(
								array(
									'post_parent' => $object_id,
									'post_type'   => 'page',
									'numberposts' => 1,
								)
							);
							$context['page_type'] = ! empty( $children ) ? 'parent' : 'top_level';
						}
						$context['page_parent'] = (string) $post->post_parent;
					}
					if ( post_type_supports( $post->post_type, 'post-formats' ) ) {
						$format                 = get_post_format( $object_id );
						$context['post_format'] = $format ? $format : 'standard';
					}
				}
			}
			break;
		case 'user':
			$context['screen']    = 'user';
			$context['user_id']   = (int) $object_id;
			$context['user_form'] = 'edit';
			if ( $object_id ) {
				$user = get_user_by( 'id', $object_id );
				if ( $user && ! empty( $user->roles ) ) {
					$context['user_role'] = $user->roles[0];
				}
			}
			break;
		case 'term':
			$context['screen']  = 'term';
			$context['term_id'] = (int) $object_id;
			if ( $object_id ) {
				$term = get_term( $object_id );
				if ( $term && ! is_wp_error( $term ) ) {
					$context['taxonomy'] = $term->taxonomy;
				}
			}
			break;
		case 'option':
			$context['screen'] = 'option';
			break;
		case 'comment':
			$context['screen']     = 'comment';
			$context['comment_id'] = (int) $object_id;
			break;
		default:
			$context['post_id']   = is_numeric( $object_id ) ? (int) $object_id : 0;
			$context['post_type'] = 'post';
	}

	// Viewer keys, on every object type: they describe who is looking, not what is looked at.
	// user_role stays whatever the object branch set (the edited user's role); the viewer's is current_user_role.
	$viewer    = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$viewer_id = $viewer ? (int) $viewer->ID : 0;

	$context['current_user_id']   = $viewer_id;
	$context['current_user_role'] = ( $viewer && ! empty( $viewer->roles ) ) ? (string) $viewer->roles[0] : '';
	// On user screens user_id is the user being edited; everywhere else there is no object user, so
	// the viewer is the only sensible answer.
	if ( ! isset( $context['user_id'] ) ) {
		$context['user_id'] = $viewer_id;
	}
	// user_role is deliberately NOT defaulted to the viewer's role.

	// current_user is a list, not one value: an admin in wp-admin is both logged_in and viewing_back at once.
	// rule_matches() treats an array actual as "value is in the list".
	$current_user = array();
	if ( $viewer_id ) {
		$current_user[] = 'logged_in';
	}
	$current_user[]          = is_admin() ? 'viewing_back' : 'viewing_front';
	$context['current_user'] = $current_user;

	if ( ! empty( $overrides ) && is_array( $overrides ) ) {
		$context = array_merge( $context, $overrides );
	}

	return $context;
}

/**
 * Find field config and group_key by selector (field name) for a given object. First matching group/field wins.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, etc.
 * @return array|null ['field' => array, 'group_key' => string, 'object_type' => string, 'object_id' => mixed] or null.
 */
function nxt_maybe_get_field_info( $selector, $object_id = false ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return null;
	}

	$obj         = nxt_get_valid_object_id( $object_id );
	$object_type = $obj['type'];
	$object_id   = $obj['id'];

	$context = nxt_build_context_for_object( $object_type, $object_id );

	// 1. Location-matched groups first — this governs which groups render in the editor and
	// whether an unsaved field falls back to its default_value ('matched' => true).
	$matched = nxt_get_all_groups_for_context( $context );
	$info    = _nxt_find_field_in_groups( $selector, $matched, $object_type, $object_id, true );
	if ( $info ) {
		return $info;
	}

	// 2. Fallback: a value may be stored even though the object no longer matches the group's location rules.
	$all  = nxt_get_all_groups_for_context( array( 'screen' => 'option' ) );
	$info = _nxt_find_field_in_groups( $selector, $all, $object_type, $object_id, false );
	if ( $info ) {
		return $info;
	}

	return null;
}

/**
 * Locate a field by selector within a list of groups, returning the resolution info (or null).
 *
 * @param string $selector    Field name or key.
 * @param array  $groups      Group configs.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   Object ID.
 * @param bool   $matched     Whether these groups matched the object's location context.
 * @return array|null
 */
function _nxt_find_field_in_groups( $selector, $groups, $object_type, $object_id, $matched ) {
	foreach ( (array) $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		$field     = nxt_find_top_level_field_by_selector( $selector, $fields );
		if ( $field ) {
			return array(
				'field'        => $field,
				'group_key'    => $group_key,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'matched'      => (bool) $matched,
				// Siblings of $field within the same group, needed to evaluate $field's own
				// conditional_logic (see nxt_field_conditional_passes()) without a second lookup.
				'group_fields' => $fields,
			);
		}
	}
	return null;
}

/**
 * Whether a resolved field's own conditional-logic rules currently pass, given its siblings' stored values.
 *
 * @param array $info Result of nxt_maybe_get_field_info()/_nxt_find_field_in_groups().
 * @return bool
 */
function nxt_field_conditional_passes( $info ) {
	if ( ! is_array( $info ) || ! is_array( $info['field'] ?? null ) ) {
		return true;
	}
	$field = $info['field'];
	if ( empty( $field['conditional_logic_enabled'] ) || empty( $field['conditional_logic'] )
		|| ! class_exists( 'Nxt_Content_Types_Field_Groups_Location' )
	) {
		return true;
	}
	$sibling_values = nxt_get_sibling_field_values(
		isset( $info['group_fields'] ) ? $info['group_fields'] : array(),
		$info['group_key'],
		$info['object_type'],
		$info['object_id']
	);
	return Nxt_Content_Types_Field_Groups_Location::match_field_conditional( $field['conditional_logic'], $sibling_values );
}

/**
 * Raw values of a group's top-level fields, keyed by field name.
 * This is the shape match_field_conditional() expects.
 *
 * @param array      $fields      Top-level fields of the group the field being resolved belongs to.
 * @param string     $group_key   Field group key.
 * @param string     $object_type post, user, term, option, comment.
 * @param int|string $object_id   Object ID.
 * @return array<string, mixed>
 */
function nxt_get_sibling_field_values( $fields, $group_key, $object_type, $object_id ) {
	$values = array();
	foreach ( (array) $fields as $sibling ) {
		if ( ! is_array( $sibling ) || empty( $sibling['name'] ) ) {
			continue;
		}
		$name            = (string) $sibling['name'];
		$meta_key        = nxt_build_meta_key( $object_type, $group_key, $name );
		$values[ $name ] = nxt_get_stored_value( $meta_key, $object_type, $object_id, '' );
	}
	return $values;
}

/**
 * Whether a field config matches a template selector (name or field key).
 *
 * @param array  $field    Field config.
 * @param string $selector Selector string.
 * @return bool
 */
function nxt_field_matches_selector( $field, $selector ) {
	if ( ! is_array( $field ) || '' === $selector ) {
		return false;
	}
	$name = isset( $field['name'] ) ? (string) $field['name'] : '';
	if ( $name === $selector ) {
		return true;
	}
	$key = isset( $field['key'] ) ? (string) $field['key'] : '';
	return ( '' !== $key && $key === $selector );
}

/**
 * Find a top-level group field only (no recursion into repeater subfields).
 * Subfield values live inside repeater row arrays, not in separate meta keys.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Top-level fields from a group.
 * @return array|null
 */
function nxt_find_top_level_field_by_selector( $selector, $fields ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector ) {
		return null;
	}
	foreach ( $fields as $f ) {
		if ( is_array( $f ) && nxt_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
	}
	return null;
}

/**
 * Find a subfield definition inside a repeater (or container) field's `fields` list.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Subfields array.
 * @return array|null
 */
function nxt_find_subfield_by_selector( $selector, $fields ) {
	$selector = is_string( $selector ) ? trim( $selector ) : '';
	if ( '' === $selector || ! is_array( $fields ) ) {
		return null;
	}
	foreach ( $fields as $f ) {
		if ( is_array( $f ) && nxt_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
	}
	return null;
}

/**
 * Find nested repeater subfield definition.
 *
 * @param array  $parent_repeater Parent repeater field config.
 * @param string $selector        Sub repeater name or key.
 * @return array|null
 */
function nxt_find_subfield_repeater( $parent_repeater, $selector ) {
	if ( ! is_array( $parent_repeater ) || ( $parent_repeater['type'] ?? '' ) !== 'repeater' ) {
		return null;
	}
	$sub = nxt_find_subfield_by_selector( $selector, $parent_repeater['fields'] ?? array() );
	if ( $sub && ( $sub['type'] ?? '' ) === 'repeater' ) {
		return $sub;
	}
	return null;
}

/**
 * Find field by name or key in flat or nested field list (deep search).
 * Prefer top-level helpers for meta resolution; this remains for legacy/internal use.
 *
 * @param string $selector Field name or key.
 * @param array  $fields   Array of field configs.
 * @return array|null Field config or null.
 */
function nxt_find_field_in_list( $selector, $fields ) {
	foreach ( $fields as $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		if ( nxt_field_matches_selector( $f, $selector ) ) {
			return $f;
		}
		if ( ! empty( $f['fields'] ) && is_array( $f['fields'] ) ) {
			$sub = nxt_find_field_in_list( $selector, $f['fields'] );
			if ( $sub ) {
				return $sub;
			}
		}
	}
	return null;
}

/**
 * Whether a field type ships with Nexter Pro and Pro's handler for it isn't loaded.
 *
 * @param string $type Field type.
 * @return bool
 */
function nxt_field_type_is_locked( $type ) {
	$pro_types = array(
		'relationship' => 'Nexter_Pro_CF_Relationship',
		'repeater'     => 'Nexter_Pro_CF_Repeater',
	);
	return is_string( $type ) && isset( $pro_types[ $type ] ) && ! class_exists( $pro_types[ $type ] );
}

/**
 * Get raw stored value for a field (no formatting).
 *
 * @param string $meta_key    Full meta key.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @param mixed  $default     Default if not found.
 * @return mixed
 */
function nxt_get_stored_value( $meta_key, $object_type, $object_id, $default = null ) {
	// An empty stored string is ambiguous: get_*_meta() returns '' both for "row exists and holds an empty
	// value" (the editor deliberately cleared the field) and for "no row at all".
	$resolve = static function ( $v, $type, $id ) use ( $meta_key, $default ) {
		if ( '' !== $v || null !== $default ) {
			return $v;
		}
		return metadata_exists( $type, (int) $id, $meta_key ) ? '' : $default;
	};

	switch ( $object_type ) {
		case 'user':
			if ( ! $object_id ) {
				return $default;
			}
			// Values saved before user meta keys became blog-scoped live under the unscoped key.
			if ( ! metadata_exists( 'user', (int) $object_id, $meta_key ) ) {
				$legacy = nxt_legacy_user_meta_key( $meta_key );
				if ( $legacy !== $meta_key && metadata_exists( 'user', (int) $object_id, $legacy ) ) {
					$meta_key = $legacy;
				}
			}
			return $resolve( get_user_meta( (int) $object_id, $meta_key, true ), 'user', $object_id );
		case 'term':
			if ( ! $object_id ) {
				return $default;
			}
			return $resolve( get_term_meta( (int) $object_id, $meta_key, true ), 'term', $object_id );
		case 'option':
			$sentinel = '__nxt_option_not_set__';
			$v        = get_option( $meta_key, $sentinel );
			return ( $sentinel === $v ) ? $default : $v;
		case 'comment':
			if ( ! $object_id ) {
				return $default;
			}
			return $resolve( get_comment_meta( (int) $object_id, $meta_key, true ), 'comment', $object_id );
		default:
			return $resolve( get_post_meta( (int) $object_id, $meta_key, true ), 'post', $object_id );
	}
}

/**
 * Update stored value. Capability checks should be done by caller for update/delete.
 *
 * @param string $meta_key    Full meta key.
 * @param mixed  $value       Value to store.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @return bool
 */
function nxt_update_stored_value( $meta_key, $value, $object_type, $object_id ) {
	switch ( $object_type ) {
		case 'user':
			return $object_id ? (bool) update_user_meta( (int) $object_id, $meta_key, $value ) : false;
		case 'term':
			return $object_id ? (bool) update_term_meta( (int) $object_id, $meta_key, $value ) : false;
		case 'option':
			// autoload=false: field values are read on demand via nxt_field_value(), not on every page load.
			return update_option( $meta_key, $value, false );
		case 'comment':
			return $object_id ? (bool) update_comment_meta( (int) $object_id, $meta_key, $value ) : false;
		default:
			return (bool) update_post_meta( (int) $object_id, $meta_key, $value );
	}
}

/**
 * Delete stored value.
 *
 * @param string $meta_key    Full meta key.
 * @param string $object_type post, user, term, option, comment.
 * @param mixed  $object_id   ID or 'option'.
 * @return bool
 */
function nxt_delete_stored_value( $meta_key, $object_type, $object_id ) {
	switch ( $object_type ) {
		case 'user':
			return $object_id ? delete_user_meta( (int) $object_id, $meta_key ) : false;
		case 'term':
			return $object_id ? delete_term_meta( (int) $object_id, $meta_key ) : false;
		case 'option':
			return delete_option( $meta_key );
		case 'comment':
			return $object_id ? delete_comment_meta( (int) $object_id, $meta_key ) : false;
		default:
			return delete_post_meta( (int) $object_id, $meta_key );
	}
}

/**
 * Return reference to the request-level cache array (used by get_field, get_field_object, get_field_objects).
 *
 * @return array
 */
function &_nxt_request_cache() {
	static $cache = array();
	return $cache;
}

/**
 * Clear request-level cache for a field (and all fields for that object) after update/delete.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id Object ID (post ID, user_1, term_2, option, etc.).
 */
function _nxt_clear_field_request_cache( $selector, $object_id ) {
	$cache         = &_nxt_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current:' . (int) get_the_ID() : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );

	// Clear every cached entry for this object, not just $selector's own entries.
	$normalized_id = (string) $normalized_id;
	foreach ( array_keys( $cache ) as $key ) {
		$parts = explode( '|', $key );
		$count = count( $parts );
		// Cast to string: explode() always yields strings, but $normalized_id may be an int
		// (post ID) — a strict compare would never match and silently no-op the clear.
		if ( $count >= 4 && ( 'v' === $parts[0] || 'o' === $parts[0] ) && (string) $parts[ $count - 2 ] === $normalized_id ) {
			unset( $cache[ $key ] );
		} elseif ( $count >= 3 && 'a' === $parts[0] && (string) $parts[2] === $normalized_id ) {
			unset( $cache[ $key ] );
		}
	}
}

/**
 * Drop every request-cached value once a definition changes (toggle, field edit, trash).
 */
function _nxt_flush_request_cache() {
	$cache = &_nxt_request_cache();
	$cache = array();
}

// ----------------------------------------------------------------------------- 1. Core Field Retrieval
// (with request-level cache) -----------------------------------------------------------------------------.

/**
 * Return single field value. Formatted by default (e.g. image ID → URL).
 * Uses request-level cache: repeated calls for the same selector/object_id/format return cached value without DB hit.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, or false for current post.
 * @param bool   $format     Whether to format value (default true).
 * @return mixed Field value or null if not found.
 */
function nxt_field_value( $selector, $object_id = false, $format = true ) {
	$cache         = &_nxt_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current:' . (int) get_the_ID() : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'v|' . $selector . '|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( $info && ! nxt_field_conditional_passes( $info ) ) {
		$info = null;
	}
	if ( ! $info ) {
		$value               = apply_filters( 'nxt/load_field', null, $selector, $object_id );
		$cache[ $cache_key ] = $value;
		return $value;
	}

	$object_type = $info['object_type'];
	$object_id   = $info['object_id'];
	$group_key   = $info['group_key'];
	$field       = $info['field'];
	$meta_key    = nxt_build_meta_key( $object_type, $group_key, $field['name'] );

	$value = apply_filters( 'nxt/pre_load_field', null, $selector, $object_id );
	if ( null === $value ) {
		$value = nxt_get_stored_value( $meta_key, $object_type, $object_id, null );
	}
	$exists = ( 'option' !== $object_type ) ? metadata_exists( $object_type, $object_id, $meta_key ) : ( get_option( $meta_key, '__nxt_not_set__' ) !== '__nxt_not_set__' );
	// Apply default_value only when the object actually matches the group's location rules; a
	// fallback resolution (matched=false) must return stored values only, never manufacture a default.
	if ( ! $exists && ! empty( $info['matched'] ) && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}
	$value = apply_filters( 'nxt/load_field', $value, $selector, $object_id );

	if ( $format ) {
		$value = nxt_format_field_value( $value, $field );
		$value = apply_filters( 'nxt/format_value', $value, $field, $object_id );
	}

	$cache[ $cache_key ] = $value;
	return $value;
}

/**
 * Echo field value (HTML-escaped for safe output).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 */
function nxt_print_field( $selector, $object_id = false ) {
	$value = nxt_field_value( $selector, $object_id, true );

	// WYSIWYG values are already-formatted HTML (see nxt_format_field_value()'s 'wysiwyg' case, which runs
	// them through the_content).
	if ( 'wysiwyg' === nxt_field_type( $selector, $object_id ) ) {
		if ( is_string( $value ) ) {
			echo wp_kses_post( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_kses_post
		}
		return;
	}

	// Escape exactly once.
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$parts[] = nxt_escape_field_value( $item );
			}
		}
		echo implode( ', ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each element escaped by nxt_escape_field_value
		return;
	}

	$value = nxt_escape_field_value( $value );
	if ( is_scalar( $value ) ) {
		echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by nxt_escape_field_value
	}
}

/**
 * Return all field values for an object as associative array [ field_name => value ].
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @param bool  $format    Whether to format values (default true).
 * @return array Associative array of field name => value, or empty array.
 */
function nxt_field_values( $object_id = false, $format = true ) {
	$objects = nxt_field_definitions( $object_id, $format );
	if ( ! is_array( $objects ) ) {
		return array();
	}
	$out = array();
	foreach ( $objects as $name => $field_obj ) {
		$out[ $name ] = isset( $field_obj['value'] ) ? $field_obj['value'] : null;
	}
	return $out;
}

/**
 * Return field metadata plus value (field object).
 * Uses request-level cache: repeated calls for the same selector/object_id/format return cached object without DB hit.
 *
 * @param string $selector   Field name.
 * @param mixed  $object_id  Post ID, user_1, term_2, option, or false.
 * @param bool   $format     Whether to format the value (default true).
 * @return array|null Field array with 'value' key, or null.
 */
function nxt_field_definition( $selector, $object_id = false, $format = true ) {
	$cache         = &_nxt_request_cache();
	$normalized_id = ( false === $object_id ) ? 'current:' . (int) get_the_ID() : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'o|' . $selector . '|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( $info && ! nxt_field_conditional_passes( $info ) ) {
		$info = null;
	}
	if ( ! $info ) {
		$cache[ $cache_key ] = null;
		return null;
	}

	$object_type = $info['object_type'];
	$object_id   = $info['object_id'];
	$group_key   = $info['group_key'];
	$field       = $info['field'];
	$meta_key    = nxt_build_meta_key( $object_type, $group_key, $field['name'] );

	$value  = nxt_get_stored_value( $meta_key, $object_type, $object_id, null );
	$exists = ( 'option' !== $object_type ) ? metadata_exists( $object_type, $object_id, $meta_key ) : ( get_option( $meta_key, '__nxt_not_set__' ) !== '__nxt_not_set__' );
	// Default only for a location-matched object (see nxt_field_value).
	if ( ! $exists && ! empty( $info['matched'] ) && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}
	if ( $format ) {
		$value = nxt_format_field_value( $value, $field );
	}
	$field['value'] = apply_filters( 'nxt/format_value', $value, $field, $object_id );

	$cache[ $cache_key ] = $field;
	return $field;
}

/**
 * Return all field objects for an object.
 * Uses request-level cache: repeated calls for the same object_id/format return cached array without DB hit.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @param bool  $format    Whether to format values (default true).
 * @return array|false Associative array field_name => field object (with value), or false.
 */
function nxt_field_definitions( $object_id = false, $format = true ) {
	$cache         = &_nxt_request_cache();
	$obj           = nxt_get_valid_object_id( $object_id );
	$normalized_id = ( false === $object_id ) ? 'current:' . (int) get_the_ID() : ( is_scalar( $object_id ) ? $object_id : serialize( $object_id ) );
	$cache_key     = 'a|objects|' . $normalized_id . '|' . ( $format ? '1' : '0' );
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}

	$context = nxt_build_context_for_object( $obj['type'], $obj['id'] );
	$groups  = nxt_get_all_groups_for_context( $context );

	$collected = array();
	foreach ( $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$name = $field['name'];
			if ( ! nxt_field_conditional_passes(
				array(
					'field'        => $field,
					'group_key'    => $group_key,
					'group_fields' => $fields,
					'object_type'  => $obj['type'],
					'object_id'    => $obj['id'],
				)
			) ) {
				continue;
			}
			$meta_key = nxt_build_meta_key( $obj['type'], $group_key, $name );
			$value    = nxt_get_stored_value( $meta_key, $obj['type'], $obj['id'], null );
			$exists   = ( 'option' !== $obj['type'] ) ? metadata_exists( $obj['type'], $obj['id'], $meta_key ) : ( get_option( $meta_key, '__nxt_not_set__' ) !== '__nxt_not_set__' );
			if ( ! $exists && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
				$value = $field['default_value'];
			}
			if ( $format ) {
				$value = nxt_format_field_value( $value, $field );
			}
			$field['value']     = $value;
			$collected[ $name ] = $field;
		}
	}

	$result              = empty( $collected ) ? false : $collected;
	$cache[ $cache_key ] = $result;
	return $result;
}

// ----------------------------------------------------------------------------- 2. Raw Database Value
// -----------------------------------------------------------------------------.

/**
 * Return unformatted meta value.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return mixed Raw value or null.
 */
function nxt_field_value_raw( $selector, $object_id = false ) {
	return nxt_field_value( $selector, $object_id, false );
}

/**
 * Return all raw meta values for an object.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @return array Associative array field_name => raw value.
 */
function nxt_field_values_raw( $object_id = false ) {
	return nxt_field_values( $object_id, false );
}

// ----------------------------------------------------------------------------- 3. Field Metadata
// -----------------------------------------------------------------------------.

/**
 * Get field label.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context to resolve field.
 * @return string Empty string if not found.
 */
function nxt_field_label( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	return ( $info && ! empty( $info['field']['label'] ) ) ? $info['field']['label'] : '';
}

/**
 * Get field type.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return string e.g. text, image, select, repeater, group.
 */
function nxt_field_type( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	return ( $info && ! empty( $info['field']['type'] ) ) ? $info['field']['type'] : '';
}

/**
 * Get field settings (instructions, default_value, required, wrapper, etc.).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return array Settings array or empty array.
 */
function nxt_get_field_settings( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( ! $info || ! is_array( $info['field'] ) ) {
		return array();
	}
	$f        = $info['field'];
	$settings = array();
	$keys     = array(
		'instructions',
		'default_value',
		'required',
		'wrapper',
		'wrapper_class',
		'wrapper_id',
		'wrapper_width',
		'placeholder',
		'prepend',
		'append',
		'min',
		'max',
		'step',
		'rows',
		'choices',
		'return_format',
		'multiple',
		'allow_null',
		'layout',
		'post_object_return_format',
		'post_object_post_types',
		'post_object_post_status',
		'relationship_return_format',
		'relationship_post_types',
		'relationship_post_status',
		'relationship_max_posts',
		'user_return_format',
		'user_role_filter',
		'taxonomy_name',
		'taxonomy_return_format',
		'taxonomy_appearance',
		'taxonomy_save_terms',
		'page_link_post_types',
		'page_link_post_status',
		'date_display_format',
		'date_custom_display_format',
		'date_return_format',
		'datetime_display_format',
		'datetime_custom_display_format',
		'datetime_return_format',
		'time_display_format',
		'time_custom_display_format',
		'time_return_format',
		'time_custom_return_format',
		'color_return_format',
		'color_enable_transparency',
		'color_show_custom_palette',
		'color_palette_source',
		'color_custom_palette',
	);
	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $f ) ) {
			$settings[ $k ] = $f[ $k ];
		}
	}
	return $settings;
}

/**
 * Get field key (group_key + name style identifier). Nexter uses name as primary; key can be group_key + name.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return string Field key or empty.
 */
function nxt_get_field_key( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return '';
	}
	return ( isset( $info['field']['key'] ) && '' !== $info['field']['key'] ) ? $info['field']['key'] : $info['group_key'] . '_' . $info['field']['name'];
}

// ----------------------------------------------------------------------------- 4. Field Existence /
// Validation -----------------------------------------------------------------------------.

/**
 * Check if field exists and has a value for the given object.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function nxt_has_field( $selector, $object_id = false ) {
	$value = nxt_field_value( $selector, $object_id, false );
	return null !== $value && '' !== $value && ( ! is_array( $value ) || ! empty( $value ) );
}

/**
 * Check if field is registered (exists in a matching group).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function nxt_field_exists( $selector, $object_id = false ) {
	return nxt_maybe_get_field_info( $selector, $object_id ) !== null;
}

/**
 * Check if field value is empty.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Optional. Object context.
 * @return bool
 */
function nxt_is_field_empty( $selector, $object_id = false ) {
	$value = nxt_field_value( $selector, $object_id, false );
	return null === $value || '' === $value || ( is_array( $value ) && empty( $value ) );
}

// ----------------------------------------------------------------------------- 5. Field Value Management
// -----------------------------------------------------------------------------.

/**
 * Update field value. Checks edit capability for the object.
 *
 * @param string $selector  Field name.
 * @param mixed  $value     Value to save.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool Success.
 */
function nxt_save_field_value( $selector, $value, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}
	// A Pro-only type keeps its stored value read-only until Pro is active.
	if ( nxt_field_type_is_locked( isset( $info['field']['type'] ) ? $info['field']['type'] : '' ) ) {
		return false;
	}

	$obj = array(
		'type' => $info['object_type'],
		'id'   => $info['object_id'],
	);
	if ( ! nxt_current_user_can_edit_object( $obj ) ) {
		return false;
	}

	$value = apply_filters( 'nxt/update_value', $value, $info['field'], $object_id );

	// Route through the module's own per-type sanitizer — the same call the admin save handlers and the
	// abilities layer make.
	if ( ! class_exists( 'Nxt_Content_Types_Field_Groups_Render' ) ) {
		return false;
	}
	$renderer = Nxt_Content_Types_Field_Groups_Render::get_instance();
	if ( ! is_object( $renderer ) || ! method_exists( $renderer, 'sanitize_value_for_field' ) ) {
		return false;
	}
	$value = $renderer->sanitize_value_for_field( $value, $info['field'] );

	$meta_key = nxt_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );

	$updated = nxt_update_stored_value( $meta_key, $value, $info['object_type'], $info['object_id'] );
	if ( $updated ) {
		_nxt_clear_field_request_cache( $selector, $object_id );
		if ( 'post' === $info['object_type'] ) {
			nxt_purge_page_cache_for_post( $info['object_id'] );
		}
		return true;
	}

	// update_post_meta() (and its user/term/option counterparts) return false both on a genuine write failure
	// AND when the new value is identical to what's already stored (a no-op).
	$current = nxt_get_stored_value( $meta_key, $info['object_type'], $info['object_id'], null );
	return $current === $value;
}

/**
 * Delete field value.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool
 */
function nxt_erase_field_value( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}
	// A Pro-only type keeps its stored value read-only until Pro is active.
	if ( nxt_field_type_is_locked( isset( $info['field']['type'] ) ? $info['field']['type'] : '' ) ) {
		return false;
	}
	if ( ! nxt_current_user_can_edit_object(
		array(
			'type' => $info['object_type'],
			'id'   => $info['object_id'],
		)
	) ) {
		return false;
	}
	$meta_key = nxt_build_meta_key( $info['object_type'], $info['group_key'], $info['field']['name'] );
	$deleted  = nxt_delete_stored_value( $meta_key, $info['object_type'], $info['object_id'] );
	if ( $deleted ) {
		_nxt_clear_field_request_cache( $selector, $object_id );
	}
	return $deleted;
}

/**
 * Reset field to default value (from field config).
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Post ID, user_1, term_2, option, or false.
 * @return bool
 */
function nxt_reset_field( $selector, $object_id = false ) {
	$info = nxt_maybe_get_field_info( $selector, $object_id );
	if ( ! $info ) {
		return false;
	}
	// A Pro-only type keeps its stored value read-only until Pro is active.
	if ( nxt_field_type_is_locked( isset( $info['field']['type'] ) ? $info['field']['type'] : '' ) ) {
		return false;
	}
	$default = isset( $info['field']['default_value'] ) ? $info['field']['default_value'] : '';
	return nxt_save_field_value( $selector, $default, $object_id );
}

/**
 * Check if current user can edit the given object (post/user/term/option).
 *
 * @param array $obj Object reference: 'type' (string) and 'id' (int|string).
 * @return bool
 */
function nxt_current_user_can_edit_object( $obj ) {
	$type = isset( $obj['type'] ) ? $obj['type'] : 'post';
	$id   = isset( $obj['id'] ) ? $obj['id'] : 0;

	switch ( $type ) {
		case 'user':
			return current_user_can( 'edit_user', (int) $id );
		case 'term':
			$term = get_term( (int) $id );
			if ( ! $term || is_wp_error( $term ) ) {
				return false;
			}
			return current_user_can( 'edit_term', (int) $id );
		case 'option':
			return current_user_can( 'manage_options' );
		case 'comment':
			return current_user_can( 'edit_comment', (int) $id );
		default:
			return current_user_can( 'edit_post', (int) $id );
	}
}

// ----------------------------------------------------------------------------- 6. Field Formatting
// -----------------------------------------------------------------------------.

/**
 * Normalize one post_object / relationship item to WP_Post when return format is object.
 *
 * @param mixed  $item   ID, array with ID, WP_Post, or lite array.
 * @param string $return Return format key: id|object.
 * @return mixed
 */
function nxt_format_post_reference_item( $item, $return ) {
	if ( 'id' === $return ) {
		if ( $item instanceof WP_Post ) {
			return (int) $item->ID;
		}
		if ( is_numeric( $item ) ) {
			return (int) $item;
		}
		if ( is_array( $item ) && ! empty( $item['ID'] ) ) {
			return (int) $item['ID'];
		}
		return $item;
	}
	if ( $item instanceof WP_Post ) {
		return $item;
	}
	$id = 0;
	if ( is_numeric( $item ) ) {
		$id = (int) $item;
	} elseif ( is_array( $item ) && ! empty( $item['ID'] ) ) {
		$id = (int) $item['ID'];
	}
	if ( ! $id ) {
		return $item;
	}
	$post = get_post( $id );
	return $post ? $post : $item;
}

/**
 * Normalize user field item to WP_User when format is object.
 *
 * @param mixed  $item Stored id, row array, or WP_User.
 * @param string $fmt  id|object|array.
 * @return mixed
 */
function nxt_format_user_field_item( $item, $fmt ) {
	if ( 'id' === $fmt ) {
		if ( $item instanceof WP_User ) {
			return (int) $item->ID;
		}
		if ( is_numeric( $item ) ) {
			return (int) $item;
		}
		if ( is_array( $item ) ) {
			if ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
				return (int) $item['ID'];
			}
			if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				return (int) $item['id'];
			}
		}
		return $item;
	}
	if ( 'object' === $fmt ) {
		if ( $item instanceof WP_User ) {
			return $item;
		}
		$id = 0;
		if ( is_numeric( $item ) ) {
			$id = (int) $item;
		} elseif ( is_array( $item ) ) {
			if ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
				$id = (int) $item['ID'];
			} elseif ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$id = (int) $item['id'];
			}
		}
		if ( ! $id ) {
			return $item;
		}
		$u = get_user_by( 'id', $id );
		return $u ? $u : $item;
	}
	if ( 'array' === $fmt ) {
		// Legacy rows saved the array shape itself; return them as stored.
		if ( is_array( $item ) ) {
			return $item;
		}
		// Saves now store the user ID only, so build the array from the live user.
		$id = ( $item instanceof WP_User ) ? (int) $item->ID : ( is_numeric( $item ) ? (int) $item : 0 );
		if ( ! $id ) {
			return $item;
		}
		$u = get_user_by( 'id', $id );
		if ( ! $u ) {
			return $item;
		}
		return array(
			'id'       => (int) $u->ID,
			'username' => $u->user_login,
			'name'     => $u->display_name,
			'email'    => $u->user_email,
			'roles'    => array_values( (array) $u->roles ),
		);
	}
	return $item;
}

/**
 * Normalize taxonomy field entry to WP_Term when return format is object.
 *
 * @param mixed  $item Term id, lite array, or WP_Term.
 * @param string $taxonomy Taxonomy slug.
 * @return mixed
 */
function nxt_format_taxonomy_field_item( $item, $taxonomy ) {
	if ( $item instanceof WP_Term ) {
		return $item;
	}
	$id = 0;
	if ( is_numeric( $item ) ) {
		$id = (int) $item;
	} elseif ( is_array( $item ) && ! empty( $item['term_id'] ) ) {
		$id = (int) $item['term_id'];
	}
	if ( ! $id || ! $taxonomy ) {
		return $item;
	}
	$term = get_term( $id, $taxonomy );
	return ( $term && ! is_wp_error( $term ) ) ? $term : $item;
}

/**
 * Coerce a true_false field value to a real boolean.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function nxt_to_bool( $value ) {
	if ( is_string( $value ) ) {
		$normalized = strtolower( trim( $value ) );
		if ( in_array( $normalized, array( 'false', 'no', 'off', '0', '' ), true ) ) {
			return false;
		}
	}
	return (bool) $value;
}

/**
 * Apply formatting rules to a value based on field type (e.g. image ID → URL, date format).
 *
 * @param mixed $value Raw value.
 * @param array $field Field config (must have 'type' key).
 * @return mixed Formatted value.
 */
function nxt_format_field_value( $value, $field ) {
	if ( ! is_array( $field ) || empty( $field['type'] ) ) {
		return $value;
	}
	$type = $field['type'];

	// A type formatted elsewhere (Nexter Pro) returns a non-null value; also runs for repeater sub-fields.
	$pre = apply_filters( 'nxt/pre_format_field_value', null, $value, $field );
	if ( null !== $pre ) {
		return $pre;
	}

	// true_false is resolved before the empty guard below.
	if ( 'true_false' === $type ) {
		return ( null === $value ) ? null : nxt_to_bool( $value );
	}

	if ( null === $value || '' === $value ) {
		return $value;
	}

	switch ( $type ) {
		case 'image':
			$id = is_array( $value ) ? ( isset( $value['id'] ) ? $value['id'] : ( isset( $value['ID'] ) ? $value['ID'] : 0 ) ) : (int) $value;
			if ( ! $id ) {
				return $value;
			}
			$return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
			if ( 'url' === $return ) {
				$src = wp_get_attachment_image_src( $id, 'full' );
				return $src ? $src[0] : '';
			}
			if ( 'id' === $return ) {
				return $id;
			}
			$src = wp_get_attachment_image_src( $id, 'full' );
			return array(
				'id'    => $id,
				'url'   => $src ? $src[0] : '',
				'alt'   => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title' => get_the_title( $id ),
			);
		case 'file':
			$id = is_array( $value ) ? ( isset( $value['id'] ) ? $value['id'] : ( isset( $value['ID'] ) ? $value['ID'] : 0 ) ) : (int) $value;
			if ( ! $id ) {
				return $value;
			}
			$return = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
			if ( 'url' === $return ) {
				return wp_get_attachment_url( $id );
			}
			if ( 'id' === $return ) {
				return $id;
			}
			// Both return false for a deleted or non-attachment ID, and the shape promises a string.
			$file_url  = wp_get_attachment_url( $id );
			$file_path = get_attached_file( $id );
			return array(
				'id'       => $id,
				'url'      => is_string( $file_url ) ? $file_url : '',
				'title'    => get_the_title( $id ),
				'filename' => is_string( $file_path ) ? basename( $file_path ) : '',
			);
		case 'post_object':
			$return   = isset( $field['post_object_return_format'] ) ? $field['post_object_return_format'] : 'object';
			$multiple = ! empty( $field['multiple'] );
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					$value = array( $value );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( '' === $item || null === $item ) {
						continue;
					}
					$out[] = nxt_format_post_reference_item( $item, $return );
				}
				return $out;
			}
			return nxt_format_post_reference_item( $value, $return );
		case 'user':
			$fmt      = isset( $field['user_return_format'] ) ? sanitize_key( (string) $field['user_return_format'] ) : 'array';
			$multiple = ! empty( $field['multiple'] );
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					$value = array( $value );
				}
				$out = array();
				foreach ( $value as $item ) {
					if ( '' === $item || null === $item ) {
						continue;
					}
					$out[] = nxt_format_user_field_item( $item, $fmt );
				}
				return $out;
			}
			return nxt_format_user_field_item( $value, $fmt );
		case 'taxonomy':
			$tax       = isset( $field['taxonomy_name'] ) ? sanitize_key( (string) $field['taxonomy_name'] ) : '';
			$as_object = isset( $field['taxonomy_return_format'] ) && 'object' === $field['taxonomy_return_format'];
			if ( ! $as_object ) {
				return $value;
			}
			$appearance = isset( $field['taxonomy_appearance'] ) ? sanitize_key( (string) $field['taxonomy_appearance'] ) : 'checkbox';
			$multiple   = in_array( $appearance, array( 'checkbox', 'multi_select' ), true );
			if ( $multiple ) {
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$out = array();
				foreach ( $value as $item ) {
					$out[] = nxt_format_taxonomy_field_item( $item, $tax );
				}
				return $out;
			}
			return nxt_format_taxonomy_field_item( $value, $tax );
		case 'wysiwyg':
			if ( ! is_string( $value ) ) {
				return $value;
			}
			return apply_filters( 'the_content', $value );
		case 'date':
		case 'datetime':
		case 'time':
		case 'color':
		case 'link':
		case 'page_link':
			return $value;
		case 'true_false':
			return nxt_to_bool( $value );
		case 'number':
		case 'range':
			return is_numeric( $value ) ? ( strpos( (string) $value, '.' ) !== false ? (float) $value : (int) $value ) : $value;
		default:
			return $value;
	}
}

/**
 * Escape value for safe HTML output (scalar or array recursively).
 *
 * @param mixed $value Value to escape.
 * @return mixed Escaped value (string or array of strings).
 */
function nxt_escape_field_value( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'nxt_escape_field_value', $value );
	}
	if ( is_scalar( $value ) ) {
		return esc_html( (string) $value );
	}
	return '';
}

// ----------------------------------------------------------------------------- 7. Field Group Functions
// -----------------------------------------------------------------------------.

/**
 * Get a single field group by ID.
 *
 * @param int $group_id Post ID of the group (nxt_content_types post).
 * @return array|null Group config or null.
 */
function nxt_group_definition( $group_id ) {
	if ( ! class_exists( 'Nxt_Content_Types_CPT' ) ) {
		return null;
	}
	// Definitions are files.
	$group_id = (int) $group_id;
	$meta     = Nxt_Content_Types_Model_Writer::definition( $group_id );
	if ( ! is_array( $meta ) || ( isset( $meta['kind'] ) ? $meta['kind'] : '' ) !== 'group_fields' ) {
		return null;
	}
	return array(
		'id'                    => $group_id,
		'title'                 => ! empty( $meta['title'] ) ? $meta['title'] : ( ! empty( $meta['key'] ) ? $meta['key'] : '' ),
		'key'                   => ! empty( $meta['key'] ) ? $meta['key'] : 'group_' . $group_id,
		'fields'                => isset( $meta['fields'] ) ? $meta['fields'] : array(),
		'position'              => isset( $meta['position'] ) ? $meta['position'] : 'normal',
		'style'                 => isset( $meta['style'] ) ? $meta['style'] : 'default',
		'label_placement'       => isset( $meta['label_placement'] ) ? $meta['label_placement'] : 'top',
		'instruction_placement' => isset( $meta['instruction_placement'] ) ? $meta['instruction_placement'] : 'label',
		'location_rules'        => isset( $meta['location_rules'] ) ? $meta['location_rules'] : array(),
	);
}

/**
 * Get all field groups (optionally for a context).
 *
 * @param array $context Optional. Context for location matching. Empty = all group_fields.
 * @return array List of group configs.
 */
function nxt_group_definitions( $context = array() ) {
	if ( empty( $context ) ) {
		return nxt_get_all_groups_for_context( array( 'screen' => 'option' ) );
	}
	return nxt_get_all_groups_for_context( $context );
}

/**
 * Get fields for a group.
 *
 * @param int $group_id Group post ID.
 * @return array Field configs.
 */
function nxt_get_group_fields( $group_id ) {
	$group = nxt_group_definition( $group_id );
	return ( $group && isset( $group['fields'] ) ) ? (array) $group['fields'] : array();
}

// --- 8. Caching (request-level cache) ---

/**
 * Get field value from manual request cache.
 *
 * @param string $selector  Field name.
 * @param mixed  $object_id Object ID.
 * @return mixed Cached value or null if not in cache.
 */
function nxt_cache_get_field( $selector, $object_id = false ) {
	static $cache = array();
	$key          = $selector . '|' . ( is_scalar( $object_id ) ? $object_id : ( $object_id ? serialize( $object_id ) : 'current' ) );
	return isset( $cache[ $key ] ) ? $cache[ $key ] : null;
}

/**
 * Set field value in manual request cache.
 *
 * @param string $selector  Field name.
 * @param mixed  $value     Value to cache.
 * @param mixed  $object_id Object ID.
 */
function nxt_cache_set_field( $selector, $value, $object_id = false ) {
	static $cache  = array();
	$key           = $selector . '|' . ( is_scalar( $object_id ) ? $object_id : ( $object_id ? serialize( $object_id ) : 'current' ) );
	$cache[ $key ] = $value;
}

// ----------------------------------------------------------------------------- 9. Bulk Data
// -----------------------------------------------------------------------------.

/**
 * Return all registered fields across all field groups (flat list with group info).
 *
 * @return array Array of field configs with group_key/group_id added.
 */
function nxt_get_all_fields() {
	$groups = nxt_group_definitions( array() );
	$out    = array();
	foreach ( $groups as $group ) {
		$group_key = isset( $group['key'] ) ? $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 );
		$fields    = isset( $group['fields'] ) ? (array) $group['fields'] : array();
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) || empty( $f['name'] ) ) {
				continue;
			}
			$f['group_key'] = $group_key;
			$f['group_id']  = isset( $group['id'] ) ? (int) $group['id'] : 0;
			$out[]          = $f;
		}
	}
	return $out;
}

/**
 * Return all values for an object: meta, field_objects, formatted_values.
 *
 * @param mixed $object_id Post ID, user_1, term_2, option, or false.
 * @return array ['meta' => [], 'field_objects' => [], 'formatted_values' => []].
 */
function nxt_get_all_field_data( $object_id = false ) {
	$field_objects = nxt_field_definitions( $object_id, true );
	$meta          = array();
	$formatted     = array();
	if ( is_array( $field_objects ) ) {
		foreach ( $field_objects as $name => $obj ) {
			$meta[ $name ]      = isset( $obj['value'] ) ? $obj['value'] : null;
			$formatted[ $name ] = $meta[ $name ];
		}
	}
	return array(
		'meta'             => $meta,
		'field_objects'    => $field_objects ? $field_objects : array(),
		'formatted_values' => $formatted,
	);
}

/**
 * Export field groups config as JSON.
 *
 * @param array $group_ids Optional. Specific group IDs to export. Empty = all.
 * @return string JSON string.
 */
function nxt_export_fields( $group_ids = array() ) {
	$groups = array();
	if ( empty( $group_ids ) ) {
		$groups = nxt_group_definitions( array() );
	} else {
		foreach ( $group_ids as $id ) {
			$g = nxt_group_definition( (int) $id );
			if ( $g ) {
				$groups[] = $g;
			}
		}
	}
	return wp_json_encode( $groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
}

/**
 * Import field config from JSON. Creates new nxt_content_types posts (group_fields). Requires manage_options.
 *
 * @param string $json JSON string from nxt_export_fields.
 * @return array ['success' => bool, 'ids' => array of new post IDs, 'errors' => []].
 */
function nxt_import_fields( $json ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Permission denied.', 'nexter-extension' ) ),
		);
	}
	if ( ! class_exists( 'Nxt_Content_Types_CPT' ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Content Types not loaded.', 'nexter-extension' ) ),
		);
	}
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return array(
			'success' => false,
			'ids'     => array(),
			'errors'  => array( __( 'Invalid JSON.', 'nexter-extension' ) ),
		);
	}
	if ( isset( $data['id'] ) || isset( $data['key'] ) ) {
		$data = array( $data );
	}
	$ids    = array();
	$errors = array();
	foreach ( $data as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}
		// Nested config goes through the shared recursive sanitizer, the same choke point every other write path uses.
		$raw_fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
		$raw_rules  = isset( $group['location_rules'] ) && is_array( $group['location_rules'] ) ? $group['location_rules'] : array();
		if ( class_exists( 'Nxt_Content_Types_Field_Groups_Fields' ) ) {
			$raw_fields = Nxt_Content_Types_Field_Groups_Fields::sanitize_config_tree( $raw_fields );
			$raw_rules  = Nxt_Content_Types_Field_Groups_Fields::sanitize_config_tree( $raw_rules );
		}

		$meta = array(
			'kind'                  => 'group_fields',
			'active'                => isset( $group['active'] ) ? (bool) $group['active'] : true,
			'title'                 => isset( $group['title'] ) ? sanitize_text_field( $group['title'] ) : __( 'Imported Group', 'nexter-extension' ),
			'key'                   => isset( $group['key'] ) ? sanitize_key( $group['key'] ) : '',
			'description'           => isset( $group['description'] ) ? sanitize_textarea_field( $group['description'] ) : '',
			'fields'                => $raw_fields,
			'position'              => isset( $group['position'] ) ? sanitize_key( $group['position'] ) : 'normal',
			'style'                 => isset( $group['style'] ) ? sanitize_key( $group['style'] ) : 'default',
			'label_placement'       => isset( $group['label_placement'] ) ? sanitize_key( $group['label_placement'] ) : 'top',
			'instruction_placement' => isset( $group['instruction_placement'] ) ? sanitize_key( $group['instruction_placement'] ) : 'label',
			'location_rules'        => $raw_rules,
			'order_no'              => isset( $group['order_no'] ) ? (int) $group['order_no'] : 0,
			// Previously dropped entirely, so an imported group silently lost its REST visibility
			// choice and its description.
			'show_in_rest'          => ! array_key_exists( 'show_in_rest', $group ) || (bool) $group['show_in_rest'],
		);

		// Update-in-place when the export's key already belongs to a definition, so re-importing the same file is
		// idempotent instead of creating a second group on the same key.
		$post_id = 0;
		if ( '' !== $meta['key'] && class_exists( 'Nxt_Content_Types_Admin_Logic' ) ) {
			$existing = Nxt_Content_Types_Admin_Logic::find_definition_id_by_key( 'group_fields', $meta['key'] );
			if ( $existing ) {
				$post_id = (int) $existing;
			}
		}

		// Same choke point as REST create/update: guarantees a non-blank unique key and a stable key on every field.
		if ( class_exists( 'Nxt_Content_Types_Admin_Logic' ) ) {
			$meta = Nxt_Content_Types_Admin_Logic::finalize_group_meta( $meta, $post_id );
		}

		// Definitions are files. The title travels separately because it is the display name and a
		// file has no post title to fall back on.
		$saved = Nxt_Content_Types_Model_Writer::save( 'group_fields', $meta, $post_id, $meta['title'] );
		if ( is_wp_error( $saved ) ) {
			$errors[] = $saved->get_error_message();
			continue;
		}
		$ids[] = (int) $saved;
	}
	return array(
		'success' => count( $errors ) === 0,
		'ids'     => $ids,
		'errors'  => $errors,
	);
}

// --- 10. Page-Cache Purge Integration ---

/**
 * Purge a single post's page cache after one of its field values changes.
 *
 * @param int $post_id Post ID.
 */
function nxt_purge_page_cache_for_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $post_id );
	}
	if ( has_action( 'litespeed_purge_post' ) ) {
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook name.
	}
}

/**
 * Purge the whole page cache after a CPT/taxonomy definition changes.
 * A structural change affects archives, single views, rewrite rules and REST responses site-wide.
 *
 * @param int    $post_id Definition post ID (unused; part of the action signature).
 * @param string $kind    'cpt', 'taxonomy', or 'group_fields'.
 */
function nxt_purge_page_cache_all( $post_id = 0, $kind = '' ) {
	if ( 'group_fields' === $kind ) {
		return;
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook name.
	}
}

add_action( 'nxt/definition_saved', 'nxt_purge_page_cache_all', 10, 2 );
add_action( 'nxt/definition_deleted', 'nxt_purge_page_cache_all', 10, 2 );
add_action( 'nxt/definition_saved', '_nxt_flush_request_cache', 0 );
add_action( 'nxt/definition_deleted', '_nxt_flush_request_cache', 0 );
add_action( 'nxt/definition_changed', '_nxt_flush_request_cache', 0 );

// --- 11. Multilingual (Polylang) String Registration ---

/**
 * Register a definition's translatable strings with Polylang after it's saved.
 *
 * @param int    $post_id Definition post ID (unused, part of the action signature).
 * @param string $kind    'cpt', 'taxonomy', or 'group_fields'.
 * @param array  $meta    Sanitized definition meta.
 */
function nxt_ct_register_polylang_strings( $post_id, $kind, $meta ) {
	if ( ! function_exists( 'pll_register_string' ) || ! is_array( $meta ) ) {
		return;
	}
	$group      = 'Nexter Content Fields';
	$key_prefix = 'nxt_ct_' . $kind . '_' . ( isset( $meta['post_type_key'] ) ? $meta['post_type_key'] : ( isset( $meta['taxonomy_key'] ) ? $meta['taxonomy_key'] : ( isset( $meta['key'] ) ? $meta['key'] : $post_id ) ) );

	foreach ( array( 'label', 'singular_label', 'title', 'description' ) as $field ) {
		if ( ! empty( $meta[ $field ] ) && is_string( $meta[ $field ] ) ) {
			pll_register_string( $key_prefix . '_' . $field, $meta[ $field ], $group );
		}
	}
	if ( ! empty( $meta['labels'] ) && is_array( $meta['labels'] ) ) {
		foreach ( $meta['labels'] as $label_key => $label_value ) {
			if ( is_string( $label_value ) && '' !== $label_value ) {
				pll_register_string( $key_prefix . '_label_' . $label_key, $label_value, $group );
			}
		}
	}
}
add_action( 'nxt/definition_saved', 'nxt_ct_register_polylang_strings', 10, 3 );

// --- 12. Core meta registration (REST API / block editor / Block Bindings) ---

/**
 * Build the `show_in_rest` value for a meta field that stores a list.
 *
 * @param string $items_type REST type of a single item ('string' | 'integer').
 * @return array{schema:array{type:string, items:array{type:string}}}
 */
function nxt_rest_array_schema( $items_type ) {
	return array(
		'schema' => array(
			'type'  => 'array',
			'items' => array( 'type' => $items_type ),
		),
	);
}

/**
 * Resolve the register_meta() shape for a field: value type, REST exposure, and which sanitizer to apply.
 *
 * @param array $field Field config.
 * @return array{type:string, show_in_rest:(bool|array), sanitize:string}
 */
function nxt_field_meta_rest_config( $field ) {
	$type     = isset( $field['type'] ) ? (string) $field['type'] : 'text';
	$multiple = ! empty( $field['multiple'] );

	// A type registered elsewhere (Nexter Pro) returns its own register_meta() shape.
	$pre = apply_filters( 'nxt/pre_field_meta_rest_config', null, $field );
	if ( is_array( $pre ) ) {
		return $pre;
	}

	// Object / nested shapes REST can't model.
	$opaque = array(
		'type'         => 'string',
		'show_in_rest' => false,
		'sanitize'     => 'raw',
	);

	// Read the per-type return-format key each field actually stores under. These are NOT a shared
	// `return_format` key — reading the wrong one mis-detects the stored shape.
	$fmt = static function ( $key, $default ) use ( $field ) {
		return isset( $field[ $key ] ) && '' !== $field[ $key ]
			? sanitize_key( (string) $field[ $key ] )
			: $default;
	};

	switch ( $type ) {
		case 'number':
		case 'range':
			return array(
				'type'         => 'number',
				'show_in_rest' => true,
				'sanitize'     => 'number',
			);
		case 'true_false':
			return array(
				'type'         => 'boolean',
				'show_in_rest' => true,
				'sanitize'     => 'bool',
			);
		case 'url':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'url',
			);
		case 'email':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'email',
			);
		case 'wysiwyg':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'wysiwyg',
			);
		case 'textarea':
			// Must not fall through to 'text': sanitize_text_field() collapses newlines, losing a textarea's line breaks (MB-11).
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'textarea',
			);
		case 'password':
			// VULN-001 follow-up: the type is no longer offered for new fields, but legacy values were still exposed
			// to anonymous REST readers in cleartext because this fell through to the default branch.
			return array(
				'type'         => 'string',
				'show_in_rest' => false,
				'sanitize'     => 'text',
			);
		case 'image':
		case 'file':
			return array(
				'type'         => 'integer',
				'show_in_rest' => true,
				'sanitize'     => 'integer',
			);
		case 'gallery':
			return array(
				'type'         => 'array',
				'show_in_rest' => nxt_rest_array_schema( 'integer' ),
				'sanitize'     => 'array_int',
			);
		case 'checkbox':
			return array(
				'type'         => 'array',
				'show_in_rest' => nxt_rest_array_schema( 'string' ),
				'sanitize'     => 'array_text',
				'choices'      => true,
			);
		case 'radio':
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
				'choices'      => true,
			);
		case 'select':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => nxt_rest_array_schema( 'string' ),
					'sanitize'     => 'array_text',
					'choices'      => true,
				)
				: array(
					'type'         => 'string',
					'show_in_rest' => true,
					'sanitize'     => 'text',
					'choices'      => true,
				);
		case 'page_link':
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => nxt_rest_array_schema( 'string' ),
					'sanitize'     => 'array_url',
				)
				: array(
					'type'         => 'string',
					'show_in_rest' => true,
					'sanitize'     => 'url',
				);
		case 'user':
			// Only user_return_format = id stores scalars / int lists.
			if ( 'id' !== $fmt( 'user_return_format', 'array' ) ) {
				return $opaque;
			}
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => nxt_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'post_object':
			if ( 'object' === $fmt( 'post_object_return_format', 'object' ) ) {
				return $opaque; // Stores an assoc array (or a list of them when multiple).
			}
			return $multiple
				? array(
					'type'         => 'array',
					'show_in_rest' => nxt_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'taxonomy':
			if ( 'object' === $fmt( 'taxonomy_return_format', 'id' ) ) {
				return $opaque; // Stores {term_id,name,slug,taxonomy} arrays.
			}
			// Multiplicity comes from taxonomy_appearance (checkbox is the default), not `field_type` — which no
			// code path ever writes.
			$tax_multi = in_array( $fmt( 'taxonomy_appearance', 'checkbox' ), array( 'checkbox', 'multi_select' ), true );
			return $tax_multi
				? array(
					'type'         => 'array',
					'show_in_rest' => nxt_rest_array_schema( 'integer' ),
					'sanitize'     => 'array_int',
				)
				: array(
					'type'         => 'integer',
					'show_in_rest' => true,
					'sanitize'     => 'integer',
				);
		case 'color':
			// rgba_array stores {r,g,b,a} with int/float members; the generic text sanitizer would
			// stringify each one. Hex / rgba-string formats are plain scalars.
			if ( 'rgba_array' === $fmt( 'color_return_format', 'hex' ) ) {
				return $opaque;
			}
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
			);
		case 'link':
			// Relationship and repeater are answered by Nexter Pro; without it the stored value stays opaque.
		case 'relationship':
		case 'repeater':
		case 'flexible_content':
			return $opaque;
		default:
			return array(
				'type'         => 'string',
				'show_in_rest' => true,
				'sanitize'     => 'text',
			);
	}
}

/**
 * Declared choice VALUES for a select / checkbox / radio field, parsing the same array-or- "value :
 * label"-lines shape the renderer accepts.
 *
 * @param array $field Field config.
 * @return string[] Declared choice values as strings.
 */
function nxt_field_choice_keys( $field ) {
	$choices = ( is_array( $field ) && isset( $field['choices'] ) ) ? $field['choices'] : array();

	if ( is_string( $choices ) ) {
		$parsed = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
				$parsed[ trim( $m[1] ) ] = trim( $m[2] );
			} else {
				$parsed[ $line ] = $line;
			}
		}
		$choices = $parsed;
	}
	if ( ! is_array( $choices ) ) {
		return array();
	}

	// Indexed list of { value, label } objects (a REST client / JS serialization shape).
	$keys = array_keys( $choices );
	if ( array_keys( $keys ) === $keys ) {
		$first = reset( $choices );
		// Indexed list of scalars: array_keys() would make the allowlist "0","1". The scalar is the value.
		if ( ! is_array( $first ) && null !== $first ) {
			$out = array();
			foreach ( $choices as $choice ) {
				if ( is_scalar( $choice ) ) {
					$out[] = (string) $choice;
				}
			}
			return $out;
		}
		if ( is_array( $first ) && array_key_exists( 'value', $first ) ) {
			$out = array();
			foreach ( $choices as $choice ) {
				if ( is_array( $choice ) && array_key_exists( 'value', $choice ) ) {
					$out[] = (string) $choice['value'];
				}
			}
			return $out;
		}
	}

	return array_map( 'strval', array_keys( $choices ) );
}

/**
 * Sanitize a group-field meta value written through core (e.g.
 *
 * @param mixed  $value    Incoming value.
 * @param string $sanitize Sanitize kind: url|array_url|email|wysiwyg|number|integer|bool|array_int|array_text|raw|text.
 * @param array  $field    Optional field config. Supplied for choice-backed types so a REST write is
 *                         held to the same declared-choices allowlist the admin save path enforces.
 * @return mixed
 */
function nxt_sanitize_registered_field_meta_value( $value, $sanitize, $field = array() ) {
	// Choice-backed types (select / radio / checkbox): keep only values the field actually declares.
	if ( is_array( $field ) && ! empty( $field['type'] )
		&& in_array( (string) $field['type'], array( 'select', 'radio', 'checkbox' ), true )
	) {
		$valid = nxt_field_choice_keys( $field );
		if ( ! empty( $valid ) ) {
			if ( is_array( $value ) ) {
				$out = array();
				foreach ( $value as $v ) {
					if ( is_scalar( $v ) && in_array( (string) $v, $valid, true ) ) {
						$out[] = (string) $v;
					}
				}
				return array_values( array_unique( $out ) );
			}
			$v = is_scalar( $value ) ? (string) $value : '';
			return in_array( $v, $valid, true ) ? $v : '';
		}
	}

	switch ( (string) $sanitize ) {
		case 'array_url':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $v ) ) ) );
		case 'url':
			return is_array( $value )
				? array_map( 'esc_url_raw', array_map( 'strval', $value ) )
				: esc_url_raw( (string) $value );
		case 'email':
			$e = sanitize_email( (string) $value );
			return is_email( $e ) ? $e : '';
		case 'wysiwyg':
			return wp_kses_post( (string) $value );
		case 'textarea':
			return sanitize_textarea_field( (string) $value );
		case 'number':
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			// Keep an integer literal too large for PHP_INT_MAX as digits; `+ 0` would promote it to a
			// float and store it in scientific notation, so the stored value stops matching the input.
			if ( is_string( $value ) && preg_match( '/^[+-]?\d+$/', $value ) && ! is_int( $value + 0 ) ) {
				return $value;
			}
			return $value + 0;
		case 'integer':
			return absint( $value );
		case 'bool':
			// 'false' arrives from form posts and JSON round trips, and PHP treats a non-empty string as truthy.
			return ( $value && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		case 'array_int':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_values( array_filter( array_map( 'absint', $v ) ) );
		case 'array_text':
			$v = is_array( $value ) ? $value : ( ( '' === $value || null === $value ) ? array() : array( $value ) );
			return array_map( 'sanitize_text_field', array_map( 'strval', $v ) );
		case 'raw':
			return $value; // Complex/nested shapes — not exposed to REST; left to the plugin's own save path.
		case 'text':
		default:
			return is_array( $value )
				? array_map( 'sanitize_text_field', array_map( 'strval', $value ) )
				: sanitize_text_field( (string) $value );
	}
}

/**
 * Resolve which meta object types a field group targets, from its location rules.
 *
 * @param mixed $location_rules Stored location_rules (flat or nested groups of {param,operator,value}).
 * @return string[] Subset of: post, user, term, comment.
 */
function nxt_group_meta_object_types( $location_rules ) {
	$types = array();
	if ( is_array( $location_rules ) && ! empty( $location_rules ) ) {
		$nested = isset( $location_rules[0] ) && is_array( $location_rules[0] ) && ! isset( $location_rules[0]['param'] );
		$groups = $nested ? $location_rules : array( $location_rules );
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
					continue;
				}
				$p = sanitize_key( $rule['param'] );
				if ( in_array( $p, array( 'user_role', 'current_user', 'user_form' ), true ) ) {
					$types['user'] = true;
				} elseif ( 'taxonomy' === $p ) {
					$types['term'] = true;
				} elseif ( 'comment' === $p ) {
					$types['comment'] = true;
				} elseif ( in_array( $p, array( 'widget', 'nav_menu', 'nav_menu_item' ), true ) ) {
					continue; // No object meta table for these.
				} else {
					$types['post'] = true; // post_type/post/page/status/format/template/etc. + ambiguous.
				}
			}
		}
	}
	if ( empty( $types ) ) {
		$types['post'] = true; // No (or non-meta) location → default to post.
	}
	return array_keys( $types );
}

/**
 * Post types a group is pinned to (every OR-set has post_type ==); empty means not pinned.
 *
 * @param array $location_rules Location rules (flat or nested).
 * @return string[]
 */
function nxt_group_meta_post_subtypes( $location_rules ) {
	if ( ! is_array( $location_rules ) || empty( $location_rules ) ) {
		return array();
	}
	$nested = isset( $location_rules[0] ) && is_array( $location_rules[0] ) && ! isset( $location_rules[0]['param'] );
	$groups = $nested ? $location_rules : array( $location_rules );
	$types  = array();
	foreach ( $groups as $group ) {
		$found = '';
		foreach ( (array) $group as $rule ) {
			if ( is_array( $rule ) && isset( $rule['param'], $rule['value'] ) && 'post_type' === $rule['param'] && '==' === ( $rule['operator'] ?? '==' ) ) {
				$found = sanitize_key( $rule['value'] );
			}
		}
		if ( '' === $found || 'all' === $found ) {
			return array();
		}
		$types[ $found ] = true;
	}
	return array_keys( $types );
}

/**
 * Register every top-level field's value meta with core for REST, the block editor and Block Bindings.
 * Done per object type the field's group actually targets.
 *
 * @return void
 */
function nxt_register_group_field_meta() {
	if ( ! function_exists( 'nxt_group_definitions' ) || ! function_exists( 'nxt_build_meta_key' ) ) {
		return;
	}
	$groups = nxt_group_definitions( array() );
	if ( empty( $groups ) || ! is_array( $groups ) ) {
		return;
	}

	// REST write gate per object type — the correct edit_* meta-cap for each.
	$auth_caps = array(
		'post'    => 'edit_post',
		'user'    => 'edit_user',
		'term'    => 'edit_term',
		'comment' => 'edit_comment',
	);

	$seen = array();
	foreach ( $groups as $group ) {
		if ( ! is_array( $group ) || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			continue;
		}
		$group_key = isset( $group['key'] ) ? (string) $group['key'] : '';
		if ( '' === $group_key ) {
			continue;
		}
		// Group-level "Show in REST" opt-out.
		$group_in_rest = ! array_key_exists( 'show_in_rest', $group )
			|| filter_var( $group['show_in_rest'], FILTER_VALIDATE_BOOLEAN );

		$object_types = nxt_group_meta_object_types( isset( $group['location_rules'] ) ? $group['location_rules'] : array() );

		foreach ( $group['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$cfg = nxt_field_meta_rest_config( $field );

			// VULN-002 hardening: honor a per-field opt-out.
			if ( array_key_exists( 'show_in_rest', $field ) && ! filter_var( $field['show_in_rest'], FILTER_VALIDATE_BOOLEAN ) ) {
				$cfg['show_in_rest'] = false;
			}

			// The group-level opt-out wins over any per-field default.
			if ( ! $group_in_rest ) {
				$cfg['show_in_rest'] = false;
			}

			foreach ( $object_types as $object_type ) {
				$cap      = isset( $auth_caps[ $object_type ] ) ? $auth_caps[ $object_type ] : 'edit_post';
				$meta_key = nxt_build_meta_key( $object_type, $group_key, $field['name'] );
				$dedupe   = $object_type . '|' . $meta_key;
				if ( '' === $meta_key || isset( $seen[ $dedupe ] ) ) {
					continue; // Dedupe: same key on same object type registered once.
				}
				$seen[ $dedupe ] = true;

				/**
				 * Whether to register this field's value meta with core. Return false to keep the
				 * field out of the REST API / block editor entirely.
				 *
				 * @param bool   $register    Default true.
				 * @param array  $field       Field config.
				 * @param string $meta_key    The meta key.
				 * @param string $object_type post | user | term | comment.
				 */
				if ( ! apply_filters( 'nxt/register_field_meta', true, $field, $meta_key, $object_type ) ) {
					continue;
				}

				$sanitize     = $cfg['sanitize'];
				$field_config = $field;
				$args         = array(
					'single'            => true,
					'type'              => $cfg['type'],
					'description'       => isset( $field['label'] ) ? (string) $field['label'] : '',
					// Shape-aware: bool exposes as a plain type; array types carry an item schema;
					// object/nested shapes are registered with false (sanitized but not REST-exposed).
					'show_in_rest'      => $cfg['show_in_rest'],
					'sanitize_callback' => function ( $value ) use ( $sanitize, $field_config ) {
						return nxt_sanitize_registered_field_meta_value( $value, $sanitize, $field_config );
					},
					'auth_callback'     => function ( $allowed, $meta_key, $object_id ) use ( $cap ) {
						return current_user_can( $cap, (int) $object_id );
					},
				);

				/**
				 * Filter the register_meta() args for a group field before registration (e.g. to
				 * change show_in_rest, type, or the auth/sanitize callbacks).
				 *
				 * @param array  $args        register_meta() args.
				 * @param array  $field       Field config.
				 * @param string $meta_key    The meta key.
				 * @param string $object_type post | user | term | comment.
				 */
				$args = apply_filters( 'nxt/register_field_meta_args', $args, $field, $meta_key, $object_type );

				// Scope post meta to the group's post types so it does not leak into every post type's REST schema.
				$subtypes = ( 'post' === $object_type ) ? nxt_group_meta_post_subtypes( isset( $group['location_rules'] ) ? $group['location_rules'] : array() ) : array();
				if ( empty( $subtypes ) ) {
					register_meta( $object_type, $meta_key, $args );
				}
				foreach ( $subtypes as $subtype ) {
					register_meta( $object_type, $meta_key, array_merge( $args, array( 'object_subtype' => $subtype ) ) );
				}
			}
		}
	}
}
add_action( 'init', 'nxt_register_group_field_meta', 20 );

/**
 * White-label state for Content Fields: brand replaces "Nexter", hide_ads drops upgrade prompts.
 * Same Pro gate as the dashboard; brand is '' when the stock name applies.
 *
 * @return array{brand:string,hide_ads:bool}
 */
function nxt_ct_white_label() {
	$wl = ( ( defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' ) ) && class_exists( 'Nxt_Options' ) ) ? Nxt_Options::white_label() : false;
	return array(
		'brand'    => ( is_array( $wl ) && ! empty( $wl['brand_name'] ) ) ? sanitize_text_field( $wl['brand_name'] ) : '',
		'hide_ads' => is_array( $wl ) && isset( $wl['nxt_plugin_ads'] ) && 'on' === $wl['nxt_plugin_ads'],
	);
}

/**
 * Prefix for Content Fields admin notices, with the white-label brand when one is set.
 */
function nxt_ct_notice_label() {
	$brand = nxt_ct_white_label()['brand'];
	/* translators: %s: white-label brand name */
	return '' !== $brand ? sprintf( __( '%s Content Types:', 'nexter-extension' ), $brand ) : __( 'Nexter Content Types:', 'nexter-extension' );
}

/**
 * Swaps "Nexter" for the white-label brand in importer warnings and skip reasons.
 */
function nxt_ct_brand_text( $text ) {
	static $brand = null;
	if ( null === $brand ) {
		$brand = nxt_ct_white_label()['brand'];
	}
	return '' !== $brand ? str_replace( 'Nexter', $brand, $text ) : $text;
}
