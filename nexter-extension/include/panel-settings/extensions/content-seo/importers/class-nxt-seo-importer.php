<?php
/**
 * Content SEO — Import framework for third-party SEO plugin data.
 *
 * Orchestrates source importers (Yoast first; Rank Math / AIOSEO / SureRank later) behind
 * three REST actions: detect → dry-run → run. The run is batched and resume-able: every
 * migrated post/term is stamped with a marker meta, so an interrupted import continues
 * where it stopped and a re-run never double-imports. Import is NON-destructive by design —
 * source data is never deleted here; cleanup stays a separate, explicit user action.
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

	/** Default objects per run request. Filterable — see rest_run(). */
	const DEFAULT_BATCH = 100;

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
		return rest_ensure_response( call_user_func( array( $class, 'dry_run' ) ) );
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

		$state  = self::get_state( $slug );
		$report = array(
			'source'   => $slug,
			'imported' => array(),
			'skipped'  => array(),
			'log'      => array(),
		);

		// One-shot categories first: cheap, and settings (robots defaults, templates) should be
		// in place BEFORE per-object rows land, so "inherit" values resolve the same way they
		// did under the source plugin.
		if ( in_array( 'settings', $categories, true ) && empty( $state['settings_done'] ) ) {
			$result                         = call_user_func( array( $class, 'import_settings' ) );
			$report['imported']['settings'] = $result;
			$state['settings_done']         = true;
		}

		if ( in_array( 'redirections', $categories, true ) && empty( $state['redirections_done'] ) ) {
			$result                             = call_user_func( array( $class, 'import_redirections' ) );
			$report['imported']['redirections'] = $result;
			$state['redirections_done']         = true;
		}

		$remaining_posts = 0;
		if ( in_array( 'postmeta', $categories, true ) ) {
			$result                      = call_user_func( array( $class, 'import_posts_batch' ), $batch );
			$report['imported']['posts'] = $result;
			$remaining_posts             = isset( $result['remaining'] ) ? (int) $result['remaining'] : 0;
		}

		$remaining_terms = 0;
		if ( in_array( 'termmeta', $categories, true ) && 0 === $remaining_posts ) {
			// Terms start only after posts finish so every run request does a predictable
			// amount of work (one batch), not two.
			$result                      = call_user_func( array( $class, 'import_terms_batch' ), $batch );
			$report['imported']['terms'] = $result;
			$remaining_terms             = isset( $result['remaining'] ) ? (int) $result['remaining'] : 0;
		}

		$state['last_run'] = time();
		self::save_state( $slug, $state );

		$report['done']      = ( 0 === $remaining_posts && 0 === $remaining_terms );
		$report['remaining'] = array(
			'posts' => $remaining_posts,
			'terms' => $remaining_terms,
		);

		return rest_ensure_response( $report );
	}

	/**
	 * POST /seo/import/reset — clear markers and one-shot flags so a re-import starts clean.
	 * Does NOT touch imported values; a re-run simply overwrites them.
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
		$marker = self::MARKER_PREFIX . $slug;
		delete_metadata( 'post', 0, $marker, '', true );
		delete_metadata( 'term', 0, $marker, '', true );

		$all = get_option( self::STATE_OPTION, array() );
		unset( $all[ $slug ] );
		update_option( self::STATE_OPTION, $all, false );

		return rest_ensure_response( array( 'reset' => true ) );
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
}
