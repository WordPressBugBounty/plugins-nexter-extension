<?php
/**
 * Disable Comments Module
 * Handles: Disable comments per post type or site-wide, remove comment UI, REST endpoint, feeds
 *
 * @package Nexter Extension
 * @since 4.6.3
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Disable_Comments {

	/**
	 * Cached comment settings.
	 * @var array
	 */
	protected static $disable_comments_opts = [];

	/**
	 * @param array $perf_option   nexter_site_performance option value.
	 * @param array $adv_perfor    advance-performance values array.
	 */
	public function __construct( $perf_option, $adv_perfor ) {

		$disable_comments = $this->nxt_comments_enabled();

		if ( ! empty( $disable_comments )
			&& ( $disable_comments['disable_comments'] === 'custom' || $disable_comments['disable_comments'] === 'all' ) ) {
			add_action( 'wp_loaded', [ $this, 'nxt_wp_loaded_comments' ] );
		}

		/* Disable Comments Entire Site */
		if ( ! empty( $disable_comments ) && $disable_comments['disable_comments'] === 'all' ) {

			// Disable Built-in Recent Comments Widget
			add_action( 'widgets_init', function() {
				unregister_widget( 'WP_Widget_Recent_Comments' );
				add_filter( 'show_recent_comments_widget_style', '__return_false' );
			} );

			$is_rss_disabled = in_array( 'disable_rss_feed_link', $perf_option, true )
				|| ( ! empty( $adv_perfor ) && in_array( 'disable_rss_feed_link', $adv_perfor, true ) );

			if ( $is_rss_disabled ) {
				remove_action( 'wp_head', 'feed_links_extra', 3 );
			}

			// Disable 403 for all comment feed requests
			add_action( 'template_redirect', function() {
				if ( is_comment_feed() ) {
					wp_die( esc_html__( 'Comments are disabled.', 'nexter-extension' ), '', array( 'response' => 403 ) );
				}
			}, 9 );

			// Remove Comment Admin bar filtering
			add_action( 'template_redirect', [ $this, 'nxt_filter_admin_bar' ] );
			add_action( 'admin_init', [ $this, 'nxt_filter_admin_bar' ] );

			add_filter( 'rest_endpoints', [ $this, 'nxt_filter_rest_endpoints' ] );
		}
	}

	public function nxt_comments_enabled() {
		if ( ! empty( self::$disable_comments_opts ) ) {
			return self::$disable_comments_opts;
		}

		$extension_option = Nxt_Options::performance();

		$data = [
			'disable_comments'            => '',
			'disble_custom_post_comments' => [],
		];

		if ( ! empty( $extension_option ) ) {
			if ( isset( $extension_option['disble_custom_post_comments'] ) && ! empty( $extension_option['disble_custom_post_comments'] ) ) {
				$data['disble_custom_post_comments'] = $extension_option['disble_custom_post_comments'];
			}
			if ( isset( $extension_option['disable_comments'] ) && ! empty( $extension_option['disable_comments'] ) ) {
				$data['disable_comments'] = $extension_option['disable_comments'];
			} elseif ( isset( $extension_option['disable-comments'] ) && ! empty( $extension_option['disable-comments'] )
				&& isset( $extension_option['disable-comments']['switch'] ) && ! empty( $extension_option['disable-comments']['switch'] ) ) {
				if ( isset( $extension_option['disable-comments']['values'] ) && ! empty( $extension_option['disable-comments']['values'] ) ) {
					$disable_values = $extension_option['disable-comments']['values'];
					if ( isset( $disable_values['disable_comments'] ) && ! empty( $disable_values['disable_comments'] ) ) {
						$data['disable_comments'] = $disable_values['disable_comments'];
					}
					if ( isset( $disable_values['disble_custom_post_comments'] ) && ! empty( $disable_values['disble_custom_post_comments'] ) ) {
						$data['disble_custom_post_comments'] = $disable_values['disble_custom_post_comments'];
					}
				}
			}
		}

		self::$disable_comments_opts = $data;
		return self::$disable_comments_opts;
	}

	public function nxt_wp_loaded_comments() {
		$all_post_types = [];

		$disable_comments = $this->nxt_comments_enabled();

		if ( $disable_comments['disable_comments'] === 'all' ) {
			$all_post_types = get_post_types( array( 'public' => true ), 'names' );
		} elseif ( $disable_comments['disable_comments'] === 'custom' ) {
			$all_post_types = $this->nxt_get_disabled_post_types();
		}

		if ( ! empty( $all_post_types ) ) {
			foreach ( $all_post_types as $post_type ) {
				if ( post_type_supports( $post_type, 'comments' ) ) {
					remove_post_type_support( $post_type, 'comments' );
					remove_post_type_support( $post_type, 'trackbacks' );
				}
			}
		}

		add_filter( 'comments_array', function( $comments, $post_id ) {
			$disable_comments = $this->nxt_comments_enabled();
			$post_type = get_post_type( $post_id );
			return ( ! empty( $disable_comments )
				&& ( $disable_comments['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled( $post_type ) )
				? array() : $comments );
		}, 20, 2 );

		add_filter( 'comments_open', function( $open, $post_id ) {
			$disable_comments = $this->nxt_comments_enabled();
			$post_type = get_post_type( $post_id );
			return ( ! empty( $disable_comments )
				&& ( $disable_comments['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled( $post_type ) )
				? false : $open );
		}, 20, 2 );

		add_filter( 'pings_open', function( $count, $post_id ) {
			$disable_comments = $this->nxt_comments_enabled();
			$post_type = get_post_type( $post_id );
			return ( ! empty( $disable_comments )
				&& ( $disable_comments['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled( $post_type ) )
				? 0 : $count );
		}, 20, 2 );

		if ( is_admin() ) {
			if ( $disable_comments['disable_comments'] === 'all' ) {
				add_action( 'admin_menu', [ $this, 'nxt_admin_menu_comments' ], 9999 );

				add_action( 'admin_print_styles-index.php', function() {
					echo "<style>#dashboard_right_now .comment-count, #dashboard_right_now .comment-mod-count, #latest-comments, #welcome-panel .welcome-comments {
							display: none !important;
						}
					</style>";
				} );

				add_action( 'admin_print_styles-profile.php', function() {
					echo "<style>.user-comment-shortcuts-wrap {
							display: none !important;
						}
					</style>";
				} );

				add_action( 'wp_dashboard_setup', [ $this, 'nxt_recent_comments_dashboard' ] );
				add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
			}
		} else {
			add_action( 'template_redirect', [ $this, 'nxt_comment_template' ] );

			if ( $disable_comments['disable_comments'] === 'all' ) {
				add_filter( 'feed_links_show_comments_feed', '__return_false' );
			}
		}
	}

	public function nxt_get_disabled_post_types() {
		$data = $this->nxt_comments_enabled();
		$post_types = [];
		if ( ! empty( $data['disable_comments'] ) && $data['disable_comments'] === 'custom' ) {
			if ( isset( $data['disble_custom_post_comments'] ) && ! empty( $data['disble_custom_post_comments'] ) ) {
				$post_types = $data['disble_custom_post_comments'];
			}
		}
		return $post_types;
	}

	public function nxt_comment_post_type_disabled( $post_type ) {
		return $post_type && in_array( $post_type, $this->nxt_get_disabled_post_types(), true );
	}

	public function nxt_admin_menu_comments() {
		global $pagenow;

		remove_menu_page( 'edit-comments.php' );

		if ( $pagenow === 'comment.php' || $pagenow === 'edit-comments.php' ) {
			wp_die( esc_html__( 'Comments are disabled.', 'nexter-extension' ), '', array( 'response' => 403 ) );
		}

		if ( $pagenow === 'options-discussion.php' ) {
			wp_die( esc_html__( 'Comments are disabled.', 'nexter-extension' ), '', array( 'response' => 403 ) );
		}

		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	public function nxt_recent_comments_dashboard() {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	public function nxt_filter_admin_bar() {
		if ( is_admin_bar_showing() ) {
			remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
			if ( is_multisite() ) {
				add_action( 'admin_bar_menu', [ $this, 'nxt_remove_network_comment_links' ], 500 );
			}
		}
	}

	public function nxt_remove_network_comment_links( $wp_admin_bar ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active_for_network( 'nexter-extension/nexter-extension.php' ) && is_user_logged_in() ) {
			foreach ( $wp_admin_bar->user->blogs as $blog ) {
				$wp_admin_bar->remove_menu( 'blog-' . $blog->userblog_id . '-c' );
			}
		} else {
			$wp_admin_bar->remove_menu( 'blog-' . get_current_blog_id() . '-c' );
		}
	}

	public function nxt_filter_rest_endpoints( $endpoints ) {
		unset( $endpoints['comments'] );
		return $endpoints;
	}

	public function nxt_comment_template() {
		$data = $this->nxt_comments_enabled();
		if ( is_singular()
			&& ( ! empty( $data['disable_comments'] )
				&& ( $data['disable_comments'] === 'all'
					|| ( $data['disable_comments'] === 'custom' && $this->nxt_comment_post_type_disabled( get_post_type() ) )
				)
			)
		) {
			if ( ! defined( 'DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE' ) || DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE === true ) {
				add_filter( 'comments_template', [ $this, 'nxt_empty_comments_template' ], 20 );
			}
			wp_deregister_script( 'comment-reply' );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
	}

	public function nxt_empty_comments_template( $headers ) {
		return dirname( __DIR__, 2 ) . '/comments.php';
	}
}
