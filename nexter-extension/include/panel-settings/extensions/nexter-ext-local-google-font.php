<?php 
/*
 * Local Google Font Extension
 * @since 1.1.0
 */
defined('ABSPATH') or die();

class Nexter_Ext_Local_Google_Font_New {
    /**
     * Constructor
     */
    public function __construct() {
        // Load the configuration options
        $get_Option = get_option('nexter_site_performance');

		add_action( 'wp_ajax_nxt_clear_local_fonts', [ $this, 'nxt_clear_local_fonts_ajax'] );
        // Check if the local Google Fonts option is enabled
        if(isset($get_Option['nexter_google_fonts']) && empty($get_Option['nexter_google_fonts']['disable_gfont']) && !is_admin()){

            /* Self Host Google Font */
            if (!empty($get_Option['nexter_google_fonts']['self_host_gfont'])) {
                // Hook into the template redirect to start buffering for local Google Fonts
                add_action('template_redirect', array($this, 'nxt_start_buffering_for_local_fonts'));
            }

            /* Self Host Google Font */
            if (!empty($get_Option['nexter_google_fonts']['display_swap'])) {
                // Hook into the template redirect to start buffering for display swap
                add_action('template_redirect', array($this, 'nxt_start_buffering_for_display_swap'));
            }
        }

        // Check if the disable Google Fonts option is enabled
        if (isset($get_Option['nexter_google_fonts']) && !empty($get_Option['nexter_google_fonts']['disable_gfont'])) {
            // Only load on the frontend
            if (!is_admin()) {
                // Hook into the template redirect to disable Google Fonts
                add_action('template_redirect', array($this, 'nxt_disable_google_fonts'));
            }
        }

        //Local Google Font Old
        if( is_admin() ){
			add_action( 'enqueue_block_editor_assets', [ $this, 'head_style_local_google_font' ] );
		}
        add_action( 'wp_head', [ $this, 'head_style_local_google_font' ] );

        add_filter('elementor/fonts/groups', function ($groups) {
			$local_font = $this->check_nxt_ext_local_google_font(true);

			if ( !isset($local_font) || empty($local_font) ) {
				return $groups;
			}

			//unset($groups['googlefonts']);
			//unset($groups['earlyaccess']);

			$groups['nexter-local-google-fonts'] = __('Local Google Fonts', 'nexter-extension');

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
     * Start output buffering for local Google Fonts
     */
    public function nxt_start_buffering_for_local_fonts() {
        // Exclude certain requests like feed, preview, and admin pages
        if (is_feed() || is_preview() || is_customize_preview() || is_embed()) {
            return;
        }

        // Start buffering and process the output for local fonts
        ob_start(array($this, 'nxt_process_local_google_fonts'));
    }

    /**
     * Process the buffered output for local Google Fonts
     * @param string $html The HTML output of the page
     * @return string Processed HTML
     */
    public function nxt_process_local_google_fonts($html) {
        // Call the function to process local Google Fonts
        return $this->nxt_local_google_fonts($html);
    }

    /**
     * Handle local Google Fonts processing
     * @param string $html HTML content to search for Google Fonts
     * @return string Processed HTML with local Google Fonts
     */

    private function nxt_local_google_fonts($html) {
        global $wp_filesystem;
    
        // Initialize WP_Filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
    
        $directory = WP_CONTENT_DIR . '/nxt_assets/localfonts/';
    
        // Create fonts nxt_assets directory if it doesn't exist
        if (!$wp_filesystem->is_dir($directory)) {
            wp_mkdir_p($directory);
        }
    
        // Match Google Fonts links in the HTML
        preg_match_all('#<link[^>]+?href=(["\'])([^>]*?fonts\.googleapis\.com\/css.*?)\1.*?>#i', $html, $google_fonts, PREG_SET_ORDER);
    
        // Process each Google Font link
        if (!empty($google_fonts)) {
            foreach ($google_fonts as $google_font) {
                // Create unique file name based on the URL
                $file_name = substr(md5($google_font[2]), 0, 12) . '.google-fonts.min.css';
                $file_path = $directory . $file_name;
                $file_url = content_url('nxt_assets/localfonts/' . $file_name);
    
                // Download Google Fonts CSS file if not already cached
                if (!$wp_filesystem->exists($file_path)) {
                    $this->nxt_download_google_font($google_font[2], $file_path);
                }
    
                // Replace the Google Fonts link in the HTML with the local file path
                $new_google_font = str_replace($google_font[2], $file_url, $google_font[0]);
                $html = str_replace($google_font[0], $new_google_font, $html);
            }
        }
    
        return $html;
    }

    /**
     * Download and save the Google Fonts CSS file and the associated font files (.woff, .ttf)
     * @param string $url Google Fonts URL
     * @param string $file_path Path to save the downloaded CSS file
     * @return void
     */
    private function nxt_download_google_font($url, $file_path) {
        global $wp_filesystem;
    
        // Ensure URL uses HTTPS
        if (substr($url, 0, 2) === '//') {
            $url = 'https:' . $url;
        }
    
        // Fetch the Google Fonts CSS file
        $response = wp_remote_get($url);
    
        // Check if the response is valid
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }
    
        // Get the CSS content
        $css_content = wp_remote_retrieve_body($response);
    
        // Match @font-face src URLs for font files (.woff2, .woff, .ttf, etc.)
        preg_match_all('/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.(woff2|woff|ttf))\)/', $css_content, $matches);
    
        // Process and download each font file
        if (!empty($matches[1])) {
            foreach ($matches[1] as $font_url) {
                // Extract the font file name from the URL
                $font_file_name = basename($font_url);
    
                // Define the local file path to save the font file
                $font_file_path = WP_CONTENT_DIR . '/nxt_assets/localfonts/' . $font_file_name;
    
                // Download the font file
                $this->nxt_download_font_file($font_url, $font_file_path);
    
                // Replace the font URL in the CSS with the local path
                $local_url = content_url('nxt_assets/localfonts/' . $font_file_name);
                $css_content = str_replace($font_url, $local_url, $css_content);
            }
        }
    
        // Save the updated CSS content with local URLs
        $wp_filesystem->put_contents($file_path, $css_content, FS_CHMOD_FILE);
    }

    /**
     * Download a specific font file (woff2, woff, ttf) and store it locally
     * @param string $font_url URL of the font file to download
     * @param string $font_file_path Local file path to save the font file
     * @return void
     */
    private function nxt_download_font_file($font_url, $font_file_path) {
        global $wp_filesystem;
    
        // Fetch the font file
        $response = wp_remote_get($font_url);
    
        // Check if the response is valid
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }
    
        // Save the font file content
        $wp_filesystem->put_contents($font_file_path, wp_remote_retrieve_body($response), FS_CHMOD_FILE);
    }

    /**
     * Disable Google Fonts by removing their links from the HTML output
     */
    public function nxt_disable_google_fonts() {
        ob_start(array($this, 'nxt_remove_google_fonts'));
    }

    /**
     * Remove Google Fonts from the HTML output
     * @param string $html The HTML content of the page
     * @return string The HTML content without Google Fonts
     */
    public function nxt_remove_google_fonts($html) {
        // Use a regular expression to remove Google Fonts links
        $html = preg_replace('/<link[^<>]*\/\/fonts\.(googleapis|google|gstatic)\.com[^<>]*>/i', '', $html);
        return $html;
    }

    /* unlink local files */
	public static function nxt_clear_local_fonts(){
        $files = glob(WP_CONTENT_DIR . '/nxt_assets/localfonts/*');
        foreach($files as $file) {
            if(is_file($file)) {
                unlink($file);
            }
        }
    }

    //clear local fonts ajax action
    public static function nxt_clear_local_fonts_ajax() {
        check_ajax_referer( 'nexter_admin_nonce', 'nexter_nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 
					'content' => __( 'Insufficient permissions.', 'nexter-extension' ),
				)
			);
		}
        self::nxt_clear_local_fonts();

        wp_send_json_success(array(
            'message' => __('Local fonts cleared.', 'nexter-extension'), 
        ));
    }

    /**
     * Start output buffering for Google Fonts display swap
     */
    public function nxt_start_buffering_for_display_swap() {
        // Start buffering and process the output for Google Fonts display swap
        ob_start(array($this, 'nxt_process_display_swap'));
    }

    /**
     * Process the buffered output for Google Fonts display swap
     * @param string $html The HTML output of the page
     * @return string Processed HTML with display=swap for Google Fonts
     */
    public function nxt_process_display_swap($html) {
        return $this->nxt_display_swap($html);
    }

    /**
     * Add display=swap to Google Fonts links
     * @param string $html The HTML content of the page
     * @return string The HTML content with display=swap for Google Fonts
     */
    public static function nxt_display_swap($html) {
        // Match Google Fonts links in the HTML
        preg_match_all('#<link[^>]+?href=(["\'])([^>]*?fonts\.googleapis\.com\/css.*?)\1.*?>#i', $html, $google_fonts, PREG_SET_ORDER);
        
        // Process each Google Font link
        if (!empty($google_fonts)) {
            foreach ($google_fonts as $google_font) {
                // Remove any existing display parameter
                $new_href = preg_replace('/&display=(auto|block|fallback|optional|swap)/', '', html_entity_decode($google_font[2]));
                // Add display=swap to the URL
                $new_href .= '&display=swap';
                // Create new font tag with the updated URL
                $new_google_font = str_replace($google_font[2], $new_href, $google_font[0]);
                // Replace the original font tag in the HTML
                $html = str_replace($google_font[0], $new_google_font, $html);
            }
        }
        return $html;
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

// Instantiate the class to run the functionality
new Nexter_Ext_Local_Google_Font_New();