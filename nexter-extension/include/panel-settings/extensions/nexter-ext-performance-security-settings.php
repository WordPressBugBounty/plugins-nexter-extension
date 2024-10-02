<?php 
/*
 * Disable Admin Settings Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

function nexter_ext_get_post_type_list(){
	$args = array(
		'public'   => true,
		'show_ui' => true
	);	 
	$post_types = get_post_types( $args, 'objects' );
	
	$options = array();
	foreach ( $post_types  as $post_type ) {
		
		$exclude = array( 'elementor_library' );
		if( TRUE === in_array( $post_type->name, $exclude ) ){
			continue;
		}
		
		$icon = NEXTER_EXT_URL.'assets/images/panel-icon/'.$post_type->name.'.svg';
		$headers=get_headers($icon);

		if($post_type->name != 'nxt_builder'){
			$options[$post_type->name] =  [ 
				'title' =>  $post_type->label,
				'icon' => isset($headers[0]) && stripos($headers[0],"200 OK") ? $icon : NEXTER_EXT_URL.'assets/images/panel-icon/cpt.svg',
			];
		}
	}
	return $options;
}

class Nexter_Ext_Performance_Security_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {

		// Advance Performance Ajax
		add_action( 'wp_ajax_nexter_ext_advance_performance', [ $this, 'nexter_ext_advance_performance_ajax'] );

		// Disble Comment Ajax
		add_action( 'wp_ajax_nexter_ext_disable_comments', [ $this, 'nexter_ext_disable_comments_ajax'] );
		
		// Advance Security Ajax
		add_action( 'wp_ajax_nexter_ext_advance_security', [ $this, 'nexter_ext_advance_security_ajax'] );
		
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

		if( !empty($extension_option) ){
			/*Disable Emojis Scripts*/
			if( in_array("disable_emoji_scripts",$extension_option) ){
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
				remove_action( 'wp_print_styles', 'print_emoji_styles' );
				remove_action( 'admin_print_styles', 'print_emoji_styles' );
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
						wp_die( esc_html__('Comments are disabled.', 'nexter-ext'), '', array('response' => 403));
					}
				}, 9);
				
				//Remove Comment Admin bar filtering
				add_action('template_redirect',  [ $this,'nxt_filter_admin_bar'] );
				add_action('admin_init', [ $this, 'nxt_filter_admin_bar']);
				
				add_filter('rest_endpoints', [ $this , 'nxt_filter_rest_endpoints']);
				
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
						return new WP_Error('rest_authentication_error', __('Sorry, do not have permission REST API requests.', 'nexter-ext'), array('status' => 401));
					}
					
					// on logged-in requests
					return $result;
					
				}, 20);
			}
		}
    }

	/**
	 * @param $state
	 *
	 * @return bool|void
	 */
	public static function toggle_wp_includes_folder_visiblity($state){
		if(is_writable(ABSPATH . "wp-includes/index.php")){
			return false;
		}
		if($state){
			$handle = fopen(ABSPATH . "wp-includes/index.php", "w");
			if($handle){
				fclose($handle);
			}
			else return false;
		} else {
			$res = unlink(ABSPATH . "wp-includes/index.php");
			if($res)
				return true;
			else
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

    /*
	 * Nexter Disable Admin Settings
	 * @since 1.1.0
	 */
	public function nexter_ext_advance_performance_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}

		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$extension_option = get_option( 'nexter_site_performance' );
		if( !empty( $ext ) && $ext == 'advance-performance' ){
		
			$config_ext = [];
			if( has_filter('nexter-extension-performance-option-config') ){
				$config_data = apply_filters('nexter-extension-performance-option-config' , $config_ext);
				if( !empty( $config_data ) && isset($config_data[$ext]) ){
					$config_ext = $config_data[$ext];
				}
			}
			
			$output = '';
			$output .= '<div class="nxt-ext-modal-content">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['title']) : '').'</div>';
					//$output .= '<div class="nxt-modal-desc">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['description']) : '').'</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-disable-admin-wrap">';

					$icon_url = NEXTER_EXT_URL.'assets/images/panel-icon/';
					$performance = [
						'disable_emoji_scripts' => [
							'title' => esc_html__( 'Disable Emojis Script', 'nexter-ext' ),
							'desc' => esc_html__( "This helps you reduce extra HTTP requests for script 'wp-includes/js/wp-emoji-release.min.js'", 'nexter-ext' ),
							'icon' => $icon_url.'disable-emojis-script.svg',
						],
						'disable_embeds' => [
							'title' => esc_html__( 'Disable Embeds', 'nexter-ext' ),
							'desc' => esc_html__( "This helps you reduce extra HTTP requests for script 'wp-embed.min.js', which is not required in most cases. As this, JS generates links previews.", 'nexter-ext' ),
							'icon' => $icon_url.'disable-embeds.svg',
						],
						'disable_dashicons' => [
							'title' => esc_html__( 'Disable Dashicons', 'nexter-ext' ),
							'desc' => esc_html__( "This helps you reduce extra HTTP request for script 'dashicons.min.css' in frontend, which helps in fixing render blocking.", 'nexter-ext' ),
							'icon' => $icon_url.'disable-dashicons.svg',
						],
						'disable_rsd_link' => [
							'title' => esc_html__( 'Remove RSD Link', 'nexter-ext' ),
							'desc' => esc_html__( "Helps you clean unnecessary codes from the WordPress header, RSD links aren't needed unless you publish your blog from other apps.", 'nexter-ext' ),
							'icon' => $icon_url.'remove-rsd-link.svg',
						],
						'disable_wlwmanifest_link' => [
							'title' => esc_html__( 'Remove wlwmanifest Link', 'nexter-ext' ),
							'desc' => esc_html__( "Helps you clean unnecessary codes from the WordPress header, if you don't use Windows Live Writer, you can remove such links.", 'nexter-ext' ),
							'icon' => $icon_url.'remove-wlw-link.svg',
						],
						'disable_shortlink' => [
							'title' => esc_html__( 'Remove Shortlink', 'nexter-ext' ),
							'desc' => esc_html__( 'This helps you remove unnecessary short link tags from the post/page URL.', 'nexter-ext' ),
							'icon' => $icon_url.'remove-shortlink.svg',
						],
						'disable_rss_feeds' => [
							'title' => esc_html__( 'Disable RSS Feeds', 'nexter-ext' ),
							'desc' => esc_html__( 'RSS feeds are generated by WordPress by default to share your content updates with everyone. But not everyone uses WordPress for blogging or sharing content updates via RSS feeds. So, you can disable the RSS feeds to get rid of those extra codes.', 'nexter-ext' ),
							'icon' => $icon_url.'disable-rss-feeds.svg',
						],
						'disable_rss_feed_link' => [
							'title' => esc_html__( 'Remove RSS Feed Links', 'nexter-ext' ),
							'desc' => esc_html__( "If you don't use your WordPress for blogging then you can remove the WordPress RSS feeds to get rid of those extra codes.", 'nexter-ext' ),
							'icon' => $icon_url.'remove-rss-feed-links.svg',
						],
						'disable_self_pingbacks' => [
							'title' => esc_html__( 'Disable Self Pingbacks', 'nexter-ext' ),
							'desc' => esc_html__( 'Declutter your comment section by avoiding ping to the articles from your same site.', 'nexter-ext' ),
							'icon' => $icon_url.'disable-self-pingbacks.svg',
						],
						'disable_pw_strength_meter' => [
							'title' => esc_html__( 'Disable Password Strength Meter', 'nexter-ext' ),
							'desc' => esc_html__( "WordPress adds force strong password scripts to make passwords tough to guess. Loading them on all the pages is unnecessary extra code, that's why using this feature you can load the scripts only where its required i.e. login, checkouts, account page etc", 'nexter-ext' ),
							'icon' => $icon_url.'disable-pw-strength.svg',
						],
						'defer_css_js' => [
							'title' => esc_html__( 'Defer CSS & JS', 'nexter-ext' ),
							'desc' => esc_html__( "If you enable this option, Your JS and CSS will be loaded with Defer Attribute. It will make HTML render of page faster and help getting better scores in Google Page Speed", 'nexter-ext' ),
							'icon' => $icon_url.'defer-css-js.svg',
						],
					];
					foreach($performance as $option => $data){
						$output .= '<div class="nxt-option-switcher">';
							$output .= '<span class="nxt-extra-icon"><img src="'.esc_url($data['icon']).'" alt="'.esc_attr($option).'" /></span>';
							$output .= '<span class="nxt-option-check-title">'.wp_kses_post($data['title']).'</span>';
							$output .= '<span class="nxt-desc-icon">';
								$output .= '<img src="'.esc_url( $icon_url.'desc-icon.svg').'" alt="'.esc_attr__('description','nexter-ext').'" /> ';
								$output .= '<div class="nxt-tooltip '.( ('disable_emoji_scripts' == $option || 'disable_embeds' == $option) ? 'bottom' : '' ).'">'.wp_kses_post($data['desc']).'</div>';
							$output .= '</span>';
							$output .= '<span class="nxt-option-checkbox-label">';
								$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($option).'" name="nxt-advance-performance[]" value="'.esc_attr($option).'" '.(!empty($extension_option) && in_array($option,$extension_option) ? "checked" : "" ).'/>';
								$output .= '<label for="'.esc_attr($option).'"></label>';
							$output .= '</span>';	
						$output .= '</div>';
					}
				$output .= '</div>';
				$output .= '<button type="button" class="nxt-save-advance-performance"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';
			
			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
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

		//redirect failed url, show error message
		wp_die(sprintf(__("No feed available, please visit the <a href='%s'>Home Page</a>!",'nexter-ext'), esc_url(home_url('/'))));
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
	 * Comment Setting Pop up
	 * @since 1.1.0
	 */

	public function nexter_ext_disable_comments_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$extension_option = get_option( 'nexter_site_performance' );
		if( !empty( $ext ) && $ext == 'disable-comments' ){
		
			$config_ext = [];
			if( has_filter('nexter-extension-performance-option-config') ){
				$config_data = apply_filters('nexter-extension-performance-option-config' , $config_ext);
				if( !empty( $config_data ) && isset($config_data[$ext]) ){
					$config_ext = $config_data[$ext];
				}
			}
			
			$commentOpt = [
				'' => esc_html__('Enabled' , 'nexter-ext'),
				'all' => esc_html__('Disable Everywhere' , 'nexter-ext'),
				'custom' => esc_html__('Disable Specific Post Type' , 'nexter-ext'),
			];

			$output = '';
			$output .= '<div class="nxt-ext-modal-content">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['title']) : '').'</div>';
					//$output .= '<div class="nxt-modal-desc">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['description']) : '').'</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-comment-wrap">';
					$output .= '<div class="nxt-comment-inner">';
						$output .= '<label class="upload-font-label">'.esc_html__('Comments' , 'nexter-ext').'</label>';
						$output .= '<select class="nxt-select-opt nxt-disable-comment nxt-mt-8">';
							foreach($commentOpt as $key => $val){
								$output .= '<option '.(!empty($extension_option) && isset($extension_option['disable_comments']) && $extension_option['disable_comments'] == $key ? 'selected' : '' ) .' value="'.esc_attr($key).'" >'.$val.'</option>';
							}
						$output .= '</select>';
						
						if( function_exists(('nexter_ext_get_post_type_list')) ){
							$posttype = nexter_ext_get_post_type_list();
						}
						
						
						$output .= '<div class="nxt-comment-switcher '.(isset($extension_option['disable_comments']) && !empty($extension_option['disable_comments']) && $extension_option['disable_comments'] == 'custom' ? ' nxt-slide-down' : '').'">';
							foreach($posttype as $option => $data){
								$output .= '<div class="nxt-option-switcher">';
									$output .= '<span class="nxt-extra-icon"><img src="'.esc_url($data['icon']).'" alt="'.esc_attr($option).'" /></span>';
									$output .= '<span class="nxt-option-check-title">'.wp_kses_post($data['title']).'</span>';
									$output .= '<span class="nxt-option-checkbox-label">';
										$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($option).'" name="nxt-disable-comment[]" value="'.esc_attr($option).'" '.(!empty($extension_option) && isset($extension_option['disble_custom_post_comments']) && in_array($option,$extension_option['disble_custom_post_comments']) ? "checked" : "" ).'/>';
										$output .= '<label for="'.esc_attr($option).'"></label>';
									$output .= '</span>';	
								$output .= '</div>';
							}
						$output .= '</div>';
						
					$output .= '</div>';
				$output .= '</div>';
				$output .= '<button type="button" class="nxt-save-comment"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
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
			wp_die(esc_html__('Comments are disabled.', 'nexter-ext'), '', array('response' => 403));
		}

		//Disable Discussion Page
		if($pagenow == 'options-discussion.php') {
			wp_die(esc_html__('Comments are disabled.', 'nexter-ext'), '', array('response' => 403));
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
	 * Advance Security Pop up
	 * @since 1.1.0
	 */

	public function nexter_ext_advance_security_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$extension_option = get_option( 'nexter_site_security' );
		if( !empty( $ext ) && $ext == 'advance-security' ){

			$secu_ext = [];
			if( has_filter('nexter-extension-security-option-config') ){
				$sec_data = apply_filters('nexter-extension-security-option-config' , $secu_ext);
				if( !empty( $sec_data ) && isset($sec_data[$ext]) ){
					$secu_ext = $sec_data[$ext];
				}
			}

			$output = '';
			$output .= '<div class="nxt-ext-modal-content nxt-advance-security-wrap">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.(isset($secu_ext['title']) ? wp_kses_post($secu_ext['title']) : '').'</div>';
					//$output .= '<div class="nxt-modal-desc">'.(isset($secu_ext['title']) ? wp_kses_post($secu_ext['description']) : '').'</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-disable-admin-wrap">';

				$icon_url = NEXTER_EXT_URL.'assets/images/panel-icon/';
					$security = [
						'disable_xml_rpc' => [
							'title' => esc_html__('Disable XML-RPC', 'nexter-ext'),
							'desc' => esc_html__("For security reasons, it's better to disable XML-RPC on your site, unless you publish content from WordPress mobile app or use Jetpack plugins.", 'nexter-ext'),
							'icon' => $icon_url.'disable-xml.svg',
						],
						'disable_wp_version' => [
							'title' => esc_html__('Hide WordPress Version', 'nexter-ext'),
							'desc' => esc_html__("For better security, it's always safe to remove the WordPress version from your site header. This helps in hiding the WordPress version.", 'nexter-ext'),
							'icon' => $icon_url.'disable-wp-version.svg',
						],
						'disable_rest_api' => [
							'title' => esc_html__('REST API', 'nexter-ext'),
							'desc' => esc_html__("For better security, it's always safe to disable Rest API when not needed. Some plugins like Gutenberg and Yoast SEO do require this to work properly. Hence, choosing Disable for Non-Admins will be the best option.", 'nexter-ext'),
							'icon' => $icon_url.'disable-rest-api.svg',
						],
						'disable_rest_api_links' => [
							'title' => esc_html__('Remove REST API Links', 'nexter-ext'),
							'desc' => esc_html__('This helps you remove unnecessary REST API tags from endpoints.', 'nexter-ext'),
							'icon' => $icon_url.'remove-rest-api-link.svg',
						],
						'disable_file_editor' => [
							'title' => esc_html__('Disable File Editor', 'nexter-ext'),
							'desc' => esc_html__("Prevents unauthorized modifications to your website's theme and plugin files by disabling the file editor within the WordPress dashboard.", 'nexter-ext'),
							'icon' => $icon_url.'disable-file-editor.svg',
						],
						/* 'disable_wordpress_application_password' => [
							'title' => esc_html__('Disable WordPress Application Password', 'nexter-ext'),
							'desc' => esc_html__("Enhances security by disabling the creation of application-specific passwords in WordPress, reducing the risk of misuse.", 'nexter-ext'),
							'icon' => $icon_url.'disable-wp-application-password.svg',
						], */
						/* 'redirect_user_enumeration' => [
							'title' => esc_html__('Redirect User ID Enumeration', 'nexter-ext'),
							'desc' => esc_html__("Safeguards against user enumeration attacks by automatically redirecting users to a secure page or custom URL when attempting to access your website using specific user IDs.", 'nexter-ext'),
							'icon' => $icon_url.'redirect-userid-emuneration.svg',
						], */
						'remove_meta_generator' => [
							'title' => esc_html__('Remove Meta Generator', 'nexter-ext'),
							'desc' => esc_html__("Eliminates the meta generator tag from your website's HTML source code, thwarting potential attackers from identifying your WordPress version.", 'nexter-ext'),
							'icon' => $icon_url.'remove-meta-generator.svg',
						],
						/* 'remove_css_version' => [
							'title' => esc_html__('Remove CSS Version', 'nexter-ext'),
							'desc' => esc_html__("Boosts security by removing version numbers from CSS files, reducing the likelihood of exploiting known vulnerabilities associated with specific CSS versions.", 'nexter-ext'),
							'icon' => $icon_url.'remove-css-version.svg',
						], */
						/* 'remove_js_version' => [
							'title' => esc_html__('Remove JS Version', 'nexter-ext'),
							'desc' => esc_html__("Similar to the CSS version removal, this feature eliminates version numbers from JavaScript (JS) files, mitigating the risk of attacks targeting known JS vulnerabilities.", 'nexter-ext'),
							'icon' => $icon_url.'remove-js-version.svg',
						], */
						/* 'hide_wp_include_folder' => [
							'title' => esc_html__('Hide WordPress Include Folder', 'nexter-ext'),
							'desc' => esc_html__("Safeguards your website's internal structure by hiding the WordPress include folder from public access, minimizing potential vulnerabilities.", 'nexter-ext'),
							'icon' => $icon_url.'hide-wp-include-folder.svg',
						], */
						'xss_protection' => [
							'title' => esc_html__('XSS Protection', 'nexter-ext'),
							'desc' => esc_html__("Implements proactive measures against cross-site scripting (XSS) attacks, ensuring the integrity and security of your website's content.", 'nexter-ext'),
							'icon' => $icon_url.'xss-protection.svg',
						],
						'secure_cookies' =>[
							'title' => esc_html__('Secure Cookies', 'nexter-ext'),
							'desc' => esc_html__("Strengthens security by enabling secure cookie settings, protecting user authentication data from unauthorized access or tampering.", 'nexter-ext'),
							'icon' => $icon_url.'secure-cookies.svg',
						],
						'iframe_security' => [
							'title' => esc_html__('iFrame Security', 'nexter-ext'),
							'desc' => esc_html__("Enhances your website's security by implementing measures to control or restrict the usage of iFrames, mitigating the risk of click jacking or malicious content injection.", 'nexter-ext'),
							'icon' => $icon_url.'iframe-security.svg',
						],
					];

					$iframeOpt = [
						'disabled' => esc_html__('Disabled' , 'nexter-ext'),
						'sameorigin' => esc_html__('Same Origin' , 'nexter-ext'),
						'deny' => esc_html__('Deny' , 'nexter-ext'),
					];

					$enableOpt = [
						'' => esc_html__('Enabled' , 'nexter-ext'),
						'non_admin' => esc_html__('Disable for Non-Admin' , 'nexter-ext'),
						'logged_out' => esc_html__('Disable When Logged Out' , 'nexter-ext'),
					];
					foreach($security as $option => $data){
						$output .= '<div class="nxt-option-switcher">';
							$output .= '<span class="nxt-extra-icon"><img src="'.esc_url($data['icon']).'" alt="'.esc_attr($option).'" /></span>';
							$output .= '<span class="nxt-option-check-title">'.wp_kses_post($data['title']).'</span>';
							$output .= '<span class="nxt-desc-icon">';
								$output .= '<img src="'.esc_url( $icon_url.'desc-icon.svg').'" alt="'.esc_attr__('description','nexter-ext').'" /> ';
								$output .= '<div class="nxt-tooltip '.( ($option == 'disable_rest_api' || $option == 'hide_wp_include_folder' || $option == 'xss_protection' || $option == 'secure_cookies' || $option == 'iframe_security') ? 'top' : 'bottom').'">'.wp_kses_post($data['desc']).'</div>';
							$output .= '</span>';
							if( $option != 'disable_rest_api' && $option!= 'iframe_security' ){
								$output .= '<span class="nxt-option-checkbox-label">';
									$output .= '<input type="checkbox" class="cmb2-option cmb2-list" id="'.esc_attr($option).'" name="nxt-advance-security[]" value="'.esc_attr($option).'" '.( isset($extension_option) && !empty($extension_option) && in_array($option,$extension_option,true) ? "checked" : "" ).'/>';
									$output .= '<label for="'.esc_attr($option).'"></label>';
								$output .= '</span>';
							}else{
								if($option == 'iframe_security'){
									$output .=  '<select class="nxt-select-opt nxt-iframe-security">';
									foreach($iframeOpt as $key => $val){
										$output .= '<option '.(!empty($extension_option) && isset($extension_option['iframe_security']) && $extension_option['iframe_security'] == $key ? 'selected' : '' ) .' value="'.esc_attr($key).'" >'.$val.'</option>';
									}
									$output .= '</select>';
								}
								if($option == 'disable_rest_api'){
									$output .=  '<select class="nxt-select-opt nxt-disable-api">';
										foreach($enableOpt as $key => $val){
											$output .= '<option '.(!empty($extension_option) && isset($extension_option['disable_rest_api']) && $extension_option['disable_rest_api'] == $key ? 'selected' : '' ) .' value="'.esc_attr($key).'" >'.$val.'</option>';
										}
									$output .= '</select>';
								}
							}
						$output .= '</div>';
					}
				$output .= '</div>';
				$output .= '<button type="button" class="nxt-save-advance-security"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" xmlns:v="https://vecta.io/nano"><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';
			
			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
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