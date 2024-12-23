<?php 
/*
 * Adobe Font Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Adobe_Font {
    
	public static $adobe_val = [];
    /**
     * Constructor
     */
    public function __construct() {
		if( is_admin() ){
			add_action('wp_ajax_nexter_ext_save_adobe_font_data', [ $this, 'nexter_ext_save_adobe_font_data_ajax'] );
		}else{
			add_action('wp_enqueue_scripts', [ $this, 'nxt_adobe_font_enqueue' ] );
		}
		
		add_action('init', [ $this, 'nxt_adobe_font_wpblock' ] );
		add_filter( 'nexter_custom_fonts_load' , [ $this,'nexter_ext_adobe_font_lists' ], 10, 1);

		add_filter('elementor/fonts/groups', function ($groups) {
			$groups['nexter-custom-fonts'] = __('Custom Fonts', 'nexter-extension');
			return $groups;
		});

		add_filter('elementor/fonts/additional_fonts', function ($fonts) {

			$settings = $this->nxt_adobe_get_settings();
			if ( empty($settings) || ! isset($settings['project_id']) || empty($settings['project_id']) ) {
				return $fonts;
			}
			if(!empty($settings) && isset($settings['project_id']) && !empty($settings['fonts'])){
				foreach($settings['fonts'] as $key => $val){
					if(!empty($val) && isset($val['css_names']) && isset($val['css_names'][0]) ){
						$fonts[$val['css_names'][0]] = 'nexter-custom-fonts';
					}
				}
			}
			return $fonts;
		});

		add_filter('fl_theme_system_fonts', [$this, 'nexter_add_adobe_font_fl_builder'] );
		add_filter('fl_builder_font_families_system', [$this, 'nexter_add_adobe_font_fl_builder'] );

		add_filter('tpgb-custom-fonts-list', function ($font) {
			if( class_exists('Nexter_Font_Families_Listing')){
				$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
			}else{
				$font_settings = $this->nexter_ext_adobe_font_lists();
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
    }

	public function add_custom_fonts_astra_customizer( $fonts_arr ){
		$font_settings = $this->nexter_ext_adobe_font_lists();
		
		if(!empty($font_settings)){
			foreach ( $font_settings as $font => $values ) {
				$fonts_arr[ $font ] = array(
					'fallback' => 'Arial, sans-serif',
					'weights'  => isset($values['weights']) ? $values['weights'] : [],
				);
			}
		}

		return $fonts_arr;
	}
	
	public function add_custom_fonts_blocksy_customizer( $fonts ){
		$font_settings = $this->nexter_ext_adobe_font_lists();
		
		if(!empty($font_settings)){
			foreach ( $font_settings as $font => $values ) {
				$fonts[] = array(
					'name' => $font,
					'fontType' => 'regular',
				);
			}
		}

		return $fonts;
	}

	public function nxt_kadence_custom_fonts( $system_fonts ){
		$font_settings = $this->nexter_ext_adobe_font_lists();

		if(!empty($font_settings)){
			foreach ( $font_settings as $font => $values ) {
				$system_fonts[ $font ] = array(
					'fallback' => 'Verdana, Arial, sans-serif',
					'weights' => isset($values['weights']) ? $values['weights'] : [],
				);
			}
		}
		return $system_fonts;
	}

	private function nxt_adobe_get_settings(){

		if(isset(self::$adobe_val) && !empty(self::$adobe_val)){
			return self::$adobe_val;
		}

		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['adobe-font']) && !empty($option['adobe-font']['switch']) && !empty($option['adobe-font']['values']) ){
			self::$adobe_val = $option['adobe-font']['values'];
		}

		return self::$adobe_val;
	}

	/*
	 * Nexter load adobe font Customizer
	 */
	public function nexter_ext_adobe_font_lists( $fonts_list = [] ){
		$font_val = [];
		$settings = $this->nxt_adobe_get_settings();
		if ( empty($settings) || ! isset($settings['project_id']) || empty($settings['project_id']) ) {
			return $fonts_list;
		}

		if(!empty($settings) && isset($settings['project_id']) && !empty($settings['fonts'])){
			foreach($settings['fonts'] as $key=> $val){
				if(!empty($val) && isset($val['css_names']) && isset($val['css_names'][0]) ){
					$font_variant = [];
					
					if(isset($val['variations']) && !empty($val['variations'])){
						foreach($val['variations'] as $variation){
							if($variation[0]=='n'){
								$variation = str_replace('n', '', $variation);
								$variation = $variation * 100;
							}else{
								$variation = str_replace('i', '', $variation);
								$variation = ($variation * 100).'italic';
							}
							$font_variant[] = $variation ;
						}
					}
					
					$font_val[ $val['css_names'][0] ]['weights'] = $font_variant;
					$font_val[ $val['css_names'][0] ][] = 'display';
				}
			}
			if(!empty($font_val)){
				$fonts_list = array_merge($fonts_list, $font_val);
			}
		}
		return $fonts_list;
	}

	public function nxt_adobe_font_wpblock(){
		$font_val = [];
		$font_val = $this->nxt_adobe_get_settings();
		if ( empty($font_val) || ! isset($font_val['project_id']) || empty($font_val['project_id']) ) {
			return;
		}
		wp_add_inline_style('wp-edit-blocks', '@import url("https://use.typekit.net/'.esc_attr($font_val['project_id']).'.css");' );
	}

	public function nxt_adobe_font_enqueue(){
		$font_val = [];
		$font_val = $this->nxt_adobe_get_settings();
		if ( empty($font_val) || ! isset($font_val['project_id']) || empty($font_val['project_id']) ) {
			return;
		}

		wp_enqueue_style( 'nexter-adobe-typekit','https://use.typekit.net/'.esc_attr($font_val['project_id']).'.css', [], NEXTER_EXT_VER );
	}

	/*
	 * Save Adobe Font and get Font Data
	 * @since 1.1.0
	 */
	public function nexter_ext_save_adobe_font_data_ajax(){
		check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$ext = ( isset( $_POST['extension_type'] ) ) ? sanitize_text_field( wp_unslash( $_POST['extension_type'] ) ) : '';
		$project_id = ( isset( $_POST['project_id'] ) ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
		
		if (! current_user_can('manage_options')) {
			wp_send_json_error();
		}

		$option_page = 'nexter_extra_ext_options';
		$get_option = get_option($option_page);

		if( !empty( $ext ) && $ext==='adobe-font' && !empty($project_id)){
			if( !empty( $get_option ) && isset($get_option[ $ext ]) ){

				$get_fonts = $this->get_adobe_font_api($project_id);
				if ( !$get_fonts ) {
					wp_send_json_error();
				}
	
				$settings = [
					'project_id' => $project_id,
					'fonts' => $get_fonts
				];

				$get_option[ $ext ]['values'] = $settings;
				update_option( $option_page, $get_option );
				wp_send_json_success( ['settings' => $settings] );
			}
		}else if(!isset($project_id) || empty($project_id)){
			$settings = [
				'project_id' => '',
				'fonts' => [],
			];
			$get_option[ $ext ]['values'] = $settings;
			update_option( $option_page, $get_option );
		}
		wp_send_json_error();
	}

	public function get_adobe_font_api( $project_id = ''){
		$adobe_typekit_url = 'https://typekit.com/api/v1/json/kits/' . $project_id . '/published';

		$response = wp_remote_get($adobe_typekit_url, [
			'timeout' => '30',
		]);

		if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ) {
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (! $data) {
			return null;
		}

		if ( !isset($data['kit']) || !isset($data['kit']['families'])) {
			return null;
		}

		return $data['kit']['families'];
	}

	/*
	 * Nexter Adobe Font Compatibility of Beaver Builder
	 * @since 1.1.0
	 */
	public function nexter_add_adobe_font_fl_builder($system_fonts) {
		$font_families = [];
		if( class_exists('Nexter_Font_Families_Listing')){
			$font_settings = Nexter_Font_Families_Listing::get_custom_fonts_load();
		}else{
			$font_settings = $this->nexter_ext_adobe_font_lists();
		}
		
		if (! isset($font_settings) || empty($font_settings)) {
			return $system_fonts;
		}
		
		if( !empty( $font_settings) ){
			foreach ($font_settings as $font_name => $family) {
				
				if (! is_array($family['weights']) || empty($family['weights']) || !isset($family['weights']) ) {
					continue;
				}
				
				$all_weights= array_map(function ($font_weight) {
					
					$init_variation = $font_weight;
					
					$font_weight = str_replace('normal', '', $font_weight);
					$font_weight = str_replace('italic', '', $font_weight);
	
					if ($init_variation[3] === 'i') {
						$font_weight .= 'i';
					}else if( $init_variation[0] === 'i'){
						$font_weight .= '400i';
					}
	
					return $font_weight;

				}, $family['weights']);
				
				$system_fonts[ $font_name ] = array(
					'fallback' => 'Verdana, Arial, sans-serif',
					'weights' => $all_weights
				);
			}
		}

		return $system_fonts;
	}
}

 new Nexter_Ext_Adobe_Font();