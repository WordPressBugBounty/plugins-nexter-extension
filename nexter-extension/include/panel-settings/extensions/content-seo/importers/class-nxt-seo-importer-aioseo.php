<?php
/**
 * Content SEO — All in One SEO importer.
 *
 * Reads AIOSEO's stored data straight from the database, so it works whether AIOSEO is active,
 * deactivated, or removed with its data left behind. Every mapping table is filterable so an
 * edge case can be fixed without a release.
 *
 * AIOSEO is the first source whose per-object SEO does NOT live in post/term meta: it keeps one
 * row per post in its own {prefix}aioseo_posts table, and per-term rows in {prefix}aioseo_terms.
 * Selection therefore joins that table instead of using the framework's meta-key selectors, but
 * the resume marker is still ordinary post/term meta, so retries stay idempotent exactly as they
 * do for the other sources.
 *
 * Three storage areas are edition-dependent and are each checked before use:
 * - {prefix}aioseo_posts     — every edition;
 * - {prefix}aioseo_terms     — Pro only, so term import is skipped on Lite. It is also a SUBSET
 *   of the posts table (no schema columns at all), so every query is built from the table's
 *   real column list rather than from the posts-table shape;
 * - {prefix}aioseo_redirects — created by Pro's redirect module, absent on Lite.
 *
 * Two source flags gate what may be read, and ignoring either would invent values the user never
 * chose:
 * - robots_default = 1 means "inherit the global robots defaults", so per-object robots are only
 *   imported when it is 0;
 * - twitter_use_og = 1 means the Twitter tags mirror the OG tags, so the Twitter columns hold
 *   text AIOSEO never outputs and must not be copied.
 *
 * Deliberately NOT imported (reported as skipped, never silently dropped):
 * - TruSEO scores, page analysis and keyphrases — Nexter runs its own audit;
 * - schema field DATA — only the schema TYPE maps across;
 * - per-locale option overrides (aioseo_options_localized) — Nexter stores one value per
 *   setting, so picking a language would be arbitrary;
 * - link-shaped smart tags (#post_link, #site_link, …) — those render an HTML anchor, which no
 *   Nexter variable produces.
 *
 * @package Nexter Extensions
 * @since 4.7.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Importer_Aioseo
 */
class Nexter_Content_SEO_Importer_Aioseo {

	const SOURCE       = 'aioseo';
	const OPTION_NAME  = 'aioseo_options';
	const OPTION_DYN   = 'aioseo_options_dynamic';
	const OPTION_LOCAL = 'aioseo_options_localized';

	/**
	 * Human label for the sources list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'All in One SEO';
	}

	/*
	---------------------------------------------------------------------
	 * Storage locations
	 * -------------------------------------------------------------------
	 */

	/**
	 * One AIOSEO table name, or '' when that table is not installed.
	 *
	 * The terms and redirects tables ship with Pro and the Redirects addon respectively, so a
	 * Lite site legitimately has neither. Checking on use keeps a missing table a skipped
	 * category rather than a fatal query.
	 *
	 * @param string $suffix Table suffix, e.g. 'posts'.
	 * @return string
	 */
	private static function table( $suffix ) {
		global $wpdb;

		static $cache = array();
		if ( isset( $cache[ $suffix ] ) ) {
			return $cache[ $suffix ];
		}

		$name             = $wpdb->prefix . 'aioseo_' . $suffix;
		$found            = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cache[ $suffix ] = ( $found === $name ) ? $name : '';

		return $cache[ $suffix ];
	}

	/**
	 * Source columns copied straight across, mapped to their Nexter destination.
	 *
	 * @return array<string,string>
	 */
	private static function column_map() {
		$map = array(
			'title'               => Nexter_Content_SEO_Social_Meta::META_TITLE,
			'description'         => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'canonical_url'       => Nexter_Content_SEO_Canonical::META_CANONICAL,
			'og_title'            => Nexter_Content_SEO_Social_Meta::META_FB_TITLE,
			'og_description'      => Nexter_Content_SEO_Social_Meta::META_FB_DESC,
			'twitter_title'       => Nexter_Content_SEO_Social_Meta::META_TW_TITLE,
			'twitter_description' => Nexter_Content_SEO_Social_Meta::META_TW_DESC,
		);

		/**
		 * Filter the AIOSEO column → Nexter meta key map.
		 *
		 * @param array<string,string> $map source column => NE meta key.
		 */
		return apply_filters( 'nexter_content_seo_import_aioseo_column_map', $map );
	}

	/**
	 * Columns read for their value but resolved through extra logic rather than a direct copy.
	 *
	 * @return string[]
	 */
	private static function support_columns() {
		return array(
			'robots_default',
			'robots_noindex',
			'robots_nofollow',
			'robots_noarchive',
			'twitter_use_og',
			'og_image_type',
			'og_image_custom_url',
			'og_image_url',
			'twitter_image_type',
			'twitter_image_custom_url',
			'twitter_image_url',
			'schema_type',
			'schema_type_options',
			'schema',
		);
	}

	/**
	 * A plain SQL identifier? Column names are interpolated into the queries below — they cannot
	 * be prepared values — so a filtered map that adds anything else is dropped, not escaped.
	 *
	 * @param string $column Candidate column name.
	 * @return bool
	 */
	private static function is_identifier( $column ) {
		return 1 === preg_match( '/^[a-z_][a-z0-9_]{0,63}$/', (string) $column );
	}

	/**
	 * Every column the import reads.
	 *
	 * @return string[]
	 */
	private static function read_columns() {
		$columns = array_merge( array_keys( self::column_map() ), self::support_columns() );

		return array_values( array_filter( array_unique( $columns ), array( __CLASS__, 'is_identifier' ) ) );
	}

	/**
	 * Columns whose non-empty value means "this row is worth importing".
	 *
	 * @return string[]
	 */
	private static function data_columns() {
		$columns = array_merge(
			array_keys( self::column_map() ),
			array( 'og_image_custom_url', 'twitter_image_custom_url' )
		);

		return array_values( array_filter( array_unique( $columns ), array( __CLASS__, 'is_identifier' ) ) );
	}

	/**
	 * The columns one AIOSEO table actually has, keyed by name.
	 *
	 * The Pro terms table lacks the schema columns the posts table has, and the redirects table
	 * has grown over versions, so nothing below assumes a column exists without asking.
	 *
	 * @param string $table Full table name ('' when the table is absent).
	 * @return array<string,bool>
	 */
	private static function table_columns( $table ) {
		global $wpdb;

		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		$cache[ $table ] = array();
		if ( '' !== $table ) {
			foreach ( (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) as $column ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved from $wpdb->prefix.
				$cache[ $table ][ (string) $column ] = true;
			}
		}

		return $cache[ $table ];
	}

	/**
	 * Keep only the columns a table really has.
	 *
	 * @param string[] $columns Wanted columns.
	 * @param string   $table   Full table name.
	 * @return string[]
	 */
	private static function existing_columns( $columns, $table ) {
		$have = self::table_columns( $table );

		return array_values(
			array_filter(
				$columns,
				static function ( $column ) use ( $have ) {
					return isset( $have[ $column ] );
				}
			)
		);
	}

	/**
	 * SQL fragment matching rows that carry something importable. AIOSEO writes a row for every
	 * post it has ever analysed, so without this the whole site would report as pending.
	 *
	 * @param string $alias Table alias.
	 * @param string $table Full table name, so only its real columns are referenced.
	 * @return string
	 */
	private static function has_data_sql( $alias, $table ) {
		$have  = self::table_columns( $table );
		$parts = array();
		foreach ( self::existing_columns( self::data_columns(), $table ) as $column ) {
			$parts[] = "( `{$alias}`.`{$column}` IS NOT NULL AND `{$alias}`.`{$column}` != '' )";
		}
		// A row can also be meaningful without any text: an explicit robots override, or a
		// schema type chosen by hand (posts only — the terms table has no schema columns).
		if ( isset( $have['robots_default'] ) ) {
			$parts[] = "`{$alias}`.`robots_default` = 0";
		}
		if ( isset( $have['schema_type'] ) ) {
			$parts[] = "( `{$alias}`.`schema_type` IS NOT NULL AND `{$alias}`.`schema_type` NOT IN ( '', 'default' ) )";
		}
		if ( empty( $parts ) ) {
			// Nothing this importer understands is stored here, so nothing can be pending.
			return '0';
		}

		return '( ' . implode( ' OR ', $parts ) . ' )';
	}

	/**
	 * AIOSEO data present at all?
	 *
	 * @return bool
	 */
	public static function detect() {
		global $wpdb;

		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			return true;
		}

		$table = self::table( 'posts' );
		if ( '' === $table ) {
			return false;
		}

		return (bool) $wpdb->get_var( "SELECT id FROM `{$table}` LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved from $wpdb->prefix.
	}

	/**
	 * Rows still to import, and the batch of ids to work on.
	 *
	 * Mirrors the framework's meta-key selectors, but the source of truth is AIOSEO's own table.
	 * The marker stays on the WP object, so an interrupted run resumes where it stopped and a
	 * finished row is never revisited.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $limit       Batch size; 0 counts only.
	 * @return array{remaining:int, ids:int[]}
	 */
	private static function get_unimported_ids( $object_type, $limit ) {
		global $wpdb;

		$is_post = ( 'post' === $object_type );
		$table   = self::table( $is_post ? 'posts' : 'terms' );
		if ( '' === $table ) {
			return array(
				'remaining' => 0,
				'ids'       => array(),
			);
		}

		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$id_column = $is_post ? 'post_id' : 'term_id';
		$has_data  = self::has_data_sql( 'ap', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names come from $wpdb->prefix and the validated identifier whitelist; the only value goes through prepare().
		if ( $is_post ) {
			$where = $wpdb->prepare(
				"FROM `{$table}` ap
				 INNER JOIN {$wpdb->posts} p ON p.ID = ap.post_id
				 WHERE p.post_status NOT IN ('auto-draft','inherit')
				   AND {$has_data}
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = ap.post_id AND mk.meta_key = %s )",
				$marker
			);
		} else {
			$where = $wpdb->prepare(
				"FROM `{$table}` ap
				 INNER JOIN {$wpdb->terms} t ON t.term_id = ap.term_id
				 WHERE {$has_data}
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->termmeta} mk WHERE mk.term_id = ap.term_id AND mk.meta_key = %s )",
				$marker
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$remaining = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT ap.{$id_column} ) {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a prepare() fragment.
		$ids       = array();
		if ( $limit > 0 && $remaining > 0 ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col( "SELECT DISTINCT ap.{$id_column} {$where} ORDER BY ap.{$id_column} ASC LIMIT " . (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is already a prepare() fragment; LIMIT is an int cast.
			);
		}

		return array(
			'remaining' => $remaining,
			'ids'       => $ids,
		);
	}

	/**
	 * One AIOSEO row as an array, or null.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array<string,mixed>|null
	 */
	private static function get_row( $object_type, $object_id ) {
		global $wpdb;

		$is_post = ( 'post' === $object_type );
		$table   = self::table( $is_post ? 'posts' : 'terms' );
		if ( '' === $table ) {
			return null;
		}

		$wanted = self::existing_columns( self::read_columns(), $table );
		if ( empty( $wanted ) ) {
			return null;
		}
		$columns   = '`' . implode( '`, `', $wanted ) . '`';
		$id_column = $is_post ? 'post_id' : 'term_id';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; columns from the validated identifier whitelist.
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT {$columns} FROM `{$table}` WHERE `{$id_column}` = %d ORDER BY id DESC LIMIT 1",
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Object counts for the detect card and the dry run.
	 *
	 * @return array<string,mixed>
	 */
	public static function counts() {
		$posts = self::get_unimported_ids( 'post', 0 );
		$terms = self::get_unimported_ids( 'term', 0 );

		return array(
			'posts_remaining' => $posts['remaining'],
			'terms_remaining' => $terms['remaining'],
			'redirections'    => self::count_redirects(),
			'has_settings'    => ( false !== get_option( self::OPTION_NAME, false ) ),
			'plugin_active'   => defined( 'AIOSEO_VERSION' ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Preview
	 * -------------------------------------------------------------------
	 */

	/**
	 * Full preview with zero writes.
	 *
	 * @return array<string,mixed>
	 */
	public static function dry_run() {
		global $wpdb;

		$counts           = self::counts();
		$settings_preview = Nexter_Content_SEO_Importer::merge_settings( self::build_settings( $unknown_vars ) );

		$current = get_option( Nexter_Content_SEO::OPTION_NAME, array() );
		$diff    = array();
		foreach ( $settings_preview as $key => $new_value ) {
			$old = isset( $current[ $key ] ) ? $current[ $key ] : null;
			if ( $old !== $new_value ) {
				$diff[ $key ] = array(
					'from' => $old,
					'to'   => $new_value,
				);
			}
		}

		// Per-column coverage, so the preview says which fields actually carry data.
		$coverage = array();
		$table    = self::table( 'posts' );
		if ( '' !== $table ) {
			foreach ( self::data_columns() as $column ) {
				$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` != ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; column from the validated whitelist.
				if ( $n > 0 ) {
					$coverage[ $column ] = $n;
				}
			}
		}

		$skips = array();
		$notes = array();

		if ( '' !== $table ) {
			$analysis = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE ( keyphrases IS NOT NULL AND keyphrases != '' ) OR seo_score > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( $analysis > 0 ) {
				$skips[] = array(
					'key'    => __( 'TruSEO scores and keyphrases', 'nexter-extension' ),
					'count'  => $analysis,
					'reason' => __( 'AIOSEO analysis results are not imported — Nexter runs its own audit.', 'nexter-extension' ),
				);
			}

			// Only rows carrying schema the user actually built. AIOSEO stamps a default schema
			// blob ({"graphs":[]}) on every row it touches, so counting non-empty `schema` would
			// tell the user hundreds of posts have schema data they are about to lose.
			$schema_data = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE `schema` LIKE '%\"graphs\":[{%' OR ( `schema_type_options` IS NOT NULL AND `schema_type_options` NOT IN ( '', '[]', '{}' ) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( $schema_data > 0 ) {
				$skips[] = array(
					'key'    => __( 'Schema field values', 'nexter-extension' ),
					'count'  => $schema_data,
					'reason' => __( 'The schema type is imported; the individual schema field values are not — Nexter builds those from its own field set.', 'nexter-extension' ),
				);
			}

			// Twitter text that AIOSEO itself never outputs, because the row mirrors OG instead.
			$mirrored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE twitter_use_og = 1 AND ( ( twitter_title IS NOT NULL AND twitter_title != '' ) OR ( twitter_description IS NOT NULL AND twitter_description != '' ) )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( $mirrored > 0 ) {
				$skips[] = array(
					'key'    => __( 'Twitter title and description', 'nexter-extension' ),
					'count'  => $mirrored,
					'reason' => __( 'These posts are set to use the Facebook/OG values for Twitter, so the stored Twitter text is not what AIOSEO shows. The Facebook values are imported instead.', 'nexter-extension' ),
				);
			}

			// Robots the source is inheriting rather than overriding.
			$inherited = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE robots_default = 1 AND ( robots_noindex = 1 OR robots_nofollow = 1 OR robots_noarchive = 1 )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( $inherited > 0 ) {
				$notes[] = array(
					'key'    => __( 'Robots settings left as inherited', 'nexter-extension' ),
					'count'  => $inherited,
					'reason' => __( 'These posts are set to follow your global robots defaults, so their stored per-post values are not active in AIOSEO. They are left inherited in Nexter too rather than becoming explicit overrides.', 'nexter-extension' ),
				);
			}
		}

		if ( '' === self::table( 'terms' ) ) {
			$notes[] = array(
				'key'    => __( 'Category and tag SEO', 'nexter-extension' ),
				'count'  => 0,
				'reason' => __( 'AIOSEO stores term SEO in a table that only its Pro version creates. This site does not have it, so there is no term data to import.', 'nexter-extension' ),
			);
		}

		$redirect_notes = self::redirect_preview();
		$skips          = array_merge( $skips, $redirect_notes['skipped'] );
		$notes          = array_merge( $notes, $redirect_notes['notes'] );

		if ( ! empty( get_option( self::OPTION_LOCAL, array() ) ) ) {
			$skips[] = array(
				'key'    => __( 'Per-language setting overrides', 'nexter-extension' ),
				'count'  => 1,
				'reason' => __( 'AIOSEO stores a separate copy of some settings per language. Nexter keeps one value per setting, so the main values are imported and the per-language copies are left behind.', 'nexter-extension' ),
			);
		}

		// Robots conflicts are counted and reported separately: a page deliberately kept out of
		// search is a very different thing to lose than a title, and the preview never said so.
		$robots_conflicts = self::count_conflicts( 'robots' );
		if ( $robots_conflicts > 0 ) {
			$skips[] = array(
				'key'    => __( 'Your existing robots settings', 'nexter-extension' ),
				'count'  => $robots_conflicts,
				'reason' => __( 'These posts already have a Nexter No Index, No Follow or No Archive setting. The import never overwrites what you set yourself, so yours is kept.', 'nexter-extension' ),
			);
		}

		$user_meta = Nexter_Content_SEO_Importer::user_meta_skip( self::label(), 'aioseo_' );
		if ( $user_meta ) {
			$skips[] = $user_meta;
		}
		// The preview runs the very same import in dry-run mode rather than re-deriving what it
		// would do, so the two can never disagree about the outcome. Nothing is written: the only
		// write in that path is save_rules(), which the flag skips.
		$redirect_dry = self::import_redirections( true );
		if ( ! empty( $redirect_dry['loop_warning'] ) ) {
			$notes[] = array(
				'key'    => __( 'Redirect loop', 'nexter-extension' ),
				'count'  => 1,
				'reason' => sprintf(
					/* translators: %s: the loop the detector found, already a full sentence. */
					__( 'These redirects contain a chain that never resolves, so those URLs would keep bouncing. %s They are still imported — fix the rule in the source plugin before importing, or under Redirections afterwards.', 'nexter-extension' ),
					$redirect_dry['loop_warning']
				),
			);
		}
		if ( ! empty( $redirect_dry['skipped_over_limit'] ) ) {
			$skips[] = array(
				'key'    => __( 'Redirects above the rule limit', 'nexter-extension' ),
				'count'  => (int) $redirect_dry['skipped_over_limit'],
				'reason' => sprintf(
					/* translators: 1: product name, 2: maximum number of redirect rules. */
					__( '%1$s stores up to %2$d redirect rules, because the front end compiles and walks the whole set on every request. These ones are over that limit and are not imported.', 'nexter-extension' ),
					Nexter_Content_SEO_Importer::brand(),
					Nexter_Content_SEO_Redirection::max_rules()
				),
			);
		}
		$multilingual = Nexter_Content_SEO_Importer::multilingual_skip( self::label() );
		if ( $multilingual ) {
			$skips[] = $multilingual;
		}

		// Nexter keeps ONE title/description template for all post types and one for all
		// taxonomies, so only AIOSEO's Post and Category templates are imported and they then
		// apply site-wide. Any type AIOSEO configured differently is named here rather than
		// changing silently.
		$other_types = self::other_template_types();
		if ( ! empty( $other_types ) ) {
			$skips[] = array(
				'key'    => __( 'Per-type title and description templates', 'nexter-extension' ),
				'count'  => count( $other_types ),
				'reason' => sprintf(
					/* translators: 1: product name, 2: comma-separated list of post types and taxonomies. */
					__( '%1$s uses one title and description template for all post types and one for all taxonomies. The Post and Category templates are imported and will apply to these too, whose own templates are not brought across: %2$s.', 'nexter-extension' ),
					Nexter_Content_SEO_Importer::brand(),
					implode( ', ', $other_types )
				),
			);
		}

		$conflicts = self::count_conflicts();
		if ( $conflicts > 0 ) {
			$skips[] = array(
				/* translators: %s: product name. */
				'key'    => sprintf( __( 'Your existing %s values', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
				'count'  => $conflicts,
				'reason' => __( 'These posts already have a Nexter title or description. The import never overwrites what you set yourself — the AIOSEO value is skipped and your own is kept.', 'nexter-extension' ),
			);
		}

		$log_404 = self::count_404_logs();
		if ( $log_404 > 0 ) {
			$skips[] = array(
				'key'    => __( '404 log', 'nexter-extension' ),
				'count'  => $log_404,
				'reason' => __( "AIOSEO's recorded 404 hits are not imported — Nexter's own 404 Monitor starts logging fresh from when it is enabled.", 'nexter-extension' ),
			);
		}

		// Unknown-tag scan over the values the import will actually convert.
		if ( '' !== $table ) {
			$values = $wpdb->get_col( "SELECT title FROM `{$table}` WHERE title LIKE '%#%' UNION ALL SELECT description FROM `{$table}` WHERE description LIKE '%#%' LIMIT 2000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			foreach ( (array) $values as $value ) {
				self::convert( (string) $value, $unknown_vars );
			}
		}

		return array(
			'source'            => self::SOURCE,
			'counts'            => $counts,
			'settings_diff'     => $diff,
			'postmeta_coverage' => $coverage,
			'skipped'           => $skips,
			'notes'             => $notes,
			'unknown_variables' => $unknown_vars,
		);
	}

	/**
	 * Rows in AIOSEO's 404 log, or 0 when it was never enabled (the log table only exists once
	 * the Redirects addon's 404 logging has been turned on).
	 *
	 * Discovered by pattern rather than a hardcoded table name, the same as table() above does
	 * for the known posts/terms/redirects tables — a wrong guess at the exact suffix would
	 * silently report 0 rows and leave the user unwarned instead of just finding nothing.
	 *
	 * @return int
	 */
	private static function count_404_logs() {
		global $wpdb;

		$like  = $wpdb->esc_like( $wpdb->prefix . 'aioseo' ) . '%' . $wpdb->esc_like( '404' ) . '%';
		$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name just came back from SHOW TABLES.
	}

	/**
	 * Posts that already carry Nexter values and would therefore be skipped by the run.
	 *
	 * @param string $group Destination key group to compare against: 'content' or 'robots'.
	 * @return int
	 */
	private static function count_conflicts( $group = 'content' ) {
		global $wpdb;

		$dest  = Nexter_Content_SEO_Importer::destination_keys_sql( $group );
		$table = self::table( 'posts' );
		if ( '' === $table ) {
			return 0;
		}

		$has_data = self::has_data_sql( 'ap', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix; the only value goes through prepare().
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT ap.post_id )
				 FROM `{$table}` ap
				 WHERE {$has_data}
				   AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ne WHERE ne.post_id = ap.post_id AND ne.meta_key IN ( {$dest} ) AND ne.meta_value != '' )
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = ap.post_id AND mk.meta_key = %s )",
				Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/*
	---------------------------------------------------------------------
	 * Smart tags
	 * -------------------------------------------------------------------
	 */

	/**
	 * Every smart tag id AIOSEO ships, so the tokenizer only treats a real tag as a tag.
	 *
	 * @return string[]
	 */
	private static function known_tags() {
		$tags = array(
			'alt_tag',
			'archive_date',
			'archive_title',
			'attachment_caption',
			'attachment_description',
			'author_bio',
			'author_first_name',
			'author_last_name',
			'author_link',
			'author_link_alt',
			'author_name',
			'author_url',
			'blog_link',
			'blog_title',
			'categories',
			'category',
			'category_link',
			'category_link_alt',
			'current_date',
			'current_day',
			'current_month',
			'current_year',
			'custom_field',
			'description',
			'event_end_date',
			'event_start_date',
			'featured_image',
			'featured_image_url',
			'page_number',
			'parent_title',
			'permalink',
			'post_content',
			'post_date',
			'post_date_w3c',
			'post_day',
			'post_excerpt',
			'post_excerpt_only',
			'post_link',
			'post_link_alt',
			'post_modified_date',
			'post_modified_date_w3c',
			'post_month',
			'post_title',
			'post_year',
			'search_term',
			'separator_sa',
			'site_description',
			'site_link',
			'site_link_alt',
			'site_title',
			'tagline',
			'tax_name',
			'tax_parent_name',
			'taxonomy_description',
			'taxonomy_title',
		);

		/**
		 * Filter the AIOSEO smart tag ids the tokenizer recognises.
		 *
		 * @param string[] $tags Tag ids without the leading '#'.
		 */
		return apply_filters( 'nexter_content_seo_import_aioseo_known_tags', $tags );
	}

	/**
	 * Split and token patterns for AIOSEO's smart tags.
	 *
	 * AIOSEO tags have no closing delimiter, so the tokenizer is built from its own tag list,
	 * longest first, with AIOSEO's own "not followed by a word character" rule. That keeps
	 * #post_link out of #post_link_alt, and leaves a plain "#1" in a title alone instead of
	 * reporting it as an unknown variable. #custom_field and #tax_name additionally take a
	 * dash-suffixed argument, which the first alternative captures whole.
	 *
	 * @return array{split:string, token:string}
	 */
	private static function tag_regexes() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$tags = array_values( array_filter( array_map( 'strval', (array) self::known_tags() ) ) );
		usort(
			$tags,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$alt = implode( '|', array_map( 'preg_quote', $tags ) );

		$cache = array(
			'split' => '/(#(?:custom_field|tax_name)-[a-zA-Z0-9_\-]+|#(?:' . $alt . ')(?![a-zA-Z0-9_]))/',
			'token' => '/^#(?:(?:custom_field|tax_name)-[a-zA-Z0-9_\-]+|(?:' . $alt . '))$/',
		);

		return $cache;
	}

	/**
	 * AIOSEO #tag → Nexter %var% map. #separator_sa resolves to the configured glyph, because
	 * Nexter templates carry the separator inline.
	 *
	 * Link-shaped tags are deliberately absent: #post_link and friends render an HTML anchor,
	 * not a URL, so there is no Nexter variable that produces the same output. They are dropped
	 * and reported rather than quietly turned into a bare URL.
	 *
	 * @return array<string,string>
	 */
	private static function variable_map() {
		$map = array(
			'#site_title'             => '%site_name%',
			'#blog_title'             => '%site_name%',
			'#tagline'                => '%tagline%',
			'#site_description'       => '%tagline%',
			'#post_title'             => '%post_title%',
			'#post_excerpt'           => '%post_excerpt%',
			'#post_excerpt_only'      => '%post_excerpt%',
			'#post_content'           => '%post_content%',
			'#permalink'              => '%post_url%',
			'#post_date'              => '%date_published%',
			'#post_date_w3c'          => '%date_published%',
			'#post_modified_date'     => '%date_modified%',
			'#post_modified_date_w3c' => '%date_modified%',
			'#author_name'            => '%post_author_name%',
			'#taxonomy_title'         => '%term_title%',
			'#archive_title'          => '%term_title%',
			'#taxonomy_description'   => '%term_description%',
			'#current_date'           => '%current_date%',
			'#current_day'            => '%current_day%',
			'#current_month'          => '%current_month%',
			'#current_year'           => '%current_year%',
			'#separator_sa'           => self::separator(),
		);

		/**
		 * Filter the AIOSEO → Nexter smart tag map.
		 *
		 * @param array<string,string> $map #tag => %ne_variable% (or a literal).
		 */
		return apply_filters( 'nexter_content_seo_import_aioseo_variable_map', $map );
	}

	/**
	 * The configured separator, decoded. AIOSEO stores it as an HTML entity ('&#45;'), which
	 * would otherwise land in Nexter titles as literal entity text.
	 *
	 * @return string
	 */
	private static function separator() {
		$settings = self::stored_settings();
		$value    = isset( $settings['searchAppearance']['global']['separator'] )
			? (string) $settings['searchAppearance']['global']['separator']
			: '&#45;';

		$decoded = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		return '' === trim( $decoded ) ? '-' : $decoded;
	}

	/**
	 * Convert one AIOSEO template value.
	 *
	 * @param string                 $value   Source value.
	 * @param array<string,int>|null $unknown Unknown-tag collector (by reference).
	 * @param-out array<string,int> $unknown
	 * @return string
	 */
	private static function convert( $value, &$unknown ) {
		$unknown  = is_array( $unknown ) ? $unknown : array();
		$patterns = self::tag_regexes();

		return Nexter_Content_SEO_Importer::convert_template( (string) $value, $patterns['split'], $patterns['token'], self::variable_map(), $unknown );
	}

	/*
	---------------------------------------------------------------------
	 * Settings
	 * -------------------------------------------------------------------
	 */

	/**
	 * AIOSEO's options, as stored — NOT merged with its defaults.
	 *
	 * Merging defaults in would import settings the user never chose; the archive 'show' flags in
	 * particular default to true, which merged in would write an explicit "archives enabled" onto
	 * a site that had simply never opened the setting.
	 *
	 * The row is JSON, but AIOSEO itself documents finding an array there after a WP-CLI or
	 * migration write, so both shapes are accepted.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private static function stored_option( $option ) {
		$value = get_option( $option, array() );
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : Nexter_Content_SEO_Importer::unserialize_data( $value );
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * The main options array.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored_settings() {
		return self::stored_option( self::OPTION_NAME );
	}

	/**
	 * Read a nested option value without tripping over a missing branch.
	 *
	 * @param array<string,mixed> $tree Option array.
	 * @param string[]            $path Key path.
	 * @return mixed|null
	 */
	private static function dig( $tree, $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $tree ) || ! array_key_exists( $key, $tree ) ) {
				return null;
			}
			$tree = $tree[ $key ];
		}

		return $tree;
	}

	/**
	 * Was a robots override actually switched on for this branch?
	 *
	 * AIOSEO keeps a 'default' flag beside the robots checkboxes; while it is true the checkboxes
	 * are inactive, so importing them would create an override the user never set.
	 *
	 * @param mixed  $robots One robotsMeta array.
	 * @param string $flag   Flag to test, e.g. 'noindex'.
	 * @return bool
	 */
	private static function robots_override( $robots, $flag ) {
		if ( ! is_array( $robots ) ) {
			return false;
		}
		if ( ! array_key_exists( 'default', $robots ) || ! empty( $robots['default'] ) ) {
			return false;
		}

		return ! empty( $robots[ $flag ] );
	}

	/**
	 * Build the NE options array the settings import will write.
	 *
	 * @param array<string,int>|null $unknown Unknown-tag collector (by reference).
	 * @param-out array<string,int> $unknown
	 * @return array<string,mixed>
	 */
	private static function build_settings( &$unknown = null ) {
		$unknown  = is_array( $unknown ) ? $unknown : array();
		$settings = self::stored_settings();
		$dynamic  = self::stored_option( self::OPTION_DYN );
		$out      = array();

		// Home page + global templates. AIOSEO's "site title" is the home template; the per-type
		// templates live in the separate dynamic options row, and Nexter has one singular and one
		// archive template, so 'post' and 'category' are the closest global representatives.
		$templates = array(
			'home_title'                   => array( self::OPTION_NAME, array( 'searchAppearance', 'global', 'siteTitle' ) ),
			'home_description'             => array( self::OPTION_NAME, array( 'searchAppearance', 'global', 'metaDescription' ) ),
			'home_og_title'                => array( self::OPTION_NAME, array( 'social', 'facebook', 'homePage', 'title' ) ),
			'home_og_description'          => array( self::OPTION_NAME, array( 'social', 'facebook', 'homePage', 'description' ) ),
			'meta_title_template'          => array( self::OPTION_DYN, array( 'searchAppearance', 'postTypes', 'post', 'title' ) ),
			'meta_description_template'    => array( self::OPTION_DYN, array( 'searchAppearance', 'postTypes', 'post', 'metaDescription' ) ),
			'archive_title_template'       => array( self::OPTION_DYN, array( 'searchAppearance', 'taxonomies', 'category', 'title' ) ),
			'archive_description_template' => array( self::OPTION_DYN, array( 'searchAppearance', 'taxonomies', 'category', 'metaDescription' ) ),
		);

		/**
		 * Filter the AIOSEO → Nexter template map.
		 *
		 * @param array<string,array> $templates NE key => [option name, key path].
		 */
		$templates = apply_filters( 'nexter_content_seo_import_aioseo_template_map', $templates );

		foreach ( $templates as $dst => $source ) {
			list( $option, $path ) = $source;
			$value                 = self::dig( self::OPTION_DYN === $option ? $dynamic : $settings, $path );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$converted = self::convert( $value, $unknown );
			if ( '' !== $converted ) {
				$out[ $dst ] = $converted;
			}
		}

		$home_image = self::dig( $settings, array( 'social', 'facebook', 'homePage', 'image' ) );
		if ( is_string( $home_image ) && '' !== $home_image ) {
			$out['home_og_image'] = esc_url_raw( $home_image );
			$img_id               = attachment_url_to_postid( $home_image );
			if ( $img_id > 0 ) {
				$out['home_og_image_id'] = $img_id;
			}
		}

		// Per-type robots, only where the type's own override is switched on.
		$noindex_pt = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			$robots = self::dig( $dynamic, array( 'searchAppearance', 'postTypes', $pt, 'advanced', 'robotsMeta' ) );
			if ( self::robots_override( $robots, 'noindex' ) ) {
				$noindex_pt[ $pt ] = true;
			}
		}
		if ( ! empty( $noindex_pt ) ) {
			$out['noindex_post_types'] = $noindex_pt;
		}

		$noindex_tax = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$robots = self::dig( $dynamic, array( 'searchAppearance', 'taxonomies', $tax, 'advanced', 'robotsMeta' ) );
			if ( self::robots_override( $robots, 'noindex' ) ) {
				$noindex_tax[ $tax ] = true;
			}
		}
		if ( ! empty( $noindex_tax ) ) {
			$out['noindex_taxonomies'] = $noindex_tax;
		}

		// AIOSEO's archive 'show' flag controls INDEXING, not whether the archive exists: with it
		// off the pages still serve to visitors and are only hidden from search engines. Mapping
		// it onto Nexter's disable_*_archives turned that into a 301, removing pages for everyone
		// — so it maps to noindex, and disable_*_archives is left alone.
		$noindex_archives = array();
		foreach ( array( 'author', 'date', 'search' ) as $archive ) {
			$robots = self::dig( $settings, array( 'searchAppearance', 'archives', $archive, 'advanced', 'robotsMeta' ) );
			if ( self::robots_override( $robots, 'noindex' ) ) {
				$noindex_archives[ $archive ] = true;
				continue;
			}

			$show = self::dig( $settings, array( 'searchAppearance', 'archives', $archive, 'show' ) );
			if ( null !== $show && empty( $show ) ) {
				$noindex_archives[ $archive ] = true;
			}
		}
		if ( ! empty( $noindex_archives ) ) {
			$out['noindex_archives'] = $noindex_archives;
		}

		// Sitemap. AIOSEO stores the exclusion; Nexter stores the inclusion.
		$exclude_images = self::dig( $settings, array( 'sitemap', 'general', 'advancedSettings', 'excludeImages' ) );
		if ( null !== $exclude_images ) {
			$out['sitemap_include_images'] = empty( $exclude_images );
		}

		// Social profiles, default image and card type.
		foreach ( array(
			'facebook_page_url'   => array( 'social', 'profiles', 'urls', 'facebookPageUrl' ),
			'twitter_site'        => array( 'social', 'profiles', 'urls', 'twitterUrl' ),
			'facebook_author_url' => array( 'social', 'facebook', 'advanced', 'authorUrl' ),
		) as $dst => $path ) {
			$value = self::dig( $settings, $path );
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $dst ] = $value;
			}
		}

		$default_image = self::dig( $settings, array( 'social', 'facebook', 'general', 'defaultImagePosts' ) );
		if ( is_string( $default_image ) && '' !== $default_image ) {
			$out['default_social_image'] = esc_url_raw( $default_image );
		}

		$card = self::dig( $settings, array( 'social', 'twitter', 'general', 'defaultCardType' ) );
		if ( is_string( $card ) && '' !== $card ) {
			$out['twitter_card_layout'] = $card;
		}

		// Webmaster verification. Yandex / Baidu / Norton / Clarity have no Nexter destination
		// and are reported in the dry run instead.
		foreach ( array(
			'google_verification'    => 'google',
			'bing_verification'      => 'bing',
			'pinterest_verification' => 'pinterest',
		) as $dst => $src ) {
			$value = self::dig( $settings, array( 'webmasterTools', $src ) );
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $dst ] = $value;
			}
		}

		/**
		 * Filter the final NE settings payload the AIOSEO import will save.
		 *
		 * @param array<string,mixed> $out      NE option key => value.
		 * @param array               $settings Raw aioseo_options.
		 * @param array               $dynamic  Raw aioseo_options_dynamic.
		 */
		return apply_filters( 'nexter_content_seo_import_aioseo_settings', $out, $settings, $dynamic );
	}

	/**
	 * Import global settings through the module's own REST save so its sanitizers run.
	 *
	 * @return array<string,mixed>
	 */
	public static function import_settings() {
		$unknown  = array();
		$settings = Nexter_Content_SEO_Importer::merge_settings( self::build_settings( $unknown ) );
		if ( empty( $settings ) ) {
			return array(
				'updated' => 0,
				'keys'    => array(),
			);
		}

		$request = new WP_REST_Request( 'POST', '/' . Nexter_Content_SEO::REST_NAMESPACE . '/seo/settings' );
		$request->set_body_params( array( 'settings' => $settings ) );
		$response = rest_do_request( $request );

		return array(
			'updated'           => $response->is_error() ? 0 : count( $settings ),
			'keys'              => array_keys( $settings ),
			'unknown_variables' => $unknown,
			'error'             => $response->is_error() ? $response->as_error()->get_error_message() : '',
		);
	}

	/*
	---------------------------------------------------------------------
	 * Post and term rows
	 * -------------------------------------------------------------------
	 */

	/**
	 * The column map that applies to one row, with the mirrored Twitter fields removed.
	 *
	 * Shared by import and verify: if verify used the full map it would report the deliberately
	 * skipped Twitter fields as a failed import.
	 *
	 * @param array<string,mixed> $row Source row.
	 * @return array<string,string>
	 */
	private static function applicable_column_map( $row ) {
		$map = self::column_map();
		if ( empty( $row['twitter_use_og'] ) ) {
			return $map;
		}

		foreach ( array_keys( $map ) as $column ) {
			if ( 0 === strpos( $column, 'twitter_' ) ) {
				unset( $map[ $column ] );
			}
		}

		return $map;
	}

	/**
	 * The social image URL a row actually resolves to, for one channel.
	 *
	 * AIOSEO caches whatever the chosen source produced in og_image_url, so that is the right
	 * value for 'featured' or 'attach' rows; a custom image is stored separately and wins when
	 * that is the selected type.
	 *
	 * @param array<string,mixed> $row     Source row.
	 * @param string              $channel 'og' or 'twitter'.
	 * @return string
	 */
	private static function resolve_image( $row, $channel ) {
		$type = isset( $row[ $channel . '_image_type' ] ) ? (string) $row[ $channel . '_image_type' ] : '';
		if ( 'default' === $type || '' === $type ) {
			return '';
		}

		$custom = isset( $row[ $channel . '_image_custom_url' ] ) ? (string) $row[ $channel . '_image_custom_url' ] : '';
		if ( 'custom_image' === $type && '' !== $custom ) {
			return $custom;
		}

		$cached = isset( $row[ $channel . '_image_url' ] ) ? (string) $row[ $channel . '_image_url' ] : '';

		return '' !== $cached ? $cached : $custom;
	}

	/**
	 * Nexter schema type from a row.
	 *
	 * Modern AIOSEO keeps the chosen graph in the JSON 'schema' column; older rows only have the
	 * schema_type column, with the sub-type in schema_type_options. The modern value is preferred
	 * because it is the one AIOSEO renders.
	 *
	 * @param array<string,mixed> $row Source row.
	 * @return string
	 */
	private static function resolve_schema_type( $row ) {
		$schema = isset( $row['schema'] ) ? $row['schema'] : '';
		if ( is_string( $schema ) && '' !== $schema ) {
			$decoded = json_decode( $schema, true );
			if ( is_array( $decoded ) ) {
				$graph = self::dig( $decoded, array( 'default', 'graphName' ) );
				if ( is_string( $graph ) && '' !== $graph ) {
					$mapped = Nexter_Content_SEO_Importer::map_schema_type( $graph );
					if ( '' !== $mapped ) {
						return $mapped;
					}
				}
			}
		}

		$type = isset( $row['schema_type'] ) ? (string) $row['schema_type'] : '';
		if ( '' === $type || 'default' === $type ) {
			return '';
		}

		// The sub-type is the more specific answer where it maps, e.g. Article → BlogPosting.
		$options = isset( $row['schema_type_options'] ) ? $row['schema_type_options'] : '';
		if ( is_string( $options ) && '' !== $options ) {
			$decoded = json_decode( $options, true );
			if ( is_array( $decoded ) ) {
				$sub = self::dig( $decoded, array( strtolower( $type ), 'schemaType' ) );
				if ( is_string( $sub ) && '' !== $sub ) {
					$mapped = Nexter_Content_SEO_Importer::map_schema_type( $sub );
					if ( '' !== $mapped ) {
						return $mapped;
					}
				}
			}
		}

		return Nexter_Content_SEO_Importer::map_schema_type( $type );
	}

	/**
	 * Copy one row's mapped fields, images, robots and schema type.
	 *
	 * @param string            $object_type 'post' or 'term'.
	 * @param int               $object_id   Object ID.
	 * @param array<string,int> $unknown     Unknown-tag collector (by reference).
	 * @param int               $kept        Count of destinations left untouched (by reference).
	 * @return int Fields written.
	 */
	private static function import_object( $object_type, $object_id, &$unknown, &$kept ) {
		$row = self::get_row( $object_type, $object_id );
		if ( null === $row ) {
			return 0;
		}

		$fields = 0;

		foreach ( self::applicable_column_map( $row ) as $column => $dst ) {
			$value = isset( $row[ $column ] ) ? $row[ $column ] : '';
			if ( '' === $value || is_array( $value ) ) {
				continue;
			}
			if ( false !== strpos( (string) $value, '#' ) ) {
				$value = self::convert( $value, $unknown );
			}
			if ( '' === $value ) {
				continue;
			}
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $dst, $value ) ) {
				++$fields;
			} else {
				++$kept;
			}
		}

		// Social images, whose stored value depends on the selected source type.
		$images = array(
			'og' => Nexter_Content_SEO_Social_Meta::META_FB_IMAGE,
		);
		if ( empty( $row['twitter_use_og'] ) ) {
			$images['twitter'] = Nexter_Content_SEO_Social_Meta::META_TW_IMAGE;
		}
		foreach ( $images as $channel => $dst ) {
			$url = self::resolve_image( $row, $channel );
			if ( '' === $url ) {
				continue;
			}
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $dst, esc_url_raw( $url ) ) ) {
				++$fields;
			} else {
				++$kept;
			}
		}

		// Robots, only where the row overrides the global defaults. While robots_default is 1
		// AIOSEO ignores these columns, so importing them would invent an override.
		if ( isset( $row['robots_default'] ) && ! (int) $row['robots_default'] ) {
			foreach ( array(
				'robots_noindex'   => Nexter_Content_SEO_Robots::META_NOINDEX,
				'robots_nofollow'  => Nexter_Content_SEO_Robots::META_NOFOLLOW,
				'robots_noarchive' => Nexter_Content_SEO_Robots::META_NOARCHIVE,
			) as $column => $dst ) {
				if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
					continue;
				}
				if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $dst, (int) $row[ $column ] ? '1' : '0' ) ) {
					++$fields;
				} else {
					++$kept;
				}
			}
		}

		$schema_type = self::resolve_schema_type( $row );
		if ( '' !== $schema_type ) {
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SeoRank::META_SCHEMA_TYPE, $schema_type ) ) {
				++$fields;
			} else {
				++$kept;
			}
		}

		return $fields;
	}

	/**
	 * Import one batch of posts. Idempotent via the per-post marker.
	 *
	 * @param int $batch Batch size.
	 * @return array<string,mixed>
	 */
	public static function import_posts_batch( $batch ) {
		// Tells write_if_empty() whose import this is, so it can tell its own earlier writes
		// apart from values the user set.
		Nexter_Content_SEO_Importer::set_active_source( self::SOURCE );
		return self::import_batch( 'post', $batch );
	}

	/**
	 * Import one batch of terms. AIOSEO only creates its terms table in Pro, so on Lite this
	 * reports nothing to do rather than failing the phase.
	 *
	 * @param int $batch Batch size.
	 * @return array<string,mixed>
	 */
	public static function import_terms_batch( $batch ) {
		// Tells write_if_empty() whose import this is, so it can tell its own earlier writes
		// apart from values the user set.
		Nexter_Content_SEO_Importer::set_active_source( self::SOURCE );
		return self::import_batch( 'term', $batch );
	}

	/**
	 * Shared batch loop for posts and terms.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $batch       Batch size.
	 * @return array<string,mixed>
	 */
	private static function import_batch( $object_type, $batch ) {
		$selection = self::get_unimported_ids( $object_type, $batch );
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$update    = 'post' === $object_type ? 'update_post_meta' : 'update_term_meta';
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;
		$kept      = 0;

		foreach ( $selection['ids'] as $object_id ) {
			$fields += self::import_object( $object_type, (int) $object_id, $unknown, $kept );
			call_user_func( $update, (int) $object_id, $marker, time() );
			++$imported;
		}

		return array(
			'imported'          => $imported,
			'fields_written'    => $fields,
			'kept_existing'     => $kept,
			'remaining'         => max( 0, $selection['remaining'] - $imported ),
			'unknown_variables' => $unknown,
		);
	}

	/**
	 * Re-read a sample of already-imported objects and confirm the Nexter destination holds a
	 * value wherever the source had one.
	 *
	 * @param int $sample Objects to check.
	 * @return array<string,mixed>
	 */
	public static function verify_sample( $sample ) {
		return Nexter_Content_SEO_Importer::verify_sample_shared( self::SOURCE, $sample, array( __CLASS__, 'verify_spec' ) );
	}

	/**
	 * What one object should hold after the import.
	 *
	 * The map is built per object, so a row mirroring OG onto Twitter is not checked for the
	 * Twitter fields the import correctly skipped. Source values are converted first: a title
	 * made only of tags Nexter has no equivalent for converts to nothing, nothing is written,
	 * and that is correct behaviour rather than a lost field.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array{map:array<string,string>,expected:array<string,string>}
	 */
	public static function verify_spec( $object_type, $object_id ) {
		$row = self::get_row( $object_type, $object_id );
		if ( null === $row ) {
			return array( 'map' => array() );
		}

		$map      = self::applicable_column_map( $row );
		$expected = array();
		$ignored  = array();
		foreach ( array_keys( $map ) as $column ) {
			$value = isset( $row[ $column ] ) && ! is_array( $row[ $column ] ) ? (string) $row[ $column ] : '';
			if ( '' !== $value && false !== strpos( $value, '#' ) ) {
				$value = self::convert( $value, $ignored );
			}
			$expected[ $column ] = $value;
		}

		return array(
			'map'      => $map,
			'expected' => $expected,
		);
	}

	/*
	---------------------------------------------------------------------
	 * Redirects
	 * -------------------------------------------------------------------
	 */

	/**
	 * Post types and taxonomies that carry their own AIOSEO title or description template,
	 * other than the two Nexter imports as its global pair.
	 *
	 * @return string[] Human-ish names, for the preview.
	 */
	private static function other_template_types() {
		$dynamic = self::stored_option( self::OPTION_DYN );
		$found   = array();

		foreach ( array(
			'postTypes'  => 'post',
			'taxonomies' => 'category',
		) as $group => $imported ) {
			$entries = self::dig( $dynamic, array( 'searchAppearance', $group ) );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $name => $config ) {
				if ( $name === $imported || ! is_array( $config ) ) {
					continue;
				}
				if ( ! empty( $config['title'] ) || ! empty( $config['metaDescription'] ) ) {
					$found[] = (string) $name;
				}
			}
		}

		return $found;
	}

	/**
	 * Which of the redirect columns this install actually has.
	 *
	 * AIOSEO's Redirects addon is a separate download, so its table is absent on Lite and its
	 * column set has grown over versions. Reading the real column list keeps a shape this code
	 * has not seen a reported skip rather than a fatal query.
	 *
	 * @return array<string,bool>
	 */
	private static function redirect_columns() {
		global $wpdb;

		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$table = self::table( 'redirects' );
		if ( '' === $table ) {
			$cache = array();
			return $cache;
		}

		$cache   = array();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		foreach ( (array) $columns as $column ) {
			$cache[ (string) $column ] = true;
		}

		return $cache;
	}

	/**
	 * Is the redirects table present and shaped the way this importer can read?
	 *
	 * @return bool
	 */
	private static function redirects_readable() {
		$columns = self::redirect_columns();

		return isset( $columns['source_url'], $columns['target_url'] );
	}

	/**
	 * Active redirects available to import.
	 *
	 * @return int
	 */
	private static function count_redirects() {
		global $wpdb;

		if ( ! self::redirects_readable() ) {
			return 0;
		}

		$table   = self::table( 'redirects' );
		$columns = self::redirect_columns();
		$where   = isset( $columns['enabled'] ) ? 'WHERE enabled = 1' : '';

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; $where is a fixed literal.
	}

	/**
	 * Redirect-related preview rows for the dry run.
	 *
	 * @return array{skipped:array[], notes:array[]}
	 */
	private static function redirect_preview() {
		global $wpdb;

		$skipped = array();
		$notes   = array();

		if ( '' === self::table( 'redirects' ) ) {
			$notes[] = array(
				'key'    => __( 'Redirects', 'nexter-extension' ),
				'count'  => 0,
				'reason' => __( 'Redirects are part of AIOSEO Pro, and this site does not have its redirects table. There is nothing to import.', 'nexter-extension' ),
			);

			return array(
				'skipped' => $skipped,
				'notes'   => $notes,
			);
		}

		if ( ! self::redirects_readable() ) {
			$skipped[] = array(
				'key'    => __( 'Redirects', 'nexter-extension' ),
				'count'  => 0,
				'reason' => __( 'The AIOSEO redirects table on this site is not in a layout Nexter recognises, so redirects are left alone rather than imported incorrectly.', 'nexter-extension' ),
			);

			return array(
				'skipped' => $skipped,
				'notes'   => $notes,
			);
		}

		$table   = self::table( 'redirects' );
		$columns = self::redirect_columns();

		if ( isset( $columns['type'] ) ) {
			$unsupported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE type NOT IN ( 301, 302, 307, 308, 410, 451 )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			if ( $unsupported > 0 ) {
				$skipped[] = array(
					'key'    => __( 'Redirects with an unsupported status', 'nexter-extension' ),
					'count'  => $unsupported,
					'reason' => __( 'Nexter redirects support 301, 302, 307, 308, 410 and 451. Redirects using any other response are left behind.', 'nexter-extension' ),
				);
			}
		}

		if ( ! isset( $columns['regex'] ) ) {
			$notes[] = array(
				'key'    => __( 'Redirect matching mode', 'nexter-extension' ),
				'count'  => 0,
				'reason' => __( 'This version of the AIOSEO redirects table does not record which redirects use a regular expression, so each one is imported as an exact URL match. Any source that looks like a pattern is skipped instead of being imported as a literal URL.', 'nexter-extension' ),
			);
		}

		if ( isset( $columns['ignore_case'] ) ) {
			$where    = isset( $columns['enabled'] ) ? 'enabled = 1 AND ' : '';
			$where   .= isset( $columns['regex'] ) ? 'regex = 0 AND ' : '';
			$flexible = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where} ignore_case = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; $where is built from fixed literals.
			if ( $flexible > 0 ) {
				$notes[] = array(
					'key'    => __( 'Redirects that ignore letter case', 'nexter-extension' ),
					'count'  => $flexible,
					'reason' => __( 'AIOSEO matches these regardless of letter case (its default). Nexter compares case exactly, so they are stored as Regex rules and keep matching /Old-Page as well as /old-page; they will show as Regex in the Redirection Manager. A trailing slash needs no special handling — Nexter already treats /old-page and /old-page/ alike.', 'nexter-extension' ),
				);
			}
		}

		return array(
			'skipped' => $skipped,
			'notes'   => $notes,
		);
	}

	/**
	 * Does this source string carry regular-expression syntax?
	 *
	 * Used only when the table has no regex flag to consult: a source holding metacharacters is
	 * far more likely to be a pattern than a literal path, and importing it as an exact match
	 * would produce a rule that silently never fires.
	 *
	 * @param string $source Source URL or pattern.
	 * @return bool
	 */
	private static function looks_like_regex( $source ) {
		return 1 === preg_match( '/[\\\\^$*+?()\[\]{}|]/', (string) $source );
	}

	/**
	 * AIOSEO redirects → Nexter redirection rules, through the module's own sanitize_rule() +
	 * save_rules() seam. Existing NE rules are kept; a duplicate source is skipped rather than
	 * overwritten, so the user's own rule wins.
	 *
	 * @param bool $dry_run True to compute the result without saving, for the preview.
	 * @return array<string,mixed>
	 */
	public static function import_redirections( $dry_run = false ) {
		global $wpdb;

		if ( ! self::redirects_readable() || ! class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
			);
		}

		$table   = self::table( 'redirects' );
		$columns = self::redirect_columns();

		$select = array( 'source_url', 'target_url' );
		foreach ( array( 'type', 'regex', 'enabled', 'ignore_case', 'ignore_slash' ) as $optional ) {
			if ( isset( $columns[ $optional ] ) ) {
				$select[] = $optional;
			}
		}
		$select_sql = '`' . implode( '`, `', $select ) . '`';
		$where      = isset( $columns['enabled'] ) ? 'WHERE enabled = 1' : '';

		$rows = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` {$where}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; the column list is built from the table's own SHOW COLUMNS output.
		if ( empty( $rows ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
			);
		}

		$existing      = Nexter_Content_SEO_Redirection::get_rules();
		$existing_from = array();
		foreach ( $existing as $rule ) {
			if ( ! empty( $rule['from_url'] ) ) {
				$existing_from[ $rule['from_url'] ] = true;
			}
		}

		// The stored-rule cap lived on the hand-entry REST route only, so an import walked
		// straight past it. Stop at the cap and report the overflow as a counted skip instead
		// of writing an option the front-end matcher then compiles on every request.
		$max  = Nexter_Content_SEO_Redirection::max_rules();
		$over = 0;

		$allowed   = Nexter_Content_SEO_Redirection::ALLOWED_STATUS_CODES;
		$imported  = 0;
		$skipped   = 0;
		$converted = 0;

		foreach ( $rows as $row ) {
			$source = isset( $row['source_url'] ) ? trim( (string) $row['source_url'] ) : '';
			if ( '' === $source ) {
				++$skipped;
				continue;
			}

			$status = isset( $row['type'] ) ? (int) $row['type'] : 301;
			if ( ! in_array( $status, $allowed, true ) ) {
				++$skipped;
				continue;
			}

			// Without a regex flag the safe reading of a metacharacter-bearing source is "leave
			// it alone" — importing it as a literal URL would create a rule that never matches.
			if ( isset( $columns['regex'] ) ) {
				$is_regex = ! empty( $row['regex'] );
			} else {
				if ( self::looks_like_regex( $source ) ) {
					++$skipped;
					continue;
				}
				$is_regex = false;
			}

			$built = self::build_rule_args( $source, $is_regex, $row, $status );
			$rule  = Nexter_Content_SEO_Redirection::sanitize_rule( $built['args'] );

			if ( null === $rule || '' === $rule['from_url'] || isset( $existing_from[ $rule['from_url'] ] ) ) {
				++$skipped;
				continue;
			}

			if ( $max > 0 && count( $existing ) >= $max ) {
				++$over;
				continue;
			}
			$existing[]                         = $rule;
			$existing_from[ $rule['from_url'] ] = true;
			++$imported;
			if ( $built['converted'] ) {
				++$converted;
			}
		}

		$loop = '';
		if ( $imported > 0 ) {
			// The loop detector guarded the hand-entry route only, so an import could install a
			// chain that never resolves. Report it rather than refuse the import: the rules are
			// the user's own data, and they need to see which one to fix.
			$loop = Nexter_Content_SEO_Redirection::find_loop( $existing );
			if ( ! $dry_run ) {
				Nexter_Content_SEO_Redirection::save_rules( $existing );
			}
		}

		return array(
			'imported'           => $imported,
			'skipped'            => $skipped,
			'skipped_over_limit' => $over,
			'converted_to_regex' => $converted,
			'loop_warning'       => $loop,
		);
	}

	/**
	 * Turn one AIOSEO redirect row into the arguments Nexter's sanitizer expects.
	 *
	 * AIOSEO matches a plain source ignoring letter case and a trailing slash — both flags are
	 * on by default — so /Old-Page/ reaches the rule for /old-page. Nexter's Exact Match already
	 * treats /old-page and /old-page/ alike but compares case exactly, and its regex engine takes
	 * no modifiers, so a case-insensitive rule is stored as an equivalent regex instead: an inline
	 * (?i), plus /? when the slash flag is on too. The redirect keeps firing exactly as it did; it
	 * is just labelled Regex. A rule with only the slash flag needs nothing and stays Exact Match.
	 *
	 * @param string              $source   Source URL or pattern.
	 * @param bool                $is_regex Row is already a regex.
	 * @param array<string,mixed> $row      Source row (flags read only when the columns exist).
	 * @param int                 $status   Status code.
	 * @return array{args:array<string,mixed>, converted:bool}
	 */
	private static function build_rule_args( $source, $is_regex, $row, $status ) {
		$pattern   = $source;
		$condition = $is_regex ? 'regex' : 'exact_match';
		$converted = false;

		$ignore_case  = ! empty( $row['ignore_case'] );
		$ignore_slash = ! empty( $row['ignore_slash'] );
		$path         = (string) wp_parse_url( $source, PHP_URL_PATH );
		$path         = '/' . ltrim( $path, '/' );

		// Only a plain, case-insensitive path needs converting; the home page and pattern-shaped
		// sources are left as they are.
		if ( ! $is_regex && $ignore_case && '/' !== $path && ! self::looks_like_regex( $source ) ) {
			$quoted = preg_quote( rtrim( $path, '/' ) ); // phpcs:ignore WordPress.PHP.PregQuoteDelimiter.Missing -- delimiter is chosen per-pattern by Nexter_Content_SEO_Redirection::compile_regex().
			$regex  = '(?i)^' . $quoted . ( $ignore_slash ? '/?' : '' ) . '$';
			$probe  = Nexter_Content_SEO_Redirection::sanitize_rule(
				array(
					'from_url'  => $regex,
					'condition' => 'regex',
				)
			);
			// Should the pattern ever be refused, fall back to a plain exact match rather than
			// drop the redirect — narrower matching, but still a working rule.
			if ( null !== $probe ) {
				$pattern   = $regex;
				$condition = 'regex';
				$converted = true;
			}
		}

		return array(
			'args'      => array(
				'enabled'     => true,
				'from_url'    => $pattern,
				// 410/451 rows have no target; NE's sanitizer keeps them as an empty to_url.
				'to_url'      => isset( $row['target_url'] ) ? (string) $row['target_url'] : '',
				'condition'   => $condition,
				'status_code' => $status,
			),
			'converted' => $converted,
		);
	}
}
