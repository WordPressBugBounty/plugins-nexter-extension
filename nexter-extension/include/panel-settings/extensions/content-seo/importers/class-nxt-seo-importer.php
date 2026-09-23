<?php
/**
 * Content SEO — Import framework for third-party SEO plugin data.
 *
 * Orchestrates source importers (Yoast, Rank Math, SureRank, AIOSEO) behind four REST actions:
 * detect → dry-run → run → verify. The run is batched and resume-able: every migrated
 * post/term is stamped with a marker meta, so an interrupted import continues where it
 * stopped and a re-run never double-imports. Import is NON-destructive by design — source
 * data is never deleted here; cleanup stays a separate, explicit user action.
 *
 * Note on identity: this importer never CREATES a destination record. It writes SEO meta onto
 * posts and terms that already exist, so the source and the destination are the same row and a
 * duplicate cannot be produced no matter how often a run is retried or interrupted.
 *
 * @package Nexter Extensions
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Importer
 */
class Nexter_Content_SEO_Importer {

	const REST_NAMESPACE = 'nexter/v1';

	/**
	 * Marker meta key prefix (post meta and term meta): MARKER_PREFIX . source slug.
	 * Our own key deliberately does NOT share the source plugin's prefix, so marker rows
	 * can never make a source "detected" or be swept by a source-cleanup LIKE query.
	 */
	const MARKER_PREFIX = '_nxt_seo_imported_';

	/** Option holding per-source one-shot progress (settings/redirections done flags). */
	const STATE_OPTION = 'nexter_content_seo_import_state';

	/**
	 * Per-object list of the destination meta keys a source's import wrote.
	 *
	 * This is what makes an imported value distinguishable from one the user set themselves,
	 * which two separate problems both need: a resumed run must not credit its own half-
	 * finished work to the user, and a reset must be able to undo the import without touching
	 * anything the user typed.
	 */
	const WROTE_PREFIX = '_nxt_seo_import_wrote_';

	/**
	 * Slug of the import currently writing, set once per batch by the adapter.
	 *
	 * @var string
	 */
	private static $active_source = '';

	/**
	 * Recorded keys per object for the active source, so one batch reads each object once.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static $wrote_cache = array();

	/** Default objects per run request. Filterable — see rest_run(). */
	const DEFAULT_BATCH = 100;

	/** Seconds before a lock left behind by a killed request is treated as stale. */
	const LOCK_TTL = 120;

	/** How many times a one-shot phase may fail before the run stops re-attempting it. */
	const MAX_PHASE_ATTEMPTS = 3;

	/**
	 * Registered source importers: slug => class name.
	 *
	 * @var array<string,string>
	 */
	private static $sources = array();

	/**
	 * Boot the framework.
	 */
	public static function init() {
		$sources = array();
		if ( class_exists( 'Nexter_Content_SEO_Importer_Yoast' ) ) {
			$sources['yoast'] = 'Nexter_Content_SEO_Importer_Yoast';
		}
		if ( class_exists( 'Nexter_Content_SEO_Importer_Rankmath' ) ) {
			$sources['rankmath'] = 'Nexter_Content_SEO_Importer_Rankmath';
		}
		if ( class_exists( 'Nexter_Content_SEO_Importer_Surerank' ) ) {
			$sources['surerank'] = 'Nexter_Content_SEO_Importer_Surerank';
		}
		if ( class_exists( 'Nexter_Content_SEO_Importer_Aioseo' ) ) {
			$sources['aioseo'] = 'Nexter_Content_SEO_Importer_Aioseo';
		}

		/**
		 * Register additional SEO import sources.
		 *
		 * @param array<string,string> $sources slug => class implementing the importer contract
		 *                                      (detect/counts/dry_run/import_* static methods).
		 */
		self::$sources = apply_filters( 'nexter_content_seo_import_sources', $sources );

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ), 15 );
	}

	/**
	 * Same gate as the rest of the Content SEO module.
	 *
	 * @return bool
	 */
	public static function rest_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Routes: detect (GET), dry-run / run / reset (POST).
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import/detect',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_detect' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			)
		);

		$source_arg = array(
			'source' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import/dry-run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_dry_run' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => $source_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_run' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => array(
					'source'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'categories' => array(
						'type'     => 'array',
						'required' => false,
					),
					'batch_size' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'retry'      => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_verify' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'sample' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/import/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_reset' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => $source_arg,
			)
		);
	}

	/**
	 * Resolve a source slug to its importer class, or null.
	 *
	 * @param string $slug Source slug.
	 * @return string|null
	 */
	private static function source_class( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( self::$sources[ $slug ] ) ? self::$sources[ $slug ] : null;
	}

	/**
	 * GET /seo/import/detect — every registered source with its data counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_detect() {
		$out = array();
		foreach ( self::$sources as $slug => $class ) {
			$detected = call_user_func( array( $class, 'detect' ) );
			$row      = array(
				'source'   => $slug,
				'label'    => call_user_func( array( $class, 'label' ) ),
				'detected' => (bool) $detected,
			);
			if ( $detected ) {
				$row['counts'] = call_user_func( array( $class, 'counts' ) );
				$row['state']  = self::get_state( $slug );
			}
			$out[] = $row;
		}
		return rest_ensure_response( array( 'sources' => $out ) );
	}

	/**
	 * POST /seo/import/dry-run — full preview, zero writes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_dry_run( $request ) {
		$class = self::source_class( $request->get_param( 'source' ) );
		if ( ! $class ) {
			return new WP_Error( 'nxt_seo_import_unknown_source', __( 'Unknown import source.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		if ( ! call_user_func( array( $class, 'detect' ) ) ) {
			return new WP_Error( 'nxt_seo_import_nothing', __( 'No data from this plugin was found on the site.', 'nexter-extension' ), array( 'status' => 404 ) );
		}

		// One unreadable row in the source used to stop the user at the preview with WordPress's
		// generic critical-error page inside the admin banner, while the same failure during the
		// real import returned a partial, explained result. Preview now fails the same way the
		// import does: a valid response that names what could not be read.
		try {
			return rest_ensure_response( call_user_func( array( $class, 'dry_run' ) ) );
		} catch ( \Throwable $e ) {
			return rest_ensure_response( self::failed_preview( $request->get_param( 'source' ), $e ) );
		}
	}

	/**
	 * The preview shape to return when an adapter could not build one.
	 *
	 * Keeps every key the UI reads, so the panel renders and the user can still decide to run
	 * the import — where each phase is caught independently — instead of hitting a dead end.
	 *
	 * @param string     $slug Source slug.
	 * @param \Throwable $e    The failure.
	 * @return array<string,mixed>
	 */
	private static function failed_preview( $slug, $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Nexter SEO import preview failed for ' . $slug . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return array(
			'source'   => sanitize_key( (string) $slug ),
			'counts'   => array(),
			'settings' => array(),
			'skipped'  => array(),
			'notes'    => array(),
			'failed'   => array(
				array(
					'phase'  => 'preview',
					'reason' => __( 'The preview could not be built from this plugin\'s data. The import itself handles each step separately, so it can still run and will report whatever it cannot read.', 'nexter-extension' ),
				),
			),
		);
	}

	/**
	 * POST /seo/import/run — one batch of work. The client keeps calling until done:true.
	 *
	 * Categories: settings, postmeta, termmeta, redirections. Settings and redirections are
	 * one-shot (state-flagged); postmeta/termmeta consume one marker-driven batch per call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_run( $request ) {
		$slug  = sanitize_key( (string) $request->get_param( 'source' ) );
		$class = self::source_class( $slug );
		if ( ! $class ) {
			return new WP_Error( 'nxt_seo_import_unknown_source', __( 'Unknown import source.', 'nexter-extension' ), array( 'status' => 400 ) );
		}

		// /dry-run 404s when the source has no data; /run had no such gate, so an import on an
		// empty site reported done:true and the two endpoints disagreed about the same site.
		if ( ! call_user_func( array( $class, 'detect' ) ) ) {
			return new WP_Error( 'nxt_seo_import_nothing', __( 'No data from this plugin was found on the site.', 'nexter-extension' ), array( 'status' => 404 ) );
		}

		$categories = $request->get_param( 'categories' );
		$categories = is_array( $categories ) && ! empty( $categories )
			? array_values( array_intersect( array_map( 'sanitize_key', $categories ), array( 'settings', 'postmeta', 'termmeta', 'redirections' ) ) )
			: array( 'settings', 'postmeta', 'termmeta', 'redirections' );

		$batch = (int) $request->get_param( 'batch_size' );
		if ( $batch < 1 || $batch > 500 ) {
			$batch = self::DEFAULT_BATCH;
		}
		/**
		 * Objects imported per run request.
		 *
		 * @param int $batch Default 100.
		 */
		$batch = max( 1, (int) apply_filters( 'nexter_content_seo_import_batch_size', $batch ) );

		// One import at a time per source. Two browser tabs, a double-clicked button, or a retry
		// racing the request it is retrying would otherwise each claim their own batch of the
		// same queue and do the work twice.
		$token = self::acquire_lock( $slug );
		if ( '' === $token ) {
			// "Reload the page" did not help anyone: the lock clears on its own, and the one
			// thing the user can do about a lock left behind by a killed run is reset the markers.
			$seconds = self::lock_seconds_left( $slug );
			return new WP_Error(
				'nxt_seo_import_locked',
				sprintf(
					/* translators: %d: seconds until the import lock expires. */
					_n(
						'An import from this plugin is already running. It releases in about %d second. If no import is actually running, use Reset markers and start again.',
						'An import from this plugin is already running. It releases in about %d seconds. If no import is actually running, use Reset markers and start again.',
						$seconds,
						'nexter-extension'
					),
					$seconds
				),
				array(
					'status'       => 409,
					'seconds_left' => $seconds,
					'can_reset'    => true,
				)
			);
		}

		$state = self::get_state( $slug );

		// An explicit retry clears the attempt counters, so a phase parked by its budget
		// gets a fresh set of tries instead of being skipped forever.
		if ( $request->get_param( 'retry' ) ) {
			unset( $state['errors'] );
			self::save_state( $slug, $state );
		}

		$report = array(
			'source'   => $slug,
			'imported' => array(),
			'skipped'  => array(),
			'failed'   => array(),
			'log'      => array(),
		);

		// Each phase runs independently: one failing must not abandon the phases after it, and
		// must not mark itself done, so a retry picks up exactly what did not land.
		//
		// The one-shot phases are re-attempted on every slice until they succeed. On a large
		// site that is thousands of attempts at something that is reliably broken, so a phase
		// that has already failed its budget is left alone until the user resets or retries.
		$phases = array();
		if ( in_array( 'settings', $categories, true ) && empty( $state['settings_done'] ) && ! self::phase_exhausted( $slug, 'settings' ) ) {
			$phases['settings'] = array( 'import_settings', array() );
		}
		if ( in_array( 'redirections', $categories, true ) && empty( $state['redirections_done'] ) && ! self::phase_exhausted( $slug, 'redirections' ) ) {
			$phases['redirections'] = array( 'import_redirections', array() );
		}
		if ( in_array( 'postmeta', $categories, true ) ) {
			$phases['posts'] = array( 'import_posts_batch', array( $batch ) );
		}

		$remaining_posts = 0;
		$remaining_terms = 0;

		foreach ( $phases as $phase => $call ) {
			list( $method, $args ) = $call;
			$outcome               = self::run_phase( $class, $method, $args, $slug, $phase );

			if ( isset( $outcome['error'] ) ) {
				$report['failed'][ $phase ] = $outcome['error'];
				continue;
			}

			$result                       = $outcome['result'];
			$report['imported'][ $phase ] = $result;

			if ( 'settings' === $phase ) {
				$state['settings_done'] = true;
			} elseif ( 'redirections' === $phase ) {
				$state['redirections_done'] = true;
			} elseif ( 'posts' === $phase ) {
				$remaining_posts = isset( $result['remaining'] ) ? (int) $result['remaining'] : 0;
			}
		}

		// Terms start only after posts finish so every run request does a predictable amount of
		// work (one batch), not two. A failed post batch also holds terms back — otherwise the
		// run could report "done" while posts still had unimported rows.
		if ( in_array( 'termmeta', $categories, true ) && 0 === $remaining_posts && empty( $report['failed'] ) ) {
			$outcome = self::run_phase( $class, 'import_terms_batch', array( $batch ), $slug, 'terms' );
			if ( isset( $outcome['error'] ) ) {
				$report['failed']['terms'] = $outcome['error'];
			} else {
				$report['imported']['terms'] = $outcome['result'];
				$remaining_terms             = isset( $outcome['result']['remaining'] ) ? (int) $outcome['result']['remaining'] : 0;
			}
		}

		// record_error() saves its own copy of the state while the phases run, so writing the
		// copy read at the top of this request would clobber every failure just recorded — and
		// with it the attempt counts the retry budget depends on. Re-read, then apply only the
		// two flags this function actually owns.
		$latest                      = self::get_state( $slug );
		$latest['settings_done']     = ! empty( $state['settings_done'] );
		$latest['redirections_done'] = ! empty( $state['redirections_done'] );
		$latest['last_run']          = time();
		self::save_state( $slug, $latest );
		self::release_lock( $slug, $token );

		// A phase that failed leaves work behind, so the run is never "done" while one is listed.
		$report['done']      = ( 0 === $remaining_posts && 0 === $remaining_terms && empty( $report['failed'] ) );
		$report['remaining'] = array(
			'posts' => $remaining_posts,
			'terms' => $remaining_terms,
		);
		$report['errors']    = self::get_errors( $slug );

		return rest_ensure_response( $report );
	}

	/**
	 * Run one import phase, converting any failure into a recorded error instead of a 500.
	 *
	 * A PHP error inside an adapter (a source plugin storing an unexpected shape, a DB timeout)
	 * would otherwise abort the whole request and leave the client with no idea how far it got
	 * or what to retry. Catching Throwable keeps the phases after it running and the failure
	 * addressable.
	 *
	 * @param string  $adapter Adapter class.
	 * @param string  $method Adapter method.
	 * @param mixed[] $args   Method arguments.
	 * @param string  $slug   Source slug.
	 * @param string  $phase  Phase name, for the log.
	 * @return array{result?:array,error?:array}
	 */
	private static function run_phase( $adapter, $method, $args, $slug, $phase ) {
		try {
			$result = call_user_func_array( array( $adapter, $method ), $args );

			// import_settings() reports a REST failure in its payload rather than throwing.
			if ( is_array( $result ) && ! empty( $result['error'] ) ) {
				return array( 'error' => self::record_error( $slug, $phase, (string) $result['error'] ) );
			}

			return array( 'result' => is_array( $result ) ? $result : array() );
		} catch ( \Throwable $e ) {
			// The message can name internal paths, so log the detail and hand the client a
			// bounded string.
			return array( 'error' => self::record_error( $slug, $phase, $e->getMessage() ) );
		}
	}

	/**
	 * Append one failure to the source's error log, newest first and capped.
	 *
	 * @param string $slug    Source slug.
	 * @param string $phase   Phase name.
	 * @param string $message Failure message.
	 * @return array{phase:string,message:string,attempts:int,time:int}
	 */
	private static function record_error( $slug, $phase, $message ) {
		$state  = self::get_state( $slug );
		$errors = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();

		// Repeated failures of the same phase are one entry with a count, not a growing list.
		$attempts = 1;
		foreach ( $errors as $index => $entry ) {
			if ( isset( $entry['phase'] ) && $entry['phase'] === $phase ) {
				$attempts = ( isset( $entry['attempts'] ) ? (int) $entry['attempts'] : 1 ) + 1;
				unset( $errors[ $index ] );
				break;
			}
		}

		$record = array(
			'phase'    => (string) $phase,
			'message'  => mb_substr( wp_strip_all_tags( (string) $message ), 0, 300 ),
			'attempts' => $attempts,
			'time'     => time(),
		);

		array_unshift( $errors, $record );
		$state['errors'] = array_slice( array_values( $errors ), 0, 20 );
		self::save_state( $slug, $state );

		return $record;
	}

	/**
	 * Has a phase already used up its attempt budget for this source?
	 *
	 * @param string $slug  Source slug.
	 * @param string $phase Phase name.
	 * @return bool
	 */
	private static function phase_exhausted( $slug, $phase ) {
		foreach ( self::get_errors( $slug ) as $entry ) {
			if ( isset( $entry['phase'] ) && $entry['phase'] === $phase ) {
				return ( isset( $entry['attempts'] ) ? (int) $entry['attempts'] : 0 ) >= self::MAX_PHASE_ATTEMPTS;
			}
		}
		return false;
	}

	/**
	 * Recorded failures for a source.
	 *
	 * @param string $slug Source slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_errors( $slug ) {
		$state = self::get_state( $slug );
		return isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();
	}

	/**
	 * The option row name holding a source's import lock.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function lock_key( $slug ) {
		return self::STATE_OPTION . '_lock_' . $slug;
	}

	/**
	 * Read the lock row straight from the table.
	 *
	 * Reading through get_option() would answer from a cache that the direct writes below
	 * deliberately bypass, and a stale answer is exactly what makes a lock not a lock.
	 *
	 * @param string $key Option name.
	 * @return string Raw stored value, or '' when the row does not exist.
	 */
	private static function read_lock_row( $key ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Drop the option caches the direct lock writes go around.
	 *
	 * @param string $key Option name.
	 */
	private static function flush_lock_cache( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Create the lock row, and say whether THIS request is the one that created it.
	 *
	 * INSERT IGNORE against option_name's UNIQUE index is the atomicity: of any number of
	 * racing requests exactly one affects a row. add_option() cannot promise that — it decides
	 * whether the option exists by reading a cache that can be stale, and is more likely to be
	 * stale under a persistent object cache, which is where the duplicate imports were seen.
	 *
	 * @param string $key   Option name.
	 * @param string $value Raw value to store.
	 * @return bool
	 */
	private static function insert_lock_row( $key, $value ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, $value, 'no' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = ( 1 === (int) $wpdb->rows_affected );
		self::flush_lock_cache( $key );

		return $inserted;
	}

	/**
	 * Seconds until a held lock expires on its own; 0 when nothing holds it.
	 *
	 * @param string $slug Source slug.
	 * @return int
	 */
	private static function lock_seconds_left( $slug ) {
		$held = json_decode( self::read_lock_row( self::lock_key( $slug ) ), true );
		if ( ! is_array( $held ) || ! isset( $held['time'] ) ) {
			return 0;
		}

		return max( 0, self::LOCK_TTL - ( time() - (int) $held['time'] ) );
	}

	/**
	 * Claim the per-source import lock.
	 *
	 * @param string $slug Source slug.
	 * @return string Lock token, or '' when another run holds it.
	 */
	private static function acquire_lock( $slug ) {
		global $wpdb;

		$key   = self::lock_key( $slug );
		$token = wp_generate_password( 20, false, false );
		$value = (string) wp_json_encode(
			array(
				'token' => $token,
				'time'  => time(),
			)
		);

		if ( self::insert_lock_row( $key, $value ) ) {
			return $token;
		}

		// A run killed by a PHP timeout leaves its lock behind; expire it rather than blocking
		// the source forever. The DELETE is conditional on the exact bytes just read, so of two
		// requests racing to take over the same stale lock only one can remove it — and only the
		// one that did goes on to insert its own.
		$held = self::read_lock_row( $key );
		if ( '' === $held ) {
			return self::insert_lock_row( $key, $value ) ? $token : '';
		}

		$decoded = json_decode( $held, true );
		$started = ( is_array( $decoded ) && isset( $decoded['time'] ) ) ? (int) $decoded['time'] : 0;
		if ( ( time() - $started ) <= self::LOCK_TTL ) {
			return '';
		}

		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_lock_cache( $key );
		if ( 1 !== (int) $removed ) {
			return '';
		}

		return self::insert_lock_row( $key, $value ) ? $token : '';
	}

	/**
	 * Release the lock, but only if this request still owns it.
	 *
	 * @param string $slug  Source slug.
	 * @param string $token Token returned by acquire_lock().
	 */
	private static function release_lock( $slug, $token ) {
		global $wpdb;

		$key  = self::lock_key( $slug );
		$held = self::read_lock_row( $key );
		if ( '' === $held ) {
			return;
		}

		$decoded = json_decode( $held, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['token'] ) || ! hash_equals( (string) $decoded['token'], (string) $token ) ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_lock_cache( $key );
	}

	/**
	 * POST /seo/import/reset — clear markers, one-shot flags and the values this import
	 * wrote, so a re-import genuinely starts clean.
	 *
	 * The panel promised that a re-run would pick up changed source values, and it could not:
	 * write_if_empty() found Nexter's fields already filled by the first import and kept them.
	 * Only the values recorded as this import's own are removed — anything the user set is
	 * left exactly as it is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_reset( $request ) {
		$slug  = sanitize_key( (string) $request->get_param( 'source' ) );
		$class = self::source_class( $slug );
		if ( ! $class ) {
			return new WP_Error( 'nxt_seo_import_unknown_source', __( 'Unknown import source.', 'nexter-extension' ), array( 'status' => 400 ) );
		}

		// delete_metadata with delete_all, NOT raw SQL: term selection reads markers through
		// get_term_meta(), so a raw DELETE would leave the meta cache claiming the markers
		// still exist for the rest of the request (and on persistent caches, beyond it).
		$cleared = self::clear_imported_values( $slug );

		$marker = self::MARKER_PREFIX . $slug;
		delete_metadata( 'post', 0, $marker, '', true );
		delete_metadata( 'term', 0, $marker, '', true );

		$all = get_option( self::STATE_OPTION, array() );
		unset( $all[ $slug ] );
		update_option( self::STATE_OPTION, $all, false );
		delete_option( self::lock_key( $slug ) );
		self::flush_lock_cache( self::lock_key( $slug ) );

		return rest_ensure_response(
			array(
				'reset'          => true,
				'values_cleared' => $cleared,
			)
		);
	}

	/**
	 * POST /seo/import/verify — read back a sample of imported objects and confirm the Nexter
	 * destination actually holds a value for every source field that had one.
	 *
	 * A successful write is not proof of a successful import: a sanitizer can reject a value, a
	 * meta write can be filtered away, and a marker gets stamped even when nothing mapped. This
	 * re-reads what landed and reports mismatches per field.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_verify( $request ) {
		$slug  = sanitize_key( (string) $request->get_param( 'source' ) );
		$class = self::source_class( $slug );
		if ( ! $class ) {
			return new WP_Error( 'nxt_seo_import_unknown_source', __( 'Unknown import source.', 'nexter-extension' ), array( 'status' => 400 ) );
		}
		if ( ! method_exists( $class, 'verify_sample' ) ) {
			return new WP_Error( 'nxt_seo_import_no_verify', __( 'This source does not support verification.', 'nexter-extension' ), array( 'status' => 400 ) );
		}

		$sample = (int) $request->get_param( 'sample' );
		if ( $sample < 1 || $sample > 200 ) {
			$sample = 50;
		}

		return rest_ensure_response( call_user_func( array( $class, 'verify_sample' ), $sample ) );
	}

	/**
	 * Compare one object's source fields against what the Nexter destination now holds.
	 *
	 * Shared by every adapter so "verified" means the same thing across sources.
	 *
	 * @param string                   $object_type   'post' or 'term'.
	 * @param int                      $object_id     Object ID.
	 * @param array<string,string>     $map           source key => NE meta key.
	 * @param array<string,mixed>|null $source_values Source values keyed by the map's source
	 *                                                keys. Pass this when the source does not
	 *                                                store its data in post/term meta.
	 * @return array{checked:int,matched:int,missing:array<int,string>}
	 */
	public static function verify_object( $object_type, $object_id, $map, $source_values = null ) {
		$get     = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$checked = 0;
		$matched = 0;
		$missing = array();

		// Two source keys can target the same destination (Yoast stores both wpseo_desc and
		// wpseo_metadesc). The import writes whichever it reaches first and leaves the rest,
		// so only the first may be verified — checking the second reports a false failure.
		$seen = array();

		foreach ( $map as $src => $dst ) {
			if ( isset( $seen[ $dst ] ) ) {
				continue;
			}
			$source_value = is_array( $source_values )
				? ( isset( $source_values[ $src ] ) ? $source_values[ $src ] : '' )
				: call_user_func( $get, $object_id, $src, true );
			if ( '' === $source_value || null === $source_value || false === $source_value || is_array( $source_value ) ) {
				continue;
			}
			++$checked;
			$seen[ $dst ] = true;

			$dest_value = call_user_func( $get, $object_id, $dst, true );
			$present    = ( '' !== $dest_value && null !== $dest_value && false !== $dest_value );

			// An adapter that passes $source_values has already run each value through the same
			// conversion the import used, so the destination must hold exactly that — checking
			// only presence would pass a field that landed with the wrong content. Adapters that
			// read raw meta cannot be compared that way (the import transforms on the way in), so
			// for those presence remains the contract.
			$ok = is_array( $source_values )
				? ( $present && (string) $dest_value === (string) $source_value )
				: $present;

			if ( $ok ) {
				++$matched;
			} else {
				$missing[] = $dst;
			}
		}

		return array(
			'checked' => $checked,
			'matched' => $matched,
			'missing' => $missing,
		);
	}

	/**
	 * Is this schema field still at its shipped default rather than something the user typed?
	 *
	 * The seeded Organization row uses pure tokens (%site.title%, %website_details.website_logo%),
	 * so a value that is nothing but tokens has never been filled in by hand.
	 *
	 * @param mixed $value Stored field value.
	 * @return bool
	 */
	private static function schema_field_is_unset( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		return '' === trim( (string) preg_replace( '/%[a-z0-9_.]+%/i', '', $value ) );
	}

	/**
	 * Fill Nexter's Organization schema identity from an imported source.
	 *
	 * Nexter has no plain "organization name" setting — the knowledge-graph identity lives in
	 * an Organization row inside the schema option, which is why this could not simply be
	 * mapped like the other settings. The row is read (and created from the shipped default when
	 * the site has none), the two fields are filled, and the whole
	 * payload goes back through Nexter_Content_SEO_Schema::sanitize_schema_payload() rather
	 * than being written raw, so the schema option cannot end up in a shape the module does
	 * not expect.
	 *
	 * A field is only filled when it is still the shipped default. A name or logo the user
	 * typed is never replaced — same contract as write_if_empty() for post meta.
	 *
	 * @param string $name Organization name from the source, or ''.
	 * @param string $logo Organization logo URL from the source, or ''.
	 * @return string[] The field names actually written.
	 */
	public static function import_organization_identity( $name, $logo ) {
		$name = trim( (string) $name );
		$logo = trim( (string) $logo );
		if ( ( '' === $name && '' === $logo ) || ! class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			return array();
		}

		// normalize_schema_lists() rather than the raw option: a legacy-shaped option, and a
		// fresh install whose defaults are not stored yet, both have to resolve to the same lists.
		$lists = Nexter_Content_SEO_Schema::normalize_schema_lists( get_option( Nexter_Content_SEO_Schema::OPTION_SCHEMA, array() ) );
		$lists = array(
			'site_wide'     => isset( $lists['site_wide'] ) && is_array( $lists['site_wide'] ) ? array_values( $lists['site_wide'] ) : array(),
			'page_specific' => isset( $lists['page_specific'] ) && is_array( $lists['page_specific'] ) ? array_values( $lists['page_specific'] ) : array(),
		);

		// One Organization row is the identity; the resolver reads the first it finds, site_wide first.
		$bucket = '';
		$index  = -1;
		foreach ( array( 'site_wide', 'page_specific' ) as $list ) {
			foreach ( $lists[ $list ] as $i => $row ) {
				if ( is_array( $row ) && isset( $row['type'] ) && 'Organization' === $row['type'] ) {
					$bucket = $list;
					$index  = $i;
					break 2;
				}
			}
		}

		// A site with no Organization row at all — the preview promised the name and logo would
		// land, so the shipped default row is added rather than the values being dropped.
		if ( '' === $bucket ) {
			$defaults = Nexter_Content_SEO_Schema::get_default_schema_lists();
			$seed     = array();
			foreach ( (array) $defaults['site_wide'] as $row ) {
				if ( is_array( $row ) && isset( $row['type'] ) && 'Organization' === $row['type'] ) {
					$seed = $row;
					break;
				}
			}
			if ( empty( $seed ) ) {
				return array();
			}
			$lists['site_wide'][] = $seed;
			$bucket               = 'site_wide';
			$index                = count( $lists['site_wide'] ) - 1;
		}

		$row     = $lists[ $bucket ][ $index ];
		$fields  = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
		$written = array();
		if ( '' !== $name && self::schema_field_is_unset( isset( $fields['name'] ) ? $fields['name'] : '' ) ) {
			$fields['name'] = $name;
			$written[]      = 'name';
		}
		if ( '' !== $logo && self::schema_field_is_unset( isset( $fields['logo'] ) ? $fields['logo'] : '' ) ) {
			$fields['logo'] = $logo;
			$written[]      = 'logo';
		}

		if ( empty( $written ) ) {
			return array();
		}

		$lists[ $bucket ][ $index ]['fields'] = $fields;

		update_option( Nexter_Content_SEO_Schema::OPTION_SCHEMA, Nexter_Content_SEO_Schema::sanitize_schema_payload( $lists ), false );

		return $written;
	}

	/**
	 * The destination meta keys an import writes, by group.
	 *
	 * The preview used to count a conflict only when a post already had a Nexter title or
	 * description, so a page deliberately kept out of search could have its robots settings
	 * changed by the import with nothing said about it beforehand.
	 *
	 * @param string $group 'content', 'robots' or 'all'.
	 * @return string[]
	 */
	public static function destination_keys( $group = 'content' ) {
		$content = array(
			'_nxt_seo_title',
			'_nxt_seo_description',
			'_nxt_seo_canonical',
			'_nxt_seo_fb_title',
			'_nxt_seo_fb_desc',
			'_nxt_seo_fb_image',
			'_nxt_seo_tw_title',
			'_nxt_seo_tw_desc',
			'_nxt_seo_tw_image',
			'_nxt_seo_schema_type',
		);
		$robots  = array(
			'_nxt_seo_noindex',
			'_nxt_seo_nofollow',
			'_nxt_seo_noarchive',
		);

		if ( 'robots' === $group ) {
			return $robots;
		}
		if ( 'all' === $group ) {
			return array_merge( $content, $robots );
		}

		return $content;
	}

	/**
	 * The same keys as a quoted SQL list, for the adapters' conflict counts.
	 *
	 * The values are this class's own constants, never anything from a request.
	 *
	 * @param string $group 'content', 'robots' or 'all'.
	 * @return string
	 */
	public static function destination_keys_sql( $group = 'content' ) {
		return "'" . implode( "', '", array_map( 'esc_sql', self::destination_keys( $group ) ) ) . "'";
	}

	/**
	 * The product name the preview should use. Every string a user reads on the migration
	 * screen goes through this, so a white-labelled install never shows "Nexter SEO".
	 *
	 * @return string
	 */
	public static function brand() {
		return class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_label() : __( 'Nexter SEO', 'nexter-extension' );
	}

	/**
	 * A preview skip row for per-user SEO data, or null when the source has none.
	 *
	 * Yoast and Rank Math both store an author title, an author meta description and a per-user
	 * noindex in user meta. Nexter imports none of it. Unlike focus keywords and cornerstone,
	 * which get an honest "will be skipped" row, this loss was invisible: the author archive
	 * simply kept whatever the global template produced.
	 *
	 * @param string $source_label Human label for the source plugin.
	 * @param string $meta_prefix  User-meta key prefix the source writes (SQL LIKE, un-escaped).
	 * @return array{key:string,count:int,reason:string}|null
	 */
	public static function user_meta_skip( $source_label, $meta_prefix ) {
		global $wpdb;

		$like  = $wpdb->esc_like( $meta_prefix ) . '%';
		$users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT user_id ) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND meta_value != ''", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $users < 1 ) {
			return null;
		}

		return array(
			'key'    => __( 'Per-author SEO settings', 'nexter-extension' ),
			'count'  => $users,
			'reason' => sprintf(
				/* translators: 1: source SEO plugin name, 2: product name. */
				__( 'These users have an author title, description or noindex set in %1$s. %2$s has no per-author fields yet, so they are not imported and the author archives will use your global archive template instead.', 'nexter-extension' ),
				$source_label,
				self::brand()
			),
		);
	}

	/**
	 * A preview skip row for a multilingual site, or null when the site is not multilingual.
	 *
	 * Every adapter writes ONE set of global settings, so per-language title and description
	 * templates, robots defaults and social settings are flattened to whichever copy the
	 * source stored as canonical. Per-post SEO is unaffected — Polylang and WPML each store a
	 * translation as its own post with its own meta. Flattening is an acceptable limit; doing
	 * it silently, in a feature whose whole promise is "this is what the import would do",
	 * is not.
	 *
	 * @param string $source_label Human label for the source plugin.
	 * @return array{key:string,count:int,reason:string}|null
	 */
	public static function multilingual_skip( $source_label ) {
		$plugin = '';
		if ( defined( 'POLYLANG_VERSION' ) || defined( 'POLYLANG_BASENAME' ) || function_exists( 'pll_languages_list' ) ) {
			$plugin = 'Polylang';
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'WPML_PLUGIN_BASENAME' ) ) {
			$plugin = 'WPML';
		}

		if ( '' === $plugin ) {
			return null;
		}

		return array(
			'key'    => __( 'Per-language global settings', 'nexter-extension' ),
			'count'  => 1,
			'reason' => sprintf(
				/* translators: 1: multilingual plugin name, 2: source SEO plugin name. */
				__( '%1$s is active. %3$s keeps one set of global settings for the whole site, so the title and description templates, robots defaults and social settings are imported once from %2$s and then apply to every language. Your per-post SEO is unaffected: each translation is its own post with its own values.', 'nexter-extension' ),
				$plugin,
				$source_label,
				self::brand()
			),
		);
	}

	/**
	 * Unserialize stored source data with objects refused.
	 *
	 * Everything an adapter reads is data another plugin wrote — a custom table column, an
	 * option, a meta row — and none of it is ever meant to be an object. maybe_unserialize()
	 * would instantiate one, which turns a crafted O: payload in a source table into object
	 * instantiation inside the import.
	 *
	 * @param mixed $raw Stored value.
	 * @return mixed Array or scalar; never an object.
	 */
	public static function unserialize_data( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}

		return unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- objects refused by the option above.
	}

	/**
	 * Already-imported object IDs of both kinds, newest first.
	 *
	 * Terms are read first and capped at half the sample: a site usually has far fewer terms
	 * than posts, and a posts-only sample is exactly how term data loss went unnoticed.
	 *
	 * @param string $source_slug Source slug.
	 * @param int    $sample      Total objects to return across both kinds.
	 * @return array{posts:int[],terms:int[]}
	 */
	public static function sample_marked_ids( $source_slug, $sample ) {
		global $wpdb;

		$marker = self::MARKER_PREFIX . $source_slug;
		$sample = max( 1, (int) $sample );
		$terms  = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s ORDER BY term_id DESC LIMIT %d", $marker, (int) floor( $sample / 2 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$terms  = array_map( 'intval', (array) $terms );
		$left   = max( 1, $sample - count( $terms ) );
		$posts  = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT %d", $marker, $left ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'posts' => array_map( 'intval', (array) $posts ),
			'terms' => $terms,
		);
	}

	/**
	 * Build the verify response, with the one rule every adapter must share.
	 *
	 * Comparing checked against matched is trivially true at 0 === 0, which reported a clean
	 * pass for an import that read nothing at all — the exact failure mode a verify step
	 * exists to catch. A pass now requires that something was actually checked, and every
	 * other outcome says why in plain words so the UI need not infer it from the counts.
	 *
	 * @param string                         $source_slug Source slug.
	 * @param int                            $objects     Objects sampled.
	 * @param int                            $checked     Source fields that held a value.
	 * @param int                            $matched     Of those, the ones the destination has.
	 * @param array<int,array<string,mixed>> $issues      Per-object misses.
	 * @return array<string,mixed>
	 */
	public static function verify_summary( $source_slug, $objects, $checked, $matched, $issues ) {
		$objects  = (int) $objects;
		$checked  = (int) $checked;
		$matched  = (int) $matched;
		$verified = ( $checked > 0 && $checked === $matched );
		$reason   = '';

		if ( ! $verified ) {
			if ( 0 === $objects ) {
				$reason = __( 'Nothing has been imported from this source yet, so there is nothing to verify.', 'nexter-extension' );
			} elseif ( 0 === $checked ) {
				$reason = __( 'The imported objects were found, but not one source field could be read from them. The import wrote nothing — treat this as a failure and re-run it after resetting the markers.', 'nexter-extension' );
			} else {
				$reason = __( 'Some values the source holds are missing or different in Nexter. The affected objects are listed below.', 'nexter-extension' );
			}
		}

		return array(
			'source'   => $source_slug,
			'objects'  => $objects,
			'checked'  => $checked,
			'matched'  => $matched,
			// Only the first few, so a site-wide problem does not return a wall of rows.
			'issues'   => array_slice( $issues, 0, 10 ),
			'verified' => $verified,
			'reason'   => $reason,
		);
	}

	/**
	 * Shared verify pass: sample both posts and terms and compare each against the source.
	 *
	 * @param string   $source_slug Source slug.
	 * @param int      $sample      Objects to check.
	 * @param callable $resolver    fn( string $object_type, int $object_id ) => array{map:array,expected:array|null}.
	 * @return array<string,mixed>
	 */
	public static function verify_sample_shared( $source_slug, $sample, $resolver ) {
		$ids     = self::sample_marked_ids( $source_slug, $sample );
		$objects = 0;
		$checked = 0;
		$matched = 0;
		$issues  = array();

		$by_type = array(
			'post' => $ids['posts'],
			'term' => $ids['terms'],
		);

		foreach ( $by_type as $object_type => $object_ids ) {
			foreach ( $object_ids as $object_id ) {
				++$objects;
				$spec = call_user_func( $resolver, $object_type, (int) $object_id );
				if ( empty( $spec['map'] ) ) {
					continue;
				}

				$result   = self::verify_object( $object_type, (int) $object_id, $spec['map'], isset( $spec['expected'] ) ? $spec['expected'] : null );
				$checked += $result['checked'];
				$matched += $result['matched'];
				if ( ! empty( $result['missing'] ) ) {
					$issues[] = array(
						'id'      => (int) $object_id,
						'type'    => $object_type,
						'title'   => self::object_label( $object_type, (int) $object_id ),
						'missing' => $result['missing'],
					);
				}
			}
		}

		return self::verify_summary( $source_slug, $objects, $checked, $matched, $issues );
	}

	/**
	 * Human label for an object in the verify issues list.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return string
	 */
	private static function object_label( $object_type, $object_id ) {
		if ( 'post' === $object_type ) {
			return (string) get_the_title( $object_id );
		}

		$name = get_term_field( 'name', $object_id, '', 'raw' );

		return is_wp_error( $name ) ? '' : (string) $name;
	}

	/**
	 * Per-source state row.
	 *
	 * @param string $slug Source slug.
	 * @return array<string,mixed>
	 */
	public static function get_state( $slug ) {
		$all = get_option( self::STATE_OPTION, array() );
		return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/**
	 * Persist a per-source state row.
	 *
	 * @param string              $slug  Source slug.
	 * @param array<string,mixed> $state State row.
	 */
	public static function save_state( $slug, $state ) {
		$all          = get_option( self::STATE_OPTION, array() );
		$all[ $slug ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Map a source plugin's schema type onto one Nexter supports.
	 *
	 * Every SEO plugin names these differently (Yoast splits page type from article type, Rank
	 * Math stores lowercase slugs, SureRank stores schema.org @type), and each carries types
	 * Nexter has no builder for. The mapping folds a family onto its Nexter representative
	 * (BlogPosting/NewsArticle/TechArticle are all Article; AboutPage/ContactPage are all
	 * WebPage), and returns '' for anything with no equivalent so the caller can report it
	 * rather than writing a type the schema builder cannot render.
	 *
	 * @param string $source_type Raw type from the source plugin.
	 * @return string Nexter schema type, or '' when there is no equivalent.
	 */
	public static function map_schema_type( $source_type ) {
		$source_type = trim( (string) $source_type );
		if ( '' === $source_type ) {
			return '';
		}

		$map = array(
			// Article family.
			'article'                  => 'Article',
			'blogposting'              => 'Article',
			'newsarticle'              => 'Article',
			'techarticle'              => 'Article',
			'scholarlyarticle'         => 'Article',
			'satiricalarticle'         => 'Article',
			'advertisercontentarticle' => 'Article',
			'socialmediaposting'       => 'Article',
			'report'                   => 'Article',
			// WebPage family.
			'webpage'                  => 'WebPage',
			'itempage'                 => 'WebPage',
			'aboutpage'                => 'WebPage',
			'contactpage'              => 'WebPage',
			'collectionpage'           => 'WebPage',
			'profilepage'              => 'WebPage',
			'checkoutpage'             => 'WebPage',
			'medicalwebpage'           => 'WebPage',
			'realestatelisting'        => 'WebPage',
			'searchresultspage'        => 'WebPage',
			'qapage'                   => 'WebPage',
			'faqpage'                  => 'FAQPage',
			// Direct equivalents.
			'product'                  => 'Product',
			'course'                   => 'Course',
			'event'                    => 'Event',
			'recipe'                   => 'Recipe',
			'person'                   => 'Person',
			'service'                  => 'Service',
			'howto'                    => 'HowTo',
			'claimreview'              => 'ClaimReview',
			'organization'             => 'Organization',
			'website'                  => 'WebSite',
			'breadcrumblist'           => 'BreadcrumbList',
			'searchaction'             => 'SearchAction',
			'videoobject'              => 'VideoObject',
			'video'                    => 'VideoObject',
			'softwareapplication'      => 'SoftwareApplication',
			'software'                 => 'SoftwareApplication',
			'mobileapplication'        => 'SoftwareApplication',
			'webapplication'           => 'SoftwareApplication',
			'localbusiness'            => 'LocalBusiness',
			'restaurant'               => 'LocalBusiness',
		);

		/**
		 * Filter the source → Nexter schema type map.
		 *
		 * @param array<string,string> $map lowercased source type => Nexter type.
		 */
		$map = apply_filters( 'nexter_content_seo_import_schema_type_map', $map );

		$key      = strtolower( preg_replace( '/[^a-z0-9]/i', '', $source_type ) );
		$resolved = isset( $map[ $key ] ) ? $map[ $key ] : '';

		// Never hand the schema builder a type it has no field file for.
		if ( '' !== $resolved && class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			$allowed = array_keys( Nexter_Content_SEO_Schema::get_schema_types() );
			if ( ! in_array( $resolved, $allowed, true ) ) {
				return '';
			}
		}

		return $resolved;
	}

	/**
	 * Write an imported value only when the Nexter destination is still empty.
	 *
	 * An import must not silently replace something the user typed into Nexter SEO. The marker
	 * already stops a *second* run from touching an object, but on the first run the destination
	 * may well be populated — someone who configured Nexter first and imported afterwards would
	 * otherwise lose that work with no warning. A skip is counted so the summary can report what
	 * was kept instead of dropping the fact on the floor.
	 *
	 * Shared by every adapter, so future sources inherit the same rule.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @param string $meta_key    Nexter destination meta key.
	 * @param mixed  $value       Value to write.
	 * @return bool True when written, false when an existing value was kept.
	 */
	public static function write_if_empty( $object_type, $object_id, $meta_key, $value ) {
		$get    = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$update = 'post' === $object_type ? 'update_post_meta' : 'update_term_meta';

		$existing = call_user_func( $get, $object_id, $meta_key, true );
		$known    = self::wrote_keys( $object_type, $object_id );

		// '0' is a real value for the robots keys, so only genuinely empty counts as free.
		$occupied = ( '' !== $existing && null !== $existing && false !== $existing && array() !== $existing );

		// A value THIS import wrote is not the user's. A run killed mid-object leaves its own
		// writes behind with no marker, so the object is picked up again — and every field it
		// had already written was being counted as "already the user's" and left alone.
		if ( $occupied && ! ( null !== $known && isset( $known[ $meta_key ] ) ) ) {
			return false;
		}

		// Sanitize at write time, not just on render: source plugins copy title/description/
		// canonical/social text straight from their own tables, and this is the one seam every
		// adapter (Yoast/Rank Math/AIOSEO/SureRank) writes through. Images are already escaped by
		// the caller before reaching here; the canonical URL is not, so it needs the same
		// treatment as an image rather than the plain-text one.
		if ( is_string( $value ) ) {
			$value = ( class_exists( 'Nexter_Content_SEO_Canonical' ) && Nexter_Content_SEO_Canonical::META_CANONICAL === $meta_key )
				? esc_url_raw( $value )
				: sanitize_text_field( $value );
		}

		// Recorded before the value, never after: a crash in between leaves a recorded key
		// whose value is still empty, which costs nothing, where the reverse would leave a
		// written value nothing knows about.
		self::record_wrote_key( $object_type, $object_id, $meta_key );
		call_user_func( $update, $object_id, $meta_key, $value );

		return true;
	}

	/**
	 * Declare which source is writing. Called once per batch; clears the per-object cache.
	 *
	 * @param string $slug Source slug.
	 */
	public static function set_active_source( $slug ) {
		self::$active_source = sanitize_key( (string) $slug );
		self::$wrote_cache   = array();
	}

	/**
	 * The destination keys the active source's import has already written to one object.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array<string,bool>|null Null when no source is active.
	 */
	private static function wrote_keys( $object_type, $object_id ) {
		if ( '' === self::$active_source ) {
			return null;
		}

		$cache_key = $object_type . ':' . (int) $object_id;
		if ( ! isset( self::$wrote_cache[ $cache_key ] ) ) {
			$get   = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
			$saved = call_user_func( $get, $object_id, self::WROTE_PREFIX . self::$active_source, true );
			$saved = is_array( $saved ) ? $saved : array();

			self::$wrote_cache[ $cache_key ] = array_fill_keys( array_map( 'strval', $saved ), true );
		}

		return self::$wrote_cache[ $cache_key ];
	}

	/**
	 * Add one destination key to an object's record, if it is not already there.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @param string $meta_key    Destination meta key.
	 */
	private static function record_wrote_key( $object_type, $object_id, $meta_key ) {
		$known = self::wrote_keys( $object_type, $object_id );
		if ( null === $known || isset( $known[ $meta_key ] ) ) {
			return;
		}

		$cache_key = $object_type . ':' . (int) $object_id;

		self::$wrote_cache[ $cache_key ][ $meta_key ] = true;

		$update = 'post' === $object_type ? 'update_post_meta' : 'update_term_meta';
		call_user_func( $update, $object_id, self::WROTE_PREFIX . self::$active_source, array_keys( self::$wrote_cache[ $cache_key ] ) );
	}

	/**
	 * Remove the values one source's import wrote, and nothing else.
	 *
	 * @param string $slug Source slug.
	 * @return int Values removed.
	 */
	private static function clear_imported_values( $slug ) {
		global $wpdb;

		$record  = self::WROTE_PREFIX . $slug;
		$removed = 0;

		foreach ( array( 'post', 'term' ) as $object_type ) {
			$table  = ( 'post' === $object_type ) ? $wpdb->postmeta : $wpdb->termmeta;
			$column = ( 'post' === $object_type ) ? 'post_id' : 'term_id';
			$delete = ( 'post' === $object_type ) ? 'delete_post_meta' : 'delete_term_meta';

			// Table and column come from the hardcoded pair above, never from a request.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$column} AS object_id, meta_value FROM {$table} WHERE meta_key = %s", $record ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				$keys = maybe_unserialize( $row['meta_value'] );
				if ( ! is_array( $keys ) ) {
					continue;
				}
				foreach ( $keys as $meta_key ) {
					call_user_func( $delete, (int) $row['object_id'], (string) $meta_key );
					++$removed;
				}
			}
		}

		// delete_metadata with delete_all rather than raw SQL, for the same cache reason the
		// marker cleanup gives.
		delete_metadata( 'post', 0, $record, '', true );
		delete_metadata( 'term', 0, $record, '', true );

		return $removed;
	}
	/**
	 * Post IDs that still carry source meta and no marker — one batch, oldest first for
	 * stable, resume-able ordering.
	 *
	 * @param string $meta_prefix Source meta key prefix (SQL LIKE, un-escaped).
	 * @param string $source_slug Source slug (for the marker key).
	 * @param int    $limit       Batch size. 0 = count only.
	 * @return array{remaining:int, ids:int[]}
	 */
	public static function get_unimported_post_ids( $meta_prefix, $source_slug, $limit ) {
		global $wpdb;

		$marker = self::MARKER_PREFIX . $source_slug;
		$like   = $wpdb->esc_like( $meta_prefix ) . '%';

		// EXISTS + NOT EXISTS instead of JOIN+GROUP BY: the prefix LIKE can match several meta
		// rows per post and a join would hand back duplicate IDs to count and page over.
		$where = $wpdb->prepare(
			"FROM {$wpdb->posts} p
			 WHERE p.post_status NOT IN ('auto-draft','inherit')
			   AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key LIKE %s )
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = p.ID AND mk.meta_key = %s )",
			$like,
			$marker
		);

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(p.ID) {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment.
		$ids       = array();
		if ( $limit > 0 && $remaining > 0 ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col( "SELECT p.ID {$where} ORDER BY p.ID ASC LIMIT " . (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment; LIMIT is an int cast.
			);
		}

		return array(
			'remaining' => $remaining,
			'ids'       => $ids,
		);
	}

	/**
	 * Post IDs carrying at least one of an explicit list of source meta keys, and no marker.
	 *
	 * The prefix form above is wrong for sources that stamp bookkeeping meta on every post
	 * (Rank Math writes rank_math_internal_links_processed and rank_math_seo_score whether or
	 * not the post has any SEO data). A prefix LIKE would then report every post on the site as
	 * pending and burn batches examining posts with nothing to import.
	 *
	 * @param string[] $meta_keys   Source meta keys that count as real data.
	 * @param string   $source_slug Source slug (for the marker key).
	 * @param int      $limit       Batch size. 0 = count only.
	 * @return array{remaining:int, ids:int[]}
	 */
	public static function get_unimported_post_ids_by_keys( $meta_keys, $source_slug, $limit ) {
		global $wpdb;

		$meta_keys = array_values( array_filter( array_map( 'strval', (array) $meta_keys ) ) );
		if ( empty( $meta_keys ) ) {
			return array(
				'remaining' => 0,
				'ids'       => array(),
			);
		}

		$marker       = self::MARKER_PREFIX . $source_slug;
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$args         = $meta_keys;
		$args[]       = $marker;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and the generated %s placeholder list; every value goes through prepare().
		$where = $wpdb->prepare(
			"FROM {$wpdb->posts} p
			 WHERE p.post_status NOT IN ('auto-draft','inherit')
			   AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key IN ({$placeholders}) AND pm.meta_value != '' )
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = p.ID AND mk.meta_key = %s )",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(p.ID) {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment.
		$ids       = array();
		if ( $limit > 0 && $remaining > 0 ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col( "SELECT p.ID {$where} ORDER BY p.ID ASC LIMIT " . (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment; LIMIT is an int cast.
			);
		}

		return array(
			'remaining' => $remaining,
			'ids'       => $ids,
		);
	}

	/**
	 * Term IDs carrying at least one of an explicit list of source term meta keys, and no marker.
	 *
	 * @param string[] $meta_keys   Source term meta keys.
	 * @param string   $source_slug Source slug (for the marker key).
	 * @param int      $limit       Batch size. 0 = count only.
	 * @return array{remaining:int, ids:int[]}
	 */
	public static function get_unimported_term_ids_by_keys( $meta_keys, $source_slug, $limit ) {
		global $wpdb;

		$meta_keys = array_values( array_filter( array_map( 'strval', (array) $meta_keys ) ) );
		if ( empty( $meta_keys ) ) {
			return array(
				'remaining' => 0,
				'ids'       => array(),
			);
		}

		$marker       = self::MARKER_PREFIX . $source_slug;
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$args         = $meta_keys;
		$args[]       = $marker;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and the generated %s placeholder list; every value goes through prepare().
		$where = $wpdb->prepare(
			"FROM {$wpdb->terms} t
			 WHERE EXISTS ( SELECT 1 FROM {$wpdb->termmeta} tm WHERE tm.term_id = t.term_id AND tm.meta_key IN ({$placeholders}) AND tm.meta_value != '' )
			   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} mk WHERE mk.term_id = t.term_id AND mk.meta_key = %s )",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(t.term_id) {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment.
		$ids       = array();
		if ( $limit > 0 && $remaining > 0 ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col( "SELECT t.term_id {$where} ORDER BY t.term_id ASC LIMIT " . (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a $wpdb->prepare() fragment; LIMIT is an int cast.
			);
		}

		return array(
			'remaining' => $remaining,
			'ids'       => $ids,
		);
	}

	/**
	 * Convert a source template string to Nexter template syntax with a tokenized parse.
	 *
	 * Using str_replace over the whole string is not safe here: an unknown source variable would
	 * survive verbatim and render as literal "%%pt_single%%" text in page titles. The string
	 * is split into variable / non-variable parts; known variables map, unknown ones are
	 * DROPPED and reported so the dry run can list them.
	 *
	 * @param string               $value       Source template.
	 * @param string               $split_regex Token regex WITH a capture group, for preg_split
	 *                                          (e.g. '/(%%[^%\s]+%%)/').
	 * @param string               $token_regex Anchored regex matching a whole token
	 *                                          (e.g. '/^%%[^%\s]+%%$/').
	 * @param array<string,string> $map         token => NE variable (or literal).
	 * @param array<string,int>    $unknown     Collector: token => occurrences (by reference).
	 * @return string
	 */
	public static function convert_template( $value, $split_regex, $token_regex, $map, &$unknown ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$parts = preg_split( $split_regex, $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			return $value;
		}

		$out = '';
		foreach ( $parts as $part ) {
			if ( preg_match( $token_regex, $part ) ) {
				if ( isset( $map[ $part ] ) ) {
					$out .= $map[ $part ];
				} else {
					$unknown[ $part ] = isset( $unknown[ $part ] ) ? $unknown[ $part ] + 1 : 1;
				}
				continue;
			}
			$out .= $part;
		}

		// Collapse doubled whitespace left behind by dropped tokens.
		return trim( preg_replace( '/\s{2,}/', ' ', $out ) );
	}

	/**
	 * Union array-valued settings with what the site already has.
	 *
	 * An import adds to the user's per-type noindex choices rather than replacing them: a site
	 * that already noindexes search must not lose that because the other plugin noindexed the
	 * author and date archives. Scalars are left as built — the Preview shows their before/after.
	 *
	 * @param array<string,mixed> $settings NE option key => value, as built by an adapter.
	 * @return array<string,mixed>
	 */
	public static function merge_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}
		$current = get_option( Nexter_Content_SEO::OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			return $settings;
		}
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) && isset( $current[ $key ] ) && is_array( $current[ $key ] ) ) {
				$settings[ $key ] = self::union_arrays( $current[ $key ], $value );
			}
		}

		return $settings;
	}

	/**
	 * Union two arrays: lists by value, maps by key (existing entries kept, new ones added).
	 *
	 * @param array $existing Value already stored.
	 * @param array $incoming Value the import built.
	 * @return array
	 */
	private static function union_arrays( $existing, $incoming ) {
		if ( ! empty( $existing ) && ! empty( $incoming ) && self::is_list( $existing ) && self::is_list( $incoming ) ) {
			return array_values( array_unique( array_merge( $existing, $incoming ) ) );
		}

		return array_replace( $existing, $incoming );
	}

	/**
	 * Sequential numeric keys only?
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private static function is_list( $value ) {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
