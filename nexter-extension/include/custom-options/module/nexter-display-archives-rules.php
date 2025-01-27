<?php
/**
 * Nexter Archives Taxonomy Rules
 *
 * @package Nexter Extensions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Nexter Archives Taxonomy Condition Rules
 */
class Nexter_Builders_Archives_Conditional_Rules {

		/**
		 * Instance
		 */
		private static $instance;

		public static $archive_conditions = [
				'all-archives' => [
					'label' => 'All',
					'value' => [ 'all' => 'All Archives', ]
				],
			];
		
		public static $load_conditions_rule = [];
		
		public static $advanced_archive = [];
		
		/**
		 * Archives Options
		 */
		public static $Nexter_Archives_Config = array();
		
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
			add_action( 'wp', [ $this, 'register_post_type_conditions' ], 0 );
			add_action('wp_ajax_nxt_archive_preview_taxonomy_ajax', [ $this, 'get_terms_by_taxonomy' ] );
		}
		
		/*
		 * Archives Options
		 */
		public static function get_archives_options(array $data) {
			
			$options = array(
				"all" => __("All",'nexter-extension'),
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
		 * Array Sanitize data
		 * @since 1.0.1
		 */
		public static function sanitize_array_recursive( $data ) {
			if ( is_array( $data ) ) {
				foreach ( $data as $key => $value ) {
					$data[ $key ] = self::sanitize_array_recursive( $value );
				}

				return $data;
			}

			return sanitize_text_field( wp_unslash( $data ) );
		}
		
		/*
		 * Get Posts Lists Ids By Post Type
		 * @since 1.0.1
		 */
		public static function get_terms_by_taxonomy( $taxonomy = '' ) {
		
			if(!empty($taxonomy)){
				$data = $taxonomy;
			}else{
				$data = (!empty($_POST['data'])) ? self::sanitize_array_recursive( json_decode( stripslashes_deep( $_POST['data'] ), true ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$data["rules"] = (!empty($_POST['rules'])) ? sanitize_text_field( wp_unslash($_POST['rules']) ) : '';
			}
			
			if(empty($data)){
				return __('Empty Data','nexter-extension');
			}

			$query_data = Nexter_Builder_Pages_Conditional::get_query_singular_archive_data( $data );

			if ( is_wp_error( $query_data ) ) {				
				throw new \Exception( $query_data->get_error_code() . ':' . $query_data->get_error_message() );
			}
			
			$results = [];
			$results_data = [];
			$query_args = $query_data['query'];
			if(!empty($query_data['object'])){
			
				if( $query_data['object'] == 'tax' ) {
				
					$field_name = ! empty( $query_data['field_name'] ) ? $query_data['field_name'] : 'term_taxonomy_id';
					$terms = get_terms( $query_args );
					
					if ( !is_wp_error( $terms ) ) {
						global $wp_taxonomies;
						foreach ( $terms as $term ) {
							if( $data['query']['taxonomy'] == $term->taxonomy ){
								$text = $wp_taxonomies[ $term->taxonomy ]->labels->name . ': ' . $term->name;
								$results[] = [
									'id' => $term->{$field_name},
									'text' => $text,
								];
							}
						}
					}
				}else if( $query_data['object'] == 'author' || $query_data['object'] == 'user' ) {

					$user_query = new \WP_User_Query( $query_args );
					foreach ( $user_query->get_results() as $user ) {
						$results[] = [
							'id' => $user->ID,
							'text' => $user->display_name,
						];
					}
					
				}
			}else{
				$results = false;
			}
			
			if(!empty($results)){
				$results_data['response'] = true;
				$results_data['results'] = $results;				
			}else{
				$results_data['response'] = false;
				$results_data['results'] = $results;				
			}
			
			if(!empty($taxonomy)){
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
		 * Get All Post Type
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

			$post_types = [];

			foreach ( $get_post_types as $post_type => $object ) {
				if($post_type==NXT_BUILD_POST){
					//skip to next iteration if item value is 0
					continue;
				}
				$post_types[ $post_type ] = $object->label;
			}
			
			return apply_filters( 'nexter_get_archive_post_types_list', $post_types );
		}
		
		/*
		 * Register Post Type Condition
		 */
		public static function register_post_type_conditions( $preview ='' ) {
		
			$all_archive = new Nexter_All_Archive();
			self::register_post_sub_condition($all_archive);
			
			self::$advanced_archive = array_merge([ 'nexter_author','nexter_date','nexter_search' ],self::$advanced_archive );

			$advanced_archive = apply_filters( 'nexter_advanced_archives_list', self::$advanced_archive );
			
			if(!empty($advanced_archive)){
				foreach ( $advanced_archive as $label ) {
					$label = __NAMESPACE__ . '\\' . $label;
					if(class_exists($label)){
						$sub_condition = new $label();
						self::register_post_sub_condition($sub_condition);
					}
				}
			}
			
			$post_types = self::get_post_types_list();
			
			foreach ( $post_types as $post_type => $post_label ) {
				
				if ( ! get_post_type_archive_link( $post_type ) ) {
					continue;
				}
				
				$condition = new Nexter_Post_Type_Archive( [
					'post_type' => $post_type,
				] );
				self::$archive_conditions[$post_label] = [ 'label' => $post_label ];
				self::register_post_sub_condition($condition);
				$condition->register_post_type_conditions();
			}
			
			if($preview == 'preview'){
				if( isset(self::$archive_conditions['all-archives']['value']['nxt_date']) ){
					unset( self::$archive_conditions['all-archives']['value']['all'] );
					unset( self::$archive_conditions['all-archives']['value']['nxt_date'] );
					unset( self::$archive_conditions['all-archives']['value']['nxt_search'] );
				}else{
					unset( self::$archive_conditions['all-archives'] );
				}
			}
			
			return  apply_filters( 'Nexter_display_archives_list', self::$archive_conditions );
		}
		
		public static function register_post_sub_condition( $condition ) {
			
			$key = $condition->get_type_name();
			$group = $condition->get_group_name();
			self::$archive_conditions[$group]['value'][$key] = $condition->get_type_label();
			self::$Nexter_Archives_Config[$key] = $condition->query_control();
		
			self::register_post_condition_instance($condition);
		
		}
		
		public static function register_post_condition_instance( $instance ) {
			self::$load_conditions_rule[ $instance->get_type_name() ] = $instance;
		}
		
		public static function get_condition_priority() {
			return 60;
		}
}

new Nexter_Builders_Archives_Conditional_Rules();

/*Archives Posts options_cb */
function nxt_get_type_archives_field( $field ) {
	
	$group_field= $field->args('id');
	$group_field = str_replace('nxt-archive-group_', '', $group_field);	
	$index_id = str_replace('_nxt-archive-conditional-type', '', $group_field);
	
	$group_value = get_post_meta( get_the_ID(), 'nxt-archive-group', true );
	
	$index_id = (!empty($index_id)) ? $index_id : 0;
	$options = '';
	$data = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
	
	if( !empty( $group_value ) && isset( $group_value[$index_id]['nxt-archive-conditional-rule'] ) ){
		
		$value = $group_value[$index_id]['nxt-archive-conditional-rule'];
		if( isset($data[$value]) && !empty($data[$value]["condition_type"]) && $data[$value]["condition_type"]=='yes' ){
			$query = $data[$value];
			$query['rules'] = $value;				
			$options = Nexter_Builders_Archives_Conditional_Rules::get_archives_options($query);
		}else {
			$options = array('all' => __('All','nexter-extension'));
		}
		
	}else{
		$data = $data['all'];
		if(!empty($data["condition_type"]) && $data["condition_type"]=='yes'){
			$query['rules'] = 'all';
			$options = Nexter_Builders_Archives_Conditional_Rules::get_archives_options($query);
		}else{
			$options = array('all' => __('All','nexter-extension'));
		}
	}
	
	return $options;
}

/*
 * Preview Archives Page Id
 * @since 1.0.1
 */
function nxt_get_type_archives_preview_id( $field ){
	
	$archive_value = get_post_meta( get_the_ID(), 'nxt-archive-preview-type', true );
	$data = Nexter_Builders_Archives_Conditional_Rules::$Nexter_Archives_Config;
	
	if( !empty( $archive_value ) && isset($data[$archive_value]) && !empty($data[$archive_value]["condition_type"]) && $data[$archive_value]["condition_type"]=='yes' ){
			$query = $data[$archive_value];
			$query['rules'] = $archive_value;
		$options = Nexter_Builders_Archives_Conditional_Rules::get_terms_by_taxonomy( $query );
	}else{
		$data = $data['category'];
		if(!empty($data["condition_type"]) && $data["condition_type"]=='yes'){
			$query = $data;
			$query['rules'] = 'category';
			$options = Nexter_Builders_Archives_Conditional_Rules::get_terms_by_taxonomy($query);
		}else{
			$options = array( 'all' => __( 'All','nexter-extension' ) );
		}
	}
	if( empty($options) ){
		$options = array( 'all' => __( 'All','nexter-extension' ) );
	}
	
	return $options;
}

if( !class_exists('Nexter_All_Archive')){
	class Nexter_All_Archive {
		
		public function get_type_name() {
			return 'all';
		}
		public function get_group_name() {
			return 'all-archives';
		}

		public function get_type_label() {
			return __( 'All Archives', 'nexter-extension' );
		}

		public static function condition_check( $args ) {
			$check_archive = is_archive() || is_home() || is_search();

			if ( $check_archive && class_exists( 'woocommerce' ) && is_woocommerce() ) {
				$check_archive = true;
			}

			return $check_archive;
		}
		
		public function query_control() {
			return [
				'condition_type' => 'no',
				'query' => [],
			];
		}
		
		public static function get_condition_priority() {
			return 30;
		}
	}
}

if( !class_exists('Nexter_Post_Type_Archive')){
	class Nexter_Post_Type_Archive {

		public $post_type;
		private $taxonomies_list;
		private $child_terms = [];

		public function __construct( $data ) {
			$this->post_type = get_post_type_object( $data['post_type'] );
			$taxonomies = get_object_taxonomies( $data['post_type'], 'objects' );
			$this->taxonomies_list = wp_filter_object_list( $taxonomies, [
				'public' => true,
				'show_in_nav_menus' => true,
			] );
		}

		public function get_type_name() {
			return $this->post_type->name . '_archive';
		}
		public function get_group_name() {
			return $this->post_type->label;
		}
		public function get_type_label() {
			/* translators: %s: Archive */
			return sprintf( __( 'Default %s Archive', 'nexter-extension' ), $this->post_type->label );
		}

		public function register_post_type_conditions() {
		
			foreach ( $this->taxonomies_list as $slug => $object ) {
				$in_taxonomy = new Nexter_Taxonomy( [
					'object' => $object
				], $this->post_type->label );

				Nexter_Builders_Archives_Conditional_Rules::register_post_sub_condition( $in_taxonomy );

				if ( ! $object->hierarchical ) {
					continue;
				}
				
				$child_term = [ 'Nexter_Archives_First_Child_Term', 'Nexter_Archives_All_Child_Term' ];

				foreach ( $child_term as $class_name ) {
					$full_name = __NAMESPACE__ . '\\' . $class_name;
					if(class_exists($full_name)){
						Nexter_Builders_Archives_Conditional_Rules::register_post_sub_condition( new $full_name( [ 'object' => $object ], $this->post_type->label ) );
					}
				}

				$this->child_terms = apply_filters( 'nexter_archives_taxonomy_childs', $object, $this->post_type->label );
			}
		}

		public static function condition_check( $args ) {
			return is_post_type_archive( $args['post_type'] ) || ( 'post' === $args['post_type'] && is_home() );
		}
		
		public function query_control() {
			return [
				'condition_type' => 'no',
				'query' => [],
			];
		}
		
		public static function get_condition_priority() {
			return 70;
		}
	}
}

if( !class_exists('Nexter_Taxonomy')){
	class Nexter_Taxonomy {

		public $taxonomy;
		public $post_label;

		public function __construct( $data, $post_label='' ) {
			
			$this->taxonomy = $data['object'];
			$this->post_label = $post_label;
		}

		public function get_type_name() {
			return $this->taxonomy->name;
		}
		
		public function get_group_name() {
			return $this->post_label;
		}

		public function get_type_label() {
			return $this->post_label.' : '.$this->taxonomy->label;
		}

		public static function condition_check( $args ) {
			$taxonomy = $args['taxonomy'];
			$id = (int) $args['id'];

			if ( $taxonomy === 'category' ) {
				return is_category( $id );
			}

			if ( $taxonomy === 'post_tag' ) {
				return is_tag( $id );
			}

			return is_tax( $taxonomy, $id );
		}

		public function query_control() {
			return [
				'query' => [
					'taxonomy' => $this->taxonomy->name,
				],
				'object' => 'tax',
				'field_name' => 'term_id',
				'condition_type' => 'yes',
			];
		}
		
		public static function get_condition_priority() {
			return 70;
		}
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Archives_First_Child_Term')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Archives_First_Child_Term extends Nexter_Taxonomy {

		public $taxonomy;
		
		public function __construct( $data, $post_label ) {
			parent::__construct( $data, $post_label );
			
			$this->taxonomy = $data['object'];
		}
		
		public function get_type_name() {
			return 'first_child_' . $this->taxonomy->name;
		}

		public function get_type_label() {
			/* translators: %s: Taxonomy Label */
			return sprintf( __( '%1$s : First Child %2$s', 'nexter-extension' ), $this->post_label, $this->taxonomy->labels->singular_name );
		}
		
		public static function is_term_taxonomy($args) {
			$taxonomy = $args['taxonomy'];
			$queried_object = get_queried_object();
			return ( $queried_object && isset( $queried_object->taxonomy ) && $taxonomy === $queried_object->taxonomy );
		}

		public static function condition_check( $args ) {
			$id = (int) $args['id'];
			$queried_object = get_queried_object();

			return self::is_term_taxonomy($args) && $id === $queried_object->parent;
		}
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Archives_All_Child_Term')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Archives_All_Child_Term extends Nexter_Archives_First_Child_Term {

		public $taxonomy;
		
		public function __construct( $data, $post_label ) {
			parent::__construct( $data, $post_label );

			$this->taxonomy = $data['object'];
		}
		
		public function get_type_name() {
			return 'all_child_' . $this->taxonomy->name;
		}

		public function get_type_label() {
			/* translators: %s: Taxonomy Label */
			return sprintf( __( '%1$s : Child %2$s', 'nexter-extension' ), $this->post_label, $this->taxonomy->labels->singular_name );
		}
		
		public static function condition_check( $args ) {
			$id = (int) $args['id'];
			
			$queried_object = get_queried_object();
			if ( ! self::is_term_taxonomy($args) || 0 === $queried_object->parent ) {
				return false;
			}

			while ( $queried_object->parent > 0 ) {
				if ( $id === $queried_object->parent ) {
					return true;
				}
				$queried_object = get_term_by( 'id', $queried_object->parent, $queried_object->taxonomy );
			}

			return $id === $queried_object->parent;
		}
	}
}
