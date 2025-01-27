<?php
/**
 * Nexter Builder Pages Loader
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

class Nexter_Builder_Pages_Loader {

	/**
	 * Member Variable
	 */
	private static $instance;		
	private static $template_cache = [];
	private static $condition_rule = '';
	
	/**
	 *  Initiator
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 *  Constructor
	 */
	public function __construct() {}

	/**
	 * Get Template Ids on singular/archives
	 */
	public function get_templates_ids_for_location( $type ) {
	
		if ( isset( self::$template_cache[ $type ] ) ) {
			return self::$template_cache[ $type ];
		}
		
		$templates_ids = $this->get_templates_ids( $type );
		
		self::$template_cache[ $type ] = $templates_ids;
		
		return $templates_ids;
	}
	
	public function get_templates_ids( $singular_archive ) {
	
		$priority_cond = [];
		
		$pages_conditions = Nexter_Builder_Pages_Conditional::nexter_get_pages_singular_archive( 'pages', $singular_archive );
		
		$excludes_ids = [];
		
		if( !empty($pages_conditions) ) {
			if($singular_archive == 'singular'){
				self::$condition_rule = Nexter_Builders_Singular_Conditional_Rules::$load_conditions_rule;
			}
			if($singular_archive == 'archives'){
				self::$condition_rule = Nexter_Builders_Archives_Conditional_Rules::$load_conditions_rule;
			}

			foreach ( $pages_conditions as $template_id => $conditions ) {
				
				foreach( $conditions['template_group'] as $key => $condition ){
				
					if($singular_archive == 'singular'){
						$include = isset($condition['nxt-singular-include-exclude']) ? $condition['nxt-singular-include-exclude'] : '';
						$rule = isset($condition['nxt-singular-conditional-rule']) ? $condition['nxt-singular-conditional-rule'] : '';
						$type = isset($condition['nxt-singular-conditional-type']) ? $condition['nxt-singular-conditional-type'] : [];
					}
					if($singular_archive == 'archives'){
						$include = isset($condition['nxt-archive-include-exclude']) ? $condition['nxt-archive-include-exclude'] : '';
						$rule = isset($condition['nxt-archive-conditional-rule']) ? $condition['nxt-archive-conditional-rule'] : '';
						$type = isset($condition['nxt-archive-conditional-type']) ? $condition['nxt-archive-conditional-type'] : [];
					}
					$get_condition = '';
					
					if ( !empty(self::$condition_rule) && isset(self::$condition_rule) && isset(self::$condition_rule[$rule]) ) {
					
						$check_condition_rule = self::$condition_rule[$rule];
						
						//check post type
						if( isset($check_condition_rule->post_type->name) ){
							$post_type = $check_condition_rule->post_type->name;
						}else{
							$post_type = get_post_type();
						}
						//check taxonomy
						if( isset($check_condition_rule->taxonomy->name) ){
							$taxonomy = $check_condition_rule->taxonomy->name;
						}else{
							$taxonomy = get_post_type();
						}
						
						//check taxonomy terms
						if( isset($check_condition_rule->taxonomy_terms->name) ){
							$taxonomy_terms = $check_condition_rule->taxonomy_terms->name;
						}else{
							$taxonomy_terms = get_post_type();
						}
						
						//check front page
						if( $rule === 'front_page' ){
							$get_condition = $check_condition_rule::condition_check();
						}
					}
					
					if ( isset(self::$condition_rule[$rule]) && !empty($rule) ) {
						if( empty( $type ) ){
							$type = ['all'];
						}
						foreach ($type as $key => $value ){
							if($value == 'all'){
								$value = '';
							}
							
							$args = [
								'id' => $value,
								'post_type' => $post_type,
								'taxonomy' => $taxonomy,
								'taxonomy_terms' => $taxonomy_terms,
							];
							
							$get_condition = $check_condition_rule::condition_check($args);
							
							if ( !empty($get_condition) ) {
							
								$post_status = get_post_status( $template_id );
								if ( $post_status !== 'publish' ) {
									continue;
								}
								
								if ( $include === 'include' ) {
									$priority_cond[ $template_id ] = self::get_condition_rules_priority( $type,$check_condition_rule, $value );
								} else {
									$excludes_ids[] = $template_id;
								}
								
							}
						}
					}
					
					//Front Page
					if ( $get_condition ) {
						$post_status = get_post_status( $template_id );
						if ( $post_status !== 'publish' ) {
							continue;
						}
						
						if ( 'include' === $include ) {
							$priority_cond[ $template_id ] = self::get_condition_rules_priority( $type,$check_condition_rule );									
						} else {
							$excludes_ids[] = $template_id;
						}
					}
					
					
				}//end sub foreach
				
			}//end foreach
		
		}//end pages_conditions
		
		foreach ( $excludes_ids as $exclude_id ) {
			unset( $priority_cond[ $exclude_id ] );
		}
		
		asort( $priority_cond );
		
		return $priority_cond;			
	}
	
	/**
	 * Sorting Templates By Priority
	 */
	private static function get_condition_rules_priority( $type, $check_condition_rule, $value ='' ) {
		$priority = 60;
		if($type=='singular'){
			$priority = Nexter_Builders_Singular_Conditional_Rules::get_condition_priority();
		}
		if($type=='archives'){
			$priority = Nexter_Builders_Archives_Conditional_Rules::get_condition_priority();
		}
		if ( $check_condition_rule ) {
			if ( $check_condition_rule::get_condition_priority() < $priority ) {
				$priority = $check_condition_rule::get_condition_priority();
			}
			
			$priority -= 10;
			
			if ( $value ) {
				$priority -= 10;
			}
		}
		
		return $priority;
	}
}

new Nexter_Builder_Pages_Loader();