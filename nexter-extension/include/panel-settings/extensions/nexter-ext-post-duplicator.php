<?php
/*
 *	Nexter Duplicate Post/Page
 *	@since 1.1.0
**/
defined( 'ABSPATH' ) || exit;

// Admin-only feature: post list row actions, admin scripts, and AJAX handlers.
// File is only included when wp-duplicate-post switch is enabled (see nexter-extra-settings-extension.php).
if ( ! is_admin() ) {
	return;
}

$extension_option = Nxt_Options::extra_ext();

if( !empty($extension_option['wp-duplicate-post']['values']) ){

	if( !function_exists('nxt_duplicate_post_action_link')){
		function nxt_duplicate_post_action_link( $post ) {

			$extension_option = Nxt_Options::extra_ext();
			$wpDupPostSet = $extension_option['wp-duplicate-post']['values'];
			if(!empty($wpDupPostSet)){
	
				$duplicate_access = (!empty($wpDupPostSet['nxt-duppost-access'])) ? $wpDupPostSet['nxt-duppost-access'] : 'all_users';
				$duplicate_author = (!empty($wpDupPostSet['nxt-duppost-author'])) ? $wpDupPostSet['nxt-duppost-author'] : 'current_author';
				$duplicate_date = (!empty($wpDupPostSet['nxt-duppost-date'])) ? $wpDupPostSet['nxt-duppost-date'] : 'original_date';
				$duplicate_status = (!empty($wpDupPostSet['nxt-duppost-status'])) ? $wpDupPostSet['nxt-duppost-status'] : 'same';
				$duplicate_postfix = (!empty($wpDupPostSet['nxt-duplicate-postfix'])) ? $wpDupPostSet['nxt-duplicate-postfix'] : 'Copy';
				$duplicate_slug = (!empty($wpDupPostSet['nxt-duplicate-slug'])) ? $wpDupPostSet['nxt-duplicate-slug'] : 'copy';
			
				$settings = ['duplicate_access' => $duplicate_access,
							'post_author' => $duplicate_author,
							'timestamp' => $duplicate_date,
							'status' => $duplicate_status,
							'title' => $duplicate_postfix,
							'slug' => $duplicate_slug];
				
				// Hide on trash page
				$post_status = isset( $_GET['post_status'] ) ? sanitize_text_field( wp_unslash( $_GET['post_status']) ) : false;
				if ( $post_status=='trash' ) {
					return false;
				}
	
				if ( $settings['duplicate_access'] == 'original_user' ) {
					if ( $post->post_author!=get_current_user_id() ) {
						return false;
					}
				}
	
				// Get post type
				$post_type = get_post_type_object( $post->post_type );
				
				// Security: Create and Return Link with proper escaping
				$post_type_label = isset( $post_type->labels->singular_name ) ? esc_html( $post_type->labels->singular_name ) : '';
				return '<a class="nxt-post-duplicate" href="" data-postid="'.esc_attr( $post->ID ).'">'. esc_html__( 'Duplicate', 'nexter-extension' ).'</a><div class="nxt-dp-post-modal"><div class="nxt-post-modal-inner"><div class="nxt-post-dp-input-wrap"><input class="nxt-dp-post-input" type="number" min="1" value="1"/><span class="nxt-dp-post-total-text">: '.esc_html($post_type_label).'(s)</span></div><a class="nxt-dp-post-btn" href="">'.esc_html__('Duplicate','nexter-extension').'</a></div></div>';
			}
		}
	}
	
	if( !function_exists('nxt_duplicator_post_action')){
		// Duplicate Post Link Action
		function nxt_duplicator_post_action( $actions, $post ){
			
			if( function_exists('nxt_duplicate_post_action_link') && current_user_can( 'edit_posts' ) ) {
				if ( $link = nxt_duplicate_post_action_link( $post ) ) {
					$actions['nexter_duplicate_post'] = $link;
				}	
			}
			return $actions;
		}
		add_filter( 'post_row_actions', 'nxt_duplicator_post_action', 10, 2 );
		add_filter( 'page_row_actions', 'nxt_duplicator_post_action', 10, 2 );
		add_filter( 'cuar/core/admin/content-list-table/row-actions', 'nxt_duplicator_post_action', 10, 2 );
	}

	add_action( 'admin_enqueue_scripts', function(){
		$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style( 'nxt-duplicate-post-css', NEXTER_EXT_URL .'assets/css/admin/nxt-duplicate-post'. $minified .'.css', array(), NEXTER_EXT_VER );
		wp_enqueue_script( 'nexter-duplicate-post-js', NEXTER_EXT_URL . 'assets/js/admin/nexter-duplicate-post'. $minified .'.js', array(), NEXTER_EXT_VER, true);
	} );

	/**
	 * Meta keys to skip when duplicating (locks, stale generated CSS).
	 *
	 * @return string[]
	 */
	if ( ! function_exists( 'nxt_duplicate_post_skip_meta_keys' ) ) {
		function nxt_duplicate_post_skip_meta_keys() {
			$skip_keys = array(
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
				// Elementor — regenerated per post ID after duplicate.
				'_elementor_css',
				'_elementor_inline_css',
				// Beaver Builder cached assets.
				'_fl_builder_css',
				'_fl_builder_hash',
				// Bricks generated CSS (files are post-ID-specific).
				'_bricks_page_css',
				// Divi static CSS cache.
				'_et_pb_static_css_file',
				'_et_pb_ab_current_shortcode',
			);

			/**
			 * Filter meta keys excluded from post duplication.
			 *
			 * @param string[] $skip_keys Meta keys to skip.
			 */
			return apply_filters( 'nxt_duplicate_post_skip_meta_keys', $skip_keys );
		}
	}

	/**
	 * Copy post meta from DB (preserves Elementor JSON, Bricks arrays, serialized builder data).
	 *
	 * @param int $original_id  Source post ID.
	 * @param int $duplicate_id New post ID.
	 */
	if ( ! function_exists( 'nxt_duplicate_post_copy_meta' ) ) {
		function nxt_duplicate_post_copy_meta( $original_id, $duplicate_id ) {
			global $wpdb;

			$original_id  = absint( $original_id );
			$duplicate_id = absint( $duplicate_id );
			$skip_keys    = nxt_duplicate_post_skip_meta_keys();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$metas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
					$original_id
				)
			);

			if ( empty( $metas ) ) {
				return;
			}

			foreach ( $metas as $meta ) {
				if ( in_array( $meta->meta_key, $skip_keys, true ) ) {
					continue;
				}
				$value = maybe_unserialize( $meta->meta_value );
				add_post_meta( $duplicate_id, $meta->meta_key, $value );
			}
		}
	}

	/**
	 * Copy taxonomies for the duplicate post.
	 *
	 * @param int    $original_id  Source post ID.
	 * @param int    $duplicate_id New post ID.
	 * @param string $post_type    Post type.
	 */
	if ( ! function_exists( 'nxt_duplicate_post_copy_taxonomies' ) ) {
		function nxt_duplicate_post_copy_taxonomies( $original_id, $duplicate_id, $post_type ) {
			$taxonomies = get_object_taxonomies( $post_type );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_object_terms( $original_id, $taxonomy, array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					wp_set_object_terms( $duplicate_id, $terms, $taxonomy, false );
				}
			}
		}
	}

	/**
	 * Regenerate Bricks element IDs on a duplicated post (required for builder + frontend).
	 *
	 * @param array $elements Bricks flat elements array.
	 * @return array
	 */
	if ( ! function_exists( 'nxt_duplicate_post_bricks_regenerate_element_ids' ) ) {
		function nxt_duplicate_post_bricks_regenerate_element_ids( $elements ) {
			if ( ! is_array( $elements ) || empty( $elements ) ) {
				return $elements;
			}

			$id_map = array();
			foreach ( $elements as $element ) {
				if ( empty( $element['id'] ) ) {
					continue;
				}
				$old_id = (string) $element['id'];
				if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'generate_random_id' ) ) {
					$new_id = \Bricks\Helpers::generate_random_id( false );
				} else {
					$new_id = substr( str_replace( array( '-', '_', '.' ), '', wp_generate_password( 8, false, false ) ), 0, 6 );
				}
				$id_map[ $old_id ] = $new_id;
			}

			foreach ( $elements as $index => $element ) {
				if ( ! empty( $element['id'] ) && isset( $id_map[ $element['id'] ] ) ) {
					$elements[ $index ]['id'] = $id_map[ $element['id'] ];
				}
				if ( isset( $element['parent'] ) && 0 !== $element['parent'] && '0' !== $element['parent'] ) {
					$parent = (string) $element['parent'];
					if ( isset( $id_map[ $parent ] ) ) {
						$elements[ $index ]['parent'] = $id_map[ $parent ];
					}
				}
				if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
					$new_children = array();
					foreach ( $element['children'] as $child_id ) {
						$child_id = (string) $child_id;
						$new_children[] = isset( $id_map[ $child_id ] ) ? $id_map[ $child_id ] : $child_id;
					}
					$elements[ $index ]['children'] = $new_children;
				}
			}

			return $elements;
		}
	}

	/**
	 * Apply Bricks-specific fixes after meta copy (_bricks_page_* keys + new element IDs).
	 *
	 * @param int $duplicate_id New post ID.
	 */
	if ( ! function_exists( 'nxt_duplicate_post_bricks_compat' ) ) {
		function nxt_duplicate_post_bricks_compat( $duplicate_id ) {
			if ( ! defined( 'BRICKS_VERSION' ) ) {
				return;
			}

			$bricks_meta_keys = array(
				'_bricks_page_header_2',
				'_bricks_page_content_2',
				'_bricks_page_footer_2',
			);
			if ( defined( 'BRICKS_DB_PAGE_HEADER' ) ) {
				$bricks_meta_keys[0] = BRICKS_DB_PAGE_HEADER;
			}
			if ( defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
				$bricks_meta_keys[1] = BRICKS_DB_PAGE_CONTENT;
			}
			if ( defined( 'BRICKS_DB_PAGE_FOOTER' ) ) {
				$bricks_meta_keys[2] = BRICKS_DB_PAGE_FOOTER;
			}

			$bricks_meta_keys = array_unique( $bricks_meta_keys );

			foreach ( $bricks_meta_keys as $meta_key ) {
				$elements = get_post_meta( $duplicate_id, $meta_key, true );
				if ( empty( $elements ) || ! is_array( $elements ) ) {
					continue;
				}
				$elements = nxt_duplicate_post_bricks_regenerate_element_ids( $elements );
				update_post_meta( $duplicate_id, $meta_key, $elements );
			}

			delete_post_meta( $duplicate_id, '_bricks_page_css' );
		}
	}

	/**
	 * Regenerate or clear page-builder caches so frontend matches the editor.
	 *
	 * @param int $original_id  Source post ID.
	 * @param int $duplicate_id New post ID.
	 */
	if ( ! function_exists( 'nxt_duplicate_post_page_builder_compat' ) ) {
		function nxt_duplicate_post_page_builder_compat( $original_id, $duplicate_id ) {
			$original_id  = absint( $original_id );
			$duplicate_id = absint( $duplicate_id );

			// Featured image (shared attachment ID — same as ASE / Yoast Duplicate Post).
			$thumbnail_id = get_post_thumbnail_id( $original_id );
			if ( $thumbnail_id ) {
				set_post_thumbnail( $duplicate_id, $thumbnail_id );
			}

			// Elementor: rebuild CSS file for the new post ID (fixes blank/wrong frontend layout).
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				delete_post_meta( $duplicate_id, '_elementor_css' );
				if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
					$css_file = new \Elementor\Core\Files\CSS\Post( $duplicate_id );
					if ( method_exists( $css_file, 'update' ) ) {
						$css_file->update();
					} elseif ( method_exists( $css_file, 'delete' ) ) {
						$css_file->delete();
					}
				}
			}

			// Beaver Builder.
			if ( class_exists( 'FLBuilderModel' ) ) {
				if ( method_exists( 'FLBuilderModel', 'delete_asset_cache_for_post' ) ) {
					FLBuilderModel::delete_asset_cache_for_post( $duplicate_id );
				} elseif ( method_exists( 'FLBuilderModel', 'delete_all_asset_cache' ) ) {
					FLBuilderModel::delete_all_asset_cache( $duplicate_id );
				}
			}

			// Bricks — new element IDs + drop stale CSS (regenerated on next builder/front-end load).
			nxt_duplicate_post_bricks_compat( $duplicate_id );

			// Divi Builder.
			if ( function_exists( 'et_pb_set_post_css' ) ) {
				et_pb_set_post_css( $duplicate_id );
			}

			// Oxygen Builder.
			if ( defined( 'CT_VERSION' ) && class_exists( 'Oxygen_VSB_Dynamic_Shortcodes' ) ) {
				delete_post_meta( $duplicate_id, 'ct_builder_css' );
			}

			/**
			 * Run after meta/taxonomy copy — for Pro or third-party builders.
			 *
			 * @param int $original_id  Source post ID.
			 * @param int $duplicate_id New post ID.
			 */
			do_action( 'nxt_post_duplicate_page_builder_compat', $original_id, $duplicate_id );
		}
	}

	/**********************************************************/
	/*
	 * Nexter Function For Ajax Call
	 */
	if( !function_exists('nxt_post_duplicate')){
		function nxt_post_duplicate( $original_id,$p) {
			$extension_option = Nxt_Options::extra_ext();
			$wpDupPostSet = $extension_option['wp-duplicate-post']['values'];
			if(!empty($wpDupPostSet)){

				$args=array(); $do_action=true ;

				$duplicate_access = (!empty($wpDupPostSet['nxt-duppost-access'])) ? $wpDupPostSet['nxt-duppost-access'] : 'all_users';
				$duplicate_author = (!empty($wpDupPostSet['nxt-duppost-author'])) ? $wpDupPostSet['nxt-duppost-author'] : 'current_author';
				$duplicate_date = (!empty($wpDupPostSet['nxt-duppost-date'])) ? $wpDupPostSet['nxt-duppost-date'] : 'original_date';
				$duplicate_status = (!empty($wpDupPostSet['nxt-duppost-status'])) ? $wpDupPostSet['nxt-duppost-status'] : 'same';
				$duplicate_postfix = (!empty($wpDupPostSet['nxt-duplicate-postfix'])) ? $wpDupPostSet['nxt-duplicate-postfix'] : 'Copy';
				$duplicate_slug = (!empty($wpDupPostSet['nxt-duplicate-slug'])) ? $wpDupPostSet['nxt-duplicate-slug'] : 'copy';
			
				$new_settings  = ['duplicate_access' => $duplicate_access,
					'post_author' => $duplicate_author,
					'timestamp' => $duplicate_date,
					'status' => $duplicate_status,
					'title' => $duplicate_postfix,
					'slug' => $duplicate_slug
				];
				$settings = wp_parse_args( $args, $new_settings );
				
				$original_post = get_post( $original_id );
				if ( ! $original_post ) {
					return false;
				}

				if ( $settings['duplicate_access'] == 'original_user' ) {
					if ( (int) $original_post->post_author !== get_current_user_id() ) {
						return false;
					}
				}

				// Change elements
				$postfixText = isset( $settings['title'] ) ? sanitize_text_field( $settings['title'] ) : esc_html__( 'Copy', 'nexter-extension' );
				$p           = absint( $p );
				$new_title   = sanitize_text_field( $original_post->post_title . ' ' . $postfixText . ' #' . $p );
				$new_slug    = sanitize_title( $original_post->post_name . '-' . $settings['slug'] . '-' . $p );

				// Set the status
				$new_status = $original_post->post_status;
				if ( $settings['status'] != 'same' ) {
					$new_status = sanitize_text_field( $settings['status'] );
				}

				// Set the post date
				$timestamp     = ( $settings['timestamp'] == 'original_date' ) ? strtotime( $original_post->post_date ) : current_time( 'timestamp', 0 );
				$timestamp_gmt = ( $settings['timestamp'] == 'original_date' ) ? strtotime( $original_post->post_date_gmt ) : current_time( 'timestamp', 1 );

				$post_author = (int) $original_post->post_author;
				if ( $settings['post_author'] == 'current_author' ) {
					$post_author = get_current_user_id();
				}

				// Backslash-safe content for Gutenberg blocks, shortcodes (WPBakery), and hybrid layouts.
				$post_content = is_string( $original_post->post_content ) ? $original_post->post_content : '';
				$post_content = str_replace( '\\', '\\\\', $post_content );

				$post_excerpt = is_string( $original_post->post_excerpt ) ? $original_post->post_excerpt : '';
				$post_excerpt = str_replace( '\\', '\\\\', $post_excerpt );

				/**
				 * Filter post content before insert (page builders that store data in post_content).
				 *
				 * @param string  $post_content   Prepared post content.
				 * @param WP_Post $original_post  Source post object.
				 * @param array   $settings       Duplicator settings.
				 */
				$post_content = apply_filters( 'nxt_duplicate_post_content', $post_content, $original_post, $settings );

				$insert_args = array(
					'comment_status'    => $original_post->comment_status,
					'ping_status'       => $original_post->ping_status,
					'post_author'       => $post_author,
					'post_content'      => $post_content,
					'post_excerpt'      => $post_excerpt,
					'post_name'         => $new_slug,
					'post_parent'       => $original_post->post_parent,
					'post_password'     => $original_post->post_password,
					'post_status'       => $new_status,
					'post_title'        => $new_title,
					'post_type'         => $original_post->post_type,
					'post_date'         => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $timestamp_gmt ),
					'post_modified'     => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', 1 ) ),
					'to_ping'           => $original_post->to_ping,
					'menu_order'        => $original_post->menu_order,
				);

				$duplicate_id = wp_insert_post( $insert_args, true );
				if ( is_wp_error( $duplicate_id ) ) {
					return false;
				}

				// Taxonomies, meta (_elementor_*, _bricks_*, _fl_builder_*, _et_pb_*, ct_*, etc.), builder CSS.
				nxt_duplicate_post_copy_taxonomies( $original_id, $duplicate_id, $original_post->post_type );
				nxt_duplicate_post_copy_meta( $original_id, $duplicate_id );
				nxt_duplicate_post_page_builder_compat( $original_id, $duplicate_id );

				if ( $do_action ) {
					do_action( 'nxt_post_duplicate_custom', $original_id, $duplicate_id, $settings );
				}
				return $duplicate_id;
			}
		}
	}
	/******
	Nexter Duplicate Ajax Function
	******/
	if( !function_exists('nxt_duplicate_post_ajax') ){
		function nxt_duplicate_post_ajax() {
			check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( __('Insufficient permissions.','nexter-extension') );
			}
			
			$original_id  = ( isset( $_POST['original_id'] ) ) ? sanitize_text_field( intval( $_POST['original_id'] ) ) : '';

			if ( ! current_user_can( 'edit_post', $original_id ) ) {
				wp_send_json_error( __('You do not have permission to duplicate this post.','nexter-extension') );
			}

			$total  = ( isset( $_POST['total'] ) ) ? sanitize_text_field( intval( $_POST['total'] ) ) : '';

			for($p=1; $p<=$total;$p++){
				nxt_post_duplicate( $original_id,$p );
			}
			wp_send_json_success();
		}
		add_action( 'wp_ajax_nxt_duplicate_post', 'nxt_duplicate_post_ajax' );
	}
}