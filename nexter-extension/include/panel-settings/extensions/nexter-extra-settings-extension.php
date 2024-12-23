<?php
/*
 * Nexter Extension Extra Settings
 * @since 1.1.0
 */
defined('ABSPATH') or die();

class Nexter_Ext_Extra_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
		
		$extension_option = get_option( 'nexter_extra_ext_options' );
		if( !empty($extension_option)){
			//Adobe Font
			if( isset($extension_option['adobe-font']) && !empty($extension_option['adobe-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-adobe-font.php';
			}
			//Local Google Font
			// if( isset($extension_option['local-google-font']) && !empty($extension_option['local-google-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-local-google-font.php';
			// }
			//Custom Upload Font
			if( isset($extension_option['custom-upload-font']) && !empty($extension_option['custom-upload-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-custom-upload-font.php';
			}
			//Disable Admin Settings
			if( isset($extension_option['disable-admin-setting']) && !empty($extension_option['disable-admin-setting']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-disable-admin-settings.php';
			}
		}
		
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-post-duplicator.php';
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-replace-url.php';
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-google-captcha.php';

		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-performance-security-settings.php';
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-image-sizes.php';
		if(class_exists( '\Elementor\Plugin' ) ){
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-disable-elementor-icons.php';
		}

        add_filter( 'upload_mimes', [$this, 'nxt_allow_mime_types']);
		add_filter('wp_check_filetype_and_ext', [$this, 'nxt_check_file_ext'], 10, 4);
    }

	/**
	 * Nexter Check Filetype and Extension File Woff, ttf, woff2
	 * @since 1.1.0 
	 */
	public function nxt_check_file_ext($types, $file, $filename, $mimes) {
		
		if (false !== strpos($filename, '.ttf')) {
			$types['ext'] = 'ttf';
			$types['type'] = 'application/x-font-ttf';
		}

		if (false !== strpos($filename, '.woff2')) {
			$types['ext'] = 'woff2';
			$types['type'] = 'font/woff2|application/octet-stream|font/x-woff2';
		}

		return $types;
	}

	/**
	 * Nexter Upload Mime Font File Woff, ttf, woff2
	 * @since 1.1.0 
	 */
	public function nxt_allow_mime_types( $mimes ) {
		$mimes['ttf'] = 'application/x-font-ttf';
		$mimes['woff2'] = 'font/woff2|application/octet-stream|font/x-woff2';
		
		return $mimes;
	}
}
new Nexter_Ext_Extra_Settings();