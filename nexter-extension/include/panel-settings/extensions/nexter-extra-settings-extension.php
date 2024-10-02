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
        add_filter( 'nexter-extension-extra-option-config', [$this, 'nxt_extension_option_config'], 10, 1 );

		$extension_option = get_option( 'nexter_extra_ext_options' );
		if( !empty($extension_option)){
			//Adobe Font
			if( isset($extension_option['adobe-font']) && !empty($extension_option['adobe-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-adobe-font.php';
			}
			//Local Google Font
			if( isset($extension_option['local-google-font']) && !empty($extension_option['local-google-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-local-google-font.php';
			}
			//Custom Upload Font
			if( isset($extension_option['custom-upload-font']) && !empty($extension_option['custom-upload-font']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-custom-upload-font.php';
			}
			//Disable Admin Settings
			if( isset($extension_option['disable-admin-setting']) && !empty($extension_option['disable-admin-setting']['switch']) ){
				require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-disable-admin-settings.php';
			}
		}
		
		if(!defined('NXT_VERSION')){
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-post-duplicator.php';
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-replace-url.php';
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-google-captcha.php';
		}

		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-performance-security-settings.php';
		require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-image-sizes.php';
		if(class_exists( '\Elementor\Plugin' ) ){
			require_once NEXTER_EXT_DIR . 'include/panel-settings/extensions/nexter-ext-disable-elementor-icons.php';
		}

		add_filter( 'nexter-extension-performance-option-config', [$this, 'nxt_performance_option_config']);
		add_filter( 'nexter-extension-security-option-config', [$this, 'nxt_security_option_config']);

        add_filter( 'upload_mimes', [$this, 'nxt_allow_mime_types']);
		add_filter('wp_check_filetype_and_ext', [$this, 'nxt_check_file_ext'], 10, 4);

		add_action( 'nexter_ext_extra_option' , [ $this ,'nexter_exten_performance_security_content'] , 10 , 1);
    }

    public function nxt_extension_option_config( $config = [] ){

        $ext_config = [ 
			'custom-upload-font' => [
				'title' => esc_html__( 'Custom Fonts Upload', 'nexter-ext' ),
				'description' => esc_html__( 'Upload your Custom Font Style to design your personalized website.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/custom-fonts.svg',
				'priority' => 2,
				'button' => true,
			],
			'adobe-font' => [
				'title' => esc_html__( 'Adobe Fonts', 'nexter-ext' ),
				'description' => esc_html__( 'Nexter Theme integrates with your Adobe Cloud TypeKit account to fetch the fonts directly. If you want to use Adobe fonts on your site, then connecting them will help you easily access them from the dashboard.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/adobe-font.svg',
				'priority' => 1,
				'button' => true,
			],
			'disable-admin-setting' => [
				'title' => esc_html__( 'Disable Admin Settings', 'nexter-ext' ),
				'description' => esc_html__( 'Hide WP Admin Bar and get rid of unnecessary extra notifications hijacking your WordPress dashboard based on user type.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/disable-admin.svg',
				'priority' => 3,
				'button' => true,
			],
			/* 'branded-wp-admin' => [
				'title' => esc_html__( 'Branded WordPress Admin', 'nexter-ext' ),
				'description' => esc_html__( 'White label WordPress completely with custom wp-admin page and your agency logo with your brand colors.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/branded-wordpress-admin.svg',
				'priority' => 9,
				'button' => false,
			], */
		];

		if( !defined('NXT_VERSION') || (defined('NXT_VERSION') && version_compare( NXT_VERSION, '2.0.4', '>' )) ){
			$ext_config['regenerate-thumbnails'] = [
				'title' => esc_html__( 'Regenerate Thumbnails', 'nexter-ext' ),
				'description' => esc_html__( 'Quickly recreate the image thumbnails on your website to ensure they are properly sized and optimized.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/regenerate-thumbnails.svg',
				'priority' => 8,
				'button' => false,
				'beta' => true
			];
		}
		if( defined('NXT_VERSION') ){
			$ext_config['local-google-font'] = [
				'title' => esc_html__( 'Local Google Fonts', 'nexter-ext' ),
				'description' => esc_html__( 'Self-Host your Google Font Locally. This helps to be GDPR-Compliant and avoid any 3rd party request from Google Servers, resulting to Faster Site Performance.', 'nexter-ext' ),
				'type' => 'free',
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/local-google-fonts.svg',
				'priority' => 1,
				'button' => true,
			];
		}
        $config = array_merge($config, $ext_config);

        return $config;
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

	/**
	 * Nexter Performance Option array
	 * @since 1.1.0
	 */

	public function nxt_performance_option_config(){
		$perconfig = [
			'advance-performance' => [
				'title' => esc_html__( 'Advanced Performance', 'nexter-ext' ),
				'description' => 'Set of handy options to fine tune your WordPress site performance, remove unnecessary code.',
				'type' => 'free',
				'priority' => 1,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/advance-performance.svg',
				'button' => false,
			],
			'disable-comments' => [
				'title' => esc_html__( 'Disable Comments', 'nexter-ext' ),
				'description' => 'Stop spam comments submissions completely, disable comments for Posts or any Custom Post Types',
				'type' => 'free',
				'priority' => 2,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/comment.svg',
				'button' => true,
			],
		];
		
		if( !defined('NXT_VERSION') || (defined('NXT_VERSION') && version_compare( NXT_VERSION, '2.0.4', '>' )) ){
			$perconfig['disabled-image-sizes'] = [
				'title' => esc_html__('Disable Image Sizes', 'nexter-ext'),
				'description' => 'Disable certain image sizes generated by WordPress, helping to optimize your website\'s performance by reducing unnecessary image variations.',
				'type' => 'free',
				'priority' => 3,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/disable-image-size.svg',
				'button' => true,
			];
			$perconfig['nexter-custom-image-sizes'] = [
				'title' => esc_html__('Register Custom Image Sizes', 'nexter-ext'),
				'description' => 'Add custom image sizes to your WordPress media settings, enabling you to use specific dimensions for images throughout your site.',
				'type' => 'free',
				'priority' => 3,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/register-custom-image-sizes.svg',
				'button' => true,
			];
			if(class_exists( '\Elementor\Plugin' ) ){
				$perconfig['disable-elementor-icons'] = [
					'title' => esc_html__('Disable Elementor Icons', 'nexter-ext'),
					'description' => 'Improve your Elementor page builder\'s performance by removing unnecessary icons.',
					'type' => 'free',
					'priority' => 5,
					'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/disable-elementor-icons.svg',
					'button' => true,
				];
			}
		}
		return $perconfig;
	}

	/**
	 * Nexter Security Option array
	 * @since 1.1.0
	 */

	public function nxt_security_option_config( $config = [] ){
		$secuconfig = [
			'advance-security' => [
				'title' => esc_html__( 'Advanced Security', 'nexter-ext' ),
				'description' => esc_html__('Make your WordPress site more secure with these handy options.','nexter-ext'),
				'type' => 'free',
				'priority' => 1,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/advance-security.svg',
				'button' => false,
			],
			'custom-login' => [
				'title' => esc_html__( 'Custom Login URL', 'nexter-ext' ),
				'description' => esc_html__('Hide your WP-Admin page from the world. Create your custom WP-Admin  your login page from spammers.','nexter-ext'),
				'type' => 'free',
				'priority' => 3,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/custom-login.svg',
				'button' => false,
			],
			'2-fac-authentication' => [
				'title' => esc_html__( '2-Factor Authentication', 'nexter-ext' ),
				'description' => esc_html__('Enhance the security of your WordPress login by requiring an additional verification step, typically through a mobile app or email, for added protection against unauthorized login attempts.','nexter-ext'),
				'type' => 'free',
				'priority' => 4,
				'svg' => NEXTER_EXT_URL.'assets/images/panel-icon/2-factor-auth.svg',
				'button' => true,
			],
		];
		
		$config = array_merge($config, $secuconfig);
		return $config;
	}

	/**
	 * Nexter Extnsion Extra Option render
	 * @since 1.1.0
	 */

	public function nexter_exten_performance_security_content( $tab_id = '' ){
		if( $tab_id == 'nexter_site_performance' ){
			$perfo_option = [];
			if( has_filter('nexter-extension-performance-option-config') ){
				$perfo_option = apply_filters('nexter-extension-performance-option-config' , $perfo_option);
			}
			echo '<div class="nxt-extra-opt-wrap nxt-mt-50">';
				echo '<div class="nxt-panel-row">';
					if( !empty($perfo_option) ){
						$columns = array_column($perfo_option, 'priority');
						array_multisort($columns, SORT_ASC, $perfo_option);

						foreach($perfo_option as $name => $data){
							echo '<div class="nxt-panel-col nxt-panel-col-33">';
								echo '<div class="nxt-panel-sec nxt-'.esc_attr($name).' nxt-p-20">';
									echo '<div class="nxt-extra-icon"><img src="'.esc_url($data['svg']).'" alt="'.esc_attr($name).'" /></div>';
									echo '<div class="nxt-extra-title">';
										echo wp_kses_post($data['title']);
										echo '<span class="nxt-desc-icon" >';
											echo '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_attr($name).'" /> ';
											echo '<div class="nxt-tooltip">'.wp_kses_post($data['description']).'</div>';
										echo '</span>';
									echo '</div>';
									echo '<button class="nxt-ext-btn nxt-ext-settings" data-ext="'.esc_attr($name).'"><span>'.esc_html__( 'Settings', 'nexter-ext' ).'</span></button>';
								echo '</div>';
							echo '</div>';
						}
					}
				echo '</div>';
			echo '</div>';
			
		}
		if( $tab_id == 'nexter_site_security' ){
			$nxt_secu_option = [];
			$extension = get_option( 'nexter_site_security' );
			if( has_filter('nexter-extension-security-option-config') ){
				$nxt_secu_option = apply_filters('nexter-extension-security-option-config' , $nxt_secu_option);
			}
			echo '<div class="nxt-extra-opt-wrap nxt-mt-50">';
				echo '<div class="nxt-panel-row">';
					if( !empty($nxt_secu_option) ){
						$columns = array_column($nxt_secu_option, 'priority');
						array_multisort($columns, SORT_ASC, $nxt_secu_option);

						foreach($nxt_secu_option as $name => $data){
							echo '<div class="nxt-panel-col nxt-panel-col-33">';
								echo '<div class="nxt-panel-sec nxt-'.esc_attr($name).' nxt-p-20">';
									echo '<div class="nxt-extra-icon"><img src="'.esc_url($data['svg']).'" alt="'.esc_attr($name).'" /></div>';
									echo '<div class="nxt-extra-title">';
										echo wp_kses_post($data['title']);
										echo '<span class="nxt-desc-icon" >';
											echo '<img src="'.esc_url( NEXTER_EXT_URL.'assets/images/panel-icon/desc-icon.svg').'" alt="'.esc_attr($name).'" /> ';
											echo '<div class="nxt-tooltip">'.wp_kses_post($data['description']).'</div>';
										echo '</span>';
									echo '</div>';
									if($data['button'] == true){
										if($name=='2-fac-authentication' && (!defined('NXT_PRO_EXT_VER') || (defined('NXT_PRO_EXT_VER') && version_compare( NXT_PRO_EXT_VER, '3.0.0', '<=' )) )){
											echo '<button class="nxt-ext-coming-soon" data-ext="'.esc_attr($name).'"><span>'.esc_html__( 'Coming Soon', 'nexter-ext' ).'</span></button>';
										}else{
											if( !empty($extension) && !empty($extension[ $name ]) && !empty($extension[ $name ]['switch'])){
												echo '<button class="nxt-ext-btn nxt-ext-deactivate" data-ext="'.esc_attr($name).'" data-enable-disable="deactive"><span>'.esc_html__( 'Deactivate', 'nexter-ext' ).'</span></button>';
												echo '<button class="nxt-ext-btn nxt-ext-settings" data-ext="'.esc_attr($name).'"><span><img src ="'.esc_url(NEXTER_EXT_URL.'assets/images/panel-icon/setting.svg').'" alt="Setting" /></span></button>';
											}else{
												echo '<button class="nxt-ext-btn nxt-ext-active" data-ext="'.esc_attr($name).'" data-enable-disable="active"><span>'.esc_html__( 'Enable', 'nexter-ext' ).'</span></button>';
											}
										}
									}else{
										echo '<button class="nxt-ext-btn nxt-ext-settings" data-ext="'.esc_attr($name).'"><span>'.esc_html__( 'Settings', 'nexter-ext' ).'</span></button>';
									}
								echo '</div>';
							echo '</div>';
						}
					}
				echo '</div>';
			echo '</div>';
		}
	}
}
new Nexter_Ext_Extra_Settings();