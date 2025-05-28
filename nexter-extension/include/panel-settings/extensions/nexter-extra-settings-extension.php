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

			//Content Post Order
			if( isset($extension_option['content-post-order']) && !empty($extension_option['content-post-order']['switch'])){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-content-post-order.php';
			}

			//Clean-Up Admin Bar
			if( isset($extension_option['clean-up-admin-bar']) && !empty($extension_option['clean-up-admin-bar']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-clean-up-admin-bar.php';
			}

			//Wilder Admin Menu Width
			if( isset($extension_option['wider-admin-menu']) && !empty($extension_option['wider-admin-menu']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-wider-admin-menu.php';
			}
			
			//Disable Gutenberg
			if( isset($extension_option['disable-gutenberg']) && !empty($extension_option['disable-gutenberg']['switch'])){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-disable-gutenberg.php';
			}

			//Redirect 404 Page
			if( isset($extension_option['redirect-404-page']) && !empty($extension_option['redirect-404-page']['switch']) && !defined( 'NXT_PRO_EXT' ) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-redirect-404-page.php';
			}
			
			//Rollback Manager
			if( isset($extension_option['rollback-manager']) && !empty($extension_option['rollback-manager']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-rollback-manager.php';
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