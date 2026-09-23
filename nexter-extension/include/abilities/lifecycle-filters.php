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
			++$version;
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
	 * STEP 4. The one line appended to every write's description.
	 *
	 * Deliberately not translated: it names ability names and argument keys, and a translated
	 * ability name is a broken ability name.
	 *
	 * @return string
	 */
	function nexter_ext_ability_write_sentence() {
		return 'Writes to the site. Check it with `dry_run: true` before persisting. '
			. 'Send writes one at a time, never concurrently. Include the `if_match` value from '
			. 'whichever read you built this payload from. The response carries an `undo` block — '
			. 'store it; that is how this change is rolled back.';
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

				// Keep the annotation consistent with the mode, so a client reading either gets the
				// same answer.
				if ( ! isset( $args['meta']['annotations'] ) || ! is_array( $args['meta']['annotations'] ) ) {
					$args['meta']['annotations'] = array();
				}
				if ( ! array_key_exists( 'readonly', $args['meta']['annotations'] ) ) {
					$args['meta']['annotations']['readonly'] = ( 'read' === $policy['mode'] );
				}

				// STEP 4. Append the standing sentence to every write's description. It goes
				// ALONGSIDE the per-ability annotations.instructions, never in place of them — those
				// name each ability's own traps and are the better guidance.
				if ( 'write' === $policy['mode'] && isset( $args['description'] ) && is_string( $args['description'] ) ) {
					$sentence = nexter_ext_ability_write_sentence();
					if ( false === strpos( $args['description'], 'Send writes one at a time' ) ) {
						$args['description'] = rtrim( $args['description'] ) . ' ' . $sentence;
					}
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
							return $lock;
						}
						try {
							return $inner( $input );
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

				// Nexter Fields definitions are JSON files, not posts.
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
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( $is_read( $name ) && $ttl( $name ) > 0 ) {
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
