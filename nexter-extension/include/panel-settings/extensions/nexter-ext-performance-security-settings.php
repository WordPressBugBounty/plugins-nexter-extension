<?php 
/*
 * Disable Admin Settings Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

class Nexter_Ext_Performance_Security_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {

		// Nexter Site Performance
		$extension_option = get_option( 'nexter_site_performance' );

		// Nexter Security
		$nxt_security_option = get_option( 'nexter_site_security' );

		add_action('init',[$this,'add_security_header']);

		if(isset($nxt_security_option) && !empty($nxt_security_option) && isset($nxt_security_option['iframe_security'])){
			add_action('send_headers',[$this,'add_x_frame_options_header']);
		}

		if(isset($nxt_security_option) && !empty($nxt_security_option) && in_array("remove_meta_generator",$nxt_security_option)){
			add_action('init',[$this,'remove_meta_generator']);
		}

		//XSS Protection
		if(isset($nxt_security_option) && !empty($nxt_security_option) && in_array('xss_protection',$nxt_security_option)){
			add_action( 'send_headers', function() {
				header("X-XSS-Protection: 1; mode=block");
			}, 99 );
		}

		if(isset($nxt_security_option) && !empty($nxt_security_option) && in_array('user_register_date_time',$nxt_security_option)){
			add_filter( 'manage_users_columns', function( $columns ){
				$columns['nxt_registered_date'] = __( 'Registered', 'nexter-extension' );
				return $columns;
			} );

            add_filter('manage_users_custom_column',function( $output, $column_name, $user_id ){
				
				if ( 'nxt_registered_date' === $column_name ) {
					$user = get_userdata( $user_id );
       				$user_registered_date = strtotime( $user->user_registered );
					$date_format = get_option('date_format', 'F j, Y');
        			$time_format = get_option('time_format', 'g:i a');
					
					$output = function_exists('wp_date') ? wp_date("$date_format $time_format", $user_registered_date) : date_i18n("$date_format $time_format", $user_registered_date);
				}
				return $output;
			}, 10, 3);
		}

		if(isset($nxt_security_option) && !empty($nxt_security_option) && in_array('user_last_login_display',$nxt_security_option)){
			//Update Last Login
			add_action( 'wp_login', function ( $user_login ){
				$user = get_user_by( 'login', $user_login );
				if ( is_object( $user ) ) {
					if ( property_exists( $user, 'ID' ) ) {
						update_user_meta( $user->ID, 'nxt_last_login_on', time() );
					}
				}
			}, 3, 1 );
			//Column
			add_filter( 'manage_users_columns', function( $columns ) {
				$columns['nxt_last_login_on'] = __( 'Last Login', 'nexter-extension' );
				return $columns;
			} );
			add_filter('manage_users_custom_column', function( $output, $column_name, $user_id ) {
				if ($column_name === 'nxt_last_login_on') {
					$nxt_last_login_on = (int) get_user_meta($user_id, 'nxt_last_login_on', true);
					if (!empty($nxt_last_login_on)) {
						$format = get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a');
						$output = function_exists('wp_date') ? wp_date($format, $nxt_last_login_on) : date_i18n($format, $nxt_last_login_on);
					} else {
						$output = __('Never', 'nexter-extension');
					}
				}
				return $output;
			}, 10, 3 );
		}
		
		if( !empty($extension_option) ){
			/*Disable Emojis Scripts*/
			if( in_array("disable_emoji_scripts",$extension_option) ){
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
				remove_action( 'wp_print_styles', 'wp_enqueue_emoji_styles' );
				remove_action( 'admin_print_styles', 'wp_enqueue_emoji_styles' );
				remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
				remove_filter( 'comment_text_rss', 'wp_staticize_emoji' ); 
				remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
				
				add_filter('tiny_mce_plugins', function ($plugins) {
					if (is_array($plugins)) {
						return array_diff($plugins, array('wpemoji'));
					} else {
						return array();
					}
				});

				add_filter('wp_resource_hints', function ($urls, $relation_type) {
					if ('dns-prefetch' === $relation_type) {
						/** This filter is documented in wp-includes/formatting.php */
						$emoji_svg_url = apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/');

						$urls = array_diff($urls, array($emoji_svg_url));
					}

					return $urls;
				}, 10, 2);
			}
			
			/*Disable Embeds*/
			if( in_array("disable_embeds",$extension_option) ){
				add_action('init',  [ $this, 'nxt_disable_embeds' ], 9999);
			}
			
			/*Disable Embeds*/
			if( in_array("media_infinite_scroll",$extension_option) ){
				
				add_filter( 'media_library_infinite_scrolling', '__return_true' );
			}

			/*Disable DashIcons*/
			if( in_array("disable_dashicons",$extension_option)  ){
				add_action('wp_enqueue_scripts', function() { 
					if(!is_user_logged_in()) {
						wp_dequeue_style('dashicons');
						wp_deregister_style('dashicons');
					}
				});
			}

			/*Remove RSD Link*/
			if( in_array("disable_rsd_link",$extension_option)  ){
				remove_action('wp_head', 'rsd_link');
			}

			/*Remove wlwmanifest Link*/
			if( in_array("disable_wlwmanifest_link",$extension_option) ){
				remove_action('wp_head', 'wlwmanifest_link');
			}
			/*Remove Shortlink Link*/
			if( in_array("disable_shortlink",$extension_option) ){
				remove_action('wp_head', 'wp_shortlink_wp_head');
				remove_action ('template_redirect', 'wp_shortlink_header', 11, 0);
			}

			/*Remove RSS Feeds*/
			if( in_array("disable_rss_feeds",$extension_option) ){
				add_action('template_redirect', [ $this , 'nxt_disable_rss_feeds'], 1);
			}

			/*Remove RSS Feed Links*/
			if( in_array("disable_rss_feed_link",$extension_option) ){
				remove_action('wp_head', 'feed_links_extra', 3);
				remove_action('wp_head', 'feed_links', 2);
			}
			
			/*Disable Self Pingbacks*/
			if( in_array("disable_self_pingbacks",$extension_option) ){
				add_action('pre_ping', [ $this , 'nxt_disable_self_pingbacks']);
			}

			/* Disable Password Strength Meter */
			if( in_array("disable_pw_strength_meter",$extension_option) ){
	
				add_action('wp_print_scripts', function(){
					//admin
					if( is_admin() ) {
						return;
					}
					
					//wp-login.php
					if( ( isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php' ) || ( isset($_GET['action']) && in_array($_GET['action'], array('register','rp', 'lostpassword' )) ) ) {
						return;
					}
			
					//woocommerce
					if( class_exists('WooCommerce') && ( is_account_page() || is_checkout() ) ) {
						return;
					}
				
					wp_dequeue_script('password-strength-meter');
					wp_deregister_script('password-strength-meter');
			
					wp_dequeue_script('wc-password-strength-meter');
					wp_deregister_script('wc-password-strength-meter');
					
					wp_dequeue_script('zxcvbn-async');
					wp_deregister_script('zxcvbn-async');
					
				}, 100);
			}

			/* Defer CSS/JS */
			if( !is_admin() && in_array("defer_css_js",$extension_option) ){
				add_filter( 'style_loader_tag', [$this, 'nxt_onload_style_css'], 10, 4 );
				add_filter( 'script_loader_tag', [$this,'nxt_onload_defer_js'], 10, 2 );
			}
			if( isset($extension_option['disable_comments']) && !empty($extension_option['disable_comments']) && ($extension_option['disable_comments'] === 'custom' || $extension_option['disable_comments'] === 'all')){
				add_action('wp_loaded', [ $this , 'nxt_wp_loaded_comments']);
			}
			

			/*Disable Comments Entire Site*/
			if( isset($extension_option['disable_comments']) && !empty($extension_option['disable_comments']) && $extension_option['disable_comments'] === 'all' ) {

				//Disable Built-in Recent Comments Widget
				add_action('widgets_init', function(){
					unregister_widget('WP_Widget_Recent_Comments');
					add_filter('show_recent_comments_widget_style', '__return_false');
				});
				
				if( in_array("disable_rss_feed_link",$extension_option) ){
					// feed_links_extra inserts a comments RSS link.
					remove_action('wp_head', 'feed_links_extra', 3);
				}
				
				//Disable 403 for all comment feed requests
				add_action('template_redirect', function(){
					if(is_comment_feed()) {
						wp_die( esc_html__('Comments are disabled.', 'nexter-extension'), '', array('response' => 403));
					}
				}, 9);
				
				//Remove Comment Admin bar filtering
				add_action('template_redirect',  [ $this,'nxt_filter_admin_bar'] );
				add_action('admin_init', [ $this, 'nxt_filter_admin_bar']);
				
				add_filter('rest_endpoints', [ $this , 'nxt_filter_rest_endpoints']);
				
			}
			
			//Revision Control
			if( isset($extension_option['post-revision-control']) && !empty($extension_option['post-revision-control']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-post-revision-control.php';
			}

			//Heartbeat Control
			if( isset($extension_option['heartbeat-control']) && !empty($extension_option['heartbeat-control']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-heartbeat-control.php';
			}
		}
		
		if( isset($nxt_security_option) && !empty($nxt_security_option)){

			// Disable XML-RPC
			if( in_array( 'disable_xml_rpc' , $nxt_security_option ) ){
				add_filter('xmlrpc_enabled', '__return_false');
				add_filter('wp_headers', [ $this , 'nxt_remove_x_pingback'] );
				add_filter('pings_open', '__return_false', 9999);
				add_filter('pre_update_option_enable_xmlrpc', '__return_false');
				add_filter('pre_option_enable_xmlrpc', '__return_zero');
				add_action('init', [ $this , 'nxt_xmlrpc_header']);
			}

			// Disable WP Version
			if( in_array( 'disable_wp_version' , $nxt_security_option ) ){
				remove_action('wp_head', 'wp_generator');
				add_filter('the_generator', function(){
					return '';
				});
			}

			if( in_array( 'disable_rest_api_links' , $nxt_security_option ) ){
				remove_action('wp_head', 'rest_output_link_wp_head');
				remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
				remove_action('template_redirect', 'rest_output_link_header', 11, 0);
			}

			if( isset($nxt_security_option['disable_rest_api']) && !empty($nxt_security_option['disable_rest_api']) ){
				
				add_filter( 'rest_authentication_errors', function( $result ) {
					if(!empty($result)) {
						return $result;
					}else{
						$nxt_site_security =  get_option( 'nexter_site_security' );
						$check_disabled = false;
			
						//get rest route
						$rest_route = $GLOBALS['wp']->query_vars['rest_route'];
			
						//check rest route for exceptions
						if(strpos($rest_route, 'contact-form-7') !== false) {
							return;
						}
			
						//check options
						if( isset($nxt_site_security['disable_rest_api'] ) && !empty($nxt_site_security['disable_rest_api'] ) && $nxt_site_security['disable_rest_api'] == 'non_admin' && !current_user_can('manage_options')) {
							$check_disabled = true;
						}else if( isset($nxt_site_security['disable_rest_api'] ) && !empty($nxt_site_security['disable_rest_api'] ) && $nxt_site_security['disable_rest_api'] == 'logged_out' && !is_user_logged_in()) {
							// Return an error if user is not logged in.
							$check_disabled = true;
						}
					}
					if($check_disabled) {
						return new WP_Error('rest_authentication_error', __('Sorry, do not have permission REST API requests.', 'nexter-extension'), array('status' => 401));
					}
					
					// on logged-in requests
					return $result;
					
				}, 20);
			}

			//SVG Upload
			$svg_data = $this->nxt_convert_object_to_array($nxt_security_option);
			if(isset($svg_data['svg-upload']) && !empty($svg_data['svg-upload']['switch']) && !empty($svg_data['svg-upload']['values'])){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-svg-upload.php';
			}
		}

    }

	public function nxt_convert_object_to_array($data) {
		if (is_object($data)) {
			$data = get_object_vars($data);
		}
		if (is_array($data)) {
			return array_map([$this, 'nxt_convert_object_to_array'], $data);
		}
		return $data;
	}

	/**
	 * @param $state
	 *
	 * @return bool|void
	 */
	public static function toggle_wp_includes_folder_visiblity($state){
		if (!function_exists('wp_filesystem')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		
		global $wp_filesystem;
		
		// Initialize the WordPress filesystem.
		if (WP_Filesystem()) {
			$file_path = ABSPATH . "wp-includes/index.php";
		
			if (!$wp_filesystem->is_writable($file_path)) {
				return false;
			}
		
			if ($state) {
				// Create or open the file for writing.
				$result = $wp_filesystem->put_contents($file_path, '', FS_CHMOD_FILE);
				if ($result) {
					return true;
				} else {
					return false;
				}
			} else {
				// Delete the file.
				$result = $wp_filesystem->delete($file_path);
				if ($result) {
					return true;
				} else {
					return false;
				}
			}
		} else {
			return false;
		}
	}

	public function add_x_frame_options_header() {
		$advanced_security_options = get_option( 'nexter_site_security' ,array());
		//IFrame Security
		if( isset($advanced_security_options['iframe_security']) && !empty($advanced_security_options['iframe_security']) ){
			switch ($advanced_security_options['iframe_security']) {
				case 'sameorigin' :
					if (!defined('DOING_CRON')){
						header('X-Frame-Options: sameorigin');
					}
					break;
				case 'deny':
					header("X-Frame-Options: deny");
					break;
				default :
					break;
			}
		}
	}

	public function add_security_header(){
		$advanced_security_options = get_option( 'nexter_site_security' ,array());
		
		if( in_array('disable_file_editor',$advanced_security_options) && !defined('DISALLOW_FILE_EDIT')){
			define( 'DISALLOW_FILE_EDIT', true );
		}
		
		//HTTP Secure Flag
		if (in_array('secure_cookies',$advanced_security_options)) {
			@ini_set('session.cookie_httponly', true);
			@ini_set('session.cookie_secure', true);
			@ini_set('session.use_only_cookies', true);
		}
		
	}

	public function remove_meta_generator(){
		//if(ini_set('output_buffering', 'on')){
			add_action('get_header', [$this,'clean_generated_header'], 50);
			add_action('wp_footer', function(){ ob_end_flush(); }, 100);
		//}
	}

	public function clean_generated_header($generated_html){
		ob_start('remove_meta_tags');
	}

	/* Function For Defer JS */
	public function nxt_onload_defer_js($html, $handle){
		$handles = array( 'nexter-frontend-js' );
		if ( in_array( $handle, $handles )) {
			$html = str_replace( '></script>', ' defer></script>', $html );
		}
		return $html;
	}

	/* Function For Defer CSS */
	public function nxt_onload_style_css( $html, $handle, $href, $media ){
		$handles = array( 'dashicons', 'wp-block-library' );
		if( in_array( $handle, $handles ) ){
			$html = '<link rel="preload" href="' . $href . '" as="style" id="' . $handle . '" media="' . $media . '" onload="this.onload=null;this.rel=\'stylesheet\'">'
			. '<noscript>' . $html . '</noscript>';
		}
		return $html;
	}

	/**
	 * Disable Embeds 
	 * @since 1.1.0
	 */

	public function nxt_disable_embeds(){
		global $wp;
		$wp->public_query_vars = array_diff($wp->public_query_vars, array('embed'));
		add_filter('embed_oembed_discover', '__return_false');
		remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
		remove_action('wp_head', 'wp_oembed_add_discovery_links');
		remove_action('wp_head', 'wp_oembed_add_host_js');
		add_filter('tiny_mce_plugins', function( $plugins ) {  
			return array_diff($plugins, array('wpembed'));
		});
		add_filter('rewrite_rules_array', function($rules) {
			foreach($rules as $rule => $rewrite) {
				if(false !== strpos($rewrite, 'embed=true')) {
					unset($rules[$rule]);
				}
			}
			return $rules;
		});
		remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
	}

	/**
	 * disable RSS Feed
	 * @since 1.1.0
	 */

	public function nxt_disable_rss_feeds() {
		if(!is_feed() || is_404()) {
			return;
		}
		
		global $wp_rewrite;
		global $wp_query;

		//check for GET feed variable
		if(isset($_GET['feed'])) {
			wp_redirect(esc_url_raw(remove_query_arg('feed')), 301);
			exit;
		}

		//unset/remove wp_query feed variable
		if(get_query_var('feed') !== 'old') {
			set_query_var('feed', '');
		}
			
		//Wp redirect to the proper URL
		redirect_canonical();

		// Translators: %s is the URL of the Home Page.
		wp_die(
			sprintf(
				esc_html__("No feed available, please visit the %s!", 'nexter-extension'),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url(home_url('/')),
					esc_html__("Home Page", 'nexter-extension')
				)
			)
		);
	}

	/**
	 * Disable pingbacks link
	 * @since 1.1.0
	 */

	public function nxt_disable_self_pingbacks( &$links ){
		$home = home_url();
		foreach($links as $l => $link) {
			if(strpos($link, $home) === 0) {
				unset($links[$l]);
			}
		}
	}

	/**
	 * Remove comments links from admin bar.
	 * @since 1.1.0
	 */
	
	public function nxt_filter_admin_bar(){
		if (is_admin_bar_showing()) {
			remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
			if (is_multisite()) {
				add_action('admin_bar_menu', [ $this , 'nxt_remove_network_comment_links'], 500);
			}
		}
	}

	/**
	 *  Remove Comment Links from the Multisite(Network) Admin Bar
	 * @since 1.1.0
	 */

	public function nxt_remove_network_comment_links($wp_admin_bar) {
		if(!function_exists('is_plugin_active_for_network')) {
			require_once(ABSPATH . '/wp-admin/includes/plugin.php');
		}
		if(is_plugin_active_for_network('nexter-extension/nexter-extension.php') && is_user_logged_in()) {
			//Remove for All
			foreach($wp_admin_bar->user->blogs as $blog) {
				$wp_admin_bar->remove_menu('blog-' . $blog->userblog_id . '-c');
			}
		} else {
			//Remove for Current
			$wp_admin_bar->remove_menu('blog-' . get_current_blog_id() . '-c');
		}
	}

	/**
	 * Disable Comments REST API Endpoint
	 * @since 1.1.0
	 */
	public function nxt_filter_rest_endpoints( $endpoints ){
		unset($endpoints['comments']);
		return $endpoints;
	}

	/**
	 * Disable Comments In Post Type
	 * @since 1.1.0
	 */
	public function nxt_wp_loaded_comments(){
		$extension_option = get_option( 'nexter_site_performance' );
		//All Post Types Remove Support Comments
		$all_post_types = [];
		if(!empty($extension_option['disable_comments']) && $extension_option['disable_comments'] === 'all'){
			$all_post_types = get_post_types( array('public' => true), 'names' );
		}else if(!empty($extension_option['disable_comments']) && $extension_option['disable_comments'] === 'custom'){
			$all_post_types = $this->nxt_get_disabled_post_types();
		}
		if(!empty($all_post_types)) {
			foreach($all_post_types as $post_type) {
				if(post_type_supports($post_type, 'comments')) {
					remove_post_type_support($post_type, 'comments');
					remove_post_type_support($post_type, 'trackbacks');
				}
			}
		}
	
		add_filter('comments_array', function($comments, $post_id) { 
			$extension_option = get_option( 'nexter_site_performance' );
			$post_type = get_post_type($post_id);
			return (!empty($extension_option) && ($extension_option['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled($post_type)) ? array() : $comments);
		}, 20, 2);
		add_filter('comments_open', function($open, $post_id) {
			$extension_option = get_option( 'nexter_site_performance' );
			$post_type = get_post_type($post_id);
			return ( !empty($extension_option) && ($extension_option['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled($post_type)) ? false : $open); 
		}, 20, 2);
		add_filter('pings_open', function($count, $post_id) {
			$extension_option = get_option( 'nexter_site_performance' );
			$post_type = get_post_type($post_id);
			return (!empty($extension_option) && ($extension_option['disable_comments'] === 'all' || $this->nxt_comment_post_type_disabled($post_type)) ? 0 : $count);
		}, 20, 2);
	
		if(is_admin()) {
			
			if(!empty($nxt_site_performance['disable_comments']) && $nxt_site_performance['disable_comments'] === 'all'){
			
				//Remove Menu Links And Disable Admin Pages 
				add_action('admin_menu', [ $this, 'nxt_admin_menu_comments'], 9999);
			
			
				//Hide Css Comments from Dashboard
				add_action('admin_print_styles-index.php', function(){
					echo "<style>#dashboard_right_now .comment-count, #dashboard_right_now .comment-mod-count, #latest-comments, #welcome-panel .welcome-comments {
							display: none !important;
						}
					</style>";
				});
	
				//Hide Css Comments from Profile
				add_action('admin_print_styles-profile.php', function(){
					echo "<style>.user-comment-shortcuts-wrap {
							display: none !important;
						}
					</style>";
				});
			
				//Recent Comments Meta
				add_action('wp_dashboard_setup', [ $this , 'nxt_recent_comments_dashboard']);
				
				//Pingback Flag
				add_filter('pre_option_default_pingback_flag', '__return_zero');
			}
		} else {
			
			add_action('template_redirect', [ $this ,'nxt_comment_template'] );
			
			if(!empty($nxt_site_performance['disable_comments']) && $nxt_site_performance['disable_comments'] === 'all'){
				//Disable the Comments Feed Link
				add_filter('feed_links_show_comments_feed', '__return_false');
			}
		}
	}

	/**
	 * Get Post Type disable Comment
	 * @since 1.1.0
	 */

	public function nxt_get_disabled_post_types(){
		$extension_option = get_option( 'nexter_site_performance' );
		$post_types = [];
		if(!empty($extension_option['disable_comments']) && $extension_option['disable_comments'] === 'custom'){
			if(isset($extension_option['disble_custom_post_comments']) && !empty($extension_option['disble_custom_post_comments'])){
				$post_types = $extension_option['disble_custom_post_comments'];
			}
		}
		return $post_types;
	}

	public function nxt_comment_post_type_disabled($post_type){
		return $post_type && in_array($post_type, $this->nxt_get_disabled_post_types() );
	}
	
	/**
	 * Admin Bar Menu Comments
	 * @since 1.1.0
	 */

	public function nxt_admin_menu_comments(){
		global $pagenow;

		//Remove Comment Menu Links
		remove_menu_page('edit-comments.php');

		//Disable Comments Pages
		if($pagenow == 'comment.php' || $pagenow == 'edit-comments.php') {
			wp_die(esc_html__('Comments are disabled.', 'nexter-extension'), '', array('response' => 403));
		}

		//Disable Discussion Page
		if($pagenow == 'options-discussion.php') {
			wp_die(esc_html__('Comments are disabled.', 'nexter-extension'), '', array('response' => 403));
		}
		//Remove Discussion Menu Links
		remove_submenu_page('options-general.php', 'options-discussion.php');
	}

	/**
	 * Remove Comment Meta Box
	 * @since 1.1.0
	 */

	public function nxt_recent_comments_dashboard(){
		remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
	}

	/**
	 * Remove X pingback
	 * @since 1.1.0
	 */

	public function nxt_remove_x_pingback($headers){
		unset($headers['X-Pingback'], $headers['x-pingback']);
   		return $headers;
	}

	public function nxt_xmlrpc_header() {
		if(!isset($_SERVER['SCRIPT_FILENAME'])) {
			return;
		}
		
		if('xmlrpc.php' !== basename($_SERVER['SCRIPT_FILENAME'])) {
			return;
		}
	
		$header = 'HTTP/1.1 Error 403 Forbidden';
		header($header);
		echo $header;
		die();
	}

	public function nxt_empty_comments_template($headers){
		return dirname(__FILE__) . '/comments.php';
	}

	public function nxt_comment_template(){
		$extension_option = get_option( 'nexter_site_performance' );
		if (is_singular() && (!empty($extension_option['disable_comments']) && ( $extension_option['disable_comments'] === 'all' || ($extension_option['disable_comments'] === 'custom' && $this->nxt_comment_post_type_disabled(get_post_type())) ) )) {
			if (!defined('DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE') || DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE == true) {
				//Replace Comments Template
				add_filter('comments_template', [ $this ,'nxt_empty_comments_template'], 20);
			}
			//Remove Script Comment Reply
			wp_deregister_script('comment-reply');
			
			// feed_links_extra inserts a comments RSS link.
			remove_action('wp_head', 'feed_links_extra', 3);
		}
	}
}

new Nexter_Ext_Performance_Security_Settings();


function remove_meta_tags($generated_html){
	$regex = '/<meta name(.*)=(.*)"generator"(.*)>/i';
	$generated_html = preg_replace($regex, '', $generated_html);
	return $generated_html;
}