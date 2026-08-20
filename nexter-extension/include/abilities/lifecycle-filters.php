<?php
/**
 * Nexter Extension — Abilities API 7.1 enhancements.
 *
 * Attaches to the WordPress 7.1 Abilities API extension points so every
 * nexter/* ability is more discoverable, cheaper and more reliable for AI
 * agents — without editing any individual ability definition. Loaded from
 * class-nxt-mcp-abilities.php during register_abilities(), BEFORE the ability
 * files register, so wp_register_ability_args applies to every one of them.
 *
 *   A. Unified `public` flag        — wp_register_ability_args
 *   B. Loose-boolean normalisation  — wp_ability_normalize_input
 *   C. Semantic input validation    — wp_ability_validate_input
 *   D. Usage telemetry (opt-in)     — wp_ability_invoked
 *   E. Read-result caching          — wp_pre_execute_ability
 *   F. Central Pro gating           — wp_ability_permission_result
 *   G. Cache fill + write busting   — wp_ability_execute_result
 *
 * No-op before WordPress 7.1 (WP_Filter_Sentinel and the filters do not exist).
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

		// Reads are cacheable; writes must never be. Anything not a read is treated as a write.
		$is_read  = static fn( $n ) => is_string( $n ) && ( str_starts_with( $n, 'nexter/get-' ) || str_starts_with( $n, 'nexter/list-' ) );
		$is_write = static fn( $n ) => nexter_ext_ability_is_nexter( $n ) && ! $is_read( $n );

		// Write abilities that address an existing record by id.
		$targets_id = static fn( $n ) => is_string( $n ) && (bool) preg_match( '#^nexter/(update|delete|toggle)-#', $n );

		$ttl  = static fn( $n ) => (int) apply_filters( 'nexter_ability_cache_ttl', 15 * MINUTE_IN_SECONDS, $n );
		$ckey = static fn( $n, $i ) => 'nxt_ab_' . md5( nexter_ext_ability_cache_version() . '|' . $n . '|' . wp_json_encode( $i ) );

		// A. Unified public flag — keep every Nexter ability discoverable under 7.1.
		add_filter(
			'wp_register_ability_args',
			static function ( $args, $name ) {
				if ( nexter_ext_ability_is_nexter( $name ) ) {
					if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
						$args['meta'] = array();
					}
					if ( ! isset( $args['meta']['public'] ) ) {
						$args['meta']['public'] = true;
					}
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

		// E. Serve read-only abilities from cache (short-circuit).
		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, $name, $input ) use ( $is_read, $ckey, $ttl ) {
				if ( ! $is_read( $name ) || $ttl( $name ) <= 0 ) {
					return $pre;
				}
				$hit = get_transient( $ckey( $name, $input ) );

				return ( false !== $hit ) ? $hit : $pre;
			},
			10,
			3
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
