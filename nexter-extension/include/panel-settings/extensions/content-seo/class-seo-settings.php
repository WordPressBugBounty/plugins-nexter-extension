<?php
/**
 * Content SEO – global settings helpers (templates, variables, validation).
 *
 * @package Nexter_Extension
 * @subpackage Content_SEO
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nexter_Content_SEO_Settings
 */
class Nexter_Content_SEO_Settings {

	/**
	 * Dynamic variables available in title/description templates.
	 *
	 * @return array
	 */
	public static function get_template_variables() {
		$vars = array(
			'site_name'         => __( 'Site Name', 'nexter-extension' ),
			'tagline'           => __( 'Tagline', 'nexter-extension' ),
			'term_title'        => __( 'Term Title', 'nexter-extension' ),
			'post_title'        => __( 'Post Title', 'nexter-extension' ),
			'post_excerpt'      => __( 'Post Excerpt', 'nexter-extension' ),
			'post_content'      => __( 'Post Content', 'nexter-extension' ),
			'term_description'  => __( 'Term Description', 'nexter-extension' ),
			'date_published'    => __( 'Date Published', 'nexter-extension' ),
			'date_modified'     => __( 'Date Modified', 'nexter-extension' ),
			'post_url'          => __( 'Post URL', 'nexter-extension' ),
			'current_date'      => __( 'Current Date', 'nexter-extension' ),
			'current_day'       => __( 'Current Day', 'nexter-extension' ),
			'current_month'     => __( 'Current Month', 'nexter-extension' ),
			'current_year'      => __( 'Current Year', 'nexter-extension' ),
			'current_time'      => __( 'Current Time', 'nexter-extension' ),
			'organization_name' => __( 'Organization Name', 'nexter-extension' ),
			'organization_logo' => __( 'Organization Logo', 'nexter-extension' ),
			'organization_url'  => __( 'Organization URL', 'nexter-extension' ),
			'post_author_name'  => __( 'Post Author Name', 'nexter-extension' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$vars['wc_price']             = __( 'Product price (WooCommerce)', 'nexter-extension' );
			$vars['wc_currency']          = __( 'Product currency code (WooCommerce)', 'nexter-extension' );
			$vars['wc_sku']               = __( 'Product SKU (WooCommerce)', 'nexter-extension' );
			$vars['wc_short_description'] = __( 'Product short description (WooCommerce)', 'nexter-extension' );
			$vars['wc_stock_status']      = __( 'Stock status label (WooCommerce)', 'nexter-extension' );
		}
		return $vars;
	}

	/**
	 * Replace template variables in a string.
	 *
	 * @param string $template Template string with %variable% placeholders.
	 * @param array  $context  Context (post, term, etc.).
	 * @return string
	 */
	public static function replace_variables( $template, $context = array() ) {
		if ( empty( $template ) ) {
			return '';
		}
		$replace = array(
			'%site_name%'     => get_bloginfo( 'name' ),
			'%tagline%'       => get_bloginfo( 'description' ),
			'%current_date%'  => current_time( get_option( 'date_format' ) ),
			'%current_day%'   => current_time( 'd' ),
			'%current_month%' => current_time( 'm' ),
			'%current_year%'  => current_time( 'Y' ),
			'%current_time%'  => current_time( get_option( 'time_format' ) ),
		);
		if ( ! empty( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			$post                          = $context['post'];
			$replace['%post_title%']       = $post->post_title;
			$replace['%post_excerpt%']     = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
			$replace['%post_content%']     = wp_strip_all_tags( $post->post_content );
			$replace['%post_url%']         = get_permalink( $post );
			$replace['%date_published%']   = get_the_date( '', $post );
			$replace['%date_modified%']    = get_the_modified_date( '', $post );
			$replace['%post_author_name%'] = get_the_author_meta( 'display_name', $post->post_author );

			if ( 'product' === $post->post_type && class_exists( 'Nexter_Content_SEO_Schema' ) ) {
				$sr                                = Nexter_Content_SEO_Schema::get_replacements( $post );
				$replace['%wc_price%']             = isset( $sr['%product.price%'] ) ? $sr['%product.price%'] : '';
				$replace['%wc_currency%']          = isset( $sr['%product.currency%'] ) ? $sr['%product.currency%'] : '';
				$replace['%wc_sku%']               = isset( $sr['%product.sku%'] ) ? $sr['%product.sku%'] : '';
				$replace['%wc_short_description%'] = isset( $sr['%product.short_description%'] ) ? $sr['%product.short_description%'] : '';
				$replace['%wc_stock_status%']      = '';
				if ( function_exists( 'wc_get_product' ) ) {
					$wc_p = wc_get_product( $post->ID );
					if ( $wc_p && is_a( $wc_p, 'WC_Product' ) ) {
						$st = $wc_p->get_stock_status();
						if ( 'outofstock' === $st ) {
							$replace['%wc_stock_status%'] = __( 'Out of stock', 'nexter-extension' );
						} elseif ( 'onbackorder' === $st ) {
							$replace['%wc_stock_status%'] = __( 'On backorder', 'nexter-extension' );
						} else {
							$replace['%wc_stock_status%'] = __( 'In stock', 'nexter-extension' );
						}
					}
				}
			}
		}
		if ( ! empty( $context['term'] ) && $context['term'] instanceof WP_Term ) {
			$term                          = $context['term'];
			$desc_plain                    = wp_strip_all_tags( $term->description );
			$term_link                     = get_term_link( $term );
			$replace['%term_title%']       = $term->name;
			$replace['%term_description%'] = $desc_plain;
			$replace['%post_title%']       = $term->name;
			$replace['%post_excerpt%']     = wp_trim_words( $desc_plain, 25 );
			$replace['%post_content%']     = $desc_plain;
			$replace['%post_url%']         = is_wp_error( $term_link ) ? '' : $term_link;
			$replace['%date_published%']   = '';
			$replace['%date_modified%']    = '';
			$replace['%post_author_name%'] = '';
		}
		// Archive context: make %term_title% / %term_description% resolve for ALL archive types.
		// Term archives already set these from the term above; author/date/post-type archives
		// fall back to WordPress's own per-type archive strings ("Author: Jane", "Year: 2026"),
		// so the same dropdown tokens work everywhere without empty-segment artifacts.
		if ( ! is_admin() && function_exists( 'is_archive' ) && ( is_archive() || is_post_type_archive() ) ) {
			if ( empty( $replace['%term_title%'] ) ) {
				// Suppress the "Category:/Author:/Tag:/Archives:" label prefix (WP 5.5+) so the
				// bare archive name lands in the <title> template instead of a prefixed string.
				add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
				$replace['%term_title%'] = wp_strip_all_tags( get_the_archive_title() );
				remove_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
			}
			if ( empty( $replace['%term_description%'] ) ) {
				$replace['%term_description%'] = wp_strip_all_tags( get_the_archive_description() );
			}
		}

		// Organization tokens (%organization_name/logo/url%) advertised in the variable picker.
		// Sourced from the stored Organization schema row so title/description templates resolve
		// real data instead of having the tokens stripped as unknown placeholders below.
		if ( class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			$replace = array_merge( $replace, Nexter_Content_SEO_Schema::get_organization_token_values() );
		}

		// Single-pass token substitution against the ORIGINAL template only. str_replace() with
		// cumulative array replacement re-scanned its own output: a %post_title% (or excerpt /
		// content / WooCommerce field) whose value contained the literal text "%post_excerpt%"
		// was spliced in during the title pass, then that reintroduced literal was replaced again
		// by the excerpt pass — letting author/content-controlled text silently corrupt the
		// resolved title/description. A callback resolves each %token% from the template exactly
		// once; the replacement text is never re-interpreted. Unknown tokens (a typo or a removed
		// variable) resolve to '' so they never leak verbatim, while %token%-looking text that
		// appears INSIDE a resolved value is left untouched (it's content, not a directive).
		$output = preg_replace_callback(
			'/%([a-z0-9_]+)%/i',
			function ( $m ) use ( $replace ) {
				$key = '%' . $m[1] . '%';
				return isset( $replace[ $key ] ) ? (string) $replace[ $key ] : '';
			},
			(string) $template
		);

		// Collapse any double spaces a stripped token may have left behind.
		$output = preg_replace( '/[ \t]{2,}/', ' ', (string) $output );

		return is_string( $output ) ? trim( $output ) : '';
	}

	/**
	 * Get sample preview data for template variables (used when no post context).
	 *
	 * @return array Map of %key% => sample value.
	 */
	public static function get_preview_data() {
		$data = array(
			'%site_name%'         => get_bloginfo( 'name' ) ?: __( 'Site Name', 'nexter-extension' ),
			'%tagline%'           => get_bloginfo( 'description' ) ?: __( 'Tagline', 'nexter-extension' ),
			'%term_title%'        => __( 'Term Title', 'nexter-extension' ),
			'%post_title%'        => __( 'Sample Post', 'nexter-extension' ),
			'%post_excerpt%'      => __( 'This content will be set as the meta description tag and may appear in search results. Keep it short and clearly explain what the page is about.', 'nexter-extension' ),
			'%post_content%'      => __( 'This content will be set as the meta description tag and may appear in search results. Keep it short and clearly explain what the page is about.', 'nexter-extension' ),
			'%term_description%'  => __( 'Term Description', 'nexter-extension' ),
			'%date_published%'    => current_time( get_option( 'date_format' ) ),
			'%date_modified%'     => current_time( get_option( 'date_format' ) ),
			'%post_url%'          => home_url( '/sample-post/' ),
			'%site_url%'          => home_url( '/' ),
			'%current_date%'      => current_time( get_option( 'date_format' ) ),
			'%current_day%'       => current_time( 'd' ),
			'%current_month%'     => current_time( 'm' ),
			'%current_year%'      => current_time( 'Y' ),
			'%current_time%'      => current_time( get_option( 'time_format' ) ),
			'%organization_name%' => get_bloginfo( 'name' ) ?: __( 'Organization Name', 'nexter-extension' ),
			'%organization_logo%' => '',
			'%organization_url%'  => home_url( '/' ),
			'%post_author_name%'  => __( 'Author Name', 'nexter-extension' ),
		);

		// Preview reflects the stored Organization schema row (with site fallbacks) so the
		// dashboard shows what these tokens actually resolve to at render time.
		if ( class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			$data = array_merge( $data, Nexter_Content_SEO_Schema::get_organization_token_values() );
		}

		// Use a recent post for more realistic preview when available.
		$sample = get_posts(
			array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			) 
		);
		if ( ! empty( $sample[0] ) ) {
			$post                       = $sample[0];
			$data['%post_title%']       = $post->post_title;
			$data['%post_excerpt%']     = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
			$data['%post_content%']     = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			$data['%post_url%']         = get_permalink( $post );
			$data['%date_published%']   = get_the_date( '', $post );
			$data['%date_modified%']    = get_the_modified_date( '', $post );
			$data['%post_author_name%'] = get_the_author_meta( 'display_name', $post->post_author );
		}

		// Front page preview tokens. Homepage SEO meta is configured directly on
		// the static front page via the per-post Nexter SEO meta box, so these
		// tokens fall back to the WordPress site name and tagline.
		$front_id    = (int) get_option( 'page_on_front' );
		$front_title = '';
		$front_desc  = '';
		if ( $front_id > 0 ) {
			$front_title = (string) get_post_meta( $front_id, '_nxt_seo_title', true );
			$front_desc  = (string) get_post_meta( $front_id, '_nxt_seo_description', true );
		}
		$data['%homepage_title%']       = $front_title !== '' ? $front_title : ( get_bloginfo( 'name' ) ?: __( 'Site Name', 'nexter-extension' ) );
		$data['%homepage_description%'] = $front_desc !== '' ? $front_desc : ( get_bloginfo( 'description' ) ?: __( 'Tagline', 'nexter-extension' ) );
		$data['%homepage_url%']         = home_url( '/' );

		if ( class_exists( 'WooCommerce' ) && class_exists( 'Nexter_Content_SEO_Schema' ) ) {
			$data['%wc_price%']             = '29.99';
			$data['%wc_currency%']          = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
			$data['%wc_sku%']               = 'SKU-001';
			$data['%wc_short_description%'] = __( 'Short product summary for search and social previews.', 'nexter-extension' );
			$data['%wc_stock_status%']      = __( 'In stock', 'nexter-extension' );
			$sample_product                 = get_posts(
				array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				) 
			);
			if ( ! empty( $sample_product[0] ) ) {
				$p  = $sample_product[0];
				$sr = Nexter_Content_SEO_Schema::get_replacements( $p );
				if ( isset( $sr['%product.price%'] ) && '' !== $sr['%product.price%'] ) {
					$data['%wc_price%'] = $sr['%product.price%'];
				}
				if ( isset( $sr['%product.currency%'] ) && '' !== $sr['%product.currency%'] ) {
					$data['%wc_currency%'] = $sr['%product.currency%'];
				}
				if ( isset( $sr['%product.sku%'] ) && '' !== $sr['%product.sku%'] ) {
					$data['%wc_sku%'] = $sr['%product.sku%'];
				}
				if ( isset( $sr['%product.short_description%'] ) && '' !== $sr['%product.short_description%'] ) {
					$data['%wc_short_description%'] = $sr['%product.short_description%'];
				}
				$ctx                       = array( 'post' => $p );
				$data['%wc_stock_status%'] = self::replace_variables( '%wc_stock_status%', $ctx );
			}
		}

		return $data;
	}
}
