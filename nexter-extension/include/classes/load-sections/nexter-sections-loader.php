<?php
/**
 * Nexter Builder Hooks Loader
 *
 * @package Nexter Extensions
 * @since 1.0.0
 */

if (!class_exists('Nexter_Builder_Hooks_Loader')) {

	class Nexter_Builder_Hooks_Loader
	{


		/**
		 * Member Variable
		 */
		private static $instance;

		/**
		 *  Initiator
		 */
		public static function get_instance()
		{
			if (!isset(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 *  Constructor
		 */
		public function __construct()
		{

			if (is_admin()) {
				add_filter('parse_query', array($this, 'nexter_sections_pages_query_filter'));
				add_filter('manage_' . NXT_BUILD_POST . '_posts_columns', array($this, 'nxt_column_headings'));
				add_action('manage_' . NXT_BUILD_POST . '_posts_custom_column', array($this, 'nxt_column_content'), 10, 2);

				add_filter('views_edit-' . NXT_BUILD_POST, array($this, 'nexter_admin_print_view_tabs'));
			}

		}

		/**
		 * Filter nexter builder sections/pages types in admin query
		 *
		 * Fired by `parse_query` action
		 */
		public function nexter_sections_pages_query_filter($query)
		{
			global $pagenow;

			// Get the post type
			$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';

			if (is_admin() && $pagenow == 'edit.php' && $post_type == NXT_BUILD_POST && isset($_GET['nxt_type']) && $_GET['nxt_type'] != 'all') {
				$nxt_type= sanitize_text_field(wp_unslash($_GET['nxt_type']));
				$meta_query = [
					'relation' => 'OR',
					[
						'key' => 'nxt-hooks-layout',
						'value' => $nxt_type,
        				'compare' => '=',
					],
					[
						'key' => 'nxt-hooks-layout-sections',
						'value' => $nxt_type,
						'compare' => '=',
					],
					[
						'key' => 'nxt-hooks-layout-pages',
						'value' => $nxt_type,
						'compare' => '='
					]
				];

				if($nxt_type=='sections'){
					$meta_query[] = [
						'key' => 'nxt-hooks-layout-sections',
						'value' => ['header', 'footer','breadcrumb','hooks'],
						'compare' => 'IN'
					];
				}
			
				$query->set('meta_query', $meta_query);
			}
		}

		/**
		 * Nexter Builder views admin tabs.
		 *
		 * Fired by `views_edit-nxt_builder` filter.
		 */
		public function nexter_admin_print_view_tabs($views){
			$view_type = '';
			$active_tab = ' nav-tab-active';

			if (!empty($_REQUEST['nxt_type'])) {
				$view_type = sanitize_text_field(wp_unslash($_REQUEST['nxt_type']));
				$active_tab = '';
			}

			$url_args = [
				'post_type' => NXT_BUILD_POST,
			];

			$baseurl = add_query_arg($url_args, admin_url('edit.php'));

			echo '<div id="nxt-builder-tabs-wrapper" class="nav-tab-wrapper">
					<a class="nav-tab' . esc_attr($active_tab) . '" href="' . esc_url($baseurl) . '">' . esc_html__('All', 'nexter-extension') . '</a>';

			$nxt_type = [
				'sections' => __('Sections', 'nexter-extension'),
				'header' => __('Header', 'nexter-extension'),
				'footer' => __('Footer', 'nexter-extension'),
				'breadcrumb' => __('Breadcrumb', 'nexter-extension'),
				'hooks' => __('Hooks', 'nexter-extension'),
				'singular' => __('Single', 'nexter-extension'),
				'archives' => __('Archive', 'nexter-extension'),
				'page-404' => __('404', 'nexter-extension'),
			];

			foreach ($nxt_type as $type => $label):
				$active_tab = '';

				if ($view_type === $type) {
					$active_tab = 'nav-tab-active';
				}

				$type_url = add_query_arg('nxt_type', $type, $baseurl);

				echo '<a class="nav-tab ' . esc_attr($active_tab) . '" href="' . esc_url($type_url) . '">' . esc_html($label) . '</a>';
			endforeach;

			echo '</div>';

			return $views;
		}

		/**
		 * Nexter builder manage post list table column headings
		 * @since 1.0.7
		 */
		public static function nxt_column_headings($columns) {

			unset($columns['date']);
			$columns['sections_pages_action'] = __('Type', 'nexter-extension');
			$columns['display_rules'] = __('Conditions', 'nexter-extension');
			$columns['date'] = __('Date', 'nexter-extension');
			$columns['author'] = __('Author', 'nexter-extension');
			$columns['status'] = __('Status', 'nexter-extension');

			return apply_filters('nexter_builder_column_headings', $columns);
		}

		/**
		 * Nexter builder posts Adds Column Content
		 * @since 1.0.7
		 */
		public function nxt_column_content($column, $post_id) {

			if ($column == 'sections_pages_action') {

				// OLD Format
				$old_layout = get_post_meta($post_id, 'nxt-hooks-layout', true);
				
				$layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
				$sections_pages = '';
				$nxt_type = (!empty($layout)) ? $layout : '';
				if(!empty($old_layout)){
					if($old_layout == 'sections'){
						$sections_pages = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
						$nxt_type = 'sections';
					}else if($old_layout == 'pages'){
						$sections_pages = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
						$nxt_type = 'pages';
					}else if($old_layout == 'code_snippet'){
						$sections_pages = esc_html__('Snippet : ', 'nexter-extension').get_post_meta($post_id, 'nxt-hooks-layout-code-snippet', true);
						$nxt_type = 'code_snippet';
					}else{
						$sections_pages = __('None', 'nexter-extension');
					}
				}else if( $layout === 'header' ) {
					$sections_pages = __('Header', 'nexter-extension');
				}else if( $layout === 'footer' ){
					$sections_pages = __('Footer', 'nexter-extension');
				}else if( $layout === 'breadcrumb' ){
					$sections_pages = __('Breadcrumb', 'nexter-extension');
				}else if( $layout === 'hooks' ){
					$sections_pages = __('Hooks', 'nexter-extension');
				}else if( $layout === 'singular' ){
					$sections_pages = __('Singular', 'nexter-extension');
				}else if( $layout === 'archives' ){
					$sections_pages = __('Archive', 'nexter-extension');
				}else if( $layout === 'page-404' ){
					$sections_pages = __('404 Page', 'nexter-extension');
				}else if( $layout === 'section' ){
					$sections_pages = __('Section', 'nexter-extension');
				}else{
					$sections_pages = __('None', 'nexter-extension');
				}

				$url_args = [
					'post_type' => NXT_BUILD_POST,
				];
	
				$baseurl = add_query_arg($url_args, admin_url('edit.php'));
				$type_url = add_query_arg('nxt_type', $nxt_type, $baseurl);
				// echo apply_filters('nexter_builder_column_content', $sections_pages);	// phpcs:ignore

				printf('<a class="nexter-action-or-type-button" href="'.esc_url($type_url).'">' . $sections_pages . '</a>');

			}elseif ($column == 'display_rules') {

				$layout = get_post_meta($post_id, 'nxt-hooks-layout', true);
				$sections_layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
				//Display Sections Column data
				if ($layout === 'sections' || (!empty($sections_layout) && ($sections_layout == 'header' || $sections_layout == 'footer' || $sections_layout == 'breadcrumb' || $sections_layout == 'hooks'))) {
					if (!empty($sections_layout) && $sections_layout != 'none') {
						echo wp_kses_post($this->nxt_sections_display_rules($post_id));
					} else {
						echo esc_html__('None', 'nexter-extension');
					}
				} else if ($layout === 'pages' || (!empty($sections_layout) && ($sections_layout == 'singular' || $sections_layout == 'archives' || $sections_layout == 'page-404'))) {
					//Display Pages Column data
					$layout_pages = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
					$page_name = (!empty($layout_pages)) ? $layout_pages : $sections_layout;
					$load_actions = Nexter_Builder_Pages_Conditional::nexter_get_pages_singular_archive('pages', $page_name);

					if (!empty($load_actions)) {
						foreach ($load_actions as $template_id => $actions) {
							if ($post_id === $template_id) {
								foreach ($actions['template_group'] as $key => $action) {
									if ($page_name == 'singular') {
										$include = isset($action['nxt-singular-include-exclude']) ? $action['nxt-singular-include-exclude'] : '';
										$rule = isset($action['nxt-singular-conditional-rule']) ? $action['nxt-singular-conditional-rule'] : '';
										$type = isset($action['nxt-singular-conditional-type']) ? $action['nxt-singular-conditional-type'] : [];

									}
									if ($page_name == 'archives') {
										$include = isset($action['nxt-archive-include-exclude']) ? $action['nxt-archive-include-exclude'] : '';
										$rule = isset($action['nxt-archive-conditional-rule']) ? $action['nxt-archive-conditional-rule'] : '';
										$type = isset($action['nxt-archive-conditional-type']) ? $action['nxt-archive-conditional-type'] : [];
									}

									if(!empty($rule) && !empty($type) && is_array($type)){
										$update_type = [];
										foreach ( $type as $item ) {
											if ( $item === 'all' ) {
												$update_type[] = esc_html__('All','nexter-extension');
											}elseif( str_contains($rule, 'by_author') ){
												$author = get_user_by( 'ID', $item );
            									if ( $author ) {
													$update_type[] = $author->display_name;
												}else{
													$update_type[] = $item;
												}
											}elseif( str_contains($rule, 'child') || str_contains($rule, 'category') || str_contains($rule, 'tag') ){
												$term = get_term( $item );
												if ( ! is_wp_error( $term ) ) {
													$update_type[] = $term->name;
												}else{
													$update_type[] = $item;
												}
											} else if ( get_post_status( $item ) ) {
												// It's a post ID
												$update_type[] = get_the_title( $item ) ;
											} else {
												$update_type[] = $item;
											}
										}
										$type = $update_type;
									}
									
									if (!empty($include)) {
										echo '<div class="nxt-sections-add-display-wrap">';
										echo '<strong>' . esc_html__('Display :', 'nexter-extension') . ' </strong>' . esc_html($include);
										if (!empty($rule) && $page_name == 'singular') {
											$rule_name = ($post_obj = get_post_type_object($rule)) ? $post_obj->labels->name : $rule;
											echo '</br><strong>' . esc_html__('Rule :', 'nexter-extension') . ' </strong>' . esc_html($rule_name);
										}
										if (!empty($rule) && $page_name == 'archives') {
											$taxonomy_obj = ($rule === 'all') ? __('All', 'nexter-extension') : ((get_taxonomy($rule)) ? get_taxonomy($rule)->labels->name : $rule);
											echo '</br><strong>' . esc_html__('Rule :', 'nexter-extension') . ' </strong>' . esc_html($taxonomy_obj);
										}
										if (!empty($type)) {
											$type_value = implode(', ', $type);
											echo '</br><strong>' . esc_html__('Type :', 'nexter-extension') . ' </strong>' . esc_html($type_value);
										}
										echo '</div>';
									}
								}
							}
						}
					}
				} else if ($layout === 'code_snippet') {

					$code_layout = get_post_meta($post_id, 'nxt-hooks-layout-code-snippet', true);
					if (!empty($code_layout) && $code_layout != 'php') {
						if ($code_layout == 'html') {
							$html_hook = get_post_meta($post_id, 'nxt-code-hooks-action', true);
							if (!empty($html_hook)) {
								echo '<strong>' . esc_html__('Hooks : ', 'nexter-extension') . ' </strong>' . wp_kses_post($html_hook);
							}
						}
						echo wp_kses_post($this->nxt_sections_display_rules($post_id));
					} else if (!empty($code_layout) && $code_layout == 'php') {
						$php_action = get_post_meta($post_id, 'nxt-code-execute', true);
						if (!empty($php_action)) {
							echo '<strong>' . esc_html__('Actions : ', 'nexter-extension') . ' </strong>' . wp_kses_post($php_action);
						}
					} else {
						echo esc_html__('None', 'nexter-extension');
					}
				} else {
					echo esc_html__('None', 'nexter-extension');
				}

				$selectType = $selectSType = '';
				$old_layout = get_post_meta($post_id, 'nxt-hooks-layout', true);
				if(!empty($old_layout)){
					$selectType = $old_layout;
					if($old_layout == 'sections'){
						$selectSType = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
					}else if($old_layout == 'pages'){
						$selectSType = get_post_meta($post_id, 'nxt-hooks-layout-pages', true);
					}else if($old_layout == 'code_snippet'){
						$selectSType = get_post_meta($post_id, 'nxt-hooks-layout-code-snippet', true);
					}else{
						$selectSType = __('None', 'nexter-extension');
					}
				}else{
					$layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
					if( $layout === 'header' || $layout === 'footer' || $layout === 'breadcrumb' || $layout === 'hooks' ) {
						$selectType = __('sections', 'nexter-extension');
					}else if( $layout === 'singular' || $layout === 'archives' || $layout === 'page-404'){
						$selectType = __('pages', 'nexter-extension');
					}
					$selectSType = $layout;
				}
				if($selectType !='none' && $selectSType!='' && $selectSType!='none' && $selectSType!='section'){
					printf('<button class="nexter-conditions-action" data-post="'.esc_attr($post_id).'" data-type="'.esc_attr($selectType).'" data-subtype="'.esc_attr($selectSType).'">
					<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg" class="nexter-conditions-action-svg">
						<path d="M8.42293 1.377C8.30181 1.2564 8.158 1.161 7.99981 1.09629C7.84161 1.03159 7.67216 0.998873 7.50125 1.00003C7.33034 1.00119 7.16135 1.0362 7.00404 1.10304C6.84673 1.16987 6.70423 1.26722 6.58476 1.38945L1.42936 6.54484L0.800049 8.99988L3.25508 8.37021L8.41048 3.21481C8.53274 3.0954 8.63012 2.95293 8.69698 2.79565C8.76384 2.63837 8.79886 2.4694 8.80002 2.2985C8.80118 2.12761 8.76845 1.95817 8.70372 1.8C8.63899 1.64183 8.54356 1.49806 8.42293 1.377V1.377Z" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>'.esc_attr("Edit", "nexter-extension").'</button>', 'nexter-extension');
				}
				
			} elseif ($column == 'author') {

				printf('<div class="author-row-text-add" data-content="%s"></div>
    					<style>
        						.conditions-row-text-add::after {content: attr(data-content);}
    					</style>',
					esc_html__('None', 'nexter-extension')
				);

			} elseif ($column == 'status') {
				$sections_layout = get_post_meta($post_id, 'nxt-hooks-layout-sections', true);
				if($sections_layout!='' && $sections_layout != 'section'){
					$meta_key = 'nxt_build_status';
					$getPostStatus = get_post_meta($post_id, $meta_key, true);
					$check = 'checked';
					if(!empty($getPostStatus)){
						$check = 'checked';
					}else if($getPostStatus==0){
						$check = '';
					}
	
					printf('<div class="nxt-post-status-wrap">
								<input type="checkbox" class="nxt-post-status" name="nxt-post-'.esc_attr($post_id).'" id="nxt-post-'.esc_attr($post_id).'" value="'.esc_attr($post_id).'" '.$check.'> 
							<label for="nxt-post-'.esc_attr($post_id).'"></label>
							</div>', 'nexter-extension');
	
				}
			}
		}

		/*
		 * Get Include/Exclude for Display Rule column
		 * @since 1.0.4
		 */
		public function nxt_sections_display_rules($post_id = '')
		{
			$output = '';
			if (!empty($post_id)) {
				$sections_include = get_post_meta($post_id, 'nxt-add-display-rule', true);
				if (!empty($sections_include)) {
					$output .= '<div class="nxt-sections-add-display-wrap">';
					$output .= '<strong>' . esc_html__('Display :', 'nexter-extension') . ' </strong>';
					$output .= $this->nxt_column_sections_rules($sections_include, $post_id, 'include');
					$output .= '</div>';
				}

				$sections_exclude = get_post_meta($post_id, 'nxt-exclude-display-rule', true);
				if (!empty($sections_exclude)) {
					$output .= '<div class="nxt-sections-excluse-display-wrap">';
					$output .= '<strong>' . esc_html__('Exclusion :', 'nexter-extension') . ' </strong>';
					$output .= $this->nxt_column_sections_rules($sections_exclude, $post_id, 'exclude');
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
		public function nxt_column_sections_rules($sections, $post_id = '', $include_exclude = '')
		{

			$sections_value = [];
			$output = '';
			if (isset($sections) && is_array($sections)) {
				foreach ($sections as $section) {
					$sections_value[] = Nexter_Builder_Display_Conditional_Rules::display_label_location_by_key($section);
				}
			}

			$output .= implode(', ', $sections_value); // phpcs:ignore

			if (!empty($sections)) {
				$particular_posts = array_search('particular-post', $sections);
				if ($particular_posts !== false && !empty($post_id) && $include_exclude == 'include') {
					$specific = get_post_meta($post_id, 'nxt-hooks-layout-specific', true);
				}
				if ($particular_posts !== false && !empty($post_id) && $include_exclude == 'exclude') {
					$specific = get_post_meta($post_id, 'nxt-hooks-layout-exclude-specific', true);
				}

				$specific_value = [];
				if (isset($specific) && is_array($specific)) {
					foreach ($specific as $section) {
						$specific_value[] = Nexter_Builder_Display_Conditional_Rules::display_label_location_by_key($section);
					}
				}

				if (!empty($include_exclude) && !empty($specific_value) && is_array($specific_value)) {
					$output .= wp_kses_post('</br><strong>' . esc_html__('Specific', 'nexter-extension') . ' ' . ucwords($include_exclude) . ': </strong> ' . implode(', ', $specific_value));
				}
			}

			$other_val = apply_filters('nexter_display_sections_specific_value', $sections, $post_id, $include_exclude);

			if (!empty($other_val) && !is_array($other_val)) {
				$output .= wp_kses_post($other_val);
			}

			return $output;
		}

	}
}

Nexter_Builder_Hooks_Loader::get_instance();