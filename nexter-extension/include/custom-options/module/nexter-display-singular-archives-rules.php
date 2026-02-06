<?php
/**
 * Nexter Pro Advanced Singular/Archives Rules
 *
 * @package	Nexter Pro Extensions
 * @since  1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Nexter_Singular_Archives_Rules {
		
	/**
	 * Instance
	 */
	private static $instance;

	/**
	 * Get Instance
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
		add_action('wp_ajax_nxt_singular_archives_filters_ajax', [ $this, 'nexter_ajax_singular_archives_filters' ] );
	}
	
	public static function sanitize_array_recursive( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::sanitize_array_recursive( $value );
			}

			return $data;
		}

		return sanitize_text_field( wp_unslash( $data ) );
	}
	
	//Ajax Get Data Singular/Archive Condition Rules
	public static function nexter_ajax_singular_archives_filters( $get_data ='' ){
		
		if(!empty($get_data)){
			$data = $get_data;
		}else{
			$data = (!empty($_POST['data'])) ? self::sanitize_array_recursive( json_decode( stripslashes_deep( $_POST['data'] ), true ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// ensure we always work with an array to avoid string offset notices
			$data = is_array( $data ) ? $data : [];
			$data["rules"] = (isset($_POST['rules']) && !empty($_POST['rules'])) ? sanitize_text_field( wp_unslash($_POST['rules']) ) : '';
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
		
		//Query Type Taxonomy/Attachment/Posts/Authors/user
		if(!empty($query_data['object'])){
		
			if( $query_data['object'] == 'tax' ) {
			
				$field_name = ! empty( $query_data['field_name'] ) ? $query_data['field_name'] : 'term_taxonomy_id';
				$terms = get_terms( $query_args );					
				if ( !is_wp_error( $terms ) ) {
					global $wp_taxonomies;
					foreach ( $terms as $term ) {
						if( $data['query']['taxonomy'] == $term->taxonomy ){
							$term_name = self::nexter_term_name_with_parents( $term );
							$text = $wp_taxonomies[ $term->taxonomy ]->labels->name . ': ' . $term_name;
							$results[] = [
								'id' => $term->{$field_name},
								'text' => $text,
							];
						}
					}
				}
			}else if( $query_data['object'] == 'post' ) {
			
				$post_query = new \WP_Query( $query_args );

				foreach ( $post_query->posts as $post ) {
					$post_type_obj = get_post_type_object( $post->post_type );
					$text = ( $post_type_obj->hierarchical ) ? self::nexter_post_name_with_parents( $post ) : $post->post_title;
						$results[] = [
							'id' => $post->ID,
							'text' => $text,
						];
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
		
		if(!empty($get_data)){
			return json_encode($results_data);
		}else{
			echo json_encode($results_data);
		}
		
		wp_die();
	}

	/**
	 * get Term name with parents
	 */
	private static function nexter_term_name_with_parents( \WP_Term $term, $max = 3 ) {
		if ( $term->parent === 0 ) {
			return $term->name;
		}
		
		$check_term = $term;
		$names = [];
		while ( $check_term->parent > 0 ) {
			$check_term = get_term_by( 'term_taxonomy_id', $check_term->parent );
			if ( ! $check_term ) {
				break;
			}
			$names[] = $check_term->name;
		}
		
		$sign_sep = is_rtl() ? ' < ' : ' > ';
		
		$names = array_reverse( $names );
		if ( count( $names ) < ( $max ) ) {
			return implode( $sign_sep, $names ) . $sign_sep . $term->name;
		}

		$nameString = '';
		for ( $j = 0; $j < ( $max - 1 ); $j++ ) {
			$nameString .= $names[ $j ] . $sign_sep;
		}
		return $nameString . '...' . $sign_sep . $term->name;
	}
	
	/**
	 * get post name with parents
	 */
	private static function nexter_post_name_with_parents( $post, $max = 3 ) {
		if ( $post->post_parent === 0 ) {
			return $post->post_title;
		}
		
		$check_post = $post;
		$names = [];
		while ( $check_post->post_parent > 0 ) {
			$check_post = get_post( $check_post->post_parent );
			if ( ! $check_post ) {
				break;
			}
			$names[] = $check_post->post_title;
		}
		
		$sign_sep = is_rtl() ? ' < ' : ' > ';
		
		$names = array_reverse( $names );
		if ( count( $names ) < ( $max ) ) {
			return implode( $sign_sep, $names ) . $sign_sep . $post->post_title;
		}

		$nameString = '';
		for ( $j = 0; $j < ( $max - 1 ); $j++ ) {
			$nameString .= $names[ $j ] . $sign_sep;
		}
		return $nameString . '...' . $sign_sep . $post->post_title;
	}
	
	public static function nexter_post_singular_sub_conditions( $post_type ){
	
		$post_type_object = get_post_type_object( $post_type );
		$object_taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$post_taxonomies = wp_filter_object_list( $object_taxonomies, [
			'public' => true,
			'show_in_nav_menus' => true,
		] );
		
		foreach ( $post_taxonomies as $slug => $object ) {
			if(class_exists('Nexter_Singular_Taxonomy')){
				$in_taxonomy = new Nexter_Singular_Taxonomy( [
					'object' => $object,
				] );
				Nexter_Builders_Singular_Conditional_Rules::register_post_sub_condition( $in_taxonomy, $post_type );
			}
			
			if ( $object->hierarchical && class_exists('Nexter_Singular_Sub_Term')) {
				$in_sub_term = new Nexter_Singular_Sub_Term( [
					'object' => $object,
				] );
				Nexter_Builders_Singular_Conditional_Rules::register_post_sub_condition( $in_sub_term , $post_type);				
			}
		}
		if(class_exists('Nexter_Singular_Post_By_Author')){
			$by_author = new Nexter_Singular_Post_By_Author( $post_type_object );
			Nexter_Builders_Singular_Conditional_Rules::register_post_sub_condition( $by_author , $post_type );
		}
	}
	
}

new Nexter_Singular_Archives_Rules();

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Singular_Taxonomy')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){

	class Nexter_Singular_Taxonomy {

		public $taxonomy;
		private static $taxonomy_data;
		public static $post_type_data;
	
		public function __construct( $data ) {
			$this->taxonomy = $data['object'];
			self::$taxonomy_data = $data['object'];
			if(!empty($this->taxonomy->object_type) && $this->taxonomy->object_type[0]){
				self::$post_type_data = get_post_type_object( $this->taxonomy->object_type[0] );
			}
		}
	
		public function get_post_type_name() {
			$post_name = '';
			if(isset(self::$post_type_data) && self::$post_type_data->name){
				$post_name = self::$post_type_data->name;
			}
			return $post_name .'_'. $this->taxonomy->name;
		}
	
		public function get_post_type_label() {
			$post_name = '';
			if(isset(self::$post_type_data) && self::$post_type_data->label){
				$post_name = self::$post_type_data->label;
			}
			/* translators: %s: Taxonomy Label */
			return sprintf( __( '%1$s : %2$s', 'nexter-extension' ), $post_name ,$this->taxonomy->labels->singular_name );
		}
		
		public static function condition_check( $args ) {
			return is_singular() && has_term( (int) $args['id'], $args['taxonomy'] );
		}
		
		public static function query_control(){
			return [
				'query' => [
					'taxonomy' => self::$taxonomy_data->name,
				],
				'display' => 'detailed',
				'field_name' => 'term_id',
				'object' => 'tax',
			];
		}
		
		public static function get_condition_priority() {
			return 40;
		}
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Singular_Sub_Term')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Singular_Sub_Term extends Nexter_Singular_Taxonomy {

		public $taxonomy_terms;

		public function __construct( $data ) {
			$this->taxonomy_terms = $data['object'];
		}

		public function get_post_type_name() {
			$post_name = '';
			if(isset(self::$post_type_data) && self::$post_type_data->name){
				$post_name = self::$post_type_data->name;
			}
			return $post_name.'_child_' . $this->taxonomy_terms->name;
		}

		public function get_post_type_label() {
			$post_name = '';
			if(isset(self::$post_type_data) && self::$post_type_data->label){
				$post_name = self::$post_type_data->label;
			}
			/* translators: %s: Taxonomy terms */
			return sprintf( __( '%1$s : Child %2$s', 'nexter-extension' ), $post_name, $this->taxonomy_terms->labels->name );
		}
		
		public static function condition_check( $args ) {
			$id = (int) $args['id'];
			if ( ! is_singular() || ! $id ) {
				return false;
			}
			
			$child_terms = get_term_children( $id, $args['taxonomy_terms'] );

			return ! empty( $child_terms ) && has_term( $child_terms, $args['taxonomy_terms'] );
		}
		
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Singular_Post_By_Author')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Singular_Post_By_Author {

		public $post_type;

		public function __construct( $post_type ) {
			$this->post_type = $post_type;
		}

		public function get_post_type_name() {
			return $this->post_type->name . '_by_author';
		}

		public function get_post_type_label() {
			/* translators: %s: By Author */
			return sprintf( __( '%s : By Author', 'nexter-extension' ), $this->post_type->label );
		}
		
		public static function condition_check( $args = null ) {
			return is_singular( $args['post_type'] ) && get_post_field( 'post_author' ) === $args['id'];
		}
		
		public static function query_control() {
			return [
				'query' => [],
				'object' => 'author',
			];
		}
		
		public static function get_condition_priority() {
			return 40;
		}
	}
}


/*Archives Posts*/
if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Author')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Author {

		public function get_type_name() {
			return 'nxt_author';
		}
		
		public function get_group_name() {
			return 'all-archives';
		}

		public function get_type_label() {
			return __( 'Author Archive', 'nexter-extension' );
		}

		public static function condition_check( $args = null ) {
			return is_author( $args['id'] );
		}

		public function query_control() {
			return [
				'query' => [],
				'object' => 'author',			
				'condition_type' => 'yes',
			];
		}
		
		public static function get_condition_priority() {
			return 70;
		}
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Date')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Date {

		public function get_type_name() {
			return 'nxt_date';
		}
		
		public function get_group_name() {
			return 'all-archives';
		}
		
		public function get_type_label() {
			return __( 'Date Archive', 'nexter-extension' );
		}

		public static function condition_check( $args ) {
			return is_date();
		}
		
		public function query_control() {
			return [
				'query' => [],
				'condition_type' => 'no',
			];
		}
		
		public static function get_condition_priority() {
			return 70;
		}
	}
}

if( (!defined('NXT_PRO_EXT_VER') && !class_exists('Nexter_Search')) || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '2.0.4', '>' )) ){
	class Nexter_Search {

		public function get_type_name() {
			return 'nxt_search';
		}
		
		public function get_group_name() {
			return 'all-archives';
		}
		
		public function get_type_label() {
			return __( 'Search Results', 'nexter-extension' );
		}

		public static function condition_check( $args ) {
			return is_search();
		}
		
		public function query_control() {
			return [
				'query' => [],
				'condition_type' => 'no',
			];
		}
		
		public static function get_condition_priority() {
			return 70;
		}
	}
}