<?php
/**
 * Admin Bar Handler
 *
 * Manages the Nexter admin bar menu: template list, snippet list,
 * page-context filtering, reusable-block discovery, and related
 * enqueue logic.
 *
 * Extracted from Nexter_Class_Load (nexter-class-load.php) to give
 * the admin-bar concern its own single-responsibility class.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Admin_Bar_Handler {

	/**
	 * Register admin-bar hooks.
	 *
	 * Called from Nexter_Class_Load::__construct() inside a deferred
	 * `init` callback so is_user_logged_in() is available.
	 */
	public function register_hooks() {
		add_action( 'admin_bar_menu', [ $this, 'add_edit_template_admin_bar' ], 300 );
		add_action( 'wp_footer', [ $this, 'admin_bar_enqueue_scripts' ] );
	}

	// ── Admin Bar Nodes ────────────────────────────────────────────

	/**
	 * Add Admin Bar menu — Load Templates.
	 *
	 * @since 1.0.7
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_edit_template_admin_bar( \WP_Admin_Bar $wp_admin_bar ) {
		global $wp_admin_bar;

		if ( ! is_super_admin()
			 || ! is_object( $wp_admin_bar )
			 || ! function_exists( 'is_admin_bar_showing' )
			 || ! is_admin_bar_showing() ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'nxt_edit_template',
			'meta'  => array(
				'class' => 'nxt_edit_template',
			),
			'title' => esc_html__( 'Template List', 'nexter-extension' ),
		] );

		$wp_admin_bar->add_node( [
			'id'    => 'nxt_edit_snippets',
			'meta'  => array(
				'class' => 'nxt_edit_snippets',
			),
			'title' => esc_html__( 'Snippets List', 'nexter-extension' ),
		] );
	}

	// ── Admin Bar Scripts ──────────────────────────────────────────

	/**
	 * Enqueue admin bar scripts and inline template/snippet data.
	 *
	 * @since 1.0.8
	 */
	public function admin_bar_enqueue_scripts() {
		global $wp_admin_bar;

		if ( ! is_super_admin()
			 || ! is_object( $wp_admin_bar )
			 || ! function_exists( 'is_admin_bar_showing' )
			 || ! is_admin_bar_showing() ) {
			return;
		}

		$current_post_id = get_the_ID();
		$post_ids        = $current_post_id ? [ $current_post_id ] : [];

		if ( has_filter( 'nexter_template_load_ids' ) ) {
			$post_ids = apply_filters( 'nexter_template_load_ids', $post_ids );
		}

		$snippets_ids    = [];
		$get_opt         = Nxt_Options::extra_ext();
		$adminbar_enabled = false;

		// Check if code snippets are enabled.
		$code_snippets_enabled = true;

		if ( isset( $get_opt['code-snippets'] ) && isset( $get_opt['code-snippets']['switch'] ) ) {
			$code_snippets_enabled = ! empty( $get_opt['code-snippets']['switch'] );
		}

		if ( $code_snippets_enabled && ! empty( $get_opt ) && ! empty( $get_opt['code-snippets'] ) && ! empty( $get_opt['code-snippets']['values'] ) ) {
			$cs_options       = $get_opt['code-snippets']['values'];
			$adminbar_enabled = ! empty( $cs_options['adminbar'] );

			if ( has_filter( 'nexter_loaded_snippet_ids' ) && $adminbar_enabled ) {
				$raw_snippets_ids = apply_filters( 'nexter_loaded_snippet_ids', $snippets_ids );
				$snippets_ids     = $this->filter_snippets_by_page_context( $raw_snippets_ids );
			}
		}

		// Only process Pro snippets if code snippets are enabled.
		if ( $code_snippets_enabled && defined( 'NXT_PRO_EXT' ) && class_exists( 'Nexter_Builder_Code_Snippets_Render_Pro' ) ) {
			if ( ! empty( Nexter_Builder_Code_Snippets_Render_Pro::$snippet_loaded_ids_pro ) ) {
				$pro_snippets = $this->filter_snippets_by_page_context( Nexter_Builder_Code_Snippets_Render_Pro::$snippet_loaded_ids_pro );
				$snippets_ids = $this->nxt_deep_merge_snippet_ids( $snippets_ids, $pro_snippets );
			}
		}

		/* The Plus Template Blocks load */
		if ( class_exists( 'Tpgb_Library' ) ) {
			$tpgb_libraby = Tpgb_Library::get_instance();
			if ( isset( $tpgb_libraby->plus_template_blocks ) ) {
				$post_ids = array_unique( array_merge( $post_ids, $tpgb_libraby->plus_template_blocks ) );
			}
		}

		if ( ! empty( $post_ids ) ) {
			$post_ids = $this->find_reusable_block( $post_ids );
			if ( ( $key = array_search( $current_post_id, $post_ids ) ) !== false ) {
				unset( $post_ids[ $key ] );
			}
		}

		// Load js 'nxt-admin-bar' before 'admin-bar'.
		wp_dequeue_script( 'admin-bar' );

		wp_enqueue_style(
			'nxt-admin-bar',
			NEXTER_EXT_URL . 'assets/css/main/nxt-admin-bar.css',
			[ 'admin-bar' ],
			NEXTER_EXT_VER
		);
		wp_enqueue_script(
			'nxt-admin-bar',
			NEXTER_EXT_URL . 'assets/js/main/nxt-admin-bar.min.js',
			[],
			NEXTER_EXT_VER,
			true
		);

		wp_enqueue_script( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
			'admin-bar',
			null,
			[ 'nxt-admin-bar' ],
			NEXTER_EXT_VER,
			true
		);

		$template_list = [];
		if ( ! empty( $post_ids ) ) {
			foreach ( $post_ids as $key => $post_id ) {
				if ( ! isset( $template_list[ $post_id ] ) ) {
					$posts = get_post( $post_id );
					if ( isset( $posts->post_title ) ) {
						$template_list[ $post_id ]['id']       = $post_id;
						$template_list[ $post_id ]['title']    = $posts->post_title;
						$template_list[ $post_id ]['edit_url'] = esc_url( get_edit_post_link( $post_id ) );
					}
					if ( isset( $posts->post_type ) ) {
						$template_list[ $post_id ]['post_type'] = $posts->post_type;
						$post_type_obj = get_post_type_object( $posts->post_type );
						$template_list[ $post_id ]['post_type_name'] = ( $post_type_obj && isset( $post_type_obj->labels ) && isset( $post_type_obj->labels->singular_name ) ) ? $post_type_obj->labels->singular_name : '';

						if ( $posts->post_type === 'nxt_builder' ) {
							if ( get_post_meta( $post_id, 'nxt-hooks-layout', true ) ) {
								$layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
								$type   = '';
								if ( ! empty( $layout ) && $layout === 'sections' ) {
									$type = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
								} elseif ( ! empty( $layout ) && $layout === 'pages' ) {
									$type = get_post_meta( $post_id, 'nxt-hooks-layout-pages', true );
								} elseif ( ! empty( $layout ) && $layout === 'code_snippet' ) {
									$type = get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', true );
								} elseif ( ! empty( $layout ) && $layout === 'none' ) {
									unset( $template_list[ $post_id ] );
								}
								if ( isset( $template_list[ $post_id ] ) ) {
									$template_list[ $post_id ]['nexter_layout'] = $layout;
									$template_list[ $post_id ]['nexter_type']   = $type;
								}
							} elseif ( get_post_meta( $post_id, 'nxt-hooks-layout-sections', true ) ) {
								$type = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
								if ( isset( $template_list[ $post_id ] ) ) {
									$template_list[ $post_id ]['nexter_type'] = $type;
								}
							}
						}
					}
				}
			}
		}

		$snippets_lists = array(
			'css'        => [],
			'javascript' => [],
			'php'        => [],
			'htmlmixed'  => [],
		);

		$all_snippets_ids = $snippets_ids;
		foreach ( [ 'css', 'javascript', 'php', 'htmlmixed' ] as $type ) {
			if ( ! empty( $all_snippets_ids[ $type ] ) ) {
				foreach ( $all_snippets_ids[ $type ] as $post_id ) {
					if ( is_numeric( $post_id ) && ! isset( $snippets_lists[ $type ][ $post_id ] ) ) {
						$post = get_post( $post_id );
						if ( $post ) {
							$snippets_lists[ $type ][ $post_id ] = [
								'id'       => $post_id,
								'title'    => $post->post_title,
								'edit_url' => admin_url( 'admin.php?page=nxt_code_snippets#/edit/' . $post_id ),
							];
						}
					} elseif ( ! empty( $post_id ) && is_string( $post_id ) && ! isset( $snippets_lists[ $type ][ $post_id ] ) ) {
						if ( class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
							$file_based   = new Nexter_Code_Snippets_File_Based();
							$snippet_data = $file_based->getSnippetData( $post_id );
							if ( ! empty( $snippet_data ) ) {
								$snippets_lists[ $type ][ $post_id ] = [
									'id'       => $post_id,
									'title'    => isset( $snippet_data['name'] ) ? $snippet_data['name'] : $post_id,
									'edit_url' => admin_url( 'admin.php?page=nxt_code_snippets#/edit/' . $post_id ),
								];
							}
						}
					}
				}
			}
		}

		$template_list1 = array_column( $template_list, 'post_type' );
		array_multisort( $template_list1, SORT_DESC, $template_list );

		$nxt_template = [
			'nxt_edit_template' => $template_list,
		];

		// Only add snippets to admin bar if Admin Bar Info toggle is enabled.
		if ( $adminbar_enabled ) {
			$nxt_template['nxt_edit_snippet'] = $snippets_lists;
		}

		$scripts = 'var NexterAdminBar = ' . wp_json_encode( $nxt_template );
		wp_add_inline_script( 'nxt-admin-bar', $scripts, 'before' );
	}

	// ── Snippet Context Filtering ──────────────────────────────────

	/**
	 * Filter snippets based on current page context.
	 *
	 * Ensures snippets only appear in admin bar when they would
	 * actually execute on the current page.
	 *
	 * @param array $snippets_ids Snippet IDs grouped by type.
	 * @return array Filtered snippet IDs.
	 */
	private function filter_snippets_by_page_context( $snippets_ids ) {
		if ( empty( $snippets_ids ) ) {
			return $snippets_ids;
		}

		$filtered_snippets = [];

		foreach ( [ 'css', 'javascript', 'php', 'htmlmixed' ] as $type ) {
			$filtered_snippets[ $type ] = [];

			if ( ! empty( $snippets_ids[ $type ] ) ) {
				foreach ( $snippets_ids[ $type ] as $snippet_id ) {
					if ( $this->should_snippet_show_in_admin_bar( $snippet_id ) ) {
						$filtered_snippets[ $type ][] = $snippet_id;
					}
				}
			}
		}

		return $filtered_snippets;
	}

	/**
	 * Check if a snippet should show in admin bar for current page context.
	 *
	 * @param int|string $snippet_id Snippet ID (post ID or file-based ID).
	 * @return bool
	 */
	private function should_snippet_show_in_admin_bar( $snippet_id ) {
		$location = get_post_meta( $snippet_id, 'nxt-code-location', true );

		if ( empty( $location ) ) {
			return true; // Old system snippets — always show.
		}

		// Check basic snippet status first.
		$is_active = get_post_meta( $snippet_id, 'nxt-code-status', true );
		if ( $is_active != '1' ) {
			return false;
		}

		// Check schedule restrictions.
		if ( defined( 'NXT_PRO_EXT' ) && function_exists( 'apply_filters' ) ) {
			$should_skip_schedule = apply_filters( 'nexter_check_pro_schedule_restrictions', false, $snippet_id );
			if ( $should_skip_schedule ) {
				return false;
			}
		}

		// Check Smart Conditional Logic.
		$smart_conditions = get_post_meta( $snippet_id, 'nxt-smart-conditional-logic', true );
		if ( ! empty( $smart_conditions ) && class_exists( 'Nexter_Builder_Display_Conditional_Rules' ) ) {
			if ( ! Nexter_Builder_Display_Conditional_Rules::evaluate_smart_conditional_logic( $smart_conditions ) ) {
				return false;
			}
		}

		// Check if this is a Pro location — validate with page context.
		if ( defined( 'NXT_PRO_EXT' ) ) {
			$pro_locations = [
				'before_html_element',
				'after_html_element',
				'start_html_element',
				'end_html_element',
				'replace_html_element',
				'insert_after_words',
				'insert_every_words',
				'insert_middle_content',
				'insert_after_25',
				'insert_after_75',
				'insert_after_33',
				'insert_after_66',
				'insert_after_80',
			];

			if ( in_array( $location, $pro_locations ) ) {
				return $this->validate_pro_location_context( $location );
			}
		}

		// Global locations should always show.
		if ( Nexter_Global_Code_Handler::is_global_location( $location ) ) {
			return true;
		}

		// eCommerce locations — validate page context.
		if ( Nexter_ECommerce_Code_Handler::is_ecommerce_location( $location ) ) {
			return $this->validate_ecommerce_location_context( $location );
		}

		// Page-specific locations — check page context.
		if ( Nexter_Page_Specific_Code_Handler::is_page_specific_location( $location ) ) {
			return $this->validate_page_specific_location_context( $location );
		}

		return true;
	}

	/**
	 * Validate Pro location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_pro_location_context( $location ) {
		$content_based_locations = [
			'insert_after_words',
			'insert_every_words',
			'insert_middle_content',
			'insert_after_25',
			'insert_after_33',
			'insert_after_66',
			'insert_after_75',
			'insert_after_80',
		];

		if ( in_array( $location, $content_based_locations ) ) {
			return is_singular();
		}

		return true;
	}

	/**
	 * Validate eCommerce location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_ecommerce_location_context( $location ) {
		if ( Nexter_ECommerce_Code_Handler::is_woocommerce_location( $location ) ) {
			if ( ! Nexter_ECommerce_Code_Handler::is_woocommerce_active() ) {
				return false;
			}
			return $this->validate_woocommerce_location_context( $location );
		}

		if ( Nexter_ECommerce_Code_Handler::is_edd_location( $location ) ) {
			if ( ! Nexter_ECommerce_Code_Handler::is_edd_active() ) {
				return false;
			}
			return $this->validate_edd_location_context( $location );
		}

		if ( Nexter_ECommerce_Code_Handler::is_memberpress_location( $location ) ) {
			if ( ! Nexter_ECommerce_Code_Handler::is_memberpress_active() ) {
				return false;
			}
			return $this->validate_memberpress_location_context( $location );
		}

		return true;
	}

	/**
	 * Validate WooCommerce location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_woocommerce_location_context( $location ) {
		if ( strpos( $location, 'shop' ) !== false || strpos( $location, 'list_products' ) !== false ) {
			return ( function_exists( 'is_shop' ) && is_shop() ) ||
			       ( function_exists( 'is_product_category' ) && is_product_category() ) ||
			       ( function_exists( 'is_product_tag' ) && is_product_tag() );
		}

		if ( strpos( $location, 'single_product' ) !== false ) {
			return function_exists( 'is_product' ) && is_product();
		}

		if ( strpos( $location, 'cart' ) !== false ) {
			return function_exists( 'is_cart' ) && is_cart();
		}

		if ( strpos( $location, 'checkout' ) !== false ) {
			return function_exists( 'is_checkout' ) && is_checkout();
		}

		if ( strpos( $location, 'thank_you' ) !== false ) {
			return function_exists( 'is_order_received_page' ) && is_order_received_page();
		}

		return true;
	}

	/**
	 * Validate EDD location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_edd_location_context( $location ) {
		if ( strpos( $location, 'download' ) !== false ) {
			return function_exists( 'is_singular' ) && is_singular( 'download' );
		}

		if ( strpos( $location, 'cart' ) !== false || strpos( $location, 'checkout' ) !== false ) {
			return function_exists( 'edd_is_checkout' ) && edd_is_checkout();
		}

		return true;
	}

	/**
	 * Validate MemberPress location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_memberpress_location_context( $location ) {
		if ( strpos( $location, 'checkout' ) !== false ) {
			return function_exists( 'is_page' ) && function_exists( 'get_query_var' ) &&
			       is_page() && get_query_var( 'action' ) === 'checkout';
		}

		if ( strpos( $location, 'account' ) !== false ) {
			return function_exists( 'is_page' ) && function_exists( 'get_query_var' ) &&
			       is_page() && get_query_var( 'action' ) === 'account';
		}

		if ( strpos( $location, 'login' ) !== false ) {
			return function_exists( 'is_page' ) && function_exists( 'get_query_var' ) &&
			       is_page() && get_query_var( 'action' ) === 'login';
		}

		if ( strpos( $location, 'unauthorized' ) !== false ) {
			return true;
		}

		return true;
	}

	/**
	 * Validate page-specific location against current page context.
	 *
	 * @param string $location Location key.
	 * @return bool
	 */
	private function validate_page_specific_location_context( $location ) {
		$archive_only_locations = [
			'insert_before_excerpt',
			'insert_after_excerpt',
			'between_posts',
			'before_post',
			'after_post',
		];

		$singular_only_locations = array_merge( [
			'insert_before_content',
			'insert_after_content',
			'insert_before_paragraph',
			'insert_after_paragraph',
			'insert_before_post',
			'insert_after_post',
		], Nexter_Page_Specific_Code_Handler::get_advanced_content_locations() );

		if ( in_array( $location, $archive_only_locations ) ) {
			return ! is_singular();
		}

		if ( in_array( $location, $singular_only_locations ) ) {
			return is_singular();
		}

		return true;
	}

	// ── Utilities ──────────────────────────────────────────────────

	/**
	 * Deep-merge two snippet-ID arrays (keyed by type).
	 *
	 * @param array $base   Base array.
	 * @param array $append Array to merge in.
	 * @return array
	 */
	public function nxt_deep_merge_snippet_ids( $base, $append ) {
		$merged = [];
		$keys   = array_unique( array_merge( array_keys( $base ), array_keys( $append ) ) );

		foreach ( $keys as $key ) {
			$base_items   = $base[ $key ] ?? [];
			$append_items = $append[ $key ] ?? [];
			$merged[ $key ] = array_values( array_unique( array_merge( $base_items, $append_items ) ) );
		}

		return $merged;
	}

	/**
	 * Find reusable blocks referenced in the given posts.
	 *
	 * @since 1.0.7
	 *
	 * @param array $post_ids Post IDs to scan.
	 * @return array Augmented post IDs including reusable block IDs.
	 */
	public function find_reusable_block( $post_ids ) {
		if ( ! empty( $post_ids ) ) {
			foreach ( $post_ids as $key => $post_id ) {
				$post_content = get_post( $post_id );
				if ( isset( $post_content->post_content ) ) {
					$content = $post_content->post_content;
					if ( has_blocks( $content ) ) {
						$parse_blocks = parse_blocks( $content );
						$res_id       = $this->block_reference_id( $parse_blocks );
						if ( is_array( $res_id ) && ! empty( $res_id ) ) {
							$post_ids = array_unique( array_merge( $res_id, $post_ids ) );
						}
					}
				}
			}
		}

		return $post_ids;
	}

	/**
	 * Recursively get reference IDs from parsed blocks.
	 *
	 * @since 1.0.7
	 *
	 * @param array $res_blocks Parsed blocks.
	 * @return array Reference IDs.
	 */
	public function block_reference_id( $res_blocks ) {
		$ref_id = array();
		if ( ! empty( $res_blocks ) ) {
			foreach ( $res_blocks as $key => $block ) {
				if ( $block['blockName'] == 'core/block' ) {
					$ref_id[] = $block['attrs']['ref'];
				}
				if ( count( $block['innerBlocks'] ) > 0 ) {
					$ref_id = array_merge( $this->block_reference_id( $block['innerBlocks'] ), $ref_id );
				}
			}
		}
		return $ref_id;
	}
}
