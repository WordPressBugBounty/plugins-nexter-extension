<?php
/**
 * Content SEO — Yoast SEO importer.
 *
 * Reads Yoast's stored data directly from the database (options + meta), so it works whether
 * Yoast is active, deactivated, or already deleted-but-data-left-behind. Every mapping table
 * is filterable so an edge case can be fixed on a live site without a plugin release.
 *
 * Deliberately NOT imported (reported as skipped, never silently dropped):
 * - focus keywords / SEO scores / content analysis — Nexter's analyzer computes its own;
 * - primary term (no Nexter equivalent yet) and cornerstone flag;
 * - author (user) meta — Content SEO has no per-user title/description destination yet.
 *
 * @package Nexter Extensions
 * @since 4.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Importer_Yoast
 */
class Nexter_Content_SEO_Importer_Yoast {

	const SOURCE      = 'yoast';
	const META_PREFIX = '_yoast_wpseo_';
	const SPLIT_REGEX = '/(%%[a-zA-Z0-9_\-]+%%)/';
	const TOKEN_REGEX = '/^%%[a-zA-Z0-9_\-]+%%$/';

	/**
	 * Human label for the sources list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'Yoast SEO';
	}

	/**
	 * Yoast data present at all?
	 *
	 * @return bool
	 */
	public static function detect() {
		global $wpdb;

		if ( false !== get_option( 'wpseo_titles', false ) || false !== get_option( 'wpseo', false ) ) {
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
		$posts = Nexter_Content_SEO_Importer::get_unimported_post_ids( self::META_PREFIX, self::SOURCE, 0 );
		$terms = self::get_unimported_terms( 0 );

		$redirects       = get_option( 'wpseo-premium-redirects-base', array() );
		$redirects_count = is_array( $redirects ) ? count( $redirects ) : 0;

		return array(
			'posts_remaining' => $posts['remaining'],
			'terms_remaining' => $terms['remaining'],
			'redirections'    => $redirects_count,
			'has_settings'    => ( false !== get_option( 'wpseo_titles', false ) ),
			'plugin_active'   => defined( 'WPSEO_VERSION' ),
		);
	}

	/**
	 * Full preview with zero writes: counts, the exact settings diff, per-field coverage,
	 * and everything that will be skipped (with reasons) — including unknown template
	 * variables, so nothing is dropped silently at import time that the user did not see here.
	 *
	 * @return array<string,mixed>
	 */
	public static function dry_run() {
		global $wpdb;

		$counts = self::counts();

		// Which NE option keys the settings import would change, old => new.
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

		// Per-source-field post coverage: how many posts carry each mapped key.
		$coverage = array();
		$mapped   = array_merge(
			array_keys( self::postmeta_map() ),
			array( '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_meta-robots-adv', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image' )
		);
		foreach ( $mapped as $key ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $n > 0 ) {
				$coverage[ $key ] = $n;
			}
		}

		// Skips the user should know about before running.
		$skips = array();
		$notes = array();
		foreach ( array(
			/* translators: %s: product name. */
			'_yoast_wpseo_focuskw'        => sprintf( __( 'Focus keywords are not imported — %s runs its own analysis.', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
			'_yoast_wpseo_is_cornerstone' => __( 'Cornerstone flags have no Nexter equivalent.', 'nexter-extension' ),
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

		$primary = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_primary\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $primary > 0 ) {
			$skips[] = array(
				'key'    => '_yoast_wpseo_primary_*',
				'count'  => $primary,
				'reason' => __( 'Primary term has no Nexter equivalent yet.', 'nexter-extension' ),
			);
		}

		// Posts that already carry Nexter values. Those are left alone by the import, so say so
		// here rather than letting the user find out from the summary afterwards. Counts only
		// posts that (a) have Nexter data, (b) have Yoast data to bring across, and (c) have not
		// been imported yet — the same three conditions the run itself uses.
		$conflicts = self::count_conflicts();
		if ( $conflicts > 0 ) {
			$skips[] = array(
				/* translators: %s: product name. */
				'key'    => sprintf( __( 'Your existing %s values', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
				'count'  => $conflicts,
				'reason' => __( 'These posts already have a Nexter title or description. The import never overwrites what you set yourself — the Yoast value is skipped and your own is kept.', 'nexter-extension' ),
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

		$user_meta = Nexter_Content_SEO_Importer::user_meta_skip( self::label(), 'wpseo_' );
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

		// Yoast's Organization identity lands in Nexter's Organization SCHEMA row, not in a plain
		// setting — so it is reported here rather than in the settings diff above, where it would
		// not appear.
		$company = get_option( 'wpseo_titles', array() );
		if ( is_array( $company ) && ( ! empty( $company['company_name'] ) || ! empty( $company['company_logo'] ) ) ) {
			$notes[] = array(
				'key'    => __( 'Organization name and logo', 'nexter-extension' ),
				'count'  => 1,
				/* translators: %s: product name. */
				'reason' => sprintf( __( 'These are imported into the Organization schema in %s, which is where it keeps your site identity — you will find them under Schema rather than in the settings list above. If you have already set your own name or logo there, yours is kept.', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
			);
		}

		// Redirect statuses Nexter cannot serve. Its sanitizer coerces anything else to a 301,
		// so these are skipped rather than silently changed — and said so here.
		$bad_status  = 0;
		$status_rows = get_option( 'wpseo-premium-redirects-base', array() );
		if ( is_array( $status_rows ) && class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			foreach ( $status_rows as $r ) {
				if ( empty( $r['origin'] ) ) {
					continue;
				}
				$type = isset( $r['type'] ) ? (int) $r['type'] : 301;
				if ( ! in_array( $type, Nexter_Content_SEO_Redirection::ALLOWED_STATUS_CODES, true ) ) {
					++$bad_status;
				}
			}
		}
		if ( $bad_status > 0 ) {
			$skips[] = array(
				'key'    => __( 'Redirects with an unsupported status', 'nexter-extension' ),
				'count'  => $bad_status,
				/* translators: %s: product name. */
				'reason' => sprintf( __( '%s can serve 301, 302, 307, 308, 410 and 451. These redirects use a different response, so they are skipped rather than changed into a 301 behind your back.', 'nexter-extension' ), Nexter_Content_SEO_Importer::brand() ),
			);
		}

		// Regex redirects import as-is now that Nexter matches on patterns, so the only ones
		// worth warning about are those its sanitizer will refuse.
		$redirects = get_option( 'wpseo-premium-redirects-base', array() );
		$bad_regex = 0;
		if ( is_array( $redirects ) && class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
			foreach ( $redirects as $r ) {
				if ( ! isset( $r['format'] ) || 'regex' !== $r['format'] || empty( $r['origin'] ) ) {
					continue;
				}
				$probe = Nexter_Content_SEO_Redirection::sanitize_rule(
					array(
						'from_url'  => (string) $r['origin'],
						'condition' => 'regex',
					)
				);
				if ( null === $probe ) {
					++$bad_regex;
				}
			}
		}
		if ( $bad_regex > 0 ) {
			$skips[] = array(
				'key'    => 'redirects(regex)',
				'count'  => $bad_regex,
				'reason' => __( 'These regex redirects use a pattern Nexter cannot compile, so they are skipped.', 'nexter-extension' ),
			);
		}

		// Unknown-variable scan over the VALUES the import will actually convert (mapped post
		// meta rows + term rows). Settings-only scanning under-reported: a %%token%% sitting in
		// a post's custom title would otherwise first surface as a silent drop at import time.
		$text_keys = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description' );
		$in        = implode( ',', array_fill( 0, count( $text_keys ), '%s' ) );
		$args      = $text_keys;
		// esc_like so the two percent signs are literals inside the LIKE, not wildcards.
		$args[] = '%' . $wpdb->esc_like( '%%' ) . '%';
		// Bounded scan: enough to catch every token style in use without paging a huge site.
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s LIMIT 2000", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a generated %s placeholder list, values go through prepare().
		foreach ( (array) $values as $value ) {
			self::convert( (string) $value, $unknown_vars );
		}
		$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		if ( is_array( $tax_meta ) ) {
			foreach ( $tax_meta as $terms ) {
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $data ) {
					foreach ( (array) $data as $value ) {
						if ( is_string( $value ) && false !== strpos( $value, '%%' ) ) {
							self::convert( $value, $unknown_vars );
						}
					}
				}
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

	/*
	---------------------------------------------------------------------
	 * Settings
	 * -------------------------------------------------------------------
	 */

	/**
	 * Yoast %%var%% → Nexter %var% map. The separator token maps to the literal glyph Yoast
	 * had configured, because Nexter templates carry the separator inline.
	 *
	 * @return array<string,string>
	 */
	private static function variable_map() {
		$titles     = get_option( 'wpseo_titles', array() );
		$sep_key    = isset( $titles['separator'] ) ? (string) $titles['separator'] : 'sc-dash';
		$sep_glyphs = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '⋆',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
			'sc-lt'     => '<',
			'sc-gt'     => '>',
		);

		$map = array(
			'%%sitename%%'             => '%site_name%',
			'%%sitedesc%%'             => '%tagline%',
			'%%title%%'                => '%post_title%',
			'%%excerpt%%'              => '%post_excerpt%',
			'%%excerpt_only%%'         => '%post_excerpt%',
			'%%term_title%%'           => '%term_title%',
			'%%term_description%%'     => '%term_description%',
			'%%category_description%%' => '%term_description%',
			'%%tag_description%%'      => '%term_description%',
			'%%date%%'                 => '%date_published%',
			'%%modified%%'             => '%date_modified%',
			'%%name%%'                 => '%post_author_name%',
			'%%currentdate%%'          => '%current_date%',
			'%%currentday%%'           => '%current_day%',
			'%%currentmonth%%'         => '%current_month%',
			'%%currentyear%%'          => '%current_year%',
			'%%currenttime%%'          => '%current_time%',
			'%%sep%%'                  => isset( $sep_glyphs[ $sep_key ] ) ? $sep_glyphs[ $sep_key ] : '-',
		);

		/**
		 * Filter the Yoast → Nexter template-variable map.
		 *
		 * @param array<string,string> $map %%token%% => %ne_variable% (or a literal).
		 */
		return apply_filters( 'nexter_content_seo_import_yoast_variable_map', $map );
	}

	/**
	 * Convert one Yoast template value.
	 *
	 * @param string                 $value   Source value.
	 * @param array<string,int>|null $unknown Unknown-token collector (by reference; null
	 *                                       when the caller passes an undeclared variable).
	 * @param-out array<string,int> $unknown
	 * @return string
	 */
	private static function convert( $value, &$unknown ) {
		// Matches build_settings(): a by-ref arg can arrive undeclared, i.e. null.
		$unknown = is_array( $unknown ) ? $unknown : array();
		return Nexter_Content_SEO_Importer::convert_template( (string) $value, self::SPLIT_REGEX, self::TOKEN_REGEX, self::variable_map(), $unknown );
	}

	/**
	 * Build the NE options array the settings import will write.
	 *
	 * Split from import_settings() so the dry run shows the EXACT payload. Only keys with a
	 * real source value are produced — the import must never reset an NE option the user
	 * already configured just because Yoast had nothing there.
	 *
	 * @param array<string,int>|null $unknown Unknown-variable collector (by reference).
	 * @return array<string,mixed>
	 */
	private static function build_settings( &$unknown = null ) {
		$unknown = is_array( $unknown ) ? $unknown : array();

		$main   = get_option( 'wpseo', array() );
		$titles = get_option( 'wpseo_titles', array() );
		$social = get_option( 'wpseo_social', array() );
		$out    = array();

		$main   = is_array( $main ) ? $main : array();
		$titles = is_array( $titles ) ? $titles : array();
		$social = is_array( $social ) ? $social : array();

		// Homepage (blog-index fallback fields; a static front page keeps its per-post meta).
		foreach ( array(
			'title-home-wpseo'    => 'home_title',
			'metadesc-home-wpseo' => 'home_description',
		) as $src => $dst ) {
			if ( ! empty( $titles[ $src ] ) ) {
				$out[ $dst ] = self::convert( $titles[ $src ], $unknown );
			}
		}
		// Current Yoast keeps the front-page OG fields in wpseo_titles as open_graph_frontpage_*;
		// pre-14.0 kept them in wpseo_social as og_frontpage_*, so the modern pair is read first.
		foreach ( array(
			'frontpage_title' => 'home_og_title',
			'frontpage_desc'  => 'home_og_description',
		) as $src => $dst ) {
			if ( ! empty( $titles[ 'open_graph_' . $src ] ) ) {
				$out[ $dst ] = self::convert( $titles[ 'open_graph_' . $src ], $unknown );
			} elseif ( ! empty( $social[ 'og_' . $src ] ) ) {
				$out[ $dst ] = self::convert( $social[ 'og_' . $src ], $unknown );
			}
		}

		$home_image    = '';
		$home_image_id = 0;
		if ( ! empty( $titles['open_graph_frontpage_image'] ) ) {
			$home_image    = (string) $titles['open_graph_frontpage_image'];
			$home_image_id = isset( $titles['open_graph_frontpage_image_id'] ) ? (int) $titles['open_graph_frontpage_image_id'] : 0;
		} elseif ( ! empty( $social['og_frontpage_image'] ) ) {
			$home_image    = (string) $social['og_frontpage_image'];
			$home_image_id = isset( $social['og_frontpage_image_id'] ) ? (int) $social['og_frontpage_image_id'] : 0;
		}
		if ( '' !== $home_image ) {
			$out['home_og_image'] = esc_url_raw( $home_image );
			if ( $home_image_id <= 0 ) {
				$home_image_id = (int) attachment_url_to_postid( $home_image );
			}
			if ( $home_image_id > 0 ) {
				$out['home_og_image_id'] = $home_image_id;
			}
		}

		// Global templates. Nexter has one singular template (not per post type); Yoast's
		// 'post' templates are the closest global representative and only land when set.
		$template_map = apply_filters(
			'nexter_content_seo_import_yoast_template_map',
			array(
				'title-post'            => 'meta_title_template',
				'metadesc-post'         => 'meta_description_template',
				'title-tax-category'    => 'archive_title_template',
				'metadesc-tax-category' => 'archive_description_template',
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

		// Robots defaults: noindex-{post_type} / noindex-tax-{taxonomy} / archive flags.
		$noindex_pt = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( ! empty( $titles[ 'noindex-' . $pt ] ) ) {
				$noindex_pt[ $pt ] = true;
			}
		}
		if ( ! empty( $noindex_pt ) ) {
			$out['noindex_post_types'] = $noindex_pt;
		}

		$noindex_tax = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( ! empty( $titles[ 'noindex-tax-' . $tax ] ) ) {
				$noindex_tax[ $tax ] = true;
			}
		}
		if ( ! empty( $noindex_tax ) ) {
			$out['noindex_taxonomies'] = $noindex_tax;
		}

		$noindex_archives = array();
		if ( ! empty( $titles['noindex-author-wpseo'] ) ) {
			$noindex_archives['author'] = true;
		}
		if ( ! empty( $titles['noindex-archive-wpseo'] ) ) {
			$noindex_archives['date'] = true;
		}
		if ( ! empty( $noindex_archives ) ) {
			$out['noindex_archives'] = $noindex_archives;
		}

		// Archives on/off + attachment handling.
		if ( ! empty( $titles['disable-author'] ) ) {
			$out['disable_author_archives'] = true;
		}
		if ( ! empty( $titles['disable-date'] ) ) {
			$out['disable_date_archives'] = true;
		}
		if ( isset( $titles['disable-attachment'] ) ) {
			$out['redirect_attachment_pages'] = (bool) $titles['disable-attachment'];
		}

		// Sitemap.
		if ( isset( $main['enable_xml_sitemap'] ) ) {
			$out['enable_xml_sitemap'] = (bool) $main['enable_xml_sitemap'];
		}

		// Social profiles + default image. Yoast has stored these in other_social_urls since
		// 20.0; the per-network keys are its pre-20.0 layout. Reading only the legacy keys meant
		// an upgraded site imported whatever stale values those rows still held and dropped every
		// profile added since — which rewrites the site's sameAs list on migration.
		foreach ( self::social_profile_urls( $social ) as $dst => $url ) {
			$out[ $dst ] = $url;
		}
		if ( ! empty( $social['og_default_image'] ) ) {
			$out['default_social_image'] = esc_url_raw( (string) $social['og_default_image'] );
		}

		// Webmaster verification. Yoast defines pinterestverify on the social option group
		// rather than the main one, so reading only $main loses the Pinterest domain claim.
		// Each key is read from social first and main second: the two groups never hold the
		// same key, and the order keeps working whichever group a Yoast version used.
		foreach ( array(
			'googleverify'    => 'google_verification',
			'msverify'        => 'bing_verification',
			'pinterestverify' => 'pinterest_verification',
		) as $src => $dst ) {
			if ( ! empty( $social[ $src ] ) ) {
				$out[ $dst ] = (string) $social[ $src ];
			} elseif ( ! empty( $main[ $src ] ) ) {
				$out[ $dst ] = (string) $main[ $src ];
			}
		}

		/**
		 * Filter the final NE settings payload the Yoast import will save.
		 *
		 * @param array<string,mixed> $out    NE option key => value.
		 * @param array               $titles Raw wpseo_titles.
		 * @param array               $main   Raw wpseo.
		 * @param array               $social Raw wpseo_social.
		 */
		return apply_filters( 'nexter_content_seo_import_yoast_settings', $out, $titles, $main, $social );
	}

	/**
	 * Import global settings through the module's own REST save so its sanitizers run —
	 * never a bare update_option() from here.
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

		// Yoast's Organization identity is not a Nexter setting — it lives in the Organization
		// schema row, so it goes through its own seam rather than the settings payload above.
		$titles = get_option( 'wpseo_titles', array() );
		$org    = Nexter_Content_SEO_Importer::import_organization_identity(
			is_array( $titles ) && isset( $titles['company_name'] ) ? $titles['company_name'] : '',
			is_array( $titles ) && isset( $titles['company_logo'] ) ? $titles['company_logo'] : ''
		);

		$org_keys = array();
		foreach ( $org as $field ) {
			$org_keys[] = 'organization_' . $field;
		}

		return array(
			'updated'           => ( $response->is_error() ? 0 : count( $settings ) ) + count( $org ),
			'keys'              => array_merge( array_keys( $settings ), $org_keys ),
			'unknown_variables' => $unknown,
			'error'             => $response->is_error() ? $response->as_error()->get_error_message() : '',
		);
	}

	/*
	---------------------------------------------------------------------
	 * Post meta
	 * -------------------------------------------------------------------
	 */

	/**
	 * Direct source→destination post meta map (values copied after variable conversion).
	 *
	 * @return array<string,string>
	 */
	private static function postmeta_map() {
		$map = array(
			'_yoast_wpseo_title'                 => Nexter_Content_SEO_Social_Meta::META_TITLE,
			'_yoast_wpseo_metadesc'              => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'_yoast_wpseo_canonical'             => Nexter_Content_SEO_Canonical::META_CANONICAL,
			'_yoast_wpseo_opengraph-title'       => Nexter_Content_SEO_Social_Meta::META_FB_TITLE,
			'_yoast_wpseo_opengraph-description' => Nexter_Content_SEO_Social_Meta::META_FB_DESC,
			'_yoast_wpseo_opengraph-image'       => Nexter_Content_SEO_Social_Meta::META_FB_IMAGE,
			'_yoast_wpseo_twitter-title'         => Nexter_Content_SEO_Social_Meta::META_TW_TITLE,
			'_yoast_wpseo_twitter-description'   => Nexter_Content_SEO_Social_Meta::META_TW_DESC,
			'_yoast_wpseo_twitter-image'         => Nexter_Content_SEO_Social_Meta::META_TW_IMAGE,
		);

		/**
		 * Filter the Yoast → Nexter post/term meta key map.
		 *
		 * @param array<string,string> $map source meta key => NE meta key.
		 */
		return apply_filters( 'nexter_content_seo_import_yoast_postmeta_map', $map );
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
		$selection = Nexter_Content_SEO_Importer::get_unimported_post_ids( self::META_PREFIX, self::SOURCE, $batch );
		$map       = self::postmeta_map();
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;
		$kept      = 0;

		foreach ( $selection['ids'] as $post_id ) {
			$wrote = false;

			foreach ( $map as $src => $dst ) {
				$value = get_post_meta( $post_id, $src, true );
				if ( '' === $value || null === $value || is_array( $value ) ) {
					continue;
				}
				// Canonical and image URLs are copied verbatim; text fields get their Yoast
				// variables converted so "%%title%% %%sep%% %%sitename%%" doesn't render literally.
				if ( false !== strpos( (string) $value, '%%' ) ) {
					$value = self::convert( $value, $unknown );
				}
				if ( Nexter_Content_SEO_Importer::write_if_empty( 'post', $post_id, $dst, $value ) ) {
					$wrote = true;
					++$fields;
				} else {
					++$kept;
				}
			}

			$fields += self::import_object_robots( 'post', $post_id, $kept );
			$fields += self::import_object_schema_type( 'post', $post_id, $kept );

			// Marker even when nothing mapped (e.g. only focuskw rows): the post was examined,
			// re-runs must not re-scan it forever.
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
	 * Map Yoast's schema type onto NE's single per-object schema type.
	 *
	 * Yoast splits this in two: a page type on every object and an article type on posts. NE
	 * stores one type, so the article type wins where both are present.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @param int    $kept        Running count of destinations left untouched (by reference).
	 * @return int Fields written.
	 */
	private static function import_object_schema_type( $object_type, $object_id, &$kept = 0 ) {
		$get = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';

		// Article type is the more specific of the two, so it wins when both are set.
		foreach ( array( '_yoast_wpseo_schema_article_type', '_yoast_wpseo_schema_page_type' ) as $key ) {
			$raw = call_user_func( $get, $object_id, $key, true );
			if ( ! is_string( $raw ) || '' === $raw || 'None' === $raw ) {
				continue;
			}
			$mapped = Nexter_Content_SEO_Importer::map_schema_type( $raw );
			if ( '' === $mapped ) {
				continue;
			}
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SeoRank::META_SCHEMA_TYPE, $mapped ) ) {
				return 1;
			}
			++$kept;
			return 0;
		}

		return 0;
	}

	/**
	 * Normalise Yoast robots meta onto NE's three boolean keys.
	 *
	 * Yoast semantics: meta-robots-noindex 1 = noindex, 2 = explicit index, 0/absent = inherit;
	 * meta-robots-nofollow '1' = nofollow; meta-robots-adv = CSV that may contain 'noarchive'.
	 * NE semantics: '1' explicit on, '0' explicit off, absent = inherit global. Inherit maps to
	 * writing NOTHING — after import the global defaults (imported in settings) resolve it.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @param int    $kept        Running count of destinations left untouched (by reference).
	 * @return int Fields written.
	 */
	private static function import_object_robots( $object_type, $object_id, &$kept = 0 ) {
		$get = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$n   = 0;

		$noindex = (int) call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( 1 === $noindex ) {
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SEO_Robots::META_NOINDEX, '1' ) ) {
				++$n;
			} else {
				++$kept;
			}
		} elseif ( 2 === $noindex ) {
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SEO_Robots::META_NOINDEX, '0' ) ) {
				++$n;
			} else {
				++$kept;
			}
		}

		$nofollow = call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-nofollow', true );
		if ( '1' === (string) $nofollow ) {
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SEO_Robots::META_NOFOLLOW, '1' ) ) {
				++$n;
			} else {
				++$kept;
			}
		}

		$adv = call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-adv', true );
		if ( is_string( $adv ) && '' !== $adv && false !== strpos( $adv, 'noarchive' ) ) {
			if ( Nexter_Content_SEO_Importer::write_if_empty( $object_type, $object_id, Nexter_Content_SEO_Robots::META_NOARCHIVE, '1' ) ) {
				++$n;
			} else {
				++$kept;
			}
		}

		return $n;
	}

	/*
	---------------------------------------------------------------------
	 * Term meta
	 * -------------------------------------------------------------------
	 */

	/**
	 * Yoast keeps ALL term SEO data in one option (wpseo_taxonomy_meta), not in termmeta —
	 * so term selection enumerates that option and filters out already-marked terms.
	 *
	 * Two costs had to go. The original code called get_term_meta() once per term, so every
	 * batch re-queried the whole catalogue. Replacing that with a single marker query fixed the
	 * query count but not the row count: on a 20,000-term catalogue that one query returned
	 * 20,000 rows on every batch — measured at 0.394s, and growing as the import progressed.
	 *
	 * So the batch is now chosen by asking about only the window of term IDs it might use.
	 * Terms are visited in term-id order and markers are written in that same order, so the
	 * window starting at the marker count is normally entirely unimported. When it is not —
	 * a term that failed earlier leaves a gap — the full marker list is read once and the exact
	 * filtering runs, so a failed term is retried rather than skipped. That self-healing is why
	 * this is a window and not a stored cursor: a cursor would step over the gap for good.
	 *
	 * @param int $limit Batch size. 0 = count only.
	 * @return array{remaining:int, rows:array<int,array{term_id:int,taxonomy:string,data:array}>}
	 */
	private static function get_unimported_terms( $limit ) {
		global $wpdb;

		$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		$marker   = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$empty    = array(
			'remaining' => 0,
			'rows'      => array(),
		);

		if ( ! is_array( $tax_meta ) ) {
			return $empty;
		}

		$candidates = array();
		foreach ( $tax_meta as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $data ) {
				$term_id = (int) $term_id;
				if ( $term_id <= 0 || ! is_array( $data ) ) {
					continue;
				}
				$candidates[ $term_id ] = array(
					'term_id'  => $term_id,
					'taxonomy' => (string) $taxonomy,
					'data'     => $data,
				);
			}
		}

		if ( empty( $candidates ) ) {
			return $empty;
		}

		ksort( $candidates, SORT_NUMERIC );
		$ids   = array_keys( $candidates );
		$total = count( $ids );

		// Every marker belongs to a term in this option, so a count is enough to place the window.
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s", $marker ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $limit > 0 && $done < $total ) {
			$rows = self::unmarked_in_window( $candidates, array_slice( $ids, $done, $limit * 2 ), $marker, $limit );
			if ( null !== $rows && count( $rows ) >= min( $limit, $total - $done ) ) {
				return array(
					'remaining' => $total - $done,
					'rows'      => $rows,
				);
			}
		}

		// Exact path: read every marker and filter the whole list.
		$imported  = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", $marker ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$imported  = array_flip( array_map( 'intval', (array) $imported ) );
		$rows      = array();
		$remaining = 0;

		foreach ( $ids as $term_id ) {
			if ( isset( $imported[ $term_id ] ) ) {
				continue;
			}
			++$remaining;
			if ( $limit > 0 && count( $rows ) < $limit ) {
				$rows[] = $candidates[ $term_id ];
			}
		}

		return array(
			'remaining' => $remaining,
			'rows'      => $rows,
		);
	}

	/**
	 * The terms in one window that carry no marker, asking the database about that window only.
	 *
	 * @param array<int,array<string,mixed>> $candidates Term id => row.
	 * @param int[]                          $window     Term ids to ask about.
	 * @param string                         $marker     Marker meta key.
	 * @param int                            $limit      Batch size.
	 * @return array<int,array<string,mixed>>|null Null when the window overlaps an imported term.
	 */
	private static function unmarked_in_window( $candidates, $window, $marker, $limit ) {
		global $wpdb;

		if ( empty( $window ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $window ), '%d' ) );
		$args         = array_merge( array( $marker ), array_map( 'intval', $window ) );
		// The IN list is a generated run of %d placeholders, filled from the same prepare() call.
		$marked = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ( {$placeholders} )", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $marked ) ) {
			// An imported term inside the window means the marker count does not place it, which
			// happens when a term failed earlier and was left unmarked. Let the caller do it exactly.
			return null;
		}

		return array_map(
			static function ( $term_id ) use ( $candidates ) {
				return $candidates[ $term_id ];
			},
			array_slice( $window, 0, $limit )
		);
	}

	/**
	 * Term-level source keys (inside wpseo_taxonomy_meta rows) → NE term meta.
	 *
	 * @return array<string,string>
	 */
	private static function termmeta_map() {
		$map = array(
			'wpseo_title'                 => Nexter_Content_SEO_Social_Meta::META_TITLE,
			'wpseo_desc'                  => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'wpseo_metadesc'              => Nexter_Content_SEO_Social_Meta::META_DESCRIPTION,
			'wpseo_canonical'             => Nexter_Content_SEO_Canonical::META_CANONICAL,
			'wpseo_opengraph-title'       => Nexter_Content_SEO_Social_Meta::META_FB_TITLE,
			'wpseo_opengraph-description' => Nexter_Content_SEO_Social_Meta::META_FB_DESC,
			'wpseo_opengraph-image'       => Nexter_Content_SEO_Social_Meta::META_FB_IMAGE,
			'wpseo_twitter-title'         => Nexter_Content_SEO_Social_Meta::META_TW_TITLE,
			'wpseo_twitter-description'   => Nexter_Content_SEO_Social_Meta::META_TW_DESC,
			'wpseo_twitter-image'         => Nexter_Content_SEO_Social_Meta::META_TW_IMAGE,
		);

		/** This filter is documented in postmeta_map(). */
		return apply_filters( 'nexter_content_seo_import_yoast_termmeta_map', $map );
	}

	/**
	 * Post types and taxonomies carrying their own Yoast title or description template, other
	 * than the two Nexter imports as its global pair.
	 *
	 * @return string[] Type names, for the preview.
	 */
	private static function other_template_types() {
		$titles = get_option( 'wpseo_titles', array() );
		if ( ! is_array( $titles ) ) {
			return array();
		}

		$found = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( 'post' === $pt ) {
				continue;
			}
			if ( ! empty( $titles[ 'title-' . $pt ] ) || ! empty( $titles[ 'metadesc-' . $pt ] ) ) {
				$found[] = (string) $pt;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			if ( 'category' === $tax ) {
				continue;
			}
			if ( ! empty( $titles[ 'title-tax-' . $tax ] ) || ! empty( $titles[ 'metadesc-tax-' . $tax ] ) ) {
				$found[] = (string) $tax;
			}
		}

		return $found;
	}

	/**
	 * Posts that already carry Nexter values the import would otherwise have filled.
	 *
	 * Counts only posts that (a) already have Nexter data in the requested group, (b) have
	 * Yoast data to bring across, and (c) have not been imported yet — the same three
	 * conditions the run itself uses. The group exists because counting only title and
	 * description meant a page deliberately kept out of search could have its robots settings
	 * changed with nothing said in the preview.
	 *
	 * @param string $group 'content' or 'robots'.
	 * @return int
	 */
	private static function count_conflicts( $group = 'content' ) {
		global $wpdb;

		$dest = Nexter_Content_SEO_Importer::destination_keys_sql( $group );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and this class's own key constants; every value goes through prepare().
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT ne.post_id )
				 FROM {$wpdb->postmeta} ne
				 WHERE ne.meta_key IN ( {$dest} )
				   AND ne.meta_value != ''
				   AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} src WHERE src.post_id = ne.post_id AND src.meta_key LIKE %s )
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = ne.post_id AND mk.meta_key = %s )",
				$wpdb->esc_like( self::META_PREFIX ) . '%',
				Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Yoast's social profile URLs mapped onto Nexter's per-network settings.
	 *
	 * The other_social_urls list holds plain URLs with no network label, so each is matched to a
	 * network by host. The pre-20.0 per-network keys then fill only the gaps the modern list
	 * left, so an upgraded site cannot resurrect a profile its owner removed.
	 *
	 * @param array<string,mixed> $social Raw wpseo_social.
	 * @return array<string,string> NE option key => URL.
	 */
	private static function social_profile_urls( $social ) {
		$by_host = apply_filters(
			'nexter_content_seo_import_yoast_social_hosts',
			array(
				'facebook.com'  => 'facebook_page_url',
				'fb.com'        => 'facebook_page_url',
				'twitter.com'   => 'twitter_site',
				'x.com'         => 'twitter_site',
				'linkedin.com'  => 'linkedin_url',
				'instagram.com' => 'instagram_url',
				'youtube.com'   => 'youtube_url',
				'youtu.be'      => 'youtube_url',
				'pinterest.com' => 'pinterest_url',
				'tiktok.com'    => 'tiktok_url',
				't.me'          => 'telegram_url',
				'telegram.me'   => 'telegram_url',
				'wa.me'         => 'whatsapp_url',
				'yelp.com'      => 'yelp_url',
				'bsky.app'      => 'bluesky_url',
			)
		);

		$out    = array();
		$modern = ( isset( $social['other_social_urls'] ) && is_array( $social['other_social_urls'] ) ) ? $social['other_social_urls'] : array();

		foreach ( $modern as $raw ) {
			$url = esc_url_raw( trim( (string) $raw ) );
			if ( '' === $url ) {
				continue;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );
			foreach ( $by_host as $needle => $dst ) {
				// Exact host, or a subdomain of it — never a host that merely ends in the string.
				if ( $host === $needle || substr( $host, - ( strlen( $needle ) + 1 ) ) === '.' . $needle ) {
					if ( ! isset( $out[ $dst ] ) ) {
						$out[ $dst ] = $url;
					}
					break;
				}
			}
		}

		foreach ( array(
			'facebook_site' => 'facebook_page_url',
			'twitter_site'  => 'twitter_site',
			'instagram_url' => 'instagram_url',
			'linkedin_url'  => 'linkedin_url',
			'youtube_url'   => 'youtube_url',
			'pinterest_url' => 'pinterest_url',
		) as $src => $dst ) {
			if ( ! isset( $out[ $dst ] ) && ! empty( $social[ $src ] ) ) {
				$out[ $dst ] = (string) $social[ $src ];
			}
		}

		return $out;
	}

	/**
	 * Import one batch of terms.
	 *
	 * @param int $batch Batch size.
	 * @return array<string,mixed>
	 */
	public static function import_terms_batch( $batch ) {
		// Tells write_if_empty() whose import this is, so it can tell its own earlier writes
		// apart from values the user set.
		Nexter_Content_SEO_Importer::set_active_source( self::SOURCE );
		$selection = self::get_unimported_terms( $batch );
		$map       = self::termmeta_map();
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;
		$kept      = 0;

		foreach ( $selection['rows'] as $row ) {
			$term_id = $row['term_id'];
			$data    = $row['data'];

			foreach ( $map as $src => $dst ) {
				if ( empty( $data[ $src ] ) || is_array( $data[ $src ] ) ) {
					continue;
				}
				$value = (string) $data[ $src ];
				if ( false !== strpos( $value, '%%' ) ) {
					$value = self::convert( $value, $unknown );
				}
				if ( Nexter_Content_SEO_Importer::write_if_empty( 'term', $term_id, $dst, $value ) ) {
					++$fields;
				} else {
					++$kept;
				}
			}

			// Term robots live inside the same row as 'wpseo_noindex' ('noindex'|'index'|'default').
			if ( ! empty( $data['wpseo_noindex'] ) && 'default' !== $data['wpseo_noindex'] ) {
				if ( Nexter_Content_SEO_Importer::write_if_empty( 'term', $term_id, Nexter_Content_SEO_Robots::META_NOINDEX, 'noindex' === $data['wpseo_noindex'] ? '1' : '0' ) ) {
					++$fields;
				} else {
					++$kept;
				}
			}

			$fields += self::import_object_schema_type( 'term', $term_id, $kept );

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
	 * One term's row out of wpseo_taxonomy_meta, which is where Yoast keeps all term SEO.
	 *
	 * The option is read once per request: it holds every term on the site, and the verify
	 * pass would otherwise re-read and re-unserialize it for each sampled term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>
	 */
	private static function term_row( $term_id ) {
		static $by_term = null;

		if ( null === $by_term ) {
			$by_term  = array();
			$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
			foreach ( (array) $tax_meta as $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $id => $row ) {
					if ( is_array( $row ) ) {
						$by_term[ (int) $id ] = $row;
					}
				}
			}
		}

		return isset( $by_term[ (int) $term_id ] ) ? $by_term[ (int) $term_id ] : array();
	}

	/**
	 * What one object should hold after the import: the fields the source actually has, with
	 * their template variables converted exactly as the import converted them, plus robots.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array{map:array<string,string>,expected:array<string,string>}
	 */
	public static function verify_spec( $object_type, $object_id ) {
		$map      = array();
		$expected = array();
		$ignored  = array();

		if ( 'post' === $object_type ) {
			foreach ( self::postmeta_map() as $src => $dst ) {
				$value = get_post_meta( $object_id, $src, true );
				if ( '' === $value || null === $value || is_array( $value ) ) {
					continue;
				}
				$value = (string) $value;
				if ( false !== strpos( $value, '%%' ) ) {
					$value = self::convert( $value, $ignored );
				}
				$map[ $src ]      = $dst;
				$expected[ $src ] = $value;
			}

			$noindex = (int) get_post_meta( $object_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( 1 === $noindex || 2 === $noindex ) {
				$map['robots_noindex']      = Nexter_Content_SEO_Robots::META_NOINDEX;
				$expected['robots_noindex'] = ( 1 === $noindex ) ? '1' : '0';
			}
			if ( '1' === (string) get_post_meta( $object_id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
				$map['robots_nofollow']      = Nexter_Content_SEO_Robots::META_NOFOLLOW;
				$expected['robots_nofollow'] = '1';
			}
			$adv = get_post_meta( $object_id, '_yoast_wpseo_meta-robots-adv', true );
			if ( is_string( $adv ) && false !== strpos( $adv, 'noarchive' ) ) {
				$map['robots_noarchive']      = Nexter_Content_SEO_Robots::META_NOARCHIVE;
				$expected['robots_noarchive'] = '1';
			}
		} else {
			$row = self::term_row( $object_id );
			foreach ( self::termmeta_map() as $src => $dst ) {
				if ( empty( $row[ $src ] ) || is_array( $row[ $src ] ) ) {
					continue;
				}
				$value = (string) $row[ $src ];
				if ( false !== strpos( $value, '%%' ) ) {
					$value = self::convert( $value, $ignored );
				}
				$map[ $src ]      = $dst;
				$expected[ $src ] = $value;
			}

			if ( ! empty( $row['wpseo_noindex'] ) && 'default' !== $row['wpseo_noindex'] ) {
				$map['wpseo_noindex']      = Nexter_Content_SEO_Robots::META_NOINDEX;
				$expected['wpseo_noindex'] = ( 'noindex' === $row['wpseo_noindex'] ) ? '1' : '0';
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
	 * Yoast Premium redirects → Nexter redirection rules, through the module's own
	 * sanitize_rule() + save_rules() seam. Existing NE rules are kept; duplicates
	 * (same from_url) are skipped rather than overwritten — the user's own rule wins.
	 *
	 * @param bool $dry_run True to compute the result without saving, for the preview.
	 * @return array<string,mixed>
	 */
	public static function import_redirections( $dry_run = false ) {
		$source = get_option( 'wpseo-premium-redirects-base', array() );
		if ( ! is_array( $source ) || empty( $source ) || ! class_exists( 'Nexter_Content_SEO_Redirection' ) ) {
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

		$imported = 0;
		$skipped  = 0;

		foreach ( $source as $redirect ) {
			if ( empty( $redirect['origin'] ) ) {
				++$skipped;
				continue;
			}
			// Yoast stores a regex source as a bare pattern, which is exactly how Nexter stores
			// one, so the source copies across untouched.
			$condition = ( isset( $redirect['format'] ) && 'regex' === $redirect['format'] ) ? 'regex' : 'exact_match';

			$status = isset( $redirect['type'] ) ? (int) $redirect['type'] : 301;
			if ( ! in_array( $status, Nexter_Content_SEO_Redirection::ALLOWED_STATUS_CODES, true ) ) {
				// Nexter's sanitizer would quietly coerce this to a 301, turning a response the site
				// owner chose into one they did not. Skipping and counting it is the honest outcome,
				// and matches what the AIOSEO adapter already does.
				++$skipped;
				continue;
			}

			$rule = Nexter_Content_SEO_Redirection::sanitize_rule(
				array(
					'enabled'     => true,
					'from_url'    => (string) $redirect['origin'],
					// 410/451 rows have no target; NE's sanitizer keeps them as empty to_url.
					'to_url'      => isset( $redirect['url'] ) ? (string) $redirect['url'] : '',
					'condition'   => $condition,
					'status_code' => $status,
				)
			);

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
			'loop_warning'       => $loop,
		);
	}
}
