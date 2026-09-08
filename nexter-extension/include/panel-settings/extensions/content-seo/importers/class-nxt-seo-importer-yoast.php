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
 * - primary term (no Nexter equivalent yet), cornerstone flag, per-post schema type (M3);
 * - author (user) meta — Content SEO has no per-user title/description destination yet;
 * - regex-format redirects — the redirection module matches exact/contains/starts/ends only.
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
		$settings_preview = self::build_settings( $unknown_vars );

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
		foreach ( array(
			'_yoast_wpseo_focuskw'             => __( 'Focus keywords are not imported — Nexter SEO runs its own analysis.', 'nexter-extension' ),
			'_yoast_wpseo_is_cornerstone'      => __( 'Cornerstone flags have no Nexter equivalent.', 'nexter-extension' ),
			'_yoast_wpseo_schema_article_type' => __( 'Per-post schema type import arrives with the schema milestone.', 'nexter-extension' ),
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

		$redirects = get_option( 'wpseo-premium-redirects-base', array() );
		$regex     = 0;
		if ( is_array( $redirects ) ) {
			foreach ( $redirects as $r ) {
				if ( isset( $r['format'] ) && 'regex' === $r['format'] ) {
					++$regex;
				}
			}
		}
		if ( $regex > 0 ) {
			$skips[] = array(
				'key'    => 'redirects(regex)',
				'count'  => $regex,
				'reason' => __( 'Regex redirects are skipped — Nexter redirection matches exact/contains/starts/ends.', 'nexter-extension' ),
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
	 * @param string            $value   Source value.
	 * @param array<string,int>|null $unknown Unknown-token collector (by reference; null when the
	 *                                        caller passes an undeclared variable).
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
		foreach ( array(
			'og_frontpage_title' => 'home_og_title',
			'og_frontpage_desc'  => 'home_og_description',
		) as $src => $dst ) {
			if ( ! empty( $social[ $src ] ) ) {
				$out[ $dst ] = self::convert( $social[ $src ], $unknown );
			}
		}
		if ( ! empty( $social['og_frontpage_image'] ) ) {
			$out['home_og_image'] = esc_url_raw( (string) $social['og_frontpage_image'] );
			$img_id               = attachment_url_to_postid( (string) $social['og_frontpage_image'] );
			if ( $img_id > 0 ) {
				$out['home_og_image_id'] = $img_id;
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

		// Social profiles + default image.
		foreach ( array(
			'facebook_site' => 'facebook_page_url',
			'twitter_site'  => 'twitter_site',
			'instagram_url' => 'instagram_url',
			'linkedin_url'  => 'linkedin_url',
			'youtube_url'   => 'youtube_url',
			'pinterest_url' => 'pinterest_url',
		) as $src => $dst ) {
			if ( ! empty( $social[ $src ] ) ) {
				$out[ $dst ] = (string) $social[ $src ];
			}
		}
		if ( ! empty( $social['og_default_image'] ) ) {
			$out['default_social_image'] = esc_url_raw( (string) $social['og_default_image'] );
		}

		// Webmaster verification.
		foreach ( array(
			'googleverify'    => 'google_verification',
			'msverify'        => 'bing_verification',
			'pinterestverify' => 'pinterest_verification',
		) as $src => $dst ) {
			if ( ! empty( $main[ $src ] ) ) {
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
		$settings = self::build_settings( $unknown );
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
		$selection = Nexter_Content_SEO_Importer::get_unimported_post_ids( self::META_PREFIX, self::SOURCE, $batch );
		$map       = self::postmeta_map();
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;

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
				update_post_meta( $post_id, $dst, $value );
				$wrote = true;
				++$fields;
			}

			$fields += self::import_object_robots( 'post', $post_id );

			// Marker even when nothing mapped (e.g. only focuskw rows): the post was examined,
			// re-runs must not re-scan it forever.
			update_post_meta( $post_id, $marker, time() );
			++$imported;
		}

		return array(
			'imported'          => $imported,
			'fields_written'    => $fields,
			'remaining'         => max( 0, $selection['remaining'] - $imported ),
			'unknown_variables' => $unknown,
		);
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
	 * @return int Fields written.
	 */
	private static function import_object_robots( $object_type, $object_id ) {
		$get    = 'post' === $object_type ? 'get_post_meta' : 'get_term_meta';
		$update = 'post' === $object_type ? 'update_post_meta' : 'update_term_meta';
		$n      = 0;

		$noindex = (int) call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( 1 === $noindex ) {
			call_user_func( $update, $object_id, Nexter_Content_SEO_Robots::META_NOINDEX, '1' );
			++$n;
		} elseif ( 2 === $noindex ) {
			call_user_func( $update, $object_id, Nexter_Content_SEO_Robots::META_NOINDEX, '0' );
			++$n;
		}

		$nofollow = call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-nofollow', true );
		if ( '1' === (string) $nofollow ) {
			call_user_func( $update, $object_id, Nexter_Content_SEO_Robots::META_NOFOLLOW, '1' );
			++$n;
		}

		$adv = call_user_func( $get, $object_id, '_yoast_wpseo_meta-robots-adv', true );
		if ( is_string( $adv ) && '' !== $adv && false !== strpos( $adv, 'noarchive' ) ) {
			call_user_func( $update, $object_id, Nexter_Content_SEO_Robots::META_NOARCHIVE, '1' );
			++$n;
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
	 * @param int $limit Batch size. 0 = count only.
	 * @return array{remaining:int, rows:array<int,array{term_id:int,taxonomy:string,data:array}>}
	 */
	private static function get_unimported_terms( $limit ) {
		$tax_meta  = get_option( 'wpseo_taxonomy_meta', array() );
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$rows      = array();
		$remaining = 0;

		if ( ! is_array( $tax_meta ) ) {
			return array(
				'remaining' => 0,
				'rows'      => array(),
			);
		}

		foreach ( $tax_meta as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $data ) {
				$term_id = (int) $term_id;
				if ( $term_id <= 0 || ! is_array( $data ) ) {
					continue;
				}
				if ( '' !== (string) get_term_meta( $term_id, $marker, true ) ) {
					continue;
				}
				++$remaining;
				if ( $limit > 0 && count( $rows ) < $limit ) {
					$rows[] = array(
						'term_id'  => $term_id,
						'taxonomy' => (string) $taxonomy,
						'data'     => $data,
					);
				}
			}
		}

		return array(
			'remaining' => $remaining,
			'rows'      => $rows,
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
	 * Import one batch of terms.
	 *
	 * @param int $batch Batch size.
	 * @return array<string,mixed>
	 */
	public static function import_terms_batch( $batch ) {
		$selection = self::get_unimported_terms( $batch );
		$map       = self::termmeta_map();
		$marker    = Nexter_Content_SEO_Importer::MARKER_PREFIX . self::SOURCE;
		$unknown   = array();
		$imported  = 0;
		$fields    = 0;

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
				update_term_meta( $term_id, $dst, $value );
				++$fields;
			}

			// Term robots live inside the same row as 'wpseo_noindex' ('noindex'|'index'|'default').
			if ( ! empty( $data['wpseo_noindex'] ) && 'default' !== $data['wpseo_noindex'] ) {
				update_term_meta( $term_id, Nexter_Content_SEO_Robots::META_NOINDEX, 'noindex' === $data['wpseo_noindex'] ? '1' : '0' );
				++$fields;
			}

			update_term_meta( $term_id, $marker, time() );
			++$imported;
		}

		return array(
			'imported'          => $imported,
			'fields_written'    => $fields,
			'remaining'         => max( 0, $selection['remaining'] - $imported ),
			'unknown_variables' => $unknown,
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
	 * @return array<string,mixed>
	 */
	public static function import_redirections() {
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

		$imported = 0;
		$skipped  = 0;

		foreach ( $source as $redirect ) {
			if ( empty( $redirect['origin'] ) ) {
				++$skipped;
				continue;
			}
			// NE matching has no regex mode; importing a regex pattern as an exact URL would
			// create a rule that can never fire (or worse, fires on a literal-match URL).
			if ( isset( $redirect['format'] ) && 'regex' === $redirect['format'] ) {
				++$skipped;
				continue;
			}

			$status = isset( $redirect['type'] ) ? (int) $redirect['type'] : 301;

			$rule = Nexter_Content_SEO_Redirection::sanitize_rule(
				array(
					'enabled'     => true,
					'from_url'    => (string) $redirect['origin'],
					// 410/451 rows have no target; NE's sanitizer keeps them as empty to_url.
					'to_url'      => isset( $redirect['url'] ) ? (string) $redirect['url'] : '',
					'condition'   => 'exact_match',
					'status_code' => $status,
				)
			);

			if ( null === $rule || '' === $rule['from_url'] || isset( $existing_from[ $rule['from_url'] ] ) ) {
				++$skipped;
				continue;
			}

			$existing[]                         = $rule;
			$existing_from[ $rule['from_url'] ] = true;
			++$imported;
		}

		if ( $imported > 0 ) {
			Nexter_Content_SEO_Redirection::save_rules( $existing );
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}
}
