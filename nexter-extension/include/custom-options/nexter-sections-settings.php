<?php
/*
 * Custom Options Nexter Builder
 *
 * @package Nexter Extensions
 * @since 3.0.0
 */

// 1. Register the Meta Box
function nxt_custom_meta_box() {
    add_meta_box(
        'nxt_builder_settings_1', 
        'Nexter Builder', 
        'nxt_custom_meta_box_callback',
        'nxt_builder', 
        'normal',
        'default'
    );
}

if (isset($_GET['post'])) {
    $post_id = intval($_GET['post']);
	$hook_layout = get_post_meta( $post_id, 'nxt-hooks-layout', true );
	$code_snippet = get_post_meta( $post_id, 'nxt-hooks-layout-code-snippet', true );	
	
	if(!empty($hook_layout) && $hook_layout == 'code_snippet' && !empty($code_snippet)){
		add_action('add_meta_boxes', 'nxt_custom_meta_box');
	}
}

// 2. Create the HTML Form Inside the Meta Box
function nxt_custom_meta_box_callback($post) {
    //wp_nonce_field('nxt-code-snippet-nonce_action', 'nxt-code-snippet-nonce');

	$code_snippet = get_post_meta( $post->ID, 'nxt-hooks-layout-code-snippet', true );
	$code_type = ($code_snippet == 'html') ? 'htmlmixed' : $code_snippet;
    $custom_field_value = get_post_meta($post->ID, 'nxt-code-'.$code_type.'-snippet', true);

	echo '<div class="nxt-settings-wrap">';
	echo '<label class="nxt-layout-type">'.esc_html__('Layout is : Code Snippet','nexter-extension').'</label>';
	echo '<label class="nxt-code-snippet-type">'.esc_html__('Code Type is : ','nexter-extension').esc_html($code_snippet).'</label>';
		echo '<div class="nxt-code-snippet-inner">';
			echo '<div class="nxt-code-snip-left">';
				echo '<label for="nxt-code-'.esc_attr($code_type).'-snippet">'.esc_html($code_snippet).''.esc_html__(' Code','nexter-extension').'</label>';
			echo '</div>';
			echo '<div class="nxt-code-snip-right">';
                echo '<textarea id="nxt-code-'.esc_attr($code_type).'-snippet" name="nxt-code-'.esc_attr($code_type).'-snippet" size="25">'.esc_textarea($custom_field_value).'</textarea>';
			echo '</div>';
		echo '</div>';
	echo '</div>';
}

function nxt_ext_builder_post_type() {
    $screen = get_current_screen();

    if ($screen->post_type === 'nxt_builder' && $screen->base === 'post') {
		$singular_preview_type = get_post_meta(get_the_ID(), 'nxt-singular-preview-type', true);
		$singular_preview_id = get_post_meta(get_the_ID(), 'nxt-singular-preview-id', true);
		$archives_preview_type = get_post_meta(get_the_ID(), 'nxt-singular-preview-type', true);
		$archives_preview_id = get_post_meta(get_the_ID(), 'nxt-singular-preview-id', true);

		$old_layout = get_post_meta(get_the_ID(), 'nxt-hooks-layout', true);
        $layout = get_post_meta(get_the_ID(), 'nxt-hooks-layout-sections', true);
        $layoutType = '';

        if(!empty($old_layout)){
            if($old_layout == 'sections'){
                $layoutType = get_post_meta(get_the_ID(), 'nxt-hooks-layout-sections', true);
            }else if($old_layout == 'pages'){
                $layoutType = get_post_meta(get_the_ID(), 'nxt-hooks-layout-pages', true);
            }else if( !empty($layout)){
                $layoutType = $layout;
            }
        }else if( !empty($layout)){
			$layoutType = $layout;
        }

		if(!empty($layoutType)){
			echo '<input type="hidden" id="nxt-layout-type" name="nxt-layout-type" value="'.esc_attr($layoutType).'">';
		}
		if(!empty($singular_preview_type)){
			echo '<input type="hidden" id="nxt-singular-preview-type" name="nxt-singular-preview-type" value="'.esc_attr($singular_preview_type).'">';
		}
		if(!empty($singular_preview_id)){
			echo '<input type="hidden" id="nxt-singular-preview-id" name="nxt-singular-preview-id" value="'.esc_attr($singular_preview_id).'">';
		}
		if(!empty($archives_preview_type)){
			echo '<input type="hidden" id="nxt-archive-preview-type" name="nxt-archive-preview-type" value="'.esc_attr($archives_preview_type).'">';
		}
		if(!empty($archives_preview_id)){
			echo '<input type="hidden" id="nxt-archive-preview-id" name="nxt-archive-preview-id" value="'.esc_attr($archives_preview_id).'">';
		}
    }
}
add_action('admin_footer', 'nxt_ext_builder_post_type');