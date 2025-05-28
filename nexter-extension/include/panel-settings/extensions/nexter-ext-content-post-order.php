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
        if ( wp_is_mobile() || ( function_exists( 'jetpack_is_mobile' ) && jetpack_is_mobile() ) )
            return;

        //if is taxonomy term filter return
        if(is_category()    ||  is_tax())
            return;
        
        //return if use orderby columns
        if (isset($_GET['orderby']) && $_GET['orderby'] !==  'menu_order')
            return false;
            
        //return if post status filtering
        if ( isset( $_GET['post_status'] )  &&  $_GET['post_status']    !== 'all' )
            return false;
            
        //return if post author filtering
        if (isset($_GET['author']))
            return false;
        if ( ! empty( $screen ) && in_array($screen->post_type, self::$post_type_order) ) {
            wp_enqueue_style( 'nxt-post-order', NEXTER_EXT_URL . 'assets/css/admin/nxt-post-order.css', [], NEXTER_EXT_VER );
            wp_enqueue_script( 'nxt-sortable', NEXTER_EXT_URL . 'assets/js/extra/sortable.min.js', [], NEXTER_EXT_VER, false );
            wp_enqueue_script( 'nxt-post-order', NEXTER_EXT_URL . 'assets/js/admin/nxt-post-order.js', ['nxt-sortable'], NEXTER_EXT_VER, false );

            wp_localize_script( 'nxt-post-order', 'nxtContentPostOrder', array(
                'post_type'      => $screen->post_type,
                'nonce'       => wp_create_nonce( 'nxt_post_order_nonce' ),
            ) );
        }
    }

    /**
     * Save content Post Order
     */
    public function save_post_content_order() {
        global $wpdb, $userdata;
        // Check user capabilities
        if ( !current_user_can( 'edit_others_posts' ) ) {
            wp_send_json( 'Something went wrong.' );
        }
        // Verify nonce
        check_ajax_referer( 'nxt_post_order_nonce', 'nonce' );
        
        $post_type  =  isset($_POST['post_type']) ? preg_replace( '/[^a-zA-Z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) ) : '';
        $paged      =  isset($_POST['paged']) ? filter_var ( sanitize_text_field( wp_unslash( $_POST['paged'] ) ), FILTER_SANITIZE_NUMBER_INT) : 1;
       
        parse_str( sanitize_text_field( wp_unslash( $_POST['order'] ) ) , $order_data );
                    
        if ( empty( $order_data['post'] ) || ! is_array( $order_data['post'] ) ) {
            wp_send_json_error( 'Invalid order data.' );
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

        // Get pagination settings
        $per_page_meta_key = $post_type === 'attachment' ? 'upload_per_page' : "edit_{$post_type}_per_page";
        $objects_per_page  = (int) get_user_meta( $userdata->ID, $per_page_meta_key, true );
        $objects_per_page  = apply_filters( "edit_{$post_type}_per_page", $objects_per_page );

        if ( $objects_per_page <= 0 ) {
            $objects_per_page = 20;
        }

        // Slice the correct portion of posts
        $start  = ( $paged - 1 ) * $objects_per_page;
        $slice  = array_slice( $posts, $start, $objects_per_page );
        $new_ids = array_map( 'intval', $order_data['post'] );

        // Update menu_order
        foreach ( $slice as $menu_order => $post_id ) {
            if ( isset( $new_ids[ $menu_order ] ) ) {
                $wpdb->update(
                    $wpdb->posts,
                    [ 'menu_order' => $menu_order ],
                    [ 'ID' => $new_ids[ $menu_order ] ],
                    [ '%d' ],
                    [ '%d' ]
                );
                clean_post_cache( $new_ids[ $menu_order ] );
            }
        }

        self::site_cache_clear();
        wp_send_json_success( 'Order updated.' );
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
        if ( is_admin() && ( $pagenow === 'edit.php' || $pagenow === 'upload.php' ) && ! isset( $_GET['orderby'] ) ) {
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