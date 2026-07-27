<?php
/**
 * Content SEO – Image SEO (attachment redirect, alt on upload, content/thumbnail enhancement).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Image
 */
class Nexter_Content_SEO_Image {

	/**
	 * Register media and frontend content filters.
	 */
	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'maybe_fill_alt_on_upload' ), 20, 1 );
	}

	/**
	 * Register the_content, post thumbnail, and WooCommerce product image filters.
	 */
	public static function register_frontend_content_hooks() {
		if ( ! self::is_content_processing_enabled() ) {
			return;
		}

		$hooks = array(
			'the_content'         => array(
		'priority' => 11,
		'args'     => 1
		),
			'post_thumbnail_html' => array(
		'priority' => 11,
		'args'     => 5
		),
		);

		if ( class_exists( 'WooCommerce', false ) ) {
			$hooks['woocommerce_single_product_image_thumbnail_html'] = array(
			'priority' => 11,
			'args'     => 2
			);
		}

		foreach ( $hooks as $hook => $cfg ) {
			add_filter( $hook, array( __CLASS__, 'filter_enhance_images' ), $cfg['priority'], $cfg['args'] );
		}
	}

	/**
	 * Whether frontend HTML should be processed for missing image alt (and optional title).
	 *
	 * @return bool
	 */
	public static function is_content_processing_enabled() {
		$options     = Nexter_Content_SEO::get_options();
		$from_option = ! empty( $options['auto_alt_text'] );
		/**
		 * Enable Nexter content image enhancement (missing alt in rendered HTML).
		 *
		 * @param bool $from_option Value from `auto_alt_text` setting.
		 */
		return (bool) apply_filters( 'nexter_content_seo_image_content_processing_enabled', $from_option );
	}

	/**
	 * Filter callback: enhance images in HTML (variable hook arity).
	 *
	 * @param string      $html HTML fragment.
	 * @param int|null    $arg2 post_thumbnail_html: post ID; WooCommerce: thumbnail attachment ID; ignored for the_content.
	 * @param mixed       $arg3 Unused (thumbnail API).
	 * @param mixed       $arg4 Unused.
	 * @param mixed       $arg5 Unused.
	 * @return string
	 */
	public static function filter_enhance_images( $html, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null ) {
		if ( ! is_string( $html ) || $html === '' || strpos( $html, '<img' ) === false ) {
			return $html;
		}

		$hook    = current_filter();
		$post_id = null;

		if ( 'post_thumbnail_html' === $hook ) {
			$post_id = $arg2;
		} elseif ( 'woocommerce_single_product_image_thumbnail_html' === $hook ) {
			// WC passes ( $html, $thumbnail_id ); use product post for %title% / %slug%.
			if ( function_exists( 'is_product' ) && is_product() ) {
				$post_id = get_the_ID();
			}
		}
		// the_content: $post_id null → get_post() uses global $post when available.

		return self::enhance_images_only( $html, $post_id );
	}

	/**
	 * Process images only (content and thumbnail HTML fragments).
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string
	 */
	public static function enhance_images_only( $content, $post_id = null ) {
		if ( ! self::is_content_processing_enabled() || ! is_string( $content ) || $content === '' || strpos( $content, '<img' ) === false ) {
			return $content;
		}

		// WP_HTML_Tag_Processor (WP 6.2+) edits the single attribute we need in place: it
		// preserves boolean/unquoted attributes (data-no-lazy, etc.) and reports alt="" as an
		// empty string rather than "absent", so decorative images are never overwritten.
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return self::enhance_images_with_processor( $content, $post_id );
		}

		return self::enhance_images_only_legacy( $content, $post_id );
	}

	/**
	 * Enhance images using WP_HTML_Tag_Processor (set only alt/title, in place).
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string
	 */
	private static function enhance_images_with_processor( $content, $post_id ) {
		$options   = Nexter_Content_SEO::get_options();
		$add_alt   = ! empty( $options['auto_alt_text'] );
		$add_title = (bool) apply_filters( 'nexter_content_seo_image_seo_enable_title', false );

		if ( ! $add_alt && ! $add_title ) {
			return $content;
		}

		$context   = self::build_processing_context( $post_id );
		$alt_tpl   = (string) apply_filters( 'nexter_content_seo_image_seo_alt_template', '%filename%' );
		$title_tpl = (string) apply_filters( 'nexter_content_seo_image_seo_title_template', '%title%' );

		$processor = new WP_HTML_Tag_Processor( $content );
		$changed   = false;

		while ( $processor->next_tag( 'img' ) ) {
			// get_attribute() returns null when the attribute is absent and '' for alt="".
			// We only fill a genuinely missing attribute — an intentional empty alt (decorative
			// image) is left exactly as authored.
			$needs_alt   = $add_alt && ( null === $processor->get_attribute( 'alt' ) );
			$needs_title = $add_title && ( null === $processor->get_attribute( 'title' ) );

			if ( ! $needs_alt && ! $needs_title ) {
				continue;
			}

			$src = self::resolve_src_from_processor( $processor );
			if ( '' === $src ) {
				continue;
			}
			$filename = self::extract_clean_filename( $src );

			if ( $needs_alt ) {
				$alt = self::resolve_template( $alt_tpl, $context, $filename );
				/**
				 * Whether a generated alt is descriptive enough to inject. Filename-derived junk
				 * (e.g. "Img 1234", "DSC0001", hashes, dimension strings) is skipped by default.
				 *
				 * @param bool   $descriptive Heuristic result.
				 * @param string $alt         Resolved alt text.
				 * @param string $filename    Clean filename.
				 * @param object $context     Processing context.
				 */
				$descriptive = (bool) apply_filters( 'nexter_content_seo_image_seo_alt_is_descriptive', self::is_descriptive_alt( $alt ), $alt, $filename, $context );
				if ( '' !== $alt && $descriptive ) {
					$processor->set_attribute( 'alt', $alt );
					$changed = true;
				}
			}

			if ( $needs_title ) {
				$title = self::resolve_template( $title_tpl, $context, $filename );
				if ( '' !== $title ) {
					$processor->set_attribute( 'title', $title );
					$changed = true;
				}
			}
		}

		return $changed ? $processor->get_updated_html() : $content;
	}

	/**
	 * Resolve the effective image source from a Tag_Processor cursor (lazy-load aware).
	 *
	 * @param WP_HTML_Tag_Processor $processor Cursor positioned on an <img>.
	 * @return string
	 */
	private static function resolve_src_from_processor( $processor ) {
		foreach ( array( 'data-src', 'data-lazy-src', 'data-layzr', 'src' ) as $attr ) {
			$val = $processor->get_attribute( $attr );
			if ( is_string( $val ) && '' !== $val ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * Heuristic: is a generated alt descriptive, or filename-derived junk?
	 *
	 * @param string $text Candidate alt text.
	 * @return bool
	 */
	private static function is_descriptive_alt( $text ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) < 3 : strlen( $text ) < 3 ) {
			return false;
		}

		// Needs at least a few actual letters — rejects "1920 1080", "20240101", etc.
		$letters    = preg_replace( '/[^\p{L}]+/u', '', $text );
		$letter_len = is_string( $letters ) ? ( function_exists( 'mb_strlen' ) ? mb_strlen( $letters ) : strlen( $letters ) ) : 0;
		if ( $letter_len < 3 ) {
			return false;
		}

		$lower    = strtolower( $text );
		$patterns = array(
			'/^(img|image|images|dsc|dscf|dscn|pxl|gopr|mvimg|photo|picture|pic|untitled|screenshot|screen shot|capture|scaled|cropped|copy|final|edited|e\d+)[\s_-]*(\d+[\s_-]*)*$/',
			'/^[a-f0-9]{8,}$/',     // hexadecimal hash filenames.
			'/^\d+(\s+\d+)*$/',      // pure numbers / dimensions.
		);
		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $lower ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Legacy regex-based enhancement (only when WP_HTML_Tag_Processor is unavailable).
	 *
	 * @param string   $content Content to enhance.
	 * @param int|null $post_id Post ID context.
	 * @return string
	 */
	private static function enhance_images_only_legacy( $content, $post_id = null ) {
		$clean_content = self::remove_script_style_tags( $content );
		$image_tags    = self::extract_processable_images( $clean_content );

		if ( empty( $image_tags ) ) {
			return $content;
		}

		return self::process_images( $content, $image_tags, $post_id );
	}

	/**
	 * Extract images that need processing (missing alt or title).
	 *
	 * @param string $content Clean content.
	 * @return array<int, string> Image tags.
	 */
	public static function extract_processable_images( $content ) {
		$missing_alt   = self::extract_images_missing_alt( $content );
		$missing_title = self::extract_images_missing_title( $content );

		if ( empty( $missing_alt ) && empty( $missing_title ) ) {
			return array();
		}

		return array_values( array_unique( array_merge( $missing_alt, $missing_title ) ) );
	}

	/**
	 * Process image tags in content.
	 *
	 * @param string        $content    Original content.
	 * @param array<string> $image_tags Image tags to process.
	 * @param int|null      $post_id    Post context.
	 * @return string
	 */
	public static function process_images( $content, $image_tags, $post_id ) {
		$context = self::build_processing_context( $post_id );
		return self::enhance_image_tags( $content, $image_tags, $context );
	}

	/**
	 * Redirect attachment single views to parent post or homepage.
	 */
	public static function maybe_redirect_attachment() {
		$options = Nexter_Content_SEO::get_options();
		if ( empty( $options['redirect_attachment_pages'] ) ) {
			return;
		}
		if ( ! is_attachment() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}
		$target = home_url( '/' );
		if ( ! empty( $post->post_parent ) ) {
			$parent = get_permalink( (int) $post->post_parent );
			if ( $parent ) {
				$target = $parent;
			}
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * On upload: optional AI alt (Pro + settings) or non-AI alt from filename / parent title.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function maybe_fill_alt_on_upload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( is_string( $existing ) && '' !== trim( $existing ) ) {
			return;
		}

		$options          = Nexter_Content_SEO::get_options();
		$auto_alt_enabled = ! empty( $options['auto_alt_text'] );

		if ( $auto_alt_enabled ) {
			$alt = self::suggest_alt_without_ai( $attachment_id );
			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}

	/**
	 * Bulk-generate alt text for existing image attachments that have none. Processes one batch
	 * per call (so the UI can loop with progress) using the same filename/parent-title heuristic
	 * as the on-upload path — no AI. Never overwrites an existing alt.
	 *
	 * @param int $limit Max attachments to scan this batch (clamped 1..200).
	 * @return array{scanned:int, updated:int, remaining:int, done:bool}
	 */
	public static function bulk_fill_missing_alt( $limit = 50 ) {
		$limit = max( 1, min( 200, (int) $limit ) );

		$query = new WP_Query(
			array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			// Only attachments with no alt meta or an empty one.
			'meta_query'             => array(
				'relation' => 'OR',
				array(
			'key'     => '_wp_attachment_image_alt',
			'compare' => 'NOT EXISTS'
			),
				array(
			'key'     => '_wp_attachment_image_alt',
			'value'   => '',
			'compare' => '='
			),
			 ),
			) 
		);

		$ids     = $query->posts;
		$scanned = count( $ids );
		$updated = 0;

		foreach ( $ids as $id ) {
			$id = (int) $id;
			// Guard: skip if something set an alt between the query and now.
			$existing = get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( is_string( $existing ) && '' !== trim( $existing ) ) {
				continue;
			}
			$alt = self::suggest_alt_without_ai( $id );
			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				++$updated;
			}
		}

		$total_missing = (int) $query->found_posts;
		$remaining     = max( 0, $total_missing - $updated );
		// A FULL batch that filled nothing means the images still missing alt are un-fillable by
		// the heuristic (no descriptive filename, no usable parent title). We must stop — the same
		// rows would come back next batch and loop forever — but that's NOT "complete": flag it as
		// `stalled` with the true `remaining` count so the UI can show "N images couldn't be
		// auto-filled" instead of silently reporting done with a positive backlog.
		$stalled = ( $scanned === $limit && 0 === $updated && $remaining > 0 );
		// Done only when the queue is genuinely exhausted (partial batch or nothing left), or when
		// stalled on un-fillable rows (loop guard). Distinguished from `stalled` above.
		$done = ( $scanned < $limit ) || ( 0 === $remaining ) || $stalled;

		return array(
			'scanned'   => $scanned,
			'updated'   => $updated,
			'remaining' => $remaining,
			'stalled'   => $stalled,
			'done'      => $done,
		);
	}

	/**
	 * Build alt text from parent post title or sanitized filename.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function suggest_alt_without_ai( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return '';
		}

		// Prefer the image's own filename (image-specific) so different images in one post get
		// distinct alt text. Only fall back to the parent post title when the filename is
		// non-descriptive junk (IMG_1234, DSC0001, hashes, pure numbers) — previously the parent
		// title was used for EVERY image, giving them all the same alt.
		$filename_alt = '';
		$file         = get_attached_file( $attachment_id );
		if ( $file && is_string( $file ) ) {
			$base         = basename( $file );
			$base         = preg_replace( '/\.[^.]+$/', '', $base );
			$base         = str_replace( array( '-', '_' ), ' ', $base );
			$base         = preg_replace( '/\s+/', ' ', $base );
			$filename_alt = trim( (string) $base );
		}

		if ( '' !== $filename_alt && self::is_descriptive_alt( $filename_alt ) ) {
			return $filename_alt;
		}

		// Non-descriptive filename → fall back to the parent post title.
		if ( ! empty( $post->post_parent ) ) {
			$title = trim( wp_strip_all_tags( (string) get_the_title( (int) $post->post_parent ) ) );
			if ( '' !== $title ) {
				return $title;
			}
		}

		return $filename_alt;
	}

	/**
	 * @param string $content Content.
	 * @return array<int, string>
	 */
	private static function extract_images_missing_alt( $content ) {
		// An alt attribute with ANY value — including alt="" (decorative) — counts as present so
		// the legacy path never overwrites an intentional empty alt.
		preg_match_all( '/<img(?![^>]*alt\s*=\s*["\'][^"\']*["\'])[^>]*>/i', $content, $matches );
		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * @param string $content Content.
	 * @return array<int, string>
	 */
	private static function extract_images_missing_title( $content ) {
		preg_match_all( '/<img(?![^>]*title\s*=\s*["\'][^"\'\s][^"\']*["\'])[^>]*>/i', $content, $matches );
		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * @param int|null $post_id Post ID.
	 * @return object{title: string, slug: string, site_name: string}
	 */
	private static function build_processing_context( $post_id ) {
		$post = get_post( $post_id );

		return (object) array(
			'title'     => $post && isset( $post->post_title ) ? (string) $post->post_title : '',
			'slug'      => $post && isset( $post->post_name ) ? (string) $post->post_name : '',
			'site_name' => get_bloginfo( 'name' ),
		);
	}

	/**
	 * @param string                                                 $content Original content.
	 * @param array<string>                                          $images  Image tag array.
	 * @param object{title: string, slug: string, site_name: string} $context Processing context.
	 * @return string
	 */
	private static function enhance_image_tags( $content, $images, $context ) {
		foreach ( $images as $original_tag ) {
			$enhanced_tag = self::enhance_single_image( $original_tag, $context );

			if ( $enhanced_tag !== $original_tag ) {
				$content = str_replace( $original_tag, $enhanced_tag, $content );
			}
		}

		return $content;
	}

	/**
	 * @param string                                                 $tag     Original image tag.
	 * @param object{title: string, slug: string, site_name: string} $context Processing context.
	 * @return string
	 */
	private static function enhance_single_image( $tag, $context ) {
		$attributes = self::parse_image_attributes( $tag );

		if ( empty( $attributes ) ) {
			return $tag;
		}

		$image_src = self::resolve_image_source( $attributes );

		if ( '' === $image_src ) {
			return $tag;
		}

		$enhancements = self::calculate_needed_enhancements( $attributes );
		/**
		 * @param array<string, string> $enhancements Attribute => template.
		 * @param array<string, string> $attributes   Parsed attributes.
		 * @param string                $image_src    Resolved src.
		 * @param object                $context      Context object.
		 */
		$enhancements = apply_filters( 'nexter_content_seo_image_seo_enhancements', $enhancements, $attributes, $image_src, $context );

		if ( empty( $enhancements ) ) {
			return $tag;
		}

		return self::apply_enhancements( $tag, $attributes, $enhancements, $image_src, $context );
	}

	/**
	 * @param string $tag Image tag.
	 * @return array<string, string>
	 */
	private static function parse_image_attributes( $tag ) {
		$attributes = array();

		if ( preg_match_all( '/([a-zA-Z_:][a-zA-Z0-9\-_.:]*)=["\']([^"\']*)["\']/', $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes[ $match[1] ] = $match[2];
			}
		}

		return $attributes;
	}

	/**
	 * @param array<string, string> $attributes Image attributes.
	 * @return string
	 */
	private static function resolve_image_source( $attributes ) {
		$lazy_attrs = array( 'data-src', 'data-lazy-src', 'data-layzr' );

		foreach ( $lazy_attrs as $attr ) {
			if ( ! empty( $attributes[ $attr ] ) ) {
				return $attributes[ $attr ];
			}
		}

		return isset( $attributes['src'] ) ? $attributes['src'] : '';
	}

	/**
	 * @param array<string, string> $attributes Current attributes.
	 * @return array<string, string> Needed enhancements (attr => template).
	 */
	private static function calculate_needed_enhancements( $attributes ) {
		$needed       = array();
		$options      = Nexter_Content_SEO::get_options();
		$auto_add_alt = ! empty( $options['auto_alt_text'] );

		// Use !isset (not empty): parse_image_attributes() records alt="" as an empty string, so
		// isset() is true for a decorative alt="" and false only when the attribute is genuinely
		// absent. This stops the title path from re-adding alt to an intentionally-empty alt.
		if ( $auto_add_alt && ! isset( $attributes['alt'] ) ) {
			$needed['alt'] = (string) apply_filters( 'nexter_content_seo_image_seo_alt_template', '%filename%' );
		}

		$title_on = (bool) apply_filters( 'nexter_content_seo_image_seo_enable_title', false );

		if ( $title_on && ! isset( $attributes['title'] ) ) {
			$needed['title'] = (string) apply_filters( 'nexter_content_seo_image_seo_title_template', '%title%' );
		}

		return $needed;
	}

	/**
	 * @param array<string, string> $attributes   Original attributes.
	 * @param array<string, string> $enhancements Needed enhancements.
	 * @param string                $src          Image source.
	 * @param object                $context      Context.
	 * @return string
	 */
	private static function apply_enhancements( $tag, $attributes, $enhancements, $src, $context ) {
		$filename = self::extract_clean_filename( $src );
		$inject   = array();

		foreach ( $enhancements as $attr => $template ) {
			$value = self::resolve_template( $template, $context, $filename );
			if ( '' === $value ) {
				continue;
			}
			// Mirror the WP_HTML_Tag_Processor path: never inject filename-derived junk alt
			// ("Img 1234", "DSC0001", hashes, dimension strings).
			if ( 'alt' === $attr ) {
				$descriptive = (bool) apply_filters( 'nexter_content_seo_image_seo_alt_is_descriptive', self::is_descriptive_alt( $value ), $value, $filename, $context );
				if ( ! $descriptive ) {
					continue;
				}
			}
			$inject[ $attr ] = $value;
		}

		if ( empty( $inject ) ) {
			return $tag;
		}

		// Insert ONLY the new attribute(s) into the ORIGINAL tag, in place. Unlike rebuilding the
		// tag from parsed attributes, this preserves boolean and unquoted attributes
		// (data-no-lazy, etc.) exactly as authored.
		return self::insert_img_attributes( $tag, $inject );
	}

	/**
	 * Insert new attributes into an <img> tag without disturbing existing ones.
	 *
	 * Splices the attribute string in right after "<img" using string offsets (not a regex
	 * replacement), so attribute values containing "$" or "\" can never be mis-interpreted as
	 * backreferences, and boolean/unquoted attributes already on the tag are preserved verbatim.
	 *
	 * @param string                $tag   Original <img> tag.
	 * @param array<string, string> $attrs Attributes to add (name => value).
	 * @return string
	 */
	private static function insert_img_attributes( $tag, $attrs ) {
		$insert = '';
		foreach ( $attrs as $name => $value ) {
			$insert .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		if ( '' === $insert ) {
			return $tag;
		}
		$pos = stripos( $tag, '<img' );
		if ( false === $pos ) {
			return $tag;
		}
		$at = $pos + 4; // Just after "<img".
		return substr( $tag, 0, $at ) . $insert . substr( $tag, $at );
	}

	/**
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function extract_clean_filename( $url ) {
		if ( '' === $url ) {
			return '';
		}

		return self::sanitize_filename( self::get_basename_without_extension( $url ) );
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	private static function get_basename_without_extension( $url ) {
		$filename = basename( $url );
		$result   = preg_replace( '/\.[^.]+$/', '', $filename );
		return $result !== null ? $result : $filename;
	}

	/**
	 * @param string $filename Raw filename.
	 * @return string
	 */
	private static function sanitize_filename( $filename ) {
		$cleaned      = preg_replace( '/[-_]+/', ' ', $filename );
		$safe_cleaned = $cleaned !== null ? $cleaned : $filename;
		return ucwords( trim( $safe_cleaned ) );
	}

	/**
	 * @param string $template Template string.
	 * @param object $context  Context.
	 * @param string $filename Clean filename.
	 * @return string
	 */
	private static function resolve_template( $template, $context, $filename ) {
		if ( '' === $template ) {
			return '';
		}

		$variables = self::build_variable_map( $context, $filename );

		$resolved = trim( strtr( $template, $variables ) );
		/**
		 * @param string $resolved Resolved string.
		 * @param string $template Original template.
		 * @param object $context  Context.
		 * @param string $filename Filename.
		 */
		return (string) apply_filters( 'nexter_content_seo_image_seo_resolved_text', $resolved, $template, $context, $filename );
	}

	/**
	 * @param object $context  Context.
	 * @param string $filename Filename.
	 * @return array<string, string>
	 */
	private static function build_variable_map( $context, $filename ) {
		$default_vars = array(
			'%title%'     => $context->title,
			'%filename%'  => $filename,
			'%site_name%' => $context->site_name,
			'%slug%'      => $context->slug,
		);

		return apply_filters( 'nexter_content_seo_image_seo_variable_map', $default_vars, $context, $filename );
	}

	/**
	 * @param string $content Raw content.
	 * @return string
	 */
	private static function remove_script_style_tags( $content ) {
		$result = preg_replace( '/<(script|style)[^>]*?>.*?<\/\1>/si', '', $content );
		return $result !== null ? $result : $content;
	}
}
