<?php
/**
 * Content SEO – Schema markup (global + per-post).
 * Schema variables, rules, validation, and JSON-LD output.
 *
 * Per-type UI fields and type labels live under `schema-types/` (e.g. fields-organization.php,
 * fields-web-page.php, schema-type-labels.php, organization-subtypes.php, web-page-subtypes.php).
 * Register new types in schema-type-labels.php
 * and add matching fields-{slug}.php; slugs are mapped in get_schema_type_fields_slug().
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Schema
 */
class Nexter_Content_SEO_Schema {

	const OPTION_SCHEMA = 'nexter_content_seo_schema';

	/**
	 * Initialize schema hooks.
	 */
	/** Object-cache group for rendered JSON-LD output. */
	const CACHE_GROUP = 'nexter_content_seo_schema';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_schema' ), 1 );
		// Invalidate the per-object JSON-LD output cache when its source data changes.
		add_action( 'save_post', array( __CLASS__, 'purge_post_schema_cache' ), 10, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'purge_post_schema_cache' ), 10, 1 );
		add_action( 'edited_term', array( __CLASS__, 'purge_term_schema_cache' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'purge_term_schema_cache' ), 10, 3 );
	}

	/**
	 * Hash of all config that affects rendered JSON-LD. Part of every cache key, so any
	 * settings/schema change automatically invalidates previously cached output.
	 *
	 * @return string
	 */
	private static function schema_cache_config_hash() {
		$schema_opt = get_option( self::OPTION_SCHEMA, array() );
		$main_opt   = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$ver        = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '0';
		return md5( (string) wp_json_encode( array( $schema_opt, $main_opt, $ver ) ) );
	}

	/**
	 * Cache key for a single post's rendered JSON-LD. SHARED by the request-time reader and the
	 * save_post purge so the two can never drift — a mismatch would leave stale schema cached after
	 * an edit now that the store is a persistent transient. Folds in the per-post schema meta and
	 * the global config hash.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function schema_post_cache_key( $post_id ) {
		$post_schema = array(
			'rows'     => self::get_post_custom_schema_rows_raw( (int) $post_id ),
			'override' => self::post_uses_custom_page_schema( (int) $post_id ),
		);
		$sig         = 's' . (int) $post_id . '_' . substr( md5( (string) wp_json_encode( $post_schema ) ), 0, 10 );
		return 'nxtschema_' . $sig . '_' . self::schema_cache_config_hash();
	}

	/**
	 * Cache key for a single term's rendered JSON-LD. Shared by the reader and the edited_term
	 * purge (see schema_post_cache_key).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID.
	 * @return string
	 */
	private static function schema_term_cache_key( $taxonomy, $term_id ) {
		return 'nxtschema_t' . $taxonomy . '_' . (int) $term_id . '_' . self::schema_cache_config_hash();
	}

	/**
	 * Cache key for the current request's JSON-LD output, or '' when the request must not be
	 * cached. Only the deterministic anonymous case is cached: a logged-out, query-arg-free,
	 * unpaged singular post or term archive (where output depends solely on the object + config).
	 *
	 * @return string
	 */
	private static function schema_output_cache_key() {
		if ( ! apply_filters( 'nexter_content_seo_schema_cache', true ) ) {
			return '';
		}
		// Marketing/analytics query args (utm_*, fbclid, gclid, …) don't change schema output,
		// so don't let them bust the cache — otherwise ad/UTM traffic rebuilds JSON-LD every hit.
		// Any *other* query arg still disables caching (it may legitimately alter output).
		$ignorable_query_args = array(
			'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
			'gclid',
		'gad_source',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'yclid',
		'dclid',
			'mc_cid',
		'mc_eid',
		'_ga',
		'ref',
		'igshid',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache-key decision, no state change.
		$significant_query = is_array( $_GET ) ? array_diff_key( $_GET, array_flip( $ignorable_query_args ) ) : array();
		if ( is_user_logged_in() || is_paged() || ! empty( $significant_query ) || is_preview() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
			return '';
		}
		if ( is_singular() ) {
			return self::schema_post_cache_key( (int) get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Term ) {
				return self::schema_term_cache_key( $obj->taxonomy, (int) $obj->term_id );
			}
		}
		return '';
	}

	/**
	 * Purge the cached JSON-LD for a single post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function purge_post_schema_cache( $post_id ) {
		delete_transient( self::schema_post_cache_key( (int) $post_id ) );
	}

	/**
	 * Purge the cached JSON-LD for a single term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function purge_term_schema_cache( $term_id, $tt_id = 0, $taxonomy = '' ) {
		unset( $tt_id );
		if ( '' === (string) $taxonomy ) {
			return;
		}
		delete_transient( self::schema_term_cache_key( $taxonomy, (int) $term_id ) );
	}

	/**
	 * Available schema types (labels for add-schema UI and validation).
	 * Data file: `schema-types/schema-type-labels.php`.
	 *
	 * @return array<string, string>
	 */
	public static function get_schema_types() {
		$file = __DIR__ . '/schema-types/schema-type-labels.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$labels = require $file;
		if ( ! is_array( $labels ) ) {
			return array();
		}
		// Product is a manually-configurable type in the builder (independent of WooCommerce).
		// On WooCommerce product pages the auto-generated Product schema still applies; a manual
		// Product row is intended for non-product pages (landing pages, custom catalogs, etc.).
		return $labels;
	}

	/**
	 * Base directory for per-type field files (`fields-{slug}.php`).
	 *
	 * @return string
	 */
	private static function get_schema_types_directory() {
		return __DIR__ . '/schema-types';
	}

	/**
	 * Map schema row type to `fields-{slug}.php` basename.
	 *
	 * @param string $type Type key (e.g. WebSite).
	 * @return string
	 */
	private static function get_schema_type_fields_slug( $type ) {
		static $map = array(
			'WebSite'             => 'website',
			'WebPage'             => 'web-page',
			'Organization'        => 'organization',
			'Person'              => 'person',
			'SearchAction'        => 'search-action',
			'BreadcrumbList'      => 'breadcrumb-list',
			'Article'             => 'article',
			'Product'             => 'product',
			'Service'             => 'service',
			'ClaimReview'         => 'claim-review',
			'Course'              => 'course',
			'Event'               => 'event',
			'FAQPage'             => 'faq-page',
			'HowTo'               => 'how-to',
			'LocalBusiness'       => 'local-business',
			'Recipe'              => 'recipe',
			'SoftwareApplication' => 'software-application',
			'VideoObject'         => 'video-object',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $type ) );
	}

	/**
	 * Load field rows for one schema type from `schema-types/fields-{slug}.php`.
	 *
	 * @param string $type Schema type.
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_schema_type_field_definitions( $type ) {
		$file = self::get_schema_types_directory() . '/fields-' . self::get_schema_type_fields_slug( $type ) . '.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$loaded = require $file;
		return is_array( $loaded ) ? $loaded : array();
	}

	/**
	 * Build a { key => default } field map for a schema type from its field-definition config
	 * (schema-types/fields-{slug}.php) — the single source of truth for seeded defaults. Skips
	 * `@type` (set from the row's `type`) and any empty/blank default so seeded rows carry only
	 * meaningful, template-token defaults. Keeps DRY with the editor's field defaults.
	 *
	 * @param string $type Schema type (e.g. 'Article').
	 * @return array<string, mixed>
	 */
	private static function default_fields_from_config( $type ) {
		$defs   = self::load_schema_type_field_definitions( $type );
		$fields = array();
		foreach ( $defs as $def ) {
			if ( ! is_array( $def ) || empty( $def['key'] ) || ! array_key_exists( 'default', $def ) ) {
				continue;
			}
			$key    = (string) $def['key'];
			$subkey = isset( $def['subkey'] ) ? (string) $def['subkey'] : '';
			// The TOP-LEVEL @type comes from the row's `type` (builder prepends it). Nested @type
			// subkeys (e.g. offers.@type, image.@type, brand.@type) are legitimate and kept.
			if ( '@type' === $key && '' === $subkey ) {
				continue;
			}
			$default = $def['default'];
			// Drop empty scalars / empty arrays — nothing useful to seed.
			if ( '' === $default || null === $default || array() === $default ) {
				continue;
			}
			if ( '' !== $subkey ) {
				// Nested object field (brand/image/offers/aggregateRating → { subkey: default }).
				if ( ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
					$fields[ $key ] = array();
				}
				$fields[ $key ][ $subkey ] = $default;
			} else {
				$fields[ $key ] = $default;
			}
		}
		return $fields;
	}

	/**
	 * Merge all per-type field files (one file per entry in get_schema_types()).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function build_schema_field_definitions_merged() {
		$defs = array();
		foreach ( array_keys( self::get_schema_types() ) as $type ) {
			$fields = self::load_schema_type_field_definitions( $type );
			if ( ! empty( $fields ) ) {
				$defs[ $type ] = $fields;
			}
		}
		return $defs;
	}

	/**
	 * Grouped JSON-LD @type values for Organization rows.
	 * Data: `schema-types/organization-subtypes.php`.
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	public static function get_organization_schema_type_options() {
		$file = self::get_schema_types_directory() . '/organization-subtypes.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		if ( function_exists( 'nexter_content_seo_schema_organization_subtype_groups' ) ) {
			return nexter_content_seo_schema_organization_subtype_groups();
		}
		return array();
	}

	/**
	 * Allowed Organization JSON-LD @type strings (flattened from grouped options).
	 *
	 * @return string[]
	 */
	private static function get_organization_schema_type_allowed_values() {
		$groups = self::get_organization_schema_type_options();
		$out    = array();
		foreach ( $groups as $group ) {
			if ( ! empty( $group['options'] ) && is_array( $group['options'] ) ) {
				$out = array_merge( $out, array_keys( $group['options'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize Organization row fields: JSON-LD @type must be an allowed subtype.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_organization_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$allowed = self::get_organization_schema_type_allowed_values();
		$v       = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $v || ! in_array( $v, $allowed, true ) ) {
			$v = 'Organization';
		}
		$fields['@type'] = $v;
		return $fields;
	}

	/**
	 * WebPage JSON-LD @type option groups (CollectionPage, AboutPage, …).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	public static function get_web_page_schema_type_options() {
		$file = self::get_schema_types_directory() . '/web-page-subtypes.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		if ( function_exists( 'nexter_content_seo_schema_webpage_subtype_groups' ) ) {
			return nexter_content_seo_schema_webpage_subtype_groups();
		}
		return array();
	}

	/**
	 * Allowed WebPage JSON-LD @type strings.
	 *
	 * @return string[]
	 */
	private static function get_web_page_schema_type_allowed_values() {
		$groups = self::get_web_page_schema_type_options();
		$out    = array();
		foreach ( $groups as $group ) {
			if ( ! empty( $group['options'] ) && is_array( $group['options'] ) ) {
				$out = array_merge( $out, array_keys( $group['options'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize WebPage row fields: JSON-LD @type must be an allowed subtype.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_web_page_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$allowed = self::get_web_page_schema_type_allowed_values();
		$v       = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $v || ! in_array( $v, $allowed, true ) ) {
			$v = 'WebPage';
		}
		$fields['@type'] = $v;
		return $fields;
	}

	/**
	 * Article JSON-LD @type option groups (Article, NewsArticle, BlogPosting).
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	public static function get_article_schema_type_options() {
		$file = self::get_schema_types_directory() . '/article-subtypes.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		if ( function_exists( 'nexter_content_seo_schema_article_subtype_groups' ) ) {
			return nexter_content_seo_schema_article_subtype_groups();
		}
		return array();
	}

	/**
	 * Allowed Article row @type strings.
	 *
	 * @return string[]
	 */
	private static function get_article_schema_type_allowed_values() {
		$groups = self::get_article_schema_type_options();
		$out    = array();
		foreach ( $groups as $group ) {
			if ( ! empty( $group['options'] ) && is_array( $group['options'] ) ) {
				$out = array_merge( $out, array_keys( $group['options'] ) );
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( empty( $out ) ) {
			return array( 'Article', 'NewsArticle', 'BlogPosting' );
		}
		return $out;
	}

	/**
	 * Sanitize Article row fields: JSON-LD @type must be an allowed subtype.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_article_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$allowed = self::get_article_schema_type_allowed_values();
		$v       = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $v || ! in_array( $v, $allowed, true ) ) {
			$v = 'Article';
		}
		$fields['@type'] = $v;
		return $fields;
	}

	/**
	 * Sanitize BreadcrumbList row fields.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_breadcrumb_list_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'BreadcrumbList' !== $t ) {
			$fields['@type'] = 'BreadcrumbList';
		}
		return $fields;
	}

	/**
	 * Sanitize Product row fields: JSON-LD @type must be Product.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_product_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$fields['@type'] = 'Product';
		return $fields;
	}

	/**
	 * Sanitize ClaimReview row fields: JSON-LD @type must be ClaimReview.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_claim_review_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'ClaimReview' !== $t ) {
			$fields['@type'] = 'ClaimReview';
		}
		return $fields;
	}

	/**
	 * Sanitize Course row fields: JSON-LD @type must be Course.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_course_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'Course' !== $t ) {
			$fields['@type'] = 'Course';
		}
		return $fields;
	}

	/**
	 * Sanitize Event row fields: JSON-LD @type must be Event.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_event_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'Event' !== $t ) {
			$fields['@type'] = 'Event';
		}
		return $fields;
	}

	/**
	 * Sanitize FAQPage row fields: JSON-LD @type must be FAQPage.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_faq_page_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'FAQPage' !== $t ) {
			$fields['@type'] = 'FAQPage';
		}
		return $fields;
	}

	/**
	 * Sanitize HowTo row fields: JSON-LD @type must be HowTo.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_how_to_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$t = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $t || 'HowTo' !== $t ) {
			$fields['@type'] = 'HowTo';
		}
		return $fields;
	}

	/**
	 * Sanitize Recipe row fields: JSON-LD @type must be Recipe.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_recipe_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$fields['@type'] = 'Recipe';
		return $fields;
	}

	/**
	 * Sanitize SoftwareApplication row fields: @type and additional type list.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_software_application_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$fields['@type'] = 'SoftwareApplication';
		$allow           = array( 'MobileApplication', 'WebApplication', 'VideoGame' );
		if ( isset( $fields['_software_additional_types'] ) && is_array( $fields['_software_additional_types'] ) ) {
			$clean = array();
			foreach ( $fields['_software_additional_types'] as $x ) {
				$x = trim( (string) $x );
				if ( in_array( $x, $allow, true ) ) {
					$clean[] = $x;
				}
			}
			$fields['_software_additional_types'] = array_values( array_unique( $clean ) );
		}
		return $fields;
	}

	/**
	 * Sanitize VideoObject row fields: JSON-LD @type must be VideoObject.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_video_object_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$fields['@type'] = 'VideoObject';
		return $fields;
	}

	/**
	 * LocalBusiness JSON-LD @type option groups.
	 *
	 * @return array<string, array{label: string, options: array<string, string>}>
	 */
	public static function get_local_business_schema_type_options() {
		$file = self::get_schema_types_directory() . '/local-business-subtypes.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		if ( function_exists( 'nexter_content_seo_schema_local_business_subtype_groups' ) ) {
			return nexter_content_seo_schema_local_business_subtype_groups();
		}
		return array();
	}

	/**
	 * Allowed LocalBusiness @type strings.
	 *
	 * @return string[]
	 */
	private static function get_local_business_schema_type_allowed_values() {
		$groups = self::get_local_business_schema_type_options();
		$out    = array();
		foreach ( $groups as $group ) {
			if ( ! empty( $group['options'] ) && is_array( $group['options'] ) ) {
				$out = array_merge( $out, array_keys( $group['options'] ) );
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( empty( $out ) ) {
			return array( 'LocalBusiness' );
		}
		return $out;
	}

	/**
	 * Sanitize LocalBusiness row fields: JSON-LD @type must be an allowed subtype.
	 *
	 * @param array $fields Field map after recursive sanitize.
	 * @return array
	 */
	private static function sanitize_local_business_schema_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$allowed = self::get_local_business_schema_type_allowed_values();
		$v       = isset( $fields['@type'] ) ? sanitize_text_field( (string) $fields['@type'] ) : '';
		if ( '' === $v || ! in_array( $v, $allowed, true ) ) {
			$v = 'LocalBusiness';
		}
		$fields['@type'] = $v;
		return $fields;
	}

	/**
	 * Form field definitions per @type (keys match JSON-LD property names).
	 * Each type lives in `schema-types/fields-{slug}.php` (see get_schema_type_fields_slug()).
	 * `subkey` is used for nested objects (e.g. publisher.@id).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_schema_field_definitions() {
		return apply_filters(
			'nexter_content_seo_schema_field_definitions',
			self::build_schema_field_definitions_merged()
		);
	}

	/**
	 * Default field values for a schema @type (for new entries in the UI).
	 *
	 * @param string $type Schema type.
	 * @return array
	 */
	public static function get_default_fields_for_type( $type ) {
		$defs = self::get_schema_field_definitions();
		if ( empty( $defs[ $type ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $defs[ $type ] as $field ) {
			$key    = isset( $field['key'] ) ? $field['key'] : '';
			$sub    = isset( $field['subkey'] ) ? $field['subkey'] : '';
			$defval = isset( $field['default'] ) ? $field['default'] : '';
			$input  = isset( $field['input'] ) ? $field['input'] : '';
			if ( ! $key ) {
				continue;
			}
			if ( 'person_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array(
					array(
						'@type' => 'Person',
						'name'  => '',
					),
				);
				continue;
			}
			if ( 'string_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( 'has_part_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( 'breadcrumb_items' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( 'key_value_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( 'course_instance_list' === $input || 'course_offer_list' === $input || 'course_part_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( 'faq_main_entity_list' === $input ) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if (
				'how_to_step_list' === $input ||
				'how_to_supply_list' === $input ||
				'how_to_tool_list' === $input
			) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if (
				'local_business_opening_hours_list' === $input ||
				'local_business_brand_list' === $input ||
				'local_business_contact_point_list' === $input ||
				'local_business_place_list' === $input ||
				'local_business_founding_place_list' === $input ||
				'local_business_employee_list' === $input
			) {
				$out[ $key ] = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : array();
				continue;
			}
			if ( $sub ) {
				if ( ! isset( $out[ $key ] ) || ! is_array( $out[ $key ] ) ) {
					$out[ $key ] = array();
				}
				$out[ $key ][ $sub ] = $defval;
			} else {
				$out[ $key ] = $defval;
			}
		}
		return $out;
	}

	/**
	 * Sanitize a schema field key (allows @id, dots).
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private static function sanitize_schema_field_key( $k ) {
		$k = (string) $k;
		if ( preg_match( '/^[a-zA-Z0-9_@.\-]+$/', $k ) ) {
			return $k;
		}
		return '';
	}

	/**
	 * Sanitize fields tree (string leaves only; nested arrays preserved).
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function sanitize_fields_recursive( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $k => $v ) {
			$key = self::sanitize_schema_field_key( $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$nested = self::sanitize_fields_recursive( $v );
				if ( ! empty( $nested ) ) {
					$out[ $key ] = $nested;
				}
			} elseif ( is_string( $v ) || is_numeric( $v ) ) {
				// Schema values are plain-text JSON-LD, not HTML. Strip all tags (and any
				// <script>/<style> bodies) rather than wp_kses_post, so markup like
				// "</script>..." can never land in a schema value. wp_strip_all_tags keeps
				// line breaks, preserving multi-line descriptions. Output is also HEX-encoded.
				$out[ $key ] = is_string( $v ) ? wp_strip_all_tags( $v ) : (string) $v;
			}
		}
		return $out;
	}

	/**
	 * Sanitize show / not_show_on blocks.
	 *
	 * @param array $block Block with rules array.
	 * @return array
	 */
	private static function sanitize_rules_block( $block ) {
		$rules = isset( $block['rules'] ) && is_array( $block['rules'] ) ? $block['rules'] : array();
		$clean = array();
		foreach ( $rules as $r ) {
			$clean[] = sanitize_text_field( (string) $r );
		}
		return array( 'rules' => array_values( array_unique( array_filter( $clean ) ) ) );
	}

	/**
	 * Sanitize payload from REST (site_wide + page_specific lists).
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	public static function sanitize_schema_payload( $data ) {
		if ( ! is_array( $data ) ) {
			return array(
				'site_wide'     => array(),
				'page_specific' => array(),
			);
		}
		$allowed_types = array_keys( self::get_schema_types() );
		$out           = array(
			'site_wide'     => array(),
			'page_specific' => array(),
		);
		foreach ( array( 'site_wide', 'page_specific' ) as $bucket ) {
			$rows = isset( $data[ $bucket ] ) && is_array( $data[ $bucket ] ) ? $data[ $bucket ] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$type = isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '';
				if ( empty( $type ) || ! in_array( $type, $allowed_types, true ) ) {
					continue;
				}
				$id = isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '';
				if ( '' === $id ) {
					$id = wp_generate_password( 12, false, false );
				}
				$title  = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $type;
				$fields = self::sanitize_fields_recursive( isset( $row['fields'] ) ? $row['fields'] : array() );
				if ( 'Organization' === $type ) {
					$fields = self::sanitize_organization_schema_fields( $fields );
				}
				if ( 'WebPage' === $type ) {
					$fields = self::sanitize_web_page_schema_fields( $fields );
				}
				if ( 'Article' === $type ) {
					$fields = self::sanitize_article_schema_fields( $fields );
				}
				if ( 'BreadcrumbList' === $type ) {
					$fields = self::sanitize_breadcrumb_list_schema_fields( $fields );
				}
				if ( 'Product' === $type ) {
					$fields = self::sanitize_product_schema_fields( $fields );
				}
				if ( 'ClaimReview' === $type ) {
					$fields = self::sanitize_claim_review_schema_fields( $fields );
				}
				if ( 'Course' === $type ) {
					$fields = self::sanitize_course_schema_fields( $fields );
				}
				if ( 'Event' === $type ) {
					$fields = self::sanitize_event_schema_fields( $fields );
				}
				if ( 'FAQPage' === $type ) {
					$fields = self::sanitize_faq_page_schema_fields( $fields );
				}
				if ( 'HowTo' === $type ) {
					$fields = self::sanitize_how_to_schema_fields( $fields );
				}
				if ( 'Recipe' === $type ) {
					$fields = self::sanitize_recipe_schema_fields( $fields );
				}
				if ( 'SoftwareApplication' === $type ) {
					$fields = self::sanitize_software_application_schema_fields( $fields );
				}
				if ( 'VideoObject' === $type ) {
					$fields = self::sanitize_video_object_schema_fields( $fields );
				}
				if ( 'LocalBusiness' === $type ) {
					$fields = self::sanitize_local_business_schema_fields( $fields );
				}
				$out[ $bucket ][] = array(
					'id'          => $id,
					'type'        => $type,
					'title'       => $title,
					'enabled'     => ! empty( $row['enabled'] ),
					'fields'      => $fields,
					'show_on'     => self::sanitize_rules_block( isset( $row['show_on'] ) ? $row['show_on'] : array() ),
					'not_show_on' => self::sanitize_rules_block( isset( $row['not_show_on'] ) ? $row['not_show_on'] : array() ),
				);
			}
		}
		return $out;
	}

	/**
	 * Get the default schema lists to populate on fresh install.
	 *
	 * @return array
	 */
	public static function get_default_schema_lists() {
		return array(
			'site_wide'     => array(
				array(
					'id'          => 'default_website',
					'type'        => 'WebSite',
					'title'       => 'WebSite',
					'enabled'     => true,
					'fields'      => array(
						'@id'  => '%site.url%#website',
						'name' => '%site.title%',
						'url'  => '%site.url%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-global' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_webpage',
					'type'        => 'WebPage',
					'title'       => 'WebPage',
					'enabled'     => true,
					'fields'      => array(
						// Give the WebPage node a stable @id (home/#webpage) so Article's
						// isPartOf / mainEntityOfPage resolve to it instead of matching no node and
						// being pruned. Use %site.url%#webpage, NOT %schemas.webpage%: the %schemas.*
						// map is not merged into per-node field replacement, so %schemas.webpage%
						// resolved to empty and the @id was dropped. %site.url% IS in the node map
						// (same working pattern as Organization's %site.url%#organization).
						'@id'         => '%site.url%#webpage',
						'name'        => '%post.title%',
						'url'         => '%post.url%',
						'description' => '%post.excerpt%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-global' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_organization',
					'type'        => 'Organization',
					'title'       => 'Organization',
					'enabled'     => true,
					'fields'      => array(
						'@id'  => '%site.url%#organization',
						'name' => '%site.title%',
						'url'  => '%site.url%',
						'logo' => '%website_details.website_logo%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-global' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_searchaction',
					'type'        => 'SearchAction',
					'title'       => 'SearchAction',
					'enabled'     => true,
					'fields'      => array(
						'target'      => '%site.search_url%',
						'query-input' => 'required name=search_term_string',
					),
					'show_on'     => array(
						'rules' => array( 'basic-global' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_person',
					'type'        => 'Person',
					'title'       => 'Person',
					'enabled'     => true,
					'fields'      => array(
						'@id'  => '%schemas.person%',
						'name' => '%author.display_name%',
						'url'  => '%author.posts_url%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-global' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
			),
			'page_specific' => array(
				array(
					'id'          => 'default_breadcrumblist',
					'type'        => 'BreadcrumbList',
					'title'       => 'BreadcrumbList',
					'enabled'     => true,
					'fields'      => array(),
					'show_on'     => array(
						'rules' => array( 'basic-singulars' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_article',
					'type'        => 'Article',
					'title'       => 'Article',
					'enabled'     => true,
					// Derive the seeded Article fields from the single source of truth
					// (schema-types/fields-article.php) so the default is complete — headline,
					// description, image, author→Person, publisher→Organization, datePublished,
					// dateModified, @id, etc. — instead of a hand-written 3-field subset that made
					// every post emit an incomplete Article (ineligible for rich results).
					'fields'      => self::default_fields_from_config( 'Article' ),
					'show_on'     => array(
						'rules' => array( 'basic-singulars' ),
						'posts' => array(),
						'tax'   => array(),
					),
					// Never emit Article on WooCommerce products — they get the Product rule below
					// (or WooCommerce's own Product structured data). `product|all` only matches when
					// the product post type exists, so this is a no-op on non-Woo sites.
					'not_show_on' => array(
				'rules' => array( 'product|all' ),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_product',
					'type'        => 'Product',
					'title'       => 'Product',
					'enabled'     => true,
					// Complete Product (name/offers/price/availability/brand/image/rating) derived
					// from schema-types/fields-product.php. Scoped to products only. On a normal Woo
					// store this defers to WooCommerce's native Product output (see the coexistence
					// guard in print_schema); it fills in only when Woo structured data is disabled.
					'fields'      => self::default_fields_from_config( 'Product' ),
					'show_on'     => array(
						'rules' => array( 'product|all' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				// Archives (category/tag/custom-taxonomy) previously emitted only the site-wide
				// Organization + Person nodes. Seed a CollectionPage describing the archive and a
				// BreadcrumbList (Home → term hierarchy) so archive pages carry page-level schema.
				array(
					'id'          => 'default_archive_collectionpage',
					'type'        => 'WebPage',
					'title'       => 'CollectionPage',
					'enabled'     => true,
					'fields'      => array(
						'@type'       => 'CollectionPage',
						'name'        => '%term.name%',
						'url'         => '%term.url%',
						'description' => '%term.description%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-archives' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
				array(
					'id'          => 'default_archive_breadcrumblist',
					'type'        => 'BreadcrumbList',
					'title'       => 'BreadcrumbList (Archives)',
					'enabled'     => true,
					// Explicit fallback token (not relying on field-default merge) so the archive
					// breadcrumb resolves from %current.breadcrumbs% built for the current term.
					'fields'      => array(
						'breadcrumbJsonFallback' => '%current.breadcrumbs%',
					),
					'show_on'     => array(
						'rules' => array( 'basic-archives' ),
						'posts' => array(),
						'tax'   => array(),
					),
					'not_show_on' => array(
				'rules' => array(),
				'posts' => array(),
				'tax'   => array()
				),
				),
			),
		);
	}

	/**
	 * Normalize stored option into site_wide / page_specific lists (migrate legacy).
	 *
	 * @param array|false $option Raw option.
	 * @return array{site_wide: array, page_specific: array}
	 */
	public static function normalize_schema_lists( $option ) {
		if ( false === get_option( self::OPTION_SCHEMA, false ) && ( empty( $option ) || ! is_array( $option ) ) ) {
			return self::get_default_schema_lists();
		}
		
		if ( ! is_array( $option ) ) {
			return array(
				'site_wide'     => array(),
				'page_specific' => array(),
			);
		}
		if ( isset( $option['site_wide'] ) || isset( $option['page_specific'] ) ) {
			return array(
				'site_wide'     => array_values( (array) ( $option['site_wide'] ?? array() ) ),
				'page_specific' => array_values( (array) ( $option['page_specific'] ?? array() ) ),
			);
		}
		$legacy = isset( $option['schemas'] ) ? $option['schemas'] : $option;
		if ( ! is_array( $legacy ) ) {
			return array(
				'site_wide'     => array(),
				'page_specific' => array(),
			);
		}
		$site = array();
		$page = array();
		foreach ( $legacy as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = $row;
			if ( empty( $item['id'] ) ) {
				$item['id'] = wp_generate_password( 12, false, false );
			}
			if ( empty( $item['title'] ) && ! empty( $item['type'] ) ) {
				$item['title'] = $item['type'];
			}
			$rules   = isset( $item['show_on']['rules'] ) ? (array) $item['show_on']['rules'] : array();
			$is_wide = in_array( 'basic-global', $rules, true );
			if ( $is_wide ) {
				$site[] = $item;
			} else {
				$page[] = $item;
			}
		}
		return array(
			'site_wide'     => $site,
			'page_specific' => $page,
		);
	}

	/**
	 * All schema rows from option (merged), for JSON-LD rendering.
	 *
	 * @return array
	 */
	private static function get_merged_schema_rows() {
		$option = get_option( self::OPTION_SCHEMA, array() );
		$lists  = self::normalize_schema_lists( $option );
		return array_merge( $lists['site_wide'], $lists['page_specific'] );
	}

	/**
	 * Get schema variables (placeholders for dynamic replacement).
	 * Template variable map for schema replacement.
	 *
	 * @return array Map of variable => label.
	 */
	public static function get_schema_variables() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$tax_vars   = array();
		foreach ( $taxonomies as $tax ) {
			$key                                   = sanitize_key( $tax->name );
			$tax_vars[ '%post.tax.' . $key . '%' ] = $tax->labels->name;
		}

		$all = array_merge(
			$tax_vars,
			self::get_post_variables(),
			self::get_term_variables(),
			self::get_author_variables(),
			self::get_user_variables(),
			self::get_site_variables(),
			self::get_current_page_variables(),
			self::get_schema_link_variables(),
			self::get_website_details_variables()
		);

		asort( $all );
		return apply_filters( 'nexter_content_seo_schema_variables', $all );
	}

	/**
	 * Post variables.
	 *
	 * @return array
	 */
	private static function get_post_variables() {
		$base = array(
			'%post.title%'           => __( 'Post Title', 'nexter-extension' ),
			'%post.ID%'              => __( 'Post ID', 'nexter-extension' ),
			'%post.excerpt%'         => __( 'Post Excerpt', 'nexter-extension' ),
			'%post.content%'         => __( 'Post Content', 'nexter-extension' ),
			'%post.url%'             => __( 'Post URL', 'nexter-extension' ),
			'%post.slug%'            => __( 'Post Slug', 'nexter-extension' ),
			'%post.date%'            => __( 'Post Date', 'nexter-extension' ),
			'%post.modified_date%'   => __( 'Post Modified Date', 'nexter-extension' ),
			'%post.date_c%'          => __( 'Post Date (ISO 8601)', 'nexter-extension' ),
			'%post.modified_date_c%' => __( 'Post Modified Date (ISO 8601)', 'nexter-extension' ),
			'%post.thumbnail%'       => __( 'Post Thumbnail', 'nexter-extension' ),
			'%post.comment_count%'   => __( 'Post Comment Count', 'nexter-extension' ),
			'%post.word_count%'      => __( 'Post Word Count', 'nexter-extension' ),
			'%post.tags%'            => __( 'Post Tags', 'nexter-extension' ),
			'%post.categories%'      => __( 'Post Categories', 'nexter-extension' ),
			'%current.breadcrumbs%'  => __( 'Breadcrumb ListItems (JSON)', 'nexter-extension' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$base = array_merge( $base, self::get_woocommerce_product_variable_labels() );
		}
		return $base;
	}

	/**
	 * Schema variable labels for WooCommerce product data (single product context).
	 *
	 * @return array<string, string>
	 */
	private static function get_woocommerce_product_variable_labels() {
		return array(
			'%product.sku%'               => __( 'Product SKU (WooCommerce)', 'nexter-extension' ),
			'%product.price%'             => __( 'Product price (WooCommerce)', 'nexter-extension' ),
			'%product.currency%'          => __( 'Store currency code (WooCommerce)', 'nexter-extension' ),
			'%product.stock%'             => __( 'Availability schema.org URL (WooCommerce)', 'nexter-extension' ),
			'%product.image%'             => __( 'Product image URL (WooCommerce)', 'nexter-extension' ),
			'%product.image_width%'       => __( 'Product image width (WooCommerce)', 'nexter-extension' ),
			'%product.image_height%'      => __( 'Product image height (WooCommerce)', 'nexter-extension' ),
			'%product.low_price%'         => __( 'Low price / min variation (WooCommerce)', 'nexter-extension' ),
			'%product.high_price%'        => __( 'High price / max variation (WooCommerce)', 'nexter-extension' ),
			'%product.offer_count%'       => __( 'Variation count (WooCommerce)', 'nexter-extension' ),
			'%product.rating%'            => __( 'Average rating (WooCommerce)', 'nexter-extension' ),
			'%product.rating_value%'      => __( 'Average rating value (WooCommerce)', 'nexter-extension' ),
			'%product.review_count%'      => __( 'Rating/review count (WooCommerce)', 'nexter-extension' ),
			'%product.short_description%' => __( 'Product short description (WooCommerce)', 'nexter-extension' ),
		);
	}

	/**
	 * Replacement map for WooCommerce product variables (single product only).
	 *
	 * @param WP_Post $post Product post.
	 * @return array<string, string>
	 */
	private static function get_woocommerce_product_replacements( $post ) {
		$out = array();
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $out;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$fmt      = static function ( $raw ) use ( $decimals ) {
			if ( '' === $raw || null === $raw ) {
				return '';
			}
			return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $raw, $decimals ) : (string) $raw;
		};

		$out['%product.sku%'] = (string) $product->get_sku();

		$price = $product->get_price();
		if ( '' === $price && $product->is_type( 'variable' ) ) {
			$price = $product->get_variation_price( 'min', false );
		}
		$out['%product.price%'] = $fmt( $price );

		$out['%product.currency%'] = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		$status                 = method_exists( $product, 'get_stock_status' ) ? $product->get_stock_status() : 'instock';
		$stock_map              = array(
			'instock'     => 'https://schema.org/InStock',
			'outofstock'  => 'https://schema.org/OutOfStock',
			'onbackorder' => 'https://schema.org/BackOrder',
		);
		$out['%product.stock%'] = isset( $stock_map[ $status ] ) ? $stock_map[ $status ] : 'https://schema.org/InStock';

		$img_id = $product->get_image_id();
		if ( ! $img_id ) {
			$img_id = (int) get_post_thumbnail_id( $post->ID );
		}
		$img_url                = $img_id ? wp_get_attachment_url( $img_id ) : '';
		$out['%product.image%'] = $img_url ? (string) $img_url : '';

		$w = '';
		$h = '';
		if ( $img_id ) {
			$meta = wp_get_attachment_metadata( $img_id );
			if ( is_array( $meta ) ) {
				$w = isset( $meta['width'] ) ? (string) (int) $meta['width'] : '';
				$h = isset( $meta['height'] ) ? (string) (int) $meta['height'] : '';
			}
		}
		$out['%product.image_width%']  = $w;
		$out['%product.image_height%'] = $h;

		if ( $product->is_type( 'variable' ) ) {
			$out['%product.low_price%']   = $fmt( $product->get_variation_price( 'min', false ) );
			$out['%product.high_price%']  = $fmt( $product->get_variation_price( 'max', false ) );
			$children                     = $product->get_children();
			$out['%product.offer_count%'] = (string) max( 1, is_array( $children ) ? count( $children ) : 1 );
		} else {
			$out['%product.low_price%']   = $out['%product.price%'];
			$out['%product.high_price%']  = $out['%product.price%'];
			$out['%product.offer_count%'] = '1';
		}

		$avg                           = method_exists( $product, 'get_average_rating' ) ? (float) $product->get_average_rating() : 0.0;
		$rc                            = method_exists( $product, 'get_rating_count' ) ? (int) $product->get_rating_count() : 0;
		$avg_s                         = ( $avg > 0 ) ? (string) $avg : '';
		$out['%product.rating%']       = $avg_s;
		$out['%product.rating_value%'] = $avg_s;
		$out['%product.review_count%'] = $rc > 0 ? (string) $rc : '';

		$short                              = method_exists( $product, 'get_short_description' ) ? $product->get_short_description() : '';
		$out['%product.short_description%'] = $short ? wp_strip_all_tags( $short ) : '';

		return $out;
	}

	/**
	 * Term variables (archive context).
	 *
	 * @return array
	 */
	private static function get_term_variables() {
		return array(
			'%term.ID%'          => __( 'Term ID', 'nexter-extension' ),
			'%term.name%'        => __( 'Term Name', 'nexter-extension' ),
			'%term.slug%'        => __( 'Term Slug', 'nexter-extension' ),
			'%term.taxonomy%'    => __( 'Term Taxonomy', 'nexter-extension' ),
			'%term.description%' => __( 'Term Description', 'nexter-extension' ),
			'%term.url%'         => __( 'Term URL', 'nexter-extension' ),
		);
	}

	/**
	 * Author variables.
	 *
	 * @return array
	 */
	private static function get_author_variables() {
		return array(
			'%author.ID%'           => __( 'Author ID', 'nexter-extension' ),
			'%author.first_name%'   => __( 'Author First Name', 'nexter-extension' ),
			'%author.last_name%'    => __( 'Author Last Name', 'nexter-extension' ),
			'%author.display_name%' => __( 'Author Display Name', 'nexter-extension' ),
			'%author.username%'     => __( 'Author Username', 'nexter-extension' ),
			'%author.nickname%'     => __( 'Author Nickname', 'nexter-extension' ),
			'%author.website_url%'  => __( 'Author Website URL', 'nexter-extension' ),
			'%author.nicename%'     => __( 'Author Nicename', 'nexter-extension' ),
			'%author.description%'  => __( 'Author Description', 'nexter-extension' ),
			'%author.posts_url%'    => __( 'Author Posts URL', 'nexter-extension' ),
			'%author.avatar%'       => __( 'Author Avatar', 'nexter-extension' ),
		);
	}

	/**
	 * User variables.
	 *
	 * @return array
	 */
	private static function get_user_variables() {
		// Current-logged-in-user (%user.*%) tokens are intentionally NOT offered: schema is
		// rendered into cacheable server output, so viewer-specific values would leak the
		// first visitor's PII into the page cache. Use %author.*% (post author) instead.
		return array();
	}

	/**
	 * Site variables.
	 *
	 * @return array
	 */
	private static function get_site_variables() {
		return array(
			'%site.title%'                       => __( 'Site Title', 'nexter-extension' ),
			'%site.description%'                 => __( 'Site Description', 'nexter-extension' ),
			'%site.url%'                         => __( 'Site URL', 'nexter-extension' ),
			'%site.search_url%'                  => __( 'Site search URL (SearchAction target template)', 'nexter-extension' ),
			'%site.language%'                    => __( 'Site Language', 'nexter-extension' ),
			'%site.icon%'                        => __( 'Site Icon', 'nexter-extension' ),
			'%schema.primary_user_display_name%' => __( 'Primary user (ID 1) display name', 'nexter-extension' ),
		);
	}

	/**
	 * Current page variables.
	 *
	 * @return array
	 */
	private static function get_current_page_variables() {
		return array(
			'%current.title%' => __( 'Current Page Title', 'nexter-extension' ),
			'%current.url%'   => __( 'Current Page URL', 'nexter-extension' ),
		);
	}

	/**
	 * Website details variables (organization/business from options).
	 *
	 * @return array
	 */
	private static function get_website_details_variables() {
		return array(
			'%website_details.website_name%'         => __( 'Website Name', 'nexter-extension' ),
			'%website_details.business_description%' => __( 'Business Description', 'nexter-extension' ),
			'%website_details.website_owner_name%'   => __( 'Website Owner Name', 'nexter-extension' ),
			'%website_details.organization_type%'    => __( 'Organization Type', 'nexter-extension' ),
			'%website_details.website_owner_phone%'  => __( 'Website Owner Phone', 'nexter-extension' ),
			'%website_details.website_logo%'         => __( 'Website Logo', 'nexter-extension' ),
		);
	}

	/**
	 * Schema link variables (reference other schema types).
	 *
	 * @return array
	 */
	private static function get_schema_link_variables() {
		$types = self::get_schema_types();
		$vars  = array(
			'%schema.item.id%' => __( 'Current schema entry ID (replaced when JSON-LD is output)', 'nexter-extension' ),
		);
		foreach ( $types as $type => $label ) {
			$key                              = strtolower( $type );
			$vars[ '%schemas.' . $key . '%' ] = sprintf(
				/* translators: %s: schema type name */
				__( '%s Schema', 'nexter-extension' ),
				$type
			);
		}
		return $vars;
	}

	/**
	 * Replace variables in a string with actual values.
	 *
	 * @param string $text    Text containing variables.
	 * @param int    $post_id Optional post ID for post context.
	 * @return string
	 */
	public static function replace_variables( $text, $post_id = 0 ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return $text;
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post && is_singular() ) {
			$post = get_post();
		}
		$replacements = self::get_replacements( $post );
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
	}

	/**
	 * Append Shop + product category trail for WooCommerce products (before page ancestors / current).
	 *
	 * @param array   $items Breadcrumb ListItem list.
	 * @param int     $pos   Next position index (by ref).
	 * @param WP_Post $post  Product post.
	 */
	private static function append_woocommerce_product_breadcrumb_items( array &$items, &$pos, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}
		$shop_id = (int) wc_get_page_id( 'shop' );
		if ( $shop_id > 0 ) {
			$shop_url = get_permalink( $shop_id );
			if ( $shop_url ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => get_the_title( $shop_id ),
					'item'     => $shop_url,
				);
			}
		}
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$terms = wp_get_post_terms( $post->ID, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		$best       = null;
		$best_depth = -1;
		foreach ( $terms as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}
			$depth = count( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) );
			if ( $depth > $best_depth ) {
				$best_depth = $depth;
				$best       = $t;
			}
		}
		if ( ! $best instanceof WP_Term ) {
			return;
		}
		$chain   = array_reverse( array_map( 'intval', get_ancestors( $best->term_id, 'product_cat', 'taxonomy' ) ) );
		$chain[] = (int) $best->term_id;
		foreach ( $chain as $tid ) {
			$t = get_term( $tid, 'product_cat' );
			if ( ! $t || is_wp_error( $t ) ) {
				continue;
			}
			$link = get_term_link( $t );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $t->name,
				'item'     => $link,
			);
		}
	}

	/**
	 * JSON array string of ListItem objects for BreadcrumbList (home → Shop/categories/ancestors → current).
	 *
	 * @param WP_Post $post Post.
	 * @return string JSON.
	 */
	private static function build_current_term_breadcrumbs_json( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return '[]';
		}
		$items   = array();
		$pos     = 1;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => get_bloginfo( 'name' ),
			'item'     => home_url( '/' ),
		);
		// Ancestor terms for hierarchical taxonomies (e.g. category), root-first.
		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$ancestors = array_reverse( array_map( 'intval', (array) get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) );
			foreach ( $ancestors as $aid ) {
				$at = get_term( $aid, $term->taxonomy );
				if ( ! $at instanceof WP_Term ) {
					continue;
				}
				$link = get_term_link( $at );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => $at->name,
					'item'     => $link,
				);
			}
		}
		$cur_link = get_term_link( $term );
		$items[]  = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $term->name,
			'item'     => is_wp_error( $cur_link ) ? '' : $cur_link,
		);
		return wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}

	private static function build_current_breadcrumbs_json( $post ) {
		if ( ! $post instanceof WP_Post || ! $post->ID ) {
			return '[]';
		}
		$items   = array();
		$pos     = 1;
		$home    = home_url( '/' );
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => get_bloginfo( 'name' ),
			'item'     => $home,
		);
		if ( 'product' === $post->post_type ) {
			self::append_woocommerce_product_breadcrumb_items( $items, $pos, $post );
		}
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$ancestors = array_reverse( array_map( 'intval', get_post_ancestors( $post ) ) );
			foreach ( $ancestors as $aid ) {
				$ap = get_post( $aid );
				if ( ! $ap ) {
					continue;
				}
				$purl = get_permalink( $ap );
				if ( ! $purl ) {
					continue;
				}
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => get_the_title( $ap ),
					'item'     => $purl,
				);
			}
		}
		$cur = get_permalink( $post );
		if ( $cur ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => get_the_title( $post ),
				'item'     => $cur,
			);
		}
		// Intermediate serialization (decoded + re-encoded by the hardened output sink);
		// kept consistent with that sink for defense-in-depth.
		return wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}

	/**
	 * Clean post text for use in a structured-data text field: expand nothing, but remove
	 * shortcodes (registered via strip_shortcodes(), plus any unregistered/leftover shortcode-
	 * shaped tokens) and HTML tags, then collapse whitespace. Prevents raw "[shortcode …]" markup
	 * — including the email-obfuscator's plain address — from leaking into JSON-LD descriptions.
	 *
	 * @param string $text Raw post content/excerpt.
	 * @return string
	 */
	private static function clean_schema_text_value( $text ) {
		$text = strip_shortcodes( (string) $text );
		// Drop unregistered / late-registered shortcode-shaped tokens strip_shortcodes() leaves
		// behind (a bracketed tag starting with a letter, opening or closing). Numeric refs like
		// "[1]" are intentionally preserved.
		$text = preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:[^\]]*)?\]/i', '', (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Get replacement values for variables (context-aware).
	 *
	 * @param WP_Post|null $post Current post or null.
	 * @return array Map of variable => value.
	 */
	public static function get_replacements( $post = null ) {
		// Resolve an author for %author.*% replacements. On non-singular pages or posts with
		// no post_author, fall back to user ID 1 so schemas (Person, Article byline, etc.) do
		// not render with literal `%author.display_name%` style placeholders.
		$author_id = 0;
		if ( $post && ! empty( $post->post_author ) ) {
			$author_id = (int) $post->post_author;
		}
		if ( ! $author_id ) {
			$primary   = get_users(
				array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
				) 
			);
			$author_id = ! empty( $primary[0] ) ? (int) $primary[0] : 1;
		}
		$term    = get_queried_object();
		$is_term = $term instanceof WP_Term;

		$r = array(
			'%current.breadcrumbs%' => '[]',
		);

		// Post.
		if ( $post ) {
			$r['%post.title%']           = $post->post_title;
			$r['%post.ID%']              = (string) $post->ID;
			// Strip shortcodes before these feed structured-data text fields (e.g. WebPage /
			// Article description = %post.excerpt%). Raw content otherwise leaks a literal
			// "[obfuscate email=…]" / "[some_slider]" into the JSON-LD — an unexpanded shortcode
			// (and, for the email obfuscator, the plain address) exposed in machine-readable markup.
			// strip_shortcodes() clears registered ones; the leftover-bracket sweep clears
			// unregistered/late-registered ones (mirrors the meta-description cleanup).
			$r['%post.excerpt%']         = has_excerpt( $post->ID )
				? self::clean_schema_text_value( get_the_excerpt( $post ) )
				: wp_trim_words( self::clean_schema_text_value( $post->post_content ), 55 );
			$r['%post.content%']         = self::clean_schema_text_value( $post->post_content );
			$r['%post.url%']             = get_permalink( $post );
			$r['%post.slug%']            = $post->post_name;
			$r['%post.date%']            = get_the_date( '', $post );
			$r['%post.modified_date%']   = get_the_modified_date( '', $post );
			$r['%post.date_c%']          = get_the_date( 'c', $post );
			$r['%post.modified_date_c%'] = get_the_modified_date( 'c', $post );
			$thumb_id                    = get_post_thumbnail_id( $post->ID );
			$r['%post.thumbnail%']       = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
			$r['%post.comment_count%']   = (string) get_comments_number( $post->ID );
			// wp_check_invalid_utf8( …, true ) strips malformed bytes first; without it the /u
			// pattern would return false on invalid UTF-8 and the count would silently be 0.
			$wc_text                = wp_check_invalid_utf8( wp_strip_all_tags( $post->post_content ), true );
			$wc                     = preg_match_all( '/\p{L}[\p{L}\p{M}\p{Nd}\x27-]*/u', $wc_text );
			$r['%post.word_count%'] = (string) ( false === $wc ? 0 : (int) $wc );
			$terms_tags             = get_the_terms( $post->ID, 'post_tag' );
			$r['%post.tags%']       = $terms_tags && ! is_wp_error( $terms_tags ) ? implode( ', ', wp_list_pluck( $terms_tags, 'name' ) ) : '';
			$terms_cat              = get_the_terms( $post->ID, 'category' );
			$r['%post.categories%'] = $terms_cat && ! is_wp_error( $terms_cat ) ? implode( ', ', wp_list_pluck( $terms_cat, 'name' ) ) : '';

			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			foreach ( $taxonomies as $tax ) {
				$terms     = get_the_terms( $post->ID, $tax );
				$key       = '%post.tax.' . sanitize_key( $tax ) . '%';
				$r[ $key ] = $terms && ! is_wp_error( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
			}

			$r['%current.breadcrumbs%'] = self::build_current_breadcrumbs_json( $post );
			if ( 'product' === $post->post_type ) {
				$r = array_merge( $r, self::get_woocommerce_product_replacements( $post ) );
			}
		}

		// Term (archive context).
		if ( $is_term ) {
			$term_link               = get_term_link( $term );
			$r['%term.ID%']          = (string) $term->term_id;
			$r['%term.name%']        = $term->name;
			$r['%term.slug%']        = $term->slug;
			$r['%term.taxonomy%']    = $term->taxonomy;
			$r['%term.description%'] = $term->description;
			$r['%term.url%']         = is_wp_error( $term_link ) ? '' : $term_link;
			// Breadcrumbs for term archives (Home → ancestor terms → current term), so a
			// BreadcrumbList seeded for archives has real items instead of resolving to "[]".
			$r['%current.breadcrumbs%'] = self::build_current_term_breadcrumbs_json( $term );
		}

		// Author.
		if ( $author_id ) {
			$user = get_userdata( $author_id );
			if ( $user ) {
				$r['%author.ID%']           = (string) $user->ID;
				$r['%author.first_name%']   = $user->first_name;
				$r['%author.last_name%']    = $user->last_name;
				$r['%author.display_name%'] = $user->display_name;
				$r['%author.username%']     = $user->user_login;
				$r['%author.nickname%']     = $user->nickname;
				// %author.email% intentionally not resolved: emitting a real email into public
				// JSON-LD exposes PII to crawlers/aggregators permanently. Use explicit per-post
				// schema meta if an email is genuinely required for a given entity.
				$r['%author.website_url%'] = $user->user_url;
				$r['%author.nicename%']    = $user->user_nicename;
				$r['%author.description%'] = get_user_meta( $user->ID, 'description', true );
				$r['%author.posts_url%']   = get_author_posts_url( $user->ID );
				$r['%author.avatar%']      = get_avatar_url( $user->ID, array( 'size' => 96 ) );
			}
		}

		// Site.
		$r['%site.title%']                       = get_bloginfo( 'name' );
		$r['%site.description%']                 = get_bloginfo( 'description' );
		$r['%site.url%']                         = home_url( '/' );
		$default_search_url                      = esc_url( home_url( '/' ) ) . '?s={search_term_string}';
		$r['%site.search_url%']                  = apply_filters( 'nexter_content_seo_search_action_target_url', $default_search_url );
		$r['%site.language%']                    = get_bloginfo( 'language' );
		$primary_user                            = get_userdata( 1 );
		$r['%schema.primary_user_display_name%'] = ( $primary_user && $primary_user->display_name )
			? $primary_user->display_name
			: get_bloginfo( 'name' );
		$site_icon                               = get_site_icon_url();
		$r['%site.icon%']                        = $site_icon ?: '';

		// Current page. Build the URL from the trusted site host (home_url), NOT the client-
		// supplied Host header — a spoofed Host could otherwise be injected into rendered
		// schema. The request path/query is run through esc_url_raw.
		$r['%current.title%'] = wp_get_document_title();
		$current_host         = wp_parse_url( home_url(), PHP_URL_HOST );
		$current_uri          = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$r['%current.url%']   = $current_host
			? esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $current_host . $current_uri )
			: '';

		// NOTE: the current-logged-in-user (%user.*%) tokens were intentionally removed.
		// Schema is rendered server-side into cacheable HTML; resolving viewer-specific data
		// (email, name, username) here would bake the first logged-in visitor's PII into the
		// full-page cache served to all subsequent visitors. Person/author data is sourced
		// from the post author (%author.*%) instead, which is viewer-independent.

		// Website details. The static front page's per-post Nexter SEO meta is the
		// source of truth for homepage SEO; the schema-level website name and
		// description fall back to the WordPress site name and tagline.
		$opts                                        = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$r['%website_details.website_name%']         = get_bloginfo( 'name' );
		$r['%website_details.business_description%'] = get_bloginfo( 'description' );
		$r['%website_details.website_owner_name%']   = '';
		$r['%website_details.organization_type%']    = 'Organization';
		$r['%website_details.website_owner_phone%']  = '';
		$r['%website_details.website_logo%']         = isset( $opts['default_social_image'] ) && $opts['default_social_image'] ? $opts['default_social_image'] : get_site_icon_url();

		// Resolve %schemas.{type}% to home URL + /#fragment. Must match the exact form used by the
		// seeded node @ids (%site.url%#organization → "https://site/#organization") and the
		// automatic WebPage node's org/website/person refs, otherwise author/publisher references
		// dangle and get pruned. %site.url% carries a trailing slash, so mirror it with "/#".
		$root = isset( $r['%site.url%'] ) ? untrailingslashit( $r['%site.url%'] ) : untrailingslashit( home_url( '/' ) );
		foreach ( array_keys( self::get_schema_types() ) as $stype ) {
			$slug                           = strtolower( $stype );
			$r[ '%schemas.' . $slug . '%' ] = $root . '/#' . $slug;
		}

		// Organization tokens (%organization_name/logo/url%) advertised in Settings. Sourced from
		// the stored Organization schema row so they resolve to real data instead of being stripped
		// as unknown placeholders by replace_variables_recursive().
		$r = array_merge( $r, self::get_organization_token_values() );

		return apply_filters( 'nexter_content_seo_schema_replacements', $r );
	}

	/**
	 * Locate the enabled Organization schema row's stored fields (site_wide preferred), or [].
	 *
	 * @return array<string,mixed>
	 */
	private static function get_organization_fields_from_schema() {
		$schema = get_option( self::OPTION_SCHEMA, array() );
		if ( ! is_array( $schema ) ) {
			return array();
		}
		foreach ( array( 'site_wide', 'page_specific' ) as $bucket ) {
			if ( empty( $schema[ $bucket ] ) || ! is_array( $schema[ $bucket ] ) ) {
				continue;
			}
			foreach ( $schema[ $bucket ] as $row ) {
				if ( is_array( $row ) && ! empty( $row['enabled'] )
					&& isset( $row['type'] ) && 'Organization' === $row['type']
					&& ! empty( $row['fields'] ) && is_array( $row['fields'] ) ) {
					return $row['fields'];
				}
			}
		}
		return array();
	}

	/**
	 * Resolved values for the %organization_name/logo/url% tokens, sourced from the stored
	 * Organization schema row (the source of truth advertised in Settings) with site-identity
	 * fallbacks. Any %site.*% / %website_details.*% tokens the stored values carry (the seeded
	 * defaults use them) are resolved here so the tokens never leak or resolve to empty.
	 *
	 * @return array<string,string> Map of the three %organization_*% tokens => value.
	 */
	public static function get_organization_token_values() {
		$org          = self::get_organization_fields_from_schema();
		$opts         = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		$icon         = get_site_icon_url() ?: '';
		$logo_default = ! empty( $opts['default_social_image'] ) ? (string) $opts['default_social_image'] : $icon;

		$resolve = static function ( $value ) use ( $icon, $logo_default ) {
			$value = strtr(
				(string) $value,
				array(
					'%site.title%'                   => get_bloginfo( 'name' ),
					'%site.url%'                     => home_url( '/' ),
					'%site.icon%'                    => $icon,
					'%website_details.website_logo%' => $logo_default,
					'%website_details.website_name%' => get_bloginfo( 'name' ),
				)
			);
			$value = preg_replace( '/%[a-z0-9_.]+%/i', '', (string) $value ); // Drop any other tokens.
			return trim( (string) $value );
		};

		$name = $resolve( isset( $org['name'] ) ? $org['name'] : '' );
		$logo = $resolve( isset( $org['logo'] ) ? $org['logo'] : '' );
		$url  = $resolve( isset( $org['url'] ) ? $org['url'] : '' );

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		if ( '' === $logo ) {
			$logo = $logo_default;
		}

		return array(
			'%organization_name%' => $name,
			'%organization_logo%' => $logo,
			'%organization_url%'  => $url,
		);
	}

	/**
	 * Recursively replace variables in array (template rendering).
	 *
	 * @param array $data   Data array (may contain nested arrays and strings with %var%).
	 * @param array $replacements Optional. Pre-computed replacements. If empty, uses get_replacements().
	 * @return array
	 */
	public static function replace_variables_recursive( $data, $replacements = null ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( null === $replacements ) {
			$post         = is_singular() ? get_post() : null;
			$replacements = self::get_replacements( $post );
		}
		foreach ( $data as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = self::replace_variables_recursive( $value, $replacements );
			} elseif ( is_string( $value ) && str_contains( $value, '%' ) ) {
				$value = str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
				// Strip any remaining %var.name% tokens so unresolved placeholders
				// (e.g. %author.*% on archives without a discoverable author) do not
				// leak into rendered JSON-LD.
				if ( str_contains( $value, '%' ) ) {
					$value = preg_replace( '/%[a-zA-Z0-9_.]+%/', '', $value );
					$value = is_string( $value ) ? trim( $value ) : '';
				}
			}
		}
		return $data;
	}

	/**
	 * Get schema rules selections for Display On / Do Not Display On.
	 *
	 * @return array
	 */
	public static function get_schema_rules_selections() {
		$options = array(
			'basic'         => array(
				'label' => __( 'Basic', 'nexter-extension' ),
				'value' => array(
					'basic-global'    => __( 'Entire Website', 'nexter-extension' ),
					'basic-singulars' => __( 'All Singulars', 'nexter-extension' ),
					'basic-archives'  => __( 'All Archives', 'nexter-extension' ),
				),
			),
			'special-pages' => array(
				'label' => __( 'Special Pages', 'nexter-extension' ),
				'value' => array(
					'special-front'  => __( 'Front Page', 'nexter-extension' ),
					'special-blog'   => __( 'Blog / Posts Page', 'nexter-extension' ),
					'special-search' => __( 'Search Page', 'nexter-extension' ),
					'special-404'    => __( '404 Page', 'nexter-extension' ),
					'special-author' => __( 'Author Archive', 'nexter-extension' ),
					'special-date'   => __( 'Date Archive', 'nexter-extension' ),
				),
			),
		);

		$post_types         = get_post_types(
			array(
			'public'   => true,
			'_builtin' => false
			),
			'objects' 
		);
		$post_types['post'] = get_post_type_object( 'post' );
		$post_types['page'] = get_post_type_object( 'page' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $pt ) {
			if ( ! $pt ) continue;
			$key             = sanitize_key( $pt->name );
			$options[ $key ] = array(
				'label' => $pt->labels->name,
				'value' => array(
					/* translators: %s: the content type name */
					$pt->name . '|all'         => sprintf( __( 'All %s', 'nexter-extension' ), $pt->labels->name ),
					/* translators: %s: post type or taxonomy label */
					$pt->name . '|all|archive' => sprintf( __( 'All %s Archive', 'nexter-extension' ), $pt->labels->name ),
				),
			);
		}

		$options['particular'] = array(
			'label' => __( 'Particular Posts/Pages/Taxonomies', 'nexter-extension' ),
			'value' => array(
				'particular-post' => __( 'Specific Pages / Posts / Taxonomies, etc.', 'nexter-extension' ),
			),
		);

		return apply_filters( 'nexter_content_seo_schema_rules_selections', $options );
	}

	/**
	 * Whether a rule id is a builder-style particular target (post-*, taxonomy-*).
	 *
	 * @param string $rule Rule string.
	 * @return bool
	 */
	private static function is_schema_particular_rule( $rule ) {
		$rule = (string) $rule;
		return ( strpos( $rule, 'post-' ) === 0 || strpos( $rule, 'taxonomy-' ) === 0 );
	}

	/**
	 * Split stored rules into main multiselect vs particular-post AJAX multiselect (for markup).
	 *
	 * @param array $rules Raw rule strings.
	 * @return array{0: array, 1: array} Main rules, particular ids.
	 */
	private static function split_schema_rules_for_markup( $rules ) {
		$rules = is_array( $rules ) ? array_map( 'strval', $rules ) : array();
		$main  = array();
		$part  = array();
		foreach ( $rules as $r ) {
			if ( self::is_schema_particular_rule( $r ) ) {
				$part[] = $r;
			} elseif ( 'particular-post' === $r ) {
				continue;
			} else {
				$main[] = $r;
			}
		}
		if ( ! empty( $part ) && ! in_array( 'particular-post', $main, true ) ) {
			$main[] = 'particular-post';
		}
		return array( $main, $part );
	}

	/**
	 * Human label for a particular rule (post / taxonomy ids from Nexter Builder AJAX).
	 *
	 * @param string $rule Rule id.
	 * @return string
	 */
	private static function resolve_schema_particular_label( $rule ) {
		$rule = (string) $rule;
		if ( preg_match( '/^post-(\d+)$/', $rule, $m ) ) {
			$title = get_the_title( (int) $m[1] );
			return '' !== $title ? $title : $rule;
		}
		if ( preg_match( '/^taxonomy-(\d+)-singular-([a-zA-Z0-9_-]+)$/', $rule, $m ) ) {
			$term = get_term( (int) $m[1], $m[2] );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name . ' — ' . __( 'singulars', 'nexter-extension' );
			}
		}
		if ( preg_match( '/^taxonomy-(\d+)$/', $rule, $m ) ) {
			$term = get_term( (int) $m[1] );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name . ' — ' . __( 'archive', 'nexter-extension' );
			}
		}
		return $rule;
	}

	/**
	 * Markup for Specific Pages/Posts select (Select2 + same AJAX as Nexter Builder).
	 *
	 * @param string $bucket            'include' or 'exclude'.
	 * @param array  $selected_particular Selected post-/taxonomy- ids.
	 * @return string
	 */
	private static function build_schema_specific_posts_markup( $bucket, $selected_particular ) {
		$bucket  = ( 'exclude' === $bucket ) ? 'exclude' : 'include';
		$id      = 'nxt-seo-schema-' . $bucket . '-specific';
		$output  = '<div class="nxt-layout-specific-post-wrap nxt-seo-schema-specific-wrap" data-nxt-seo-specific-bucket="' . esc_attr( $bucket ) . '">';
		$output .= '<label class="nxt-main-label">' . esc_html__( 'Specific Pages/Posts', 'nexter-extension' ) . '</label>';
		$output .= '<select class="nxt-temp-select nxt-layout-user-roles nxt-seo-schema-specific-select" multiple="multiple" name="' . esc_attr( $id ) . '[]" id="' . esc_attr( $id ) . '">';
		foreach ( (array) $selected_particular as $rid ) {
			if ( ! self::is_schema_particular_rule( $rid ) ) {
				continue;
			}
			$output .= '<option value="' . esc_attr( $rid ) . '" selected="selected">' . esc_html( self::resolve_schema_particular_label( $rid ) ) . '</option>';
		}
		$output .= '</select></div>';
		return $output;
	}

	/**
	 * Build a multi-select of schema display rules (same option set as the Schema settings UI).
	 *
	 * @param string $id_attr       HTML id for the select.
	 * @param string $name_attr     Name attribute (include [] for multiple).
	 * @param array  $selected_rules Rule ids to mark selected.
	 * @return string
	 */
	private static function build_schema_rules_multiselect_html( $id_attr, $name_attr, $selected_rules ) {
		$selected_rules = is_array( $selected_rules ) ? array_map( 'strval', $selected_rules ) : array();
		$output         = '<select class="nxt-temp-select nxt-seo-schema-cond-select" multiple="multiple" id="' . esc_attr( $id_attr ) . '" name="' . esc_attr( $name_attr ) . '">';
		$groups         = self::get_schema_rules_selections();
		foreach ( $groups as $group ) {
			$label = isset( $group['label'] ) ? $group['label'] : '';
			$vals  = isset( $group['value'] ) && is_array( $group['value'] ) ? $group['value'] : array();
			if ( '' === $label || empty( $vals ) ) {
				continue;
			}
			$output .= '<optgroup label="' . esc_attr( $label ) . '">';
			foreach ( $vals as $rule_id => $rule_label ) {
				$rid      = (string) $rule_id;
				$selected = in_array( $rid, $selected_rules, true ) ? ' selected="selected"' : '';
				$output  .= '<option value="' . esc_attr( $rid ) . '"' . $selected . '>' . esc_html( $rule_label ) . '</option>';
			}
			$output .= '</optgroup>';
		}
		$output .= '</select>';
		return $output;
	}

	/**
	 * Markup for the schema “display conditions” popup (structure aligned with Nexter Builder condition UI).
	 *
	 * @param array $show_on     Block with `rules` list (include).
	 * @param array $not_show_on Block with `rules` list (exclude).
	 * @return string Safe HTML.
	 */
	public static function get_schema_conditions_popup_markup( $show_on = array(), $not_show_on = array() ) {
		$include = isset( $show_on['rules'] ) && is_array( $show_on['rules'] ) ? $show_on['rules'] : array();
		$exclude = isset( $not_show_on['rules'] ) && is_array( $not_show_on['rules'] ) ? $not_show_on['rules'] : array();

		list( $inc_main, $inc_part ) = self::split_schema_rules_for_markup( $include );
		list( $exc_main, $exc_part ) = self::split_schema_rules_for_markup( $exclude );

		$output  = '<div class="nxt-common-cnt-wrap nxt-condition-main-wrap nxt-seo-schema-condition-ajax">';
		$output .= '<div class="nxt-common-cnt-inner">';
		$output .= '<div class="nxt-condition-include">';
		$output .= '<label class="nxt-main-label">' . esc_html__( 'Include In', 'nexter-extension' ) . '</label>';
		$output .= self::build_schema_rules_multiselect_html( 'nxt-seo-schema-include-rules', 'nxt-seo-schema-include-rules[]', $inc_main );
		$output .= self::build_schema_specific_posts_markup( 'include', $inc_part );
		$output .= '</div>';
		$output .= '<div class="nxt-include-exclude-sep"></div>';
		$output .= '<div class="nxt-condition-exclude">';
		$output .= '<label class="nxt-main-label">' . esc_html__( 'Exclude From', 'nexter-extension' ) . '</label>';
		$output .= self::build_schema_rules_multiselect_html( 'nxt-seo-schema-exclude-rules', 'nxt-seo-schema-exclude-rules[]', $exc_main );
		$output .= self::build_schema_specific_posts_markup( 'exclude', $exc_part );
		$output .= '</div>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Validate schema display rules.
	 *
	 * @param array  $schema    Schema with show_on, not_show_on.
	 * @param string $post_type Current post type.
	 * @param int    $post_id   Current post ID.
	 * @return bool
	 */
	public static function validate_schema_rules( $schema, $post_type = '', $post_id = 0 ) {
		$show_on_rules     = isset( $schema['show_on']['rules'] ) ? (array) $schema['show_on']['rules'] : array();
		$not_show_on_rules = isset( $schema['not_show_on']['rules'] ) ? (array) $schema['not_show_on']['rules'] : array();

		// No rules = don't show.
		if ( empty( $show_on_rules ) && empty( $not_show_on_rules ) ) {
			return false;
		}

		// show_on: empty = match all; otherwise at least one rule must match.
		$show_match = empty( $show_on_rules ) || self::evaluate_rules( $show_on_rules, $post_type, $post_id );
		// not_show_on: if any rule matches, exclude.
		$not_match = ! empty( $not_show_on_rules ) && self::evaluate_rules( $not_show_on_rules, $post_type, $post_id );

		return $show_match && ! $not_match;
	}

	/**
	 * Evaluate rules against current context.
	 *
	 * @param array  $rules     Rules to evaluate.
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post ID.
	 * @return bool
	 */
	private static function evaluate_rules( $rules, $post_type, $post_id ) {
		foreach ( $rules as $rule ) {
			if ( self::matches_context( $rule, $post_type, $post_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if rule matches current context.
	 *
	 * @param string $rule      Rule string.
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post ID.
	 * @return bool
	 */
	private static function matches_context( $rule, $post_type, $post_id ) {
		if ( 'particular-post' === $rule ) {
			return false;
		}

		if ( preg_match( '/^post-(\d+)$/', $rule, $m ) ) {
			$pid = (int) $m[1];
			if ( ! is_singular() ) {
				return false;
			}
			$qid = (int) get_queried_object_id();
			return $qid === $pid;
		}

		if ( preg_match( '/^taxonomy-(\d+)-singular-([a-zA-Z0-9_-]+)$/', $rule, $m ) ) {
			if ( ! is_singular() || ! $post_id ) {
				return false;
			}
			return has_term( (int) $m[1], $m[2], $post_id );
		}

		if ( preg_match( '/^taxonomy-(\d+)$/', $rule, $m ) ) {
			$tid = (int) $m[1];
			if ( ! is_tax() && ! is_category() && ! is_tag() ) {
				return false;
			}
			$obj = get_queried_object();
			return $obj && isset( $obj->term_id ) && (int) $obj->term_id === $tid;
		}

		$parts = explode( '|', $rule );
		$type  = $parts[0] ?? '';

		switch ( $type ) {
			case 'basic-global':
				return true;
			case 'basic-singulars':
				// A static front page is a homepage, not a content article — exclude it so
				// singular-scoped defaults (Article, BreadcrumbList) don't fire on the home URL.
				// It still matches special-front / basic-global for WebPage/WebSite output.
				return is_singular() && ! is_front_page();
			case 'basic-archives':
				return is_archive();
			case 'special-front':
				return is_front_page();
			case 'special-blog':
				return is_home() && ! is_front_page();
			case 'special-search':
				return is_search();
			case 'special-404':
				return is_404();
			case 'special-author':
				return is_author();
			case 'special-date':
				return is_date();
		}

		if ( post_type_exists( $type ) ) {
			$sub = $parts[1] ?? '';
			if ( 'all' === $sub ) {
				$archive = $parts[2] ?? '';
				if ( 'archive' === $archive ) {
					return is_post_type_archive( $type );
				}
				return is_singular( $type ) || $post_type === $type;
			}
		}
		return false;
	}

	/**
	 * Get default schema configs (when none configured).
	 *
	 * @return array
	 */
	public static function get_default_schemas() {
		$person_fields = self::get_default_fields_for_type( 'Person' );
		if ( empty( $person_fields ) ) {
			$person_fields = array(
				'@id'  => '%schemas.person%',
				'name' => '%author.display_name%',
				'url'  => '%author.posts_url%',
			);
		}

		return array(
			array(
				'type'        => 'Organization',
				'enabled'     => true,
				'fields'      => array(
					'@id'     => '%site.url%#organization',
					'@type'   => 'Organization',
					'name'    => '%site.title%',
					'url'     => '%site.url%',
					'logo'    => '%website_details.website_logo%',
					'slogan'  => '%site.description%',
					'founder' => array(
						array(
							'@type' => 'Person',
							'name'  => '%schema.primary_user_display_name%',
						),
					),
				),
				'show_on'     => array( 'rules' => array( 'basic-global' ) ),
				'not_show_on' => array( 'rules' => array() ),
			),
			array(
				'type'        => 'Person',
				'enabled'     => true,
				'fields'      => $person_fields,
				'show_on'     => array( 'rules' => array( 'basic-global' ) ),
				'not_show_on' => array( 'rules' => array() ),
			),
			array(
				'type'        => 'WebSite',
				'enabled'     => true,
				'fields'      => array(
					'@id'             => '%site.url%#website',
					'name'            => '%site.title%',
					'url'             => '%site.url%',
					'author'          => '%schemas.person%',
					'copyrightHolder' => '%schemas.person%',
					'potentialAction' => '%schemas.searchaction%',
					'publisher'       => array( '@id' => '%site.url%#organization' ),
				),
				'show_on'     => array( 'rules' => array( 'basic-global' ) ),
				'not_show_on' => array( 'rules' => array() ),
			),
		);
	}

	/**
	 * Append enabled schema rows (with type) to a result list.
	 *
	 * @param array $rows   Raw rows.
	 * @param array $result Result list (by reference).
	 */
	private static function append_enabled_schema_rows_to_result( $rows, array &$result ) {
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$type = isset( $s['type'] ) ? $s['type'] : '';
			if ( empty( $type ) ) {
				continue;
			}
			if ( isset( $s['enabled'] ) && ! $s['enabled'] ) {
				continue;
			}
			$result[] = array_merge( array( 'type' => $type ), $s );
		}
	}

	/**
	 * Whether this post uses per-post schema rows instead of global page-specific rules.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_uses_custom_page_schema( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		return (bool) get_post_meta( $post_id, '_nxt_seo_schema_custom', true );
	}

	/**
	 * Raw decoded per-post schema rows from meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_post_custom_schema_rows_raw( $post_id ) {
		$raw = get_post_meta( (int) $post_id, '_nxt_seo_schema_rows', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Global page-specific schema rows that apply to this post (display rules pass).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_inherited_page_schema_rows_for_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$lists = self::normalize_schema_lists( get_option( self::OPTION_SCHEMA, array() ) );
		$out   = array();
		foreach ( $lists['page_specific'] as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$type = isset( $s['type'] ) ? $s['type'] : '';
			if ( empty( $type ) ) {
				continue;
			}
			if ( isset( $s['enabled'] ) && ! $s['enabled'] ) {
				continue;
			}
			if ( ! self::validate_schema_rules( $s, $post->post_type, $post_id ) ) {
				continue;
			}
			$out[] = $s;
		}
		return $out;
	}

	/**
	 * Sanitize a flat list of schema rows for per-post storage (same rules as global payload rows).
	 *
	 * @param array $rows    Raw rows from REST.
	 * @param int   $post_id Post ID (for rule anchoring).
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_post_schema_rows_list( $rows, $post_id ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $rows ) || $post_id <= 0 ) {
			return array();
		}
		$allowed_types = array_keys( self::get_schema_types() );
		$out           = array();
		$post_rule     = 'post-' . $post_id;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '';
			if ( empty( $type ) || ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '';
			if ( '' === $id ) {
				$id = wp_generate_password( 12, false, false );
			}
			$title  = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $type;
			$fields = self::sanitize_fields_recursive( isset( $row['fields'] ) ? $row['fields'] : array() );
			if ( 'Organization' === $type ) {
				$fields = self::sanitize_organization_schema_fields( $fields );
			}
			if ( 'WebPage' === $type ) {
				$fields = self::sanitize_web_page_schema_fields( $fields );
			}
			if ( 'Article' === $type ) {
				$fields = self::sanitize_article_schema_fields( $fields );
			}
			if ( 'BreadcrumbList' === $type ) {
				$fields = self::sanitize_breadcrumb_list_schema_fields( $fields );
			}
			if ( 'Product' === $type ) {
				$fields = self::sanitize_product_schema_fields( $fields );
			}
			if ( 'ClaimReview' === $type ) {
				$fields = self::sanitize_claim_review_schema_fields( $fields );
			}
			if ( 'Course' === $type ) {
				$fields = self::sanitize_course_schema_fields( $fields );
			}
			if ( 'Event' === $type ) {
				$fields = self::sanitize_event_schema_fields( $fields );
			}
			if ( 'FAQPage' === $type ) {
				$fields = self::sanitize_faq_page_schema_fields( $fields );
			}
			if ( 'HowTo' === $type ) {
				$fields = self::sanitize_how_to_schema_fields( $fields );
			}
			if ( 'Recipe' === $type ) {
				$fields = self::sanitize_recipe_schema_fields( $fields );
			}
			if ( 'SoftwareApplication' === $type ) {
				$fields = self::sanitize_software_application_schema_fields( $fields );
			}
			if ( 'VideoObject' === $type ) {
				$fields = self::sanitize_video_object_schema_fields( $fields );
			}
			if ( 'LocalBusiness' === $type ) {
				$fields = self::sanitize_local_business_schema_fields( $fields );
			}
			$out[] = array(
				'id'          => $id,
				'type'        => $type,
				'title'       => $title,
				'enabled'     => ! empty( $row['enabled'] ),
				'fields'      => $fields,
				'show_on'     => array( 'rules' => array( $post_rule ) ),
				'not_show_on' => self::sanitize_rules_block( isset( $row['not_show_on'] ) ? $row['not_show_on'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * Default schema rows for taxonomy term SEO editor (WebSite → WebPage → Organization → SearchAction).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_default_term_schema_inherited_rows() {
		$make = function ( $type, $fields ) {
			if ( empty( $fields ) || ! is_array( $fields ) ) {
				return null;
			}
			return array(
				'id'          => wp_generate_password( 12, false, false ),
				'type'        => $type,
				'title'       => $type,
				'enabled'     => true,
				'fields'      => $fields,
				'show_on'     => array( 'rules' => array( 'basic-global' ) ),
				'not_show_on' => array( 'rules' => array() ),
			);
		};

		$defaults_by_type = array();
		foreach ( self::get_default_schemas() as $row ) {
			if ( ! empty( $row['type'] ) && is_array( $row ) ) {
				$defaults_by_type[ $row['type'] ] = $row;
			}
		}

		$ordered = array( 'WebSite', 'WebPage', 'Organization', 'SearchAction' );
		$out     = array();
		foreach ( $ordered as $type ) {
			if ( isset( $defaults_by_type[ $type ]['fields'] ) ) {
				$r = $make( $type, $defaults_by_type[ $type ]['fields'] );
			} else {
				$r = $make( $type, self::get_default_fields_for_type( $type ) );
			}
			if ( $r ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Whether a taxonomy term uses custom JSON-LD rows from term meta.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function term_uses_custom_schema( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return false;
		}
		return (bool) get_term_meta( $term_id, '_nxt_seo_schema_custom', true );
	}

	/**
	 * Raw per-term schema rows from term meta (decoded).
	 *
	 * @param int $term_id Term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_term_custom_schema_rows_raw( $term_id ) {
		$raw = get_term_meta( (int) $term_id, '_nxt_seo_schema_rows', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sanitize schema rows stored on a taxonomy term (anchored to this term archive).
	 *
	 * @param array $rows    Rows from REST.
	 * @param int   $term_id Term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_term_schema_rows_list( $rows, $term_id ) {
		$term_id = (int) $term_id;
		if ( ! is_array( $rows ) || $term_id <= 0 ) {
			return array();
		}
		$allowed_types = array_keys( self::get_schema_types() );
		$out           = array();
		$term_rule     = 'taxonomy-' . $term_id;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '';
			if ( empty( $type ) || ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '';
			if ( '' === $id ) {
				$id = wp_generate_password( 12, false, false );
			}
			$title  = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $type;
			$fields = self::sanitize_fields_recursive( isset( $row['fields'] ) ? $row['fields'] : array() );
			if ( 'Organization' === $type ) {
				$fields = self::sanitize_organization_schema_fields( $fields );
			}
			if ( 'WebPage' === $type ) {
				$fields = self::sanitize_web_page_schema_fields( $fields );
			}
			if ( 'Article' === $type ) {
				$fields = self::sanitize_article_schema_fields( $fields );
			}
			if ( 'BreadcrumbList' === $type ) {
				$fields = self::sanitize_breadcrumb_list_schema_fields( $fields );
			}
			if ( 'Product' === $type ) {
				$fields = self::sanitize_product_schema_fields( $fields );
			}
			if ( 'ClaimReview' === $type ) {
				$fields = self::sanitize_claim_review_schema_fields( $fields );
			}
			if ( 'Course' === $type ) {
				$fields = self::sanitize_course_schema_fields( $fields );
			}
			if ( 'Event' === $type ) {
				$fields = self::sanitize_event_schema_fields( $fields );
			}
			if ( 'FAQPage' === $type ) {
				$fields = self::sanitize_faq_page_schema_fields( $fields );
			}
			if ( 'HowTo' === $type ) {
				$fields = self::sanitize_how_to_schema_fields( $fields );
			}
			if ( 'Recipe' === $type ) {
				$fields = self::sanitize_recipe_schema_fields( $fields );
			}
			if ( 'SoftwareApplication' === $type ) {
				$fields = self::sanitize_software_application_schema_fields( $fields );
			}
			if ( 'VideoObject' === $type ) {
				$fields = self::sanitize_video_object_schema_fields( $fields );
			}
			if ( 'LocalBusiness' === $type ) {
				$fields = self::sanitize_local_business_schema_fields( $fields );
			}
			$out[] = array(
				'id'          => $id,
				'type'        => $type,
				'title'       => $title,
				'enabled'     => ! empty( $row['enabled'] ),
				'fields'      => $fields,
				'show_on'     => array( 'rules' => array( $term_rule ) ),
				'not_show_on' => self::sanitize_rules_block( isset( $row['not_show_on'] ) ? $row['not_show_on'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * Get active schemas from options (site-wide + page-specific or per-post override on singular).
	 *
	 * @return array
	 */
	public static function get_active_schemas() {
		$lists  = self::normalize_schema_lists( get_option( self::OPTION_SCHEMA, array() ) );
		$result = array();
		self::append_enabled_schema_rows_to_result( $lists['site_wide'], $result );

		$term_archive_rows               = array();
		$term_skips_global_page_specific = false;
		if ( is_category() || is_tag() || is_tax() ) {
			$q = get_queried_object();
			if ( $q instanceof WP_Term ) {
				$tid = (int) $q->term_id;
				if ( $tid && self::term_uses_custom_schema( $tid ) ) {
					$term_skips_global_page_specific = true;
					foreach ( self::get_term_custom_schema_rows_raw( $tid ) as $s ) {
						if ( ! is_array( $s ) ) {
							continue;
						}
						$type = isset( $s['type'] ) ? $s['type'] : '';
						if ( empty( $type ) ) {
							continue;
						}
						if ( isset( $s['enabled'] ) && ! $s['enabled'] ) {
							continue;
						}
						$s['__nxt_skip_rules'] = true;
						$term_archive_rows[]   = array_merge( array( 'type' => $type ), $s );
					}
				}
			}
		}

		$post_id   = 0;
		$post_type = '';
		if ( is_singular() ) {
			$p = get_post();
			if ( $p instanceof WP_Post ) {
				$post_id   = (int) $p->ID;
				$post_type = (string) $p->post_type;
			}
		}

		$post_custom_rows     = $post_id ? self::get_post_custom_schema_rows_raw( $post_id ) : array();
		$post_uses_override   = $post_id && self::post_uses_custom_page_schema( $post_id );
		$post_has_custom_rows = ! empty( $post_custom_rows );

		if ( $post_uses_override ) {
			// Override: per-post rows replace global page-specific rules entirely.
			foreach ( $post_custom_rows as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$type = isset( $s['type'] ) ? $s['type'] : '';
				if ( empty( $type ) ) {
					continue;
				}
				if ( isset( $s['enabled'] ) && ! $s['enabled'] ) {
					continue;
				}
				$s['__nxt_skip_rules'] = true;
				$result[]              = array_merge( array( 'type' => $type ), $s );
			}
		} elseif ( $term_skips_global_page_specific ) {
			foreach ( $term_archive_rows as $row ) {
				$result[] = $row;
			}
		} else {
			self::append_enabled_schema_rows_to_result( $lists['page_specific'], $result );
			foreach ( $term_archive_rows as $row ) {
				$result[] = $row;
			}
			// Additive: per-post rows (e.g. FAQPage) without the override toggle
			// should still render alongside global page-specific rules, otherwise
			// they silently disappear from the @graph.
			if ( $post_has_custom_rows ) {
				foreach ( $post_custom_rows as $s ) {
					if ( ! is_array( $s ) ) {
						continue;
					}
					$type = isset( $s['type'] ) ? $s['type'] : '';
					if ( empty( $type ) ) {
						continue;
					}
					if ( isset( $s['enabled'] ) && ! $s['enabled'] ) {
						continue;
					}
					$s['__nxt_skip_rules'] = true;
					$result[]              = array_merge( array( 'type' => $type ), $s );
				}
			}
		}

		if ( empty( $result ) ) {
			return self::get_default_schemas();
		}
		return $result;
	}

	/**
	 * Whether a string should be coerced to a Schema.org {@id} reference object.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function looks_like_schema_reference_url( $value ) {
		return is_string( $value ) && preg_match( '#^https?://#i', $value );
	}

	/**
	 * Turn URL strings on known reference properties into array( '@id' => url ) (JSON-LD @graph style).
	 *
	 * @param array $data Node data (modified by reference).
	 * @param int   $depth Recursion depth.
	 */
	private static function normalize_schema_reference_fields( &$data, $depth = 0 ) {
		if ( $depth > 12 || ! is_array( $data ) ) {
			return;
		}
		$ref_keys = apply_filters(
			'nexter_content_seo_schema_reference_keys',
			array(
				'author',
				'publisher',
				'copyrightHolder',
				'contributor',
				'potentialAction',
				'isPartOf',
				'mainEntityOfPage',
				'mainEntity',
				'breadcrumb',
			)
		);
		foreach ( $data as $key => &$val ) {
			if ( in_array( (string) $key, $ref_keys, true ) && is_string( $val ) && self::looks_like_schema_reference_url( $val ) ) {
				$val = array( '@id' => $val );
			} elseif ( is_array( $val ) ) {
				self::normalize_schema_reference_fields( $val, $depth + 1 );
			}
		}
		unset( $val );
	}

	/**
	 * Whether an array is a JSON-style list (0..n-1 keys).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function array_is_zero_indexed_list( $arr ) {
		if ( ! is_array( $arr ) ) {
			return false;
		}
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * Normalize sameAs (string or list) to esc_url_raw values for JSON-LD output.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_schema_same_as_urls( &$fields ) {
		if ( ! is_array( $fields ) || ! isset( $fields['sameAs'] ) ) {
			return;
		}
		if ( is_string( $fields['sameAs'] ) ) {
			$one = trim( $fields['sameAs'] );
			if ( '' === $one ) {
				unset( $fields['sameAs'] );
			} else {
				$fields['sameAs'] = esc_url_raw( $one );
			}
		} elseif ( is_array( $fields['sameAs'] ) ) {
			$urls = array();
			foreach ( $fields['sameAs'] as $u ) {
				$u = trim( (string) $u );
				if ( '' === $u ) {
					continue;
				}
				$urls[] = esc_url_raw( $u );
			}
			$urls = array_values( array_filter( $urls ) );
			if ( empty( $urls ) ) {
				unset( $fields['sameAs'] );
			} elseif ( 1 === count( $urls ) ) {
				$fields['sameAs'] = $urls[0];
			} else {
				$fields['sameAs'] = $urls;
			}
		}
	}

	/**
	 * Normalize Organization-only list fields after variable replace / empty pruning.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_organization_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		if ( isset( $fields['@type'] ) ) {
			$allowed = self::get_organization_schema_type_allowed_values();
			$t       = trim( (string) $fields['@type'] );
			if ( ! in_array( $t, $allowed, true ) ) {
				unset( $fields['@type'] );
			}
		}
		if ( isset( $fields['founder'] ) && is_array( $fields['founder'] ) ) {
			$list = $fields['founder'];
			if ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$clean[] = array_merge(
					$item,
					array(
					'@type' => 'Person',
					'name'  => $name
					) 
				);
			}
			if ( empty( $clean ) ) {
				unset( $fields['founder'] );
			} else {
				$fields['founder'] = $clean;
			}
		}
		// Let other modules (e.g. Social Meta's profile URLs) contribute to this single, @id-bearing
		// Organization node's sameAs, so social profiles flow into the one Organization entity in the
		// @graph instead of a second, disconnected Organization JSON-LD block. finalize_schema_same_as_urls()
		// then normalizes / de-dupes / escapes the merged result.
		$existing_same_as = isset( $fields['sameAs'] ) ? $fields['sameAs'] : array();
		$merged_same_as   = apply_filters( 'nexter_content_seo_organization_same_as', $existing_same_as );
		if ( ! empty( $merged_same_as ) ) {
			$fields['sameAs'] = $merged_same_as;
		}
		self::finalize_schema_same_as_urls( $fields );
	}

	/**
	 * Normalize Person fields after variable replace / empty pruning.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_person_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		self::finalize_schema_same_as_urls( $fields );
	}

	/**
	 * Normalize WebPage fields after variable replace / empty pruning.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_web_page_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		if ( isset( $fields['@type'] ) ) {
			$allowed = self::get_web_page_schema_type_allowed_values();
			$t       = trim( (string) $fields['@type'] );
			if ( ! in_array( $t, $allowed, true ) ) {
				unset( $fields['@type'] );
			}
		}
	}

	/**
	 * Normalize Article fields after variable replace / empty pruning.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_article_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		if ( isset( $fields['@type'] ) ) {
			$allowed = self::get_article_schema_type_allowed_values();
			$t       = trim( (string) $fields['@type'] );
			if ( ! in_array( $t, $allowed, true ) ) {
				unset( $fields['@type'] );
			}
		}
		foreach ( array( 'commentCount', 'wordCount' ) as $num_key ) {
			if ( ! isset( $fields[ $num_key ] ) ) {
				continue;
			}
			$v = $fields[ $num_key ];
			if ( is_string( $v ) && is_numeric( $v ) ) {
				$fields[ $num_key ] = (int) $v;
			}
		}
		if ( isset( $fields['hasPart'] ) && is_array( $fields['hasPart'] ) ) {
			$clean = array();
			foreach ( $fields['hasPart'] as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				$sel = isset( $part['cssSelector'] ) ? trim( (string) $part['cssSelector'] ) : '';
				if ( '' === $sel ) {
					continue;
				}
				$free = $part['isAccessibleForFree'] ?? true;
				if ( is_string( $free ) ) {
					$low  = strtolower( $free );
					$free = ( 'true' === $low || '1' === $low || 'yes' === $low );
				} else {
					$free = (bool) $free;
				}
				$clean[] = array(
					'@type'               => 'WebPageElement',
					'isAccessibleForFree' => $free,
					'cssSelector'         => $sel,
				);
			}
			if ( empty( $clean ) ) {
				unset( $fields['hasPart'] );
			} else {
				$fields['hasPart'] = $clean;
			}
		}
	}

	/**
	 * Normalize Product fields (WooCommerce-oriented) after variable replace / empty pruning.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_product_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'Product';

		if ( isset( $fields['brand'] ) && is_array( $fields['brand'] ) ) {
			$bn = isset( $fields['brand']['name'] ) ? trim( (string) $fields['brand']['name'] ) : '';
			if ( '' === $bn ) {
				unset( $fields['brand'] );
			} else {
				$fields['brand']['@type'] = 'Brand';
			}
		}

		if ( isset( $fields['image'] ) && is_array( $fields['image'] ) ) {
			$url = isset( $fields['image']['url'] ) ? trim( (string) $fields['image']['url'] ) : '';
			if ( '' === $url ) {
				unset( $fields['image'] );
			} else {
				$fields['image']['@type'] = 'ImageObject';
			}
		}

		if ( isset( $fields['aggregateRating'] ) && is_array( $fields['aggregateRating'] ) ) {
			$rv = isset( $fields['aggregateRating']['ratingValue'] ) ? trim( (string) $fields['aggregateRating']['ratingValue'] ) : '';
			$rc = isset( $fields['aggregateRating']['reviewCount'] ) ? trim( (string) $fields['aggregateRating']['reviewCount'] ) : '';
			if ( '' === $rv && '' === $rc ) {
				unset( $fields['aggregateRating'] );
			} else {
				$fields['aggregateRating']['@type'] = 'AggregateRating';
				if ( '' !== $rv && is_numeric( $rv ) ) {
					$fields['aggregateRating']['ratingValue'] = (float) $rv;
				}
				if ( '' !== $rc && is_numeric( $rc ) ) {
					$fields['aggregateRating']['reviewCount'] = (int) $rc;
				}
			}
		}

		if ( isset( $fields['offers'] ) && is_array( $fields['offers'] ) ) {
			$ot = isset( $fields['offers']['@type'] ) ? trim( (string) $fields['offers']['@type'] ) : 'Offer';
			if ( 'AggregateOffer' !== $ot ) {
				$ot = 'Offer';
			}
			$fields['offers']['@type'] = $ot;
			if ( 'Offer' === $ot ) {
				unset( $fields['offers']['lowPrice'], $fields['offers']['highPrice'], $fields['offers']['offerCount'] );
			} else {
				unset( $fields['offers']['price'] );
			}

			if ( isset( $fields['offers']['availability'] ) ) {
				$fields['offers']['availability'] = self::normalize_schema_availability( $fields['offers']['availability'] );
				if ( '' === $fields['offers']['availability'] ) {
					unset( $fields['offers']['availability'] );
				}
			}
		}
	}

	/**
	 * Normalize a product availability value to a canonical schema.org URL. Accepts a full
	 * schema.org URL as-is, maps common free-text / WooCommerce statuses (and synonyms) to the
	 * right URL, and — rather than emit invalid free text — defaults unrecognized non-empty
	 * values to InStock. Empty input returns ''.
	 *
	 * @param mixed $value Raw availability value.
	 * @return string Canonical schema.org availability URL (or '').
	 */
	private static function normalize_schema_availability( $value ) {
		$av = trim( (string) $value );
		if ( '' === $av ) {
			return '';
		}
		if ( preg_match( '#^https?://schema\.org/#i', $av ) ) {
			return $av; // already a schema.org URL.
		}
		$map = array(
			'instock'             => 'https://schema.org/InStock',
			'available'           => 'https://schema.org/InStock',
			'outofstock'          => 'https://schema.org/OutOfStock',
			'unavailable'         => 'https://schema.org/OutOfStock',
			'onbackorder'         => 'https://schema.org/BackOrder',
			'backorder'           => 'https://schema.org/BackOrder',
			'preorder'            => 'https://schema.org/PreOrder',
			'presale'             => 'https://schema.org/PreSale',
			'soldout'             => 'https://schema.org/SoldOut',
			'discontinued'        => 'https://schema.org/Discontinued',
			'limitedavailability' => 'https://schema.org/LimitedAvailability',
			'instoreonly'         => 'https://schema.org/InStoreOnly',
			'onlineonly'          => 'https://schema.org/OnlineOnly',
		);
		$key = strtolower( preg_replace( '/[\s_-]+/', '', $av ) );
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		// Unrecognized free text — emit a valid default rather than invalid structured data.
		return 'https://schema.org/InStock';
	}

	/**
	 * Normalize ClaimReview nested objects (author, itemReviewed as Claim, reviewRating as Rating).
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_claim_review_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'ClaimReview';

		if ( isset( $fields['author'] ) && is_array( $fields['author'] ) ) {
			$aname = isset( $fields['author']['name'] ) ? trim( (string) $fields['author']['name'] ) : '';
			if ( '' === $aname ) {
				unset( $fields['author'] );
			} else {
				$at = isset( $fields['author']['@type'] ) ? trim( (string) $fields['author']['@type'] ) : 'Organization';
				if ( ! in_array( $at, array( 'Person', 'Organization' ), true ) ) {
					$at = 'Organization';
				}
				$fields['author']['@type'] = $at;
			}
		}

		if ( isset( $fields['itemReviewed'] ) && is_array( $fields['itemReviewed'] ) ) {
			$fields['itemReviewed']['@type'] = 'Claim';
			$has                             = false;
			foreach ( $fields['itemReviewed'] as $ik => $iv ) {
				if ( '@type' === $ik ) {
					continue;
				}
				if ( is_string( $iv ) && '' !== trim( $iv ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				unset( $fields['itemReviewed'] );
			}
		}

		if ( isset( $fields['reviewRating'] ) && is_array( $fields['reviewRating'] ) ) {
			$fields['reviewRating']['@type'] = 'Rating';
			foreach ( array( 'ratingValue', 'bestRating', 'worstRating' ) as $rk ) {
				if ( ! isset( $fields['reviewRating'][ $rk ] ) ) {
					continue;
				}
				$v = trim( (string) $fields['reviewRating'][ $rk ] );
				if ( '' === $v ) {
					unset( $fields['reviewRating'][ $rk ] );
					continue;
				}
				if ( is_numeric( $v ) ) {
					$fields['reviewRating'][ $rk ] = strpos( $v, '.' ) !== false ? (float) $v : (int) $v;
				}
			}
			$rr   = $fields['reviewRating'];
			$keep = false;
			foreach ( $rr as $ik => $iv ) {
				if ( '@type' === $ik ) {
					continue;
				}
				if ( is_string( $iv ) && '' !== trim( $iv ) ) {
					$keep = true;
					break;
				}
				if ( is_numeric( $iv ) ) {
					$keep = true;
					break;
				}
			}
			if ( ! $keep ) {
				unset( $fields['reviewRating'] );
			}
		}
	}

	/**
	 * Normalize Course lists (CourseInstance, Offer, child Course) and provider.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_course_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'Course';

		if ( isset( $fields['provider'] ) && is_string( $fields['provider'] ) ) {
			$p = trim( $fields['provider'] );
			if ( '' === $p ) {
				unset( $fields['provider'] );
			} else {
				$fields['provider'] = array(
					'@type' => 'Organization',
					'name'  => $p,
				);
			}
		}

		$allowed_modes = array( 'online', 'onsite', 'blended', 'synchronous', 'asynchronous', 'full-time', 'part-time' );
		if ( isset( $fields['hasCourseInstance'] ) && is_array( $fields['hasCourseInstance'] ) ) {
			$list = $fields['hasCourseInstance'];
			if ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$mode = isset( $row['courseMode'] ) ? trim( (string) $row['courseMode'] ) : 'online';
				if ( ! in_array( $mode, $allowed_modes, true ) ) {
					$mode = 'online';
				}
				$sd  = isset( $row['startDate'] ) ? trim( (string) $row['startDate'] ) : '';
				$ed  = isset( $row['endDate'] ) ? trim( (string) $row['endDate'] ) : '';
				$loc = isset( $row['location'] ) ? trim( (string) $row['location'] ) : '';
				$ins = isset( $row['instructor'] ) ? trim( (string) $row['instructor'] ) : '';
				if ( '' === $sd && '' === $ed && '' === $loc && '' === $ins ) {
					continue;
				}
				$one = array(
					'@type'      => 'CourseInstance',
					'courseMode' => $mode,
				);
				if ( '' !== $sd ) {
					$one['startDate'] = $sd;
				}
				if ( '' !== $ed ) {
					$one['endDate'] = $ed;
				}
				if ( '' !== $loc ) {
					$one['location'] = $loc;
				}
				if ( '' !== $ins ) {
					$one['instructor'] = $ins;
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['hasCourseInstance'] );
			} else {
				$fields['hasCourseInstance'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		$allowed_cats = array( 'Free', 'Partially Free', 'Subscription', 'Paid' );
		if ( isset( $fields['offers'] ) && is_array( $fields['offers'] ) ) {
			$list = $fields['offers'];
			if ( isset( $list['@type'] ) ) {
				$list = array( $list );
			}
			if ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$price = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
				$cur   = isset( $row['priceCurrency'] ) ? trim( (string) $row['priceCurrency'] ) : '';
				$cat   = isset( $row['category'] ) ? trim( (string) $row['category'] ) : '';
				if ( '' !== $cat && ! in_array( $cat, $allowed_cats, true ) ) {
					$cat = '';
				}
				if ( '' === $price && '' === $cur && '' === $cat ) {
					continue;
				}
				$one = array( '@type' => 'Offer' );
				if ( '' !== $price ) {
					$one['price'] = $price;
				}
				if ( '' !== $cur ) {
					$one['priceCurrency'] = $cur;
				}
				if ( '' !== $cat ) {
					$one['category'] = $cat;
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['offers'] );
			} else {
				$fields['offers'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		if ( isset( $fields['hasPart'] ) && is_array( $fields['hasPart'] ) ) {
			$list = $fields['hasPart'];
			if ( isset( $list['@type'] ) && 'Course' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
				$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
				$desc = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
				if ( '' === $name && '' === $url && '' === $desc ) {
					continue;
				}
				$one = array( '@type' => 'Course' );
				if ( '' !== $name ) {
					$one['name'] = $name;
				}
				if ( '' !== $url ) {
					$one['url'] = $url;
				}
				if ( '' !== $desc ) {
					$one['description'] = $desc;
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['hasPart'] );
			} else {
				$fields['hasPart'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}
	}

	/**
	 * Normalize Event: Place + PostalAddress, Organization organizer, Person performers, Offer, URLs.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_event_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'Event';

		$att_allowed = array(
			'https://schema.org/OnlineEventAttendanceMode',
			'https://schema.org/OfflineEventAttendanceMode',
			'https://schema.org/MixedEventAttendanceMode',
		);
		if ( isset( $fields['eventAttendanceMode'] ) ) {
			$m = trim( (string) $fields['eventAttendanceMode'] );
			if ( ! in_array( $m, $att_allowed, true ) ) {
				unset( $fields['eventAttendanceMode'] );
			} else {
				$fields['eventAttendanceMode'] = $m;
			}
		}

		$status_allowed = array(
			'https://schema.org/EventCancelled',
			'https://schema.org/EventMovedOnline',
			'https://schema.org/EventPostponed',
			'https://schema.org/EventRescheduled',
			'https://schema.org/EventScheduled',
		);
		if ( isset( $fields['eventStatus'] ) ) {
			$s = trim( (string) $fields['eventStatus'] );
			if ( ! in_array( $s, $status_allowed, true ) ) {
				unset( $fields['eventStatus'] );
			} else {
				$fields['eventStatus'] = $s;
			}
		}

		if ( isset( $fields['location'] ) ) {
			$list = $fields['location'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'Place' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$place_name       = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
				$street           = isset( $row['streetAddress'] ) ? trim( (string) $row['streetAddress'] ) : '';
				$locality         = isset( $row['addressLocality'] ) ? trim( (string) $row['addressLocality'] ) : '';
				$region           = isset( $row['addressRegion'] ) ? trim( (string) $row['addressRegion'] ) : '';
				$postal           = isset( $row['postalCode'] ) ? trim( (string) $row['postalCode'] ) : '';
				$country          = isset( $row['addressCountry'] ) ? trim( (string) $row['addressCountry'] ) : '';
				$addr_has_content = '' !== $street || '' !== $locality || '' !== $region || '' !== $postal || '' !== $country;
				if ( '' === $place_name && ! $addr_has_content ) {
					continue;
				}
				$place = array( '@type' => 'Place' );
				if ( '' !== $place_name ) {
					$place['name'] = $place_name;
				}
				if ( $addr_has_content ) {
					$addr = array( '@type' => 'PostalAddress' );
					if ( '' !== $street ) {
						$addr['streetAddress'] = $street;
					}
					if ( '' !== $locality ) {
						$addr['addressLocality'] = $locality;
					}
					if ( '' !== $region ) {
						$addr['addressRegion'] = $region;
					}
					if ( '' !== $postal ) {
						$addr['postalCode'] = $postal;
					}
					if ( '' !== $country ) {
						$addr['addressCountry'] = $country;
					}
					$place['address'] = $addr;
				}
				$clean[] = $place;
			}
			if ( empty( $clean ) ) {
				unset( $fields['location'] );
			} else {
				$fields['location'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		if ( isset( $fields['organizer'] ) && is_array( $fields['organizer'] ) ) {
			$org  = $fields['organizer'];
			$on   = isset( $org['name'] ) ? trim( wp_strip_all_tags( (string) $org['name'] ) ) : '';
			$ourl = isset( $org['url'] ) ? trim( (string) $org['url'] ) : '';
			if ( '' === $on && '' === $ourl ) {
				unset( $fields['organizer'] );
			} else {
				$out = array( '@type' => 'Organization' );
				if ( '' !== $on ) {
					$out['name'] = $on;
				}
				if ( '' !== $ourl ) {
					$out['url'] = $ourl;
				}
				$fields['organizer'] = $out;
			}
		}

		if ( isset( $fields['performer'] ) && is_array( $fields['performer'] ) ) {
			$list = $fields['performer'];
			if ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$n = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' === $n ) {
					continue;
				}
				$clean[] = array(
				'@type' => 'Person',
				'name'  => $n
				);
			}
			if ( empty( $clean ) ) {
				unset( $fields['performer'] );
			} else {
				$fields['performer'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		$avail_allowed = array(
			'https://schema.org/InStock',
			'https://schema.org/SoldOut',
			'https://schema.org/PreOrder',
		);
		if ( isset( $fields['offers'] ) && is_array( $fields['offers'] ) ) {
			$o = $fields['offers'];
			if ( self::array_is_zero_indexed_list( $o ) && isset( $o[0] ) && is_array( $o[0] ) ) {
				$o = $o[0];
			}
			$one = array( '@type' => 'Offer' );
			foreach ( array( 'name', 'price', 'priceCurrency', 'url', 'validFrom' ) as $prop ) {
				if ( isset( $o[ $prop ] ) ) {
					$pv = trim( (string) $o[ $prop ] );
					if ( '' !== $pv ) {
						$one[ $prop ] = $pv;
					}
				}
			}
			if ( isset( $o['availability'] ) ) {
				$av = trim( (string) $o['availability'] );
				if ( in_array( $av, $avail_allowed, true ) ) {
					$one['availability'] = $av;
				}
			}
			$meaningful = array_filter(
				array_keys( $one ),
				static function ( $k ) {
					return '@type' !== $k;
				}
			);
			if ( empty( $meaningful ) ) {
				unset( $fields['offers'] );
			} else {
				$fields['offers'] = $one;
			}
		}

		if ( isset( $fields['previousStartDate'] ) ) {
			$prev = $fields['previousStartDate'];
			if ( is_string( $prev ) ) {
				$prev = array( $prev );
			}
			if ( is_array( $prev ) ) {
				$clean = array();
				foreach ( $prev as $p ) {
					$p = trim( (string) $p );
					if ( '' !== $p ) {
						$clean[] = $p;
					}
				}
				if ( empty( $clean ) ) {
					unset( $fields['previousStartDate'] );
				} elseif ( count( $clean ) === 1 ) {
					$fields['previousStartDate'] = $clean[0];
				} else {
					$fields['previousStartDate'] = $clean;
				}
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * Normalize FAQPage: mainEntity as Question / Answer pairs; optional isPartOf and mainEntityOfPage.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_faq_page_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'FAQPage';

		if ( isset( $fields['mainEntity'] ) ) {
			$list = $fields['mainEntity'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'Question' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$qname = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				$atext = '';
				if ( isset( $row['answerText'] ) ) {
					$atext = trim( (string) $row['answerText'] );
				} elseif ( isset( $row['acceptedAnswer'] ) && is_array( $row['acceptedAnswer'] ) ) {
					$aa = $row['acceptedAnswer'];
					if ( isset( $aa['text'] ) ) {
						$atext = trim( (string) $aa['text'] );
					}
				}
				if ( '' === $qname || '' === $atext ) {
					continue;
				}
				$item = array(
					'@type'          => 'Question',
					'name'           => $qname,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $atext,
					),
				);
				$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
				if ( '' !== $url ) {
					$item['url'] = $url;
				}
				$img = isset( $row['image'] ) ? trim( (string) $row['image'] ) : '';
				if ( '' !== $img ) {
					$item['image'] = $img;
				}
				$clean[] = $item;
			}
			if ( empty( $clean ) ) {
				unset( $fields['mainEntity'] );
			} else {
				$fields['mainEntity'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		if ( isset( $fields['isPartOf'] ) ) {
			$ip = trim( (string) $fields['isPartOf'] );
			if ( '' === $ip ) {
				unset( $fields['isPartOf'] );
			} else {
				$fields['isPartOf'] = $ip;
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * Normalize HowTo: HowToStep / HowToSupply / HowToTool lists and scalar fields.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_how_to_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'HowTo';

		if ( isset( $fields['step'] ) ) {
			$list = $fields['step'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'HowToStep' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			$pos   = 1;
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$text = isset( $row['text'] ) ? trim( (string) $row['text'] ) : '';
				if ( '' === $text ) {
					continue;
				}
				$one = array(
					'@type'    => 'HowToStep',
					'text'     => $text,
					'position' => $pos,
				);
				++$pos;
				$sname = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' !== $sname ) {
					$one['name'] = $sname;
				}
				foreach ( array( 'image', 'video', 'url' ) as $prop ) {
					if ( isset( $row[ $prop ] ) ) {
						$pv = trim( (string) $row[ $prop ] );
						if ( '' !== $pv ) {
							$one[ $prop ] = $pv;
						}
					}
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['step'] );
			} else {
				$fields['step'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		foreach ( array(
		'supply' => 'HowToSupply',
		'tool'   => 'HowToTool'
		) as $prop => $stype ) {
			if ( ! isset( $fields[ $prop ] ) ) {
				continue;
			}
			$list = $fields[ $prop ];
			if ( is_array( $list ) && isset( $list['@type'] ) && $stype === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$n = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' === $n ) {
					continue;
				}
				$clean[] = array(
					'@type' => $stype,
					'name'  => $n,
				);
			}
			if ( empty( $clean ) ) {
				unset( $fields[ $prop ] );
			} else {
				$fields[ $prop ] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		foreach ( array( 'totalTime', 'estimatedCost' ) as $scalar ) {
			if ( isset( $fields[ $scalar ] ) ) {
				$v = trim( (string) $fields[ $scalar ] );
				if ( '' === $v ) {
					unset( $fields[ $scalar ] );
				} else {
					$fields[ $scalar ] = $v;
				}
			}
		}

		if ( isset( $fields['isPartOf'] ) ) {
			$ip = trim( (string) $fields['isPartOf'] );
			if ( '' === $ip ) {
				unset( $fields['isPartOf'] );
			} else {
				$fields['isPartOf'] = $ip;
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * Normalize Recipe: HowToStep instructions, ingredients, nutrition, author, durations.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_recipe_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'Recipe';

		if ( isset( $fields['author'] ) && is_array( $fields['author'] ) ) {
			$n = isset( $fields['author']['name'] ) ? trim( wp_strip_all_tags( (string) $fields['author']['name'] ) ) : '';
			if ( '' === $n ) {
				unset( $fields['author'] );
			} else {
				$fields['author'] = array(
					'@type' => 'Person',
					'name'  => $n,
				);
			}
		}

		foreach ( array( 'prepTime', 'cookTime', 'totalTime' ) as $dk ) {
			if ( ! isset( $fields[ $dk ] ) ) {
				continue;
			}
			$raw = trim( (string) $fields[ $dk ] );
			if ( '' === $raw ) {
				unset( $fields[ $dk ] );
				continue;
			}
			if ( preg_match( '/^\d+$/', $raw ) ) {
				$fields[ $dk ] = 'PT' . (int) $raw . 'M';
			} else {
				$fields[ $dk ] = $raw;
			}
		}

		if ( isset( $fields['recipeIngredient'] ) && is_array( $fields['recipeIngredient'] ) ) {
			$lines = array();
			foreach ( $fields['recipeIngredient'] as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
			if ( empty( $lines ) ) {
				unset( $fields['recipeIngredient'] );
			} elseif ( 1 === count( $lines ) ) {
				$fields['recipeIngredient'] = $lines[0];
			} else {
				$fields['recipeIngredient'] = $lines;
			}
		}

		if ( isset( $fields['recipeInstructions'] ) ) {
			$list = $fields['recipeInstructions'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'HowToStep' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			$pos   = 1;
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$text = isset( $row['text'] ) ? trim( (string) $row['text'] ) : '';
				if ( '' === $text ) {
					continue;
				}
				$one = array(
					'@type'    => 'HowToStep',
					'text'     => $text,
					'position' => $pos,
				);
				++$pos;
				$sname = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' !== $sname ) {
					$one['name'] = $sname;
				}
				foreach ( array( 'image', 'video', 'url' ) as $prop ) {
					if ( isset( $row[ $prop ] ) ) {
						$pv = trim( (string) $row[ $prop ] );
						if ( '' !== $pv ) {
							$one[ $prop ] = $pv;
						}
					}
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['recipeInstructions'] );
			} else {
				$fields['recipeInstructions'] = count( $clean ) === 1 ? $clean[0] : $clean;
			}
		}

		if ( isset( $fields['nutrition'] ) && is_array( $fields['nutrition'] ) ) {
			$nut = array( '@type' => 'NutritionInformation' );
			foreach ( array( 'calories', 'fatContent', 'carbohydrateContent', 'proteinContent', 'fiberContent', 'sugarContent', 'sodiumContent' ) as $nk ) {
				if ( isset( $fields['nutrition'][ $nk ] ) ) {
					$v = trim( (string) $fields['nutrition'][ $nk ] );
					if ( '' !== $v ) {
						$nut[ $nk ] = $v;
					}
				}
			}
			if ( count( $nut ) <= 1 ) {
				unset( $fields['nutrition'] );
			} else {
				$fields['nutrition'] = $nut;
			}
		}

		if ( isset( $fields['aggregateRating'] ) && is_array( $fields['aggregateRating'] ) ) {
			$rv = isset( $fields['aggregateRating']['ratingValue'] ) ? trim( (string) $fields['aggregateRating']['ratingValue'] ) : '';
			$rc = isset( $fields['aggregateRating']['reviewCount'] ) ? trim( (string) $fields['aggregateRating']['reviewCount'] ) : '';
			if ( '' === $rv && '' === $rc ) {
				unset( $fields['aggregateRating'] );
			} else {
				$fields['aggregateRating']['@type'] = 'AggregateRating';
				if ( '' !== $rv && is_numeric( $rv ) ) {
					$fields['aggregateRating']['ratingValue'] = (float) $rv;
				}
				if ( '' !== $rc && is_numeric( $rc ) ) {
					$fields['aggregateRating']['reviewCount'] = (int) $rc;
				}
			}
		}

		if ( isset( $fields['isPartOf'] ) ) {
			$ip = trim( (string) $fields['isPartOf'] );
			if ( '' === $ip ) {
				unset( $fields['isPartOf'] );
			} else {
				$fields['isPartOf'] = $ip;
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * Normalize SoftwareApplication: @type list, Offer, category, play mode, scalars.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_software_application_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}

		$extra_allow = array( 'MobileApplication', 'WebApplication', 'VideoGame' );
		$extras      = array();
		if ( isset( $fields['_software_additional_types'] ) && is_array( $fields['_software_additional_types'] ) ) {
			foreach ( $fields['_software_additional_types'] as $x ) {
				$x = trim( (string) $x );
				if ( in_array( $x, $extra_allow, true ) ) {
					$extras[] = $x;
				}
			}
			$extras = array_values( array_unique( $extras ) );
		}
		unset( $fields['_software_additional_types'] );

		$types = array( 'SoftwareApplication' );
		foreach ( $extras as $e ) {
			$types[] = $e;
		}
		$types = array_values( array_unique( $types ) );
		if ( count( $types ) === 1 ) {
			$fields['@type'] = 'SoftwareApplication';
		} else {
			$fields['@type'] = $types;
		}

		$cat_allow = array(
			'GameApplication',
			'SocialNetworkingApplication',
			'TravelApplication',
			'ShoppingApplication',
			'SportsApplication',
			'LifestyleApplication',
			'BusinessApplication',
			'DesignApplication',
			'DeveloperApplication',
			'DriverApplication',
			'EducationalApplication',
			'HealthApplication',
			'FinanceApplication',
			'SecurityApplication',
			'BrowserApplication',
			'CommunicationApplication',
			'DesktopEnhancementApplication',
			'EntertainmentApplication',
			'MultimediaApplication',
			'HomeApplication',
			'UtilitiesApplication',
			'ReferenceApplication',
		);
		if ( isset( $fields['applicationCategory'] ) ) {
			$c = trim( (string) $fields['applicationCategory'] );
			if ( '' === $c || ! in_array( $c, $cat_allow, true ) ) {
				unset( $fields['applicationCategory'] );
			} else {
				$fields['applicationCategory'] = $c;
			}
		}

		$play_allow = array( 'CoOp', 'MultiPlayer', 'SinglePlayer' );
		if ( isset( $fields['playMode'] ) ) {
			$pm = trim( (string) $fields['playMode'] );
			if ( '' === $pm || ! in_array( $pm, $play_allow, true ) ) {
				unset( $fields['playMode'] );
			} else {
				$fields['playMode'] = $pm;
			}
		}

		$payment_allow = array(
			'http://purl.org/goodrelations/v1#ByBankTransferInAdvance',
			'http://purl.org/goodrelations/v1#ByInvoice',
			'http://purl.org/goodrelations/v1#Cash',
			'http://purl.org/goodrelations/v1#CheckInAdvance',
			'http://purl.org/goodrelations/v1#COD',
			'http://purl.org/goodrelations/v1#DirectDebit',
			'http://purl.org/goodrelations/v1#GoogleCheckout',
			'http://purl.org/goodrelations/v1#PayPal',
			'http://purl.org/goodrelations/v1#PaySwarm',
		);

		if ( isset( $fields['offers'] ) && is_array( $fields['offers'] ) ) {
			$o   = $fields['offers'];
			$one = array( '@type' => 'Offer' );
			foreach ( array( 'name', 'priceCurrency', 'priceValidUntil', 'seller', 'serialNumber', 'sku' ) as $prop ) {
				if ( isset( $o[ $prop ] ) ) {
					$pv = trim( (string) $o[ $prop ] );
					if ( '' !== $pv ) {
						$one[ $prop ] = $pv;
					}
				}
			}
			if ( isset( $o['price'] ) ) {
				$pr = trim( (string) $o['price'] );
				if ( '' !== $pr ) {
					$one['price'] = is_numeric( $pr ) ? (float) $pr : $pr;
				}
			}
			if ( isset( $o['acceptedPaymentMethod'] ) ) {
				$pm = trim( (string) $o['acceptedPaymentMethod'] );
				if ( in_array( $pm, $payment_allow, true ) ) {
					$one['acceptedPaymentMethod'] = $pm;
				}
			}
			$meaningful = array_filter(
				array_keys( $one ),
				static function ( $k ) {
					return '@type' !== $k;
				}
			);
			if ( empty( $meaningful ) ) {
				unset( $fields['offers'] );
			} else {
				$fields['offers'] = $one;
			}
		}

		$scalar_trim = array(
			'name',
			'carrierRequirements',
			'browserRequirements',
			'actor',
			'director',
			'cheatCode',
			'gameEdition',
			'gamePlatform',
			'musicBy',
			'trailer',
			'applicationSubCategory',
			'applicationSuite',
			'availableOnDevice',
			'countriesSupported',
			'countriesNotSupported',
			'downloadUrl',
			'featureList',
			'fileSize',
			'installUrl',
			'memoryRequirements',
			'releaseNotes',
			'operatingSystem',
			'permissions',
			'processorRequirements',
			'screenshot',
			'softwareRequirements',
			'softwareVersion',
			'storageRequirements',
		);
		foreach ( $scalar_trim as $sk ) {
			if ( ! isset( $fields[ $sk ] ) ) {
				continue;
			}
			$v = trim( (string) $fields[ $sk ] );
			if ( '' === $v ) {
				unset( $fields[ $sk ] );
			} else {
				$fields[ $sk ] = $v;
			}
		}

		if ( isset( $fields['isPartOf'] ) ) {
			$ip = trim( (string) $fields['isPartOf'] );
			if ( '' === $ip ) {
				unset( $fields['isPartOf'] );
			} else {
				$fields['isPartOf'] = $ip;
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * Normalize VideoObject: InteractionCounter, publisher, author, booleans, duration.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_video_object_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'VideoObject';

		if ( isset( $fields['duration'] ) ) {
			$raw = trim( (string) $fields['duration'] );
			if ( '' === $raw ) {
				unset( $fields['duration'] );
			} elseif ( preg_match( '/^\d+$/', $raw ) ) {
				$fields['duration'] = 'PT' . (int) $raw . 'M';
			} else {
				$fields['duration'] = $raw;
			}
		}

		$interaction_allow = array(
			'http://schema.org/WatchAction',
			'http://schema.org/LikeAction',
			'http://schema.org/CommentAction',
			'https://schema.org/WatchAction',
			'https://schema.org/LikeAction',
			'https://schema.org/CommentAction',
		);
		if ( isset( $fields['interactionStatistic'] ) && is_array( $fields['interactionStatistic'] ) ) {
			$b   = $fields['interactionStatistic'];
			$it  = isset( $b['interactionType'] ) ? trim( (string) $b['interactionType'] ) : '';
			$uc  = isset( $b['userInteractionCount'] ) ? trim( (string) $b['userInteractionCount'] ) : '';
			$one = array( '@type' => 'InteractionCounter' );
			$has = false;
			if ( '' !== $it && in_array( $it, $interaction_allow, true ) ) {
				$one['interactionType'] = $it;
				$has                    = true;
			}
			if ( '' !== $uc ) {
				$has = true;
				if ( is_numeric( $uc ) ) {
					$one['userInteractionCount'] = (int) $uc;
				} else {
					$one['userInteractionCount'] = $uc;
				}
			}
			if ( $has && ! isset( $one['interactionType'] ) && '' !== $uc ) {
				$one['interactionType'] = 'https://schema.org/WatchAction';
			}
			if ( ! $has || ( count( $one ) <= 1 ) ) {
				unset( $fields['interactionStatistic'] );
			} else {
				$fields['interactionStatistic'] = $one;
			}
		}

		if ( isset( $fields['publisher'] ) && is_array( $fields['publisher'] ) ) {
			$pn = isset( $fields['publisher']['name'] ) ? trim( wp_strip_all_tags( (string) $fields['publisher']['name'] ) ) : '';
			if ( '' === $pn ) {
				unset( $fields['publisher'] );
			} else {
				$pub = array(
					'@type' => 'Organization',
					'name'  => $pn,
				);
				$lg  = isset( $fields['publisher']['logo'] ) ? trim( (string) $fields['publisher']['logo'] ) : '';
				if ( '' !== $lg ) {
					$pub['logo'] = $lg;
				}
				$fields['publisher'] = $pub;
			}
		}

		if ( isset( $fields['author'] ) && is_array( $fields['author'] ) ) {
			$an = isset( $fields['author']['name'] ) ? trim( wp_strip_all_tags( (string) $fields['author']['name'] ) ) : '';
			if ( '' === $an ) {
				unset( $fields['author'] );
			} else {
				$auth = array(
					'@type' => 'Person',
					'name'  => $an,
				);
				$au   = isset( $fields['author']['url'] ) ? trim( (string) $fields['author']['url'] ) : '';
				if ( '' !== $au ) {
					$auth['url'] = $au;
				}
				$fields['author'] = $auth;
			}
		}

		$quality_allow = array( 'HD', 'SD', '4K', '8K' );
		if ( isset( $fields['videoQuality'] ) ) {
			$vq = trim( (string) $fields['videoQuality'] );
			if ( '' === $vq || ! in_array( $vq, $quality_allow, true ) ) {
				unset( $fields['videoQuality'] );
			} else {
				$fields['videoQuality'] = $vq;
			}
		}

		foreach ( array( 'requiresSubscription', 'isFamilyFriendly' ) as $bk ) {
			if ( ! isset( $fields[ $bk ] ) ) {
				continue;
			}
			$bv = trim( (string) $fields[ $bk ] );
			if ( 'true' === $bv ) {
				$fields[ $bk ] = true;
			} elseif ( 'false' === $bv ) {
				$fields[ $bk ] = false;
			} else {
				unset( $fields[ $bk ] );
			}
		}

		$rating_allow = array( 'G', 'PG', 'PG-13', 'R', 'NC-17' );
		if ( isset( $fields['contentRating'] ) ) {
			$cr = trim( (string) $fields['contentRating'] );
			if ( '' === $cr || ! in_array( $cr, $rating_allow, true ) ) {
				unset( $fields['contentRating'] );
			} else {
				$fields['contentRating'] = $cr;
			}
		}

		$scalar_trim = array(
			'name',
			'description',
			'thumbnailUrl',
			'uploadDate',
			'contentUrl',
			'embedUrl',
			'genre',
			'keywords',
			'videoFrameSize',
			'inLanguage',
			'transcript',
			'caption',
			'expires',
			'regionsAllowed',
		);
		foreach ( $scalar_trim as $sk ) {
			if ( ! isset( $fields[ $sk ] ) ) {
				continue;
			}
			$v = trim( (string) $fields[ $sk ] );
			if ( '' === $v ) {
				unset( $fields[ $sk ] );
			} else {
				$fields[ $sk ] = $v;
			}
		}

		if ( isset( $fields['isPartOf'] ) ) {
			$ip = trim( (string) $fields['isPartOf'] );
			if ( '' === $ip ) {
				unset( $fields['isPartOf'] );
			} else {
				$fields['isPartOf'] = $ip;
			}
		}

		if ( isset( $fields['mainEntityOfPage'] ) ) {
			$mep = trim( (string) $fields['mainEntityOfPage'] );
			if ( '' === $mep ) {
				unset( $fields['mainEntityOfPage'] );
			} else {
				$fields['mainEntityOfPage'] = $mep;
			}
		}
	}

	/**
	 * @return string[]
	 */
	private static function get_local_business_day_of_week_urls() {
		return array(
			'https://schema.org/Monday',
			'https://schema.org/Tuesday',
			'https://schema.org/Wednesday',
			'https://schema.org/Thursday',
			'https://schema.org/Friday',
			'https://schema.org/Saturday',
			'https://schema.org/Sunday',
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	private static function local_business_postal_address_from_flat_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$street   = isset( $row['streetAddress'] ) ? trim( (string) $row['streetAddress'] ) : '';
		$locality = isset( $row['addressLocality'] ) ? trim( (string) $row['addressLocality'] ) : '';
		$region   = isset( $row['addressRegion'] ) ? trim( (string) $row['addressRegion'] ) : '';
		$postal   = isset( $row['postalCode'] ) ? trim( (string) $row['postalCode'] ) : '';
		$country  = isset( $row['addressCountry'] ) ? trim( (string) $row['addressCountry'] ) : '';
		if ( '' === $street && '' === $locality && '' === $region && '' === $postal && '' === $country ) {
			return null;
		}
		$addr = array( '@type' => 'PostalAddress' );
		if ( '' !== $street ) {
			$addr['streetAddress'] = $street;
		}
		if ( '' !== $locality ) {
			$addr['addressLocality'] = $locality;
		}
		if ( '' !== $region ) {
			$addr['addressRegion'] = $region;
		}
		if ( '' !== $postal ) {
			$addr['postalCode'] = $postal;
		}
		if ( '' !== $country ) {
			$addr['addressCountry'] = $country;
		}
		return $addr;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	private static function local_business_place_from_storage_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$ptype = isset( $row['placeType'] ) ? sanitize_text_field( (string) $row['placeType'] ) : 'Place';
		if ( ! in_array( $ptype, array( 'Place', 'VirtualLocation' ), true ) ) {
			$ptype = 'Place';
		}
		$name = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
		$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		$addr = self::local_business_postal_address_from_flat_row( $row );
		if ( 'VirtualLocation' === $ptype ) {
			if ( '' === $name && '' === $url && null === $addr ) {
				return null;
			}
			$out = array( '@type' => 'VirtualLocation' );
			if ( '' !== $name ) {
				$out['name'] = $name;
			}
			if ( '' !== $url ) {
				$out['url'] = $url;
			}
			if ( null !== $addr ) {
				$out['address'] = $addr;
			}
			return $out;
		}
		if ( '' === $name && '' === $url && null === $addr ) {
			return null;
		}
		$out = array( '@type' => 'Place' );
		if ( '' !== $name ) {
			$out['name'] = $name;
		}
		if ( '' !== $url ) {
			$out['url'] = $url;
		}
		if ( null !== $addr ) {
			$out['address'] = $addr;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param string               $prop
	 */
	private static function finalize_local_business_person_name_list( &$fields, $prop ) {
		if ( ! isset( $fields[ $prop ] ) || ! is_array( $fields[ $prop ] ) ) {
			return;
		}
		$list = $fields[ $prop ];
		if ( ! self::array_is_zero_indexed_list( $list ) ) {
			$list = array( $list );
		}
		$clean = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = isset( $item['name'] ) ? trim( wp_strip_all_tags( (string) $item['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			$clean[] = array(
			'@type' => 'Person',
			'name'  => $name
			);
		}
		if ( empty( $clean ) ) {
			unset( $fields[ $prop ] );
		} elseif ( 1 === count( $clean ) ) {
			$fields[ $prop ] = $clean[0];
		} else {
			$fields[ $prop ] = $clean;
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param string               $key
	 */
	private static function finalize_local_business_place_list_key( &$fields, $key ) {
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}
		$list = $fields[ $key ];
		if ( is_array( $list ) && isset( $list['@type'] ) ) {
			$list = array( $list );
		} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
			$list = array( $list );
		}
		$clean = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$p = self::local_business_place_from_storage_row( $row );
			if ( null !== $p ) {
				$clean[] = $p;
			}
		}
		if ( empty( $clean ) ) {
			unset( $fields[ $key ] );
		} elseif ( 1 === count( $clean ) ) {
			$fields[ $key ] = $clean[0];
		} else {
			$fields[ $key ] = $clean;
		}
	}

	/**
	 * Normalize LocalBusiness (lists, address, geo, opening hours).
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_local_business_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$allowed = self::get_local_business_schema_type_allowed_values();
		$t       = isset( $fields['@type'] ) ? trim( (string) $fields['@type'] ) : '';
		if ( '' === $t || ! in_array( $t, $allowed, true ) ) {
			$fields['@type'] = 'LocalBusiness';
		}

		if ( isset( $fields['address'] ) && is_array( $fields['address'] ) ) {
			$addr = self::local_business_postal_address_from_flat_row( $fields['address'] );
			if ( null === $addr ) {
				unset( $fields['address'] );
			} else {
				$fields['address'] = $addr;
			}
		}

		if ( isset( $fields['geo'] ) && is_array( $fields['geo'] ) ) {
			$lat = isset( $fields['geo']['latitude'] ) ? trim( (string) $fields['geo']['latitude'] ) : '';
			$lng = isset( $fields['geo']['longitude'] ) ? trim( (string) $fields['geo']['longitude'] ) : '';
			if ( '' === $lat || '' === $lng ) {
				unset( $fields['geo'] );
			} else {
				$fields['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $lat,
					'longitude' => $lng,
				);
			}
		}

		if ( isset( $fields['acceptsReservations'] ) ) {
			$v = trim( (string) $fields['acceptsReservations'] );
			if ( 'True' === $v ) {
				$fields['acceptsReservations'] = true;
			} elseif ( 'False' === $v ) {
				$fields['acceptsReservations'] = false;
			} else {
				unset( $fields['acceptsReservations'] );
			}
		}

		$day_urls = self::get_local_business_day_of_week_urls();
		if ( isset( $fields['openingHoursSpecification'] ) ) {
			$list = $fields['openingHoursSpecification'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'OpeningHoursSpecification' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$days_raw = isset( $row['dayOfWeek'] ) ? $row['dayOfWeek'] : array();
				if ( is_string( $days_raw ) ) {
					$days_raw = array_filter( array_map( 'trim', explode( ',', $days_raw ) ) );
				}
				if ( ! is_array( $days_raw ) ) {
					$days_raw = array();
				}
				$days = array();
				foreach ( $days_raw as $d ) {
					$d = trim( (string) $d );
					if ( in_array( $d, $day_urls, true ) ) {
						$days[] = $d;
					}
				}
				$opens  = isset( $row['opens'] ) ? trim( (string) $row['opens'] ) : '';
				$closes = isset( $row['closes'] ) ? trim( (string) $row['closes'] ) : '';
				if ( empty( $days ) || ( '' === $opens && '' === $closes ) ) {
					continue;
				}
				$one = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => 1 === count( $days ) ? $days[0] : $days,
				);
				if ( '' !== $opens ) {
					$one['opens'] = $opens;
				}
				if ( '' !== $closes ) {
					$one['closes'] = $closes;
				}
				$clean[] = $one;
			}
			if ( empty( $clean ) ) {
				unset( $fields['openingHoursSpecification'] );
			} elseif ( 1 === count( $clean ) ) {
				$fields['openingHoursSpecification'] = $clean[0];
			} else {
				$fields['openingHoursSpecification'] = $clean;
			}
		}

		foreach ( array( 'image' ) as $img_key ) {
			if ( ! isset( $fields[ $img_key ] ) || ! is_array( $fields[ $img_key ] ) ) {
				continue;
			}
			$imgs = array();
			foreach ( $fields[ $img_key ] as $im ) {
				$im = trim( (string) $im );
				if ( '' !== $im ) {
					$imgs[] = $im;
				}
			}
			if ( empty( $imgs ) ) {
				unset( $fields[ $img_key ] );
			} elseif ( 1 === count( $imgs ) ) {
				$fields[ $img_key ] = $imgs[0];
			} else {
				$fields[ $img_key ] = $imgs;
			}
		}

		foreach ( array( 'additionalType', 'department', 'owns' ) as $sl ) {
			if ( ! isset( $fields[ $sl ] ) || ! is_array( $fields[ $sl ] ) ) {
				continue;
			}
			$lines = array();
			foreach ( $fields[ $sl ] as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
			if ( empty( $lines ) ) {
				unset( $fields[ $sl ] );
			} elseif ( 1 === count( $lines ) ) {
				$fields[ $sl ] = $lines[0];
			} else {
				$fields[ $sl ] = $lines;
			}
		}

		if ( isset( $fields['brand'] ) ) {
			$list = $fields['brand'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'Brand' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$n = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' === $n ) {
					continue;
				}
				$b = array(
				'@type' => 'Brand',
				'name'  => $n
				);
				if ( isset( $row['logo'] ) ) {
					$lg = trim( (string) $row['logo'] );
					if ( '' !== $lg ) {
						$b['logo'] = $lg;
					}
				}
				if ( isset( $row['url'] ) ) {
					$u = trim( (string) $row['url'] );
					if ( '' !== $u ) {
						$b['url'] = $u;
					}
				}
				$clean[] = $b;
			}
			if ( empty( $clean ) ) {
				unset( $fields['brand'] );
			} elseif ( 1 === count( $clean ) ) {
				$fields['brand'] = $clean[0];
			} else {
				$fields['brand'] = $clean;
			}
		}

		$contact_opts = array(
			'https://schema.org/TollFree',
			'https://schema.org/HearingImpairedSupported',
		);
		if ( isset( $fields['contactPoint'] ) ) {
			$list = $fields['contactPoint'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'ContactPoint' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$em = isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';
				if ( '' === $em ) {
					continue;
				}
				$cp = array(
					'@type' => 'ContactPoint',
					'email' => $em,
				);
				foreach ( array( 'contactType', 'telephone', 'faxNumber', 'areaServed', 'productSupported' ) as $ck ) {
					if ( isset( $row[ $ck ] ) ) {
						$cv = trim( (string) $row[ $ck ] );
						if ( '' !== $cv ) {
							$cp[ $ck ] = $cv;
						}
					}
				}
				if ( isset( $row['contactOption'] ) ) {
					$co = trim( (string) $row['contactOption'] );
					if ( in_array( $co, $contact_opts, true ) ) {
						$cp['contactOption'] = $co;
					}
				}
				$clean[] = $cp;
			}
			if ( empty( $clean ) ) {
				unset( $fields['contactPoint'] );
			} elseif ( 1 === count( $clean ) ) {
				$fields['contactPoint'] = $clean[0];
			} else {
				$fields['contactPoint'] = $clean;
			}
		}

		if ( isset( $fields['employee'] ) ) {
			$list = $fields['employee'];
			if ( is_array( $list ) && isset( $list['@type'] ) && 'Person' === $list['@type'] ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$clean = array();
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$n = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
				if ( '' === $n ) {
					continue;
				}
				$p = array(
				'@type' => 'Person',
				'name'  => $n
				);
				foreach ( array( 'jobTitle', 'email', 'telephone' ) as $ek ) {
					if ( isset( $row[ $ek ] ) ) {
						$ev = trim( (string) $row[ $ek ] );
						if ( '' !== $ev ) {
							$p[ $ek ] = $ev;
						}
					}
				}
				$clean[] = $p;
			}
			if ( empty( $clean ) ) {
				unset( $fields['employee'] );
			} elseif ( 1 === count( $clean ) ) {
				$fields['employee'] = $clean[0];
			} else {
				$fields['employee'] = $clean;
			}
		}

		foreach ( array( 'founder', 'funder', 'sponsor', 'alumni' ) as $pp ) {
			self::finalize_local_business_person_name_list( $fields, $pp );
		}

		self::finalize_local_business_place_list_key( $fields, 'location' );
		self::finalize_local_business_place_list_key( $fields, 'hasPOS' );

		if ( isset( $fields['foundingLocation'] ) ) {
			$list = $fields['foundingLocation'];
			if ( is_array( $list ) && isset( $list['@type'] ) ) {
				$list = array( $list );
			} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
				$list = array( $list );
			}
			$set = false;
			foreach ( $list as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$p = self::local_business_place_from_storage_row( $row );
				if ( null !== $p ) {
					$fields['foundingLocation'] = $p;
					$set                        = true;
					break;
				}
			}
			if ( ! $set ) {
				unset( $fields['foundingLocation'] );
			}
		}

		if ( isset( $fields['numberOfEmployees'] ) && is_array( $fields['numberOfEmployees'] ) ) {
			$val = isset( $fields['numberOfEmployees']['value'] ) ? trim( (string) $fields['numberOfEmployees']['value'] ) : '';
			if ( '' === $val ) {
				unset( $fields['numberOfEmployees'] );
			} else {
				$fields['numberOfEmployees'] = array(
					'@type' => 'QuantitativeValue',
					'value' => $val,
				);
			}
		}

		$trim_scalars = array(
			'name',
		'description',
		'url',
		'logo',
		'servesCuisine',
		'areaServed',
		'hasMap',
			'currenciesAccepted',
		'paymentAccepted',
		'priceRange',
		'telephone',
		'email',
		'faxNumber',
			'parentOrganization',
		'duns',
		'globalLocationNumber',
		'isicV4',
		'iso6523Code',
		'leiCode',
			'naics',
		'taxID',
		'vatID',
		'award',
		'foundingDate',
		'keywords',
		'knowsLanguage',
			'legalName',
		'slogan',
		'mainEntityOfPage',
		);
		foreach ( $trim_scalars as $sk ) {
			if ( ! isset( $fields[ $sk ] ) ) {
				continue;
			}
			$v = trim( (string) $fields[ $sk ] );
			if ( '' === $v ) {
				unset( $fields[ $sk ] );
			} else {
				$fields[ $sk ] = $v;
			}
		}

		self::finalize_schema_same_as_urls( $fields );
	}

	/**
	 * Normalize ListItem-shaped rows for BreadcrumbList itemListElement.
	 *
	 * @param array $list Raw list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_breadcrumb_list_items_array( $list ) {
		$out = array();
		foreach ( (array) $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
			$item = isset( $row['item'] ) ? $row['item'] : '';
			if ( is_array( $item ) && isset( $item['@id'] ) ) {
				$item = trim( (string) $item['@id'] );
			} else {
				$item = trim( (string) $item );
			}
			if ( '' === $name && '' === $item ) {
				continue;
			}
			$out[] = array(
				'@type' => 'ListItem',
				'name'  => $name,
				'item'  => $item,
			);
		}
		foreach ( $out as $i => &$row ) {
			$row['position'] = $i + 1;
		}
		unset( $row );
		return $out;
	}

	/**
	 * Merge key/value custom rows into the schema node; remove storage key.
	 *
	 * @param array $fields Fields (by reference).
	 */
	private static function merge_breadcrumb_custom_fields_into_node( &$fields ) {
		if ( empty( $fields['customFields'] ) || ! is_array( $fields['customFields'] ) ) {
			unset( $fields['customFields'] );
			return;
		}
		$reserved = array( '@type', '@id', '@context', 'itemListElement', 'customFields', 'name', 'breadcrumbJsonFallback' );
		foreach ( $fields['customFields'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = isset( $row['key'] ) ? trim( (string) $row['key'] ) : '';
			if ( '' === $k || ! preg_match( '/^[a-zA-Z0-9@._-]+$/', $k ) ) {
				continue;
			}
			if ( in_array( $k, $reserved, true ) ) {
				continue;
			}
			$val = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
			if ( '' === $val ) {
				continue;
			}
			$fields[ $k ] = $val;
		}
		unset( $fields['customFields'] );
	}

	/**
	 * Remove @id references pointing to nodes not present in the graph, so the JSON-LD never
	 * contains a dangling reference (e.g. isPartOf → #webpage when no WebPage node was emitted).
	 *
	 * @param array $nodes Graph nodes.
	 * @return array
	 */
	private static function prune_dangling_references( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return $nodes;
		}
		$present = array();
		foreach ( $nodes as $n ) {
			if ( is_array( $n ) && isset( $n['@id'] ) && is_string( $n['@id'] ) ) {
				$present[ $n['@id'] ] = true;
			}
		}
		$out = array();
		foreach ( $nodes as $node ) {
			$out[] = self::strip_dangling_refs( $node, $present );
		}
		return $out;
	}

	/**
	 * Recursively drop pure {@id} references whose target is absent from $present.
	 *
	 * @param mixed $value   Node or sub-value.
	 * @param array $present Set of @id strings present in the graph.
	 * @return mixed
	 */
	private static function strip_dangling_refs( $value, $present ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( ! is_array( $v ) ) {
				$out[ $k ] = $v;
				continue;
			}
			if ( self::is_reference_array( $v ) ) {
				if ( isset( $present[ $v['@id'] ] ) ) {
					$out[ $k ] = $v;
				}
				continue; // Drop a dangling single reference.
			}
			if ( self::array_is_zero_indexed_list( $v ) ) {
				$list = array();
				foreach ( $v as $item ) {
					if ( is_array( $item ) && self::is_reference_array( $item ) ) {
						if ( isset( $present[ $item['@id'] ] ) ) {
							$list[] = $item;
						}
						continue;
					}
					$list[] = is_array( $item ) ? self::strip_dangling_refs( $item, $present ) : $item;
				}
				$out[ $k ] = array_values( $list );
				continue;
			}
			$out[ $k ] = self::strip_dangling_refs( $v, $present );
		}
		return $out;
	}

	/**
	 * Whether a value is a pure JSON-LD reference object: exactly { "@id": "<string>" }.
	 *
	 * @param mixed $a Value.
	 * @return bool
	 */
	private static function is_reference_array( $a ) {
		return is_array( $a ) && count( $a ) === 1 && isset( $a['@id'] ) && is_string( $a['@id'] );
	}

	/**
	 * Finalize WebSite: guarantee an absolute url and a nested SearchAction (sitelinks
	 * searchbox) with an absolute target, so a separate top-level SearchAction node is never
	 * required and the WebSite is structurally valid.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_website_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		if ( empty( $fields['url'] ) ) {
			$fields['url'] = home_url( '/' );
		}
		// Guarantee a name so the WebSite node is never dropped (Google requires WebSite.name).
		// When blogname is empty (%site.title% → ''), fall back to the site host, then home URL —
		// this keeps the sitelinks-searchbox WebSite present even on unconfigured installs.
		if ( empty( $fields['name'] ) ) {
			$host           = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$fields['name'] = get_bloginfo( 'name' ) ?: ( $host ? $host : home_url( '/' ) );
		}
		if ( empty( $fields['potentialAction'] ) ) {
			$search_url                = apply_filters(
				'nexter_content_seo_search_action_target_url',
				home_url( '/' ) . '?s={search_term_string}'
			);
			$fields['potentialAction'] = array(
				array(
					'@type'       => 'SearchAction',
					'target'      => $search_url,
					'query-input' => 'required name=search_term_string',
				),
			);
		}
	}

	/**
	 * Finalize BreadcrumbList: itemListElement, optional JSON fallback, custom key/value rows.
	 *
	 * @param array $fields Schema fields (by reference).
	 */
	private static function finalize_breadcrumb_list_fields( &$fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['@type'] = 'BreadcrumbList';

		$fallback_raw = isset( $fields['breadcrumbJsonFallback'] ) ? trim( (string) $fields['breadcrumbJsonFallback'] ) : '';
		unset( $fields['breadcrumbJsonFallback'] );

		$manual = array();
		if ( isset( $fields['itemListElement'] ) && is_array( $fields['itemListElement'] ) ) {
			$manual = self::normalize_breadcrumb_list_items_array( $fields['itemListElement'] );
		}

		if ( ! empty( $manual ) ) {
			$fields['itemListElement'] = $manual;
		} else {
			unset( $fields['itemListElement'] );
			if ( '' !== $fallback_raw ) {
				$decoded = json_decode( $fallback_raw, true );
				if ( is_array( $decoded ) ) {
					$from_fb = self::normalize_breadcrumb_list_items_array( $decoded );
					if ( ! empty( $from_fb ) ) {
						$fields['itemListElement'] = $from_fb;
					}
				}
			}
		}

		self::merge_breadcrumb_custom_fields_into_node( $fields );
	}

	/**
	 * Remove empty values from array recursively.
	 *
	 * @param array $data Data array.
	 * @return array
	 */
	private static function remove_empty( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::remove_empty( $value );
				if ( empty( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
			} elseif ( ( '' === $value || null === $value ) && 0 !== $value ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Whether the current request resolves to a noindex page (per-post/term meta, global archive
	 * flags, or a search-discouraged site). Used to suppress JSON-LD on non-indexable pages.
	 *
	 * @return bool
	 */
	private static function current_request_is_noindex() {
		if ( ! get_option( 'blog_public' ) ) {
			return true;
		}
		// Read the ACTUAL rendered robots signal, not Nexter's private opinion. `wp_robots` is the
		// core filter every SEO plugin (Nexter, Yoast, Rank Math, …) and WP core itself feed into,
		// and it's exactly what the <meta name="robots"> tag is built from. Querying Nexter's own
		// get_robots_directives() would disagree with reality whenever a competing plugin owns the
		// tag — suppressing schema on a page the competitor indexes, or emitting it on a page the
		// competitor noindexes. The merged array is associative ( e.g. [ 'noindex' => true ] ).
		$robots = apply_filters( 'wp_robots', array() );
		return is_array( $robots ) && ! empty( $robots['noindex'] );
	}

	/**
	 * Whether WooCommerce owns the Product structured data for the current request. When true,
	 * Nexter defers Product/BreadcrumbList/Review on the product page to avoid duplicate or
	 * conflicting types. WooCommerce emits Product structured data on single product pages by
	 * default; sites that disable it (e.g. remove WC_Structured_Data output) can opt Nexter back
	 * in by returning false from the filter.
	 *
	 * @return bool
	 */
	private static function woo_owns_product_schema() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		return (bool) apply_filters( 'nexter_content_seo_defer_product_schema_to_woo', class_exists( 'WooCommerce', false ) );
	}

	/**
	 * Print schema JSON-LD in wp_head.
	 */
	public static function print_schema() {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		// Defer structured data to a competing SEO plugin (Yoast / Rank Math / AIOSEO / SEOPress / …)
		// so we don't emit a duplicate JSON-LD @graph alongside theirs. Mirrors the coexistence guard
		// already applied to <title>, meta description, canonical, robots and OG/Twitter output.
		if ( class_exists( 'Nexter_Content_SEO_Description' ) && Nexter_Content_SEO_Description::other_seo_plugin_active() ) {
			return;
		}
		$options = class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::get_options() : array();
		// `disable_website_schema` should suppress only the WebSite node (and the auto-injected
		// one), NOT the whole @graph. It's applied per-node in the render loop below and gates the
		// auto-WebSite injection — it no longer early-returns and wipes Article/Product/Person/etc.
		$disable_website = ! empty( $options['disable_website_schema'] );
		// Don't emit structured data on pages excluded from search — a noindex page shouldn't be
		// advertised via schema either (matches Yoast; opt out with the filter below).
		if ( apply_filters( 'nexter_content_seo_skip_schema_on_noindex', true ) && self::current_request_is_noindex() ) {
			return;
		}
		if ( ! apply_filters( 'nexter_content_seo_print_schema', true ) ) {
			return;
		}

		// Serve cached JSON-LD for the deterministic anonymous case (singular/term, no query
		// args, not paged, logged out). Invalidated on save_post / edited_term and by the
		// config hash baked into the key.
		$cache_key = self::schema_output_cache_key();
		if ( $cache_key ) {
			// Transient (not wp_cache): persists across requests even without an external object
			// cache, so the JSON-LD is built once per 12h per URL instead of rebuilt on every
			// front-end hit on typical shared hosting. Invalidated by the save_post / edited_term
			// purges (shared key builders) and by the config hash baked into the key.
			$cached_html = get_transient( $cache_key );
			if ( is_string( $cached_html ) ) {
				echo $cached_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, JSON-LD hardened on cache write.
				return;
			}
		}

		$schemas           = self::get_active_schemas();
		$post_obj          = is_singular() ? get_post() : null;
		$base_replacements = self::get_replacements( $post_obj );
		$post_type         = '';
		$post_id           = 0;
		if ( is_singular() && $post_obj instanceof WP_Post ) {
			$post_type = (string) $post_obj->post_type;
			$post_id   = (int) $post_obj->ID;
		}
		$rendered    = array();
		$seen_at_ids = array();

		foreach ( $schemas as $schema ) {
			$skip_rules = ! empty( $schema['__nxt_skip_rules'] );
			if ( $skip_rules ) {
				unset( $schema['__nxt_skip_rules'] );
			} elseif ( ! self::validate_schema_rules( $schema, $post_type, $post_id ) ) {
				continue;
			}
			$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();
			$type   = isset( $schema['type'] ) ? $schema['type'] : 'Thing';
			// WooCommerce coexistence: on a product page where Woo emits its own structured data,
			// skip the types Woo already outputs (Product / BreadcrumbList / Review) so the page
			// doesn't carry duplicate or conflicting nodes. Opt back in via the filter in
			// woo_owns_product_schema().
			if ( self::woo_owns_product_schema() ) {
				$type_check = is_array( $type ) ? $type : array( $type );
				if ( array_intersect( array( 'Product', 'BreadcrumbList', 'Review' ), $type_check ) ) {
					continue;
				}
			}
			// disable_website_schema hides only the WebSite node (see the flag above), leaving the
			// rest of the graph intact.
			if ( $disable_website ) {
				$type_check_ws = is_array( $type ) ? $type : array( $type );
				if ( in_array( 'WebSite', $type_check_ws, true ) ) {
					continue;
				}
			}
			$replacements = $base_replacements;
			$row_id       = isset( $schema['id'] ) ? sanitize_text_field( (string) $schema['id'] ) : '';
			if ( '' !== $row_id ) {
				$replacements['%schema.item.id%'] = $row_id;
			}
			$fields = self::replace_variables_recursive( $fields, $replacements );
			$fields = self::remove_empty( $fields );
			if ( 'Organization' === $type ) {
				self::finalize_organization_fields( $fields );
			}
			if ( 'Person' === $type ) {
				self::finalize_person_fields( $fields );
			}
			if ( 'WebPage' === $type ) {
				self::finalize_web_page_fields( $fields );
			}
			if ( 'Article' === $type ) {
				self::finalize_article_fields( $fields );
			}
			if ( 'WebSite' === $type ) {
				self::finalize_website_fields( $fields );
				// WebSite requires a name (Schema.org / Google). Skip the node entirely rather
				// than emit one with an empty/missing name.
				if ( empty( $fields['name'] ) ) {
					continue;
				}
			}
			if ( 'SearchAction' === $type ) {
				// A standalone SearchAction is not a valid top-level @graph entity; the sitelinks
				// searchbox is emitted nested in the WebSite node's potentialAction (see the
				// WebSite finalizer and the auto-WebSite node below). Drop the standalone row.
				continue;
			}
			if ( 'BreadcrumbList' === $type ) {
				self::finalize_breadcrumb_list_fields( $fields );
				// BreadcrumbList requires itemListElement; skip an empty one (Google Rich Results
				// flags a BreadcrumbList with no items).
				if ( empty( $fields['itemListElement'] ) ) {
					continue;
				}
			}
			if ( 'Product' === $type ) {
				self::finalize_product_fields( $fields );
			}
			if ( 'ClaimReview' === $type ) {
				self::finalize_claim_review_fields( $fields );
			}
			if ( 'Course' === $type ) {
				self::finalize_course_fields( $fields );
			}
			if ( 'Event' === $type ) {
				self::finalize_event_fields( $fields );
			}
			if ( 'FAQPage' === $type ) {
				self::finalize_faq_page_fields( $fields );
				// FAQPage requires mainEntity (at least one Question/Answer). Skip an empty one
				// rather than emit invalid markup Google Rich Results rejects.
				if ( empty( $fields['mainEntity'] ) ) {
					continue;
				}
			}
			if ( 'QAPage' === $type && empty( $fields['mainEntity'] ) ) {
				continue;
			}
			if ( 'HowTo' === $type ) {
				self::finalize_how_to_fields( $fields );
			}
			if ( 'Recipe' === $type ) {
				self::finalize_recipe_fields( $fields );
			}
			if ( 'SoftwareApplication' === $type ) {
				self::finalize_software_application_fields( $fields );
			}
			if ( 'VideoObject' === $type ) {
				self::finalize_video_object_fields( $fields );
			}
			if ( 'LocalBusiness' === $type ) {
				self::finalize_local_business_fields( $fields );
			}
			if ( empty( $fields ) ) {
				continue;
			}
			// Advanced types (Product, Event, Recipe, etc.) must carry their Google-required
			// properties or they emit invalid structured data. The WebSite/BreadcrumbList/FAQPage/
			// QAPage gates above cover their own requirements; this catches every other type after
			// its finalizer has run, so a node with a bare @type but blank required fields is
			// dropped rather than emitted.
			if ( self::node_missing_required_fields( $type, $fields ) ) {
				continue;
			}
			$node = array_merge( array( '@type' => $type ), $fields );
			self::normalize_schema_reference_fields( $node );
			$at_id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			if ( '' !== $at_id ) {
				if ( isset( $seen_at_ids[ $at_id ] ) ) {
					continue;
				}
				$seen_at_ids[ $at_id ] = true;
			}
			$rendered[] = $node;
		}

		// Only inject the automatic WebSite node when the graph does not already contain a
		// WebSite (e.g. the seeded default_website row, which has no @id for the dedup above
		// to catch, or a user-defined WebSite). Prevents duplicate WebSite entities.
		$has_website_node = false;
		foreach ( $rendered as $rendered_node ) {
			$rn_type = isset( $rendered_node['@type'] ) ? $rendered_node['@type'] : '';
			if ( is_array( $rn_type ) ? in_array( 'WebSite', $rn_type, true ) : 'WebSite' === $rn_type ) {
				$has_website_node = true;
				break;
			}
		}

		if ( ! $has_website_node && ! $disable_website && apply_filters( 'nexter_content_seo_auto_website_schema', true ) ) {
			$search_url = isset( $base_replacements['%site.search_url%'] ) ? $base_replacements['%site.search_url%'] : home_url( '/' ) . '?s={search_term_string}';
			// Name falls back to host → home URL when blogname is empty, so the site-identity
			// WebSite (and its sitelinks searchbox) is present even on an unconfigured install.
			$auto_host    = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$auto_name    = get_bloginfo( 'name' ) ?: ( $auto_host ? $auto_host : home_url( '/' ) );
			$website_node = array(
				'@type'           => 'WebSite',
				'@id'             => home_url( '/#website' ),
				'url'             => home_url( '/' ),
				'name'            => $auto_name,
				'description'     => get_bloginfo( 'description' ),
				'potentialAction' => array(
					array(
						'@type'       => 'SearchAction',
						'target'      => $search_url,
						'query-input' => 'required name=search_term_string',
					),
				),
				'inLanguage'      => get_bloginfo( 'language' ),
			);
			$rendered[]   = $website_node;
		}

		$rendered = apply_filters( 'nexter_content_seo_schema_graph', $rendered );

		// Guarantee the WebPage↔Article linkage regardless of what the (possibly older, stored)
		// schema rows contain. Stored rows seeded by earlier versions shipped a WebPage node with
		// NO @id and an Article whose mainEntityOfPage pointed at the bare page URL (matching no
		// node) and whose isPartOf used a token that resolved empty — so BOTH refs were pruned as
		// dangling and never rendered. Here we (1) give the WebPage node a stable @id derived from
		// its own url (falling back to the home URL), then (2) point every Article node's
		// isPartOf + mainEntityOfPage at that @id. This is the Google-recommended linkage and needs
		// no data migration — it corrects existing installs and defaults alike.
		$nxt_webpage_id = '';
		foreach ( $rendered as &$nxt_node ) {
			$nxt_t = isset( $nxt_node['@type'] ) ? $nxt_node['@type'] : '';
			$nxt_is_webpage = is_array( $nxt_t ) ? in_array( 'WebPage', $nxt_t, true ) : ( 'WebPage' === $nxt_t );
			if ( $nxt_is_webpage ) {
				if ( empty( $nxt_node['@id'] ) ) {
					$nxt_base           = ! empty( $nxt_node['url'] ) ? untrailingslashit( (string) $nxt_node['url'] ) : untrailingslashit( home_url( '/' ) );
					$nxt_node['@id']    = $nxt_base . '#webpage';
				}
				$nxt_webpage_id = (string) $nxt_node['@id'];
				break;
			}
		}
		unset( $nxt_node );
		if ( '' !== $nxt_webpage_id ) {
			foreach ( $rendered as &$nxt_node2 ) {
				$nxt_t2 = isset( $nxt_node2['@type'] ) ? $nxt_node2['@type'] : '';
				$nxt_is_article = is_array( $nxt_t2 ) ? (bool) array_intersect( array( 'Article', 'NewsArticle', 'BlogPosting' ), $nxt_t2 ) : in_array( $nxt_t2, array( 'Article', 'NewsArticle', 'BlogPosting' ), true );
				if ( $nxt_is_article ) {
					$nxt_node2['isPartOf']         = array( '@id' => $nxt_webpage_id );
					$nxt_node2['mainEntityOfPage'] = array( '@id' => $nxt_webpage_id );
				}
			}
			unset( $nxt_node2 );
		}

		// Drop references (e.g. isPartOf → #webpage) pointing to @id nodes that aren't actually
		// present in this @graph, so the markup never contains a broken/dangling @id reference.
		$rendered = self::prune_dangling_references( $rendered );

		if ( empty( $rendered ) ) {
			return;
		}

		$schema_data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $rendered,
		);

		// Harden JSON-LD for output inside an HTML <script> context: JSON_HEX_TAG hex-encodes
		// < and > (so a stray "</script>" in any value becomes "</script>" and cannot
		// break out), JSON_HEX_AMP covers &, and JSON_HEX_QUOT | JSON_HEX_APOS encode " and ' for
		// defense in depth. We intentionally do NOT use JSON_UNESCAPED_SLASHES, so "/" is escaped
		// too. Unicode stays unescaped for readable, valid JSON-LD.
		$json = wp_json_encode( $schema_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

		$html = '<script type="application/ld+json" id="nexter-content-seo-schema">' . $json . '</script>' . "\n";
		if ( ! empty( $cache_key ) ) {
			set_transient( $cache_key, $html, 12 * HOUR_IN_SECONDS );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD hardened above.
	}

	/**
	 * REST: GET schema config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_get_schema( $request ) {
		$option = get_option( self::OPTION_SCHEMA, array() );
		$lists  = self::normalize_schema_lists( $option );
		$static = self::get_cached_static_schema_config();
		return rest_ensure_response(
			array(
			'data' => array(
				'schema'                   => array(
					'site_wide'     => $lists['site_wide'],
					'page_specific' => $lists['page_specific'],
				),
				'schema_types'             => $static['schema_types'],
				'schema_variables'         => $static['schema_variables'],
				// Rule-selection targets reflect currently-registered post types / taxonomies, so
				// they are built live (cheap: sub-millisecond) rather than cached.
				'schema_rules'             => self::get_schema_rules_selections(),
				'schema_field_definitions' => $static['schema_field_definitions'],
			 ),
			) 
		);
	}

	/**
	 * Schema type list, token variables and field definitions are STATIC configuration — they are
	 * identical for every request in a given locale and change only when the plugin version or the
	 * admin locale changes. Building them is cheap on a plain install, but the field definitions
	 * alone are ~60 KB assembled from hundreds of __() calls; on a site running a translation layer
	 * (WPML / Polylang / Loco) every __() fires a gettext filter, so rebuilding the whole block on
	 * every Schema Builder open can cost seconds (see PERF-003: 2.2s observed). Cache the built
	 * result in a per-locale, version-stamped transient so it is assembled once instead of on every
	 * request. The user's schema selections are NOT cached here — only this static config.
	 *
	 * @return array{schema_types:array, schema_variables:array, schema_field_definitions:array}
	 */
	public static function get_cached_static_schema_config() {
		$version = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '0';
		$locale  = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$key     = 'nxt_seo_schema_static_' . md5( $version . '|' . $locale );

		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['schema_types'], $cached['schema_variables'], $cached['schema_field_definitions'] ) ) {
			return $cached;
		}

		$data = array(
			'schema_types'             => self::get_schema_types(),
			'schema_variables'         => self::get_schema_variables(),
			'schema_field_definitions' => self::get_schema_field_definitions(),
		);
		set_transient( $key, $data, DAY_IN_SECONDS );
		return $data;
	}

	/**
	 * REST: POST HTML for schema display-conditions popup (Include In / Exclude From).
	 *
	 * @param WP_REST_Request $request Request with JSON { show_on: { rules: [] }, not_show_on: { rules: [] } }.
	 * @return WP_REST_Response
	 */
	public static function rest_post_schema_conditions_markup( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$show_on     = isset( $params['show_on'] ) && is_array( $params['show_on'] ) ? $params['show_on'] : array();
		$not_show_on = isset( $params['not_show_on'] ) && is_array( $params['not_show_on'] ) ? $params['not_show_on'] : array();
		$show_on     = self::sanitize_rules_block( $show_on );
		$not_show_on = self::sanitize_rules_block( $not_show_on );
		$html        = self::get_schema_conditions_popup_markup( $show_on, $not_show_on );
		return rest_ensure_response(
			array(
				'data' => array(
					'html' => $html,
				),
			)
		);
	}

	/**
	 * REST: POST save schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_save_schema( $request ) {
		$schema = $request->get_param( 'schema' );
		$clean  = self::sanitize_schema_payload( is_array( $schema ) ? $schema : array() );

		// Save-side validation: block persisting a schema that would emit invalid structured data
		// (e.g. an enabled FAQPage with no question/answer pair) and return a clear, actionable
		// error, instead of silently saving a broken node the way the previous pass-through did.
		$errors = self::collect_schema_validation_errors( $clean );
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'nexter_seo_schema_invalid',
				implode( ' ', $errors ),
				array(
					'status' => 400,
					'errors' => array_values( $errors ),
				)
			);
		}

		// Autoload: schema config is read on every frontend page render via print_schema().
		update_option( self::OPTION_SCHEMA, $clean, true );
		return rest_ensure_response( array( 'data' => array( 'saved' => true ) ) );
	}

	/**
	 * Collect human-readable validation errors for a sanitized schema payload. Only ENABLED rows
	 * are checked (disabled rows never render). Currently enforces each type's minimum required
	 * property; extend the switch as more types gain hard requirements.
	 *
	 * @param array $clean Sanitized payload from sanitize_schema_payload().
	 * @return array<int,string> Error messages (empty when the payload is valid).
	 */
	private static function collect_schema_validation_errors( array $clean ) {
		$errors = array();
		foreach ( array( 'site_wide', 'page_specific' ) as $bucket ) {
			if ( empty( $clean[ $bucket ] ) || ! is_array( $clean[ $bucket ] ) ) {
				continue;
			}
			foreach ( $clean[ $bucket ] as $row ) {
				if ( ! is_array( $row ) || empty( $row['enabled'] ) ) {
					continue; // Only enabled rows render, so only they must be valid.
				}
				$type   = isset( $row['type'] ) ? (string) $row['type'] : '';
				$fields = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
				$label  = ( isset( $row['title'] ) && '' !== (string) $row['title'] ) ? (string) $row['title'] : $type;

				if ( 'FAQPage' === $type && self::faq_qa_pair_count( $fields ) < 1 ) {
					$errors[] = sprintf(
						/* translators: %s: schema title/label */
						__( '“%s” (FAQ Page) needs at least one question and answer before it can be saved.', 'nexter-extension' ),
						$label
					);
				}
			}
		}
		return $errors;
	}

	/**
	 * Google rich-result minimum required properties per advanced schema type. A finalized node
	 * missing any of these is invalid structured data and must not be emitted.
	 *
	 * WebSite, BreadcrumbList, FAQPage and QAPage are intentionally absent — they are gated by
	 * their own dedicated checks in the emission loop.
	 *
	 * @return array<string, array<int,string>>
	 */
	private static function schema_required_fields() {
		return array(
			// Article's one genuinely-required property is headline; a user who blanks it produces
			// an incomplete node, so gate it out (default Article is post-derived and always has one).
			'Article'             => array( 'headline' ),
			'Product'             => array( 'name', 'image' ),
			'Event'               => array( 'name', 'startDate', 'location' ),
			'Recipe'              => array( 'name', 'image', 'recipeIngredient', 'recipeInstructions' ),
			'HowTo'               => array( 'name', 'step' ),
			'Course'              => array( 'name', 'description', 'provider' ),
			'VideoObject'         => array( 'name', 'description', 'thumbnailUrl', 'uploadDate' ),
			'SoftwareApplication' => array( 'name', 'applicationCategory', 'operatingSystem' ),
			'LocalBusiness'       => array( 'name', 'address' ),
			'ClaimReview'         => array( 'claimReviewed', 'reviewRating', 'itemReviewed' ),
			'Service'             => array( 'name' ),
		);
	}

	/**
	 * Whether a finalized node is missing any of its type's required properties. Types without a
	 * registered requirement (or already gated elsewhere) always pass.
	 *
	 * @param string $type   Node @type.
	 * @param array  $fields Finalized fields.
	 * @return bool
	 */
	private static function node_missing_required_fields( $type, $fields ) {
		$map = self::schema_required_fields();
		if ( ! isset( $map[ $type ] ) ) {
			return false;
		}
		foreach ( $map[ $type ] as $key ) {
			if ( ! isset( $fields[ $key ] ) || self::is_blank_value( $fields[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively determine whether a field value is effectively blank: an empty/whitespace
	 * string, null, an empty array, or a (possibly nested) array whose every leaf is blank.
	 * Numbers, booleans and objects count as present.
	 *
	 * @param mixed $value Field value.
	 * @return bool
	 */
	private static function is_blank_value( $value ) {
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::is_blank_value( $item ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Count valid question/answer pairs in a FAQPage row's stored mainEntity. Mirrors the pairing
	 * logic in finalize_faq_page_fields() so save-side validation matches what would actually
	 * render: a pair counts only when BOTH the question name and the answer text are non-empty.
	 *
	 * @param array $fields FAQPage row fields.
	 * @return int
	 */
	private static function faq_qa_pair_count( $fields ) {
		if ( ! is_array( $fields ) || ! isset( $fields['mainEntity'] ) ) {
			return 0;
		}
		$list = $fields['mainEntity'];
		if ( is_array( $list ) && isset( $list['@type'] ) && 'Question' === $list['@type'] ) {
			$list = array( $list );
		} elseif ( ! self::array_is_zero_indexed_list( $list ) ) {
			$list = array( $list );
		}
		$count = 0;
		foreach ( (array) $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
			$a = '';
			if ( isset( $row['answerText'] ) ) {
				$a = trim( (string) $row['answerText'] );
			} elseif ( isset( $row['acceptedAnswer']['text'] ) ) {
				$a = trim( (string) $row['acceptedAnswer']['text'] );
			}
			if ( '' !== $q && '' !== $a ) {
				++$count;
			}
		}
		return $count;
	}
}
