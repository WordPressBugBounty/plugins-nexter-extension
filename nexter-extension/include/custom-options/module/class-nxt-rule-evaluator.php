<?php
/**
 * Rule Evaluator
 *
 * Evaluates display include/exclude conditional rules for templates
 * and sections. Extracted from Nexter_Builder_Display_Conditional_Rules
 * to isolate the rule-evaluation concern.
 *
 * @package Nexter Extension
 * @since   4.6.4
 */
defined( 'ABSPATH' ) || exit;

class Nxt_Rule_Evaluator {

	/**
	 * Reference to the parent conditional rules instance.
	 *
	 * @var Nexter_Builder_Display_Conditional_Rules
	 */
	private $parent;

	/**
	 * @param Nexter_Builder_Display_Conditional_Rules $parent Parent instance for static data access.
	 */
	public function __construct( $parent ) {
		$this->parent = $parent;
	}

	/**
	 * Checks for Current Page By Display Condition Rules.
	 *
	 * @since 1.0.0
	 *
	 * @param int|false $post_id    Current post ID.
	 * @param array     $conditions Conditions array.
	 * @return bool
	 */
	public function check_layout_display_inc_exc_rules( $post_id, $conditions ) {

		$current_post_type = get_post_type( $post_id );
		$display           = false;

		if ( isset( $conditions ) && is_array( $conditions ) && ! empty( $conditions ) ) {

			foreach ( $conditions as $key => $condition ) {

				if(is_array($condition) && isset($condition['value'])){
					if(strrpos( $condition['value'], 'entire' ) !== false){
						$check_cond = 'entire';
					}else{
						$check_cond = $condition['value'];
					}
				}else if ( !is_array($condition) && strrpos( $condition, 'entire' ) !== false ) {
					$check_cond = 'entire';
				} else {
					$check_cond = $condition;
				}

				if( !empty($check_cond) ){
					if( $check_cond == 'standard-universal' ){
						$display = true;
					}else if( $check_cond == 'entire' ){

						// Fix: Handle array condition properly before explode
						$condition_value = $condition;
						if (is_array($condition) && isset($condition['value'])) {
							$condition_value = $condition['value'];
						}

						// Ensure we have a string before using explode
						if (!is_string($condition_value)) {
							continue; // Skip this condition if it's not a string
						}

						$condition_data = explode( '|', $condition_value );

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
							$display = Nexter_Builder_Display_Conditional_Rules::check_condition_set_day( $conditions['set-day'], $display );
						}else if($check_cond == 'os' && isset( $conditions['os'] ) && is_array( $conditions['os'] ) ) {
							$display = Nexter_Builder_Display_Conditional_Rules::check_condition_os($conditions['os'], $display);
						}else if($check_cond == 'browser' && isset( $conditions['browser'] ) && is_array( $conditions['browser'] ) ) {
							$display = Nexter_Builder_Display_Conditional_Rules::check_condition_browser($conditions['browser'], $display);
						}else if($check_cond == 'login-status' && isset( $conditions['login-status'] ) && is_array( $conditions['login-status'] ) ) {
							$display = Nexter_Builder_Display_Conditional_Rules::check_condition_login_status($conditions['login-status'], $display);
						}else if($check_cond == 'user-roles' && isset( $conditions['user-roles'] ) && is_array( $conditions['user-roles'] ) ) {
							$display = Nexter_Builder_Display_Conditional_Rules::check_condition_user_roles($conditions['user-roles'], $display);
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

	/**
	 * Remove Template Exclude Locations Conditional Rules.
	 *
	 * @param string $type    Template type key in $current_load_page_data.
	 * @param array  $options Options containing 'exclusion' key and 'current_post_id'.
	 */
	public function remove_templates_excludes_conditional_rules( $type, $options ) {

		$exclusion       = isset( $options['exclusion'] ) ? $options['exclusion'] : '';
		$current_post_id = isset( $options['current_post_id'] ) ? $options['current_post_id'] : false;

		foreach ( Nexter_Builder_Display_Conditional_Rules::$current_load_page_data[ $type ] as $c_post_id => $c_data ) {

			$exclusion_rules = get_post_meta( $c_post_id, $exclusion, true );
			if( !empty($exclusion_rules) && !empty($c_post_id)){
				$code_condition = [];
				$get_sub_field = [];
				if(isset($exclusion_rules[0]) && isset($exclusion_rules[0]['value'])){
					$code_condition = array_column($exclusion_rules, 'value');
					$get_sub_field = get_post_meta( $c_post_id, 'nxt-ex-sub-rule', true );
				}

				if(!empty($code_condition) && in_array('particular-post',$code_condition) && !empty($get_sub_field) && isset($get_sub_field['specific'])){
					$exclusion_rules['specific'] = array_column($get_sub_field['specific'], 'value');
				}else if( !empty($exclusion_rules) && in_array('particular-post',$exclusion_rules) ){
					$exclusion_rules['specific'] = get_post_meta( $c_post_id, 'nxt-hooks-layout-exclude-specific', true );
				}

				$exclude_array = [ 'set-day', 'os', 'browser', 'login-status', 'user-roles' ];

				foreach ($exclude_array as $exclude) {
					if(!empty($code_condition) && !empty($get_sub_field) && isset($get_sub_field[$exclude]) ){
						$exclusion_rules[$exclude] = array_column($get_sub_field[$exclude], 'value');
					}else if( !empty($exclusion_rules) && in_array($exclude, $exclusion_rules) ){
						$exclusion_rules[$exclude]   = get_post_meta( $c_post_id, 'nxt-hooks-layout-exclude-'.$exclude, true );
					}
				}
			}

			$exclusion_rules = apply_filters( 'nexter_advanced_section_exclude_condition', $exclusion_rules, $c_post_id );

			$exclude_id = $this->check_layout_display_inc_exc_rules( $current_post_id, $exclusion_rules );

			if ( $exclude_id ) {
				unset( Nexter_Builder_Display_Conditional_Rules::$current_load_page_data[ $type ][ $c_post_id ] );
			}
		}
	}

	/**
	 * Sort data array by priority.
	 *
	 * @param array $data_array Data to sort.
	 * @param string $on        Key to sort by.
	 * @param int    $order     SORT_ASC or SORT_DESC.
	 * @return array Sorted array (only items with condition==1).
	 */
	public function array_sort_by_priority( $data_array, $on, $order = SORT_ASC ) {

		$new_array     = [];
		$sorting_array = [];

		if ( count( $data_array ) > 0 ) {
			foreach ( $data_array as $key => $val ) {
				if ( is_array( $val ) ) {
					foreach ( $val as $k2 => $v2 ) {
						if ( $k2 == $on ) {
							$sorting_array[ $key ] = $v2;
						}
					}
				} else {
					$sorting_array[ $key ] = $val;
				}
			}

			switch ( $order ) {
				case SORT_ASC:
					asort( $sorting_array );
					break;
				case SORT_DESC:
					arsort( $sorting_array );
					break;
			}

			foreach ( $sorting_array as $key => $val ) {
				if ( isset( $data_array[ $key ]['condition'] ) && $data_array[ $key ]['condition'] == 1 ) {
					$new_array[ $key ] = $data_array[ $key ];
				}
			}
		}

		return $new_array;
	}
}
