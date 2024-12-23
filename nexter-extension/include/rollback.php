<?php
/**
 * NxtExt Rollback version
 * @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if(!class_exists('NxtExt_Rollback')){

	class NxtExt_Rollback {
		
		/**
         * Member Variable
         * @var instance
         */
        private static $instance;
        
		protected $version;
		protected $plugin_slug;
		protected $plugin_name;
		protected $pakg_url;

        /**
         * Initiator
         */
        public static function get_instance() {
            if ( !isset( self::$instance ) ) {
                self::$instance = new self;
            } 
            return self::$instance;
        }
        
        /**
         * Constructor
         */
        public function __construct() {
			add_action( 'admin_post_nxtext_rollback', [ $this, 'nxtext_rollback_check_func' ] );
        }

		private function rollback_page_style() {
			?>
			<style>
				body#error-page {
					border: 0;
					background: #fff;
					padding: 0;
					border-radius: 5px;
				}
				.wrap {
					position: relative;
					margin: 0 auto;
					border: 2px solid #6f1ef1;
					border-radius: 5px;
					-webkit-box-shadow: 0 0 35px 0 rgb(154 161 171 / 15%);
					box-shadow: 0 0 35px 0 rgb(154 161 171 / 15%);
					padding: 0 20px;
					font-family: Courier, monospace;
					overflow: hidden;
					max-width: 850px;
				}
				.wrap h1 {
					text-align: center;
					color: #fff;
					background: #6f1ef1;
					padding: 60px;
					letter-spacing: 0.8px;
					border-radius: 5px;
				}
				.wrap h1 img {
					display: block;
					max-width: 250px;
					margin: auto auto 35px;
				}
				.nxtext-rb-subtitle{
					font-size: 18px;
    				font-family: monospace;
				}
			</style>
			<?php
		}

		public static function get_rollback_versions() {
			$versions_list = get_transient( 'nxtext_rollback_version_' . NEXTER_EXT_VER );
			if ( $versions_list === false ) {
				
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	
				$plugin_info = plugins_api(
					'plugin_information', [
						'slug' => 'nexter-extension',
					]
				);
	
				if ( empty( $plugin_info->versions ) || ! is_array( $plugin_info->versions ) ) {
					return [];
				}
	
				krsort( $plugin_info->versions );
	
				$versions_list = [];
	
				$index = 0;
				foreach ( $plugin_info->versions as $version => $download_link ) {
					if ( 25 <= $index ) {
						break;
					}
	
					$lowercase_version = strtolower( $version );
					$check_rollback_version = ! preg_match( '/(beta|rc|trunk|dev)/i', $lowercase_version );
	
					$check_rollback_version = apply_filters(
						'nxtext_check_rollback_version',
						$check_rollback_version,
						$lowercase_version
					);
	
					if ( ! $check_rollback_version ) {
						continue;
					}
	
					if ( version_compare( $version, NEXTER_EXT_VER, '>=' ) ) {
						continue;
					}
	
					$index++;
					$versions_list[] = $version;
				}
	
				set_transient( 'nxtext_rollback_version_' . NEXTER_EXT_VER, $versions_list, WEEK_IN_SECONDS );
			}
	
			return $versions_list;
		}

		public function nxtext_rollback_check_func(){
			check_admin_referer( 'nxtext_rollback' );

			if ( ! static::update_user_rollback_versions() ) {
				wp_die( esc_html__( 'Rollback versions not allowed', 'nexter-extension' ) );
			}

			$rv = self::get_rollback_versions();
			$version = isset($_GET['version']) && !empty($_GET['version']) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';
			if ( empty( $version ) || ! in_array( $version, $rv ) ) {
				wp_die( esc_html__( 'Error, Try selecting another version.', 'nexter-extension' ) );
			}

			$plugin_slug = basename( NEXTER_EXT_FILE, '.php' );
			
			$this->version = $version;
			$this->plugin_name = NEXTER_EXT_BASE;
			$this->plugin_slug = $plugin_slug;
			$this->pakg_url = sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', $this->plugin_slug, $this->version );
			
			$plugin_info = [
				'plugin_name' => $this->plugin_name,
				'plugin_slug' => $this->plugin_slug,
				'version' 	  => $this->version,
				'package_url' => $this->pakg_url,
			];

			$this->nxtext_update_plugin();
			$this->nxtext_upgrade_plugin();

			wp_die(
				'', esc_html__( 'Rollback to Previous Version', 'nexter-extension' ), [
					'response' => 200,
				]
			);
		}

		public function nxtext_update_plugin(){
			$update_plugins_data = get_site_transient( 'update_plugins' );

			if ( ! is_object( $update_plugins_data ) ) {
				$update_plugins_data = new \stdClass();
			}

			$plugin_info = new \stdClass();
			$plugin_info->new_version = $this->version;
			$plugin_info->slug = $this->plugin_slug;
			$plugin_info->package = $this->pakg_url;
			$plugin_info->url = 'http://nexterwp.com/';

			$update_plugins_data->response[ $this->plugin_name ] = $plugin_info;
			set_site_transient( 'update_plugins', $update_plugins_data );
		}

		public function nxtext_upgrade_plugin(){

			require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

			$this->rollback_page_style();

			// $logo_url = NEXTER_EXT_DIR . 'assets/images/nexter-builder.svg';

			$args = [
				'url' => 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $this->plugin_name ),
				'plugin' => $this->plugin_name,
				'nonce' => 'upgrade-plugin_' . $this->plugin_name,
				// 'title' => '<img src="' . $logo_url . '" alt="nxtext-logo"><div class="nxtext-rb-subtitle">' . esc_html__( 'Rollback to Previous Version', 'nexter-extension' ).'</div>',
				'title' => esc_html__( 'Nexter Extension', 'nexter-extension' ).'<div class="nxtext-rb-subtitle">' . esc_html__( 'Rollback to Previous Version', 'nexter-extension' ).'</div>',
			];

			$upgrader_plugin = new \Plugin_Upgrader( new \Plugin_Upgrader_Skin( $args ) );
			$upgrader_plugin->upgrade( $this->plugin_name );

		}

		/**
		 * Check current user can access the version control and rollback versions.
		 */
		public static function update_user_rollback_versions() {
			return current_user_can( 'activate_plugins' ) && current_user_can( 'update_plugins' );
		}
	}
	new NxtExt_Rollback();
}