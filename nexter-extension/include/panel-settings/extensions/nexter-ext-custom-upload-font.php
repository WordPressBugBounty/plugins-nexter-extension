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
		add_filter( 'nexter_custom_fonts_load' , [ $this,'nexter_ext_custom_upload_font_lists' ], 10, 1);

		add_filter('elementor/fonts/groups', function ($groups) {
			$groups['nexter-custom-fonts'] = __('Custom Fonts', 'nexter-extension');
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
		
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 1 );
    }
	
	/* Frontend Load Custom Font */
	public function enqueue_scripts(){
		$fonts = $this->nexter_ext_custom_upload_font_lists();
		if(!empty($fonts)){
			$custom_fonts_face = $this->get_custom_fonts_face();
			if( !empty( $custom_fonts_face ) ){
				echo '<style>'.esc_html($custom_fonts_face).'</style>';
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