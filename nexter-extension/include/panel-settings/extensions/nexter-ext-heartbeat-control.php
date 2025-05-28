<?php 
/*
 * HeartBeat Control Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_HeartBeat_Control {
    
    public static $heartbeat_opt = [];

    private $current_url_path;

    /**
     * Constructor
     */
    public function __construct() {
        $this->nxt_get_post_order_settings();
        add_filter( 'heartbeat_settings', [$this, 'change_heartbeat_interval'], 99, 2 );
        add_action( 'admin_enqueue_scripts', [$this, 'disable_heartbeat_enqueue'], 99 );
        add_action( 'wp_enqueue_scripts', [$this, 'disable_heartbeat_enqueue'], 99 );
    }

    private function nxt_get_post_order_settings(){
        
		if(isset(self::$heartbeat_opt) && !empty(self::$heartbeat_opt)){
			return self::$heartbeat_opt;
		}

		$option = get_option( 'nexter_site_performance' );
		
		if(!empty($option) && isset($option['heartbeat-control']) && !empty($option['heartbeat-control']['switch']) && !empty($option['heartbeat-control']['values']) ){
			self::$heartbeat_opt = (array) $option['heartbeat-control']['values'];
		}
        
	}

    /**
	 * Set current URL path.
	 */
	public function get_url_path() {
		global $pagenow;

		$url = isset($_SERVER['HTTP_HOST'])
			? (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
			: get_admin_url() . $pagenow;

		$path = parse_url($url, PHP_URL_PATH);

		// Only keep the part after "/wp-admin"
		if (strpos($path, '/wp-admin/') !== false) {
			$wp_admin_path = strstr($path, '/wp-admin');
		} else {
			$wp_admin_path = '';
		}
		
		$this->current_url_path = $wp_admin_path;
	}

    /**
	 * Modify heartbeat tick interval based on context.
	 */
	public function change_heartbeat_interval($settings) {
		if (wp_doing_cron()) {
			return $settings;
		}

		$this->get_url_path();
		$settings['autostart'] = false;

		if (is_admin()) {
			
			if (in_array($this->current_url_path, ['/wp-admin/post.php', '/wp-admin/post-new.php'], true)) {
				if (isset(self::$heartbeat_opt['interval-post-edit']) && self::$heartbeat_opt['interval-post-edit'] != 'disable') {
					$settings['minimalInterval'] = absint(self::$heartbeat_opt['interval-post-edit']);
				}
			} elseif (isset(self::$heartbeat_opt['interval-admin-pages']) && self::$heartbeat_opt['interval-admin-pages'] != 'disable') {
				$settings['minimalInterval'] = absint(self::$heartbeat_opt['interval-admin-pages']);
			}
		} elseif (isset(self::$heartbeat_opt['interval-frontend']) && self::$heartbeat_opt['interval-frontend'] != 'disable') {
			$settings['minimalInterval'] = absint(self::$heartbeat_opt['interval-frontend']);
		}
		
		return $settings;
	}

	/**
	 * Disable heartbeat enqueue scripts as needed.
	 */
	public function disable_heartbeat_enqueue() {
		global $pagenow;

		if (is_admin()) {
			if (in_array($pagenow, ['post.php', 'post-new.php'], true)) {
				if (isset(self::$heartbeat_opt['interval-post-edit']) && self::$heartbeat_opt['interval-post-edit'] === 'disable') {
					wp_deregister_script('heartbeat');
				}
			} elseif (isset(self::$heartbeat_opt['interval-admin-pages']) && self::$heartbeat_opt['interval-admin-pages'] === 'disable') {
				wp_deregister_script('heartbeat');
			}
		} elseif (isset(self::$heartbeat_opt['interval-frontend']) && self::$heartbeat_opt['interval-frontend'] === 'disable') {
			wp_deregister_script('heartbeat');
		}
	}

}

 new Nexter_Ext_HeartBeat_Control();