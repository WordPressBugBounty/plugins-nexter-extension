<?php 
/*
 * RollBack Manager Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_RollBack_Manager {
    
	/**
	 * Plugins API url.
	 */
	const NXT_RB_PLUGIN_API = 'https://api.wordpress.org/plugins';

	/**
	 * Plugin File Url const
	 * */
	const NXT_RB_PLUGIN_FILE_URL = 'admin.php?page=nxt-rollback&type=plugin&plugin_file=';

	/**
	 * Themes repo url.
	 */
	const NXT_RB_THEME_API = 'https://themes.svn.wordpress.org';

	/**
	 * Theme File Url const
	 * */
	const NXT_RB_THEME_FILE_URL = 'admin.php?page=nxt-rollback&type=theme&theme_file=';

	/**
	 * Theme info const
	 * */
	const NXT_RB_THEME_INFO = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information';

	/**
	 * Theme update check const
	 * */
	const NXT_RB_THEME_UPDATE_CHECK = 'http://api.wordpress.org/themes/update-check/1.1/';

	/**
	 * Versions.
	 */
	var $versions = array();

	/**
	 * Current version.
	 */
	public $current_version;

    /**
     * Constructor
     */
    public function __construct() {
		ini_set('max_execution_time', '300');
		add_action( 'admin_enqueue_scripts', array( $this, 'rb_plugin_theme_script' ), 110 );
		add_filter( 'plugin_action_links', array($this, 'rb_plugin_action_links' ), 1, 4 );
		add_filter( 'theme_action_links', array($this, 'rb_theme_action_links' ), 20, 4 );
		add_action( 'set_site_transient_update_themes', array( $this, 'rb_theme_updates_list' ) );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'rb_prepare_themes_js' ) );
		add_action( 'wp_ajax_is_wordpress_theme', array( $this, 'rb_check_wp_theme' ) );
		add_action( 'admin_menu', array( $this, 'nxt_rollback_admin_menu' ), 20 );
    }

    public static function rb_plugin_theme_script(){
        wp_enqueue_style( 'nxt_rollback', NEXTER_EXT_URL . 'assets/css/admin/nxt-rollback.css', array(), NEXTER_EXT_VER );
        wp_enqueue_script( 'nxt_rollback', NEXTER_EXT_URL . 'assets/js/admin/nxt-rollback.js', array(), NEXTER_EXT_VER );
        wp_enqueue_script( 'updates' );

        wp_localize_script(
            'nxt_rollback', 'nxt_rb_vars', array(
              'ajaxurl'               => admin_url(),
              'nonce'                 => wp_create_nonce( 'nxt_ext_rollback_nonce' ),
              'btn_label'   => __( 'Rollback', 'nexter-extension' ),
              'non_rollbackable' => __( 'No Rollback Available: This is a non-WordPress.org theme.', 'nexter-extension' ),
            )
        );
    }

    /**
     * Plugin action rollback link.
     */
    public function rb_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
        // Filter plugin data.
        $data = apply_filters('nxt_ext_plugin_data', $plugin_data);
       
        // Ensure required data exists.
        if (
            empty($data['package']) ||
            !preg_match('/^https?:\/\/downloads\.wordpress\.org/', $data['package']) ||
            empty($data['Version'])
        ) {
            return $actions;
        }
        
        // Build rollback URL.
        $args = apply_filters('nxt_ext_plugin_query_args', [
            'current_version' => urlencode($data['Version']),
            'rollback_name'   => urlencode($data['Name']),
            'plugin_slug'     => urlencode($data['slug']),
            '_wpnonce'        => wp_create_nonce('nxt_ext_rollback_nonce'),
        ]);

        $url = add_query_arg($args, self::NXT_RB_PLUGIN_FILE_URL . $plugin_file);

        // Add rollback action.
        $actions['nxt-rollback'] = apply_filters(
            'nxt_ext_plugin_render',
            sprintf('<a href="%s">%s</a>', esc_url($url), __('Rollback', 'nexter-extension'))
        );
        
        return apply_filters('nxt_ext_plugin_action_links', $actions);
    }

    /**
     * Theme action rollback link.
     */
    public function rb_theme_action_links($actions, $theme, $context) {
        // Get cached rollback themes.
        $rollback = get_site_transient('nxt_ext_rollback_themes');
        if (!is_object($rollback)) {
            self::rb_theme_updates_list();
            $rollback = get_site_transient('nxt_ext_rollback_themes');
        }

        $slug = isset( $theme->template ) ? $theme->template : '';
        $response = isset( $rollback->response[ $slug ] ) ? $rollback->response[ $slug ] : '';

        // Skip if not a WP.org theme or no rollback package.
        if (!$slug || !$response || empty($response['package'])) {
            return $actions;
        }

        // Ensure theme version exists.
        $version = $theme->get('Version');
        if (!$version) return $actions;

        // Build rollback URL.
        $url = add_query_arg(
            apply_filters('nxt_ext_theme_query_args', [
                'theme_file'      => urlencode($slug),
                'current_version' => urlencode($version),
                'rollback_name'   => urlencode($theme->get('Name')),
                '_wpnonce'        => wp_create_nonce('nxt_ext_rollback_nonce'),
            ]),
            self::NXT_RB_THEME_FILE_URL . $response['package']
        );

        // Add rollback link.
        $actions['nxt-rollback'] = apply_filters(
            'nxt_ext_theme_markup',
            sprintf('<a href="%s">%s</a>', esc_url($url), __('Rollback', 'nexter-extension'))
        );

        return apply_filters('nxt_ext_theme_action_links', $actions);
    }

    public function rb_theme_updates_list() {
		require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-theme-list.php';
	}

	public function rb_prepare_themes_js( $themes_input ) {
        $themes_output   = [];
        $rollback_themes = [];
        $rollback_data   = get_site_transient( 'nxt_ext_rollback_themes' );
    
        // Fetch rollback data if transient is missing or invalid.
        if ( empty( $rollback_data ) || ! is_object( $rollback_data ) ) {
            self::rb_theme_updates_list();
            $rollback_data = get_site_transient( 'nxt_ext_rollback_themes' );
        }
    
        if ( is_object( $rollback_data ) && isset( $rollback_data->response ) ) {
            $rollback_themes = $rollback_data->response;
        }
    
        // Append hasRollback key to each theme.
        foreach ( $themes_input as $slug => $theme ) {
            $theme['hasRollback']   = isset( $rollback_themes[ $slug ] );
            $themes_output[ $slug ] = $theme;
        }
    
        return $themes_output;
    }

    public function rb_check_wp_theme() {
        $slug     = isset($_POST['theme']) ? sanitize_text_field( $_POST['theme'] ) : '';
        $request  = add_query_arg( 'request[slug]', $slug, self::NXT_RB_THEME_INFO );
        $response = wp_remote_get( $request );
    
        if ( is_wp_error( $response ) ) {
            echo 'error';
        } else {
            $body = $response['body'] ?? '';
            echo ( ! empty( $body ) && $body !== 'false' ) ? 'wp' : 'non-wp';
        }
    
        wp_die(); // Properly ends AJAX execution
    }

    /**
     * Fetch plugin or theme info from the appropriate API.
     */
    public static function rb_svn_tags( $type, $slug ) {

        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'nxt-rollback' || empty( $_GET['type'] ) ) {
            return null;
        }

        $response = null;

        // Build request URL based on the type.
        if ( $type === 'plugin' && $_GET['type'] === 'plugin' ) {
            $plugin_slug = self::set_plugin_slug();
            $url = self::NXT_RB_PLUGIN_API . '/info/1.0/' . $plugin_slug . '.json';
            $response = wp_remote_get( $url );
        } elseif ( $type === 'theme' && $_GET['type'] === 'theme' ) {
            $url = self::NXT_RB_THEME_API . '/' . $slug;
            $response = wp_remote_get( $url );
        }

        // Return null if request failed or response code is not 200.
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Extracts version data from JSON or HTML content.
     */
    public static function set_svn_versions_data( $html ) {
        global $versions;

        if ( empty( $html ) ) {
            return false;
        }

        $json_data = json_decode( $html );

        if ( $json_data && $html !== $json_data ) {
            // Handle JSON response with version keys.
            $versions = array_keys( (array) $json_data->versions );
        } else {
            // Fallback: Parse HTML and extract version links.
            $versions = [];

            libxml_use_internal_errors( true ); // Suppress HTML warnings.
            $dom = new DOMDocument();
            $dom->loadHTML( $html );
            libxml_clear_errors();

            foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
                $href = trim( $link->getAttribute( 'href' ), '/' );

                if ( strpos( $href, 'http' ) === false && $href !== '..' ) {
                    $versions[] = $href;
                }
            }
        }

        $versions = array_reverse( $versions );
        return $versions;
    }

    /**
     * Initializes plugin-related variables by fetching and setting version data.
     */
    private static function setup_plugin_vars() {
        $plugin_slug = self::set_plugin_slug(); // Ensure slug is consistent and only called once
        $plugin_tags = self::rb_svn_tags( 'plugin', $plugin_slug );

        self::set_svn_versions_data( $plugin_tags );
    }

    /**
     * Generate a version selection dropdown for plugins or themes.
     */
    public function versions_select( $type ) {
        global $versions;

        $this->versions = $versions;

        if ( empty( $this->versions ) ) {
            $error_msg = sprintf(
                __( 'It appears there are no versions to select. This is likely due to the %s author not using tags for their versions and only committing new releases to the repository trunk.', 'nexter-extension' ),
                esc_html( $type )
            );

            return apply_filters( 'versions_failure_html', '<div class="nxt-ext-error"><p>' . $error_msg . '</p></div>' );
        }

        usort( $this->versions, 'version_compare' );
        $this->versions = array_reverse( $this->versions );

        $html  = '<select class="nxt-ext-version-list w-100 form-select" id="nxt_rb_selected_version">';
        foreach ( $this->versions as $ver ) {
            $html .= sprintf(
                '<option class="nxt-ext-version-li" value="%s" name="%s_version">%s</option>',
                esc_attr( $ver ),
                esc_attr( $type ),
                esc_html( $ver )
            );
        }
        $html .= '</select>';

        // Add hidden input with latest version
        $latest_version = isset( $this->versions[0] ) ? esc_attr( $this->versions[0]) : '';
        $html .= '<input type="hidden" value="'.esc_attr($latest_version).'" name="'.esc_attr( $type ).'_version" id="nxt_selected_ver">';
            

        return apply_filters( 'versions_select_html', $html );
    }

    /**
     * Set and return the plugin slug from the `plugin_file` URL parameter.
     */
    public static function set_plugin_slug() {
        if ( empty( $_GET['plugin_file'] ) ) {
            return false;
        }

        // Handle optional current_version.
        if ( ! empty( $_GET['current_version'] ) ) {
            $version_parts    = explode( ' ', sanitize_text_field( $_GET['current_version'] ) );
            $current_version  = $version_parts[0];
            do_action( 'nxt_current_version', $current_version );
        }

        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file_path = WP_PLUGIN_DIR . '/' . sanitize_text_field( $_GET['plugin_file'] );

        if ( ! file_exists( $plugin_file_path ) ) {
            wp_die( __( 'The referenced plugin does not exist.', 'nexter-extension' ) );
        }

        $plugin_basename = plugin_basename( $plugin_file_path );
        $parts           = explode( '/', $plugin_basename );
        $plugin_slug     = $parts[0];

        $plugin_file_path = apply_filters( 'nxt_plugin_file', $plugin_file_path );
        $plugin_slug      = apply_filters( 'nxt_plugin_slug', $plugin_slug );

        return $plugin_slug;
    }

    /**
     * Admin Menu
     * Adds a 'hidden' menu item that is activated when the user selects WP Extended Rollback.
     */
    public function nxt_rollback_admin_menu() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'nxt-rollback' ) {
            $current_url = '#';  // Default URL.

            // Determine the current URL based on the 'type' and other parameters.
            if ( isset( $_GET['type'] ) ) {
                if ( $_GET['type'] === 'plugin' && isset( $_GET['plugin_file'] ) ) {
                    $current_url = esc_url( home_url( '/wp-admin/plugins.php' ) );
                } elseif ( $_GET['type'] === 'theme' && isset( $_GET['theme_file'] ) ) {
                    $current_url = esc_url( home_url( '/wp-admin/themes.php' ) );
                }
            }

            // Set the menu text with the appropriate URL.
            $menutxt = sprintf( __( '<a href="%s">Rollback Manager</a>', 'nexter-extension' ), $current_url );

            // Add the submenu page.
            add_submenu_page(
                'nexter_welcome',
                __( 'Rollback', 'nexter-extension' ),
                $menutxt,
                'update_plugins',
                'nxt-rollback',
                array( $this, 'render_html' )
            );
        }
    }

    /**
     * Html layout for plugin/theme version
     */
    public function render_html() {
        // Permissions check
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( __( 'You do not have sufficient permissions to perform rollbacks for this site.', 'nexter-extension' ) );
        }

        // Include necessary class for plugin upgrader
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Default arguments for the rollback
        $defaults = apply_filters(
            'nxt_ext_rollback_html_args', array(
                'page'           => 'nxt-rollback',
                'plugin_file'    => '',
                'action'         => '',
                'plugin_version' => '',
                'plugin'         => '',
            )
        );

        $args = wp_parse_args( $_GET, $defaults );

        // Check for rollback based on plugin version
        if ( ! empty( $args['plugin_version'] ) ) {
            // Security check for nonce
            check_admin_referer( 'nxt_ext_rollback_nonce' );
            
            // Include plugin rollback logic
            require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-plugin-upgrader.php';
            require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-action.php';

        } elseif ( ! empty( $args['theme_version'] ) ) {
           
            // Security check for nonce
            check_admin_referer( 'nxt_ext_rollback_nonce' );
            
            // Include theme rollback logic
            require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-theme-upgrader.php';
            require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-action.php';

        } else {
            // Default menu if no version specified
            check_admin_referer( 'nxt_ext_rollback_nonce' );
            require_once NEXTER_EXT_DIR . '/include/panel-settings/extensions/custom-fields/nxt-rb-menu.php';
        }
    }

    public static function init(){
        static $instance = null;
        if ( is_null( $instance ) ) {
            $instance = new Nexter_Ext_RollBack_Manager( static::class, NEXTER_EXT_VER );
             self::setup_plugin_vars();
        }
        return $instance;  
    }
}

Nexter_Ext_RollBack_Manager::init();