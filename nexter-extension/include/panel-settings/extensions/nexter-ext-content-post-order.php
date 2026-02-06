<?php 
/*
 * Content Post Order Extension
 * @since 4.2.1
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Content_Post_Order {
    
    public static $post_type_order = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->nxt_get_post_order_settings();
        add_action( 'admin_enqueue_scripts', [ $this,'post_order_scripts' ] );
        add_action( 'wp_ajax_nxt_save_post_order', [$this, 'save_post_content_order'] );
        add_filter( 'pre_get_posts', [ $this, 'orderby_menu_order' ], PHP_INT_MAX );
    }

    private function nxt_get_post_order_settings(){

		if(isset(self::$post_type_order) && !empty(self::$post_type_order)){
			return self::$post_type_order;
		}

		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['content-post-order']) && !empty($option['content-post-order']['switch']) && !empty($option['content-post-order']['values']) ){
			self::$post_type_order = $option['content-post-order']['values'];
		}

		return self::$post_type_order;
	}

    public function post_order_scripts( $hook ) {
        $screen = get_current_screen();
        if( !isset( $screen->post_type )   ||  empty($screen->post_type)){
            return;
        }
        if ( $hook !== 'edit.php' && $hook !== 'upload.php') {
			return;
		}
        if ( wp_is_mobile() || ( function_exists( 'jetpack_is_mobile' ) && jetpack_is_mobile() ) )
            return;

        //if is taxonomy term filter return
        if(is_category()    ||  is_tax())
            return;
        
        //return if use orderby columns
        // Security: Sanitize input
        $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
        if ( $orderby !== 'menu_order' && ! empty( $orderby ) ) {
            return false;
        }
            
        //return if post status filtering
        // Security: Sanitize input
        $post_status = isset( $_GET['post_status'] ) ? sanitize_text_field( wp_unslash( $_GET['post_status'] ) ) : '';
        if ( $post_status !== 'all' && ! empty( $post_status ) ) {
            return false;
        }
            
        //return if post author filtering
        // Security: Sanitize input
        $author = isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0;
        if ( $author > 0 ) {
            return false;
        }

        $post_type  = $screen->post_type;
        if ( ! empty( $screen ) && in_array($post_type, self::$post_type_order) ) {
            
            $post_type_object = get_post_type_object( $post_type );
           
            $hierarchical = (bool) $post_type_object->hierarchical;
            // Security: Sanitize input
            $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

            // Get pagination info
            $posts_per_page = (int) get_user_option( 'edit_' . $post_type . '_per_page', get_current_user_id() );
            if ( ! $posts_per_page ) {
                $posts_per_page = 20; // Default value if not set in Screen Options
            }

            global $wp_query;

            $raw_posts = $wp_query->posts;
            $ordered_posts = [];

            // Assign menu_order or fallback to index-based ordering
            if(!empty($raw_posts)){
                foreach ( $raw_posts as $index => $post ) {
                    $post->menu_order = $post->menu_order ?? ( $index + 1 );
                    $ordered_posts[]  = $post;
                }

                // Sort posts by menu_order
                usort( $ordered_posts, function ( $a, $b ) {
                    return $a->menu_order <=> $b->menu_order;
                });
            }
            
            $final_posts = $hierarchical
                ? $this->build_hierarchical_posts( $post_type, $ordered_posts, $current_page, $posts_per_page )
                : $ordered_posts;
            
            add_filter( "views_edit-{$post_type}", [ $this, 'add_reorder_button' ], 10, 1 );

            wp_enqueue_style( 'nxt-post-order', NEXTER_EXT_URL . 'assets/css/admin/nxt-post-order.css', [], NEXTER_EXT_VER );
            wp_enqueue_script( 'nxt-sortable', NEXTER_EXT_URL . 'assets/js/extra/sortable.min.js', [], NEXTER_EXT_VER, false );
            wp_enqueue_script( 'nxt-post-order', NEXTER_EXT_URL . 'assets/js/admin/nxt-post-order.js', ['nxt-sortable'], NEXTER_EXT_VER, false );

            wp_localize_script( 'nxt-post-order', 'nxtContentPostOrder', array(
                'posts'         => $final_posts,
                'post_type'     => $post_type,
                'nonce'         => wp_create_nonce( 'nxt_post_order_nonce' ),
                'hierarchical'  => $hierarchical,
                // Security: Sanitize input
                'reorder'       => isset( $_GET['nxt_reorder'] ) ? sanitize_text_field( wp_unslash( $_GET['nxt_reorder'] ) ) : 'default',
                'current_page' => $current_page,
			    'per_page'     => $posts_per_page,
            ) );
        }
    }

    /**
     * Save content Post Order
     * Version 4.4.0
     */
	public function add_reorder_button( $views ) {
		$screen    = get_current_screen();
		$post_type = $screen->post_type;
		// Security: Sanitize input
		$mode = isset( $_GET['nxt_reorder'] ) ? sanitize_text_field( wp_unslash( $_GET['nxt_reorder'] ) ) : 'default';
		$url       = add_query_arg( [
			'post_type' => $post_type,
			'nxt_reorder'      => $mode === 'sortable' ? 'default' : 'sortable',
		] );

		// translators: %1$s: Current class, %2$s: URL, %3$s: Re-Order text
		$views['nxt_reorder'] = sprintf(
			'<a id="nxt-reorder-btn" class="nxt-reorder-btn %1$s" href="%2$s">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path d="M7 20h2V8h3L8 4 4 8h3zm13-4h-3V4h-2v12h-3l4 4z"/></svg>
				%3$s
			</a>',
			$mode === 'sortable' ? 'current' : '',
			$url,
			esc_html__( 'Re-Order', 'nexter-extension' )
		);

		return $views;
	}

    private function build_hierarchical_posts( string $post_type, array $posts, int $page, int $per_page ) {
        // Step 1: Filter out top-level posts
        $top_level_posts = array_filter( $posts, fn( $post ) => $post->post_parent == 0 );
        $top_level_ids   = wp_list_pluck( $top_level_posts, 'ID' );

        // Step 2: Paginate top-level posts
        $paginated_top_ids = array_chunk( $top_level_ids, $per_page );
        $current_page_ids  = $paginated_top_ids[ $page - 1 ] ?? [];

        // Step 3: Collect all children of current top-level posts
        $post_id_lookup = array_column( $posts, null, 'ID' );
        $required_ids   = $current_page_ids;

        foreach ( $current_page_ids as $parent_id ) {
            $required_ids = array_merge(
                $required_ids,
                $this->fetch_descendants( $parent_id, $post_id_lookup )
            );
        }

        $required_ids = array_unique( $required_ids );

        // Step 4: Query full post data if needed
        $structured_posts = [];
        if ( ! empty( $required_ids ) ) {
            $query_args = [
                'post_type'              => $post_type,
                'post__in'               => $required_ids,
                'posts_per_page'         => -1,
                'orderby'                => 'post__in',
                'ignore_sticky_posts'    => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'no_found_rows'          => false,
            ];

            $queried = new WP_Query( $query_args );
            $structured_posts = array_map( function ( $post ) use ( $post_id_lookup ) {
                return [
                    'ID'          => $post->ID,
                    'post_title'  => $post->post_title ?: __( '(no title)', 'mb-custom-post-type' ),
                    'post_parent' => $post->post_parent,
                    'menu_order'  => $post->menu_order ?: $post_id_lookup[ $post->ID ]->menu_order,
                    'post_status' => $post->post_status,
                ];
            }, $queried->posts );
        }

        return $structured_posts;
    }

    private function fetch_descendants( int $parent_id, array $post_map ) {
        $descendants = [];

        foreach ( $post_map as $post ) {
            if ( $post->post_parent === $parent_id ) {
                $descendants[] = $post->ID;
                $descendants   = array_merge(
                    $descendants,
                    $this->fetch_descendants( $post->ID, $post_map )
                );
            }
        }

        return $descendants;
    }

    /**
     * Save Content Post Order via AJAX (supports hierarchical posts)
     */
    public function save_post_content_order() {
        global $wpdb, $userdata;

        // Check permission
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Verify nonce
        check_ajax_referer( 'nxt_post_order_nonce', 'nonce' );

        // Security: Sanitize and validate post type more strictly
        $post_type = isset( $_POST['post_type'] )
            ? sanitize_key( wp_unslash( $_POST['post_type'] ) )
            : '';

        if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
            wp_send_json_error( 'Invalid post type.' );
        }

        // Get current page number
        $paged = isset( $_POST['paged'] )
            ? max( 1, (int) sanitize_text_field( wp_unslash( $_POST['paged'] ) ) )
            : 1;

        // Security: Validate and sanitize order data
        $order_data_raw = isset( $_POST['order_data'] ) ? wp_unslash( $_POST['order_data'] ) : '';
        $order_array    = ! empty( $order_data_raw ) ? json_decode( $order_data_raw, true ) : [];
        
        // Security: Validate JSON was decoded successfully and is an array
        if ( ! empty( $order_data_raw ) ) {
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                wp_send_json_error( 'Invalid order data format.' );
            }
            if ( ! is_array( $order_array ) ) {
                wp_send_json_error( 'Order data must be an array.' );
            }
        }

        // Backup flat order from post[] or media[] (from table reorder)
        $flat_order_raw = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : '';
        parse_str( $flat_order_raw, $parsed_order );
        
        // Security: Sanitize parsed order data
        $parsed_order = array_map( function( $value ) {
            if ( is_array( $value ) ) {
                return array_map( 'absint', $value );
            }
            return absint( $value );
        }, $parsed_order );
        $flat_order_ids = isset( $parsed_order['post'] ) ? $parsed_order['post'] : ( $parsed_order['media'] ?? [] );

        // Security: Determine post IDs to update with validation
        if ( ! empty( $order_array ) && is_array( $order_array ) ) {
            // Security: Limit the number of items to prevent resource exhaustion
            $max_items = 1000;
            if ( count( $order_array ) > $max_items ) {
                /* translators: %d: Maximum number of items allowed */
                wp_send_json_error( sprintf( __( 'Too many items. Maximum %d items allowed.', 'nexter-extension' ), $max_items ) );
            }
            
            // Security: Handle hierarchical (tree-based) reorder with validation
            foreach ( $order_array as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                
                $post_id    = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
                $menu_order = isset( $item['order'] ) ? absint( $item['order'] ) : 0;
                $parent_id  = isset( $item['parent_id'] ) ? absint( $item['parent_id'] ) : 0;

                // Security: Verify post exists and user has permission to edit it
                if ( $post_id > 0 && $post_id !== $parent_id ) {
                    if ( ! current_user_can( 'edit_post', $post_id ) ) {
                        continue; // Skip posts user cannot edit
                    }
                    
                    // Security: Verify post type matches
                    $post = get_post( $post_id );
                    if ( ! $post || $post->post_type !== $post_type ) {
                        continue;
                    }
                    
                    // Security: Validate parent_id if provided
                    if ( $parent_id > 0 ) {
                        $parent_post = get_post( $parent_id );
                        if ( ! $parent_post || $parent_post->post_type !== $post_type ) {
                            $parent_id = 0; // Reset to 0 if invalid
                        }
                    }
                    
                    $wpdb->update(
                        $wpdb->posts,
                        [
                            'menu_order'  => $menu_order,
                            'post_parent' => $parent_id,
                        ],
                        [ 'ID' => $post_id ],
                        [ '%d', '%d' ],
                        [ '%d' ]
                    );
                    clean_post_cache( $post_id );
                }
            }
        } elseif ( ! empty( $flat_order_ids ) && is_array( $flat_order_ids ) ) {
            // Security: Limit batch size to prevent resource exhaustion
            if ( count( $flat_order_ids ) > 1000 ) {
                wp_send_json_error( __( 'Too many items to process at once.', 'nexter-extension' ) );
            }
            // Handle flat reorder from input[name="post[]"] or input[name="media[]"]

            // Security: Validate post_type to prevent SQL injection
            $post_type = preg_replace( '/[^a-zA-Z0-9_-]/', '', $post_type );
            if ( empty( $post_type ) ) {
                wp_send_json_error( 'Invalid post type.' );
            }
            
            //retrieve a list of all objects
            $query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = %s 
                AND post_status IN ('publish', 'pending', 'draft', 'private', 'future', 'inherit') 
                ORDER BY menu_order, post_date DESC",
                $post_type
            );
        
            $posts = $wpdb->get_col( $query );

            if ( empty( $posts ) ) {
                wp_send_json_error( 'No posts found.' );
            }

            $per_page_meta_key = $post_type === 'attachment' ? 'upload_per_page' : "edit_{$post_type}_per_page";
            $objects_per_page  = (int) get_user_meta( $userdata->ID, $per_page_meta_key, true );
            $objects_per_page  = apply_filters( "edit_{$post_type}_per_page", $objects_per_page );

            if ( $objects_per_page <= 0 ) {
                $objects_per_page = 20;
            }
            $start  = ( $paged - 1 ) * $objects_per_page;
            $slice  = array_slice( $posts, $start, $objects_per_page );
            $new_ids = array_map( 'absint', $flat_order_ids );
            
            // Security: Limit batch size to prevent resource exhaustion
            if ( count( $new_ids ) > 1000 ) {
                wp_send_json_error( __( 'Too many items to process at once.', 'nexter-extension' ) );
            }
            
            // Security: Verify all post IDs exist and belong to correct post type
            $valid_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $new_ids ), '%d' ) ) . ") AND post_type = %s",
                array_merge( $new_ids, array( $post_type ) )
            ) );
            $valid_ids = array_map( 'absint', $valid_ids );

            // Update menu_order
            foreach ( $slice as $menu_order => $post_id ) {
                if ( isset( $new_ids[ $menu_order ] ) && in_array( $new_ids[ $menu_order ], $valid_ids, true ) ) {
                    // Security: Verify user has permission to edit this post
                    if ( ! current_user_can( 'edit_post', $new_ids[ $menu_order ] ) ) {
                        continue;
                    }
                    
                    $wpdb->update(
                        $wpdb->posts,
                        [ 'menu_order' => absint( $menu_order ) ],
                        [ 'ID' => absint( $new_ids[ $menu_order ] ) ],
                        [ '%d' ],
                        [ '%d' ]
                    );
                    clean_post_cache( $new_ids[ $menu_order ] );
                }
            }

        } else {
            wp_send_json_error( 'No valid order data received.' );
        }

        // Optional: clear site cache or refresh UI
        self::site_cache_clear();

        wp_send_json_success( 'Post order updated successfully.' );
    }



    /**
    * Clear cache plugins
    */
    static public function site_cache_clear() {
        wp_cache_flush();
        
        $cleared_cache  =   FALSE;
        if ( function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared_cache  =   TRUE;
        }
        
        if ( function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared_cache  =   TRUE;
        }
            
        if ( function_exists('opcache_reset')    &&  ! ini_get( 'opcache.restrict_api' ) ){
            @opcache_reset();
            $cleared_cache  =   TRUE;
        }
        
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
            $cleared_cache  =   TRUE;
        }
            
        if ( function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared_cache  =   TRUE;
        }
    
        global $wp_fastest_cache;
        if ( method_exists( 'WpFastestCache', 'deleteCache' ) && !empty( $wp_fastest_cache ) ) {
            $wp_fastest_cache->deleteCache();
            $cleared_cache  =   TRUE;
        }
    
        if ( function_exists('apc_clear_cache')) {
            apc_clear_cache();
            $cleared_cache  =   TRUE;
        }
            
        if ( function_exists('fvm_purge_all')) {
            fvm_purge_all();
            $cleared_cache  =   TRUE;
        }
        
        if ( class_exists( 'autoptimizeCache' ) ) {
            autoptimizeCache::clearall();
            $cleared_cache  =   TRUE;
        }

        //WPEngine
        if ( class_exists( 'WpeCommon' ) ) {
            if ( method_exists( 'WpeCommon', 'purge_memcached' ) )
                WpeCommon::purge_memcached();
            if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) )
                WpeCommon::clear_maxcdn_cache();
            if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) )
                WpeCommon::purge_varnish_cache();
            
            $cleared_cache  =   TRUE;
        }
            
        if (class_exists('Cache_Enabler_Disk') && method_exists('Cache_Enabler_Disk', 'clear_cache')) {
            Cache_Enabler_Disk::clear_cache();
            $cleared_cache  =   TRUE;
        }
            
        //Perfmatters
        if ( class_exists('Perfmatters\CSS') && method_exists('Perfmatters\CSS', 'clear_used_css') ) {
            Perfmatters\CSS::clear_used_css();
            $cleared_cache  =   TRUE;
        }
        
        if ( defined( 'BREEZE_VERSION' ) ) {
            do_action( 'breeze_clear_all_cache' );
            $cleared_cache  =   TRUE;
        }
            
        if ( function_exists('sg_cachepress_purge_everything')) {
            sg_cachepress_purge_everything();
            $cleared_cache  =   TRUE;
        }
        
        if ( defined ( 'FLYING_PRESS_VERSION' ) ) {
            do_action('flying_press_purge_everything:before');

            @unlink(FLYING_PRESS_CACHE_DIR . '/preload.txt');

            // Delete all files and subdirectories
            FlyingPress\Purge::purge_everything();

            @mkdir(FLYING_PRESS_CACHE_DIR, 0755, true);

            do_action('flying_press_purge_everything:after');
            
            $cleared_cache  =   TRUE;
        }
            
        if (class_exists('\LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_all();
            $cleared_cache  =   TRUE;
        }
            
        return $cleared_cache;
    }  

    /**
     * Set default ordering for sortable post types using 'menu_order' and 'title'.
     */
    public function orderby_menu_order( $query ) {
        global $pagenow, $typenow;

        // Exit early if not a query object or not affecting posts
        if ( ! $query->is_main_query() ) {
            return;
        }

        $post_type_order = self::$post_type_order;
        $current_post_type = $query->get( 'post_type' );

        // Backend: Apply ordering on post list screens if not already ordered
        // Security: Sanitize input
        $get_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
        if ( is_admin() && ( $pagenow === 'edit.php' || $pagenow === 'upload.php' ) && empty( $get_orderby ) ) {
            if ( in_array( $typenow, $post_type_order, true ) ) {
                $query->set( 'orderby', 'menu_order title' );
                $query->set( 'order', 'ASC' );
            }
            return;
        }

        // Frontend: Skip search results
        if ( is_admin() || $query->is_search() ) {
            return;
        }

        // Helper function to apply ordering
        $apply_ordering = function () use ( $query ) {
            $query->set( 'orderby', 'menu_order title' );
            $query->set( 'order', 'ASC' );
        };

        // Front page blog list
        if ( is_home() && in_array( 'post', $post_type_order, true ) ) {
            $apply_ordering();
            return;
        }

        // Archive pages
        if ( is_archive() ) {
            $should_sort = false;

            if ( is_post_type_archive() ) {
                $post_type = get_query_var( 'post_type' );
                if ( in_array( $post_type, $post_type_order, true ) ) {
                    $should_sort = true;
                }
            } elseif ( is_category() || is_tag() || is_tax() ) {
                $term = get_queried_object();
                if ( $term instanceof WP_Term ) {
                    $taxonomy_object = get_taxonomy( $term->taxonomy );
                    $related_post_types = $taxonomy_object->object_type ?? [];
                    if ( array_intersect( $related_post_types, $post_type_order ) ) {
                        $should_sort = true;
                    }
                }
            }

            if ( $should_sort ) {
                $apply_ordering();
            }
            return;
        }

        // Custom loops (not main singular post/page)
        if ( ! is_singular() && in_array( $current_post_type, $post_type_order, true ) ) {
            $apply_ordering();
        }
    }
}

 new Nexter_Ext_Content_Post_Order();