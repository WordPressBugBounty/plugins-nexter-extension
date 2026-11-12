<?php
/**
 * Build the module's row shape from files instead of from posts.
 *
 * @package Nexter_Extension
 * @subpackage Content_Types
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nxt_Content_Types_Model_Rows
 */
class Nxt_Content_Types_Model_Rows {

	/**
	 * Directory holding trashed definitions, relative to the site's model directory.
	 */
	const TRASH_DIR = '.trash';

	/**
	 * Build every row the repository would previously have queried out of the database.
	 *
	 * @param bool $include_trash Whether to include trashed definitions.
	 * @param int  $blog_id       Blog ID, or 0 for the current one.
	 * @return array<int, array{id: int, title: string, status: string, modified: string, meta: array, kind: string}>
	 */
	public static function build( $include_trash = false, $blog_id = 0 ) {
		$model       = Nxt_Content_Types_Model_Cache::get( $blog_id );
		$definitions = isset( $model['definitions'] ) ? $model['definitions'] : array();

		$live = array();
		foreach ( (array) $definitions as $kind => $defs ) {
			foreach ( (array) $defs as $key => $data ) {
				$live[ $kind ][ $key ] = $data;
			}
		}

		$trashed = $include_trash ? self::read_trash( $blog_id ) : array();

		// Live and trashed definitions are identified SEPARATELY, even on the same key.
		$map = Nxt_Content_Types_Model_Identity::map( $live, $trashed );

		$rows = array();
		$sets = array(
			'publish' => $live,
			'trash'   => $trashed,
		);
		foreach ( $sets as $status => $set ) {
			foreach ( $set as $kind => $defs ) {
				foreach ( $defs as $key => $data ) {
					$rows[] = self::row( $kind, $key, $data, $status, $map, $blog_id );
				}
			}
		}

		// The database query ordered newest first and the admin list still presents them that way.
		usort(
			$rows,
			function ( $a, $b ) {
				$cmp = strcmp( $b['modified'], $a['modified'] );
				// mtime granularity is one second, so a batch import would otherwise come back in
				// filesystem order. Fall back to the key for a stable, repeatable list.
				return 0 !== $cmp ? $cmp : strcmp( $a['title'], $b['title'] );
			}
		);

		return $rows;
	}

	/**
	 * Assemble one row.
	 *
	 * @param string $kind    Model kind: cpt | tax | group_fields.
	 * @param string $key     Definition key.
	 * @param array  $data    Definition contents.
	 * @param string $status  publish | trash.
	 * @param array  $map     Identity map from Model_Identity::map().
	 * @param int    $blog_id Blog ID.
	 * @return array
	 */
	private static function row( $kind, $key, $data, $status, $map, $blog_id ) {
		$kind_values = Nxt_Content_Types_Model_Paths::kind_values();
		$map_key     = Nxt_Content_Types_Model_Identity::map_key( $kind, $key, 'trash' === $status );

		$path  = 'trash' === $status
			? self::trash_file( $kind, $key, $blog_id )
			: Nxt_Content_Types_Model_Paths::file_for_key( $kind, $key, $blog_id );
		$mtime = ( is_string( $path ) && file_exists( $path ) ) ? (int) filemtime( $path ) : 0;

		// Post types and taxonomies name themselves with 'label'; a field group uses 'title'.
		$label = '';
		foreach ( array( 'label', 'title' ) as $label_field ) {
			if ( isset( $data[ $label_field ] ) && is_string( $data[ $label_field ] ) && '' !== $data[ $label_field ] ) {
				$label = $data[ $label_field ];
				break;
			}
		}

		return array(
			'id'       => isset( $map['ids'][ $map_key ] ) ? (int) $map['ids'][ $map_key ] : 0,
			'title'    => '' !== $label ? $label : $key,
			'status'   => $status,
			// Formatted like post_modified was, because consumers compare and display it as a string.
			'modified' => $mtime ? gmdate( 'Y-m-d H:i:s', $mtime ) : '0000-00-00 00:00:00',
			'meta'     => is_array( $data ) ? $data : array(),
			'kind'     => isset( $kind_values[ $kind ] ) ? $kind_values[ $kind ] : 'cpt',
		);
	}

	/**
	 * Absolute path to the trash directory for one kind.
	 *
	 * @param string $kind    Model kind.
	 * @param int    $blog_id Blog ID.
	 * @return string|WP_Error
	 */
	public static function trash_dir( $kind, $blog_id = 0 ) {
		$dirs = Nxt_Content_Types_Model_Paths::kind_dirs();
		if ( ! isset( $dirs[ $kind ] ) ) {
			return new WP_Error( 'nxt_model_bad_kind', __( 'Unknown definition kind.', 'nexter-extension' ) );
		}
		return Nxt_Content_Types_Model_Paths::site_dir( $blog_id ) . '/' . self::TRASH_DIR . '/' . $dirs[ $kind ];
	}

	/**
	 * Absolute path to one trashed definition file.
	 *
	 * @param string $kind    Model kind.
	 * @param string $key     Definition key.
	 * @param int    $blog_id Blog ID.
	 * @return string|WP_Error
	 */
	public static function trash_file( $kind, $key, $blog_id = 0 ) {
		$ok = Nxt_Content_Types_Model_Paths::validate_key( $kind, $key );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$dir = self::trash_dir( $kind, $blog_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		return $dir . '/' . $key . '.json';
	}

	/**
	 * Read every trashed definition.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array<string, array<string, array>>
	 */
	public static function read_trash( $blog_id = 0 ) {
		$out = array();
		foreach ( array_keys( Nxt_Content_Types_Model_Paths::kind_dirs() ) as $kind ) {
			$dir = self::trash_dir( $kind, $blog_id );
			if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( $dir . '/*.json' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				$data = Nxt_Content_Types_Model_Store::read_file( $file );
				if ( is_wp_error( $data ) ) {
					continue;
				}
				$key_field = Nxt_Content_Types_Model_Paths::key_field( $kind );
				$key       = isset( $data[ $key_field ] ) && is_string( $data[ $key_field ] ) ? $data[ $key_field ] : '';
				if ( '' === $key ) {
					continue;
				}
				$out[ $kind ][ $key ] = $data;
			}
		}
		return $out;
	}
}
