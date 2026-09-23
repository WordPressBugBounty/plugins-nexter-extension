<?php
/**
 * Content SEO — SureRank importer.
 *
 * Reads SureRank's stored data straight from the database (one option + flat per-object meta),
 * so it works whether SureRank is active, deactivated, or removed with its data left behind.
 * Every mapping table is filterable so an edge case can be fixed without a release.
 *
 * SureRank groups its per-object settings into three serialized meta rows —
 * surerank_settings_general (title, description, canonical), surerank_settings_social (the
 * Facebook/X fields) and surerank_settings_schemas — beside three flat robots scalars
 * (surerank_settings_post_no_index/_no_follow/_no_archive). Selection keys off those row names
 * and every field read below goes through object_values(), which flattens them back out.
 *
 * Redirects come from SureRank's paid edition, which adds a redirection module storing rules in
 * the surerank_redirections option; free SureRank has none, so that category reports zero rather
 * than being absent. The Pro edition adds no per-object SEO fields of its own — it writes the
 * same surerank_settings_* meta — so the field map below covers both editions.
 *
 * Deliberately NOT imported (reported as skipped, never silently dropped):
 * - SEO checks / analysis results — Nexter runs its own audit;
 * - schema field DATA — only the schema TYPE maps across;
 * - Pro-only features with no Nexter counterpart: breadcrumbs, email reports, IndexNow keys,
 *   the link manager and the HTML/news/video/author sitemaps.
 *
 * @package Nexter Extensions
 * @since 4.7.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Importer_Surerank
 */
class Nexter_Content_SEO_Importer_Surerank {

	const SOURCE      = 'surerank';
	const META_PREFIX = 'surerank_settings_';
	const OPTION_NAME = 'surerank_settings';

	/** Redirection rules, added by SureRank's paid edition. */
	const REDIRECT_OPTION = 'surerank_redirections';

	/** SureRank variables are single-percent with no arguments: %title%, %site_name%. */
	// A variable name always starts with a letter. Without that anchor "50%-70%" reads as
	// the token %-70% and gets dropped as an unknown variable, eating the literal text.
	const SPLIT_REGEX = '/(%[a-zA-Z][a-zA-Z0-9_\-]*%)/';
	const TOKEN_REGEX = '/^%[a-zA-Z][a-zA-Z0-9_\-]*%$/';

	/** The serialized per-object rows SureRank writes, in the order object_values() reads them. */
	const GROUP_KEYS = array( 'general', 'social', 'schemas' );

	/** Robots values, stored as their own scalar rows rather than inside a group. */
	const ROBOTS_KEYS = array( 'post_no_index', 'post_no_follow', 'post_no_archive' );

	/** Fields copied verbatim: template conversion would corrupt percent-encoded paths. */
	const URL_FIELDS = array( 'canonical_url', 'facebook_image_url', 'twitter_image_url' );

	/**
	 * Human label for the sources list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'SureRank';
	}

	/**
	 * Source meta keys that count as real SEO data.
	 *
	 * @return string[]
	 */
	private static function data_meta_keys() {
		$keys = array();
		foreach ( array_merge( self::GROUP_KEYS, self::ROBOTS_KEYS ) as $key ) {
			$keys[] = self::META_PREFIX . $key;
		}

		/**
		 * Filter the SureRank meta keys that mark a post/term as worth importing.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return apply_filters( 'nexter_content_seo_import_surerank_data_keys', $keys );
	}

	/**
	 * SureRank data present at all?
	 *
	 * @return bool
	 */
	public static function detect() {
		global $wpdb;

		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			return true;
		}

		if ( ! empty( self::stored_redirections() ) ) {
			return true;
		}

		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			// Only the paid edition has redirects; free SureRank simply reports none.
			'redirections'    => self::count_redirect_rules(),
			'has_settings'    => ( false !== get_option( self::OPTION_NAME, false ) ),
			'plugin_active'   => defined( 'SURERANK_VERSION' ),
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

		$coverage = array();
		foreach ( self::data_meta_keys() as $key ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $n > 0 ) {
				$coverage[ $key ] = $n;
			}
		}

		$skips  = array();
		$checks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'surerank_seo_checks'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $checks > 0 ) {
			$skips[] = array(
				'key'    => 'surerank_seo_checks',
				'count'  => $checks,
				'reason' => __( 'SureRank SEO check results are not imported — Nexter runs its own audit.', 'nexter-extension' ),
			);
		}

		$schemas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", self::META_PREFIX . 'schemas' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $schemas > 0 ) {
			$skips[] = array(
				'key'    => self::META_PREFIX . 'schemas',
				'count'  => $schemas,
				'reason' => __( 'The schema type is imported; the individual schema field values are not — Nexter builds those from its own field set.', 'nexter-extension' ),
			);
		}

		$settings = self::stored_settings();
		if ( ! empty( $settings['noindex_paginated_pages'] ) ) {
			$skips[] = array(
				'key'    => 'noindex_paginated_pages',
				'count'  => 1,
				'reason' => __( 'Nexter has no matching setting for noindexing paginated archive pages, so this one is left behind.', 'nexter-extension' ),
			);
		}

		$notes    = array();
		$disabled = 0;
		$substr   = 0;
		foreach ( self::redirect_rules() as $rule ) {
			if ( ! $rule['enabled'] ) {
				++$disabled;
				continue;
			}
			if ( in_array( $rule['match_type'], array( 'contains', 'ends_with' ), true ) ) {
				++$substr;
			}
		}
		if ( $disabled > 0 ) {
			$skips[] = array(
				'key'    => __( 'Turned-off redirects', 'nexter-extension' ),
				'count'  => $disabled,
				'reason' => __( 'These redirects are switched off in SureRank, so they are not brought across. Turn them on before importing if you want them.', 'nexter-extension' ),
			);
		}
		if ( $substr > 0 ) {
			$notes[] = array(
				'key'    => __( 'Redirects that match part of a URL', 'nexter-extension' ),
				'count'  => $substr,
				'reason' => __( 'SureRank "Contains" and "Ends With" redirects are imported as Regex rules so they keep matching exactly as they do now. They will show as Regex in the Redirection Manager.', 'nexter-extension' ),
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

		$user_meta = Nexter_Content_SEO_Importer::user_meta_skip( self::label(), 'surerank_settings_' );
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

		$conflicts = self::count_conflicts();
		if ( $conflicts > 0 ) {
			$skips[] = array(
				/* translators: %s: product name. */
				'key'    => sprintf( __( 'Your existing %s values', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
				'count'  => $conflicts,
				'reason' => __( 'These posts already have a Nexter title or description. The import never overwrites what you set yourself — the SureRank value is skipped and your own is kept.', 'nexter-extension' ),
			);
		}

		// Unknown-variable scan over the values the import will actually convert.
		$text_keys = array(
			self::META_PREFIX . 'page_title',
			self::META_PREFIX . 'page_description',
			self::META_PREFIX . 'facebook_title',
			self::META_PREFIX . 'facebook_description',
			self::META_PREFIX . 'twitter_title',
			self::META_PREFIX . 'twitter_description',
		);
		$in        = implode( ',', array_fill( 0, count( $text_keys ), '%s' ) );
		$args      = $text_keys;
		$args[]    = '%' . $wpdb->esc_like( '%' ) . '%';
		$values    = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s LIMIT 2000", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a generated %s list; values go through prepare().
		foreach ( (array) $values as $value ) {
			self::convert( (string) $value, $unknown_vars );
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
	 * SureRank's global settings, as stored — NOT merged with its defaults.
	 *
	 * Reading merged defaults would import settings the user never chose; author_archive in
	 * particular defaults to false (archives OFF), which merged in would switch off archives on
	 * a site that had simply never opened the setting.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored_settings() {
		$value = get_option( self::OPTION_NAME, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * SureRank %var% → Nexter %var% map. %separator% resolves to the configured glyph, because
	 * Nexter templates carry the separator inline.
	 *
	 * @return array<string,string>
	 */
	private static function variable_map() {
		$settings = self::stored_settings();
		$sep      = isset( $settings['separator'] ) ? (string) $settings['separator'] : '-';

		$map = array(
			'%site_name%'        => '%site_name%',
			'%tagline%'          => '%tagline%',
			'%title%'            => '%post_title%',
			'%excerpt%'          => '%post_excerpt%',
			'%content%'          => '%post_content%',
			'%permalink%'        => '%post_url%',
			'%term_title%'       => '%term_title%',
			'%archive_title%'    => '%term_title%',
			'%term_description%' => '%term_description%',
			'%published%'        => '%date_published%',
			'%modified%'         => '%date_modified%',
			'%author_name%'      => '%post_author_name%',
			'%org_name%'         => '%organization_name%',
			'%org_url%'          => '%organization_url%',
			'%org_logo%'         => '%organization_logo%',
			'%website_name%'     => '%site_name%',
			'%website_url%'      => '%post_url%',
			'%currentdate%'      => '%current_date%',
			'%currentday%'       => '%current_day%',
			'%currentmonth%'     => '%current_month%',
			'%currentyear%'      => '%current_year%',
			'%currenttime%'      => '%current_time%',
			'%separator%'        => $sep,
		);

		/**
		 * Filter the SureRank → Nexter template-variable map.
		 *
		 * @param array<string,string> $map %token% => %ne_variable% (or a literal).
		 */
		return apply_filters( 'nexter_content_seo_import_surerank_variable_map', $map );
	}

	/**
	 * Convert one SureRank template value.
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
	 * Build the NE options array the settings import will write.
	 *
	 * @param array<string,int>|null $unknown Unknown-variable collector (by reference).
	 * @return array<string,mixed>
	 */
	private static function build_settings( &$unknown = null ) {
		$unknown  = is_array( $unknown ) ? $unknown : array();
		$settings = self::stored_settings();
		$out      = array();

		// Homepage + global templates.
		foreach ( array(
			'home_page_title'                => 'home_title',
			'home_page_description'          => 'home_description',
			'home_page_facebook_title'       => 'home_og_title',
			'home_page_facebook_description' => 'home_og_description',
			'page_title'                     => 'meta_title_template',
			'page_description'               => 'meta_description_template',
		) as $src => $dst ) {
			if ( ! empty( $settings[ $src ] ) ) {
				$converted = self::convert( $settings[ $src ], $unknown );
				if ( '' !== $converted ) {
					$out[ $dst ] = $converted;
				}
			}
		}

		if ( ! empty( $settings['home_page_facebook_image_url'] ) ) {
			$out['home_og_image'] = esc_url_raw( (string) $settings['home_page_facebook_image_url'] );
			$img_id               = attachment_url_to_postid( (string) $settings['home_page_facebook_image_url'] );
			if ( $img_id > 0 ) {
				$out['home_og_image_id'] = $img_id;
			}
		}

		// SureRank's archive keys are ENABLE flags (empty = archive disabled, and it redirects to
		// home). Nexter's are DISABLE flags, so the value inverts.
		//
		// The EFFECTIVE value is imported, not only an explicitly stored one. SureRank ships
		// these archives OFF and Nexter ships them ON, so a site that never touched the setting
		// had nothing stored, the importer set nothing, and the archives silently came back.
		$out['disable_author_archives'] = empty( self::effective_setting( $settings, 'author_archive' ) );
		$out['disable_date_archives']   = empty( self::effective_setting( $settings, 'date_archive' ) );

		// Global robots. SureRank keeps three arrays of "things that are noindex/nofollow/
		// noarchive", holding a mix of post types, taxonomies and archive names in one list.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( array(
			'no_index'   => array( 'noindex_post_types', 'noindex_taxonomies', 'noindex_archives' ),
			'no_follow'  => array( 'nofollow_post_types', 'nofollow_taxonomies', 'nofollow_archives' ),
			'no_archive' => array( 'noarchive_post_types', 'noarchive_taxonomies', 'noarchive_archives' ),
		) as $src => $destinations ) {
			if ( empty( $settings[ $src ] ) || ! is_array( $settings[ $src ] ) ) {
				continue;
			}
			list( $pt_key, $tax_key, $archive_key ) = $destinations;
			$pt_out                                 = array();
			$tax_out                                = array();
			$archive_out                            = array();
			foreach ( $settings[ $src ] as $entry ) {
				$entry = (string) $entry;
				if ( in_array( $entry, $post_types, true ) ) {
					$pt_out[ $entry ] = true;
				} elseif ( in_array( $entry, $taxonomies, true ) ) {
					$tax_out[ $entry ] = true;
				} elseif ( in_array( $entry, array( 'author', 'date', 'search' ), true ) ) {
					$archive_out[ $entry ] = true;
				}
			}
			if ( ! empty( $pt_out ) ) {
				$out[ $pt_key ] = $pt_out;
			}
			if ( ! empty( $tax_out ) ) {
				$out[ $tax_key ] = $tax_out;
			}
			if ( ! empty( $archive_out ) ) {
				$out[ $archive_key ] = $archive_out;
			}
		}

		// Social profiles + default image.
		if ( ! empty( $settings['facebook_page_url'] ) ) {
			$out['facebook_page_url'] = (string) $settings['facebook_page_url'];
		}
		if ( ! empty( $settings['twitter_profile_username'] ) ) {
			$out['twitter_site'] = (string) $settings['twitter_profile_username'];
		}
		if ( ! empty( $settings['facebook_author_fallback'] ) ) {
			$out['facebook_author_url'] = (string) $settings['facebook_author_fallback'];
		}
		if ( ! empty( $settings['fallback_image'] ) ) {
			$out['default_social_image'] = esc_url_raw( (string) $settings['fallback_image'] );
		}
		if ( ! empty( $settings['twitter_card_type'] ) ) {
			$out['twitter_card_layout'] = (string) $settings['twitter_card_type'];
		}

		// Sitemap.
		if ( array_key_exists( 'enable_xml_image_sitemap', $settings ) ) {
			$out['sitemap_include_images'] = ! empty( $settings['enable_xml_image_sitemap'] );
		}

		/**
		 * Filter the final NE settings payload the SureRank import will save.
		 *
		 * @param array<string,mixed> $out      NE option key => value.
		 * @param array               $settings Raw surerank_settings.
		 */
		return apply_filters( 'nexter_content_seo_import_surerank_settings', $out, $settings );
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
	 * SureRank's own default for a global setting whose default differs from Nexter's.
	 *
	 * Hardcoded rather than read from SureRank's Defaults class on purpose: this importer is
	 * built to run with SureRank deactivated or removed, and SureRank's own defaults builder
	 * documents a re-entrancy trap on sites with WooCommerce. These values were read from
	 * SureRank 1.10's Defaults class; the filter is the escape hatch if a later version
	 * changes them.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_defaults() {
		return apply_filters(
			'nexter_content_seo_import_surerank_source_defaults',
			array(
				// Special pages: SureRank ships both archives disabled.
				'author_archive' => false,
				'date_archive'   => false,
			)
		);
	}

	/**
	 * The value SureRank is actually applying: the stored one, or its own default when the
	 * site never touched the setting.
	 *
	 * @param array<string,mixed> $settings Stored SureRank settings.
	 * @param string              $key      Setting key.
	 * @return mixed
	 */
	private static function effective_setting( $settings, $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		$defaults = self::source_defaults();

		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	}

	/**
	 * Direct source→destination field map, keyed by SureRank's own field name as it appears
	 * inside the grouped rows — the fields have no meta key of their own. SureRank uses the
	 * same field names on posts and terms.
	 *
	 * @return array<string,string>
	 */
	private static function postmeta_map() {
		$map = array(
			'page_title'           => Nexter_Content_SEO_Social_Meta::META_TITLE,
			'page_description'     => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'canonical_url'        => Nexter_Content_SEO_Canonical::META_CANONICAL,
			'facebook_title'       => Nexter_Content_SEO_Social_Meta::META_FB_TITLE,
			'facebook_description' => Nexter_Content_SEO_Social_Meta::META_FB_DESC,
			'facebook_image_url'   => Nexter_Content_SEO_Social_Meta::META_FB_IMAGE,
			'twitter_title'        => Nexter_Content_SEO_Social_Meta::META_TW_TITLE,
			'twitter_description'  => Nexter_Content_SEO_Social_Meta::META_TW_DESC,
			'twitter_image_url'    => Nexter_Content_SEO_Social_Meta::META_TW_IMAGE,
		);

		/**
		 * Filter the SureRank → Nexter post/term meta key map.
		 *
		 * @param array<string,string> $map source meta key => NE meta key.
		 */
		return apply_filters( 'nexter_content_seo_import_surerank_postmeta_map', $map );
	}

	/**
	 * One object's SureRank fields, flattened to field name => value.
	 *
	 * SureRank writes each group through Utils::process_option_values(), which stores a group
	 * only when it is non-empty, so any of the three rows may be absent. The robots values sit
	 * outside the groups as their own scalar rows.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array<string,mixed>
	 */
	private static function object_values( $object_type, $object_id ) {
		$get    = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$values = array();

		foreach ( self::GROUP_KEYS as $group ) {
			$row = call_user_func( $get, $object_id, self::META_PREFIX . $group, true );
			if ( is_string( $row ) && '' !== $row ) {
				// Refuse objects: the row is data, and a crafted O: payload must not instantiate anything.
				$row = is_serialized( $row )
					? unserialize( $row, array( 'allowed_classes' => false ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					: json_decode( $row, true );
			}
			if ( is_array( $row ) ) {
				// Field names are unique across the groups, so a flat merge keeps each addressable.
				$values = array_merge( $values, $row );
			}
		}

		foreach ( self::ROBOTS_KEYS as $key ) {
			$row = call_user_func( $get, $object_id, self::META_PREFIX . $key, true );
			if ( is_string( $row ) && '' !== $row ) {
				$values[ $key ] = $row;
			}
		}

		return $values;
	}

	/**
	 * The field map minus the fields this object has nothing to give.
	 *
	 * SureRank's twitter_same_as_facebook (default true) mirrors the Facebook values onto X
	 * instead of storing separate ones, so on those objects the X fields hold either nothing
	 * or a stale value SureRank itself does not render.
	 *
	 * @param array<string,mixed> $values Flattened source values.
	 * @return array<string,string>
	 */
	private static function applicable_postmeta_map( $values ) {
		$map = self::postmeta_map();

		if ( ! isset( $values['twitter_same_as_facebook'] ) || ! empty( $values['twitter_same_as_facebook'] ) ) {
			foreach ( array_keys( $map ) as $field ) {
				if ( 0 === strpos( $field, 'twitter_' ) ) {
					unset( $map[ $field ] );
				}
			}
		}

		return $map;
	}

	/**
	 * One field's value ready to write.
	 *
	 * URL fields are copied verbatim through esc_url_raw(): running template conversion over a
	 * URL destroys percent-encoded paths, where %C3% is indistinguishable from a variable token.
	 *
	 * @param string            $field   SureRank field name.
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
	 * Copy one object's mapped fields, robots and schema type.
	 *
	 * @param string            $object_type 'post' or 'term'.
	 * @param int               $object_id   Object ID.
	 * @param array<string,int> $unknown     Unknown-variable collector (by reference).
	 * @param int               $kept        Count of destinations left untouched (by reference).
	 * @return int Fields written.
	 */
	private static function import_object( $object_type, $object_id, &$unknown, &$kept ) {
		$values = self::object_values( $object_type, $object_id );
		$fields = 0;

		foreach ( self::applicable_postmeta_map( $values ) as $src => $dst ) {
			$value = isset( $values[ $src ] ) ? $values[ $src ] : '';
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

		// SureRank stores robots as 'yes'/'no' strings. 'no' is an explicit opt-in to indexing,
		// so it maps to '0' rather than to "nothing set"; anything else means inherit.
		foreach ( self::robots_map() as $src => $dst ) {
			$value = isset( $values[ $src ] ) ? $values[ $src ] : '';
			if ( ! is_string( $value ) || ( 'yes' !== $value && 'no' !== $value ) ) {
				continue;
			}
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, $dst, 'yes' === $value ? '1' : '0' ) ) {
				++$fields;
			} else {
				++$kept;
			}
		}

		$schema_type = self::resolve_schema_type( isset( $values['schemas'] ) ? $values['schemas'] : null );
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
	 * SureRank robots field => Nexter robots meta key. Shared by the import and the verify
	 * pass so the two can never disagree about which fields are supposed to have landed.
	 *
	 * @return array<string,string>
	 */
	private static function robots_map() {
		return array(
			'post_no_index'   => Nexter_Content_SEO_Robots::META_NOINDEX,
			'post_no_follow'  => Nexter_Content_SEO_Robots::META_NOFOLLOW,
			'post_no_archive' => Nexter_Content_SEO_Robots::META_NOARCHIVE,
		);
	}

	/**
	 * Nexter schema type from a SureRank schemas blob.
	 *
	 * SureRank keeps a list of schema definitions per object; the first one Nexter can represent
	 * wins, because Nexter stores a single per-object schema type.
	 *
	 * @param mixed $schemas Stored surerank_settings_schemas value.
	 * @return string
	 */
	private static function resolve_schema_type( $schemas ) {
		if ( is_string( $schemas ) && '' !== $schemas ) {
			$decoded = json_decode( $schemas, true );
			// Refuse objects: this is stored data, not a payload that may instantiate classes.
			$schemas = is_array( $decoded ) || ! is_serialized( $schemas )
				? $decoded
				: unserialize( $schemas, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}
		if ( ! is_array( $schemas ) ) {
			return '';
		}
		// SureRank stores the row as [ 'schemas' => [ id => record ] ]. object_values() already
		// hands over the inner list, but a whole row can still arrive through the filter.
		if ( isset( $schemas['schemas'] ) && is_array( $schemas['schemas'] ) ) {
			$schemas = $schemas['schemas'];
		}

		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}
			foreach ( array( '@type', 'type', 'schema_type' ) as $key ) {
				if ( empty( $schema[ $key ] ) || ! is_string( $schema[ $key ] ) ) {
					continue;
				}
				$mapped = Nexter_Content_SEO_Importer::map_schema_type( $schema[ $key ] );
				if ( '' !== $mapped ) {
					return $mapped;
				}
			}
		}

		return '';
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
	 * Import one batch of terms. SureRank stores term SEO in real term meta under the same key
	 * names, so the post map and marker mechanism apply unchanged.
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
	 * converted value each field is expected to have landed as.
	 *
	 * The map is built per object, so an object mirroring Facebook onto X is not checked for
	 * the X fields the import correctly skipped. Values are converted first for the same
	 * reason the import converts them: a title made only of tags Nexter cannot represent
	 * correctly writes nothing, and that must not be reported as a lost field.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array{map:array<string,string>,expected:array<string,string>}
	 */
	public static function verify_spec( $object_type, $object_id ) {
		$values   = self::object_values( $object_type, $object_id );
		$map      = self::applicable_postmeta_map( $values );
		$ignored  = array();
		$expected = array();

		foreach ( array_keys( $map ) as $field ) {
			$value              = isset( $values[ $field ] ) && ! is_array( $values[ $field ] ) ? (string) $values[ $field ] : '';
			$expected[ $field ] = ( '' === $value ) ? '' : self::prepare_value( $field, $value, $ignored );
		}

		// Robots and the schema type are written by the import too, so a verify that ignored
		// them would pass an import that silently dropped a deliberate noindex.
		foreach ( self::robots_map() as $field => $dst ) {
			$value = isset( $values[ $field ] ) ? $values[ $field ] : '';
			if ( 'yes' !== $value && 'no' !== $value ) {
				continue;
			}
			$map[ $field ]      = $dst;
			$expected[ $field ] = ( 'yes' === $value ) ? '1' : '0';
		}

		$schema_type = self::resolve_schema_type( isset( $values['schemas'] ) ? $values['schemas'] : null );
		if ( '' !== $schema_type ) {
			$map['schema_type']      = Nexter_Content_SeoRank::META_SCHEMA_TYPE;
			$expected['schema_type'] = $schema_type;
		}

		return array(
			'map'      => $map,
			'expected' => $expected,
		);
	}

	/*
	---------------------------------------------------------------------
	 * Redirects (SureRank paid edition)
	 * -------------------------------------------------------------------
	 */

	/**
	 * The stored redirection records, keyed by id.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored_redirections() {
		$value = get_option( self::REDIRECT_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * SureRank match type → Nexter condition.
	 *
	 * @return array<string,string>
	 */
	private static function condition_map() {
		return apply_filters(
			'nexter_content_seo_import_surerank_condition_map',
			array(
				// 'contains' and 'ends_with' are handled by build_rule_args(), not here — see
				// the note there on why they cannot use Nexter's like-named conditions.
				'exact'       => 'exact_match',
				'contains'    => 'contains',
				'starts_with' => 'starts_with',
				'ends_with'   => 'ends_with',
			)
		);
	}

	/**
	 * Every enabled source rule across all records — one SureRank record can carry several.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function redirect_rules() {
		$out = array();

		foreach ( self::stored_redirections() as $record ) {
			if ( ! is_array( $record ) || empty( $record['from_url'] ) || ! is_array( $record['from_url'] ) ) {
				continue;
			}
			foreach ( $record['from_url'] as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['value'] ) || '' === (string) $rule['value'] ) {
					continue;
				}
				$out[] = array(
					'match_type' => isset( $rule['match_type'] ) ? (string) $rule['match_type'] : 'exact',
					'value'      => (string) $rule['value'],
					'to_url'     => isset( $record['to_url'] ) ? (string) $record['to_url'] : '',
					'status'     => isset( $record['redirection_type'] ) ? (int) $record['redirection_type'] : 301,
					'enabled'    => ! empty( $record['enabled'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Enabled rules available to import.
	 *
	 * @return int
	 */
	private static function count_redirect_rules() {
		$count = 0;
		foreach ( self::redirect_rules() as $rule ) {
			if ( $rule['enabled'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Turn one SureRank source rule into the arguments Nexter's sanitizer expects.
	 *
	 * SureRank matches a Contains or Ends With source against the raw request path with strpos()
	 * and substr(), so a bare fragment matches anywhere in it. Nexter's sanitizer resolves a
	 * non-regex source against home_url(), which would turn that fragment into a path and limit
	 * it to a path boundary. Those two match types are therefore imported as equivalent regex
	 * rules, reproducing SureRank's matching without changing Nexter's own Contains/Ends With.
	 *
	 * Exact and Starts With need no such treatment: a request path always begins with "/", so
	 * prefixing one is the correct reading of both.
	 *
	 * @param array<string,mixed> $rule One entry from redirect_rules().
	 * @return array{args:array<string,mixed>, converted:bool}
	 */
	private static function build_rule_args( $rule ) {
		$pattern    = $rule['value'];
		$match_type = $rule['match_type'];
		$condition  = self::condition_map()[ $match_type ];
		$converted  = false;

		if ( 'contains' === $match_type || 'ends_with' === $match_type ) {
			// preg_quote() so a fragment containing regex metacharacters stays a literal. No
			// delimiter argument: the redirection module picks its delimiter at match time from
			// the characters the stored pattern does not contain.
			$quoted = preg_quote( $pattern ); // phpcs:ignore WordPress.PHP.PregQuoteDelimiter.Missing -- delimiter is chosen per-pattern by Nexter_Content_SEO_Redirection::compile_regex().
			$regex  = ( 'ends_with' === $match_type ) ? $quoted . '$' : $quoted;
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
				'to_url'      => $rule['to_url'],
				'condition'   => $condition,
				'status_code' => $rule['status'],
			),
			'converted' => $converted,
		);
	}

	/**
	 * SureRank redirects → Nexter redirection rules, through the module's own sanitize_rule() +
	 * save_rules() seam. Existing NE rules are kept; a duplicate source is skipped rather than
	 * overwritten, so the user's own rule wins.
	 *
	 * @param bool $dry_run True to compute the result without saving, for the preview.
	 * @return array<string,mixed>
	 */
	public static function import_redirections( $dry_run = false ) {
		$rules = self::redirect_rules();
		if ( empty( $rules ) || ! class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
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
		$allowed    = Nexter_Content_SEO_Redirection::ALLOWED_STATUS_CODES;
		$imported   = 0;
		$skipped    = 0;
		$converted  = 0;

		foreach ( $rules as $rule ) {
			if ( ! $rule['enabled'] || ! isset( $conditions[ $rule['match_type'] ] ) || ! in_array( $rule['status'], $allowed, true ) ) {
				++$skipped;
				continue;
			}

			$built = self::build_rule_args( $rule );
			$saved = Nexter_Content_SEO_Redirection::sanitize_rule( $built['args'] );

			if ( null === $saved || '' === $saved['from_url'] || isset( $existing_from[ $saved['from_url'] ] ) ) {
				++$skipped;
				continue;
			}

			if ( $max > 0 && count( $existing ) >= $max ) {
				++$over;
				continue;
			}
			$existing[]                          = $saved;
			$existing_from[ $saved['from_url'] ] = true;
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
}
