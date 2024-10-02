<?php 
/*
 * Local Google Font Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Local_Google_Font {
    
    /**
     * Constructor
     */
    public function __construct() {
		if( is_admin() ){
			add_action( 'wp_ajax_nexter_ext_local_google_font_content', [ $this, 'nexter_ext_local_google_font_content_ajax'] );
			add_action( 'enqueue_block_editor_assets', [ $this, 'head_style_local_google_font' ] );
		}
        
		add_action( 'wp_head', [ $this, 'head_style_local_google_font' ] );

		add_filter('elementor/fonts/groups', function ($groups) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $groups;
			}

			unset($groups['googlefonts']);
			unset($groups['earlyaccess']);

			$groups['nexter-local-google-fonts'] = __('Local Google Fonts', 'nexter-ext');

			return $groups;
		});

		add_filter('elementor/fonts/additional_fonts', function ($fonts) {
			$local_font = $this->check_nxt_ext_local_google_font(false, true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $fonts;
			}
			if( !empty($local_font) ){
				foreach ($local_font as $family) {
					$fonts[$family] = 'nexter-local-google-fonts';
				}
			}

			return $fonts;
		});

		add_filter('fl_builder_google_fonts_pre_enqueue', function($fonts) {
			return [];
		});

		// takes care of theme enqueues
		add_action( 'wp_enqueue_scripts', function() {
			global $wp_styles;
			if ( isset( $wp_styles->queue ) ) {
				foreach ( $wp_styles->queue as $key => $handle ) {
					if ( false !== strpos( $handle, 'fl-builder-google-fonts-' ) ) {
						unset( $wp_styles->queue[ $key ] );
					}
				}
			}
		}, 101 );

		add_filter('fl_builder_font_families_google', function ($gfont) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $gfont;
			}

			return $gfont;
		});
		add_filter('fl_theme_system_fonts', [$this, 'nexter_local_google_font_beaver_builder'] );
		add_filter('fl_builder_font_families_system', [$this, 'nexter_local_google_font_beaver_builder'] );

		add_filter('tpgb_google_font_load', function ($gfont) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $gfont;
			}

			return false;
		});
		add_filter('tpgb-custom-fonts-list', function ($font) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $font;
			}
			$local_font = $this->check_nxt_ext_local_google_font(false,true);
			if(!empty($local_font)){
				foreach ( $local_font as $family ) {
					$font[] = (object)['label' => $family, 'value' => $family ];
				}
			}
			return $font;
		});

		/*add_filter('stackable_enqueue_font', function ($gfont) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $gfont;
			}

			return false;
		});

		add_filter('kadence_blocks_print_google_fonts', function ($gfont) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $gfont;
			}

			return false;
		});*/
    }

	/**
	 * Check Local Google Font
	 * @since 1.1.0
	 */
	public function check_nxt_ext_local_google_font( $style = false, $values = false){
		$check = false;
		$nxt_ext = get_option( 'nexter_extra_ext_options' );
		if( !empty($nxt_ext) && isset($nxt_ext['local-google-font']) && !empty($nxt_ext['local-google-font']['switch']) && !empty($nxt_ext['local-google-font']['values']) ){
			$check = true;
			if($style==true){
				return $nxt_ext['local-google-font']['style'];
			}
			if($values==true){
				return $nxt_ext['local-google-font']['values'];
			}
		}
		
		return $check;
	}
	
	/*
	* Local Google Font load Style wp_Head
	* @since 1.1.0
	*/
	public function head_style_local_google_font(){
		$font_style = $this->check_nxt_ext_local_google_font(true);
		if( $this->check_nxt_ext_local_google_font() && !empty( $font_style ) ){
			if( is_admin() ){
				wp_add_inline_style( 'wp-edit-blocks', wp_strip_all_tags( $font_style ) );
			}else{
				echo '<style type="text/css">'.wp_strip_all_tags( $font_style ).'</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}
		
    /*
	 * Nexter Local Google Font Setting Content
	 * @since 1.1.0
	 */
	public function nexter_ext_local_google_font_content_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-ext' ),
				)
			);
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$extension_option = get_option( 'nexter_extra_ext_options' );
		
        if( !empty( $ext ) && $ext == 'local-google-font' ){
			$font_val = [];
			if(!empty($extension_option) && isset($extension_option['local-google-font']) && !empty($extension_option['local-google-font']['values']) ){
				$font_val = $extension_option['local-google-font']['values'];
			}

            $config_ext = [];
            if(function_exists('nexter_extension_option_config')){
               $config_data = nexter_extension_option_config();
               if( !empty( $config_data ) && isset($config_data[$ext]) ){
                   $config_ext = $config_data[$ext];
               }
            }else if(class_exists('Nexter_Ext_Panel_Settings')){
				$ext_panel = Nexter_Ext_Panel_Settings::get_instance();
				$config_data = $ext_panel->nexter_extension_option_config();
				if( !empty( $config_data ) && isset($config_data[$ext]) ){
					$config_ext = $config_data[$ext];
				}
			}

			$output = '';
			$output .= '<div class="nxt-ext-modal-content">';
                $output .= '<div class="nxt-modal-title-wrap">';
				    $output .= '<div class="nxt-modal-title">'.(isset($config_ext['title']) ? wp_kses_post($config_ext['title']) : '').'</div>';
                $output .= '</div>';
				$fontList = class_exists('Nexter_Font_Families_Listing') ? Nexter_Font_Families_Listing::get_google_fonts_load() : '';
				
				$output .= '<div class="nxt-gfont-section">';
					$output .= '<div class="nxt-add-google-font" role="combobox">';
						$output .= '<input type="text" id="nxt-local-google-font-select" autocomplete="off" placeholder="'.esc_html__( '--Select Value--','nexter-ext').'" value="" readonly />';
						$output .= '<button class="nxt-add-gfont-data" disabled><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M432 256c0 17.69-14.33 32.01-32 32.01H256v144c0 17.69-14.33 31.99-32 31.99s-32-14.3-32-31.99v-144H48c-17.67 0-32-14.32-32-32.01s14.33-31.99 32-31.99H192v-144c0-17.69 14.33-32.01 32-32.01s32 14.32 32 32.01v144h144C417.7 224 432 238.3 432 256z"/></svg>'.esc_html__('Add Option','nexter-ext').'</button>';
					$output .= '</div>';
					$output .= '<ul class="nxt-gfont-list">';
                        if( !empty( $fontList ) ){
                            foreach ( $fontList as $name => $font ) {
                                $selected_font = '';
                                if(!empty($font_val) && in_array($name,$font_val) ){
                                    $selected_font = 'hide-selected-val';
                                }
                                $output .= '<li value="' . esc_attr( $name ) . '" class="'.esc_attr($selected_font).'">' . esc_html( $name ) . '</li>';
                            }
                        }
					$output .= '</ul>';
					$output .= '<ul class="nxt-gfont-selected">';
						if(!empty($font_val) ){
							foreach($font_val as $val){
								$output .= '<li class="nxt-gfont-val"><div class="nxt-font-title">'.esc_html($val).'</div><button type="button" class="nxt-remove-gfont" value="'.esc_attr($val).'"></button></li>';
							}
						}
					$output .= '</ul>';
				$output .= '</div>';
				$output .= '<button type="button" class="nxt-sync-save-gfont"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" ><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Save','nexter-ext').'</button>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
	}

	/*
	 * Nexter Local Google Font Compatibility of Beaver Builder 
	 * @since 1.1.0
	 */
	public function nexter_local_google_font_beaver_builder( $system_fonts ){
		$local_font = $this->check_nxt_ext_local_google_font(false, true);

		if ( !isset($local_font) || empty($local_font) ) {
			return $fonts;
		}
		$google_fonts_list = Nexter_Font_Families_Listing::get_google_fonts_load();
		if( !empty($local_font) ){
			foreach ($local_font as $family) {
				$font_weights = [];
				if( isset($google_fonts_list[$family]) && isset($google_fonts_list[$family][0]) ){
					$weights = $google_fonts_list[$family][0];

					$font_weights = array_map(function ($variation) {
						$init_variation = $variation;
	
						$variation = str_replace('normal', '', $variation);
						$variation = str_replace('italic', '', $variation);
	
						if ($init_variation[3] === 'i') {
							$variation .= 'i';
						}else if( $init_variation[0] === 'i'){
							$variation .= '400i';
						}
	
						return $variation;
					}, $weights);

					$system_fonts[$family] = array(
						'fallback' => 'Verdana, Arial, sans-serif',
						'weights' => $font_weights,
					);
				}
			}
		}
		
		return $system_fonts;
	}
}

 new Nexter_Ext_Local_Google_Font();