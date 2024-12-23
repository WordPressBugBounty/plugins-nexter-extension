<?php
/**
 * Nexter Singular Post Type Rules
 *
 * @package   Nexter Extensions
 * @since 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Nexter_Builders_Singular_Conditional_Rules {

		/**
		 * Instance
		 */
		private static $instance;

		public static $singular_conditions = [];
		
		public static $load_conditions_rule = [];
		
		/**
		 * Singular Options
		 */
		public static $Nexter_Singular_Config = array();
		
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
			add_action( 'wp', [ $this, 'register_post_types_conditions' ], 0 );
			add_action('wp_ajax_nxt_singular_preview_type_ajax', [ $this, 'get_post_type_posts_list' ] );
			if( !is_admin() ){
				add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 11 );
			}
		}
		
		/*
		 * Backend compatibility singular WooCommerce js load
		 * @since 1.1.0
		 */
		public function enqueue_scripts(){
			if ( class_exists( '\Elementor\Plugin' ) && class_exists( 'woocommerce' ) ) {
				$post_id = get_the_ID();
				$post_type = get_post_type();
				if ( Elementor\Plugin::$instance->preview->is_preview_mode( $post_id ) && $post_type === NEXTER_EXT_CPT) {
					global $product;
		
					if ( is_singular( 'product' ) ) {
						$product = wc_get_product();
					}
		
					if ( current_theme_supports( 'wc-product-gallery-zoom' ) ) {
						wp_enqueue_script( 'zoom' );
					}
					if ( current_theme_supports( 'wc-product-gallery-slider' ) ) {
						wp_enqueue_script( 'flexslider' );
					}
					if ( current_theme_supports( 'wc-product-gallery-lightbox' ) ) {
						wp_enqueue_script( 'photoswipe-ui-default' );
						wp_enqueue_style( 'photoswipe-default-skin' );
						add_action( 'wp_footer', 'woocommerce_photoswipe' );
					}
					wp_enqueue_script( 'wc-single-product' );
		
					wp_enqueue_style( 'photoswipe' );
					wp_enqueue_style( 'photoswipe-default-skin' );
					wp_enqueue_style( 'woocommerce_prettyPhoto_css' );
				}
			}
		}

		/*
		 * Singluar Options
		 */
		public static function get_singular_options(array $data) {
			
			$options = array(
				"all" => __( "All", 'nexter-extension' ),
			);
			
			$result = Nexter_Singular_Archives_Rules::nexter_ajax_singular_archives_filters($data);
			if( !empty($result) && isset($result) ){
			
				$json_parse = json_decode( $result, true );
				if(isset($json_parse['results']) && !empty($json_parse['results'])){
					foreach ($json_parse['results'] as $key => $value) {					
						$options[$value['id']] = $value['text'];
					}
				}
				
			}

			$options = apply_filters( 'nexter_singluar_archives_specific_options', $options, $data );
			
			return $options;
		}
		
		/*
		 * Get All Post Types List
		 */
		public static function get_post_types_list( $post_args = [] ) {
	
			$args = [
				'show_in_nav_menus' => true,
			];

			if ( ! empty( $post_args['post_type'] ) ) {
				$args['name'] = $post_args['post_type'];
				unset( $post_args['post_type'] );
			}

			$args = wp_parse_args( $args, $post_args );

			$get_post_types = get_post_types( $args, 'objects' );

			$post_types_list = [];

			foreach ( $get_post_types as $post_type => $object ) {
				if($post_type==NEXTER_EXT_CPT){
					//skip to next iteration if item value is 0
					continue;
				}
				$post_types_list[ $post_type ] = $object->label;
			}
			
			return apply_filters( 'nexter_get_singular_post_types_list', $post_types_list );
		}

		/*
		 * Get Posts Lists Ids By Post Type
		 * @since 1.0.1
		 */
		public static function get_post_type_posts_list( $post_type = '') {
		
			$query_args = [ 'post_type' => 'any', 'posts_per_page' => -1 ];
			if( !empty( $post_type ) ){
				$query_args['post_type'] = $post_type;
			}else{
				$query_args['post_type'] = (!empty($_POST['rules'])) ? sanitize_text_field( wp_unslash($_POST['rules']) ) : 'any';
			}
			
			$post_query = new \WP_Query( $query_args );
			$results = [];
			$results_data = [];
			foreach ( $post_query->posts as $post ) {
				$text = $post->post_title;
				$results[] = [
					'id' => $post->ID,
					'text' => $text,
				];
			}
			
			if(!empty($results)){
				$results_data['response'] = true;
				$results_data['results'] = $results;				
			}else{
				$results_data['response'] = false;
				$results_data['results'] = $results;				
			}

			if(!empty($post_type)){
				$options = [];
				if( !empty($results) && isset($results) ){
					foreach ($results as $key => $value) {					
						$options[$value['id']] = $value['text'];
					}
				}

				return $options;
			}else{
				echo json_encode($results_data);
			}

			wp_die();
		}
		
		/*
		 * Register Post Type Condition
		 */
		public static function register_post_types_conditions( $preview = '' ) {
		
			$post_types_list = self::get_post_types_list();

			//post type attachment
			$post_types_list['attachment'] = get_post_type_object( 'attachment' )->label;
			
			$front_page = new Nxt_Front_Page();
			self::$load_conditions_rule[ $front_page->get_post_type_name() ] = $front_page;

			self::$load_conditions_rule = apply_filters( 'nexter_singular_load_condition', self::$load_conditions_rule );

			self::$singular_conditions['front-page'] = [
				'label' => __('Front Page','nexter-extension'),
				'value' => [ 'front_page' => __('Front Page','nexter-extension'), ]
			];

			foreach ( $post_types_list as $post_type => $post_label ) {
				
				$post_condition = new Nexter_Post_Singular( [
					'post_type' => $post_type,
				] );
				
				self::$singular_conditions[$post_type] = [ 'label' => $post_label, 'value' => [ $post_type => __( 'All ','nexter-extension' ).$post_label ] ];

				self::register_post_condition_instance($post_condition);
				
				if( class_exists('Nexter_Singular_Archives_Rules') && $preview!='preview'){
					Nexter_Singular_Archives_Rules::nexter_post_singular_sub_conditions($post_type);
				}
				self::$Nexter_Singular_Config[$post_type] = $post_condition->query_control($post_condition);
				
			}
			if( $preview == 'preview' ){
				return self::$singular_conditions;
			}
			return apply_filters( 'nexter_display_singular_list', self::$singular_conditions );
		}
		
		public static function register_post_sub_condition( $condition, $post_name ='' ) {
			
			$key = $condition->get_post_type_name();
			self::$singular_conditions[$post_name]['value'][$key] = $condition->get_post_type_label();
			self::$Nexter_Singular_Config[$key] = $condition->query_control();
		
			self::register_post_condition_instance($condition);
		
		}
		
		public static function register_post_condition_instance( $instance_data ) {
			self::$load_conditions_rule[ $instance_data->get_post_type_name() ] = $instance_data;
		}
		
		public static function condition_check( $args ) {
			return ( is_singular() && ! is_embed() );
		}
		
		public static function get_condition_priority() {
			return 60;
		}
}

new Nexter_Builders_Singular_Conditional_Rules();

/*Singular Posts options_cb */
function nxt_get_type_singular_field( $field ) {
	
	$group_field= $field->args('id');
	$group_field = str_replace('nxt-singular-group_', '', $group_field);	
	$index_id = str_replace('_nxt-singular-conditional-type', '', $group_field);
	
	$group_value = get_post_meta( get_the_ID(), 'nxt-singular-group', true );
	
	$index_id = (!empty($index_id)) ? $index_id : 0;
	$options = '';
	$data = Nexter_Builders_Singular_Conditional_Rules::$Nexter_Singular_Config;
	
	if( !empty( $group_value ) && isset( $group_value[$index_id]['nxt-singular-conditional-rule'] ) ) {
		$value = $group_value[$index_id]['nxt-singular-conditional-rule'];
		if(isset($data[$value])){
			$query = $data[$value];
			$query['rules'] = $value;
			$options = Nexter_Builders_Singular_Conditional_Rules::get_singular_options($query);
		}
	}else {
		$query = $data['post'];
		$query['rules'] = 'post';
		$options = Nexter_Builders_Singular_Conditional_Rules::get_singular_options($query);
	}
	
	return $options;
}

/*
 * Preview Data Post Type Get List Posts
 * @since 1.0.1
 */
function nxt_get_type_singular_preview_id( $field ) {
	
	$type_value = get_post_meta( get_the_ID(), 'nxt-singular-preview-type', true );
	if( !empty( $type_value ) ) {
		$options = Nexter_Builders_Singular_Conditional_Rules::get_post_type_posts_list($type_value);
	}else{
		$options = Nexter_Builders_Singular_Conditional_Rules::get_post_type_posts_list('post');
	}
	
	return $options;
}

if( !class_exists('Nxt_Front_Page') ){
	class Nxt_Front_Page {

		public function get_post_type_name() {
			return 'front_page';
		}
	
		public function get_post_type_label() {
			return __( 'Front Page', 'nexter-extension' );
		}
	
		public static function condition_check( $args = [] ) {
			return is_front_page();
		}
		
		public static function get_condition_priority() {
			return 30;
		}
	}
}

if( !class_exists('Nexter_Post_Singular') ){
	class Nexter_Post_Singular extends Nexter_Builders_Singular_Conditional_Rules {

		public $post_type;

		public function __construct( $data ) {
			$this->post_type = get_post_type_object( $data['post_type'] );
		}

		public function get_post_type_name() {
			return $this->post_type->name;
		}

		public function get_post_type_label() {
			return $this->post_type->labels->singular_name;
		}

		public static function condition_check( $args ) {
			if ( isset( $args['id'] ) ) {
				$post_id = (int) $args['id'];
				if ( $post_id ) {
					return is_singular() && $post_id === get_queried_object_id();
				}
			}
			
			return is_singular($args['post_type']);
		}

		public function query_control(){
			return [
				'object' => 'post',
				'query' => [
					'post_type' => $this->get_post_type_name(),
				],
			];
		}

		public static function get_condition_priority() {
			return 40;
		}
	}
}