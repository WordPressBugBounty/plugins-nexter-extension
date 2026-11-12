<?php
/**
 * Nexter Extension — Abilities API 7.1 enhancements.
 *
 * @package Nexter_Extension
 * @since   4.7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nexter_ext_register_ability' ) ) {
	/**
	 * Register a Nexter ability through the house envelope.
	 *
	 * The `wp_register_ability_args` filter below stays in place and remains the backstop, so an
	 * ability registered any other way still gets the policy — this wrapper is not a gate. What it
	 * adds is what a filter cannot: the constants every ability repeated live in one place, and a
	 * missing `meta.mode` is reported at registration instead of falling back to a guess and being
	 * discovered by an audit later.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability definition, minus whatever this fills in.
	 * @return mixed Whatever wp_register_ability() returns.
	 */
	function nexter_ext_register_ability( $name, array $args ) {
		if ( ! isset( $args['category'] ) ) {
			$args['category'] = 'nexter-extension';
		}

		// Which file registered this, so the AI Access screen can group abilities without anyone
		// maintaining a list. A new ability lands in its file's group by existing; a declared group
		// would be one more thing to forget, and forgetting it would hide the ability.
		if ( ! isset( $args['meta']['source_file'] ) ) {
			$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( isset( $caller[0]['file'] ) ) {
				$args['meta']['source_file'] = basename( (string) $caller[0]['file'], '.php' );
			}
		}
		if ( ! isset( $args['permission_callback'] ) ) {
			$args['permission_callback'] = 'nexter_mcp_permission_callback';
		}

		// An object schema with nothing required must accept a call with no input (REST GET sends none).
		if ( isset( $args['input_schema']['type'] ) && 'object' === $args['input_schema']['type']
			&& ! array_key_exists( 'default', $args['input_schema'] ) && empty( $args['input_schema']['required'] ) ) {
			$args['input_schema']['default'] = array();
		}

		// Declared, not derived. The filter can fall back to the annotation and then the name prefix,
		// but that fallback exists so nothing broke the day it landed — it is not a licence to omit.
		if ( ! isset( $args['meta']['mode'] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html( sprintf( '%s does not declare meta.mode; the lifecycle has to guess whether it writes.', (string) $name ) ),
				'4.8.0.8'
			);
		}

		return wp_register_ability( $name, $args );
	}
}

if ( ! function_exists( 'nexter_ext_ability_is_nexter' ) ) {
	/**
	 * Whether an ability name belongs to this plugin.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	function nexter_ext_ability_is_nexter( $name ) {
		return is_string( $name ) && str_starts_with( $name, 'nexter/' );
	}
}

if ( ! function_exists( 'nexter_ext_ability_cache_version' ) ) {
	/**
	 * Read-cache generation. Bumped by any write ability so cached reads expire at once.
	 *
	 * @param bool $bump Whether to advance the generation.
	 * @return int
	 */
	function nexter_ext_ability_cache_version( $bump = false ) {
		$version = (int) get_option( 'nexter_ability_cache_version', 1 );

		if ( $bump ) {
			// A millisecond stamp, so two concurrent writes cannot both land on the same next value.
			$version = max( $version + 1, (int) floor( microtime( true ) * 1000 ) );
			update_option( 'nexter_ability_cache_version', $version, false );
		}

		return $version;
	}
}

if ( ! function_exists( 'nexter_ext_ability_policy' ) ) {
	/**
	 * Declared policy for every Nexter ability, captured at registration.
	 *
	 * STEP 1. Policy used to be parsed out of the ability NAME, which failed silently both ways: a
	 * read not called get-/list- bumped the cache generation on every call and destroyed every other
	 * read's cache, and a write called get-* was served from cache and appeared to do nothing.
	 *
	 * @param string|null $name Ability name to read, or null to read the whole map.
	 * @param array|null  $set  Internal: policy to store for $name.
	 * @return array
	 */
	function nexter_ext_ability_policy( $name = null, $set = null ) {
		static $map = array();

		if ( null !== $name && null !== $set ) {
			$map[ $name ] = $set;
			return $set;
		}
		if ( null === $name ) {
			return $map;
		}
		return isset( $map[ $name ] ) ? $map[ $name ] : array();
	}
}

if ( ! function_exists( 'nexter_ext_ability_derive_policy' ) ) {
	/**
	 * Resolve mode + targets_object for one ability, preferring what it declares.
	 *
	 * The two fallbacks exist so nothing changes behaviour on the day this lands: an ability that
	 * declares nothing is classified exactly as the old name contracts classified it.
	 *
	 * @param array  $args Registration args.
	 * @param string $name Ability name.
	 * @return array{mode:string,targets_object:bool,declared:bool}
	 */
	function nexter_ext_ability_derive_policy( $args, $name ) {
		$meta     = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
		$declared = isset( $meta['mode'] ) && in_array( $meta['mode'], array( 'read', 'validate', 'write' ), true );

		if ( $declared ) {
			$mode = $meta['mode'];
		} else {
			$readonly = null;
			if ( isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && array_key_exists( 'readonly', $meta['annotations'] ) ) {
				$readonly = (bool) $meta['annotations']['readonly'];
			}
			if ( null !== $readonly ) {
				$mode = $readonly ? 'read' : 'write';
			} else {
				// Last resort: the pre-Step-1 name contract, so an undeclared ability keeps its
				// current classification rather than silently flipping.
				$is_read = str_starts_with( (string) $name, 'nexter/get-' ) || str_starts_with( (string) $name, 'nexter/list-' );
				$mode    = $is_read ? 'read' : 'write';
			}
		}

		if ( array_key_exists( 'targets_object', $meta ) ) {
			$targets = (bool) $meta['targets_object'];
		} else {
			$targets = (bool) preg_match( '#^nexter/(update|delete|toggle)-#', (string) $name );
		}

		return array(
			'mode'           => $mode,
			'targets_object' => $targets,
			'declared'       => $declared,
		);
	}
}

if ( ! function_exists( 'nexter_ext_ability_mode' ) ) {
	/**
	 * read | validate | write for a registered Nexter ability.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	function nexter_ext_ability_mode( $name ) {
		$policy = nexter_ext_ability_policy( $name );
		if ( isset( $policy['mode'] ) ) {
			return $policy['mode'];
		}
		// Not seen by the registration filter (e.g. registered before this file loaded).
		return nexter_ext_ability_derive_policy( array(), $name )['mode'];
	}
}

if ( ! function_exists( 'nexter_ext_ability_targets_object' ) ) {
	/**
	 * Whether an ability addresses an arbitrary object id and so needs a per-object check.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	function nexter_ext_ability_targets_object( $name ) {
		$policy = nexter_ext_ability_policy( $name );
		if ( isset( $policy['targets_object'] ) ) {
			return (bool) $policy['targets_object'];
		}
		return nexter_ext_ability_derive_policy( array(), $name )['targets_object'];
	}
}

if ( ! function_exists( 'nexter_ext_ability_write_sentence' ) ) {
	/**
	 * STEP 4. The standing line appended to a write's description, built from what that write
	 * actually accepts.
	 *
	 * Every clause is gated on the feature existing, because this is the one channel a model
	 * cannot skip: telling all 40 writes to send `if_match` when nothing accepts it earns a
	 * validation error on every obedient call, and advertising `dry_run` on a write that has no
	 * such argument teaches the model that a safety check it never performed was performed.
	 * Add a clause back here the same day the feature lands, not before.
	 *
	 * Deliberately not translated: it names argument keys, and a translated argument key is a
	 * broken argument key.
	 *
	 * @param array  $args Registration args, read for the arguments this ability declares.
	 * @param string $name Ability name, read for whether anything reverses it.
	 * @return string
	 */
	function nexter_ext_ability_write_sentence( $args = array(), $name = '' ) {
		$props = array();
		if ( isset( $args['input_schema']['properties'] ) && is_array( $args['input_schema']['properties'] ) ) {
			$props = array_keys( $args['input_schema']['properties'] );
		}

		$sentence = 'Writes to the site. Send writes one at a time, never concurrently.';

		if ( in_array( 'dry_run', $props, true ) ) {
			$sentence .= ' Check it with `dry_run: true` before persisting.';
		}
		if ( in_array( 'confirm', $props, true ) ) {
			$sentence .= ' If the response returns `requires_confirmation: true`, relay it to the user and only re-send with `confirm: true` once they agree.';
		}
		if ( in_array( 'if_match', $props, true ) ) {
			$sentence .= ' Include the `if_match` value from whichever read you built this payload from.';
		}
		if ( '' !== $name && '' !== nexter_ext_ability_reverses( $name ) ) {
			$sentence .= ' The response carries an `undo` block: the ability and arguments that put this back. Keep it.';
		}

		return $sentence;
	}
}

if ( ! function_exists( 'nexter_ext_ability_target_post' ) ) {
	/**
	 * The post id an ability call is addressing, or 0 when it cannot be resolved.
	 *
	 * Numeric ids only: snippet ids are slugs such as "8-my-snippet", and casting one to int then
	 * hashing that post compares the caller against a record it never read.
	 *
	 * @param array $input Ability input.
	 * @return int
	 */
	function nexter_ext_ability_target_post( $input ) {
		if ( ! is_array( $input ) ) {
			return 0;
		}

		if ( isset( $input['id'] ) ) {
			$id = $input['id'];
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
				return (int) $id > 0 ? (int) $id : 0;
			}
			return 0;
		}

		// A group is addressed by key; the module owns the key-to-definition lookup.
		if ( isset( $input['group'] ) && function_exists( 'nexter_mcp_ct_locate_group' ) ) {
			$definition = nexter_mcp_ct_locate_group( $input['group'] );
			if ( is_array( $definition ) && ! empty( $definition['id'] ) ) {
				return (int) $definition['id'];
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'nexter_ext_ability_canonical' ) ) {
	/**
	 * Deep key-sorted copy, so two equal structures hash equal regardless of insertion order.
	 *
	 * @param mixed $value Value to canonicalise.
	 * @return mixed
	 */
	function nexter_ext_ability_canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = nexter_ext_ability_canonical( $v );
		}
		ksort( $out );
		return $out;
	}
}

if ( ! function_exists( 'nexter_ext_ability_state_hash' ) ) {
	/**
	 * A hash of the stored state an ability call addresses, or '' when there is nothing to hash.
	 *
	 * Two strategies, because the two families store state differently. Snippets and templates ARE
	 * posts. Content Fields definitions are NOT: their ids are synthetic, get_post() on one returns
	 * null, so a post-based hash silently produced '' for exactly the abilities that advertise
	 * if_match — an argument accepted and never checked, which is worse than no argument.
	 *
	 * Hashes the whole stored configuration rather than the row's last_update, which has one-second
	 * granularity and so cannot separate two writes inside the same second.
	 *
	 * @param array $input      Ability input.
	 * @param bool  $definition Whether the id is known to name a Content Fields definition.
	 * @return string
	 */
	function nexter_ext_ability_state_hash( $input, $definition = false ) {
		$state = nexter_ext_ability_state( $input, $definition );
		return $state['hash'];
	}
}

if ( ! function_exists( 'nexter_ext_ability_state' ) ) {
	/**
	 * The stored state an ability call addresses: its hash, and the configuration itself when the
	 * module can hand it over.
	 *
	 * One fetch serves both the if_match comparison and the undo snapshot; computing them separately
	 * doubled the work and could read two different versions of the same record.
	 *
	 * @param array $input      Ability input.
	 * @param bool  $definition Whether the id is known to name a Content Fields definition.
	 * @return array{hash:string,data:mixed}
	 */
	function nexter_ext_ability_state( $input, $definition = false ) {
		$none = array(
			'hash' => '',
			'data' => null,
		);

		$id = nexter_ext_ability_target_post( $input );
		if ( $id <= 0 ) {
			return $none;
		}

		// A definition id is synthetic and can equal a real post's ID. Hashing that post instead would
		// make if_match pass forever, because the post never changes when the definition does.
		$post = $definition ? null : get_post( $id );
		if ( null !== $post ) {
			$meta = get_post_meta( $id );
			return array(
				'hash' => substr( md5( (string) $post->post_modified_gmt . '|' . wp_json_encode( nexter_ext_ability_canonical( is_array( $meta ) ? $meta : array() ) ) ), 0, 16 ),
				// A post's meta is not a payload any write accepts, so there is nothing to replay.
				'data' => null,
			);
		}

		if ( ! function_exists( 'nexter_mcp_ct_rest' ) ) {
			return $none;
		}

		// Field groups and CPT/taxonomy definitions live behind different routes; try both rather
		// than inferring from the ability name, which would break the moment one is renamed.
		foreach ( array( '/definitions/' . $id, '/field-groups/' . $id ) as $route ) {
			$result = nexter_mcp_ct_rest( 'GET', $route );
			if ( ! empty( $result['success'] ) && isset( $result['data'] ) ) {
				return array(
					'hash' => substr( md5( (string) wp_json_encode( nexter_ext_ability_canonical( $result['data'] ) ) ), 0, 16 ),
					'data' => $result['data'],
				);
			}
		}

		return $none;
	}
}
if ( ! function_exists( 'nexter_ext_ability_addresses_definition' ) ) {
	/**
	 * Whether a write addresses a Content Fields definition rather than a post.
	 *
	 * Read from the module's own write list, so a new definition write is covered by being registered there.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	function nexter_ext_ability_addresses_definition( $name ) {
		return function_exists( 'nexter_mcp_ct_write_abilities' )
			&& in_array( $name, (array) nexter_mcp_ct_write_abilities(), true );
	}
}
if ( ! function_exists( 'nexter_ext_ability_addresses_record' ) ) {
	/**
	 * Whether this ability's declared input can be resolved to a single stored record.
	 *
	 * if_match is only offered where it can actually be verified. Advertising it on an ability whose
	 * state we cannot hash would accept the argument and silently ignore it, which is worse than not
	 * offering it at all — the caller believes it is protected.
	 *
	 * @param array $args Registration args.
	 * @return bool
	 */
	function nexter_ext_ability_addresses_record( $args ) {
		if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) {
			return false;
		}
		$props = array_keys( $args['input_schema']['properties'] );

		return in_array( 'id', $props, true ) || in_array( 'group', $props, true );
	}
}
if ( ! function_exists( 'nexter_ext_ability_reverses' ) ) {
	/**
	 * The ability that undoes another, or '' when nothing reverses it.
	 *
	 * Declared rather than guessed. An undo descriptor that does not work is worse than an honest
	 * "none": the caller stops keeping its own safety net because it believes one exists.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	function nexter_ext_ability_reverses( $name ) {
		$map = array(
			// A definition write is reversed by re-applying the configuration we captured first.
			'nexter/update-cpt'              => 'nexter/update-cpt',
			'nexter/update-taxonomy'         => 'nexter/update-taxonomy',
			'nexter/update-field-group'      => 'nexter/update-field-group',
			// Trashing is reversed by restoring; toggling by toggling back.
			'nexter/delete-content-type'     => 'nexter/restore-content-type',
			'nexter/restore-content-type'    => 'nexter/delete-content-type',
			'nexter/toggle-content-type'     => 'nexter/toggle-content-type',
			'nexter/toggle-field-group'      => 'nexter/toggle-field-group',
			// Snippet and template toggles are deliberately absent: they are driven by `status`, and
			// the previous status is not recoverable from the snapshot, so any descriptor built for
			// them would be a guess. They report "none" with that reason instead.
		);

		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}
}

if ( ! function_exists( 'nexter_ext_ability_exposure' ) ) {
	/**
	 * Which Nexter abilities are currently exposed to MCP, and which are not.
	 *
	 * Reads the registry rather than the declarations, so it reports what the
	 * `nexter_ability_mcp_public` filter actually decided — which is the only answer worth showing
	 * on a screen that claims to say what an assistant can reach.
	 *
	 * @return array{public:string[],closed:string[],total:int}
	 */
	function nexter_ext_ability_exposure() {
		$public = array();
		$closed = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( ! nexter_ext_ability_is_nexter( $name ) ) {
				continue;
			}
			$meta = $ability->get_meta();
			if ( ! empty( $meta['mcp']['public'] ) ) {
				$public[] = $name;
			} else {
				$closed[] = $name;
			}
		}

		sort( $public );
		sort( $closed );

		return array(
			'public' => $public,
			'closed' => $closed,
			'total'  => count( $public ) + count( $closed ),
		);
	}
}

if ( ! function_exists( 'nexter_ext_ability_batch' ) ) {
	/**
	 * The batch id the current write belongs to, if any.
	 *
	 * Set by nexter/apply-changes for the length of one batch so every journal row it produces can be
	 * found together later. Without it a batch is indistinguishable from several unrelated writes that
	 * happened to arrive at the same second, and reviewing a whole task as a unit is impossible.
	 *
	 * @param string|null $set Batch id to enter, '' to leave, or null to read.
	 * @return string
	 */
	function nexter_ext_ability_batch( $set = null ) {
		static $current = '';

		if ( null !== $set ) {
			$current = (string) $set;
		}

		return $current;
	}
}

if ( ! function_exists( 'nexter_ext_ability_write_succeeded' ) ) {
	/**
	 * Whether a write result represents a change that actually landed.
	 *
	 * The module reports a refusal as `success => false` in an ordinary array rather than a WP_Error,
	 * so treating "not a WP_Error" as success journals refusals as changes and hands back an undo
	 * descriptor for a write that never happened.
	 *
	 * @param mixed $result Callback result.
	 * @return bool
	 */
	function nexter_ext_ability_write_succeeded( $result ) {
		if ( is_wp_error( $result ) ) {
			return false;
		}
		if ( is_array( $result ) && array_key_exists( 'success', $result ) ) {
			return (bool) $result['success'];
		}

		return true;
	}
}

if ( ! function_exists( 'nexter_ext_ability_config_payload' ) ) {
	/**
	 * The writable configuration inside a definition record.
	 *
	 * A read returns an envelope — id, title, kind, last_update, status, content_count and the
	 * configuration under `data` — while the update abilities take that inner configuration as their
	 * own `data`. Handing the envelope back produced an undo descriptor that replayed cleanly and
	 * restored nothing, which is the exact failure an undo feature exists to prevent.
	 *
	 * @param mixed $record Record as a read returned it.
	 * @return array|null
	 */
	function nexter_ext_ability_config_payload( $record ) {
		if ( ! is_array( $record ) ) {
			return null;
		}
		if ( isset( $record['data'] ) && is_array( $record['data'] ) ) {
			return $record['data'];
		}

		return $record;
	}
}

if ( ! function_exists( 'nexter_ext_ability_undo_descriptor' ) ) {
	/**
	 * How to reverse a write that just landed, or an honest refusal to claim.
	 *
	 * @param string $name   Ability that ran.
	 * @param array  $input  Input it ran with.
	 * @param array  $before Snapshot taken before it ran.
	 * @return array
	 */
	function nexter_ext_ability_undo_descriptor( $name, $input, $before ) {
		$reverses = nexter_ext_ability_reverses( $name );
		if ( '' === $reverses ) {
			return array(
				'strategy' => 'none',
				'reason'   => 'Nothing reverses this write automatically. Keep your own copy of what it replaced.',
			);
		}

		$id = nexter_ext_ability_target_post( $input );
		if ( $id <= 0 ) {
			return array(
				'strategy' => 'none',
				'reason'   => 'The record this changed could not be identified, so no reversal can be addressed to it.',
			);
		}

		// Re-applying the captured configuration only works when we actually captured one.
		if ( $reverses === $name && 0 === strpos( $name, 'nexter/update-' ) ) {
			$payload = nexter_ext_ability_config_payload( $before['data'] );
			if ( ! is_array( $payload ) ) {
				return array(
					'strategy' => 'none',
					'reason'   => 'The previous configuration could not be read before the write, so it cannot be put back.',
				);
			}
			return array(
				'strategy' => 'restore-config',
				'ability'  => $reverses,
				'input'    => array(
					'id'      => $id,
					'data'    => $payload,
					'confirm' => true,
				),
			);
		}

		// A toggle reverses by sending the opposite of what was sent, which we know exactly.
		if ( 0 === strpos( $name, 'nexter/toggle-' ) ) {
			if ( ! is_array( $input ) || ! array_key_exists( 'active', $input ) ) {
				return array(
					'strategy' => 'none',
					'reason'   => 'This toggle did not state the value it set, so the previous one is not known.',
				);
			}
			return array(
				'strategy' => 'toggle-back',
				'ability'  => $reverses,
				'input'    => array(
					'id' => $id,
					// Not nxt_to_bool(): that lives in the Fields module, and this file also loads in
					// the build that ships without it.
					'active' => ! filter_var( $input['active'], FILTER_VALIDATE_BOOLEAN ),
				),
			);
		}

		return array(
			'strategy' => 'call',
			'ability'  => $reverses,
			'input'    => array( 'id' => $id ),
		);
	}
}

if ( ! function_exists( 'nexter_ext_ability_record_write' ) ) {
	/**
	 * Append one write to the journal.
	 *
	 * Records the hashes rather than the payload: an input can carry field values, and a recent
	 * activity list is not the place to keep a second copy of someone's content.
	 *
	 * @param string $name   Ability that ran.
	 * @param array  $input  Input it ran with.
	 * @param array  $before Snapshot taken before it ran.
	 * @param mixed  $result Callback result.
	 * @return void
	 */
	function nexter_ext_ability_record_write( $name, $input, $before, $result ) {
		$ok = nexter_ext_ability_write_succeeded( $result );
		$is_definition = nexter_ext_ability_addresses_definition( $name );

		nexter_ext_ability_journal(
			'append',
			array(
				'time'        => time(),
				'user'        => get_current_user_id(),
				'ability'     => $name,
				'batch'       => nexter_ext_ability_batch(),
				'target'      => nexter_ext_ability_target_post( $input ),
				'hash_before' => $before['hash'],
				'hash_after'  => $ok ? nexter_ext_ability_state_hash( $input, $is_definition ) : $before['hash'],
				'ok'          => $ok,
				'error'       => is_wp_error( $result ) ? $result->get_error_code() : ( ( is_array( $result ) && ! $ok && isset( $result['error'] ) ) ? (string) $result['error'] : '' ),
			)
		);
	}
}

if ( ! function_exists( 'nexter_ext_ability_journal' ) ) {
	/**
	 * Append to, read, or clear the write journal.
	 *
	 * Bounded and option-backed on purpose: a dedicated table needs a schema version and a migration
	 * to maintain, and this has to be safe to ship before that exists. The cost is that it keeps the
	 * most recent NEXTER_ABILITY_JOURNAL_MAX entries only — it is a recent-activity record, not an
	 * audit log, and nothing should be built on the assumption that an old entry is still there.
	 * autoload is off: this must never join the options loaded on every request.
	 *
	 * @param string $op    'append' | 'list' | 'clear'.
	 * @param array  $entry Entry to append.
	 * @return array Entries for 'list', empty otherwise.
	 */
	function nexter_ext_ability_journal( $op, $entry = array() ) {
		$key = 'nxt_ability_journal';
		$max = defined( 'NEXTER_ABILITY_JOURNAL_MAX' ) ? (int) NEXTER_ABILITY_JOURNAL_MAX : 200;

		// The table is the store. What follows is the fallback for a site where it could not be
		// created — a read-only or restricted database is a real deployment, and losing the record
		// of a write is worse than keeping only the most recent 200.
		$table = class_exists( 'Nxt_Ability_Journal' ) && Nxt_Ability_Journal::table_ready();

		if ( 'clear' === $op ) {
			if ( $table ) {
				Nxt_Ability_Journal::clear();
				return array();
			}
			delete_option( $key );
			return array();
		}

		if ( $table && 'append' === $op && ! empty( $entry ) ) {
			Nxt_Ability_Journal::append( $entry );
			return array();
		}

		if ( $table && 'list' === $op ) {
			// Oldest first, matching what the option store returned, so a caller that reads this
			// directly does not have to know which store answered.
			$result = Nxt_Ability_Journal::query( array( 'limit' => 200 ) );
			return array_reverse( $result['rows'] );
		}

		$log = get_option( $key, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		if ( 'list' === $op ) {
			return array_values( $log );
		}

		if ( 'append' !== $op || empty( $entry ) ) {
			return array();
		}

		$log[] = $entry;
		if ( count( $log ) > $max ) {
			$log = array_slice( $log, -$max );
		}

		// add_option first so the row is created with autoload off; update_option keeps whatever
		// autoload the row already has, so a row created elsewhere as autoload=yes would stay that way.
		if ( ! add_option( $key, $log, '', false ) ) {
			update_option( $key, $log, false );
		}

		return array();
	}
}
if ( ! function_exists( 'nexter_ext_ability_lock' ) ) {
	/**
	 * STEP 3. Re-entrant write lock around a whole ability.
	 *
	 * Claimed with add_option(), which is a single INSERT and so atomic — the same primitive the
	 * import runner uses. A transient would be wrong here: an object-cache backend may drop the key
	 * and hand the lock to two callers at once.
	 *
	 * Re-entrancy is required, not a nicety: scaffold-content-model performs several writes inside
	 * one ability and must not deadlock against its own caller.
	 *
	 * @param string $op   'acquire' | 'release' | 'depth'.
	 * @param string $name Ability name, for the error message.
	 * @return mixed True/WP_Error on acquire, void on release, int on depth.
	 */
	function nexter_ext_ability_lock( $op, $name = '' ) {
		static $depth = 0;
		static $token = '';

		$key   = 'nxt_ability_write_lock';
		$stale = 300;

		if ( 'depth' === $op ) {
			return $depth;
		}

		if ( 'release' === $op ) {
			if ( $depth > 0 ) {
				--$depth;
			}
			if ( 0 !== $depth || '' === $token ) {
				return null;
			}
			$held = get_option( $key );
			if ( is_array( $held ) && isset( $held['token'] ) && hash_equals( (string) $held['token'], $token ) ) {
				delete_option( $key );
			}
			$token = '';
			return null;
		}

		// acquire
		if ( $depth > 0 ) {
			++$depth;   // already ours
			return true;
		}

		$now  = time();
		$mine = array(
			'token'   => wp_generate_uuid4(),
			'user'    => get_current_user_id(),
			'ability' => (string) $name,
			'started' => $now,
		);

		// autoload false: this row is written and deleted constantly and must not ride alloptions.
		if ( add_option( $key, $mine, '', false ) ) {
			$token = $mine['token'];
			$depth = 1;
			return true;
		}

		$held = get_option( $key );
		$age  = ( is_array( $held ) && isset( $held['started'] ) ) ? ( $now - (int) $held['started'] ) : PHP_INT_MAX;

		if ( $age < $stale ) {
			$other = ( is_array( $held ) && ! empty( $held['ability'] ) ) ? (string) $held['ability'] : 'another write';
			return new WP_Error(
				'nexter_ability_write_locked',
				sprintf(
					/* translators: 1: ability being called, 2: ability already running. */
					'%1$s was refused because %2$s is still writing. Issue writes one at a time, not in parallel, then retry.',
					(string) $name,
					$other
				),
				array( 'status' => 409 )
			);
		}

		// A run killed mid-flight must not lock the site out for good.
		$mine['took_over'] = true;
		update_option( $key, $mine, false );
		$token             = $mine['token'];
		$depth             = 1;
		return true;
	}
}

if ( ! function_exists( 'nexter_ext_register_ability_lifecycle' ) ) {
	/**
	 * Registers the Abilities API 7.1 enhancement filters.
	 *
	 * @return void
	 */
	function nexter_ext_register_ability_lifecycle() {
		if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
			return;
		}

		// STEP 1. Both contracts now read the DECLARED policy instead of parsing the name. The
		// get-/list- convention stays as a style rule; it stops being load-bearing.
		$mode     = static fn( $n ) => nexter_ext_ability_is_nexter( $n ) ? nexter_ext_ability_mode( $n ) : '';
		$is_read  = static fn( $n ) => 'read' === $mode( $n );
		$is_write = static fn( $n ) => 'write' === $mode( $n );

		// Abilities that address an existing record by id. Reads declare it too, but the existence
		// check below stays write-only: a read must be free to report 'nothing there' in its own shape.
		$targets_id = static fn( $n ) => $is_write( $n ) && nexter_ext_ability_targets_object( $n );

		$ttl = static fn( $n ) => (int) apply_filters( 'nexter_ability_cache_ttl', 15 * MINUTE_IN_SECONDS, $n );

		// SEC: the cache key includes the current user. wp_pre_execute_ability fires before the permission check, so a
		// shared entry would have served another user's result.
		$ckey = static fn( $n, $i ) => 'nxt_ab_' . md5( nexter_ext_ability_cache_version() . '|' . get_current_user_id() . '|' . $n . '|' . wp_json_encode( $i ) );

		// A. STEP 2. The single place policy is applied. Every Nexter ability already passes through
		// this filter, so nothing in the 35 ability files has to change to be covered — and a new
		// ability cannot be the one that forgot a guard.
		add_filter(
			'wp_register_ability_args',
			static function ( $args, $name ) {
				if ( ! nexter_ext_ability_is_nexter( $name ) ) {
					return $args;
				}
				if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
					$args['meta'] = array();
				}

				// Unified public flag — keep every Nexter ability discoverable under 7.1.
				if ( ! isset( $args['meta']['public'] ) ) {
					$args['meta']['public'] = true;
				}

				// STEP 1. Resolve and remember the policy, writing it back so anything reading the
				// registered meta sees the same answer this file uses.
				$policy                          = nexter_ext_ability_derive_policy( $args, $name );
				$args['meta']['mode']            = $policy['mode'];
				$args['meta']['targets_object']  = $policy['targets_object'];
				nexter_ext_ability_policy( $name, $policy );

				// MCP exposure, in one place so it can be closed without editing 72 files. Sits after
				// the policy so the rule can be stated in terms of the mode.
				//
				// A write is CLOSED unless the filter opens it, and its own declaration cannot reopen
				// it. Exposure defaulted to on, so activating any MCP adapter would have published
				// every delete, import and batch at once; a copy-pasted `'public' => true` in a new
				// write file must not be able to undo that quietly. Reads keep their declaration.
				if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
					$args['meta']['mcp'] = array();
				}
				$declared_public = ( 'write' !== $policy['mode'] ) && ! empty( $args['meta']['mcp']['public'] );

				$args['meta']['mcp']['public'] = (bool) apply_filters(
					'nexter_ability_mcp_public',
					$declared_public,
					$name,
					$policy['mode']
				);

				// Keep the annotation consistent with the mode, so a client reading either gets the
				// same answer.
				if ( ! isset( $args['meta']['annotations'] ) || ! is_array( $args['meta']['annotations'] ) ) {
					$args['meta']['annotations'] = array();
				}
				if ( ! array_key_exists( 'readonly', $args['meta']['annotations'] ) ) {
					$args['meta']['annotations']['readonly'] = ( 'read' === $policy['mode'] );
				}

				// TIER B. Optimistic concurrency. The argument is injected centrally rather than added
				// to 40 schemas by hand, and only where the state can actually be hashed — every
				// schema sets additionalProperties:false, so an uninjected if_match is a hard
				// validation error rather than a silently ignored one.
				$addresses_record = nexter_ext_ability_addresses_record( $args );
				if ( 'write' === $policy['mode'] && $addresses_record ) {
					$args['input_schema']['properties']['if_match'] = array(
						'type'        => 'string',
						'description' => 'The config_hash from the read this payload was built from. The write is refused if the record changed since.',
					);
				}
				// STEP 4. Append the standing sentence to every write's description. It goes
				// ALONGSIDE the per-ability annotations.instructions, never in place of them — those
				// name each ability's own traps and are the better guidance.
				if ( 'write' === $policy['mode'] && isset( $args['description'] ) && is_string( $args['description'] ) ) {
					$sentence = nexter_ext_ability_write_sentence( $args, $name );
					if ( false === strpos( $args['description'], 'Send writes one at a time' ) ) {
						$args['description'] = rtrim( $args['description'] ) . ' ' . $sentence;
					}
				}

				// TIER B. A read that addresses one record returns its config_hash. Attached centrally
				// so no read has to remember, and only onto array results — a read that returns a
				// scalar has nowhere to put it.
				if ( 'read' === $policy['mode'] && $addresses_record && isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
					$read_inner               = $args['execute_callback'];
					$args['execute_callback'] = static function ( $input = null ) use ( $read_inner ) {
						$result = $read_inner( $input );
						// Only a read that found its record: a hash on a "not found" names nothing.
						if ( is_array( $result ) && ! isset( $result['config_hash'] ) && ( ! array_key_exists( 'success', $result ) || ! empty( $result['success'] ) ) ) {
							$hash = nexter_ext_ability_state_hash( $input, isset( $result['definition'] ) || isset( $result['group'] ) );
							if ( '' !== $hash ) {
								$result['config_hash'] = $hash;
							}
						}
						return $result;
					};
				}
				// STEP 3. Wrap the callback itself, which is the only anchor that gives try/finally
				// semantics. wp_pre_execute_ability fires BEFORE the permission check and before input
				// validation, and wp_ability_execute_result does not fire when either of those aborts —
				// measured — so acquiring there leaked the lock on every refused or malformed call, and
				// a stream of unauthorised writes would have 409'd every legitimate one.
				//
				// As the callback, this runs only once the call is actually going to do work.
				if ( 'write' === $policy['mode'] && isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
					$inner                    = $args['execute_callback'];
					$args['execute_callback'] = static function ( $input = null ) use ( $inner, $name ) {
						$lock = nexter_ext_ability_lock( 'acquire', $name );
						if ( is_wp_error( $lock ) ) {
							// A lock conflict is two callers writing at once, which is exactly what the
							// journal exists to show. The state is unknown here — another write holds
							// the lock — so the entry carries no hashes rather than a guess.
							nexter_ext_ability_record_write( $name, $input, array( 'hash' => '', 'data' => null ), $lock );
							return $lock;
						}
						try {
							// TIER B. Snapshot first: the same read serves the if_match comparison and
							// the undo, and after the write the previous configuration is gone.
							$before = nexter_ext_ability_state( $input, nexter_ext_ability_addresses_definition( $name ) );

							// Inside the lock on purpose: comparing before taking it leaves a window in
							// which another caller writes between the check and this write.
							if ( is_array( $input ) && isset( $input['if_match'] ) && '' !== $input['if_match'] ) {
								$refusal = null;
								if ( '' === $before['hash'] ) {
									$refusal = new WP_Error(
										'nexter_if_match_unverifiable',
										__( 'This record no longer exists, so if_match cannot be checked. Read it again before writing.', 'nexter-extension' ),
										array( 'status' => 409 )
									);
								} elseif ( ! hash_equals( $before['hash'], (string) $input['if_match'] ) ) {
									$refusal = new WP_Error(
										'nexter_if_match_failed',
										sprintf(
											/* translators: 1: hash sent by the caller, 2: current hash. */
											__( 'This record changed since you read it (you sent %1$s, it is now %2$s). Read it again and rebuild the payload — do not retry this one.', 'nexter-extension' ),
											(string) $input['if_match'],
											$before['hash']
										),
										array( 'status' => 409 )
									);
								}

								// Journalled like any other outcome: a refusal that leaves no trace makes
								// the only_failures filter report an all-clear that was never true.
								if ( null !== $refusal ) {
									nexter_ext_ability_record_write( $name, $input, $before, $refusal );
									return $refusal;
								}

								// The inner callback never declared if_match; leaving it in trips its own validation.
								unset( $input['if_match'] );
							}

							$result = $inner( $input );
							nexter_ext_ability_record_write( $name, $input, $before, $result );

							// A dry run changed nothing, so there is nothing to reverse. Attaching an undo
							// block to it — even an honest "none" — invites the reading that something
							// happened and could not be undone.
							$was_dry = ( is_array( $result ) && ! empty( $result['dry_run'] ) );

							if ( is_array( $result ) && ! $was_dry && ! isset( $result['undo'] ) && nexter_ext_ability_write_succeeded( $result ) ) {
								$result['undo'] = nexter_ext_ability_undo_descriptor( $name, $input, $before );
							}

							return $result;
						} finally {
							nexter_ext_ability_lock( 'release', $name );
						}
					};
				}

				return $args;
			},
			10,
			2
		);

		// B. Agents send "on"/"yes"/"1" for switches; accept them instead of failing the schema.
		add_filter(
			'wp_ability_normalize_input',
			static function ( $input, $name ) {
				if ( ! nexter_ext_ability_is_nexter( $name ) || ! is_array( $input ) ) {
					return $input;
				}

				// 'status' is deliberately absent: this plugin types it as an integer enum of 0|1,
				// and coercing that to a bool made every create/update-snippet call fail validation.
				foreach ( array( 'enabled', 'active', 'switch' ) as $key ) {
					// Only word-shaped values are coerced. A numeric 0/1 is left alone so integer
					// schemas keep working.
					if ( ! array_key_exists( $key, $input ) || is_bool( $input[ $key ] ) || is_numeric( $input[ $key ] ) ) {
						continue;
					}
					$coerced = filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $coerced ) {
						$input[ $key ] = $coerced;
					}
				}

				return $input;
			},
			10,
			2
		);

		// C. Semantic input validation — fail fast, and clearly, on a missing target record.
		add_filter(
			'wp_ability_validate_input',
			static function ( $valid, $input, $name ) use ( $targets_id ) {
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}

				// Numeric ids only. Snippet ids are slugs such as "8-my-snippet", and casting one to int
				// then calling get_post() refused every legitimate snippet write.
				$id            = ( is_array( $input ) && isset( $input['id'] ) ) ? $input['id'] : null;
				$is_numeric_id = ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0;

				// Content Fields definitions are JSON files, not posts.
				if ( $targets_id( $name ) && function_exists( 'nexter_mcp_ct_write_abilities' )
					&& in_array( $name, (array) nexter_mcp_ct_write_abilities(), true ) ) {
					return $valid;
				}

				if ( $targets_id( $name ) && $is_numeric_id && null === get_post( (int) $id ) ) {
					return new WP_Error(
						'nexter_invalid_id',
						/* translators: %d: record ID. */
						sprintf( __( 'Nothing exists with ID %d, so it cannot be changed.', 'nexter-extension' ), (int) $id ),
						array( 'status' => 404 )
					);
				}

				return $valid;
			},
			10,
			3
		);

		// D. Usage telemetry (opt-in; default off to avoid a write on every call).
		add_action(
			'wp_ability_invoked',
			static function ( $name, $input, $ability ) {
				unset( $input, $ability );
				if ( ! nexter_ext_ability_is_nexter( $name ) || ! apply_filters( 'nexter_ability_telemetry', false, $name ) ) {
					return;
				}
				$stats          = (array) get_option( 'nexter_ability_usage', array() );
				$stats[ $name ] = isset( $stats[ $name ] ) ? ( (int) $stats[ $name ] + 1 ) : 1;
				update_option( 'nexter_ability_usage', $stats, false );
			},
			10,
			3
		);

		// A held lock must not outlive the request if the ability fataled inside its own callback —
		// the stale takeover is the backstop, this is the fast path.
		add_action(
			'shutdown',
			static function () {
				$guard = 0;
				while ( nexter_ext_ability_lock( 'depth' ) > 0 && $guard++ < 64 ) {
					nexter_ext_ability_lock( 'release' );
				}
			},
			0
		);

		// E. Serve read-only abilities from cache (short-circuit).
		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, $name, $input, $ability = null ) use ( $is_read, $ckey, $ttl ) {
				if ( ! $is_read( $name ) || $ttl( $name ) <= 0 ) {
					return $pre;
				}

				if ( $ability instanceof WP_Ability ) {
					$allowed = $ability->check_permissions( $input );
					if ( is_wp_error( $allowed ) || true !== $allowed ) {
						// Fall through to execute(), which produces the proper permission error.
						return $pre;
					}
				}

				$hit = get_transient( $ckey( $name, $input ) );

				return ( false !== $hit ) ? $hit : $pre;
			},
			10,
			4
		);

		// F. Central Pro gate (opt-in list of ability names).
		add_filter(
			'wp_ability_permission_result',
			static function ( $permission, $name ) {
				$pro = (array) apply_filters( 'nexter_ability_pro_list', array() );
				if ( nexter_ext_ability_is_nexter( $name ) && in_array( $name, $pro, true ) && ! defined( 'NXT_PRO_EXT' ) ) {
					return new WP_Error( 'nexter_pro_required', __( 'This feature requires Nexter Extension Pro.', 'nexter-extension' ), array( 'status' => 403 ) );
				}
				return $permission;
			},
			10,
			2
		);

		// G. Fill the read cache, and bust it whenever a write succeeds so no agent reads
		// settings it just changed.
		add_filter(
			'wp_ability_execute_result',
			static function ( $result, $name, $input ) use ( $is_read, $is_write, $ckey, $ttl ) {
				// A dry run neither fills nor busts the cache: nothing was written.
				if ( is_array( $result ) && ! empty( $result['dry_run'] ) ) {
					return $result;
				}
				$failed = is_wp_error( $result ) || ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] );

				// A failure is never cached, but a failed write still busts: batches can stop part-way.
				if ( ! $failed && $is_read( $name ) && $ttl( $name ) > 0 ) {
					set_transient( $ckey( $name, $input ), $result, $ttl( $name ) );
				} elseif ( $is_write( $name ) ) {
					// Only a write busts the read cache. A 'validate' ability no longer does, which
					// is the point: today a validator needlessly destroys every cached read.
					nexter_ext_ability_cache_version( true );
				}

				return $result;
			},
			10,
			3
		);
	}
}

nexter_ext_register_ability_lifecycle();
