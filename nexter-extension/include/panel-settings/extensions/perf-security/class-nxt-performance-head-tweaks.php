<?php
/**
 * Performance Head Tweaks
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Performance_Head_Tweaks {
	/**
	 * Remove emoji scripts/styles and related resource hints.
	 *
	 * @return void
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_print_styles', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', function( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
		} );

		add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
			if ( 'dns-prefetch' === $relation_type ) {
				$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );
				$urls = array_diff( $urls, array( $emoji_svg_url ) );
			}
			return $urls;
		}, 10, 2 );
	}

	/**
	 * Disable oEmbed discovery, endpoint rewrite rules, and TinyMCE embed plugin.
	 *
	 * @return void
	 */
	public function disable_embeds() {
		global $wp;
		$wp->public_query_vars = array_diff( $wp->public_query_vars, array( 'embed' ) );
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'tiny_mce_plugins', function( $plugins ) {
			return array_diff( $plugins, array( 'wpembed' ) );
		} );
		add_filter( 'rewrite_rules_array', function( $rules ) {
			foreach ( $rules as $rule => $rewrite ) {
				if ( false !== strpos( $rewrite, 'embed=true' ) ) {
					unset( $rules[ $rule ] );
				}
			}
			return $rules;
		} );
		remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
	}
}
