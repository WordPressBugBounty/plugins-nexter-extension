<?php
/**
 * Content SEO — Rank Math SEO importer.
 *
 * Reads Rank Math's stored data straight from the database (options + post/term meta), so it
 * works whether Rank Math is active, deactivated, or removed with its data left behind. Every
 * mapping table is filterable so an edge case can be fixed on a live site without a release.
 *
 * Deliberately NOT imported (reported as skipped, never silently dropped):
 * - focus keywords / SEO scores / internal-link counts — Nexter runs its own analysis;
 * - pillar content and primary term — no Nexter equivalent;
 * - Baidu / Yandex / Norton verification — Nexter stores Google, Bing, Pinterest and Facebook;
 * - schema field DATA — only the schema TYPE maps across; the field bodies differ per type.
 *
 * @package Nexter Extensions
 * @since 4.7.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Importer_Rankmath
 */
class Nexter_Content_SEO_Importer_Rankmath {

	const SOURCE      = 'rankmath';
	const META_PREFIX = 'rank_math_';

	/**
	 * Rank Math variables are single-percent and may carry an argument: %title%, %sep%,
	 * %customfield(price)%, %category%.
	 */
	// A variable name always starts with a letter. Without that anchor "50%-70%" reads as
	// the token %-70% and gets dropped as an unknown variable, eating the literal text.
	const SPLIT_REGEX = '/(%[a-zA-Z][a-zA-Z0-9_\-]*(?:\([^)%]*\))?%)/';
	const TOKEN_REGEX = '/^%[a-zA-Z][a-zA-Z0-9_\-]*(?:\([^)%]*\))?%$/';

	/** Fields copied verbatim: template conversion would corrupt percent-encoded paths. */
	const URL_FIELDS = array( 'rank_math_canonical_url', 'rank_math_facebook_image', 'rank_math_twitter_image' );

	/** Rank Math option groups. */
	const OPTION_TITLES  = 'rank-math-options-titles';
	const OPTION_GENERAL = 'rank-math-options-general';
	const OPTION_SITEMAP = 'rank-math-options-sitemap';

	/**
	 * Human label for the sources list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'Rank Math SEO';
	}

	/**
	 * Source meta keys that count as real SEO data.
	 *
	 * Rank Math stamps rank_math_internal_links_processed and rank_math_seo_score on posts it has
	 * merely analysed, so a "rank_math_%" prefix match would report every post on the site as
	 * pending. Selection uses this explicit list instead.
	 *
	 * @return string[]
	 */
	private static function data_meta_keys() {
		$keys = array_merge(
			array_keys( self::postmeta_map() ),
			array( 'rank_math_robots', 'rank_math_rich_snippet' )
		);

		/**
		 * Filter the Rank Math meta keys that mark a post/term as worth importing.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return apply_filters( 'nexter_content_seo_import_rankmath_data_keys', $keys );
	}

	/**
	 * Rank Math data present at all?
	 *
	 * @return bool
	 */
	public static function detect() {
		global $wpdb;

		foreach ( array( self::OPTION_TITLES, self::OPTION_GENERAL ) as $option ) {
			if ( false !== get_option( $option, false ) ) {
				return true;
			}
		}

		$keys         = self::data_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$sql = "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name plus the generated %s list.

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Object counts for the detect card and the dry run.
	 *
	 * @return array<string,mixed>
	 */
	public static function counts() {
		$keys  = self::data_meta_keys();
		$posts = Nexter_Content_SEO_Importer::get_unimported_post_ids_by_keys( $keys, self::SOURCE, 0 );
		$terms = Nexter_Content_SEO_Importer::get_unimported_term_ids_by_keys( $keys, self::SOURCE, 0 );

		return array(
			'posts_remaining' => $posts['remaining'],
			'terms_remaining' => $terms['remaining'],
			'redirections'    => self::count_redirections(),
			'has_settings'    => ( false !== get_option( self::OPTION_TITLES, false ) ),
			'plugin_active'   => defined( 'RANK_MATH_VERSION' ),
		);
	}

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

		// Per-source-field post coverage.
		$coverage = array();
		foreach ( array_merge( array_keys( self::postmeta_map() ), array( 'rank_math_robots', 'rank_math_rich_snippet' ) ) as $key ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $n > 0 ) {
				$coverage[ $key ] = $n;
			}
		}

		$skips = array();
		foreach ( array(
			/* translators: %s: product name. */
			'rank_math_focus_keyword'    => sprintf( __( 'Focus keywords are not imported — %s runs its own analysis.', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
			'rank_math_seo_score'        => __( 'Rank Math SEO scores have no Nexter equivalent.', 'nexter-extension' ),
			'rank_math_pillar_content'   => __( 'Pillar-content flags have no Nexter equivalent.', 'nexter-extension' ),
			'rank_math_breadcrumb_title' => __( 'Custom breadcrumb titles have no Nexter equivalent.', 'nexter-extension' ),
		) as $key => $reason ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $n > 0 ) {
				$skips[] = array(
					'key'    => $key,
					'count'  => $n,
					'reason' => $reason,
				);
			}
		}

		$primary = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_primary\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $primary > 0 ) {
			$skips[] = array(
				'key'    => 'rank_math_primary_*',
				'count'  => $primary,
				'reason' => __( 'Primary term has no Nexter equivalent yet.', 'nexter-extension' ),
			);
		}

		// Schema field bodies. The TYPE crosses over; the per-type fields do not.
		$schema_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_schema\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $schema_rows > 0 ) {
			$skips[] = array(
				'key'    => 'rank_math_schema_*',
				'count'  => $schema_rows,
				'reason' => __( 'The schema type is imported; the individual schema field values are not — Nexter builds those from its own field set.', 'nexter-extension' ),
			);
		}

		$general     = self::option_array( self::OPTION_GENERAL );
		$unsupported = array();
		foreach ( array( 'baidu_verify', 'yandex_verify', 'norton_verify' ) as $key ) {
			if ( ! empty( $general[ $key ] ) ) {
				$unsupported[] = $key;
			}
		}
		if ( ! empty( $unsupported ) ) {
			$skips[] = array(
				'key'    => implode( ', ', $unsupported ),
				'count'  => count( $unsupported ),
				'reason' => __( 'Nexter stores Google, Bing, Pinterest and Facebook verification codes only.', 'nexter-extension' ),
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

		$user_meta = Nexter_Content_SEO_Importer::user_meta_skip( self::label(), 'rank_math_' );
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
		// taxonomies, so only the Post and Category templates are imported and they then apply
		// site-wide. Any type configured differently is named here rather than changing silently.
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
				'reason' => __( 'These posts already have a Nexter title or description. The import never overwrites what you set yourself — the Rank Math value is skipped and your own is kept.', 'nexter-extension' ),
			);
		}

		$log_404 = self::count_404_logs();
		if ( $log_404 > 0 ) {
			$skips[] = array(
				'key'    => __( '404 Monitor log', 'nexter-extension' ),
				'count'  => $log_404,
				'reason' => __( 'Rank Math\'s recorded 404 hits are not imported — Nexter\'s own 404 Monitor starts logging fresh from when it is enabled.', 'nexter-extension' ),
			);
		}

		// Unknown-variable scan over the values the import will actually convert.
		$text_keys = array( 'rank_math_title', 'rank_math_description', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_twitter_title', 'rank_math_twitter_description' );
		$in        = implode( ',', array_fill( 0, count( $text_keys ), '%s' ) );
		$args      = $text_keys;
		$args[]    = '%' . $wpdb->esc_like( '%' ) . '%';
		$values    = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s LIMIT 2000", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a generated %s list; values go through prepare().
		foreach ( (array) $values as $value ) {
			self::convert( (string) $value, $unknown_vars );
		}

		// Things that ARE imported but land differently than the source had them. Kept apart
		// from $skips so the UI never files a change under "will be skipped".
		$notes     = array();
		$substring = self::count_substring_redirects();
		if ( $substring > 0 ) {
			$notes[] = array(
				'key'    => __( 'Contains / Ends With redirects', 'nexter-extension' ),
				'count'  => $substring,
				'reason' => __( 'These arrive as Regex rules. Rank Math matches them anywhere in the URL, and a Regex rule is how Nexter reproduces that exactly — the redirects behave the same, they are just labelled Regex in the Redirection Manager.', 'nexter-extension' ),
			);
		}

		// Rank Math PRO extras. Schema templates and locations have no per-post value to carry;
		// multiple schemas and redirect categories are imported, but flattened.
		$templates = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", 'rank_math_schema' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $templates > 0 ) {
			$skips[] = array(
				'key'    => __( 'Schema templates', 'nexter-extension' ),
				'count'  => $templates,
				'reason' => __( 'Rank Math PRO schema templates are applied to posts by display conditions rather than stored on the post, so they are not imported. The schema type set on each post is.', 'nexter-extension' ),
			);
		}
		$locations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", 'rank_math_locations' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $locations > 0 ) {
			$skips[] = array(
				'key'    => __( 'Local SEO locations', 'nexter-extension' ),
				'count'  => $locations,
				'reason' => __( 'Rank Math PRO location entries have no Nexter equivalent.', 'nexter-extension' ),
			);
		}
		$multi = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_schema\\_%' GROUP BY post_id HAVING COUNT(*) > 1 ) m" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $multi > 0 ) {
			$notes[] = array(
				'key'    => __( 'Posts with more than one schema', 'nexter-extension' ),
				'count'  => $multi,
				'reason' => __( 'Nexter stores one schema type per post, so the schema Rank Math marks as primary is imported and the others are left behind.', 'nexter-extension' ),
			);
		}
		$categories = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'rank_math_redirection_category' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $categories > 0 ) {
			$notes[] = array(
				'key'    => __( 'Redirect categories', 'nexter-extension' ),
				'count'  => $categories,
				'reason' => __( 'The redirects are imported; the Rank Math PRO categories they were filed under are not, because Nexter has no redirect categories.', 'nexter-extension' ),
			);
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
	 * How many active redirect sources use the substring comparisons that import as regex.
	 *
	 * @return int
	 */
	private static function count_substring_redirects() {
		global $wpdb;

		$table = self::redirections_table();
		if ( '' === $table ) {
			return 0;
		}

		$rows = $wpdb->get_col( "SELECT sources FROM `{$table}` WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved from $wpdb->prefix.
		$n    = 0;
		foreach ( (array) $rows as $raw ) {
			$sources = Nexter_Content_SEO_Importer::unserialize_data( $raw );
			if ( ! is_array( $sources ) ) {
				continue;
			}
			foreach ( $sources as $source ) {
				if ( ! is_array( $source ) || empty( $source['pattern'] ) ) {
					continue;
				}
				$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
				if ( 'contains' === $comparison || 'end' === $comparison ) {
					++$n;
				}
			}
		}

		return $n;
	}

	/**
	 * Rows in Rank Math's 404 Monitor log, or 0 when it was never enabled.
	 *
	 * Discovered by pattern rather than a hardcoded table name: Rank Math has changed its 404
	 * log table name across versions, and a wrong guess would silently report 0 rows and leave
	 * the user unwarned instead of just finding nothing.
	 *
	 * @return int
	 */
	private static function count_404_logs() {
		global $wpdb;

		$like  = $wpdb->esc_like( $wpdb->prefix . 'rank_math' ) . '%' . $wpdb->esc_like( '404' ) . '%';
		$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name just came back from SHOW TABLES.
	}

	/**
	 * Post types and taxonomies carrying their own Rank Math title or description template,
	 * other than the two Nexter imports as its global pair.
	 *
	 * @return string[] Type names, for the preview.
	 */
	private static function other_template_types() {
		$titles = get_option( self::OPTION_TITLES, array() );
		if ( ! is_array( $titles ) ) {
			return array();
		}

		$found = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( 'post' === $pt ) {
				continue;
			}
			if ( ! empty( $titles[ 'pt_' . $pt . '_title' ] ) || ! empty( $titles[ 'pt_' . $pt . '_description' ] ) ) {
				$found[] = (string) $pt;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( 'category' === $tax ) {
				continue;
			}
			if ( ! empty( $titles[ 'tax_' . $tax . '_title' ] ) || ! empty( $titles[ 'tax_' . $tax . '_description' ] ) ) {
				$found[] = (string) $tax;
			}
		}

		return $found;
	}

	/**
	 * Posts that already carry Nexter values and would therefore be skipped by the run.
	 *
	 * @param string $group Destination key group to compare against: 'content' or 'robots'.
	 * @return int
	 */
	private static function count_conflicts( $group = 'content' ) {
		global $wpdb;

		$dest         = Nexter_Content_SEO_Importer::destination_keys_sql( $group );
		$keys         = self::data_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$args         = $keys;
		$args[]       = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and the generated %s placeholder list; every value goes through prepare().
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT ne.post_id )
				 FROM {$wpdb->postmeta} ne
				 WHERE ne.meta_key IN ( {$dest} )
				   AND ne.meta_value != ''
				   AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} src WHERE src.post_id = ne.post_id AND src.meta_key IN ({$placeholders}) AND src.meta_value != '' )
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = ne.post_id AND mk.meta_key = %s )",
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/*
	---------------------------------------------------------------------
	 * Settings
	 * -------------------------------------------------------------------
	 */

	/**
	 * Read one Rank Math option group as an array.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private static function option_array( $option ) {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Rank Math %var% → Nexter %var% map. %sep% resolves to the literal glyph Rank Math had
	 * configured, because Nexter templates carry the separator inline.
	 *
	 * @return array<string,string>
	 */
	private static function variable_map() {
		$titles = self::option_array( self::OPTION_TITLES );
		// Stored as the HTML entity that was picked in the settings UI ('-', '&ndash;', '&bull;'…).
		$sep = isset( $titles['title_separator'] ) ? (string) $titles['title_separator'] : '-';
		$sep = html_entity_decode( $sep, ENT_QUOTES, 'UTF-8' );

		$map = array(
			'%sitename%'         => '%site_name%',
			'%sitedesc%'         => '%tagline%',
			'%title%'            => '%post_title%',
			'%excerpt%'          => '%post_excerpt%',
			'%excerpt_only%'     => '%post_excerpt%',
			'%term%'             => '%term_title%',
			'%term_description%' => '%term_description%',
			'%category%'         => '%term_title%',
			'%date%'             => '%date_published%',
			'%modified%'         => '%date_modified%',
			'%name%'             => '%post_author_name%',
			'%post_url%'         => '%post_url%',
			'%org_name%'         => '%organization_name%',
			'%org_url%'          => '%organization_url%',
			'%org_logo%'         => '%organization_logo%',
			'%currentdate%'      => '%current_date%',
			'%currentday%'       => '%current_day%',
			'%currentmonth%'     => '%current_month%',
			'%currentyear%'      => '%current_year%',
			'%currenttime%'      => '%current_time%',
			'%sep%'              => $sep,
		);

		/**
		 * Filter the Rank Math → Nexter template-variable map.
		 *
		 * @param array<string,string> $map %token% => %ne_variable% (or a literal).
		 */
		return apply_filters( 'nexter_content_seo_import_rankmath_variable_map', $map );
	}

	/**
	 * Convert one Rank Math template value.
	 *
	 * @param string                 $value   Source value.
	 * @param array<string,int>|null $unknown Unknown-token collector (by reference).
	 * @param-out array<string,int> $unknown
	 * @return string
	 */
	private static function convert( $value, &$unknown ) {
		$unknown = is_array( $unknown ) ? $unknown : array();
		return Nexter_Content_SEO_Importer::convert_template( (string) $value, self::SPLIT_REGEX, self::TOKEN_REGEX, self::variable_map(), $unknown );
	}

	/**
	 * Does a Rank Math robots array carry a directive?
	 *
	 * @param mixed  $robots    Stored value (array, or a serialized/CSV leftover).
	 * @param string $directive Directive to look for.
	 * @return bool
	 */
	private static function robots_has( $robots, $directive ) {
		if ( is_string( $robots ) ) {
			$robots = array_map( 'trim', explode( ',', $robots ) );
		}
		return is_array( $robots ) && in_array( $directive, $robots, true );
	}

	/**
	 * Build the NE options array the settings import will write.
	 *
	 * @param array<string,int>|null $unknown Unknown-variable collector (by reference).
	 * @return array<string,mixed>
	 */
	private static function build_settings( &$unknown = null ) {
		$unknown = is_array( $unknown ) ? $unknown : array();

		$titles  = self::option_array( self::OPTION_TITLES );
		$general = self::option_array( self::OPTION_GENERAL );
		$sitemap = self::option_array( self::OPTION_SITEMAP );
		$out     = array();

		// Homepage.
		foreach ( array(
			'homepage_title'                => 'home_title',
			'homepage_description'          => 'home_description',
			'homepage_facebook_title'       => 'home_og_title',
			'homepage_facebook_description' => 'home_og_description',
		) as $src => $dst ) {
			if ( ! empty( $titles[ $src ] ) ) {
				$out[ $dst ] = self::convert( $titles[ $src ], $unknown );
			}
		}
		if ( ! empty( $titles['homepage_facebook_image'] ) ) {
			$out['home_og_image'] = esc_url_raw( (string) $titles['homepage_facebook_image'] );
			$img_id               = attachment_url_to_postid( (string) $titles['homepage_facebook_image'] );
			if ( $img_id > 0 ) {
				$out['home_og_image_id'] = $img_id;
			}
		}

		// Global templates. Nexter has one singular template, not one per post type; Rank Math's
		// 'post' and 'category' entries are the closest global representatives.
		$template_map = apply_filters(
			'nexter_content_seo_import_rankmath_template_map',
			array(
				'pt_post_title'            => 'meta_title_template',
				'pt_post_description'      => 'meta_description_template',
				'tax_category_title'       => 'archive_title_template',
				'tax_category_description' => 'archive_description_template',
			)
		);
		foreach ( $template_map as $src => $dst ) {
			if ( ! empty( $titles[ $src ] ) ) {
				$converted = self::convert( $titles[ $src ], $unknown );
				if ( '' !== $converted ) {
					$out[ $dst ] = $converted;
				}
			}
		}

		// Robots defaults. Rank Math only honours pt_/tax_ robots when its matching
		// *_custom_robots switch is 'on'; without that check an inherited default would be
		// imported as an explicit per-type noindex.
		$noindex_pt = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( empty( $titles[ 'pt_' . $pt . '_custom_robots' ] ) || 'on' !== $titles[ 'pt_' . $pt . '_custom_robots' ] ) {
				continue;
			}
			if ( self::robots_has( isset( $titles[ 'pt_' . $pt . '_robots' ] ) ? $titles[ 'pt_' . $pt . '_robots' ] : array(), 'noindex' ) ) {
				$noindex_pt[ $pt ] = true;
			}
		}
		if ( ! empty( $noindex_pt ) ) {
			$out['noindex_post_types'] = $noindex_pt;
		}

		$noindex_tax = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( empty( $titles[ 'tax_' . $tax . '_custom_robots' ] ) || 'on' !== $titles[ 'tax_' . $tax . '_custom_robots' ] ) {
				continue;
			}
			if ( self::robots_has( isset( $titles[ 'tax_' . $tax . '_robots' ] ) ? $titles[ 'tax_' . $tax . '_robots' ] : array(), 'noindex' ) ) {
				$noindex_tax[ $tax ] = true;
			}
		}
		if ( ! empty( $noindex_tax ) ) {
			$out['noindex_taxonomies'] = $noindex_tax;
		}

		$noindex_archives = array();
		if ( ! empty( $titles['author_custom_robots'] ) && 'on' === $titles['author_custom_robots']
			&& self::robots_has( isset( $titles['author_robots'] ) ? $titles['author_robots'] : array(), 'noindex' ) ) {
			$noindex_archives['author'] = true;
		}
		// Gated exactly like the author branch above. Rank Math ships date_archive_robots
		// prefilled with noindex while date_archive_custom_robots is unset, so reading the
		// array on its own imports a noindex Rank Math itself never applies.
		if ( ! empty( $titles['date_archive_custom_robots'] ) && 'on' === $titles['date_archive_custom_robots']
			&& self::robots_has( isset( $titles['date_archive_robots'] ) ? $titles['date_archive_robots'] : array(), 'noindex' ) ) {
			$noindex_archives['date'] = true;
		}
		if ( ! empty( $noindex_archives ) ) {
			$out['noindex_archives'] = $noindex_archives;
		}

		// Archives on/off + attachment handling. These are CMB2 switches storing the literal
		// strings 'on' (disabled) and 'off' (enabled), so a truthiness test reads every enabled
		// site as disabled — 'off' is a non-empty string. Compare against 'on', and import the
		// effective value both ways so an enabled source cannot inherit a different NE default.
		if ( isset( $titles['disable_author_archives'] ) ) {
			$out['disable_author_archives'] = ( 'on' === $titles['disable_author_archives'] );
		}
		if ( isset( $titles['disable_date_archives'] ) ) {
			$out['disable_date_archives'] = ( 'on' === $titles['disable_date_archives'] );
		}
		if ( isset( $general['attachment_redirect_urls'] ) ) {
			$out['redirect_attachment_pages'] = ( 'on' === $general['attachment_redirect_urls'] );
		}

		// Sitemap.
		if ( isset( $sitemap['include_images'] ) ) {
			$out['sitemap_include_images'] = ( 'on' === $sitemap['include_images'] );
		}

		// Social profiles + default image.
		if ( ! empty( $titles['social_url_facebook'] ) ) {
			$out['facebook_page_url'] = (string) $titles['social_url_facebook'];
		}
		if ( ! empty( $titles['twitter_author_names'] ) ) {
			$out['twitter_site'] = (string) $titles['twitter_author_names'];
		}
		if ( ! empty( $titles['open_graph_image'] ) ) {
			$out['default_social_image'] = esc_url_raw( (string) $titles['open_graph_image'] );
		}

		// Webmaster verification. Baidu / Yandex / Norton have no Nexter destination and are
		// reported in the dry run instead.
		foreach ( array(
			'google_verify'    => 'google_verification',
			'bing_verify'      => 'bing_verification',
			'pinterest_verify' => 'pinterest_verification',
			'facebook_verify'  => 'facebook_verification',
		) as $src => $dst ) {
			if ( ! empty( $general[ $src ] ) ) {
				$out[ $dst ] = (string) $general[ $src ];
			}
		}

		// Custom robots.txt. Rank Math serves its own robots.txt from this option, so every rule
		// the owner wrote there stops applying the moment Rank Math is deactivated. Imported only
		// when Nexter's own robots.txt box is empty, so an existing Nexter rule set is never
		// replaced by the old plugin's.
		if ( ! empty( $general['robots_txt_content'] ) && is_string( $general['robots_txt_content'] ) ) {
			$current = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options( 'robots_txt_custom' ) : '';
			if ( empty( $current ) ) {
				$out['robots_txt_custom'] = (string) $general['robots_txt_content'];
			}
		}

		/**
		 * Filter the final NE settings payload the Rank Math import will save.
		 *
		 * @param array<string,mixed> $out     NE option key => value.
		 * @param array               $titles  Raw rank-math-options-titles.
		 * @param array               $general Raw rank-math-options-general.
		 * @param array               $sitemap Raw rank-math-options-sitemap.
		 */
		return apply_filters( 'nexter_content_seo_import_rankmath_settings', $out, $titles, $general, $sitemap );
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
	 * Post and term meta
	 * -------------------------------------------------------------------
	 */

	/**
	 * Direct source→destination meta map. Rank Math uses the same key names on posts and terms.
	 *
	 * @return array<string,string>
	 */
	private static function postmeta_map() {
		$map = array(
			'rank_math_title'                => Nexter_Content_SEO_Social_Meta::META_TITLE,
			'rank_math_description'          => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'rank_math_canonical_url'        => Nexter_Content_SEO_Canonical::META_CANONICAL,
			'rank_math_facebook_title'       => Nexter_Content_SEO_Social_Meta::META_FB_TITLE,
			'rank_math_facebook_description' => Nexter_Content_SEO_Social_Meta::META_FB_DESC,
			'rank_math_facebook_image'       => Nexter_Content_SEO_Social_Meta::META_FB_IMAGE,
			'rank_math_twitter_title'        => Nexter_Content_SEO_Social_Meta::META_TW_TITLE,
			'rank_math_twitter_description'  => Nexter_Content_SEO_Social_Meta::META_TW_DESC,
			'rank_math_twitter_image'        => Nexter_Content_SEO_Social_Meta::META_TW_IMAGE,
		);

		/**
		 * Filter the Rank Math → Nexter post/term meta key map.
		 *
		 * @param array<string,string> $map source meta key => NE meta key.
		 */
		return apply_filters( 'nexter_content_seo_import_rankmath_postmeta_map', $map );
	}

	/**
	 * One field's value ready to write.
	 *
	 * URL fields are copied verbatim through esc_url_raw(). They were always meant to be —
	 * running template conversion over a URL destroys percent-encoded paths, where the %C3%
	 * in /caf%C3%A9/ is indistinguishable from a variable token.
	 *
	 * @param string            $field   Rank Math meta key.
	 * @param string            $value   Raw stored value.
	 * @param array<string,int> $unknown Unknown-variable collector (by reference).
	 * @return string
	 */
	private static function prepare_value( $field, $value, &$unknown ) {
		if ( in_array( $field, self::URL_FIELDS, true ) ) {
			return esc_url_raw( $value );
		}

		return ( false !== strpos( $value, '%' ) ) ? self::convert( $value, $unknown ) : $value;
	}

	/**
	 * The subset of the map that actually applies to one object.
	 *
	 * With "use Facebook data for X" on, Rank Math renders the Facebook values and ignores any
	 * twitter_* rows — but it leaves those rows in the database. Nexter has no such switch, so
	 * copying them across would resurrect values the site does not currently output. Skipping
	 * them lets Nexter's own X → Facebook → title fallback do the same job.
	 *
	 * Import and verification both go through this, so verification can never flag a field the
	 * import skipped on purpose.
	 *
	 * @param callable $get       get_post_meta or get_term_meta.
	 * @param int      $object_id Object ID.
	 * @return array<string,string>
	 */
	private static function applicable_postmeta_map( $get, $object_id ) {
		$map = self::postmeta_map();
		if ( ! self::uses_facebook_for_twitter( $get, $object_id ) ) {
			return $map;
		}
		foreach ( array_keys( $map ) as $key ) {
			if ( 0 === strpos( $key, 'rank_math_twitter_' ) ) {
				unset( $map[ $key ] );
			}
		}
		return $map;
	}

	/**
	 * Copy one object's mapped fields, robots and schema type.
	 *
	 * @param string            $object_type 'post' or 'term'.
	 * @param int               $object_id   Object ID.
	 * @param array<string,int> $unknown     Unknown-variable collector (by reference).
	 * @param int               $kept        Count of destinations left untouched (by reference).
	 * @return int Fields written.
	 */
	private static function import_object( $object_type, $object_id, &$unknown, &$kept ) {
		$get    = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$fields = 0;

		foreach ( self::applicable_postmeta_map( $get, $object_id ) as $src => $dst ) {
			$value = call_user_func( $get, $object_id, $src, true );
			if ( '' === $value || null === $value || is_array( $value ) ) {
				continue;
			}
			$value = self::prepare_value( $src, (string) $value, $unknown );
			if ( '' === $value ) {
				continue;
			}
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $dst, $value ) ) {
				++$fields;
			} else {
				++$kept;
			}
		}

		$robots = call_user_func( $get, $object_id, 'rank_math_robots', true );
		if ( ! empty( $robots ) ) {
			$directives = array(
				Nexter_Content_SEO_Robots::META_NOFOLLOW  => 'nofollow',
				Nexter_Content_SEO_Robots::META_NOARCHIVE => 'noarchive',
			);
			// 'index' is an explicit opt-in that overrides a global noindex, so it must be
			// written as '0' rather than treated as "nothing set".
			if ( self::robots_has( $robots, 'noindex' ) ) {
				$directives[ Nexter_Content_SEO_Robots::META_NOINDEX ] = 'noindex';
			} elseif ( self::robots_has( $robots, 'index' ) ) {
				if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SEO_Robots::META_NOINDEX, '0' ) ) {
					++$fields;
				} else {
					++$kept;
				}
			}
			foreach ( $directives as $meta_key => $directive ) {
				if ( ! self::robots_has( $robots, $directive ) ) {
					continue;
				}
				if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $meta_key, '1' ) ) {
					++$fields;
				} else {
					++$kept;
				}
			}
		}

		$schema_type = self::resolve_schema_type( $object_type, $object_id );
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
	 * Is this object set to mirror its Facebook card onto X?
	 *
	 * Rank Math's own metabox stores 'on' for this, while its importers write the literal 'off'
	 * — which is a truthy PHP string. Testing for the negative values rather than for truthiness
	 * keeps both shapes reading correctly.
	 *
	 * @param callable $get       get_post_meta or get_term_meta.
	 * @param int      $object_id Object ID.
	 * @return bool
	 */
	private static function uses_facebook_for_twitter( $get, $object_id ) {
		$value = call_user_func( $get, $object_id, 'rank_math_twitter_use_facebook', true );
		if ( '' === $value || null === $value || false === $value || is_array( $value ) ) {
			return false;
		}
		return ! in_array( strtolower( (string) $value ), array( 'off', '0', 'false', 'no' ), true );
	}

	/**
	 * Nexter schema type for one object.
	 *
	 * Rank Math has two generations of storage: a legacy rank_math_rich_snippet slug, and newer
	 * rank_math_schema_{Type} rows whose key suffix is the schema.org type. The legacy key wins
	 * when both exist, because that is the one Rank Math itself still reads first.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return string
	 */
	private static function resolve_schema_type( $object_type, $object_id ) {
		$get     = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$snippet = call_user_func( $get, $object_id, 'rank_math_rich_snippet', true );
		if ( is_string( $snippet ) && '' !== $snippet && 'off' !== $snippet ) {
			$mapped = Nexter_Content_SEO_Importer::map_schema_type( $snippet );
			if ( '' !== $mapped ) {
				return $mapped;
			}
		}

		// One call primes the whole object's meta cache, so scanning the keys costs nothing extra.
		$all = call_user_func( $get, $object_id );
		if ( ! is_array( $all ) ) {
			return '';
		}

		// Rank Math PRO allows several schemas on one object and flags one isPrimary — the one it
		// renders first — so that is the one Nexter's single type follows. Without the flag
		// (free, or one schema) the first type Nexter can render wins, as before.
		$first = '';
		foreach ( $all as $key => $values ) {
			if ( 0 !== strpos( (string) $key, 'rank_math_schema_' ) ) {
				continue;
			}
			$mapped = Nexter_Content_SEO_Importer::map_schema_type( substr( (string) $key, strlen( 'rank_math_schema_' ) ) );
			if ( '' === $mapped ) {
				continue;
			}
			if ( self::schema_is_primary( is_array( $values ) ? reset( $values ) : $values ) ) {
				return $mapped;
			}
			if ( '' === $first ) {
				$first = $mapped;
			}
		}

		return $first;
	}

	/**
	 * Does a stored rank_math_schema_* value carry Rank Math's isPrimary flag?
	 *
	 * @param mixed $raw Stored meta value (serialized or array).
	 * @return bool
	 */
	private static function schema_is_primary( $raw ) {
		$schema = Nexter_Content_SEO_Importer::unserialize_data( $raw );
		if ( ! is_array( $schema ) ) {
			return false;
		}
		if ( ! empty( $schema['isPrimary'] ) ) {
			return true;
		}

		return ! empty( $schema['metadata']['isPrimary'] );
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
		$selection = Nexter_Content_SEO_Importer::get_unimported_post_ids_by_keys( self::data_meta_keys(), self::SOURCE, $batch );
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;
		$kept      = 0;

		foreach ( $selection['ids'] as $post_id ) {
			$fields += self::import_object( 'post', $post_id, $unknown, $kept );
			update_post_meta( $post_id, $marker, time() );
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
	 * Import one batch of terms. Rank Math stores term SEO in real term meta, so the same
	 * key map and marker mechanism apply unchanged.
	 *
	 * @param int $batch Batch size.
	 * @return array<string,mixed>
	 */
	public static function import_terms_batch( $batch ) {
		// Tells write_if_empty() whose import this is, so it can tell its own earlier writes
		// apart from values the user set.
		Nexter_Content_SEO_Importer::set_active_source( self::SOURCE );
		$selection = Nexter_Content_SEO_Importer::get_unimported_term_ids_by_keys( self::data_meta_keys(), self::SOURCE, $batch );
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;
		$kept      = 0;

		foreach ( $selection['ids'] as $term_id ) {
			$fields += self::import_object( 'term', $term_id, $unknown, $kept );
			update_term_meta( $term_id, $marker, time() );
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
	 * What one object should hold after the import: the applicable field map plus the
	 * converted value each field is expected to have landed as. Robots are included, so a
	 * dropped noindex is a verify failure rather than something nobody looks at.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array{map:array<string,string>,expected:array<string,string>}
	 */
	public static function verify_spec( $object_type, $object_id ) {
		$get      = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$map      = self::applicable_postmeta_map( $get, $object_id );
		$expected = array();
		$ignored  = array();

		foreach ( array_keys( $map ) as $src ) {
			$value            = call_user_func( $get, $object_id, $src, true );
			$expected[ $src ] = ( '' === $value || null === $value || is_array( $value ) )
				? ''
				: self::prepare_value( $src, (string) $value, $ignored );
		}

		$robots = call_user_func( $get, $object_id, 'rank_math_robots', true );
		if ( ! empty( $robots ) ) {
			if ( self::robots_has( $robots, 'noindex' ) ) {
				$map['robots_noindex']      = Nexter_Content_SEO_Robots::META_NOINDEX;
				$expected['robots_noindex'] = '1';
			} elseif ( self::robots_has( $robots, 'index' ) ) {
				$map['robots_noindex']      = Nexter_Content_SEO_Robots::META_NOINDEX;
				$expected['robots_noindex'] = '0';
			}
			foreach ( array(
				'nofollow'  => Nexter_Content_SEO_Robots::META_NOFOLLOW,
				'noarchive' => Nexter_Content_SEO_Robots::META_NOARCHIVE,
			) as $directive => $dst ) {
				if ( self::robots_has( $robots, $directive ) ) {
					$map[ 'robots_' . $directive ]      = $dst;
					$expected[ 'robots_' . $directive ] = '1';
				}
			}
		}

		return array(
			'map'      => $map,
			'expected' => $expected,
		);
	}

	/*
	---------------------------------------------------------------------
	 * Redirections
	 * -------------------------------------------------------------------
	 */

	/**
	 * Rank Math's redirections table, or '' when it does not exist.
	 *
	 * @return string
	 */
	private static function redirections_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ( $found === $table ) ? $table : '';
	}

	/**
	 * Count of active redirect rows.
	 *
	 * @return int
	 */
	private static function count_redirections() {
		global $wpdb;

		$table = self::redirections_table();
		if ( '' === $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved from $wpdb->prefix.
	}

	/**
	 * Rank Math comparison → Nexter condition.
	 *
	 * @return array<string,string>
	 */
	private static function condition_map() {
		return apply_filters(
			'nexter_content_seo_import_rankmath_condition_map',
			array(
				// 'contains' and 'end' are handled by build_rule_args(), not here — see the note
				// there on why they cannot use Nexter's like-named conditions.
				'exact'    => 'exact_match',
				'contains' => 'contains',
				'start'    => 'starts_with',
				'end'      => 'ends_with',
				'regex'    => 'regex',
			)
		);
	}

	/**
	 * Turn one Rank Math source into the rule arguments Nexter's sanitizer expects.
	 *
	 * Rank Math stores a Contains or End source as a bare fragment ("product-old") and matches
	 * it anywhere in the request URI. Nexter's sanitizer resolves a non-regex source against
	 * home_url(), so that fragment becomes "/product-old" and the rule then only matches at a
	 * path boundary — /shop/product-old-thing would stop redirecting. Those two comparisons are
	 * therefore imported as equivalent regex rules instead, which reproduces Rank Math's
	 * matching exactly without changing how Nexter's own Contains/Ends With behave.
	 *
	 * Exact and Start need no such treatment: a request path always begins with "/", so
	 * prefixing one to the fragment is the correct reading of both.
	 *
	 * @param array<string,mixed> $source     One entry from a Rank Math row's sources array.
	 * @param string              $comparison Rank Math comparison.
	 * @param string              $to         Destination URL.
	 * @param int                 $status     Status code.
	 * @return array{args:array<string,mixed>, converted:bool}
	 */
	private static function build_rule_args( $source, $comparison, $to, $status ) {
		$pattern   = (string) $source['pattern'];
		$condition = self::condition_map()[ $comparison ];
		$converted = false;

		if ( 'contains' === $comparison || 'end' === $comparison ) {
			// preg_quote() so a fragment containing regex metacharacters stays a literal. No
			// delimiter argument: the redirection module picks its delimiter at match time from
			// the characters the stored pattern does not contain, so there is no fixed delimiter
			// to escape for here — and that search is what makes the pattern delimiter-safe.
			$quoted = preg_quote( $pattern ); // phpcs:ignore WordPress.PHP.PregQuoteDelimiter.Missing -- delimiter is chosen per-pattern by Nexter_Content_SEO_Redirection::compile_regex().
			$regex  = ( 'end' === $comparison ) ? $quoted . '$' : $quoted;
			$probe  = Nexter_Content_SEO_Redirection::sanitize_rule(
				array(
					'from_url'  => $regex,
					'condition' => 'regex',
				)
			);
			// A fragment can in principle defeat the delimiter search in compile_regex(). Rather
			// than lose the rule, fall back to the like-named condition — narrower matching, but
			// still a working redirect.
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
				// 410/451 rows have no target; NE's sanitizer keeps them as empty to_url.
				'to_url'      => $to,
				'condition'   => $condition,
				'status_code' => $status,
			),
			'converted' => $converted,
		);
	}

	/**
	 * Rank Math redirects → Nexter redirection rules, through the module's own sanitize_rule()
	 * + save_rules() seam. Existing NE rules are kept; a duplicate source is skipped rather than
	 * overwritten, so the user's own rule wins.
	 *
	 * @param bool $dry_run True to compute the result without saving, for the preview.
	 * @return array<string,mixed>
	 */
	public static function import_redirections( $dry_run = false ) {
		global $wpdb;

		$table = self::redirections_table();
		if ( '' === $table || ! class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
			);
		}

		$rows = $wpdb->get_results( "SELECT sources, url_to, header_code FROM `{$table}` WHERE status = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved from $wpdb->prefix.
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

		$conditions = self::condition_map();
		$imported   = 0;
		$skipped    = 0;
		$converted  = 0;

		foreach ( $rows as $row ) {
			// One Rank Math row can carry several sources; each becomes its own Nexter rule.
			$sources = Nexter_Content_SEO_Importer::unserialize_data( $row['sources'] );
			if ( ! is_array( $sources ) ) {
				++$skipped;
				continue;
			}

			foreach ( $sources as $source ) {
				if ( ! is_array( $source ) || empty( $source['pattern'] ) ) {
					++$skipped;
					continue;
				}

				$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
				if ( ! isset( $conditions[ $comparison ] ) ) {
					++$skipped;
					continue;
				}

				$built = self::build_rule_args(
					$source,
					$comparison,
					isset( $row['url_to'] ) ? (string) $row['url_to'] : '',
					isset( $row['header_code'] ) ? (int) $row['header_code'] : 301
				);
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
}
