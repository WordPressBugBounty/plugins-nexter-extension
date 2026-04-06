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
			$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
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

		// Convert to absolute path
		$abs = wp_normalize_path( $base_path . '/' . $rel );

		// Check if original file exists
		if ( ! file_exists( $abs ) ) {
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

		// Check for optimised versions (prefer AVIF, fallback to WebP, then original format in nexter-optimizer/uploads)
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

		// First pass: Replace src and srcset for images with original formats (jpg/jpeg/png)
		$img_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_pattern, array( $this, 'direct_replacement_callback' ), $content );

		// Second pass: Replace srcset for images that already have optimised src (webp/avif) but srcset still has original formats
		$img_with_srcset_pattern = '/<img([^>]*?)src=["\']([^"\']+)\.(webp|avif)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_with_srcset_pattern, array( $this, 'optimize_srcset_callback' ), $content );

		// Third pass: Revert optimised URLs back to original if optimised files don't exist
		$img_optimized_pattern = '/<img([^>]*?)src=["\']([^"\']*nexter-optimizer[^"\']+)\.(webp|avif|jpg|jpeg|png)([^"\']*)["\']([^>]*?)>/i';
		$content = preg_replace_callback( $img_optimized_pattern, array( $this, 'revert_optimized_url_callback' ), $content );

		// Replace background-image URLs
		$bg_pattern = '/url\(\s*["\']?([^"\'\)]+)\.(jpg|jpeg|png)([^"\'\)]*)["\']?\s*\)/i';
		$content = preg_replace_callback( $bg_pattern, array( $this, 'background_replacement_callback' ), $content );

		return $content;
	}

	/**
	 * Callback for img tag URL replacement (and srcset in same tag).
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function direct_replacement_callback( $matches ) {
		$full_path = $matches[2] . '.' . $matches[3] . $matches[4];
		$new_url = $this->get_optimized_url_for_url( $full_path );
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
		$tag = $matches[0];
		$format = strtolower( $matches[3] );

		$upload_dir = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$content_url = content_url();

		$rel_path = '';
		if ( strpos( $optimized_url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $optimized_url );
		} elseif ( strpos( $optimized_url, '/nexter-optimizer/uploads/' ) !== false ) {
			$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $optimized_url );
		} else {
			return $tag;
		}

		$rel_path = preg_replace( '/\?.*$/', '', $rel_path );
		$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );

		$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );
		if ( ! file_exists( $opt_abs_path ) ) {
			$original_url = $upload_dir['baseurl'] . '/' . $original_rel_path;
			$tag = str_replace( $optimized_url, $original_url, $tag );

			$tag = preg_replace_callback(
				'/srcset=["\']([^"\']+)["\']/i',
				array( $this, 'revert_srcset_urls' ),
				$tag
			);
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
		$srcset = $matches[1];
		$parts = explode( ',', $srcset );
		$new_parts = array();
		$upload_dir = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
		$content_url = content_url();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part, 2 );
			$url = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';

			$rel_path = '';
			if ( strpos( $url, $content_url . '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = str_replace( $content_url . '/nexter-optimizer/uploads/', '', $url );
			} elseif ( strpos( $url, '/nexter-optimizer/uploads/' ) !== false ) {
				$rel_path = preg_replace( '/^.*\/nexter-optimizer\/uploads\//', '', $url );
			}

			if ( ! empty( $rel_path ) ) {
				$rel_path = preg_replace( '/\?.*$/', '', $rel_path );
				$opt_abs_path = wp_normalize_path( WP_CONTENT_DIR . '/nexter-optimizer/uploads/' . $rel_path );
				if ( ! file_exists( $opt_abs_path ) ) {
					$original_rel_path = preg_replace( '/\.(webp|avif)$/i', '', $rel_path );
					$url = $upload_dir['baseurl'] . '/' . $original_rel_path;
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
		$srcset = $matches[1];
		$parts = explode( ',', $srcset );
		$new_parts = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part, 2 );
			$url = trim( $bits[0] );
			$descriptor = isset( $bits[1] ) ? ' ' . trim( $bits[1] ) : '';

			$clean_url = preg_replace( '/[?#].*$/', '', $url );
			$opt_url = $this->get_optimized_url_for_url( $clean_url );
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
		$new_url = $this->get_optimized_url_for_url( $full_url );
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
