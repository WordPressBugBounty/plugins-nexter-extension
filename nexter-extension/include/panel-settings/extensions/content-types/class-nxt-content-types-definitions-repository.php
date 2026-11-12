<?php
/**
 * Single source of truth for nxt_content_types definition posts.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Definitions_Repository
 */
class Nxt_Content_Types_Definitions_Repository {

	/**
	 * Transient key for the cached definition rows.
	 */
	const TRANSIENT_KEY = 'nxt_ct_definitions_v1';

	/**
	 * Transient lifetime. Long-lived: every write flushes it explicitly.
	 */
	const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Single instance.
	 *
	 * @var Nxt_Content_Types_Definitions_Repository|null
	 */
	private static $instance = null;

	/**
	 * Per-request memoized rows (avoids a second transient read in one request).
	 *
	 * @var array|null
	 */
	private $rows = null;

	/**
	 * Per-request memo for the include-trash rows.
	 *
	 * @var array|null
	 */
	private $rows_with_trash = null;

	/**
	 * Get instance.
	 *
	 * @return Nxt_Content_Types_Definitions_Repository
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers cache-invalidation hooks so any write to a
	 * definition post (REST, WP-CLI, direct wp_update_post) drops the cache.
	 */
	private function __construct() {
		add_action( 'save_post_' . Nxt_Content_Types_CPT::POST_TYPE, array( $this, 'flush' ), 10, 0 );
		// before_delete_post, not deleted_post: the post still exists, so get_post_type() resolves.
		add_action( 'before_delete_post', array( $this, 'maybe_flush_for_post' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'maybe_flush_for_post' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'maybe_flush_for_post' ), 10, 1 );
		// Direct meta writes (WP-CLI, importers, raw update_post_meta) bypass save_post; flush when
		// the definition meta key changes so the 12h transient can't serve stale data.
		add_action( 'added_post_meta', array( $this, 'maybe_flush_for_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_flush_for_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_flush_for_meta' ), 10, 3 );
		// Every hook above is a post/meta hook, and definitions are mirrored to JSON files:
		// a file-based write fires none of them, so the cache kept serving the pre-write state
		// until something unrelated flushed it. Toggling a group active was the visible symptom.
		// These are the module's own definition-change actions, the same seam the abilities
		// cache already invalidates on.
		foreach ( array( 'nxt/definition_saved', 'nxt/definition_changed', 'nxt/definition_deleted', 'nxt/definition_deleted_permanently', 'nxt/definition_restored' ) as $nxt_def_hook ) {
			add_action( $nxt_def_hook, array( $this, 'flush' ), 10, 0 );
		}
	}

	/**
	 * Flush only when the affected post is one of our definition posts.
	 *
	 * @param int $post_id Post ID.
	 */
	public function maybe_flush_for_post( $post_id ) {
		if ( Nxt_Content_Types_CPT::POST_TYPE === get_post_type( $post_id ) ) {
			$this->flush();
		}
	}

	/**
	 * Flush when the definition meta key is written directly (added/updated/deleted post meta).
	 *
	 * @param int|int[] $meta_id   Meta row ID(s) (unused).
	 * @param int       $object_id Post ID (unused).
	 * @param string    $meta_key  Meta key that changed.
	 */
	public function maybe_flush_for_meta( $meta_id, $object_id, $meta_key ) {
		if ( Nxt_Content_Types_CPT::get_meta_key() === $meta_key ) {
			$this->flush();
		}
	}

	/**
	 * All definition rows (publish + draft), ordered newest first.
	 *
	 * @param array $args Optional. 'include_trash' to include trashed rows.
	 * @return array<int, array>
	 */
	public function get_rows( $args = array() ) {
		if ( ! empty( $args['include_trash'] ) ) {
			// Uniqueness checks must also see trashed definitions — a trashed key stays reserved so a new definition
			// can't silently collide with (and shadow) it.
			if ( null === $this->rows_with_trash ) {
				$this->rows_with_trash = $this->build_rows( array( 'publish', 'draft', 'trash' ) );
			}
			return $this->rows_with_trash;
		}
		if ( null !== $this->rows ) {
			return $this->rows;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			$this->rows = $cached;
			return $cached;
		}

		$rows = $this->build_rows();
		set_transient( self::TRANSIENT_KEY, $rows, self::TRANSIENT_TTL );
		$this->rows = $rows;
		return $rows;
	}

	/**
	 * Rows grouped by kind: cpt, tax, group_fields.
	 *
	 * @return array{cpt: array, tax: array, group_fields: array}
	 */
	public function by_kind() {
		$out = array(
			'cpt'          => array(),
			'tax'          => array(),
			'group_fields' => array(),
		);
		foreach ( $this->get_rows() as $row ) {
			if ( 'taxonomy' === $row['kind'] ) {
				$out['tax'][] = $row;
			} elseif ( 'group_fields' === $row['kind'] ) {
				$out['group_fields'][] = $row;
			} else {
				$out['cpt'][] = $row;
			}
		}
		return $out;
	}

	/**
	 * Assemble the compact rows every consumer of this module reads.
	 *
	 * @param array $statuses Which statuses to include; only the presence of 'trash' is meaningful.
	 * @return array<int, array>
	 */
	private function build_rows( $statuses = array( 'publish', 'draft' ) ) {
		return Nxt_Content_Types_Model_Rows::build( in_array( 'trash', (array) $statuses, true ) );
	}

	/**
	 * Drop the cache (transient + per-request memo + legacy object-cache keys).
	 */
	public function flush() {
		$this->rows            = null;
		$this->rows_with_trash = null;
		delete_transient( self::TRANSIENT_KEY );

		if ( class_exists( 'Nxt_Content_Types_Register' ) ) {
			wp_cache_delete( Nxt_Content_Types_Register::CACHE_KEY_CPT, Nxt_Content_Types_Register::CACHE_GROUP );
			wp_cache_delete( Nxt_Content_Types_Register::CACHE_KEY_TAX, Nxt_Content_Types_Register::CACHE_GROUP );
			// Sanitized register-args cache is derived from these rows — drop it too.
			delete_transient( Nxt_Content_Types_Register::REGISTERED_ARGS_TRANSIENT );
		}
	}

	/**
	 * Drop the cache and rebuild it immediately from the database. Public entry point for imports,
	 * WP-CLI, or any out-of-band writer that needs the freshly-persisted rows in the same request.
	 *
	 * @return array<int, array> The rebuilt rows.
	 */
	public function rebuild() {
		$this->flush();
		return $this->get_rows();
	}
}
