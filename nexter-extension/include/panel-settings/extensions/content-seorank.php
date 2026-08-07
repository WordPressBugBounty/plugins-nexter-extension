<?php
/**
 * Content SEO Rank – Nexter Extension module.
 * Registers meta, REST API, enqueues React panel; integrates with post editor.
 * Uses _nxt_* meta keys only.
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Content SEO global module (settings, schema, sitemap, REST API).
require_once dirname( __FILE__ ) . '/content-seo/class-content-seo.php';
Nexter_Content_SEO::get_instance();

/**
 * Class Nexter_Content_SeoRank
 */
class Nexter_Content_SeoRank {

	const META_TITLE         = '_nxt_seo_title';
	const META_DESCRIPTION   = '_nxt_seo_description';
	const FOCUS_KEYWORD      = '_nxt_focus_keyword';
	const SEO_SCORE          = '_nxt_seo_score';
	const READABILITY        = '_nxt_readability_score';
	const META_FB_TITLE      = '_nxt_seo_fb_title';
	const META_FB_DESC       = '_nxt_seo_fb_desc';
	const META_FB_IMAGE      = '_nxt_seo_fb_image';
	const META_TW_TITLE      = '_nxt_seo_tw_title';
	const META_TW_DESC       = '_nxt_seo_tw_desc';
	const META_TW_IMAGE      = '_nxt_seo_tw_image';
	const META_NOINDEX       = '_nxt_seo_noindex';
	const META_NOFOLLOW      = '_nxt_seo_nofollow';
	const META_NOARCHIVE     = '_nxt_seo_noarchive';
	const META_CANONICAL     = '_nxt_seo_canonical';
	const META_SCHEMA_TYPE   = '_nxt_seo_schema_type';
	const META_SCHEMA_CUSTOM = '_nxt_seo_schema_custom';
	const META_SCHEMA_ROWS   = '_nxt_seo_schema_rows';
	const REST_NAMESPACE     = 'nexter/v1';

	/** Cron hook that recomputes the stored SEO/readability score off the save request. */
	const SCORE_CRON_HOOK = 'nxt_seo_recalc_score';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'register_meta_fields' ), 15 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 15, 2 );
		add_action( self::SCORE_CRON_HOOK, array( $this, 'cron_recalc_score' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 15, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'init', array( $this, 'hook_term_edit_forms' ), 30 );
		// "SEO Checks" list-table column (posts/pages/CPTs) + its lightweight list-screen assets.
		add_action( 'admin_init', array( $this, 'register_admin_columns' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_seo_checks_assets' ), 20 );
	}

	/**
	 * Add SEO meta box on post edit screen (sidebar mount for React panel).
	 *
	 * Runs on add_meta_boxes; adds one meta box per public post type.
	 */
	public function add_meta_box() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'nxt_content_seo_sidebar',
				// White-label aware: shows the Pro brand name when set, else "Nexter SEO".
				class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_label() : __( 'Nexter SEO', 'nexter-extension' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box container for React sidebar panel.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		printf(
			'<div id="nexter-content-seo-sidebar" class="%s"></div>',
			esc_attr( 'nxt-content-seo-mount' )
		);
	}

	/**
	 * Register post meta for SEO fields.
	 */
	public function register_meta_fields() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_TITLE,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_post_meta(
				$post_type,
				self::META_DESCRIPTION,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_post_meta(
				$post_type,
				self::FOCUS_KEYWORD,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_post_meta(
				$post_type,
				self::SEO_SCORE,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'integer',
				)
			);
			register_post_meta(
				$post_type,
				self::READABILITY,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'integer',
				)
			);
			foreach ( array( self::META_FB_TITLE, self::META_FB_DESC, self::META_FB_IMAGE, self::META_TW_TITLE, self::META_TW_DESC, self::META_TW_IMAGE, self::META_CANONICAL, self::META_SCHEMA_TYPE ) as $meta_key ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'auth_callback' => array( $this, 'auth_callback' ),
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'string',
					)
				);
			}
			foreach ( array( self::META_NOINDEX, self::META_NOFOLLOW, self::META_NOARCHIVE, self::META_SCHEMA_CUSTOM ) as $meta_key ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'auth_callback' => array( $this, 'auth_callback' ),
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'boolean',
					)
				);
			}
			register_post_meta(
				$post_type,
				self::META_SCHEMA_ROWS,
				array(
					'auth_callback' => array( $this, 'auth_callback' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			register_term_meta(
				$taxonomy,
				self::META_TITLE,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_term_meta(
				$taxonomy,
				self::META_DESCRIPTION,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_term_meta(
				$taxonomy,
				self::FOCUS_KEYWORD,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
			register_term_meta(
				$taxonomy,
				self::SEO_SCORE,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'integer',
				)
			);
			register_term_meta(
				$taxonomy,
				self::READABILITY,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'integer',
				)
			);
			foreach ( array( self::META_FB_TITLE, self::META_FB_DESC, self::META_FB_IMAGE, self::META_TW_TITLE, self::META_TW_DESC, self::META_TW_IMAGE, self::META_CANONICAL, self::META_SCHEMA_TYPE ) as $meta_key ) {
				register_term_meta(
					$taxonomy,
					$meta_key,
					array(
						'auth_callback' => array( $this, 'term_meta_auth' ),
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'string',
					)
				);
			}
			foreach ( array( self::META_NOINDEX, self::META_NOFOLLOW, self::META_NOARCHIVE, self::META_SCHEMA_CUSTOM ) as $meta_key ) {
				register_term_meta(
					$taxonomy,
					$meta_key,
					array(
						'auth_callback' => array( $this, 'term_meta_auth' ),
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'boolean',
					)
				);
			}
			register_term_meta(
				$taxonomy,
				self::META_SCHEMA_ROWS,
				array(
					'auth_callback' => array( $this, 'term_meta_auth' ),
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
				)
			);
		}
	}

	/**
	 * Term meta: allow if user can edit the term.
	 *
	 * @param bool   $allowed  Whether the user can add the meta.
	 * @param string $meta_key Meta key.
	 * @param int    $term_id  Term ID.
	 * @return bool
	 */
	public function term_meta_auth( $allowed, $meta_key, $term_id ) {
		return current_user_can( 'edit_term', (int) $term_id );
	}

	/**
	 * Add Nexter SEO mount to each public taxonomy term edit form.
	 */
	public function hook_term_edit_forms() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form", array( $this, 'render_term_seo_mount' ), 99, 2 );
		}
	}

	/**
	 * Markup for React mount (Nexter SEO opens from icon button in the panel).
	 *
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Taxonomy slug.
	 */
	public function render_term_seo_mount( $term, $taxonomy ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		echo '<div class="nxt-seo-term-edit-field">';
		// White-label aware: shows the Pro brand name when set, else "Nexter SEO".
		echo '<h2>' . esc_html( class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_label() : __( 'Nexter SEO', 'nexter-extension' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Open the Nexter SEO panel to edit title, description, social previews, and advanced settings for this archive page.', 'nexter-extension' ) . '</p>';
		printf(
			'<div id="nexter-content-seo-term" class="%s" data-term-id="%d" data-taxonomy="%s"></div>',
			esc_attr( 'nxt-content-seo-mount nxt-content-seo-mount--term' ),
			(int) $term->term_id,
			esc_attr( $taxonomy )
		);
		echo '</div>';
	}

	/**
	 * Auth callback for registered post meta (allow if user can edit post).
	 *
	 * @param bool   $allowed  Whether the user can add the meta.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public function auth_callback( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Register REST routes: GET/POST /nexter/v1/seo/post/<id>, POST /nexter/v1/seo/analyze.
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/post/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_seo' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0;
						},
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/post/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_seo' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'id'               => array(
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0;
						},
					),
					'meta_title'       => array( 'type' => 'string' ),
					'meta_description' => array( 'type' => 'string' ),
					'focus_keyword'    => array( 'type' => 'string' ),
					'fb_title'         => array( 'type' => 'string' ),
					'fb_description'   => array( 'type' => 'string' ),
					'fb_image'         => array( 'type' => 'string' ),
					'tw_title'         => array( 'type' => 'string' ),
					'tw_description'   => array( 'type' => 'string' ),
					'tw_image'         => array( 'type' => 'string' ),
					'noindex'          => array( 'type' => 'boolean' ),
					'nofollow'         => array( 'type' => 'boolean' ),
					'noarchive'        => array( 'type' => 'boolean' ),
					'canonical_url'    => array( 'type' => 'string' ),
					'schema_type'      => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_analyze' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'post_id'          => array( 'type' => 'integer' ),
					'content'          => array( 'type' => 'string' ),
					'title'            => array( 'type' => 'string' ),
					'meta_description' => array( 'type' => 'string' ),
					'focus_keyword'    => array( 'type' => 'string' ),
				),
			)
		);
		$term_id_arg  = array(
			'validate_callback' => function ( $v ) {
				return is_numeric( $v ) && (int) $v > 0;
			},
		);
		$taxonomy_arg = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => function ( $tax ) {
				return is_string( $tax ) && taxonomy_exists( $tax );
			},
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/term/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_seo_term' ),
				'permission_callback' => array( $this, 'rest_term_permission' ),
				'args'                => array(
					'id'       => $term_id_arg,
					'taxonomy' => $taxonomy_arg,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/seo/term/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_seo_term' ),
				'permission_callback' => array( $this, 'rest_term_permission' ),
				'args'                => array(
					'id'               => $term_id_arg,
					'taxonomy'         => $taxonomy_arg,
					'meta_title'       => array( 'type' => 'string' ),
					'meta_description' => array( 'type' => 'string' ),
					'focus_keyword'    => array( 'type' => 'string' ),
					'fb_title'         => array( 'type' => 'string' ),
					'fb_description'   => array( 'type' => 'string' ),
					'fb_image'         => array( 'type' => 'string' ),
					'tw_title'         => array( 'type' => 'string' ),
					'tw_description'   => array( 'type' => 'string' ),
					'tw_image'         => array( 'type' => 'string' ),
					'noindex'          => array( 'type' => 'boolean' ),
					'nofollow'         => array( 'type' => 'boolean' ),
					'noarchive'        => array( 'type' => 'boolean' ),
					'canonical_url'    => array( 'type' => 'string' ),
					'schema_type'      => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * REST: permission for term SEO routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function rest_term_permission( $request ) {
		$term_id  = (int) $request->get_param( 'id' );
		$taxonomy = $request->get_param( 'taxonomy' );
		if ( ! $term_id || ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Term not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'nexter-extension' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST: GET SEO data for a taxonomy term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_seo_term( $request ) {
		$term_id  = (int) $request['id'];
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$term     = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Term not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		list( $default_title, $default_desc ) = $this->get_resolved_default_title_description_term( $term );

		$data = array(
			'focus_keyword'            => get_term_meta( $term_id, self::FOCUS_KEYWORD, true ),
			'meta_title'               => get_term_meta( $term_id, self::META_TITLE, true ),
			'meta_description'         => get_term_meta( $term_id, self::META_DESCRIPTION, true ),
			'default_meta_title'       => $default_title,
			'default_meta_description' => $default_desc,
			'seo_score'                => (int) get_term_meta( $term_id, self::SEO_SCORE, true ),
			'readability_score'        => (int) get_term_meta( $term_id, self::READABILITY, true ),
			'keyword_density'          => 0,
			'checklist'                => array(),
			'suggestions'              => array(),
			'fb_title'                 => get_term_meta( $term_id, self::META_FB_TITLE, true ),
			'fb_description'           => get_term_meta( $term_id, self::META_FB_DESC, true ),
			'fb_image'                 => get_term_meta( $term_id, self::META_FB_IMAGE, true ),
			'tw_title'                 => get_term_meta( $term_id, self::META_TW_TITLE, true ),
			'tw_description'           => get_term_meta( $term_id, self::META_TW_DESC, true ),
			'tw_image'                 => get_term_meta( $term_id, self::META_TW_IMAGE, true ),
			'noindex'                  => (bool) get_term_meta( $term_id, self::META_NOINDEX, true ),
			'nofollow'                 => (bool) get_term_meta( $term_id, self::META_NOFOLLOW, true ),
			'noarchive'                => (bool) get_term_meta( $term_id, self::META_NOARCHIVE, true ),
			'canonical_url'            => get_term_meta( $term_id, self::META_CANONICAL, true ),
			'schema_type'              => get_term_meta( $term_id, self::META_SCHEMA_TYPE, true ),
			'schema_post_custom'       => (bool) get_term_meta( $term_id, self::META_SCHEMA_CUSTOM, true ),
			'schema_post_rows'         => self::get_schema_term_rows_for_rest( $term_id, $taxonomy ),
			'schema_inherited_rows'    => class_exists( 'Nexter_Content_SEO_Schema' )
				? Nexter_Content_SEO_Schema::get_default_term_schema_inherited_rows()
				: array(),
			'schema_types'             => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_types'] : array(),
			'schema_variables'         => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_variables'] : array(),
			'schema_field_definitions' => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_field_definitions'] : array(),
		);
		require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
		$title                     = $data['meta_title'] ? $data['meta_title'] : ( $default_title ? $default_title : $term->name );
		$desc                      = $data['meta_description'] ? $data['meta_description'] : ( $default_desc ? $default_desc : wp_trim_words( wp_strip_all_tags( $term->description ), 25 ) );
		$content_plain             = wp_strip_all_tags( $term->description );
		$result                    = Nxt_Seo_Analyzer::analyze_post_content( 0, $content_plain, $title, $desc, $data['focus_keyword'] );
		$data['seo_score']         = $result['score'];
		$data['readability_score'] = $result['readability'];
		$data['keyword_density']   = $result['keyword_density'];
		$data['checklist']         = $result['checklist'];
		$data['suggestions']       = $result['suggestions'];

		return rest_ensure_response(
			array(
				'success'        => true,
				'data'           => $data,
				'global_default' => $this->get_global_default_templates_for_term(),
			)
		);
	}

	/**
	 * Map post-oriented template tokens to term tokens for taxonomy archive SEO UI.
	 *
	 * @param string $template Template with %variables%.
	 * @return string
	 */
	private function map_post_seo_tokens_to_term_templates( $template ) {
		if ( ! is_string( $template ) || $template === '' ) {
			return '';
		}
		return str_replace(
			array( '%post_title%', '%post_excerpt%', '%post_content%' ),
			array( '%term_title%', '%term_description%', '%term_description%' ),
			$template
		);
	}

	/**
	 * Global title/description templates with term tokens (for term edit screen + social defaults).
	 *
	 * @return array<string, string>
	 */
	private function get_global_default_templates_for_term() {
		$base = $this->get_global_default_templates();
		return array(
			'meta_title'       => $this->map_post_seo_tokens_to_term_templates( $base['meta_title'] ),
			'meta_description' => $this->map_post_seo_tokens_to_term_templates( $base['meta_description'] ),
			'fb_title'         => $this->map_post_seo_tokens_to_term_templates( $base['fb_title'] ),
			'fb_description'   => $this->map_post_seo_tokens_to_term_templates( $base['fb_description'] ),
			'tw_title'         => $this->map_post_seo_tokens_to_term_templates( $base['tw_title'] ),
			'tw_description'   => $this->map_post_seo_tokens_to_term_templates( $base['tw_description'] ),
			'social_image'     => $base['social_image'],
		);
	}

	/**
	 * Per-term schema rows for REST when custom override is on.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_schema_term_rows_for_rest( $term_id, $taxonomy ) {
		if ( ! get_term_meta( $term_id, self::META_SCHEMA_CUSTOM, true ) ) {
			return array();
		}
		if ( ! class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			return array();
		}
		$raw = get_term_meta( $term_id, self::META_SCHEMA_ROWS, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * REST: save term SEO meta (partial body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_save_seo_term( $request ) {
		$term_id  = (int) $request['id'];
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$reschk   = $this->rest_term_permission( $request );
		if ( is_wp_error( $reschk ) ) {
			return $reschk;
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( array_key_exists( 'meta_title', $body ) ) {
			$v = $body['meta_title'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_TITLE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'meta_description', $body ) ) {
			$v = $body['meta_description'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_DESCRIPTION );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_DESCRIPTION, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'focus_keyword', $body ) ) {
			$v = $body['focus_keyword'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::FOCUS_KEYWORD );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::FOCUS_KEYWORD, self::sanitize_focus_keyword_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_title', $body ) ) {
			$v = $body['fb_title'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_FB_TITLE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_FB_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_description', $body ) ) {
			$v = $body['fb_description'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_FB_DESC );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_FB_DESC, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_image', $body ) ) {
			$v = $body['fb_image'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_FB_IMAGE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_FB_IMAGE, esc_url_raw( $v ) );
			}
		}
		if ( array_key_exists( 'tw_title', $body ) ) {
			$v = $body['tw_title'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_TW_TITLE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_TW_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'tw_description', $body ) ) {
			$v = $body['tw_description'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_TW_DESC );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_TW_DESC, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'tw_image', $body ) ) {
			$v = $body['tw_image'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_TW_IMAGE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_TW_IMAGE, esc_url_raw( $v ) );
			}
		}
		// Tri-state write (mirrors the post-side save): store an explicit '0' on uncheck rather
		// than deleting the meta. get_term_directive() treats '1' as noindex, '0' as an explicit
		// index override, and only an ABSENT value falls back to the global noindex_taxonomies
		// option — so deleting on uncheck made it impossible to opt a single term OUT of a
		// globally-noindexed taxonomy (it silently reverted to the global directive).
		if ( array_key_exists( 'noindex', $body ) ) {
			update_term_meta( $term_id, self::META_NOINDEX, empty( $body['noindex'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'nofollow', $body ) ) {
			update_term_meta( $term_id, self::META_NOFOLLOW, empty( $body['nofollow'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'noarchive', $body ) ) {
			update_term_meta( $term_id, self::META_NOARCHIVE, empty( $body['noarchive'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'canonical_url', $body ) ) {
			$v = $body['canonical_url'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_CANONICAL );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_CANONICAL, esc_url_raw( $v ) );
			}
		}
		if ( array_key_exists( 'schema_type', $body ) ) {
			$v = $body['schema_type'];
			if ( null === $v || '' === $v ) {
				delete_term_meta( $term_id, self::META_SCHEMA_TYPE );
			} elseif ( is_string( $v ) ) {
				update_term_meta( $term_id, self::META_SCHEMA_TYPE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'schema_post_custom', $body ) ) {
			if ( empty( $body['schema_post_custom'] ) ) {
				delete_term_meta( $term_id, self::META_SCHEMA_CUSTOM );
				delete_term_meta( $term_id, self::META_SCHEMA_ROWS );
			} else {
				update_term_meta( $term_id, self::META_SCHEMA_CUSTOM, true );
				if ( array_key_exists( 'schema_post_rows', $body ) && is_array( $body['schema_post_rows'] ) && class_exists( 'Nexter_Content_SEO_Schema' ) ) {
					$clean = Nexter_Content_SEO_Schema::sanitize_term_schema_rows_list( $body['schema_post_rows'], $term_id );
					update_term_meta( $term_id, self::META_SCHEMA_ROWS, wp_json_encode( $clean ) );
				}
			}
		}

		return rest_ensure_response( array( 'data' => array( 'saved' => true ) ) );
	}

	public function rest_permission( $request ) {
		$post_id = (int) $request->get_param( 'id' ) ?: (int) $request->get_param( 'post_id' );
		if ( $post_id ) {
			// A non-existent post ID must read as 404 (rest_post_invalid_id), not 403 — otherwise a
			// missing resource is misreported as a permission error. Check existence before the cap.
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'nexter-extension' ), array( 'status' => 404 ) );
			}
			return current_user_can( 'edit_post', $post_id );
		}
		return current_user_can( 'edit_posts' );
	}

	public function rest_get_seo( $request ) {
		$post_id = (int) $request['id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'nexter-extension' ), array( 'status' => 404 ) );
		}
		$post                                 = get_post( $post_id );
		list( $default_title, $default_desc ) = $this->get_resolved_default_title_description( $post );

		$data = array(
			'focus_keyword'            => get_post_meta( $post_id, self::FOCUS_KEYWORD, true ),
			'meta_title'               => get_post_meta( $post_id, self::META_TITLE, true ),
			'meta_description'         => get_post_meta( $post_id, self::META_DESCRIPTION, true ),
			'default_meta_title'       => $default_title,
			'default_meta_description' => $default_desc,
			'seo_score'                => (int) get_post_meta( $post_id, self::SEO_SCORE, true ),
			'readability_score'        => (int) get_post_meta( $post_id, self::READABILITY, true ),
			'keyword_density'          => 0,
			'checklist'                => array(),
			'suggestions'              => array(),
			'fb_title'                 => get_post_meta( $post_id, self::META_FB_TITLE, true ),
			'fb_description'           => get_post_meta( $post_id, self::META_FB_DESC, true ),
			'fb_image'                 => get_post_meta( $post_id, self::META_FB_IMAGE, true ),
			'tw_title'                 => get_post_meta( $post_id, self::META_TW_TITLE, true ),
			'tw_description'           => get_post_meta( $post_id, self::META_TW_DESC, true ),
			'tw_image'                 => get_post_meta( $post_id, self::META_TW_IMAGE, true ),
			'noindex'                  => (bool) get_post_meta( $post_id, self::META_NOINDEX, true ),
			'nofollow'                 => (bool) get_post_meta( $post_id, self::META_NOFOLLOW, true ),
			'noarchive'                => (bool) get_post_meta( $post_id, self::META_NOARCHIVE, true ),
			'canonical_url'            => get_post_meta( $post_id, self::META_CANONICAL, true ),
			'schema_type'              => get_post_meta( $post_id, self::META_SCHEMA_TYPE, true ),
			'schema_post_custom'       => (bool) get_post_meta( $post_id, self::META_SCHEMA_CUSTOM, true ),
			'schema_post_rows'         => self::get_schema_post_rows_for_rest( $post_id ),
			'schema_inherited_rows'    => class_exists( 'Nexter_Content_SEO_Schema' )
				? Nexter_Content_SEO_Schema::get_inherited_page_schema_rows_for_post( $post_id )
				: array(),
			'schema_types'             => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_types'] : array(),
			'schema_variables'         => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_variables'] : array(),
			'schema_field_definitions' => class_exists( 'Nexter_Content_SEO_Schema' ) ? Nexter_Content_SEO_Schema::get_cached_static_schema_config()['schema_field_definitions'] : array(),
		);
		if ( $post ) {
			require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
			$title                     = $data['meta_title'] ? $data['meta_title'] : ( $default_title ? $default_title : $post->post_title );
			$desc                      = $data['meta_description'] ? $data['meta_description'] : ( $default_desc ? $default_desc : wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ) );
			$result                    = Nxt_Seo_Analyzer::analyze_post_content( $post_id, $post->post_content, $title, $desc, $data['focus_keyword'] );
			$data['seo_score']         = $result['score'];
			$data['readability_score'] = $result['readability'];
			$data['keyword_density']   = $result['keyword_density'];
			$data['checklist']         = $result['checklist'];
			$data['suggestions']       = $result['suggestions'];
		}
		return rest_ensure_response(
			array(
				'success'        => true,
				'data'           => $data,
				'global_default' => $this->get_global_default_templates(),
			)
		);
	}

	/**
	 * Raw global title/description templates (same as Content SEO settings) for editor fallbacks.
	 * Used when post meta is empty; does not include per-post resolved strings.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Per-post schema rows for REST (decoded when custom override is on).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_schema_post_rows_for_rest( $post_id ) {
		if ( ! get_post_meta( $post_id, self::META_SCHEMA_CUSTOM, true ) ) {
			return array();
		}
		return class_exists( 'Nexter_Content_SEO_Schema' )
			? Nexter_Content_SEO_Schema::get_post_custom_schema_rows_raw( $post_id )
			: array();
	}

	private function get_global_default_templates() {
		$opts       = Nexter_Content_SEO::get_options();
		$title_t    = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$desc_t     = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$title_t    = self::normalize_template_for_variables( $title_t );
		$desc_t     = self::normalize_template_for_variables( $desc_t );
		$social_img = isset( $opts['default_social_image'] ) ? trim( (string) $opts['default_social_image'] ) : '';
		// Open Graph / X use the same templates when no per-post override exists.
		return array(
			'meta_title'       => $title_t,
			'meta_description' => $desc_t,
			'fb_title'         => $title_t,
			'fb_description'   => $desc_t,
			'tw_title'         => $title_t,
			'tw_description'   => $desc_t,
			'social_image'     => $social_img,
		);
	}

	/**
	 * Sanitize the focus-keyword field while preserving newline delimiters.
	 *
	 * The analyzer (Nxt_Seo_Analyzer::normalize_keywords) supports comma/pipe/newline-separated
	 * keyword lists. sanitize_text_field() collapses every \r\n\t run into a single space, so
	 * newline-separated input ("keyword one\nkeyword two") was stored as one merged phrase. Split
	 * on line breaks, sanitize each line individually, drop blanks, and rejoin with "\n" so the
	 * newline delimiter the analyzer advertises actually survives storage.
	 *
	 * @param mixed $v Raw field value.
	 * @return string
	 */
	private static function sanitize_focus_keyword_field( $v ) {
		$v     = wp_strip_all_tags( (string) $v );
		$lines = preg_split( '/\r\n|\r|\n/', $v );
		$lines = array_map( 'sanitize_text_field', is_array( $lines ) ? $lines : array() );
		$lines = array_filter(
			$lines,
			static function ( $l ) {
				return '' !== $l;
			} 
		);
		return implode( "\n", $lines );
	}

	public function rest_save_seo( $request ) {
		$post_id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'nexter-extension' ), array( 'status' => 403 ) );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Only keys present in the JSON body are updated (partial save). Empty string removes stored meta so global defaults apply.
		if ( array_key_exists( 'meta_title', $body ) ) {
			$v = $body['meta_title'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_TITLE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'meta_description', $body ) ) {
			$v = $body['meta_description'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_DESCRIPTION );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_DESCRIPTION, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'focus_keyword', $body ) ) {
			$v = $body['focus_keyword'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::FOCUS_KEYWORD );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::FOCUS_KEYWORD, self::sanitize_focus_keyword_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_title', $body ) ) {
			$v = $body['fb_title'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_FB_TITLE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_FB_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_description', $body ) ) {
			$v = $body['fb_description'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_FB_DESC );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_FB_DESC, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'fb_image', $body ) ) {
			$v = $body['fb_image'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_FB_IMAGE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_FB_IMAGE, esc_url_raw( $v ) );
			}
		}
		if ( array_key_exists( 'tw_title', $body ) ) {
			$v = $body['tw_title'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_TW_TITLE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_TW_TITLE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'tw_description', $body ) ) {
			$v = $body['tw_description'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_TW_DESC );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_TW_DESC, sanitize_textarea_field( $v ) );
			}
		}
		if ( array_key_exists( 'tw_image', $body ) ) {
			$v = $body['tw_image'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_TW_IMAGE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_TW_IMAGE, esc_url_raw( $v ) );
			}
		}
		// Robots flags are an explicit per-post tri-state: '1' = force directive on,
		// '0' = force off (overrides the global per-post-type rule via
		// Nexter_Content_SEO_Robots::get_singular_directive). Storing '0' rather than
		// deleting is what lets an unchecked box win over a globally-noindexed post type.
		if ( array_key_exists( 'noindex', $body ) ) {
			update_post_meta( $post_id, self::META_NOINDEX, empty( $body['noindex'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'nofollow', $body ) ) {
			update_post_meta( $post_id, self::META_NOFOLLOW, empty( $body['nofollow'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'noarchive', $body ) ) {
			update_post_meta( $post_id, self::META_NOARCHIVE, empty( $body['noarchive'] ) ? '0' : '1' );
		}
		if ( array_key_exists( 'canonical_url', $body ) ) {
			$v = $body['canonical_url'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_CANONICAL );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_CANONICAL, esc_url_raw( $v ) );
			}
		}
		if ( array_key_exists( 'schema_type', $body ) ) {
			$v = $body['schema_type'];
			if ( null === $v || '' === $v ) {
				delete_post_meta( $post_id, self::META_SCHEMA_TYPE );
			} elseif ( is_string( $v ) ) {
				update_post_meta( $post_id, self::META_SCHEMA_TYPE, sanitize_text_field( $v ) );
			}
		}
		if ( array_key_exists( 'schema_post_custom', $body ) ) {
			if ( empty( $body['schema_post_custom'] ) ) {
				delete_post_meta( $post_id, self::META_SCHEMA_CUSTOM );
				delete_post_meta( $post_id, self::META_SCHEMA_ROWS );
			} else {
				update_post_meta( $post_id, self::META_SCHEMA_CUSTOM, true );
				if ( array_key_exists( 'schema_post_rows', $body ) && is_array( $body['schema_post_rows'] ) && class_exists( 'Nexter_Content_SEO_Schema' ) ) {
					$clean = Nexter_Content_SEO_Schema::sanitize_post_schema_rows_list( $body['schema_post_rows'], $post_id );
					update_post_meta( $post_id, self::META_SCHEMA_ROWS, wp_json_encode( $clean ) );
				}
			}
		}

		// Title/description/focus-keyword changes here affect the stored score; refresh it
		// off-request (debounced) so admin list columns stay in sync.
		self::schedule_score_recalc( $post_id );

		return rest_ensure_response( array( 'data' => array( 'saved' => true ) ) );
	}

	/**
	 * Normalize @variable tokens to %variable% for Nexter_Content_SEO_Settings::replace_variables().
	 *
	 * @param string $t Template.
	 * @return string
	 */
	private static function normalize_template_for_variables( $t ) {
		if ( ! is_string( $t ) ) {
			return '';
		}
		return preg_replace( '/@([a-z0-9_]+)/i', '%$1%', $t );
	}

	/**
	 * Resolved title and description from global Nexter SEO templates for this post (fallback when post meta is empty).
	 *
	 * @param WP_Post|null $post Post object.
	 * @return array{0:string,1:string} Title and description.
	 */
	private function get_resolved_default_title_description( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array( '', '' );
		}
		require_once dirname( __FILE__ ) . '/content-seo/class-seo-settings.php';
		$opts    = Nexter_Content_SEO::get_options();
		$title_t = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$desc_t  = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$title_t = self::normalize_template_for_variables( $title_t );
		$desc_t  = self::normalize_template_for_variables( $desc_t );
		$ctx     = array( 'post' => $post );
		return array(
			Nexter_Content_SEO_Settings::replace_variables( $title_t, $ctx ),
			Nexter_Content_SEO_Settings::replace_variables( $desc_t, $ctx ),
		);
	}

	/**
	 * Resolved title/description from global templates for a taxonomy term (when term meta is empty).
	 *
	 * @param WP_Term|null $term Term object.
	 * @return array{0:string,1:string} Title and description.
	 */
	private function get_resolved_default_title_description_term( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return array( '', '' );
		}
		require_once dirname( __FILE__ ) . '/content-seo/class-seo-settings.php';
		$opts    = Nexter_Content_SEO::get_options();
		$title_t = ! empty( $opts['meta_title_template'] ) ? $opts['meta_title_template'] : ( ! empty( $opts['search_title_template'] ) ? $opts['search_title_template'] : '%post_title% - %site_name%' );
		$desc_t  = ! empty( $opts['meta_description_template'] ) ? $opts['meta_description_template'] : ( ! empty( $opts['search_description_template'] ) ? $opts['search_description_template'] : '%post_excerpt%' );
		$title_t = self::normalize_template_for_variables( $title_t );
		$desc_t  = self::normalize_template_for_variables( $desc_t );
		$title_t = self::map_post_seo_tokens_to_term_templates( $title_t );
		$desc_t  = self::map_post_seo_tokens_to_term_templates( $desc_t );
		$ctx     = array( 'term' => $term );
		return array(
			Nexter_Content_SEO_Settings::replace_variables( $title_t, $ctx ),
			Nexter_Content_SEO_Settings::replace_variables( $desc_t, $ctx ),
		);
	}

	public function rest_analyze( $request ) {
		$post_id   = (int) $request->get_param( 'post_id' );
		$content   = $request->get_param( 'content' );
		$title     = $request->get_param( 'title' );
		$meta_desc = $request->get_param( 'meta_description' );
		$focus_kw  = $request->get_param( 'focus_keyword' );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'nexter-extension' ), array( 'status' => 403 ) );
		}

		$content   = is_string( $content ) ? $content : '';
		$title     = is_string( $title ) ? sanitize_text_field( $title ) : '';
		$meta_desc = is_string( $meta_desc ) ? sanitize_textarea_field( $meta_desc ) : '';
		$focus_kw  = is_string( $focus_kw ) ? sanitize_text_field( $focus_kw ) : '';
		// Cap content length to avoid excessive processing (e.g. 2MB).
		if ( strlen( $content ) > 2097152 ) {
			$content = substr( $content, 0, 2097152 );
		}

		// Audit the EFFECTIVE (rendered) title/description, not the raw override. When the editor
		// leaves a field blank the front end falls back to the default template (e.g. the
		// "%post_excerpt%" / "Post Excerpt" chip), so an empty override still produces a real
		// description on the live page. Mirror rest_get_seo()'s fallback here — otherwise a
		// perfectly valid template-driven description is wrongly flagged "missing" in the SEO
		// Audit tab even while the Google preview shows it at 149/160.
		if ( $post_id && ( '' === $title || '' === $meta_desc ) ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				// Prefer the SAVED per-post override (what the editor field shows and the front end
				// emits) before the global template default. Otherwise a stored over-long description
				// is never measured: the check resolves the short template default and wrongly passes
				// the length row even while the field shows e.g. 1039/160 in red.
				if ( '' === $title ) {
					$saved_title = trim( (string) get_post_meta( $post_id, self::META_TITLE, true ) );
					if ( '' !== $saved_title ) {
						$title = $saved_title;
					}
				}
				if ( '' === $meta_desc ) {
					$saved_desc = trim( (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ) );
					if ( '' !== $saved_desc ) {
						$meta_desc = $saved_desc;
					}
				}
				if ( '' === $title || '' === $meta_desc ) {
					list( $default_title, $default_desc ) = $this->get_resolved_default_title_description( $post );
					if ( '' === $title ) {
						$title = $default_title ? $default_title : $post->post_title;
					}
					if ( '' === $meta_desc ) {
						$meta_desc = $default_desc ? $default_desc : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 25 );
					}
				}
			}
		}

		require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
		$result = Nxt_Seo_Analyzer::analyze_post_content( $post_id ?: 0, $content, $title, $meta_desc, $focus_kw );
		return rest_ensure_response(
			array(
			'data' => array(
				'score'           => $result['score'],
				'keyword_density' => $result['keyword_density'],
				'readability'     => $result['readability'],
				'checklist'       => $result['checklist'],
				'suggestions'     => $result['suggestions'],
			 ),
			) 
		);
	}

	/**
	 * On save_post: schedule a (debounced) off-request recalculation of the SEO/readability
	 * score. The heavy regex analysis + 2 meta writes are deferred to cron so bulk operations
	 * (WP All Import etc.) don't pay the cost synchronously on every item. The live editor still
	 * shows up-to-the-moment scores via the REST analyze endpoint; this only refreshes the
	 * stored values used by admin list columns / sorting.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// Bulk imports set WP_IMPORTING. Skip entirely — scheduling 150+ events would itself be
		// overhead, and the score recomputes lazily on the next individual edit / REST analyze.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::schedule_score_recalc( $post_id );
	}

	/**
	 * Queue a single, debounced cron event to recompute the score for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function schedule_score_recalc( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$args = array( $post_id );
		if ( ! wp_next_scheduled( self::SCORE_CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 30, self::SCORE_CRON_HOOK, $args );
		}
		// Fallback for hosts with WP-Cron disabled (DISABLE_WP_CRON): the queued event can sit
		// unfired indefinitely, so the stored score never refreshes. Run the recalc on shutdown —
		// after the response is flushed (fastcgi_finish_request when available, so saving isn't
		// blocked) — and unschedule the now-duplicate event for just this post. Mirrors
		// class-seo-indexing.php maybe_ping_indexnow_on_save().
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			add_action(
				'shutdown',
				static function () use ( $post_id, $args ) {
					$timestamp = wp_next_scheduled( self::SCORE_CRON_HOOK, $args );
					if ( $timestamp ) {
						wp_unschedule_event( $timestamp, self::SCORE_CRON_HOOK, $args );
					}
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					}
					self::get_instance()->cron_recalc_score( $post_id );
				}
			);
		}
	}

	/**
	 * Cron callback: recompute and store the SEO score + readability for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function cron_recalc_score( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}
		$focus      = get_post_meta( $post_id, self::FOCUS_KEYWORD, true );
		$title_meta = get_post_meta( $post_id, self::META_TITLE, true );
		$desc_meta  = get_post_meta( $post_id, self::META_DESCRIPTION, true );
		$title      = $title_meta ?: $post->post_title;
		// strip_shortcodes() before wp_strip_all_tags(): the latter only removes HTML tags, not
		// [shortcode] bracket syntax, so a slider/gallery shortcode in the first ~25 words would
		// otherwise leak its raw markup into the auto-generated description (matches class-audit.php).
		$desc = $desc_meta ?: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 25 );
		require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
		$result = Nxt_Seo_Analyzer::analyze_post_content( $post_id, $post->post_content, $title, $desc, $focus );
		update_post_meta( $post_id, self::SEO_SCORE, $result['score'] );
		update_post_meta( $post_id, self::READABILITY, $result['readability'] );
	}

	/**
	 * After term edit: recalculate SEO score + readability (term description as content).
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$focus      = get_term_meta( $term_id, self::FOCUS_KEYWORD, true );
		$title_meta = get_term_meta( $term_id, self::META_TITLE, true );
		$desc_meta  = get_term_meta( $term_id, self::META_DESCRIPTION, true );
		$title      = $title_meta ? $title_meta : $term->name;
		// strip_shortcodes() before wp_strip_all_tags() so a shortcode in the term description
		// doesn't leak raw [shortcode] markup into the auto-generated description.
		$desc = $desc_meta ? $desc_meta : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $term->description ) ), 25 );
		require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
		$result = Nxt_Seo_Analyzer::analyze_post_content( 0, wp_strip_all_tags( $term->description ), $title, $desc, $focus );
		update_term_meta( $term_id, self::SEO_SCORE, $result['score'] );
		update_term_meta( $term_id, self::READABILITY, $result['readability'] );
	}

	/**
	 * Post types that show the "SEO Checks" list-table column. Public types (minus attachments)
	 * by default; filterable.
	 *
	 * @return string[]
	 */
	public function seo_checks_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		/**
		 * Filter which post types show the "SEO Checks" list column.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = apply_filters( 'nexter_content_seo_checks_post_types', array_values( $types ) );
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Public, UI-visible taxonomies that show the "SEO Checks" term-list column. Filterable.
	 *
	 * @return string[]
	 */
	public function seo_checks_taxonomies() {
		$taxes = get_taxonomies(
			array(
			'public'  => true,
			'show_ui' => true
			),
			'names' 
		);
		unset( $taxes['post_format'] );
		/**
		 * Filter which taxonomies show the "SEO Checks" term-list column.
		 *
		 * @param string[] $taxes Taxonomy slugs.
		 */
		$taxes = apply_filters( 'nexter_content_seo_checks_taxonomies', array_values( $taxes ) );
		return is_array( $taxes ) ? $taxes : array();
	}

	/**
	 * Register the SEO Checks column + renderer for every covered post type and taxonomy. Runs on
	 * admin_init so all custom types/taxonomies are registered by the time the filters attach.
	 */
	public function register_admin_columns() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		foreach ( $this->seo_checks_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( $this, 'add_seo_checks_column' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_seo_checks_column' ), 10, 2 );
		}
		foreach ( $this->seo_checks_taxonomies() as $tax ) {
			add_filter( "manage_edit-{$tax}_columns", array( $this, 'add_seo_checks_column' ) );
			// Term custom-column is a FILTER (returns the cell string), unlike the post action.
			add_filter( "manage_{$tax}_custom_column", array( $this, 'render_seo_checks_term_column' ), 10, 3 );
		}
	}

	/**
	 * Insert the "SEO Checks" column right after Title (posts) or Name (terms); append as fallback.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_seo_checks_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$out      = array();
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( ! $inserted && ( 'title' === $key || 'name' === $key ) ) {
				$out['nxt_seo_checks'] = __( 'SEO Checks', 'nexter-extension' );
				$inserted              = true;
			}
		}
		if ( ! $inserted ) {
			$out['nxt_seo_checks'] = __( 'SEO Checks', 'nexter-extension' );
		}
		return $out;
	}

	/**
	 * Build the badge + details-popover markup shared by post and term columns. All values are
	 * escaped here, so callers can echo/return the result directly.
	 *
	 * @param string $attrs     Pre-escaped data-* attributes for the wrapper (identifies the object).
	 * @param bool   $has_score Whether a score has been computed.
	 * @param int    $score     Cached score 0–100.
	 * @return string
	 */
	private function seo_checks_markup( $attrs, $has_score, $score ) {
		$badge     = $this->seo_score_badge( (int) $score, (bool) $has_score );
		$score_txt = $has_score ? (string) (int) $score : '—';
		$html      = '<div class="nxt-seo-checks" ' . $attrs . '>';
		$html     .= '<button type="button" class="button-link nxt-seo-checks__badge nxt-seo-checks__badge--' . esc_attr( $badge['level'] ) . '" aria-expanded="false">';
		$html     .= '<span class="nxt-seo-checks__score">' . esc_html( $score_txt ) . '</span>';
		$html     .= '<span class="nxt-seo-checks__label">' . esc_html( $badge['label'] ) . '</span>';
		$html     .= '</button>';
		$html     .= '<div class="nxt-seo-checks__detail" hidden></div>';
		$html     .= '</div>';
		return $html;
	}

	/**
	 * Map a cached SEO score to a { level, label } badge. Levels: none | good | fair | poor.
	 *
	 * @param int  $score     Cached score 0–100.
	 * @param bool $has_score Whether a score has been computed yet.
	 * @return array{level:string,label:string}
	 */
	private function seo_score_badge( $score, $has_score ) {
		if ( ! $has_score ) {
			return array(
			'level' => 'none',
			'label' => __( 'Not analyzed', 'nexter-extension' )
			);
		}
		if ( $score >= 70 ) {
			return array(
			'level' => 'good',
			'label' => __( 'Good', 'nexter-extension' )
			);
		}
		if ( $score >= 40 ) {
			return array(
			'level' => 'fair',
			'label' => __( 'Fair', 'nexter-extension' )
			);
		}
		return array(
		'level' => 'poor',
		'label' => __( 'Needs work', 'nexter-extension' )
		);
	}

	/**
	 * Render the "SEO Checks" cell: a badge from the cached score (cheap — no per-row analysis on
	 * list load) plus a Details toggle whose checklist is fetched lazily from the REST analyze
	 * endpoint on first open.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post ID.
	 */
	public function render_seo_checks_column( $column, $post_id ) {
		if ( 'nxt_seo_checks' !== $column ) {
			return;
		}
		// Compute the score live (same inputs as the details popover / editor) so the badge and the
		// popover never disagree, and refresh the cached meta so other readers stay in sync.
		$score = $this->fresh_post_seo_score( (int) $post_id );
		// Markup is fully escaped inside seo_checks_markup().
		echo $this->seo_checks_markup( 'data-post="' . (int) $post_id . '"', ( null !== $score ), (int) $score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Compute the current SEO score for a post using the SAME input resolution as the REST analyze
	 * endpoint (meta title/description → resolved template default → raw), so the list badge always
	 * matches the details popover. Refreshes the cached score/readability meta when it has drifted
	 * (e.g. the debounced recalc cron hasn't run yet).
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Score 0–100, or null when the post can't be analyzed.
	 */
	private function fresh_post_seo_score( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		// READ-ONLY on list render. edit.php calls this for EVERY visible row, for every editor;
		// the previous code ran a full regex content analysis (and up to two update_post_meta
		// writes) per row on a plain GET — N analyses plus racy writes on what should be a
		// read-only page, and incompatible with admin page caching. Persistence is owned by the
		// debounced cron (schedule_score_recalc on save → cron_recalc_score). Here we only READ
		// the stored score, and schedule a one-time backfill when a post has never been scored.
		if ( ! metadata_exists( 'post', $post_id, self::SEO_SCORE ) ) {
			self::schedule_score_recalc( $post_id );
			return null; // Not yet computed — the column shows its neutral/pending state.
		}
		return (int) get_post_meta( $post_id, self::SEO_SCORE, true );
	}

	/**
	 * Term-list custom column callback (a FILTER — returns the cell string). Appends the badge to
	 * the existing cell content when it's our column, otherwise returns the content untouched.
	 *
	 * @param string $content Existing cell HTML.
	 * @param string $column  Column id.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function render_seo_checks_term_column( $content, $column, $term_id ) {
		if ( 'nxt_seo_checks' !== $column ) {
			return $content;
		}
		$term_id = (int) $term_id;
		$term    = get_term( $term_id );
		$tax     = ( $term && ! is_wp_error( $term ) ) ? $term->taxonomy : '';
		// Compute live (same inputs as the term popover) so the badge matches, and refresh cache.
		$score = $this->fresh_term_seo_score( $term_id, $tax );
		$attrs = 'data-term="' . $term_id . '" data-taxonomy="' . esc_attr( $tax ) . '"';
		return $content . $this->seo_checks_markup( $attrs, ( null !== $score ), (int) $score );
	}

	/**
	 * Compute the current SEO score for a term using the SAME input resolution as the term REST
	 * endpoint (term description as content), so the term-list badge matches the popover. Refreshes
	 * the cached score/readability term meta when it has drifted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int|null Score 0–100, or null when the term can't be analyzed.
	 */
	private function fresh_term_seo_score( $term_id, $taxonomy ) {
		$term = $taxonomy ? get_term( $term_id, $taxonomy ) : get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		// READ-ONLY on list render — same rationale as fresh_post_seo_score(). The cached term
		// score is refreshed on edit by on_edited_term(), so here we only READ it. There is no term
		// recalc cron (SCORE_CRON_HOOK is keyed on post IDs), so when a term has never been scored
		// we compute it ONCE and cache it — a single analysis on first view, never on every GET.
		if ( metadata_exists( 'term', $term_id, self::SEO_SCORE ) ) {
			return (int) get_term_meta( $term_id, self::SEO_SCORE, true );
		}
		require_once dirname( __FILE__ ) . '/content-seo/class-nxt-seo-analyzer.php';
		list( $default_title, $default_desc ) = $this->get_resolved_default_title_description_term( $term );
		$focus                                = get_term_meta( $term_id, self::FOCUS_KEYWORD, true );
		$title                                = get_term_meta( $term_id, self::META_TITLE, true );
		$desc                                 = get_term_meta( $term_id, self::META_DESCRIPTION, true );
		$title                                = $title ? $title : ( $default_title ? $default_title : $term->name );
		$desc                                 = $desc ? $desc : ( $default_desc ? $default_desc : wp_trim_words( wp_strip_all_tags( $term->description ), 25 ) );
		$result                               = Nxt_Seo_Analyzer::analyze_post_content( 0, wp_strip_all_tags( $term->description ), $title, $desc, $focus );
		$score                                = (int) $result['score'];
		update_term_meta( $term_id, self::SEO_SCORE, $score );
		update_term_meta( $term_id, self::READABILITY, (int) $result['readability'] );
		return $score;
	}

	/**
	 * Enqueue the lightweight (build-free) column assets on the post-list screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_seo_checks_assets( $hook ) {
		if ( 'edit.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'edit.php' === $hook ) {
			$pt = ( $screen && ! empty( $screen->post_type ) ) ? $screen->post_type : '';
			if ( '' === $pt || ! in_array( $pt, $this->seo_checks_post_types(), true ) ) {
				return;
			}
		} else {
			$tax = ( $screen && ! empty( $screen->taxonomy ) ) ? $screen->taxonomy : '';
			if ( '' === $tax || ! in_array( $tax, $this->seo_checks_taxonomies(), true ) ) {
				return;
			}
		}
		$ver = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '4.6.0';

		wp_enqueue_style( 'nxt-seo-checks-col', NEXTER_EXT_URL . 'assets/css/admin/nexter-seo.css', array(), $ver );

		wp_enqueue_script( 'nxt-seo-checks-col', NEXTER_EXT_URL . 'assets/js/admin/nexter-seo.js', array(), $ver, true );
		wp_localize_script(
			'nxt-seo-checks-col',
			'nxtSeoChecks',
			array(
				'root'     => esc_url_raw( rest_url( self::REST_NAMESPACE . '/seo/post/' ) ),
				'termRoot' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/seo/term/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'loading' => __( 'Analyzing…', 'nexter-extension' ),
					'error'   => __( 'Could not load SEO checks.', 'nexter-extension' ),
					'title'   => __( 'SEO Checks', 'nexter-extension' ),
					'score'   => __( 'Score', 'nexter-extension' ),
					'edit'    => __( 'Edit SEO', 'nexter-extension' ),
				),
			)
		);
	}

	/**
	 * Enqueue Content SEO script on Content SEO page and post edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		$page            = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_seo_page     = ( $hook === 'nexter_page_nxt_content_seo' || $page === 'nxt_content_seo' );
		$is_post_edit    = ( $hook === 'post.php' || $hook === 'post-new.php' );
		$is_term_edit    = false;
		$term_id_for_js  = 0;
		$taxonomy_for_js = '';
		if ( ( $hook === 'edit-tags.php' || $hook === 'term.php' ) && ! empty( $_GET['tag_ID'] ) && ! empty( $_GET['taxonomy'] ) ) {
			$tid = absint( wp_unslash( $_GET['tag_ID'] ) );
			$tax = sanitize_key( wp_unslash( $_GET['taxonomy'] ) );
			if ( $tid && $tax && taxonomy_exists( $tax ) && current_user_can( 'edit_term', $tid ) ) {
				$is_term_edit    = true;
				$term_id_for_js  = $tid;
				$taxonomy_for_js = $tax;
			}
		}
		if ( ! $is_seo_page && ! $is_post_edit && ! $is_term_edit ) {
			return;
		}
		$build_url  = NEXTER_EXT_URL . 'content-seo/build/';
		$build_path = NEXTER_EXT_DIR . 'content-seo/build/';
		if ( ! file_exists( $build_path . 'index.js' ) ) {
			return;
		}
		$ver     = defined( 'NEXTER_EXT_VER' ) ? NEXTER_EXT_VER : '4.6.0';
		$min_sel = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style( 'nexter-select-css', NEXTER_EXT_URL . 'assets/css/extra/select2' . $min_sel . '.css', array(), $ver );
		wp_enqueue_script( 'nexter-select-js', NEXTER_EXT_URL . 'assets/js/extra/select2' . $min_sel . '.js', array( 'jquery' ), $ver, true );

		/*
		 * The design system this UI is built from.
		 *
		 * The Content SEO bundle renders 118 distinct nxp-* classes but ships none of those rules; they
		 * live in the dashboard stylesheet. That used to work by accident, because the dashboard payload
		 * was enqueued on every admin screen — once it was scoped to Nexter's own pages, this panel came
		 * out completely unstyled on post.php, post-new.php and the term screens.
		 *
		 * Enqueued under the DASHBOARD'S OWN HANDLE on purpose. The SEO settings screen is a Nexter page,
		 * so the dashboard payload queues this same file there; reusing the handle lets WordPress dedupe
		 * it to a single request instead of downloading ~155 KB of identical rules twice. Bundling the
		 * rules into the SEO build would have produced exactly that duplication.
		 */
		wp_enqueue_style( 'nexter-welcome-style', NEXTER_EXT_URL . 'dashboard/build/index.css', array(), $ver, 'all' );

		wp_enqueue_style( 'nexter-content-seo', $build_url . 'index.css', array( 'nexter-select-css', 'nexter-welcome-style' ), $ver );
		wp_enqueue_script(
			'nexter-content-seo',
			$build_url . 'index.js',
			array( 'react', 'react-dom', 'wp-element', 'wp-data', 'wp-i18n', 'jquery', 'nexter-select-js' ),
			$ver,
			true
		);
		// The Content SEO UI is a React bundle that translates strings via wp.i18n.__(). Without
		// this call the JSON translation file is never injected into wp.i18n, so every UI string
		// stays in English regardless of locale.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'nexter-content-seo', 'nexter-extension', WP_LANG_DIR . '/plugins/' );
		}
		$post_id = 0;
		if ( $is_post_edit && isset( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] );
		}
		if ( $is_term_edit ) {
			wp_enqueue_style( 'dashicons' );
		}
		$context          = $is_seo_page ? 'standalone' : ( $is_term_edit ? 'term' : 'sidebar' );
		$term_archive_url = '';
		if ( $is_term_edit && $term_id_for_js && $taxonomy_for_js ) {
			$term_obj = get_term( $term_id_for_js, $taxonomy_for_js );
			if ( $term_obj && ! is_wp_error( $term_obj ) ) {
				$link             = get_term_link( $term_obj );
				$term_archive_url = is_wp_error( $link ) ? '' : $link;
			}
		}
		wp_localize_script(
			'nexter-content-seo',
			'nxtContentSeoConfig',
			array(
				'restUrl'             => rest_url( self::REST_NAMESPACE ),
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'postId'              => $post_id,
				'context'             => $context,
				'termId'              => $term_id_for_js,
				'taxonomy'            => $taxonomy_for_js,
				'termArchiveUrl'      => $term_archive_url,
				'version'             => $ver,
				'adminUrl'            => admin_url( 'admin.php?page=nxt_content_seo' ),
				'dashboardUrl'        => admin_url( 'admin.php?page=nexter_welcome' ),
				// White-label branding for the SEO dashboard header, mirroring the main dashboard's
				// shape (dashData.whiteLabelData + dashData.whiteLabel) so both React apps read the
				// same structure. brandname resolves to "Nexter SEO" and brandlogo stays empty (the
				// header then uses its built-in SEO logo) unless a Pro white label overrides them.
				'whiteLabelData'      => array(
					'brandname' => class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_label() : 'Nexter SEO',
					'brandlogo' => class_exists( 'Nexter_Content_SEO' ) ? Nexter_Content_SEO::seo_brand_logo() : '',
				),
				'whiteLabel'          => self::seo_whitelabel_flags(),
				'homePageEditUrl'     => self::get_home_page_edit_url(),
				'isWooCommerceActive' => class_exists( 'WooCommerce' ),
				'postTypes'           => self::get_robots_post_types(),
				'taxonomies'          => self::get_robots_taxonomies(),
				'archives'            => self::get_robots_archives(),
			)
		);
	}

	/**
	 * White-label flag set for the SEO app, mirroring the main dashboard's dashData.whiteLabel:
	 * the Pro white-label option array (tpgb variant under TPGB Pro), or [] on free. Lets the SEO
	 * React app honor the same switches (e.g. nxt_help_link) as the dashboard.
	 *
	 * @return array
	 */
	private static function seo_whitelabel_flags() {
		$flags = defined( 'NXT_PRO_EXT' )
			? Nxt_Options::white_label()
			: ( defined( 'TPGBP_VERSION' ) ? Nxt_Options::tpgb_white_label() : array() );
		return is_array( $flags ) ? $flags : array();
	}

	/**
	 * Get public post types for Robots (No Index / No Follow / No Archive) settings.
	 *
	 * @return array Array of { slug, label }.
	 */
	public static function get_robots_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $slug => $obj ) {
			$out[] = array(
				'slug'  => $slug,
				'label' => $obj->labels->singular_name ?: $obj->label,
			);
		}
		return $out;
	}

	/**
	 * Get public taxonomies for Robots settings.
	 *
	 * @return array Array of { slug, label }.
	 */
	public static function get_robots_taxonomies() {
		$taxes = get_taxonomies( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $taxes as $slug => $obj ) {
			$label = $obj->labels->singular_name ?: $obj->label;
			if ( strpos( (string) $slug, 'pa_' ) === 0 || strpos( (string) $slug, 'product_' ) === 0 ) {
				$label = ! empty( $obj->labels->name ) ? $obj->labels->name : $label;
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Get archive types for Robots settings (search, author, date).
	 *
	 * @return array Array of { slug, label }.
	 */
	public static function get_robots_archives() {
		return array(
			array(
		'slug'  => 'search',
		'label' => __( 'Search Pages', 'nexter-extension' )
		),
			array(
		'slug'  => 'author',
		'label' => __( 'Author Archives', 'nexter-extension' )
		),
			array(
		'slug'  => 'date',
		'label' => __( 'Date Archives', 'nexter-extension' )
		),
		);
	}

	/**
	 * Get URL to edit the home page.
	 * When a static page is set as front page, returns that page's edit link.
	 * Otherwise returns Reading settings URL.
	 *
	 * @return string
	 */
	public static function get_home_page_edit_url() {
		$page_on_front = (int) get_option( 'page_on_front' );
		if ( $page_on_front ) {
			$edit_url = get_edit_post_link( $page_on_front, 'raw' );
			if ( $edit_url ) {
				return $edit_url;
			}
		}
		return admin_url( 'options-reading.php' );
	}
}

Nexter_Content_SeoRank::get_instance();
