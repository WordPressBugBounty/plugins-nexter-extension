<?php
/**
 * Keeps the definition files and the definition posts saying the same thing.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Model_Sync
 */
class Nxt_Content_Types_Model_Sync {

	/**
	 * Post meta recording the key this definition was last exported under.
	 */
	const LAST_KEY_META = '_nxt_model_file_key';

	/**
	 * Option holding the most recent export failure, for the admin to surface.
	 */
	const ERROR_OPTION = 'nxt_model_sync_error';

	/**
	 * Set once a site's database definitions have been exported into files.
	 */
	const MIGRATED_OPTION = 'nxt_model_migrated';

	/**
	 * Short-lived flag that stops a failed migration retrying on every admin request.
	 */
	const MIGRATION_BACKOFF = 'nxt_model_migration_backoff';

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Attach the mirror.
	 */
	public static function init() {
		// Definitions are files now, so nothing writes the post rows these hooks watched.
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_export_meta' ), 20, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_export_meta' ), 20, 3 );
		add_action( 'trashed_post', array( __CLASS__, 'maybe_export_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'maybe_export_post' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_remove_post' ), 20, 1 );

		// The upgrade path.
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_from_database' ), 5 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::maybe_migrate_from_database();
		}
	}

	/**
	 * One-time export of database definitions into files, for a site upgrading into file storage.
	 *
	 * @return void
	 */
	public static function maybe_migrate_from_database() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		if ( get_transient( self::MIGRATION_BACKOFF ) ) {
			return;
		}
		if ( ! self::ready() ) {
			return;
		}

		// Anything already on disk means this site is past the migration.
		foreach ( array_keys( Nxt_Content_Types_Model_Paths::kind_dirs() ) as $kind ) {
			if ( Nxt_Content_Types_Model_Store::list_files( $kind ) ) {
				update_option( self::MIGRATED_OPTION, 1, false );
				return;
			}
		}

		$existing = get_posts(
			array(
				'post_type'              => Nxt_Content_Types_CPT::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( ! $existing ) {
			// A fresh install with nothing to migrate. Mark it done so this never runs again.
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$result = self::export_all();
		if ( $result['failed'] > 0 || ! $result['exported'] ) {
			set_transient( self::MIGRATION_BACKOFF, 1, HOUR_IN_SECONDS );
			return;
		}

		update_option( self::MIGRATED_OPTION, 1, false );
		Nxt_Content_Types_Model_Cache::flush();
		if ( class_exists( 'Nxt_Content_Types_Definitions_Repository' ) ) {
			Nxt_Content_Types_Definitions_Repository::get_instance()->flush();
		}
	}

	/**
	 * Stop exporting until resume() is called.
	 */
	public static function suspend() {
		self::$suspended = true;
	}

	/**
	 * Resume exporting.
	 */
	public static function resume() {
		self::$suspended = false;
	}

	/**
	 * Whether the exporter is currently suspended.
	 *
	 * @return bool
	 */
	public static function is_suspended() {
		return self::$suspended;
	}

	/**
	 * Export when the definition meta key is written, whoever wrote it.
	 *
	 * @param int|int[] $meta_id   Meta row ID(s). Unused.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key that changed.
	 */
	public static function maybe_export_meta( $meta_id, $object_id, $meta_key ) {
		if ( ! class_exists( 'Nxt_Content_Types_CPT' ) || Nxt_Content_Types_CPT::get_meta_key() !== $meta_key ) {
			return;
		}
		self::export( (int) $object_id );
	}

	/**
	 * Export on a status change, if the post is one of ours.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_export_post( $post_id ) {
		if ( self::is_definition( $post_id ) ) {
			self::export( (int) $post_id );
		}
	}

	/**
	 * Remove the file for a definition about to be deleted for good.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_remove_post( $post_id ) {
		if ( self::is_definition( $post_id ) ) {
			self::remove( (int) $post_id );
		}
	}

	/**
	 * Write one definition post out to its file.
	 *
	 * @param int $post_id Definition post ID.
	 * @return true|WP_Error True on success (including a deliberate no-op).
	 */
	public static function export( $post_id ) {
		if ( self::$suspended || ! self::ready() ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post || Nxt_Content_Types_CPT::POST_TYPE !== $post->post_type ) {
			return true;
		}

		// A trashed or auto-draft definition is not registered and not part of the model, so it must not sit in
		// the file tree pretending to be.
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::remove( $post_id );
		}

		$meta = get_post_meta( $post_id, Nxt_Content_Types_CPT::get_meta_key(), true );
		if ( ! is_array( $meta ) || ! $meta ) {
			return true;
		}

		$kind = self::model_kind( $meta );
		if ( '' === $kind ) {
			return true;
		}
		$key_field = Nxt_Content_Types_Model_Paths::key_field( $kind );
		$key       = isset( $meta[ $key_field ] ) && is_string( $meta[ $key_field ] ) ? $meta[ $key_field ] : '';

		$valid = Nxt_Content_Types_Model_Paths::validate_key( $kind, $key );
		if ( is_wp_error( $valid ) ) {
			return self::fail( $valid );
		}

		// A rename leaves the old filename behind, and a stale file with a stale key inside it is
		// exactly the duplicate the loader would then refuse to load. Clear it first.
		$previous = (string) get_post_meta( $post_id, self::LAST_KEY_META, true );
		if ( '' !== $previous && $previous !== $key ) {
			Nxt_Content_Types_Model_Store::delete( $kind, $previous );
		}

		// The post title is the one piece of a definition that never lived in the meta.
		$name_field = ( 'group_fields' === $kind ) ? 'title' : 'label';
		if ( empty( $meta[ $name_field ] ) && '' !== trim( (string) $post->post_title ) ) {
			$meta[ $name_field ] = $post->post_title;
		}

		$written = Nxt_Content_Types_Model_Store::write( $kind, $key, $meta );
		if ( is_wp_error( $written ) ) {
			return self::fail( $written );
		}

		update_post_meta( $post_id, self::LAST_KEY_META, $key );
		self::clear_error();
		Nxt_Content_Types_Model_Cache::flush();

		return true;
	}

	/**
	 * Remove the file belonging to a definition post.
	 *
	 * @param int $post_id Definition post ID.
	 * @return true|WP_Error
	 */
	public static function remove( $post_id ) {
		if ( self::$suspended || ! self::ready() ) {
			return true;
		}
		$meta = get_post_meta( $post_id, Nxt_Content_Types_CPT::get_meta_key(), true );
		$kind = is_array( $meta ) ? self::model_kind( $meta ) : '';
		if ( '' === $kind ) {
			return true;
		}

		// Prefer the key the file was actually written under. The meta key may already have been
		// changed by the same request that is now deleting the definition.
		$key = (string) get_post_meta( $post_id, self::LAST_KEY_META, true );
		if ( '' === $key ) {
			$key_field = Nxt_Content_Types_Model_Paths::key_field( $kind );
			$key       = isset( $meta[ $key_field ] ) && is_string( $meta[ $key_field ] ) ? $meta[ $key_field ] : '';
		}
		if ( '' === $key || is_wp_error( Nxt_Content_Types_Model_Paths::validate_key( $kind, $key ) ) ) {
			return true;
		}

		$deleted = Nxt_Content_Types_Model_Store::delete( $kind, $key );
		if ( is_wp_error( $deleted ) ) {
			return self::fail( $deleted );
		}

		delete_post_meta( $post_id, self::LAST_KEY_META );
		Nxt_Content_Types_Model_Cache::flush();

		return true;
	}

	/**
	 * Write every definition currently in the database out to files.
	 *
	 * @return array { exported:int, failed:int, errors:array<int, string> }
	 */
	public static function export_all() {
		$out = array(
			'exported' => 0,
			'failed'   => 0,
			'errors'   => array(),
		);
		if ( ! self::ready() ) {
			$out['errors'][] = __( 'The model directory is not available.', 'nexter-extension' );
			return $out;
		}

		$posts = get_posts(
			array(
				'post_type'              => Nxt_Content_Types_CPT::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $id ) {
			$result = self::export( (int) $id );
			if ( is_wp_error( $result ) ) {
				++$out['failed'];
				$out['errors'][] = $result->get_error_message();
				continue;
			}
			++$out['exported'];
		}

		return $out;
	}

	/**
	 * Rebuild the database copy from the files.
	 *
	 * @param array $args {
	 *     Optional. Import options.
	 *
	 *     @type bool $dry_run When true, report what would change and write nothing.
	 * }
	 * @return array { imported:int, skipped:int, problems:array, dry_run:bool }
	 */
	public static function import_all( $args = array() ) {
		$dry_run = ! empty( $args['dry_run'] );
		$out     = array(
			'imported' => 0,
			'skipped'  => 0,
			'problems' => array(),
			'dry_run'  => $dry_run,
		);
		if ( ! self::ready() ) {
			$out['problems'][] = array(
				'type'    => 'unavailable',
				'message' => __( 'The model directory is not available.', 'nexter-extension' ),
			);
			return $out;
		}

		$scan            = Nxt_Content_Types_Model_Validator::validate_scan( Nxt_Content_Types_Model_Loader::scan() );
		$out['problems'] = isset( $scan['problems'] ) ? $scan['problems'] : array();
		$definitions     = isset( $scan['definitions'] ) ? $scan['definitions'] : array();
		$was_suspended   = self::$suspended;
		self::$suspended = true;

		foreach ( $definitions as $kind => $defs ) {
			foreach ( (array) $defs as $key => $data ) {
				if ( $dry_run ) {
					++$out['imported'];
					continue;
				}
				$result = self::write_definition( $kind, $key, $data );
				if ( is_wp_error( $result ) ) {
					++$out['skipped'];
					$out['problems'][] = array(
						'type'    => 'import_failed',
						'kind'    => $kind,
						'key'     => $key,
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					);
					continue;
				}
				++$out['imported'];
			}
		}

		self::$suspended = $was_suspended;

		if ( ! $dry_run && class_exists( 'Nxt_Content_Types_Definitions_Repository' ) ) {
			Nxt_Content_Types_Definitions_Repository::get_instance()->rebuild();
		}

		return $out;
	}

	/**
	 * Put one definition into the database through the path that owns it.
	 *
	 * @param string $kind cpt | tax | group_fields.
	 * @param string $key  Definition key.
	 * @param array  $data Validated definition.
	 * @return int|WP_Error
	 */
	private static function write_definition( $kind, $key, $data ) {
		$meta_kind = Nxt_Content_Types_Model_Paths::kind_value( $kind );
		$post_id   = (int) Nxt_Content_Types_Admin_Logic::find_definition_id_by_key( $meta_kind, $key );

		if ( 'group_fields' === $kind ) {
			return self::write_group_via_rest( $post_id, $data );
		}

		// $confirm = true: the file IS the intended state, so a rebuild must not stop half way to ask whether a
		// type that holds content should be deactivated.
		$saved = ( 'cpt' === $kind )
			? Nxt_Content_Types_Admin_Logic::save_cpt( $post_id, $data, true )
			: Nxt_Content_Types_Admin_Logic::save_taxonomy( $post_id, $data, true );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return isset( $saved['id'] ) ? (int) $saved['id'] : $post_id;
	}

	/**
	 * Create or update a field group through its REST route.
	 *
	 * @param int   $post_id Existing definition post ID, or 0 to create.
	 * @param array $data    Validated group definition.
	 * @return int|WP_Error
	 */
	private static function write_group_via_rest( $post_id, $data ) {
		$route   = '/' . Nxt_Content_Types_REST_Field_Groups::NAMESPACE . '/field-groups' . ( $post_id ? '/' . $post_id : '' );
		$request = new WP_REST_Request( $post_id ? 'PUT' : 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		// set_body(), not set_body_params(): once the content type says JSON, WP_REST_Request reads parameters
		// from the decoded BODY and ignores POST params entirely.
		$request->set_body( wp_json_encode( array( 'data' => $data ) ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$body = $response->get_data();
		return isset( $body['id'] ) ? (int) $body['id'] : $post_id;
	}

	/**
	 * Whether a post is one of our definition posts.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_definition( $post_id ) {
		return class_exists( 'Nxt_Content_Types_CPT' )
			&& Nxt_Content_Types_CPT::POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * Map the `kind` stored in meta onto the model layer's kind.
	 *
	 * @param array $meta Definition meta.
	 * @return string Model kind, or '' if unrecognised.
	 */
	private static function model_kind( $meta ) {
		$value = isset( $meta['kind'] ) && '' !== $meta['kind'] ? (string) $meta['kind'] : 'cpt';
		foreach ( Nxt_Content_Types_Model_Paths::kind_values() as $kind => $meta_value ) {
			if ( $meta_value === $value ) {
				return $kind;
			}
		}
		return '';
	}

	/**
	 * Whether the model layer is loaded and its directory usable.
	 *
	 * @return bool
	 */
	private static function ready() {
		return class_exists( 'Nxt_Content_Types_Model_Store' )
			&& Nxt_Content_Types_Model_Store::is_writable();
	}

	/**
	 * Record an export failure without disturbing the save that triggered it.
	 *
	 * @param WP_Error $error The failure.
	 * @return WP_Error The same error, for the caller to return.
	 */
	private static function fail( $error ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'time'    => time(),
			),
			false
		);
		return $error;
	}

	/**
	 * Clear a previously recorded failure after a successful export.
	 */
	private static function clear_error() {
		if ( false !== get_option( self::ERROR_OPTION, false ) ) {
			delete_option( self::ERROR_OPTION );
		}
	}

	/**
	 * The last export failure, if any.
	 *
	 * @return array|false
	 */
	public static function last_error() {
		return get_option( self::ERROR_OPTION, false );
	}
}
