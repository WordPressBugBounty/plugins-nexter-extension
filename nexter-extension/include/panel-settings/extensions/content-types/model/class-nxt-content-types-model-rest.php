<?php
/**
 * REST surface for the file-first model.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Model_REST
 */
class Nxt_Content_Types_Model_REST {

	const NAMESPACE = 'content-types/v1';

	/**
	 * Single instance.
	 *
	 * @var Nxt_Content_Types_Model_REST|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Nxt_Content_Types_Model_REST
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Same gate as the rest of the module.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/model/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/model/export',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_export' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'force' => array(
							'description'       => __( 'Export anyway on a site that has already migrated, overwriting current definitions with the stale database copy.', 'nexter-extension' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/model/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_import' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'dry_run' => array(
							'description'       => __( 'Report what would change without writing anything.', 'nexter-extension' ),
							'type'              => 'boolean',
							// Defaults to the safe direction on purpose: a caller that forgets the
							// parameter gets a preview, not an overwrite of every definition.
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/model/repair',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_repair' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'dry_run' => array(
							'description'       => __( 'List the files that would be renamed without renaming them.', 'nexter-extension' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * What the file store currently holds, and where it disagrees with the database.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$writable = Nxt_Content_Types_Model_Store::is_writable();
		$scan     = Nxt_Content_Types_Model_Validator::validate_scan( Nxt_Content_Types_Model_Loader::scan() );

		$counts = array();
		foreach ( $scan['definitions'] as $kind => $defs ) {
			$counts[ $kind ] = count( $defs );
		}

		return rest_ensure_response(
			array(
				'writable'    => (bool) $writable,
				'directory'   => self::relative_dir(),
				'counts'      => $counts,
				'problems'    => array_values( $scan['problems'] ),
				'repairable'  => array_values( isset( $scan['repairs'] ) ? $scan['repairs'] : array() ),
				'differences' => self::differences( $scan['definitions'] ),
				'last_error'  => Nxt_Content_Types_Model_Sync::last_error(),
			)
		);
	}

	/**
	 * Write every definition in the database out to a file.
	 *
	 * @param WP_REST_Request|null $request Request; carries the `force` override.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_export( $request = null ) {
		// This route writes the DATABASE copy over the files.
		$force = $request instanceof WP_REST_Request ? (bool) $request->get_param( 'force' ) : false;
		if ( ! $force && get_option( Nxt_Content_Types_Model_Sync::MIGRATED_OPTION ) ) {
			return new WP_Error(
				'nxt_model_export_would_overwrite',
				__( 'This site already stores its definitions in files, so the database copy is out of date. Exporting it would overwrite current definitions with older ones. Pass force=true only if you intend exactly that.', 'nexter-extension' ),
				array( 'status' => 409 )
			);
		}

		$result = Nxt_Content_Types_Model_Sync::export_all();
		if ( $result['failed'] > 0 && 0 === $result['exported'] ) {
			return new WP_Error(
				'nxt_model_export_failed',
				implode( ' ', $result['errors'] ),
				array( 'status' => 500 )
			);
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Rebuild the database from the files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_import( $request ) {
		return rest_ensure_response(
			Nxt_Content_Types_Model_Sync::import_all( array( 'dry_run' => (bool) $request->get_param( 'dry_run' ) ) )
		);
	}

	/**
	 * Rename files whose filename no longer matches the key stored inside them.
	 *
	 * @param WP_REST_Request|null $request Request; carries `dry_run`.
	 * @return WP_REST_Response
	 */
	public function run_repair( $request = null ) {
		$scan    = Nxt_Content_Types_Model_Loader::scan();
		$repairs = isset( $scan['repairs'] ) ? $scan['repairs'] : array();
		// A dry run reports the planned renames and touches nothing.
		if ( $request instanceof WP_REST_Request && $request->get_param( 'dry_run' ) ) {
			return rest_ensure_response(
				array(
					'dry_run'    => true,
					'repairable' => array_values( $repairs ),
				)
			);
		}
		$result = Nxt_Content_Types_Model_Loader::repair( $repairs );
		Nxt_Content_Types_Model_Cache::flush();
		return rest_ensure_response( $result );
	}

	/**
	 * Which definitions exist on only one side, or differ between the two.
	 *
	 * @param array $definitions Validated definitions from the scan, keyed by kind then key.
	 * @return array<int, array>
	 */
	private static function differences( $definitions ) {
		if ( ! class_exists( 'Nxt_Content_Types_Definitions_Repository' ) ) {
			return array();
		}

		$db = array();
		foreach ( Nxt_Content_Types_Definitions_Repository::get_instance()->get_rows() as $row ) {
			$meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
			$kind = self::model_kind( $row['kind'] );
			if ( '' === $kind ) {
				continue;
			}
			$key_field = Nxt_Content_Types_Model_Paths::key_field( $kind );
			$key       = isset( $meta[ $key_field ] ) ? (string) $meta[ $key_field ] : '';
			if ( '' === $key ) {
				continue;
			}
			$db[ $kind ][ $key ] = $meta;
		}

		$out = array();
		foreach ( Nxt_Content_Types_Model_Paths::kind_dirs() as $kind => $unused_dir ) {
			$on_disk = isset( $definitions[ $kind ] ) ? $definitions[ $kind ] : array();
			$in_db   = isset( $db[ $kind ] ) ? $db[ $kind ] : array();

			foreach ( $on_disk as $key => $data ) {
				if ( ! isset( $in_db[ $key ] ) ) {
					$out[] = self::difference( $kind, $key, 'file_only' );
					continue;
				}
				// Compared through the encoder so the two sides are normalised the same way —
				// key order and the schema marker are not differences.
				if ( Nxt_Content_Types_Model_Store::encode( $data ) !== Nxt_Content_Types_Model_Store::encode( $in_db[ $key ] ) ) {
					$out[] = self::difference( $kind, $key, 'different' );
				}
			}
			foreach ( $in_db as $key => $unused_meta ) {
				if ( ! isset( $on_disk[ $key ] ) ) {
					$out[] = self::difference( $kind, $key, 'database_only' );
				}
			}
		}

		return $out;
	}

	/**
	 * One difference entry, with the sentence an admin needs to decide what to do.
	 *
	 * @param string $kind  Model kind.
	 * @param string $key   Definition key.
	 * @param string $state file_only | database_only | different.
	 * @return array
	 */
	private static function difference( $kind, $key, $state ) {
		$messages = array(
			'file_only'     => __( 'On disk but not in the database — importing would add it.', 'nexter-extension' ),
			'database_only' => __( 'In the database but not on disk — exporting would add the file.', 'nexter-extension' ),
			'different'     => __( 'The file and the database disagree. Whichever direction you run overwrites the other.', 'nexter-extension' ),
		);
		return array(
			'kind'    => $kind,
			'key'     => $key,
			'state'   => $state,
			'message' => isset( $messages[ $state ] ) ? $messages[ $state ] : '',
		);
	}

	/**
	 * Map the meta `kind` onto the model layer's kind.
	 *
	 * @param string $value Value from meta.
	 * @return string Model kind, or '' if unrecognised.
	 */
	private static function model_kind( $value ) {
		$value = '' !== (string) $value ? (string) $value : 'cpt';
		foreach ( Nxt_Content_Types_Model_Paths::kind_values() as $kind => $meta_value ) {
			if ( $meta_value === $value ) {
				return $kind;
			}
		}
		return '';
	}

	/**
	 * The model directory as a path relative to wp-content, for display.
	 *
	 * @return string
	 */
	private static function relative_dir() {
		$dir = Nxt_Content_Types_Model_Paths::site_dir();
		$wp  = wp_normalize_path( WP_CONTENT_DIR );
		return 0 === strpos( $dir, $wp ) ? ltrim( substr( $dir, strlen( $wp ) ), '/' ) : basename( $dir );
	}
}
