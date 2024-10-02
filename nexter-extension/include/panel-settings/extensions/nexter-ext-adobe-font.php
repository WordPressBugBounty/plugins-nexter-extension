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
			add_action( 'wp_ajax_nexter_ext_adobe_font_settings', [ $this, 'nexter_ext_adobe_font_settings_ajax'] );
			add_action('wp_ajax_nexter_ext_save_adobe_font_data', [ $this, 'nexter_ext_save_adobe_font_data_ajax'] );
		}else{
			add_action('wp_enqueue_scripts', [ $this, 'nxt_adobe_font_enqueue' ] );
		}
		
		add_action('init', [ $this, 'nxt_adobe_font_wpblock' ] );
		add_filter( 'nexter_custom_fonts_load' , [ $this,'nexter_ext_adobe_font_lists' ], 10, 1);

		add_filter('elementor/fonts/groups', function ($groups) {
			$groups['nexter-custom-fonts'] = __('Custom Fonts', 'nexter-ext');
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
	 * Nexter Adobe Font Setting Content
	 * @since 1.1.0
	 */
	public function nexter_ext_adobe_font_settings_ajax(){
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
		
        if( !empty( $ext ) && $ext == 'adobe-font' ){
			$font_val = [];
			if(!empty($extension_option) && isset($extension_option['adobe-font']) && !empty($extension_option['adobe-font']['values']) ){
				$font_val = $extension_option['adobe-font']['values'];
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
				
				$output .= '<div class="nxt-adobe-font-section">';
					/* translators: %s: Font url */
					$output .= '<div class="nxt-adobe-note">'.sprintf( __('You can get your <a href="%s" target="_blank" rel="noopener noreferrer">Project ID</a> Once you insert your Project ID and click the "Get Fonts" Fonts','nexter-ext'), esc_url('https://fonts.adobe.com/my_fonts#web_projects-section') ).'</div>';
					$output .= '<label class="nxt-adobe-project-label">'.esc_html__('Project ID', 'nexter-ext').'</label>';
					$output .= '<div class="nxt-adobe-font-form">';
						$output .= '<input type="text" name="nxt-adobe-project-id" class="nxt-adobe-project-text" autocomplete="off" placeholder="'.esc_html__( 'Enter Project ID','nexter-ext').'" value="'.(!empty($font_val) && !empty($font_val['project_id']) ? esc_attr($font_val['project_id']) : '').'" />';
						$output .= '<button type="button" class="nxt-sync-save-adobe-font"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="#fff" stroke-width=".781" stroke-linejoin="round" ><path d="M15.833 17.5H4.167c-.442 0-.866-.176-1.179-.488s-.488-.736-.488-1.179V4.167c0-.442.176-.866.488-1.179S3.725 2.5 4.167 2.5h9.167L17.5 6.667v9.167c0 .442-.176.866-.488 1.179s-.736.488-1.179.488z"/><path d="M14.167 17.5v-6.667H5.833V17.5m0-15v4.167H12.5" stroke-linecap="round"/></svg>'.esc_html__('Get Font','nexter-ext').'</button>';
					$output .= '</div>';
					if(!empty($font_val)){
						$output .= $this->adobe_font_render_html( $font_val );
					}
				$output .= '</div>';
				
			$output .= '</div>';

			wp_send_json_success(
				array(
					'content'	=> $output,
				)
			);
		}
		wp_send_json_error();
	}

	public function adobe_font_render_html( $settings=[]){
		$output = '';
		if( !empty($settings) && !empty($settings['fonts']) ){
			foreach($settings['fonts'] as $key=> $val){
				if(!empty($val) && isset($val['name'])){
					$font_variant = '';
					if(isset($val['variations']) && !empty($val['variations'])){
						foreach($val['variations'] as $variation){
							if($variation[0]=='n'){
								$variation = str_replace('n', '', $variation);
								$variation = $variation * 100;
								if($variation == 400){
									$variation = 'Regular';
								}
							}else{
								$variation = str_replace('i', '', $variation);
								$variation = ($variation * 100).' italic';
							}
							$font_variant .= $variation.', ';
						}
					}
					$output .= '<li>';
						$output .= '<div class="adobe-font-name"><span><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M23 1.02661H1V22.9733H23V1.02661Z" fill="#142500"/><path fill-rule="evenodd" clip-rule="evenodd" d="M0 24H24V0H0V24ZM1 1.02667H23V22.9733H1V1.02667Z" fill="#86EB00"/><path d="M7.16599 7.16015H4.39265C4.31265 7.16015 4.27932 7.12682 4.29265 7.02682L4.49265 5.60682C4.50599 5.52015 4.53932 5.48682 4.62599 5.48682H11.5527C11.6527 5.48682 11.686 5.52015 11.6993 5.62015L11.8327 7.02682C11.846 7.12682 11.7993 7.16015 11.7127 7.16015H8.95932V16.6335C8.95932 16.7201 8.92599 16.7668 8.83265 16.7668H7.29265C7.19932 16.7668 7.16599 16.7335 7.16599 16.6335V7.16015Z" fill="#86EB00"/><path d="M14.7187 12.0534L17.2387 8.54675C17.3054 8.46675 17.3254 8.43342 17.4054 8.43342H19.0854C19.1854 8.43342 19.2187 8.50008 19.152 8.58008C18.7587 9.10675 16.9454 11.4067 16.352 12.1534C16.3422 12.1682 16.337 12.1856 16.337 12.2034C16.337 12.2212 16.3422 12.2386 16.352 12.2534L19.5454 16.6334C19.5787 16.7001 19.5654 16.7667 19.4654 16.7667H17.5187C17.4837 16.7718 17.448 16.7646 17.4176 16.7464C17.3872 16.7282 17.3641 16.7 17.352 16.6667C16.812 15.9267 15.412 13.9267 14.7187 12.8934V16.6534C14.7187 16.7334 14.6987 16.7667 14.5987 16.7667H13.052C12.932 16.7667 12.9187 16.7334 12.9187 16.6334V5.58675C12.9187 5.54008 12.932 5.48675 13.032 5.48675H14.5987C14.6149 5.48495 14.6313 5.48682 14.6467 5.49221C14.662 5.4976 14.676 5.50639 14.6875 5.51791C14.6991 5.52944 14.7078 5.5434 14.7132 5.55878C14.7186 5.57416 14.7205 5.59055 14.7187 5.60675V12.0534Z" fill="#86EB00"/></svg></span>'.esc_html($val['name']).'</div>';
						$output .= '<div class="adobe-font-variant">'.esc_html__('Variation :','nexter-ext').' '.esc_html(substr_replace($font_variant ,"",-2)).'</div>';
					$output .= '</li>';
				}
			}
		}
		
		return (!empty($output) ? '<ul class="nxt-adobe-font-list">'.($output).'</ul>' : '');
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
		$project_id = ( isset( $_POST['project_id'] ) ) ? wp_unslash( $_POST['project_id'] ) : '';
		
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

				$font_html = $this->adobe_font_render_html($settings);
				if(!empty($font_html)){
					$settings['render'] = $font_html;
				}
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