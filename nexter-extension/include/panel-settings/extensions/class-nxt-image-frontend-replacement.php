<?php
/**
 * Image Frontend Replacement
 *
 * Handles frontend output buffer and content filtering to replace
 * image URLs with optimized/WebP/AVIF versions.
 * Extracted from Nexter_Ext_Image_Upload_Optimization.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Image_Frontend_Replacement {

	/**
	 * Parent optimizer instance for shared data access.
	 *
	 * @var Nexter_Ext_Image_Upload_Optimization
	 */
	private $parent;

	/** @var array URL-to-optimised-URL cache. */
	private static $direct_replacement_cache = array();

	/** @var array|null Browser accept header capabilities (avif/webp). */
	private static $browser_support = null;

	/** @var array Per-URL file-driven variants (avif/webp/original) used by the <picture> output. */
	private static $variant_cache = array();

	public function __construct( $parent ) {
		$this->parent = $parent;
	}

	/**
	 * Register the_content / post_thumbnail_html / wp_get_attachment_image and output buffer for Direct Replacement (front-end only).
	 * Direct Replacement - Always on when image optimisation enabled.
	 */
	public function register_direct_replacement_hooks() {
		$settings = $this->parent->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		add_filter( 'the_content', array( $this, 'replace_img_with_webp' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'replace_img_with_webp' ), 999 );
		add_filter( 'wp_get_attachment_image', array( $this, 'replace_img_with_webp' ), 999 );
		add_action( 'template_redirect', array( $this, 'start_direct_replacement_buffer' ), 0 );
	}

	/**
	 * Start output buffer on front-end to replace img/background URLs in full HTML (e.g. inline styles).
	 */
	public function start_direct_replacement_buffer() {
		if ( is_admin() ) {
			return;
		}
		ob_start( array( $this, 'filter_global_buffer' ) );
	}

	/**
	 * Get optimised URL for a given original image URL if optimised file exists (Direct Replacement).
	 * Prefers AVIF if browser supports it, else WebP.
	 *
	 * @param string $url Original image URL (e.g. from uploads).
	 * @return string|false Optimised URL or false.
	 */
	private function get_optimized_url_for_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$upload_dir = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_path  = wp_normalize_path( $upload_dir['basedir'] );

		// Only process URLs from uploads directory
		if ( strpos( $url, $base_url ) === false ) {
			return false;
		}

		// Check browser support for optimised formats (avif/webp); original format works in all browsers.
		if ( null === self::$browser_support ) {
			$accept                = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
			self::$browser_support = array(
				'avif' => ( strpos( $accept, 'image/avif' ) !== false ),
				'webp' => ( strpos( $accept, 'image/webp' ) !== false ),
			);
		}

		// Extract relative path from URL
		$rel = str_replace( $base_url, '', $url );
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		// Remove query strings and fragments
		$rel = preg_replace( '/[?#].*$/', '', $rel );

		// Skip if empty after cleaning
		if ( empty( $rel ) ) {
			return false;
		}

		// Already a next-gen source (.webp / .avif): serve it as-is. The optimizer never converts
		// these (see Nxt_Image_Processor::is_next_gen_source), so mapping them here would only point
		// at a stale/broken "name.webp.webp" file left by an earlier build.
		if ( preg_match( '/\.(webp|avif)$/i', $rel ) ) {
			return false;
		}

		// Convert to absolute path
		$abs = wp_normalize_path( $base_path . '/' . $rel );

		// The original may be gone from uploads (kept in the optimizer's backups), so only the
		// optimised copy has to exist. A path with .. is never mapped.
		if ( false !== strpos( $rel, '..' ) ) {
			return false;
		}

		// Check cache, but verify file still exists before returning cached URL
		// This handles cases where optimised files were deleted after restore
		$opt_base = $this->parent->get_output_path( $abs );
		if ( isset( self::$direct_replacement_cache[ $url ] ) ) {
			$cached_url = self::$direct_replacement_cache[ $url ];
			if ( false !== $cached_url ) {
				// Verify the cached optimised file still exists
				$format = preg_match( '/\.(webp|avif)$/i', $cached_url, $format_match ) ? strtolower( $format_match[1] ) : '';
				if ( 'avif' === $format && file_exists( $opt_base . '.avif' ) ) {
					return $cached_url;
				} elseif ( 'webp' === $format && file_exists( $opt_base . '.webp' ) ) {
					return $cached_url;
				} elseif ( '' === $format && file_exists( $opt_base ) ) {
					return $cached_url;
				}
				// Cached file no longer exists, clear cache and continue to check
				unset( self::$direct_replacement_cache[ $url ] );
			} else {
				// Cached as false (no optimised version), return false
				return false;
			}
		}

		$new_url = false;

		// Check for optimised versions (prefer AVIF for AVIF-capable browsers, fallback to WebP, then original).
		// Smart mode keeps BOTH .avif and .webp on disk — we always prefer AVIF when the browser supports it,
		// regardless of which format was stored as the "primary" in attachment metadata.
		if ( self::$browser_support['avif'] && file_exists( $opt_base . '.avif' ) ) {
			$new_url = $this->parent->get_output_url( $abs ) . '.avif';
		} elseif ( self::$browser_support['webp'] && file_exists( $opt_base . '.webp' ) ) {
			$new_url = $this->parent->get_output_url( $abs ) . '.webp';
		} elseif ( file_exists( $opt_base ) ) {
			$new_url = $this->parent->get_output_url( $abs );
		}

		// Cache result (even if false to avoid repeated file checks)
		self::$direct_replacement_cache[ $url ] = $new_url;

		return $new_url;
	}

	/**
	 * Replace img src and background-image URLs in content with optimised .webp/.avif (Direct Replacement).
	 *
	 * @param string $content HTML/content.
	 * @return string
	 */
	public function replace_img_with_webp( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		// Images become <picture> so the browser picks AVIF/WebP itself instead of the page guessing from
		// the Accept header. Existing pictures, scripts, styles and similar blocks are split out untouched.
		$parts = preg_split( '#(<picture\b.*?</picture>|<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<noscript\b.*?</noscript>|<template\b.*?</template>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			$parts = array( $content );
		}
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$done        = preg_replace_callback( '/<img\b[^>]*>/i', array( $this, 'picture_replacement_callback' ), $part );
				$parts[ $i ] = null === $done ? $part : $done;
			}
		}
		$content = implode( '', $parts );

		// Replace background-image URLs
		$bg_pattern = '/url\(\s*["\']?([^"\'\)]+)\.(jpg|jpeg|png)([^"\'\)]*)["\']?\s*\)/i';
		$content    = preg_replace_callback( $bg_pattern, array( $this, 'background_replacement_callback' ), $content );

		return $content;
	}

	/**
	 * The Accept-header based img handling, kept for images that cannot be wrapped in <picture>.
	 *
	 * @param string $content HTML containing img tags.
	 * @return string
	 */
	private function legacy_replace_images( $content ) {
		// First pass: Replace src and srcset for images with original formats (jpg/jpeg/png)
		$img_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		$content     = preg_replace_callback( $img_pattern, array( $this, 'direct_replacement_callback' ), $content );

		// Second pass: Replace srcset for images that already have optimised src (webp/avif) but srcset still has original formats
		$img_with_srcset_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(webp|avif)([^"\']*)["\']([^>]*?)>/i';
		$content                 = preg_replace_callback( $img_with_srcset_pattern, array( $this, 'optimize_srcset_callback' ), $content );

		// Third pass: Revert optimised URLs back to original if optimised files don't exist
		$img_optimized_pattern = '/<img([^>]*?)src=["\']([^"\']*nexter-optimizer[^"\']+)\.(webp|avif|jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		return preg_replace_callback( $img_optimized_pattern, array( $this, 'revert_optimized_url_callback' ), $content );
	}

	/**
	 * Wrap one img tag in <picture> when an AVIF/WebP copy exists on disk, else use the legacy swap.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function picture_replacement_callback( $matches ) {
		$tag = $matches[0];
		if ( $this->picture_allowed() ) {
			$picture = $this->build_picture( $tag );
			if ( false !== $picture ) {
				return $picture;
			}
		}
		return $this->legacy_replace_images( $tag );
	}

	/**
	 * Picture markup is for the public page only, not the editor, REST replies or feeds.
	 *
	 * @return bool
	 */
	private function picture_allowed() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return ! ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) );
	}

	/**
	 * Build <picture> for an img tag, or false when no AVIF/WebP copy exists for it.
	 *
	 * @param string $tag The img tag.
	 * @return string|false
	 */
	private function build_picture( $tag ) {
		$src = $this->get_attr( $tag, 'src' );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return false;
		}

		$main = $this->get_variants_for_url( $src );
		if ( ! $main ) {
			return false;
		}

		$srcset  = $this->get_attr( $tag, 'srcset' );
		$sizes   = $this->get_attr( $tag, 'sizes' );
		$sources = '';
		$types   = array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
		);
		foreach ( $types as $format => $mime ) {
			$set = $this->picture_srcset( $src, $srcset, $main, $format );
			if ( '' !== $set ) {
				$sources .= '<source type="' . $mime . '" srcset="' . esc_attr( $set ) . '"' . ( '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
			}
		}
		if ( '' === $sources ) {
			return false;
		}

		// The img stays as the fallback, pointed at a file that exists.
		$img = $tag;
		if ( '' !== $main['fallback'] && $main['fallback'] !== $src ) {
			$img = $this->set_attr( $img, 'src', $main['fallback'] );
		}
		if ( '' !== $srcset ) {
			$img = $this->set_attr( $img, 'srcset', $this->fallback_srcset( $srcset ) );
		}

		return '<picture class="nxt-optimized-picture" style="display:contents">' . $sources . $img . '</picture>';
	}

	/**
	 * Build the srcset for one <source>: the entries of the img's srcset that have a copy in this format.
	 *
	 * @param string $src    The img src.
	 * @param string $srcset The img srcset, or ''.
	 * @param array  $main   Variants of the src.
	 * @param string $format 'avif' or 'webp'.
	 * @return string
	 */
	private function picture_srcset( $src, $srcset, $main, $format ) {
		$entries = '' !== trim( $srcset ) ? explode( ',', $srcset ) : array( $src );
		$out     = array();
		foreach ( $entries as $entry ) {
			$bits = preg_split( '/\s+/', trim( $entry ), 2 );
			$url  = trim( $bits[0] );
			if ( '' === $url ) {
				continue;
			}
			$variants = ( $url === $src ) ? $main : $this->get_variants_for_url( $url );
			if ( $variants && '' !== $variants[ $format ] ) {
				$out[] = $variants[ $format ] . ( isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '' );
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * The img's own srcset with any entry whose file is gone pointed at the best copy that exists.
	 *
	 * @param string $srcset The img srcset.
	 * @return string
	 */
	private function fallback_srcset( $srcset ) {
		$out = array();
		foreach ( explode( ',', $srcset ) as $entry ) {
			$bits = preg_split( '/\s+/', trim( $entry ), 2 );
			$url  = trim( $bits[0] );
			if ( '' === $url ) {
				continue;
			}
			$variants = $this->get_variants_for_url( $url );
			if ( $variants && '' !== $variants['fallback'] ) {
				$url = $variants['fallback'];
			}
			$out[] = $url . ( isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '' );
		}
		return implode( ', ', $out );
	}

	/**
	 * Which optimised copies exist on disk for an uploads or optimizer URL. Driven by files only.
	 *
	 * @param string $url Image URL.
	 * @return array|false avif, webp, opt, has_original, fallback; false when the URL is not ours.
	 */
	private function get_variants_for_url( $url ) {
		$clean = preg_replace( '/[?#].*$/', '', (string) $url );
		if ( ! isset( self::$variant_cache[ $clean ] ) ) {
			self::$variant_cache[ $clean ] = $this->resolve_variants( $clean );
		}
		return self::$variant_cache[ $clean ];
	}

	/**
	 * Work out the variants for one clean URL; see get_variants_for_url().
	 *
	 * @param string $clean URL without query or fragment.
	 * @return array|false
	 */
	private function resolve_variants( $clean ) {
		$upload_dir = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$plain      = preg_replace( '#^(https?:)?//#i', '//', $clean );
		$uploads    = rtrim( preg_replace( '#^(https?:)?//#i', '//', $upload_dir['baseurl'] ), '/' ) . '/';
		$optimizer  = preg_replace( '#^(https?:)?//#i', '//', content_url( '/nexter-optimizer/uploads/' ) );

		if ( 0 === strpos( $plain, $optimizer ) ) {
			$rel = preg_replace( '/\.(webp|avif)$/i', '', substr( $plain, strlen( $optimizer ) ) );
		} elseif ( 0 === strpos( $plain, $uploads ) ) {
			$rel = substr( $plain, strlen( $uploads ) );
		} else {
			return false;
		}

		$rel_fs = rawurldecode( ltrim( $rel, '/' ) );
		if ( '' === $rel_fs || false !== strpos( $rel_fs, '..' ) || ! preg_match( '/\.(jpe?g|png)$/i', $rel_fs ) ) {
			return false;
		}

		$opt_base = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_fs );
		$opt_url  = content_url( '/nexter-optimizer/uploads/' . ltrim( $rel, '/' ) );
		$orig_url = rtrim( $upload_dir['baseurl'], '/' ) . '/' . ltrim( $rel, '/' );
		$has      = static function ( $path ) {
			return is_file( $path ) && filesize( $path ) > 0;
		};

		$avif         = $has( $opt_base . '.avif' ) ? $opt_url . '.avif' : '';
		$webp         = $has( $opt_base . '.webp' ) ? $opt_url . '.webp' : '';
		$opt          = $has( $opt_base ) ? $opt_url : '';
		$has_original = is_file( wp_normalize_path( $upload_dir['basedir'] . '/' . $rel_fs ) );

		if ( '' !== $opt ) {
			$fallback = $opt;
		} elseif ( $has_original ) {
			$fallback = $orig_url;
		} else {
			$fallback = '' !== $webp ? $webp : $avif;
		}

		return array(
			'avif'         => $avif,
			'webp'         => $webp,
			'opt'          => $opt,
			'has_original' => $has_original,
			'fallback'     => $fallback,
		);
	}

	/**
	 * Read one attribute value from a tag; '' when absent.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private function get_attr( $tag, $name ) {
		if ( preg_match( '/(?<![\w:-])' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
			return '' !== $m[1] ? $m[1] : ( isset( $m[2] ) ? $m[2] : '' );
		}
		return '';
	}

	/**
	 * Set one attribute value on a tag, keeping the rest as it was.
	 *
	 * @param string $tag   Tag HTML.
	 * @param string $name  Attribute name.
	 * @param string $value New value.
	 * @return string
	 */
	private function set_attr( $tag, $name, $value ) {
		$replacement = $name . '="' . esc_attr( $value ) . '"';
		$done        = preg_replace_callback(
			'/(?<![\w:-])' . preg_quote( $name, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\')/i',
			static function () use ( $replacement ) {
				return $replacement;
			},
			$tag,
			1
		);
		return null === $done ? $tag : $done;
	}
	/**
	 * Callback for img tag URL replacement (and srcset in same tag).
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function direct_replacement_callback( $matches ) {
		$full_path = $matches[2] . '.' . $matches[3] . $matches[4];
		$new_url   = $this->get_optimized_url_for_url( $full_path );
		if ( ! $new_url ) {
			return $matches[0];
		}
		$tag = str_replace( $full_path, $new_url, $matches[0] );
		// Replace srcset URLs with optimised versions
		$tag = preg_replace_callback(
			'/srcset=["\']([^"\']+)["\']/i',
			array( $this, 'replace_srcset_urls' ),
			$tag
		);
		return $tag;
	}

	/**
	 * Callback for img tags that already have optimised src but need srcset replacement.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function optimize_srcset_callback( $matches ) {
		$tag = $matches[0];
		if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $tag, $srcset_match ) ) {
			$srcset_content = $srcset_match[1];
			if ( preg_match( '/\.(jpg|jpeg|png)(\?|$|\s)/i', $srcset_content ) ) {
				$tag = preg_replace_callback(
					'/srcset=["\']([^"\']+)["\']/i',
					array( $this, 'replace_srcset_urls' ),
					$tag
				);
			}
		}
		return $tag;
	}

	/**
	 * Callback to revert optimised URLs back to original if optimised files don't exist.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function revert_optimized_url_callback( $matches ) {
		$optimized_url = $matches[2] . '.' . $matches[3] . $matches[4];
		$tag           = $matches[0];
		$format        = strtolower( $matches[3] );

		$upload_dir  = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$content_url = content_url();

		$rel_path = '';
		if ( strpos( $optimized_url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $optimized_url );
		} elseif ( strpos( $optimized_url, '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $optimized_url );
		} else {
			return $tag;
		}

		$rel_path          = preg_replace( '/\?.*$/', '', $rel_path );
		$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );

		$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );

		if ( ! file_exists( $opt_abs_path ) ) {
			// Optimised file gone: revert src to original upload URL.
			$original_url = $upload_dir['baseurl'] . '/' . $original_rel_path;
			$tag          = str_replace( $optimized_url, $original_url, $tag );
			$tag          = preg_replace_callback(
				'/srcset=["\']([^"\']+)["\']/i',
				array( $this, 'revert_srcset_urls' ),
				$tag
			);
			return $tag;
		}

		// File exists — but browser may not support this format (e.g. .avif served to a WebP-only browser).
		// Initialise browser support detection if not done yet (Admin pages skip this normally, but the
		// output buffer can fire on any request).
		if ( null === self::$browser_support ) {
			$accept                = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
			self::$browser_support = array(
				'avif' => ( strpos( $accept, 'image/avif' ) !== false ),
				'webp' => ( strpos( $accept, 'image/webp' ) !== false ),
			);
		}

		// If an AVIF URL was injected (e.g. via filter_attachment_url) but the browser doesn't support AVIF,
		// try to serve the companion WebP (Smart mode keeps both) or fall back to the original.
		if ( 'avif' === $format && ! self::$browser_support['avif'] ) {
			$base_no_ext = preg_replace( '/\.avif$/i', '', $opt_abs_path );
			$webp_abs    = $base_no_ext . '.webp';
			if ( self::$browser_support['webp'] && file_exists( $webp_abs ) ) {
				$webp_url = preg_replace( '/\.avif(\?|$)/i', '.webp$1', $optimized_url );
				$tag      = str_replace( $optimized_url, $webp_url, $tag );
			} else {
				// No WebP available (or browser doesn't support it): serve original.
				$original_url = $upload_dir['baseurl'] . '/' . $original_rel_path;
				$tag          = str_replace( $optimized_url, $original_url, $tag );
				$tag          = preg_replace_callback(
					'/srcset=["\']([^"\']+)["\']/i',
					array( $this, 'revert_srcset_urls' ),
					$tag
				);
			}
		}

		return $tag;
	}

	/**
	 * Callback to revert srcset URLs from optimised back to original if files don't exist.
	 *
	 * @param array $matches Regex matches from srcset pattern.
	 * @return string
	 */
	private function revert_srcset_urls( $matches ) {
		$srcset      = $matches[1];
		$parts       = explode( ',', $srcset );
		$new_parts   = array();
		$upload_dir  = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$content_url = content_url();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits       = preg_split( '/\s+/', $part, 2 );
			$url        = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';

			$rel_path = '';
			if ( strpos( $url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $url );
			} elseif ( strpos( $url, '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $url );
			}

			if ( ! empty( $rel_path ) ) {
				$rel_path     = preg_replace( '/\?.*$/', '', $rel_path );
				$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );
				if ( ! file_exists( $opt_abs_path ) ) {
					$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );
					$url               = $upload_dir['baseurl'] . '/' . $original_rel_path;
				}
			}

			$new_parts[] = $url . $descriptor;
		}

		return 'srcset="' . implode( ', ', $new_parts ) . '"';
	}

	/**
	 * Callback to replace URLs in srcset attribute with optimised versions.
	 *
	 * @param array $matches Regex matches from srcset pattern.
	 * @return string
	 */
	private function replace_srcset_urls( $matches ) {
		$srcset    = $matches[1];
		$parts     = explode( ',', $srcset );
		$new_parts = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits       = preg_split( '/\s+/', $part, 2 );
			$url        = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';

			$clean_url = preg_replace( '/[?#].*$/', '', $url );
			$opt_url   = $this->get_optimized_url_for_url( $clean_url );
			if ( $opt_url ) {
				$query_fragment = '';
				if ( preg_match( '/[?#].*$/', $url, $qf_matches ) ) {
					$query_fragment = $qf_matches[0];
				}
				$url = $opt_url . $query_fragment;
			}
			$new_parts[] = $url . $descriptor;
		}
		return 'srcset="' . implode( ', ', $new_parts ) . '"';
	}

	/**
	 * Callback for background-image url() replacement.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function background_replacement_callback( $matches ) {
		$full_url = $matches[1] . '.' . $matches[2] . $matches[3];
		$new_url  = $this->get_optimized_url_for_url( $full_url );
		if ( ! $new_url ) {
			return $matches[0];
		}
		return str_replace( $full_url, $new_url, $matches[0] );
	}

	/**
	 * Output buffer callback: replace img/background URLs in full HTML (e.g. inline styles in body).
	 *
	 * @param string $buffer Page HTML.
	 * @return string
	 */
	public function filter_global_buffer( $buffer ) {
		if ( empty( $buffer ) || ! is_string( $buffer ) ) {
			return $buffer;
		}
		if ( stripos( $buffer, '<html' ) === false ) {
			return $buffer;
		}
		return $this->replace_img_with_webp( $buffer );
	}
}
