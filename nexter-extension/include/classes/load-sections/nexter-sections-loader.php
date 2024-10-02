<?php
/**
 * Nexter Builder Hooks Loader
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if ( ! class_exists( 'Nexter_Builder_Hooks_Loader' ) ) {

	class Nexter_Builder_Hooks_Loader {


		/**
		 * Member Variable
		 */
		private static $instance;

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
		public function __construct() {

			if ( is_admin() ) {
				add_filter( 'parse_query', array( $this,'nexter_sections_pages_query_filter') );
				add_filter( 'manage_' . NXT_BUILD_POST . '_posts_columns', array( $this, 'nxt_column_headings' ) );
				add_action( 'manage_' . NXT_BUILD_POST . '_posts_custom_column', array( $this, 'nxt_column_content' ), 10, 2 );
				
				add_filter( 'views_edit-'.NXT_BUILD_POST, array( $this,'nexter_admin_print_view_tabs') );
			}

		}
		
		/**
		 * Filter nexter builder sections/pages types in admin query
		 *
		 * Fired by `parse_query` action
		 */
		public function nexter_sections_pages_query_filter( $query ) {
		  global $pagenow;
		 
			// Get the post type
			$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
			 
			if ( is_admin() && $pagenow=='edit.php' && $post_type == NXT_BUILD_POST && isset( $_GET['nxt_type'] ) && $_GET['nxt_type'] !='all' ) {
				$query->query_vars['meta_key'] = 'nxt-hooks-layout';
				$query->query_vars['meta_value'] = sanitize_text_field( wp_unslash( $_GET['nxt_type'] ) );
				$query->query_vars['meta_compare'] = '=';
			}
		}
		
		/**
		 * Nexter Builder views admin tabs.
		 *
		 * Fired by `views_edit-nxt_builder` filter.
		 */
		public function nexter_admin_print_view_tabs( $views ) {
			$view_type = '';
			$active_tab = ' nav-tab-active';
			

			if ( ! empty( $_REQUEST[ 'nxt_type' ] ) ) {
				$view_type = sanitize_text_field( wp_unslash( $_REQUEST[ 'nxt_type' ] ) );
				$active_tab = '';
			}

			$url_args = [
				'post_type' => NXT_BUILD_POST,		
			];

			$baseurl = add_query_arg( $url_args, admin_url( 'edit.php' ) );
			
				echo '<div id="nxt-builder-tabs-wrapper" class="nav-tab-wrapper">
					<a class="nav-tab'.esc_attr($active_tab).'" href="'.esc_url($baseurl).'">'.esc_html__( 'All', 'nexter-ext' ).'</a>';
					
					$nxt_type = [
						'sections' => __( 'Sections', 'nexter-ext' ),
						'pages' => __( 'Pages', 'nexter-ext' ),
					];
					
					foreach ( $nxt_type as $type => $label ) :
						$active_tab = '';

						if ( $view_type === $type ) {
							$active_tab = 'nav-tab-active';
						}

						$type_url = add_query_arg( 'nxt_type', $type, $baseurl );

						echo '<a class="nav-tab '.esc_attr($active_tab).'" href="'.esc_url($type_url).'">'.esc_html($label).'</a>';
					endforeach;
					
				echo '</div>';
			
			return $views;
		}
		
		/**
		 * Nexter builder manage post list table column headings
		 * @since 1.0.7
		 */
		public static function nxt_column_headings( $columns ) {

			unset( $columns['date'] );

			//$columns['nxt_active_deactive']	= __( 'Active/Deactive', 'nexter-ext' );
			$columns['sections_pages_action']	= __( 'Action', 'nexter-ext' );
			$columns['display_rules']	= __( 'Display Rules', 'nexter-ext' );
			$columns['date']	= __( 'Date', 'nexter-ext' );

			return apply_filters( 'nexter_builder_column_headings', $columns );
		}

		/**
		 * Nexter builder posts Adds Column Content
		 * @since 1.0.7
		 */
		public function nxt_column_content( $column, $post_id ) {
			
			/*if( $column == 'nxt_active_deactive' ){
				$query_args = array( 'action' => 'activate', 'id' => $post_id );
				$url = add_query_arg( $query_args );
				
				// add a nonce to the URL
				$url = wp_nonce_url( $url, 'nxt_activate_deactivate' . $post_id );
		
				echo sprintf(
						'<a class="%s" href="%s" title="%s"></a> ',
						'nxt-activation-switcher', esc_url( $url ), esc_html( 'Activate' )
					);
			}else 
			*/
			if ( $column == 'sections_pages_action' ) {
			
				$layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
				if( $layout === 'sections' ){
					$sections_pages = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );					
				}else if( $layout === 'pages' ){
					$sections_pages = get_post_meta( $post_id, 'nxt-hooks-layout-pages', true );
				}else if( $layout === 'code_snippet' ){
					$sections_pages = esc_html__('Snippet : ','nexter-ext') . get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', true );
				}else{
					$sections_pages = __('None','nexter-ext');
				}
				
				echo apply_filters( 'nexter_builder_column_content', $sections_pages );	// phpcs:ignore
				
			} elseif ( $column == 'display_rules' ) {
			
				$layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
				
				//Display Sections Column data
				if($layout === 'sections'){
				
					$sections_layout = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );
					if(!empty($sections_layout) && $sections_layout!='none'){
						echo wp_kses_post($this->nxt_sections_display_rules( $post_id ));
					}else{
						echo esc_html__('None','nexter-ext');
					}
				}else if( $layout === 'pages' ){
				//Display Pages Column data
					$layout_pages = get_post_meta( $post_id, 'nxt-hooks-layout-pages', true );
					$load_actions = Nexter_Builder_Pages_Conditional::nexter_get_pages_singular_archive( 'pages', $layout_pages );
					
					if( !empty($load_actions) ) {
						foreach ( $load_actions as $template_id => $actions ) {
							if($post_id===$template_id){
								foreach( $actions['template_group'] as $key => $action ){
									if($layout_pages == 'singular'){
										$include = isset($action['nxt-singular-include-exclude']) ? $action['nxt-singular-include-exclude'] : '';
										$rule = isset($action['nxt-singular-conditional-rule']) ? $action['nxt-singular-conditional-rule'] : '';
										$type = isset($action['nxt-singular-conditional-type']) ? $action['nxt-singular-conditional-type'] : [];
									}
									if($layout_pages == 'archives'){
										$include = isset($action['nxt-archive-include-exclude']) ? $action['nxt-archive-include-exclude'] : '';
										$rule = isset($action['nxt-archive-conditional-rule']) ? $action['nxt-archive-conditional-rule'] : '';
										$type = isset($action['nxt-archive-conditional-type']) ? $action['nxt-archive-conditional-type'] : [];
									}
									
									if(!empty($include)){
										echo '<div class="nxt-sections-add-display-wrap" style="margin-bottom: 5px;text-transform:capitalize;">';
											echo '<strong>'.esc_html__('Display :','nexter-ext').' </strong>'.esc_html( $include );
											if(!empty($rule) && $layout_pages == 'singular'){
												$rule_name = ($post_obj = get_post_type_object( $rule )) ? $post_obj->labels->name : $rule;
												echo '</br><strong>'.esc_html__('Rule :','nexter-ext').' </strong>'.esc_html( $rule_name );
											}
											if(!empty($rule) && $layout_pages == 'archives'){
												$taxonomy_obj = ($rule==='all') ? __('All','nexter-ext') : ((get_taxonomy($rule)) ? get_taxonomy($rule)->labels->name : $rule);
												echo '</br><strong>'.esc_html__('Rule :','nexter-ext').' </strong>'.esc_html( $taxonomy_obj );
											}
											if(!empty($type)){
												$type_value = implode(', ', $type);
												echo '</br><strong>'.esc_html__('Type :','nexter-ext').' </strong>'.esc_html( $type_value );
											}
										echo '</div>';
									}
								}
							}
						}
					}
				}else if($layout === 'code_snippet'){
				
					$code_layout = get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', true );
					if(!empty($code_layout) && $code_layout!='php'){
						if($code_layout =='html'){
							$html_hook = get_post_meta( $post_id, 'nxt-code-hooks-action', true );
							if(!empty($html_hook)){
								echo '<strong>'.esc_html__('Hooks : ','nexter-ext').' </strong>'. wp_kses_post($html_hook);
							}
						}
						echo wp_kses_post($this->nxt_sections_display_rules( $post_id ));
					}else if(!empty($code_layout) && $code_layout=='php'){
						$php_action = get_post_meta( $post_id, 'nxt-code-execute', true );
						if(!empty($php_action)){
							echo '<strong>'.esc_html__('Actions : ','nexter-ext').' </strong>'. wp_kses_post($php_action);
						}
					}else{
						echo esc_html__('None','nexter-ext');
					}
				}else{
					echo esc_html__('None','nexter-ext');
				}
			}
		}
		
		/*
		 * Get Include/Exclude for Display Rule column
		 * @since 1.0.4
		 */
		public function nxt_sections_display_rules( $post_id = ''){
			$output = '';
			if(!empty($post_id)){
				$sections_include = get_post_meta( $post_id, 'nxt-add-display-rule', true );
				if ( ! empty( $sections_include ) ) {
					$output .= '<div class="nxt-sections-add-display-wrap" style="margin-bottom: 5px;text-transform:capitalize;">';
						$output .= '<strong>'.esc_html__('Display:','nexter-ext').' </strong>';
						$output .= $this->nxt_column_sections_rules( $sections_include, $post_id, 'include' );
					$output .= '</div>';
				}

				$sections_exclude = get_post_meta( $post_id, 'nxt-exclude-display-rule', true );
				if ( ! empty( $sections_exclude ) ) {
					$output .= '<div class="nxt-sections-excluse-display-wrap" style="margin-bottom: 5px;text-transform:capitalize;">';
						$output .= '<strong>'.esc_html__('Exclusion:','nexter-ext').' </strong>';
						$output .= $this->nxt_column_sections_rules( $sections_exclude, $post_id, 'exclude' );
					$output .= '</div>';
				}
			}
			return $output;
		}
		/**
		 * Get Sections rules for Display rule column.
		 *
		 * @param array $sections Array of sections.
		 * @since 1.0.4
		 * @return void
		 */
		public function nxt_column_sections_rules( $sections, $post_id='', $include_exclude='' ) {
			
			$sections_value = [];
			$output = '';
			if ( isset( $sections ) && is_array( $sections ) ) {
				foreach ( $sections as $section ) {
					$sections_value[] = Nexter_Builder_Display_Conditional_Rules::display_label_location_by_key( $section );
				}
			}

			$output .= implode(', ', $sections_value); // phpcs:ignore

			if(!empty($sections)){
				$particular_posts = array_search( 'particular-post', $sections);
				if ( $particular_posts !== false && !empty($post_id) && $include_exclude=='include' ) {
					$specific = get_post_meta( $post_id, 'nxt-hooks-layout-specific', true );
				}
				if ( $particular_posts !== false && !empty($post_id) && $include_exclude=='exclude' ) {
					$specific = get_post_meta( $post_id, 'nxt-hooks-layout-exclude-specific', true );
				}

				$specific_value = [];
				if ( isset( $specific ) && is_array( $specific ) ) {
					foreach ( $specific as $section ) {
						$specific_value[] = Nexter_Builder_Display_Conditional_Rules::display_label_location_by_key( $section );
					}
				}

				if(!empty($include_exclude) && !empty($specific_value) && is_array($specific_value) ) {
					$output .= wp_kses_post( '</br><strong>'.esc_html__('Specific','nexter-ext').' '.ucwords($include_exclude).': </strong> '.implode(', ', $specific_value) );
				}
			}

			$other_val = apply_filters( 'nexter_display_sections_specific_value', $sections, $post_id, $include_exclude );
			
			if(!empty($other_val) && !is_array($other_val)){
				$output .= wp_kses_post( $other_val );
			}

			return $output;
		}

	}
}

Nexter_Builder_Hooks_Loader::get_instance();