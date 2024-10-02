<?php
/**
 * Nexter Builder Display Conditional Rules
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */
if ( ! class_exists( 'Nexter_Builder_Display_Conditional_Rules' ) ) {

	class Nexter_Builder_Display_Conditional_Rules {


		/**
		 * Instance
		 */
		private static $instance;
		
		/**
		 * Location Rules Options
		 */
		private static $location_rules_options;
		
		/**
		 * Current page type
		 */
		private static $current_page_type_name = null;
		
		/**
		 * Current page type singular
		 */
		private static $current_page_type_name_singular = null;
		
		/**
		 * Current page type archive
		 */
		private static $current_page_type_name_archive = null;

		/**
		 * Current page data
		 */
		private static $current_load_page_data = [];

		/**
		 * Current singular page data
		 */
		private static $current_singular_data = [];

		/**
		 * Current archive page data
		 */
		private static $current_archive_data = [];
		
		/**
		 * Operating System List
		 */
		public static $os_list = [
			'iphone'            => '(iPhone)',
			'windows' 			=> 'Win16|(Windows 95)|(Win95)|(Windows_95)|(Windows 98)|(Win98)|(Windows NT 5.0)|(Windows 2000)|(Windows NT 5.1)|(Windows XP)|(Windows NT 5.2)|(Windows NT 6.0)|(Windows Vista)|(Windows NT 6.1)|(Windows 7)|(Windows NT 4.0)|(WinNT4.0)|(WinNT)|(Windows NT)|Windows ME',
			'open_bsd'          => 'OpenBSD',
			'sun_os'            => 'SunOS',
			'linux'             => '(Linux)|(X11)',
			'safari'            => '(Safari)',
			'mac_os'            => '(Mac_PowerPC)|(Macintosh)',
			'qnx'               => 'QNX',
			'beos'              => 'BeOS',
			'os2'              	=> 'OS/2',
			'search_bot'        => '(nuhk)|(Googlebot)|(Yammybot)|(Openbot)|(Slurp/cat)|(msnbot)|(ia_archiver)',
		];

		/*
		 * Browser List
		 */
		public static $browsers = [
			'ie'		=> [ 'MSIE', 'Trident' ],
			'firefox'	=> 'Firefox',
			'chrome'	=> 'Chrome',
			'opera_mini' => 'Opera Mini',
			'opera'		=> 'Opera',
			'safari'	=> 'Safari',
		];

		/**
		 * Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 */
		public function __construct() {
			add_action( 'admin_action_edit', array( $this, 'init_options' ) );
			add_action( 'wp_ajax_nexter_get_particular_posts_query', [ $this, 'nexter_get_particular_posts_query' ] );
			add_action( 'save_post', [ $this, 'nxt_build_save_post' ] );
		}

		public function nxt_build_save_post( $post_id, $post = false ) {

			$post_type = $post ? $post->post_type : get_post_type( $post_id );
	
			if ( ( NXT_BUILD_POST !== $post_type || ! current_user_can( 'edit_post', $post_id ) ) ) {
				return;
			}

			$option = 'nxt-build-get-data';

			$get_data = get_option($option);
			if( $get_data === false ){
				$value = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
				add_option( $option, $value, false );
			}else if(!empty($get_data)){
				$get_data['saved'] = strtotime('now');
				update_option( $option, $get_data, false );
			}
		}


		/**
		 * Initialize Options
		 */
		public function init_options() {
			self::$location_rules_options = self::get_location_rules_options();
		}
		
		/**
		 * Get location selection options.
		 *
		 * @since 1.0.0
		 */
		public static function get_location_rules_options() {

			$options = array(
				'standard'	=> array(
					'label' => __( 'Standard', 'nexter-ext' ),
					'value' => array(
						'standard-universal'	=> __( 'Entire Website', 'nexter-ext' ),
						'standard-singulars'	=> __( 'All Singulars', 'nexter-ext' ),
						'standard-archives'		=> __( 'All Archives', 'nexter-ext' ),
					),
				),
			);

			$default_pages = [
				'default-front'  => __( 'Front Page', 'nexter-ext' ),
				'default-blog'   => __( 'Blog / Posts Page', 'nexter-ext' ),
				'default-date'   => __( 'Date Archive', 'nexter-ext' ),
				'default-author' => __( 'Author Archive', 'nexter-ext' ),
				'default-search' => __( 'Search Page', 'nexter-ext' ),
				'default-404'    => __( '404 Page', 'nexter-ext' ),
			];
	
			if ( class_exists( 'WooCommerce' ) ) {
				$default_pages['default-woo-shop'] = __( 'WooCommerce Shop Page', 'nexter-ext' );
			}
			
			//Spacial Pages, Date/Time, Visitors Source Options
			$advaced_location = [
				'default-pages' => [
					'label' => __( 'Default Pages', 'nexter-ext' ),
					'value' => $default_pages,
				],
				'date-and-time' => [
					'label' => __( 'Date & Time', 'nexter-ext' ),
					'value' => [
						'set-day'	=> __( 'Day of Week', 'nexter-ext' ),
					],
				],
				'visitors-source' => [
					'label' => __( 'Visitors Source', 'nexter-ext' ),
					'value' => [
						'os'		=> __( 'Operating System', 'nexter-ext' ),
						'browser'	=> __( 'Browser', 'nexter-ext' ),
						'login-status'	=> __( 'Login Status', 'nexter-ext' ),
						'user-roles'	=> __( 'User Roles', 'nexter-ext' ),
					],
				],
			];
			
			//Post Types Options
			$get_post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
			unset( $get_post_types[ NXT_BUILD_POST ] );
			
			$taxonomy_lists = get_taxonomies( array( 'public' => true ), 'objects' );
			
			$post_list_options = [];
			if ( !empty( $taxonomy_lists ) ) {
				foreach ( $taxonomy_lists as $taxonomy ) {

					if ( $taxonomy->name == 'post_format' ) {
						continue;
					}

					foreach ( $get_post_types as $post_type ) {

						$post_options = self::get_post_type_rule_options( $post_type, $taxonomy );

						if ( isset( $post_list_options[ $post_options['key'] ] )) {						
							if ( ! empty( $post_options['value'] ) && is_array( $post_options['value'] )) {
								foreach ( $post_options['value'] as $key => $value ) {
									if ( ! in_array( $value, $post_list_options[ $post_options['key'] ]['value'] ) ) {
										$post_list_options[ $post_options['key'] ]['value'][ $key ] = $value;
									}
								}
							}
						} else {						
							$post_list_options[ $post_options['key'] ] = array(
								'label' => $post_options['label'],
								'value' => $post_options['value'],
							);
						}
						
					}
				}
			}

			$specific_post = array(
				'particular-post' => array(
					'label' => __( 'Particular Posts/Pages/Taxonomies', 'nexter-ext' ),
					'value' => array(
						'particular-post' => __( 'Particular Posts / Pages / Taxonomies, etc.', 'nexter-ext' ),
					),
				),
			);

			$options = array_merge( $options, $advaced_location, $post_list_options, $specific_post );

			return  apply_filters( 'nexter_location_rules_list', $options );
		}

		/**
		 * Get Generate Post Types Of Rules Options
		 */
		public static function get_post_type_rule_options( $post_type, $taxonomy ) {
			
			$post_name   = $post_type->name;
			$post_key    = str_replace( ' ', '-', strtolower( $post_type->label ) );
			$post_label  = ucwords( $post_type->label );
			
			$options = array();

			/* translators: %s: Post Label*/
			$options[ $post_name . '|entire' ]	= sprintf( __( 'All %s', 'nexter-ext' ), $post_label );

			if ( $post_key != 'pages' ) {
				/* translators: %s: Archive Post Label */
				$options[ $post_name . '|entire|archive' ] = sprintf( __( 'All %s Archive', 'nexter-ext' ), $post_label );
			}
			
			if ( in_array( $post_type->name, $taxonomy->object_type ) ) {
				$taxo_name  = $taxonomy->name;
				$taxo_label = ucwords( $taxonomy->label );
				/* translators: %s: Taxonomy Label */
				$options[ $post_name . '|entire|tax-archive|' . $taxo_name ] = sprintf( __( 'All %s Archive', 'nexter-ext' ), $taxo_label );
			}

			$post_output['key'] = $post_key;
			$post_output['label'] = $post_label;
			$post_output['value'] = $options;

			return $post_output;
		}

		/**
		 * Display Label Location by section.
		 *
		 * @since 1.0.0
		 */
		public static function display_label_location_by_key( $section ) {
			if ( ! isset( self::$location_rules_options ) || empty( self::$location_rules_options ) ) {
				self::$location_rules_options = self::get_location_rules_options();
			}
			
			foreach ( self::$location_rules_options as $group ) {
				if ( isset( $group['value'][ $section ] ) ) {
					return $group['value'][ $section ];
				}
			}
			
			// Display Post title By section location 
			if ( strpos( $section, 'post-' ) !== false ) {
				$post_id = (int) str_replace( 'post-', '', $section );
				return get_the_title( $post_id );
			}

			// Display Taxonomy Name By section location
			if ( strpos( $section, 'taxonomy-' ) !== false ) {
				$tax_id = (int) str_replace( 'taxonomy-', '', $section );
				$term   = get_term( $tax_id );

				if ( ! is_wp_error( $term ) ) {
					$term_taxonomy = ucfirst( str_replace( '_', ' ', $term->taxonomy ) );
					return $term->name . ' - ' . $term_taxonomy;
				} else {
					return '';
				}
			}

			return $section;
		}

		/**
		 * Checks for Current Page By Display Condition Rules
		 *
		 * @since 1.0.0
		 */
		public function check_layout_display_inc_exc_rules( $post_id, $conditions ) {

			$current_post_type = get_post_type( $post_id );
			$display           = false;

			if ( isset( $conditions ) && is_array( $conditions ) && ! empty( $conditions ) ) {
			
				foreach ( $conditions as $key => $condition ) {

					if ( !is_array($condition) && strrpos( $condition, 'entire' ) !== false ) {
						$check_cond = 'entire';
					} else {
						$check_cond = $condition;
					}

					if( !empty($check_cond) ){
						if( $check_cond == 'standard-universal' ){
							$display = true;
						}else if( $check_cond == 'entire' ){
						
							$condition_data = explode( '|', $condition );

							$post_type     = isset( $condition_data[0] ) ? $condition_data[0] : false;
							$archive  = isset( $condition_data[2] ) ? $condition_data[2] : false;
							$taxonomy      = isset( $condition_data[3] ) ? $condition_data[3] : false;
							
							if ( $archive  === false ) {
								$current_post_type = get_post_type( $post_id );

								if ( $post_id !== false && $current_post_type == $post_type ) {
									$display = true;
								}
							} else {

								if ( is_archive() ) {
									$current_post_type = get_post_type();
									if ( $current_post_type == $post_type ) {
										if ( $archive  == 'archive' ) {
											$display = true;
										} else if ( $archive  == 'tax-archive' ) {

											$object	= get_queried_object();
											$object_taxonomy = '';
											if ( $object !== '' && $object !== null) {
												$object_taxonomy = $object->taxonomy;
											}

											if ( $object_taxonomy == $taxonomy ) {
												$display = true;
											}
										}
									}
								}
							}
						}else if(!empty($check_cond) && !empty($conditions)){
							if( $check_cond == 'standard-singulars' && is_singular() ) {
								$display = true;
							}else if( $check_cond == 'standard-archives' && is_archive() ) {
								$display = true;
							}else if($check_cond == 'default-front' && is_front_page()) {
								$display = true;
							}else if($check_cond == 'default-blog' && is_home()) {
								$display = true;
							}else if($check_cond == 'default-date' && is_date()) {
								$display = true;
							}else if($check_cond == 'default-author' && is_author()) {
								$display = true;
							}else if($check_cond == 'default-search' && is_search()) {
								$display = true;
							}else if( $check_cond == 'default-404' && is_404() ) {
								$display = true;
							}else if( $check_cond == 'default-woo-shop' ) {
								if ( function_exists( 'is_shop' ) && is_shop() ) {
									$display = true;
								}
							}else if($check_cond == 'particular-post' && isset( $conditions['specific'] ) && is_array( $conditions['specific'] )) {
								foreach ( $conditions['specific'] as $specific_page ) {

									$specific_data = explode( '-', $specific_page );

									$specific_post_type = isset( $specific_data[0] ) ? $specific_data[0] : false;
									$specific_post_id   = isset( $specific_data[1] ) ? $specific_data[1] : false;
									if( $specific_post_type == 'post') {
										if( $specific_post_id == $post_id ) {
											$display = true;
										}
									}else if( isset( $specific_data[2] ) && ( $specific_data[2] == 'singular' ) && $specific_post_type == 'taxonomy' ) {
				
										if( is_singular() ) {
											$terms = get_term( $specific_post_id );
				
											if( isset( $terms->taxonomy ) ) {
												if( has_term( (int) $specific_post_id, $terms->taxonomy, $post_id ) ) {
													$display = true;
												}
											}
										}
									}else if( $specific_post_type == 'taxonomy' ) {
										if( $specific_post_id == get_queried_object_id() ) {
											$display = true;
										}
									}
								}
							}else if($check_cond == 'set-day' && isset( $conditions['set-day'] ) && is_array( $conditions['set-day'] ) ) {
								$display = self::check_condition_set_day( $conditions['set-day'], $display );
							}else if($check_cond == 'os' && isset( $conditions['os'] ) && is_array( $conditions['os'] ) ) {
								$display = self::check_condition_os($conditions['os'], $display);
							}else if($check_cond == 'browser' && isset( $conditions['browser'] ) && is_array( $conditions['browser'] ) ) {
								$display = self::check_condition_browser($conditions['browser'], $display);
							}else if($check_cond == 'login-status' && isset( $conditions['login-status'] ) && is_array( $conditions['login-status'] ) ) {
								$display = self::check_condition_login_status($conditions['login-status'], $display);
							}else if($check_cond == 'user-roles' && isset( $conditions['user-roles'] ) && is_array( $conditions['user-roles'] ) ) {
								$display = self::check_condition_user_roles($conditions['user-roles'], $display);
							}
						}
					}
					
					if ( $display ) {
						break;
					}
				}
			}

			return $display;
		}

		/* Check Condition Set Day */
		public static function check_condition_set_day($set_day = [], $display = false){
			if( !empty($set_day) ){
				$display = false;
				foreach ( $set_day as $value ) {
					if ( $value === date( 'w' ) ) {
						$display = true;
						break;
					}
				}
			}
			return $display;
		}

		/* Check Condition OS */
		public static function check_condition_os( $set_os = [], $display = false ) {
			if( !empty($set_os) ){
				$display = false;
				$user_agent = (isset($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT'] : '';	// phpcs:ignore 
				foreach ( $set_os as $value ) {
					$display_os = preg_match('@' . self::$os_list[ $value ] . '@', $user_agent );
					if($display_os){
						$display = true;
						break;
					}
				}
			}
			return $display;
		}

		/* Check Condition Browser */
		public static function check_condition_browser( $set_browser = [], $display = false ) {
		
			if( !empty($set_browser) ){
				$display = false;
				$user_agent = (isset($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT'] : '';	// phpcs:ignore 
				foreach ( $set_browser as $value ) {
					if ( $value === 'ie' ) {
						if ( strpos( $user_agent, self::$browsers[ $value ][0] ) !== false || strpos( $user_agent, self::$browsers[ $value ][1] ) !== false ) {
							$display = true;
							break;
						}
					} else {
						if ( strpos( $user_agent, self::$browsers[ $value ] ) !== false ) {
							$display = true;
							// Additional check for Chrome that returns Safari
							if ( $value === 'safari' || $value === 'firefox' ) {
								if ( strpos( $user_agent, 'Chrome' ) !== false ) {
									$display = false;
								}
							}
							break;
						}
					}
				}
			}
			
			return $display;
		}

		/* Check Condition Login Status */
		public static function check_condition_login_status( $set_login = [], $display = false ){
			if ( is_array( $set_login ) && ! empty( $set_login ) ) {
				$display = false;
				foreach ( $set_login as $key => $check_login ) {
				
					if(empty($check_login) || $check_login=='all' ){
						$display = true;
					}else if($check_login == 'logged-in' && is_user_logged_in() ){
						$display = true;
					}else if($check_login == 'logged-out' && ! is_user_logged_in() ){
						$display = true;
					}
					
					if ( $display ) {
						break;
					}
				}
			}
			
			return $display;
		}
		
		/* Check Condition User Roles*/
		public static function check_condition_user_roles($set_user_roles = [], $display = false ){
			if ( is_array( $set_user_roles ) && ! empty( $set_user_roles ) ) {
				$display = false;
				foreach ( $set_user_roles as $key => $check_role ) {
					if ( is_user_logged_in() ) {
						
						$get_current_user = wp_get_current_user();
	
						if ( isset( $get_current_user->roles ) && is_array( $get_current_user->roles ) && in_array( $check_role, $get_current_user->roles ) ) {
							$display = true;
						}
					}
					if ( $display ) {
						break;
					}
				}
			}
			return $display;
		}

		/*
		 * Sections Hooks List Options
		 */
		public static function get_sections_hooks_options() {
			if(defined('HELLO_ELEMENTOR_VERSION')){
				$hooks = array(
					'wp_head'				=> __( 'Wp Head', 'nexter-ext' ),
					'wp_footer'				=> __( 'Wp Footer', 'nexter-ext' ),
				);
			}else{
				$hooks = array(
					'nxt_html_before'		=> __( 'Html Before', 'nexter-ext' ),
					'nxt_head_top'			=> __( 'Head Top', 'nexter-ext' ),
					'nxt_head_bottom'		=> __( 'Head Bottom', 'nexter-ext' ),
					'wp_head'				=> __( 'Wp Head', 'nexter-ext' ),
			
					'nxt_body_top'			=> __( 'Body Top', 'nexter-ext' ),
					'nxt_header_before'		=> __( 'Header Before', 'nexter-ext' ),
					'nxt_header_after'		=> __( 'Header After', 'nexter-ext' ),
				
					'nxt_content_top'		=> __( 'Content Top', 'nexter-ext' ),
					'nxt_content_bottom'	=> __( 'Content Bottom', 'nexter-ext' ),
				
					'nxt_comments_before'	=> __( 'Comments Before', 'nexter-ext' ),
					'nxt_comments_after'	=> __( 'Comments After', 'nexter-ext' ),
				
					'nxt_sidebars_before'	=> __( 'Sidebar Before', 'nexter-ext' ),
					'nxt_sidebars_after'	=> __( 'Sidebar After', 'nexter-ext' ),
			
					'nxt_footer_before'		=> __( 'Footer Before', 'nexter-ext' ),
					'nxt_footer_after'		=> __( 'Footer After', 'nexter-ext' ),
					'nxt_body_bottom'		=> __( 'Body Bottom', 'nexter-ext' ),
					'wp_footer'				=> __( 'Wp Footer', 'nexter-ext' ),
				);
			}

			if(class_exists('WooCommerce')){
				//Single Product Hooks
				$hooks['woocommerce_before_single_product'] = __('Before Single Product','nexter-ext');
				$hooks['woocommerce_before_single_product_summary'] = __('Before Single Product Summary','nexter-ext');
				$hooks['woocommerce_single_product_summary'] = __('Single Product Summary','nexter-ext');
				$hooks['woocommerce_before_add_to_cart_form'] = __('Before Add To Cart Form','nexter-ext');
				$hooks['woocommerce_before_variations_form'] = __('Before Variations Form','nexter-ext');
				$hooks['woocommerce_before_add_to_cart_button'] = __('Before Add To Cart Button','nexter-ext');
				$hooks['woocommerce_before_single_variation'] = __('Before Single Variation','nexter-ext');
				$hooks['woocommerce_single_variation'] = __('Single Variation','nexter-ext');
				$hooks['woocommerce_before_add_to_cart_quantity'] = __('Before Add To Cart','nexter-ext');
				$hooks['woocommerce_after_add_to_cart_quantity'] = __('After Add To Cart','nexter-ext');
				$hooks['woocommerce_after_single_variation'] = __('After Single Variation','nexter-ext');
				$hooks['woocommerce_after_add_to_cart_button'] = __('After Add To Cart Button','nexter-ext');
				$hooks['woocommerce_after_variations_form'] = __('After Variation Form','nexter-ext');
				$hooks['woocommerce_after_add_to_cart_form'] = __('After Add To Cart Form','nexter-ext');
				$hooks['woocommerce_product_meta_start'] = __('Product Meta Start','nexter-ext');
				$hooks['woocommerce_product_meta_end'] = __('Product Meta End','nexter-ext');
				$hooks['woocommerce_share'] = __('WooCommerce Share','nexter-ext');
				$hooks['woocommerce_after_single_product_summary'] = __('After Single Product Summary','nexter-ext');
				$hooks['woocommerce_after_single_product'] = __('After Single Product','nexter-ext');

				//archive pages hooks
				$hooks['woocommerce_before_main_content'] = __('Before Main Content','nexter-ext');
				$hooks['woocommerce_archive_description'] = __('Archive Description','nexter-ext');
				$hooks['woocommerce_before_shop_loop'] = __('Before Shop Loop','nexter-ext');
				$hooks['woocommerce_before_shop_loop_item'] = __('Before Shop Loop Item','nexter-ext');
				$hooks['woocommerce_before_shop_loop_item_title'] = __('Before Shop Loop Item Title','nexter-ext');
				$hooks['woocommerce_shop_loop_item_title'] = __('Shop Loop Item Title','nexter-ext');
				$hooks['woocommerce_after_shop_loop_item_title'] = __('After Shop Loop Item Title','nexter-ext');
				$hooks['woocommerce_after_shop_loop_item'] = __('After Shop Loop Item','nexter-ext');
				$hooks['woocommerce_after_shop_loop'] = __('After Shop Loop','nexter-ext');
				$hooks['woocommerce_after_main_content'] = __('After Main Content','nexter-ext');


				//My Account Page hooks for logged in Users
				$hooks['woocommerce_account_navigation'] = __('Account Navigation','nexter-ext');
				$hooks['woocommerce_before_account_navigation'] = __('Before Account Navigation','nexter-ext');
				$hooks['woocommerce_after_account_navigation'] = __('After Account Navigation','nexter-ext');
				$hooks['woocommerce_account_dashboard'] = __('Account Dashboard','nexter-ext');
				$hooks['woocommerce_before_account_orders_pagination'] = __('Before Account Orders Pagination','nexter-ext');
				$hooks['woocommerce_before_available_downloads'] = __('Before Available Downloads','nexter-ext');
				$hooks['woocommerce_after_available_downloads'] = __('After Available Downloads','nexter-ext');
				$hooks['woocommerce_after_account_downloads'] = __('After Account Downloads','nexter-ext');
				$hooks['woocommerce_account_content'] = __('Account Content','nexter-ext');
				$hooks['woocommerce_before_edit_account_address_form'] = __('Before Edit Account Address Form','nexter-ext');
				$hooks['woocommerce_after_edit_account_address_form'] = __('After Edit Account Address form','nexter-ext');
				$hooks['woocommerce_before_edit_account_form'] = __('Before Edit Account Form','nexter-ext');
				$hooks['woocommerce_edit_account_form_start'] = __('Edit Account Form Start','nexter-ext');
				$hooks['woocommerce_edit_account_form'] = __('Edit Account Form','nexter-ext');
				$hooks['woocommerce_edit_account_form_end'] = __('Edit Account Form End','nexter-ext');
				$hooks['woocommerce_after_edit_account_form'] = __('After Edit Account Form','nexter-ext');
				//Checkout Page Hooks
				$hooks['woocommerce_before_checkout_form'] = __('Before Checkout Form','nexter-ext');
				$hooks['woocommerce_checkout_before_customer_details'] = __('Checkout Before Customer Details','nexter-ext');
				$hooks['woocommerce_before_checkout_billing_form'] = __('Before Checkout Billing Form','nexter-ext');
				$hooks['woocommerce_after_checkout_billing_form'] = __('After Checkout Billing Form','nexter-ext');
				$hooks['woocommerce_before_checkout_shipping_form'] = __('Before Checkout Shipping Form','nexter-ext');
				$hooks['woocommerce_after_checkout_shipping_form'] = __('After Checkout Shipping Form','nexter-ext');
				$hooks['woocommerce_before_order_notes'] = __('Before Order Notes','nexter-ext');
				$hooks['woocommerce_after_order_notes'] = __('After Order Notes','nexter-ext');
				$hooks['woocommerce_checkout_after_customer_details'] = __('Checkout After Customer Details','nexter-ext');
				$hooks['woocommerce_checkout_before_order_review'] = __('Checkout Before Order Review','nexter-ext');
				$hooks['woocommerce_review_order_before_cart_contents'] = __('Review Order Before Cart Contents','nexter-ext');
				$hooks['woocommerce_review_order_after_cart_contents'] = __('Review Order After Cart Contents','nexter-ext');
				$hooks['woocommerce_review_order_before_shipping'] = __('Review Order Before Shipping','nexter-ext');
				$hooks['woocommerce_review_order_after_shipping'] = __('Review Order After Shipping','nexter-ext');
				$hooks['woocommerce_review_order_before_order_total'] = __('Review Order Before Order Total','nexter-ext');
				$hooks['woocommerce_review_order_after_order_total'] = __('Review Order After Order Total','nexter-ext');
				$hooks['woocommerce_review_order_before_payment'] = __('Review Order Before Payment','nexter-ext');
				$hooks['woocommerce_review_order_before_submit'] = __('Review Order Before Submit','nexter-ext');
				$hooks['woocommerce_review_order_after_submit'] = __('Review Order After Submit','nexter-ext');
				$hooks['woocommerce_review_order_after_payment'] = __('Review Order After Payment','nexter-ext');
				$hooks['woocommerce_checkout_after_order_review'] = __('Checkout After Order Review','nexter-ext');
				$hooks['woocommerce_after_checkout_form'] = __('After Checkout Form','nexter-ext');

				//Cart Page Hooks
				$hooks['woocommerce_before_cart'] = __('Before Cart','nexter-ext');
				$hooks['woocommerce_before_cart_table'] = __('Before Cart Table','nexter-ext');
				$hooks['woocommerce_before_cart_contents'] = __('Before Cart Contents','nexter-ext');
				$hooks['woocommerce_cart_contents'] = __('Cart Contents','nexter-ext');
				$hooks['woocommerce_cart_coupon'] = __('Cart Coupon','nexter-ext');
				$hooks['woocommerce_after_cart_contents'] = __('After Cart Contents','nexter-ext');
				$hooks['woocommerce_after_cart_table'] = __('After Cart Table','nexter-ext');
				$hooks['woocommerce_cart_collaterals'] = __('Cart Collaterals','nexter-ext');
				$hooks['woocommerce_before_cart_totals'] = __('Before Cart Totals','nexter-ext');
				$hooks['woocommerce_cart_totals_before_shipping'] = __('Cart Totals Before Shipping','nexter-ext');
				$hooks['woocommerce_before_shipping_calculator'] = __('Before Shipping Calculator','nexter-ext');
				$hooks['woocommerce_after_shipping_calculator'] = __('After Shipping Calculator','nexter-ext');
				$hooks['woocommerce_cart_totals_after_shipping'] = __('Cart Totals After Shipping','nexter-ext');
				$hooks['woocommerce_cart_totals_before_order_total'] = __('Cart Totals Before Order Total','nexter-ext');
				$hooks['woocommerce_cart_totals_after_order_total'] = __('Cart Totals After Order Total','nexter-ext');
				$hooks['woocommerce_proceed_to_checkout'] = __('Proceed To Checkout','nexter-ext');
				$hooks['woocommerce_after_cart_totals'] = __('After Cart Totals','nexter-ext');
				$hooks['woocommerce_after_cart'] = __('After Cart','nexter-ext');
			}

			return apply_filters( 'nexter_sections_hooks_list', $hooks );
		}
		
		/**
		 * Get Check Current Page Type / Singular / Archive
		 *
		 * @since  1.0.0
		 */
		public function get_check_current_page_type_name( $type = '' ) {

			if ( (empty($type) && null === self::$current_page_type_name) || ($type=='singular' && null === self::$current_page_type_name_singular) || ($type=='archive' && null === self::$current_page_type_name_archive) ) {

				$current_id = false;
				$page_type  = '';

				if ( is_home() ) {
					$page_type = 'is_home';
				} elseif ( is_front_page() ) {
					$page_type  = 'is_front_page';
					$current_id = get_the_id();
				} elseif ( is_singular() ) {
					$page_type  = 'is_singular';
					$current_id = get_the_id();
				} elseif ( is_archive() ) {
					$page_type = 'is_archive';

					if ( is_category() || is_tag() || is_tax() ) {
						$page_type = 'is_tax';
					} elseif ( is_date() ) {
						$page_type = 'is_date';
					} elseif ( is_author() ) {
						$page_type = 'is_author';
					} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
						$page_type = 'is_shop_page';
					}
				}else if ( is_404() ) {
					$page_type = 'is_404';
				}else if ( is_search() ) {
					$page_type = 'is_search';
				} else {
					$current_id = get_the_id();
				}
				
				if( !empty($type) && $type=='singular' ){
					self::$current_singular_data['ID'] = $current_id;
					self::$current_page_type_name_singular = $page_type;
				}else if( !empty($type) && $type=='archive' ){
					self::$current_archive_data['ID'] = $current_id;
					self::$current_page_type_name_archive = $page_type;
				}else if(empty($type)){
					self::$current_load_page_data['ID'] = $current_id;
					self::$current_page_type_name       = $page_type;
				}
			}
			
			if( !empty($type) && $type=='singular' ){
				return self::$current_page_type_name_singular;
			}else if(!empty($type) && $type=='archive'){
				return self::$current_page_type_name_archive;
			}else{
				return self::$current_page_type_name;
			}
		}
		
		/*
		 * Get Singular Template(Posts) By Singular Options Conditions
		 *
		 * @since  1.0.0
		 */
		public function get_templates_by_singular_conditions( $type, $options ){
			global $wpdb;
			global $post;

			$type = $type ? esc_sql( $type ) : esc_sql( $post->post_type );

			if ( is_array( self::$current_singular_data ) && isset( self::$current_singular_data[ $type ] ) ) {
				return apply_filters( 'nexter_get_singluar_posts_by_conditions', self::$current_singular_data[ $type ], $type );
			}

			$current_page_type_name_singular = $this->get_check_current_page_type_name('singular');

			self::$current_singular_data[ $type ] = array();

			$options['current_post_id'] = self::$current_singular_data['ID'];

			$singular_group = isset( $options['singular_group'] ) ? esc_sql( $options['singular_group'] ) : '';

			$nxt_option = 'nxt-build-get-data';
			$get_data = get_option( $nxt_option );
			if( $get_data === false ){
				$get_data = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
				add_option( $nxt_option, $get_data, false );
			}

			if(!empty($get_data) && isset($get_data['saved']) && isset($get_data['singular_updated']) && $get_data['saved'] !== $get_data['singular_updated']){
				$sqlquery = "SELECT p.ID, pm.meta_value FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = %s) AND p.post_type = %s AND p.post_status = 'publish' ORDER BY p.post_date DESC";
			
				$sql1 = $wpdb->prepare( $sqlquery , $singular_group, $type ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			
				$posts  = $wpdb->get_results( $sql1 );	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	
				foreach ( $posts as $post_data ) {
					$get_layout_type = get_post_meta( $post_data->ID , 'nxt-hooks-layout-pages', false );
					if(!empty($get_layout_type) && !empty($get_layout_type[0]) && 'singular' == $get_layout_type[0]){
						self::$current_singular_data[ $type ][ $post_data->ID ] = array(
							'id'       => $post_data->ID,
							'template_group' => maybe_unserialize( $post_data->meta_value ),
						);
					}
				}
				$get_data['singular_updated'] = $get_data['saved'];
				$get_data[ 'singular' ] = self::$current_singular_data[ $type ];
				update_option( $nxt_option, $get_data );
			}else if( isset($get_data[ 'singular' ]) && !empty($get_data[ 'singular' ])){
				self::$current_singular_data[ $type ] = $get_data[ 'singular' ];
			}
			
			return apply_filters( 'nexter_get_singluar_posts_by_conditions', self::$current_singular_data[ $type ], $type );
		}
		
		/*
		 * Get Archives Template(Posts) By Archives Options Conditions
		 *
		 * @since 1.0.0
		 */
		public function get_templates_by_archives_conditions( $type, $options ){
			global $wpdb;
			global $post;

			$type = $type ? esc_sql( $type ) : esc_sql( $post->post_type );

			if ( is_array( self::$current_archive_data ) && isset( self::$current_archive_data[ $type ] ) ) {
				return apply_filters( 'nexter_get_archive_posts_by_conditions', self::$current_archive_data[ $type ], $type );
			}

			$current_page_type_name_archive = $this->get_check_current_page_type_name('archive');

			self::$current_archive_data[ $type ] = array();

			$options['current_post_id'] = self::$current_archive_data['ID'];

			$archive_group = isset( $options['archive_group'] ) ? esc_sql( $options['archive_group'] ) : '';

			$nxt_option = 'nxt-build-get-data';
			$get_data = get_option( $nxt_option );
			if( $get_data === false ){
				$get_data = ['saved' => strtotime('now'), 'singular_updated' => '','archives_updated' => '','sections_updated' => ''];
				add_option( $nxt_option, $get_data, false );
			}
			
			if(!empty($get_data) && isset($get_data['saved']) && isset($get_data['archives_updated']) && $get_data['saved'] !== $get_data['archives_updated']){
				$sqlquery = "SELECT p.ID, pm.meta_value FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = %s) AND p.post_type = %s AND p.post_status = 'publish' ORDER BY p.post_date DESC";
				
				$sql2 = $wpdb->prepare( $sqlquery , $archive_group, $type );	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				
				$posts  = $wpdb->get_results( $sql2 );	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				foreach ( $posts as $post_data ) {
					$get_layout_type = get_post_meta( $post_data->ID , 'nxt-hooks-layout-pages', false );
					if( !empty($get_layout_type) && !empty($get_layout_type[0]) && 'archives' == $get_layout_type[0]){
						self::$current_archive_data[ $type ][ $post_data->ID ] = array(
							'id'       => $post_data->ID,
							'template_group' => maybe_unserialize( $post_data->meta_value ),
						);
					}
				}
				
				$get_data['archives_updated'] = $get_data['saved'];
				$get_data[ 'archives' ] = self::$current_archive_data[ $type ];
				update_option( $nxt_option, $get_data );
				
			}else if( isset($get_data[ 'archives' ]) && !empty($get_data[ 'archives' ])){
				self::$current_archive_data[ $type ] = $get_data[ 'archives' ];
			}
			
			return apply_filters( 'nexter_get_archive_posts_by_conditions', self::$current_archive_data[ $type ], $type );
		}
		
		/**
		 * Get Template posts by Sections conditions
		 *
		 * @since 1.0.0
		 */
		public function get_templates_by_sections_conditions( $type, $option ) {

			global $wpdb;
			global $post;

			$type = $type ? esc_sql( $type ) : esc_sql( $post->post_type );

			if ( is_array( self::$current_load_page_data ) && isset( self::$current_load_page_data[ $type ] ) ) {
				return apply_filters( 'nexter_get_sections_posts_by_conditions', self::$current_load_page_data[ $type ], $type );
			}

			$current_page_type_name = $this->get_check_current_page_type_name();

			self::$current_load_page_data[ $type ] = array();

			$option['current_post_id'] = self::$current_load_page_data['ID'];

			$current_post_type	= esc_sql( get_post_type() );
			$current_post_id	= false;
			$queried_object		= get_queried_object();

			$location = isset( $option['location'] ) ? esc_sql( $option['location'] ) : '';

			/* global posts / specific posts */
			$join_meta = "pm.meta_value LIKE '%\"standard-universal\"%'";
			
			if( !empty( $current_page_type_name ) ){
				
				$join_meta .= " OR pm.meta_value LIKE '%\"particular-post\"%'";
				
				if( $current_page_type_name == 'is_home' ){
					$join_meta .= " OR pm.meta_value LIKE '%\"default-blog\"%'";
				}else if( $current_page_type_name == 'is_front_page' ){
					$current_id      = esc_sql( get_the_id() );
					$join_meta      .= " OR pm.meta_value LIKE '%\"post-{$current_id}\"%'";
					$join_meta      .= " OR pm.meta_value LIKE '%\"default-front\"%'";
					$join_meta      .= " OR pm.meta_value LIKE '%\"{$current_post_type}|entire\"%'";
					
				}else if( $current_page_type_name == 'is_404' ){
					$join_meta .= " OR pm.meta_value LIKE '%\default-404\%' OR pm.meta_value LIKE '%page-404%'";
				}else if( $current_page_type_name == 'is_search' ){
					$join_meta .= " OR pm.meta_value LIKE '%\"default-search\"%'";
				}else if( $current_page_type_name == 'is_singular' ){
					$current_id      = esc_sql( get_the_id() );
					$join_meta      .= " OR pm.meta_value LIKE '%\"standard-singulars\"%'";
					$join_meta      .= " OR pm.meta_value LIKE '%\"{$current_post_type}|entire\"%'";
					$join_meta      .= " OR pm.meta_value LIKE '%\"post-{$current_id}\"%'";
					
					$singular_post_type = $current_post_type;
					if(!empty($queried_object) && isset($queried_object->post_type) && !empty($queried_object->post_type)){
						$singular_post_type = $queried_object->post_type;
					}
					$taxonomies = get_object_taxonomies( $singular_post_type );
					
					$singular_id = get_queried_object_id();
					if(!empty($queried_object) && isset($queried_object->ID) && !empty($queried_object->ID)){
						$singular_id = $queried_object->ID;
					}
					$terms = wp_get_post_terms( $singular_id, $taxonomies );

					foreach ( $terms as $key => $term ) {
						$join_meta .= " OR pm.meta_value LIKE '%\"taxonomy-{$term->term_id}-singular-{$term->taxonomy}\"%'";
					}
					
				}else if( $current_page_type_name == 'is_archive' || $current_page_type_name == 'is_tax' || $current_page_type_name == 'is_date' || $current_page_type_name == 'is_author' ){
					$join_meta .= " OR pm.meta_value LIKE '%\"standard-archives\"%'";
					$join_meta .= " OR pm.meta_value LIKE '%\"{$current_post_type}|entire|archive\"%'";

					if ( $current_page_type_name == 'is_tax' && ( is_category() || is_tag() || is_tax() ) ) {
						if ( is_object( $queried_object ) ) {
							$join_meta .= " OR pm.meta_value LIKE '%\"{$current_post_type}|entire|tax-archive|{$queried_object->taxonomy}\"%'";
							$join_meta .= " OR pm.meta_value LIKE '%\"taxonomy-{$queried_object->term_id}\"%'";
						}
					} else if ( $current_page_type_name == 'is_date' ) {
						$join_meta .= " OR pm.meta_value LIKE '%\"default-date\"%'";
					} else if ( $current_page_type_name == 'is_author' ) {
						$join_meta .= " OR pm.meta_value LIKE '%\"default-author\"%'";
					}
					
				}else if( $current_page_type_name == 'is_shop_page' ){
					$join_meta .= " OR pm.meta_value LIKE '%\"default-woo-shop\"%'";
				}

				if(has_filter( 'nexter_advanced_sections_query_meta' )){
					$join_meta .= apply_filters('nexter_advanced_sections_query_meta', $current_page_type_name );
				}

				if($current_page_type_name == 'is_front_page'){
					$current_post_id = esc_sql( get_the_id() );
				}else if($current_page_type_name == 'is_singular'){
					$current_post_id = esc_sql( get_the_id() );
					
					if ( class_exists( 'SitePress' ) ) {
						$default_language = wpml_get_default_language();
						$current_post_id  = icl_object_id( $current_post_id, $current_post_type, true, $default_language );
					}
				}

			}else if( $current_page_type_name == '' ){
				$current_post_id = get_the_id();
			}
			
			$sqlquery = "SELECT p.ID, pm.meta_value FROM {$wpdb->postmeta} as pm INNER JOIN {$wpdb->posts} as p ON pm.post_id = p.ID WHERE (pm.meta_key = %s OR pm.meta_key = 'nxt-hooks-layout-pages') AND p.post_type = %s AND p.post_status = 'publish' AND ( {$join_meta} ) ORDER BY p.post_date DESC";
			
			$sql3 = $wpdb->prepare( $sqlquery , $location, $type );	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			
			$posts  = $wpdb->get_results( $sql3 );	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			
			if( !empty($posts) ){
				foreach ( $posts as $post_data ) {
					if(function_exists('pll_get_post')){
						if(!pll_get_post( $post_data->ID )){
							continue;
						}
					}
					$priority = 0;

					$specific_value=[];
					$set_day=[];
					$set_os=[];
					$set_browser=[];
					$set_login_status=[];
					$set_user_roles=[];
					
					$check_day = true;
					$check_os = true;
					$check_browser = true;
					$check_login_status = true;
					$check_user_roles = true;
					
					$display_specific = true;

					$post_meta_value = maybe_unserialize( $post_data->meta_value );
					
					if(!is_admin() && !empty($post_meta_value) && is_array($post_meta_value)){
						$check_condition = false;
						//standard match
						$standard_value = preg_grep('/^standard-/i', $post_meta_value);
						if(!empty($standard_value)){
							$priority = 5;
							$check_condition = true;
						}
						
						//post type match
						if ( is_object( $queried_object ) && isset($queried_object->post_type)) {
							$match_value = '/^'.$queried_object->post_type.'|entire$/i';
							$post_type_match = preg_grep($match_value, $post_meta_value);
							if(!empty($post_type_match)){
								$priority = 10;
								$check_condition = true;
							}
						}
						
						//archive post type
						$archive_post_type = get_post_type();
						if((is_tax() || is_category() || is_tag()) && !empty($archive_post_type) && isset($queried_object->taxonomy)){
							$match_value = '/^'.$archive_post_type.'|entire|tax-archive|'.$queried_object->taxonomy.'$/i';
							$post_type_match = preg_grep($match_value, $post_meta_value);
							if(!empty($post_type_match)){
								$priority = 15;
								$check_condition = true;
							}
						}

						//pages match
						$check_value = preg_grep('/^default-/i', $post_meta_value);
						if(!empty($check_value)){
							$priority = 15;
							$check_condition = true;
						}
						
						//Particular Posts/Pages Match
						if(!empty($post_meta_value) && in_array('particular-post',$post_meta_value) ){
						
							if(is_object( $queried_object )){
								$specific_value   = get_post_meta( $post_data->ID, 'nxt-hooks-layout-specific', true );
								
								if(!empty($specific_value) && isset($queried_object->ID)){
									
									$match = '/post-'.$queried_object->ID.'$/i';
									$check_value = preg_grep($match, $specific_value);
									$post_type_match =[];
									if ( is_object( $queried_object ) && isset($queried_object->post_type)) {
										$match_value = '/^'.$queried_object->post_type.'|entire$\/i/';
										$post_type_match = preg_grep($match_value, $post_meta_value);
									}

									if(isset($queried_object->post_type)){
										$taxonomies = get_object_taxonomies( $queried_object->post_type );
										$terms = wp_get_post_terms( $queried_object->ID, $taxonomies );
										if(!empty($terms)){
											foreach ( $terms as $key => $term ) {
												if(in_array("taxonomy-{$term->term_id}-singular-{$term->taxonomy}", $specific_value)){
													$check_value = true;
												}
											}
										}
									}
									
									if($check_value || $post_type_match){
										$priority = 20;
										$display_specific = true;
									}else{
										$display_specific = false;
									}
								}else if(!empty($specific_value) && isset($queried_object->term_id)){
									$match = '/taxonomy-'.$queried_object->term_id.'$/i';
									$check_value = preg_grep($match, $specific_value);
									if($check_value){
										$priority = 20;
										$display_specific = true;
									}else{
										$display_specific = false;
									}
								}else{
									$display_specific = false;
								}
							}else{
								$display_specific = false;
							}
						}

						//Date & Time (Day/Time/Date)						
						if(!empty($post_meta_value) && in_array('set-day',$post_meta_value)){
							$set_day   = get_post_meta( $post_data->ID, 'nxt-hooks-layout-set-day', true );
							$check_day = self::check_condition_set_day( $set_day, $check_day );
						}
						
						//Operating System
						if(!empty($post_meta_value) && in_array('os',$post_meta_value)) {
							$set_os  = get_post_meta( $post_data->ID, 'nxt-hooks-layout-os', true );
							$check_os = self::check_condition_os($set_os, $check_os);
						}
						
						//Browser
						if(!empty($post_meta_value) && in_array('browser',$post_meta_value)){
							$set_browser  = get_post_meta( $post_data->ID, 'nxt-hooks-layout-browser', true );
							$check_browser = self::check_condition_browser($set_browser, $check_browser );
						}
						
						//Login Status
						if(!empty($post_meta_value) && in_array('login-status',$post_meta_value)){
							$set_login_status  = get_post_meta( $post_data->ID, 'nxt-hooks-layout-login-status', true );
							$check_login_status = self::check_condition_login_status($set_login_status, $check_login_status );
						}
						
						//User Roles
						if(!empty($post_meta_value) && in_array('user-roles',$post_meta_value)){
							$set_user_roles  = get_post_meta( $post_data->ID, 'nxt-hooks-layout-user-roles', true );
							$check_user_roles = self::check_condition_user_roles($set_user_roles, $check_user_roles );
						}

						if($display_specific || $check_condition){
							$display_specific = true;
						}
					}
					
					self::$current_load_page_data[ $type ][ $post_data->ID ] = array(
						'id'       => $post_data->ID,
						'location' => maybe_unserialize( $post_data->meta_value ),
						'specific'	=> ($specific_value) ? $specific_value : [],
						'set-day'	=> ($set_day) ? $set_day : [],
						'os'		=> ($set_os) ? $set_os : [],
						'browser'	=> ($set_browser) ? $set_browser : [],
						'login-status'	=> ($set_login_status) ? $set_login_status : [],
						'user-roles'	=> ($set_user_roles) ? $set_user_roles : [],
						'priority' => ($priority) ? $priority : 0,
						'condition' => ($display_specific) ? $display_specific : 0,
					);
					
					if( defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' ) ){
						self::$current_load_page_data = apply_filters('nexter_advanced_section_current_page_conditions', self::$current_load_page_data, $type, $post_data, $priority);
					}
					
				}
			}
			
			self::$current_load_page_data[$type]  = $this->array_sort_by_priority(self::$current_load_page_data[$type], 'priority', SORT_DESC);
			
			$option['current_post_id'] = $current_post_id;

			$this->remove_templates_excludes_conditional_rules( $type, $option );
			
			return apply_filters( 'nexter_get_sections_posts_by_conditions', self::$current_load_page_data[ $type ], $type );
		}
		
		/**
		 * Remove Template Exclude Locations Conditional Rules
		 */
		public function remove_templates_excludes_conditional_rules( $type, $options ) {

			$exclusion       = isset( $options['exclusion'] ) ? $options['exclusion'] : '';
			$current_post_id = isset( $options['current_post_id'] ) ? $options['current_post_id'] : false;

			foreach ( self::$current_load_page_data[ $type ] as $c_post_id => $c_data ) {

				$exclusion_rules = get_post_meta( $c_post_id, $exclusion, true );
				if( !empty($exclusion_rules) && !empty($c_post_id)){
					if( !empty($exclusion_rules) && in_array('particular-post',$exclusion_rules) ){
						$exclusion_rules['specific']   = get_post_meta( $c_post_id, 'nxt-hooks-layout-exclude-specific', true );
					}
					$exclude_array = [ 'set-day', 'os', 'browser', 'login-status', 'user-roles' ];
					foreach ($exclude_array as $exclude) {
						if( !empty($exclusion_rules) && in_array($exclude, $exclusion_rules) ){
							$exclusion_rules[$exclude]   = get_post_meta( $c_post_id, 'nxt-hooks-layout-exclude-'.$exclude, true );
						}
					}
				}
				$exclusion_rules = apply_filters( 'nexter_advanced_section_exclude_condition', $exclusion_rules, $c_post_id );
				
				$exclude_id = $this->check_layout_display_inc_exc_rules( $current_post_id, $exclusion_rules );
				
				if ( $exclude_id ) {
					unset( self::$current_load_page_data[ $type ][ $c_post_id ] );
				}
			}
		}

		
		
		/* 
		 *	Sorting Data Array By Priority
		 */
		public function array_sort_by_priority($data_array, $on, $order=SORT_ASC){

			$new_array = [];
			$sorting_array = [];
			
			if (count($data_array) > 0) {
				foreach ($data_array as $key => $val) {
					if (is_array($val)) {
						foreach ($val as $k2 => $v2) {
							if ($k2 == $on) {
								$sorting_array[$key] = $v2;
							}
						}
					} else {
						$sorting_array[$key] = $val;
					}
				}

				switch ($order) {
					case SORT_ASC:
						asort($sorting_array);
						break;
					case SORT_DESC:
						arsort($sorting_array);
						break;
				}

				foreach ($sorting_array as $key => $val) {
					if( isset($data_array[$key]['condition']) && $data_array[$key]['condition']==1 ){
						$new_array[$key] = $data_array[$key];
					}
				}
			}

			return $new_array;
		}
		
		/**
		 * Ajax particular posts/pages display rules search query.
		 *
		 * @since  1.0.0
		 */
		public static function nexter_get_particular_posts_query() {

			$search_data = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash($_POST['q']) ) : '';
			$data          = array();
			$result        = array();

			$args = array(
				'public'   => true,
				'_builtin' => false,
			);

			$post_types = get_post_types( $args, 'names', 'and' );

			$post_types['Posts'] = 'post';
			$post_types['Pages'] = 'page';

			foreach ( $post_types as $key => $post_type ) {

				$data = array();
				if(isset( $_POST['q'] )){
					$get_instance = Nexter_Builder_Display_Conditional_Rules::get_instance();
					add_filter( 'posts_search', array( $get_instance, 'search_data_by_titles' ), 10, 2 );
				}
				$particular_args = array(
					'post_type'      => $post_type,
					'posts_per_page' => - 1,
					's'              => $search_data,
				);
				$particular_query = get_posts( $particular_args );
				
				foreach ( $particular_query as $post ):
						$title  = $post->post_title;						
						$title .= ( 0 != $post->post_parent ) ? ' (' . get_the_title( $post->post_parent ) . ')' : '';
						$id     = $post->ID;
						$data[] = array(
							'id'   => 'post-' . $id,
							'text' => $title,
						);
				endforeach;
				
				if ( is_array( $data ) && ! empty( $data ) ) {
					$result[] = array(
						'text'     => $key,
						'children' => $data,
					);
				}
			}
			
			$data = array();
			$args = array(
				'public' => true,
			);

			$taxonomies = get_taxonomies( $args, 'objects', 'and' );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					$taxonomy->name,
					array(
						'orderby'    => 'count',
						'hide_empty' => 0,
						'name__like' => $search_data,
					)
				);

				$data = array();

				if ( ! empty( $terms ) ) {

					foreach ( $terms as $term ) {

						$data[] = array(
							'id'   => 'taxonomy-' . $term->term_id,
							'text' => $term->name . __( ' archive page', 'nexter-ext' ),
						);

						$data[] = array(
							'id'   => 'taxonomy-' . $term->term_id . '-singular-' . $taxonomy->name,
							'text' => __( 'All singulars  ', 'nexter-ext' ) . $term->name,
						);

					}
				}
				$tax_label = ucwords( $taxonomy->label );
				if ( is_array( $data ) && ! empty( $data ) ) {
					$result[] = array(
						'text'     => $tax_label,
						'children' => $data,
					);
				}
			}
			
			if(!empty($search_data)){
				wp_send_json( $result );
			}else{
				return $result;
			}
		}

		/**
		 * Return search data results by post title.
		 */
		public function search_data_by_titles( $search, $wp_query ) {
			if ( ! empty( $search ) && ! empty( $wp_query->query_vars['search_terms'] ) ) {
				global $wpdb;

				$query = $wp_query->query_vars;
				$exact = ! empty( $query['exact'] ) ? '' : '%';

				$search = array();

				foreach ( (array) $query['search_terms'] as $term ) {
					$search[] = $wpdb->prepare( "$wpdb->posts.post_title LIKE %s", $exact . $wpdb->esc_like( $term ) . $exact );
				}

				if ( ! is_user_logged_in() ) {
					$search[] = "$wpdb->posts.post_password = ''";
				}

				$search = ' AND ' . implode( ' AND ', $search );
			}

			return $search;
		}
		
		/*
		 * Other Location Sub Options
		 */
		public static function get_others_location_sub_options( $rule='' ) {
			$options_list = [];
			if(!empty($rule)){
				if($rule == 'set-day'){
					$options_list = array(
						'1' => __( 'Monday', 'nexter-ext' ),
						'2' => __( 'Tuesday', 'nexter-ext' ),
						'3' => __( 'Wednesday', 'nexter-ext' ),
						'4' => __( 'Thursday', 'nexter-ext' ),
						'5' => __( 'Friday', 'nexter-ext' ),
						'6' => __( 'Saturday', 'nexter-ext' ),
						'7' => __( 'Sunday', 'nexter-ext' ),
					);
				}
				if($rule == 'os'){
					$options_list = array(
						'iphone' 		=> __( 'iPhone', 'nexter-ext' ),
						'windows' 		=> __( 'Windows', 'nexter-ext' ), 
						'open_bsd'		=> __( 'OpenBSD', 'nexter-ext' ), 
						'sun_os'    	=> __( 'SunOS', 'nexter-ext' ), 
						'linux'     	=> __( 'Linux', 'nexter-ext' ), 
						'safari'    	=> __( 'Safari', 'nexter-ext' ), 
						'mac_os'    	=> __( 'Mac OS', 'nexter-ext' ), 
						'qnx'       	=> __( 'QNX', 'nexter-ext' ), 
						'beos'      	=> __( 'BeOS', 'nexter-ext' ), 
						'os2'       	=> __( 'OS/2', 'nexter-ext' ), 
						'search_bot'	=> __( 'Search Bot', 'nexter-ext' ), 
					);
				}
				if($rule == 'browser'){
					$options_list = array(
						'ie'			=> __( 'Internet Explorer', 'nexter-ext' ),
						'firefox'		=> __( 'Mozilla Firefox', 'nexter-ext' ),
						'chrome'		=> __( 'Google Chrome', 'nexter-ext' ),
						'opera_mini'	=> __( 'Opera Mini', 'nexter-ext' ),
						'opera'			=> __( 'Opera', 'nexter-ext' ),
						'safari'		=> __( 'Safari', 'nexter-ext' ),
					);
				}
				if($rule == 'login-status'){
					$options_list = array(
						'logged-in'			=> __( 'Logged In', 'nexter-ext' ),
						'logged-out'		=> __( 'Logged Out', 'nexter-ext' ),
					);
				}
				if($rule == 'user-roles'){
					if ( ! function_exists( 'get_editable_roles' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					
					$user_roles = get_editable_roles();

					foreach ( $user_roles as $slug => $role ) {
						$options_list[ $slug ] = $role['name'];
					}
				}
			}
			
			return  apply_filters( 'nexter_other_location_sub_options', $options_list );
		}

	}
}

Nexter_Builder_Display_Conditional_Rules::get_instance();

/**
 * Get Specific Posts Query Display Rules
 */
function nexter_get_posts_query_specific( $field ){
	$specific_id = $field->args('id');
	$specific_value = get_post_meta( get_the_ID(), $specific_id, true );
	
	$data_query = Nexter_Builder_Display_Conditional_Rules::nexter_get_particular_posts_query();
	
	$options =array();
	if( !empty( $specific_value ) && $specific_value!='none') {
		foreach ( $data_query as $key => $parent ) {
			foreach( $parent['children'] as $key => $value ){
				if( !empty( $specific_value ) && in_array( $value['id'], $specific_value ) ) {
					$options[$value['id']] = $value['text'];
				}
			}
		}
	}else{		
		$options['none'] = esc_html__('---Select---', 'nexter-ext');
	}
	
	return $options;
}