<?php 
/*
 * Local Google Font Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

class Nexter_Ext_Custom_Upload_Font {

    /**
     * Constructor
     */
    public function __construct() {
		if( is_admin() ){
        	add_action( 'wp_ajax_nexter_ext_custom_upload_font_content', [ $this, 'nexter_ext_custom_upload_font_content_ajax'] );
		}
		add_filter( 'nexter_custom_fonts_load' , [ $this,'nexter_ext_custom_upload_font_lists' ], 10, 1);

		add_filter('elementor/fonts/groups', function ($groups) {
			$groups['nexter-custom-fonts'] = __('Custom Fonts', 'nexter-ext');
			return $groups;
		});

		add_filter('elementor/fonts/additional_fonts', function ($fonts) {
			if(class_exists('Nexter_Font_Families_Listing')){
				$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
			}else{
				$font_settings = $this->nexter_ext_custom_upload_font_lists();
			}
			if( !empty( $font_settings) ){
				foreach ($font_settings as $font_name => $family) {
					if (empty($family['weights'])) {
						continue;
					}

					$fonts[$font_name] = 'nexter-custom-fonts';
				}
			}
			return $fonts;
		});

		add_filter('fl_theme_system_fonts', [$this, 'nexter_add_custom_font_fl_builder'] );
		add_filter('fl_builder_font_families_system', [$this, 'nexter_add_custom_font_fl_builder'] );

		add_filter('tpgb-custom-fonts-list', function ($font) {
			if(class_exists('Nexter_Font_Families_Listing')){
				$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
			}else{
				$font_settings = $this->nexter_ext_custom_upload_font_lists();
			}

			if ( !isset($font_settings) || empty($font_settings) ) {
				return $font;
			}
			if(!empty($font_settings)){
				foreach ( $font_settings as $font_name => $family ) {
					$font[] = (object)['label' => $font_name, 'value' => $font_name ];
				}
			}
			return $font;
		});

		// add Custom Font list into Astra customizer.
		add_filter( 'astra_system_fonts', array( $this, 'add_custom_fonts_astra_customizer' ) );

		// add Custom Font List into Blocksy Customizer.
		add_filter('blocksy_ext_custom_fonts:dynamic_fonts', array( $this, 'add_custom_fonts_blocksy_customizer' ) );

		// add Custom Font List into kadence Customizer.
		add_filter( 'kadence_theme_add_custom_fonts', array( $this,'nxt_kadence_custom_fonts') );
		
		if(!defined('NXT_VERSION')){
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
		}
    }
	
	/* Frontend Load Custom Font */
	public function enqueue_scripts(){
		$fonts = $this->nexter_ext_custom_upload_font_lists();
		if(!empty($fonts)){
			$custom_fonts_face = $this->get_custom_fonts_face();
			if( !empty( $custom_fonts_face ) ){
				echo '<style>'.$custom_fonts_face.'</style>';
			}
		}
	}

	public static function get_custom_fonts_face(){
		$nxt_ext = get_option( 'nexter_extra_ext_options' );

		$font_faces = '';
		//custom upload font load
		if( !empty($nxt_ext) && isset($nxt_ext['custom-upload-font']) && !empty($nxt_ext['custom-upload-font']['switch']) && !empty($nxt_ext['custom-upload-font']['values']) ){
			$font_data = [];
			$upload_font_list = $nxt_ext['custom-upload-font']['values'];
			
			foreach ( $upload_font_list as $fonts ) {
				foreach ( $fonts as $key => $val ) {
					//simple font
					if( !empty($val['simplefont']) && !empty($val['simplefont']['font_name']) ){
						$simple_font_variation = [];
						if(!empty($val['simplefont']['lists'])){
							foreach($val['simplefont']['lists'] as $key_variant => $val_variation){
								if( !empty($val_variation) && !empty($val_variation['id']) && !empty($val_variation['variation']) ){

									$font_name = $val['simplefont']['font_name'];
									$font_url = wp_get_attachment_url( $val_variation['id'] );
									if( !empty($font_url)){
										$font_data[$font_name][$key_variant]['type'] = 'simple';
										$font_data[$font_name][$key_variant]['weight'] = $val_variation['variation'];
										$font_data[$font_name][$key_variant]['font-style'] = 'normal';
										$font_data[$font_name][$key_variant]['url'] = $font_url;
									}
								}
								
							}
						}
					}
					if( !empty($val['variablefont']) && !empty($val['variablefont']['font_name']) ){
						$simple_font_variation = [];
						if(!empty($val['variablefont']['lists'])){
							foreach($val['variablefont']['lists'] as $key_variant => $val_variation){
								if( !empty($val_variation) && !empty($val_variation['id']) ){
									$font_name = $val['variablefont']['font_name'];
									$font_url = wp_get_attachment_url( $val_variation['id'] );
									if( !empty($font_url)){
										$font_data[$font_name][$key_variant]['type'] = 'variable';
										$font_data[$font_name][$key_variant]['weight'] = '100 900';
										$font_data[$font_name][$key_variant]['font-style'] = ($key_variant === 'italic') ? 'italic' : 'normal';
										$font_data[$font_name][$key_variant]['url'] = $font_url;
									}
								}
							}
						}
					}
				}
			}
			
			if(!empty($font_data)){
				foreach( $font_data as $font_name => $font_val){
					foreach( $font_val as $font_key => $font_value){
						if(!empty( $font_value['url'] )){
							$format = self::check_format_font_url($font_value['url']);
							$font_faces .= '@font-face {';
							$font_faces .= 'font-family: ' . esc_html($font_name) . ';';
							$font_faces .= "font-style: " . esc_html($font_value['font-style']) . ";";
							$font_faces .= "font-weight: " . esc_attr($font_value['weight']) . ";";
							$font_faces .= "font-display: swap;";
							$font_faces .= "src: url('" . esc_url($font_value['url']) . "') format('" . $format . "');";
							$font_faces .= '}';
						}
					}
				}
			}
		}
		return $font_faces;
	}

	/*
	 * Font Url check Format
	 * @since 1.1.0
	 */
	private static function check_format_font_url($url) {
		$array = [
			'woff2' => 'woff2',
			'ttf' => 'truetype'
		];

		$d = strrpos($url,".");
		$extension = ($d===false) ? "" : substr($url,$d+1);

		if (! isset($array[$extension])) {
			return $extension;
		}

		return $array[$extension];
	}

	/*
	 * Nexter Custom Upload Font Lists
	 * @since 1.1.0
	 */
	public function nexter_ext_custom_upload_font_lists( $fonts_list=[] ){
		$custom_fonts_list = [];
		
		$nxt_ext = get_option( 'nexter_extra_ext_options' );
		//custom upload font load
		if( !empty($nxt_ext) && isset($nxt_ext['custom-upload-font']) && !empty($nxt_ext['custom-upload-font']['switch']) && !empty($nxt_ext['custom-upload-font']['values']) ){
			$upload_font_list = $nxt_ext['custom-upload-font']['values'];
			foreach ( $upload_font_list as $fonts ) {
				foreach ( $fonts as $key => $val ) {
					//simple font
					if(!empty($val['simplefont']) && !empty($val['simplefont']['font_name'])){
						$simple_font_variation = [];
						if(!empty($val['simplefont']['lists'])){
							foreach($val['simplefont']['lists'] as $key_weight => $val_weight){
								$variation = isset($val_weight['variation']) ? $val_weight['variation']: '';
								if (!empty($variation) && preg_match(
									"#(\d+?)(i)$#",
									$variation,
									$matches
								)) {
									
									if ('i' === $matches[2]) {
										$variation = $matches[1].'italic';
									}
								}
								$simple_font_variation[] = $variation;
							}
						}
						if(!empty($simple_font_variation)){
							$custom_fonts_list[ $val['simplefont']['font_name'] ]['weights'] = $simple_font_variation;
							$custom_fonts_list[ $val['simplefont']['font_name'] ][] = 'display';
						}
					}
					//variable font
					if(!empty($val['variablefont']) && !empty($val['variablefont']['font_name'])){
						$simple_font_variation = [];
						if(!empty($val['variablefont']['lists'])){
							$variable_font = [];
							foreach($val['variablefont']['lists'] as $key_type => $font_type_val){
								if(!empty($font_type_val['id'])){
									$variable_font[] = $key_type; 
								}
							}
						}
						if( !empty($variable_font) ){
							$font_weight = [ '100', '100italic', '200', '200italic', '300', '300italic', '400', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic'];
							$custom_fonts_list[ $val['variablefont']['font_name'] ]['weights'] = $font_weight;
							$custom_fonts_list[ $val['variablefont']['font_name'] ][] = 'display';
						}
					}
				}
			}
		}
		if( !empty($custom_fonts_list) ){
			$fonts_list = array_merge($custom_fonts_list, $fonts_list);
		}
		return $fonts_list;
	}

    /*
	 * Nexter Custom Upload Font Setting Content
	 * @since 1.1.0
	 */
	public function nexter_ext_custom_upload_font_content_ajax(){
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

		$font_variation = [ '100' => 'Thin 100', '100i' => 'Thin 100 Italic', '200' => 'Extra Light 200', '200i' => 'Extra Light 200 Italic', '300' => 'Light 300', '300i' => 'Light 300 Italic', '400' => 'Regular 400', '400i' => 'Regular 400 Italic', '500' => 'Medium 500', '500i' => 'Medium 500 Italic', '600' => 'Semi-Bold 600', '600i' => 'Semi-Bold 600 Italic', '700' => 'Bold 700', '700i' => 'Bold 700 Italic', '800' => 'Extra-Bold 800', '800i' => 'Extra-Bold 800 Italic',	'900' => 'Bolder 900', '900i' => 'Bolder 900 Italic' ];
		$font_val = [];

		if( !empty( $ext ) && $ext == 'custom-upload-font' ){
			if(!empty($extension_option) && isset($extension_option['custom-upload-font']) && !empty($extension_option['custom-upload-font']['values']) ){
				$font_val = $extension_option['custom-upload-font']['values'];
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
				$output .= '<ul class="nxt-custom-fonts-list">';
				if(!empty($font_val)){
					foreach($font_val as $key => $value_data){
						foreach($value_data as $uid => $fonts){
							if((!empty($fonts['simplefont']) && !empty($fonts['simplefont']['font_name'])) || (!empty($fonts['variablefont']) && !empty($fonts['variablefont']['font_name']))){
								$font_type_list = [];
								$type = '';
								if(!empty($fonts['simplefont']) && !empty($fonts['simplefont']['font_name'])){
									$font_type_list = $fonts['simplefont'];
									$type = 'simple';
								}else if(!empty($fonts['variablefont']) && !empty($fonts['variablefont']['font_name'])){
									$font_type_list = $fonts['variablefont'];
									$type = 'variable';
								}
								$output .= '<li class="custom-font-list" data-type="'.$type.'">';
									$output .= '<div class="font-list-data-wrap">';
										$output .= '<div class="custom-font-name-wrap">';
											$output .='<div class="font-name-type-wrap">';
												if($type == 'simple'){
													$output .= '<span class="display-font-type">S</span>';
												}else if($type = 'variable'){
													$output .= '<span class="display-font-type">V</span>';
												}
												if(!empty($font_type_list) && !empty($font_type_list['font_name'])){
													$output .= '<span class="custom-font-name">'.esc_html($font_type_list['font_name']).'</span>';
												}
											$output .= '</div>';
											$output .= '<div class="custom-font-action"><a href="#" class="font-edit" data-uid="'.esc_attr($uid).'"><svg viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg"><path d="M5.5 2H2C1.73478 2 1.48043 2.10536 1.29289 2.29289C1.10536 2.48043 1 2.73478 1 3V10C1 10.2652 1.10536 10.5196 1.29289 10.7071C1.48043 10.8946 1.73478 11 2 11H9C9.26522 11 9.51957 10.8946 9.70711 10.7071C9.89464 10.5196 10 10.2652 10 10V6.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.25 1.25011C9.44891 1.0512 9.7187 0.939453 10 0.939453C10.2813 0.939453 10.5511 1.0512 10.75 1.25011C10.9489 1.44903 11.0607 1.71881 11.0607 2.00011C11.0607 2.28142 10.9489 2.5512 10.75 2.75011L6 7.50011L4 8.00011L4.5 6.00011L9.25 1.25011Z" stroke-linecap="round" stroke-linejoin="round"/></svg></a><a href="#" class="font-remove" data-uid="'.esc_attr($uid).'"><svg viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg"><path d="M1.5 3H2.5H10.5"  stroke-linecap="round" stroke-linejoin="round"/><path d="M4 3V2C4 1.73478 4.10536 1.48043 4.29289 1.29289C4.48043 1.10536 4.73478 1 5 1H7C7.26522 1 7.51957 1.10536 7.70711 1.29289C7.89464 1.48043 8 1.73478 8 2V3M9.5 3V10C9.5 10.2652 9.39464 10.5196 9.20711 10.7071C9.01957 10.8946 8.76522 11 8.5 11H3.5C3.23478 11 2.98043 10.8946 2.79289 10.7071C2.60536 10.5196 2.5 10.2652 2.5 10V3H9.5Z" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 5.5V8.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 5.5V8.5" stroke-linecap="round" stroke-linejoin="round"/></svg></a></div>';
										$output .= '</div>';
										$output .= '<div class="selected-variation-list">';
											if($type == 'simple' && !empty($font_type_list['lists'])){
												$variant = [];
												foreach($font_type_list['lists'] as $list_key => $variations){
													$variant[] = isset( $font_variation[ $variations['variation'] ] ) ? $font_variation[ $variations['variation'] ] : '';
												}
												if(!empty($variant)){
													$output .= '<span class="custom-font-variant">'.esc_html__('Variation :','nexter-ext').' '.implode ( ', ', $variant ).'</span>';
												}
											}
											if($type == 'variable' && !empty($font_type_list['lists'])){
												$variant = [];
												foreach($font_type_list['lists'] as $list_key => $file_type){
													$variant[] = ( $list_key=='regular' && !empty( $file_type['id'] )) ? esc_html__('Regular','nexter-ext' ) : ( ( $list_key=='italic' && !empty( $file_type['id'] )) ? esc_html__('Italic','nexter-ext') : '');
												}
												if(!empty($variant)){
													$output .= '<span class="custom-font-variant">'.esc_html__('Variation :','nexter-ext').' '.implode ( ', ', $variant ).'</span>';
												}
											}
										$output .= '</div>';
									$output .= '</div>';
								$output .= '</li>';
							}
						}
					}
				}else{
					$output .= '<li class="empty-font-list"><img src="'.esc_url(NEXTER_EXT_URL.'assets/images/panel-icon/empty-font.svg').'" alt="'.esc_html__('empty-font-img','nexter-ext').'" /><div class="empty-font-text">'.esc_html__('So Sorry!! We are not able to find what you are looking for please add the font','nexter-ext').'</div></li>';
				}
				$output .= '</ul>';
				$output .='<div class="nxt-font-btn-action">';
					$output .= '<div class="nxt-upload-font-btn nxt-ext-simple-font-upload"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="18" fill="none" ><g stroke="#22379c" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"><path d="M2.804 3.782h16.493v13.469"/><path d="M8.634 3.782V.758H.715v16.493h18.582"/></g><mask id="A" fill="#fff"><rect x="4.688" y="5.797" width="10.625" height="9.453" rx=".156"/></mask><rect x="4.688" y="5.797" width="10.625" height="9.453" rx=".156" stroke="#22379c" stroke-width="1.563" stroke-dasharray="1.56 1.56" mask="url(#A)"/><path d="M9.081 13.023v-.131l.575-.094V8.18H8.625L8.15 9.417h-.181V8.023h4.069v1.394h-.187l-.469-1.237h-1.037v4.619l.575.094v.131H9.081z" fill="#22379c"/></svg>'.esc_html__('Simple Font Upload','nexter-ext').'</div>';
					$output .= '<div class="nxt-upload-font-btn nxt-ext-variable-font-upload"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="18" fill="none" ><g stroke="#fff" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"><path d="M2.804 3.782h16.493v13.469"/><path d="M8.634 3.782V.758H.715v16.493h18.582"/></g><mask id="A" fill="#fff"><rect x="4.688" y="5.797" width="10.625" height="9.453" rx=".156"/></mask><rect x="4.688" y="5.797" width="10.625" height="9.453" rx=".156" stroke="#fff" stroke-width="1.563" stroke-dasharray="1.56 1.56" mask="url(#A)"/><path d="M9.081 13.023v-.131l.575-.094V8.18H8.625L8.15 9.417h-.181V8.023h4.069v1.394h-.187l-.469-1.237h-1.037v4.619l.575.094v.131H9.081z" fill="#fff"/></svg>'.esc_html__('Variable Font Upload','nexter-ext').'</div>';
				$output .= '</div>';
			$output .= '</div>';

			$output .= '<div class="nxt-simple-font-wrapper">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.esc_html__( 'Simple Font Upload', 'nexter-ext' ).'</div>';
					// translators: %1$s represents the .woff2 file format, %2$s represents the .ttf file format.
					$output .= sprintf( '<div class="nxt-modal-desc">'.esc_html__( 'Upload only the %1$s or %2$s font file formats.','nexter-ext' ).'</div>', '<code>.woff2</code>', '<code>.ttf</code>' );
				$output .= '</div>';
				$output .= '<div class="nxt-upload-font-form">';
					$output .= '<label class="upload-font-label">'.esc_html__('Font Name','nexter-ext').'</label>';
					$output .= '<input type="text" name="custom-font-name" class="nxt-custom-font-name" placeholder="'.esc_html('Please Enter Font Name','nexter-ext').'" />';
					$output .= '<div class="nxt-simple-font-inner">';
						$output .= '<ul>';
							$output .= '<li class="add-font-variation"><button class="add-more-font-variant">'.esc_html__('Add Variant','nexter-ext').'</button></li>';
						$output .= '</ul>';
					$output .= '</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-upload-btn-actions nxt-simple-font-btn">';
					$output .= '<button class="nxt-back-font nxt-back-simple-font"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 15 8"><path fill="#22379C" d="M.646 3.646a.5.5 0 0 0 0 .708l3.182 3.182a.5.5 0 1 0 .708-.708L1.707 4l2.829-2.828a.5.5 0 1 0-.708-.708L.646 3.646ZM15 3.5H1v1h14v-1Z"/></svg>'.esc_html__('Back','nexter-ext').'</button>';
					$output .= '<button class="nxt-save-font nxt-save-simple-font"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.833 17.5H4.167A1.667 1.667 0 0 1 2.5 15.833V4.167A1.667 1.667 0 0 1 4.167 2.5h9.166L17.5 6.667v9.166a1.666 1.666 0 0 1-1.667 1.667Z" stroke="#fff" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.167 17.5v-6.667H5.833V17.5M5.833 2.5v4.167H12.5" stroke="#fff" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"/></svg>'.esc_html__('Save Font','nexter-ext').'</button>';
				$output .= '</div>';
			$output .= '</div>';

			$output .= '<div class="nxt-variable-font-wrapper">';
				$output .= '<div class="nxt-modal-title-wrap">';
					$output .= '<div class="nxt-modal-title">'.esc_html__( 'Variable Font Upload', 'nexter-ext' ).'</div>';
					// translators: %1$s represents the .woff2 file format, %2$s represents the .ttf file format.
					$output .= sprintf( '<div class="nxt-modal-desc">'.esc_html__( 'Upload only the %1$s or %2$s font file formats.','nexter-ext' ).'</div>', '<code>.woff2</code>', '<code>.ttf</code>' );
				$output .= '</div>';
				$output .= '<div class="nxt-upload-font-form">';
					$output .= '<label class="upload-font-label">'.esc_html__('Font Name','nexter-ext').'</label>';
					$output .= '<input type="text" name="custom-font-name" class="nxt-custom-font-name" placeholder="'.esc_html('Please Enter Font Name','nexter-ext').'" />';
					$output .= '<div class="nxt-variable-font-inner">';
						$output .= '<ul></ul>';
					$output .= '</div>';
				$output .= '</div>';
				$output .= '<div class="nxt-upload-btn-actions nxt-variable-font-btn">';
					$output .= '<button class="nxt-back-font nxt-back-variable-font"><svg viewBox="0 0 15 8" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M.646 3.646a.5.5 0 0 0 0 .708l3.182 3.182a.5.5 0 1 0 .708-.708L1.707 4l2.829-2.828a.5.5 0 1 0-.708-.708L.646 3.646ZM15 3.5H1v1h14v-1Z" fill="#22379C"/></svg>'.esc_html__('Back','nexter-ext').'</button>';
					$output .= '<button class="nxt-save-font nxt-save-variable-font"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.833 17.5H4.167A1.667 1.667 0 0 1 2.5 15.833V4.167A1.667 1.667 0 0 1 4.167 2.5h9.166L17.5 6.667v9.166a1.666 1.666 0 0 1-1.667 1.667Z" stroke="#fff" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.167 17.5v-6.667H5.833V17.5M5.833 2.5v4.167H12.5" stroke="#fff" stroke-width=".781" stroke-linecap="round" stroke-linejoin="round"/></svg>'.esc_html__('Save Font','nexter-ext').'</button>';
				$output .= '</div>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
					'fonts' => $font_val
				)
			);
		}

		wp_send_json_error();
	}

	
	public function add_custom_fonts_astra_customizer( $fonts_arr ){
		if(class_exists('Nexter_Font_Families_Listing')){
			$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
		}else{
			$font_settings = $this->nexter_ext_custom_upload_font_lists();
		}
		
		if(!empty($font_settings)){
			$fonts_arr = $this->get_font_data( $font_settings, $fonts_arr, 'astra' );
		}

		return $fonts_arr;
	}

	public function add_custom_fonts_blocksy_customizer( $fonts ){
		if(class_exists('Nexter_Font_Families_Listing')){
			$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
		}else{
			$font_settings = $this->nexter_ext_custom_upload_font_lists();
		}
		
		if(!empty($font_settings)){
			$fonts = $this->get_font_data( $font_settings, $fonts, 'blocksy' );
		}

		return $fonts;
	}
	
	public function nxt_kadence_custom_fonts( $system_fonts ){
		if(class_exists('Nexter_Font_Families_Listing')){
			$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
		}else{
			$font_settings = $this->nexter_ext_custom_upload_font_lists();
		}
		
		if(!empty($font_settings)){
			$system_fonts = $this->get_font_data( $font_settings, $system_fonts, 'kadence' );
		}

		return $system_fonts;
	}

	/*
	 * Nexter Custom Font Compatibility of Beaver Builder
	 * @since 1.1.0
	 */
	public function nexter_add_custom_font_fl_builder($system_fonts) {
		$font_families = [];
		if(class_exists('Nexter_Font_Families_Listing')){
			$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
		}else {
			$font_settings = $this->nexter_ext_custom_upload_font_lists();
		}
		if (! isset($font_settings)) {
			return $system_fonts;
		}
		
		if( !empty( $font_settings) ){
			$system_fonts = $this->get_font_data( $font_settings, $system_fonts, 'fl_builder' );
		}

		return $system_fonts;
	}
	
	public function get_font_data( $font_settings = [], $font_data = [], $type = ''){

		if( !empty( $font_settings) && !empty($type) ){
			foreach ($font_settings as $font_name => $family) {
				
				if (! is_array($family['weights']) || empty($family['weights']) || !isset($family['weights']) ) {
					continue;
				}
				
				$all_weights= array_map(function ($font_weight) {
					
					$init_variation = $font_weight;
					
					$font_weight = str_replace('normal', '', $font_weight);
					$font_weight = str_replace('italic', '', $font_weight);
	
					if (isset($init_variation[3]) && $init_variation[3] === 'i') {
						$font_weight .= 'i';
					}else if( isset($init_variation[0]) && $init_variation[0] === 'i'){
						$font_weight .= '400i';
					}
	
					return $font_weight;

				}, $family['weights']);
				
				if( $type == 'fl_builder' ){
					$font_data[ $font_name ] = array(
						'fallback' => 'Verdana, Arial, sans-serif',
						'weights' => $all_weights
					);
				}else if( $type == 'astra' ){
					$font_data[ $font_name ] = array(
						'fallback' => 'Verdana, Arial, sans-serif',
						'weights' => $all_weights
					);
				}else if( $type == 'blocksy' ){
					$font_data[] = array(
						'name' => $font_name,
						'fontType' => 'regular',
					);
				}else if( $type == 'kadence' ){
					$font_data[ $font_name ] = array(
						'fallback' => 'Verdana, Arial, sans-serif',
						'weights' => $all_weights,
					);
				}
				
			}
		}

		return $font_data;
	}
}

 new Nexter_Ext_Custom_Upload_Font();