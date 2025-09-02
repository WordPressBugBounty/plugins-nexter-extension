<?php 
/*
 * Clean Up Admin Bar Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Elementor_Adfree {
    
	public static $ele_opt = [];

    /**
     * Constructor
     */
    public function __construct() {
		$this->nxt_get_post_order_settings();
		if (! did_action('elementor/loaded')) {
			return false;
		}
		add_action( 'elementor/editor/before_enqueue_styles', [$this, 'nxt_elementor_hide_css'], 99 );
		add_action( 'admin_head', [$this, 'nxt_elementor_hide_css'], 99 );
		add_action('admin_menu', [$this, 'elementor_pro_hide_menu'], 801);
		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_pro', self::$ele_opt, true ) ) {
			add_filter( 'elementor_pro/admin/notices/show_upgrade_notice', '__return_false' );
			add_filter( 'elementor/admin/notices/show_beta_notice', '__return_false' );
			add_filter( 'elementor/admin/notices/show_go_pro_notice', '__return_false' );
			add_filter( 'elementor/admin/show_upgrade_to_pro_notice', '__return_false' );
			add_filter( 'elementor_pro/show_upgrade_link', '__return_false' );
		}
		
		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_ai', self::$ele_opt, true ) ) {
			add_action( 'wp_enqueue_scripts', function() {
				if ( \Elementor\Plugin::instance()->preview->is_preview_mode() ) {
					$this->nxt_elementor_hide_css();
				}
			} );
		}

    }

	private function nxt_get_post_order_settings(){

		if(isset(self::$ele_opt) && !empty(self::$ele_opt)){
			return self::$ele_opt;
		}

		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['elementor-adfree']) && !empty($option['elementor-adfree']['switch']) && !empty($option['elementor-adfree']['values']) ){
			self::$ele_opt = (array) $option['elementor-adfree']['values'];
		}

		return self::$ele_opt;
	}

	public function nxt_elementor_hide_css(){
		$css = '';

		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_ai', self::$ele_opt, true ) ) {
			$css .= '.e-ai-button, .elementor-control-ai, .elementor-ai-box, .elementor-ai-cta, #e-image-ai-media-library, .e-ai-layout-button, .e-text-ai,.elementor-control-notice.elementor-descriptor, .elementor-control-notice-type-info,.elementor-ai-button, .e-ai-promo__cta,.elementor-ai-callout, .e-ai-promo,.e-featured-image-ai { display: none !important; }';
		}

		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_imageopt', self::$ele_opt, true ) ) {
			$css .= '.elementor-control-promotions,.elementor-control-media__promotions,.e-image-ai-insert-media,.e-excerpt-ai,.e-notice--cta,.elementor-control-media__promotions.elementor-descriptor,.elementor-control-media__promotions.elementor-descriptor[role="alert"],.elementor-send-notice, .elementor-send-banner{ display: none !important; }';
		}

		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_pro', self::$ele_opt, true ) ) {
			$css .= '#elementor-panel-category-pro-elements, #elementor-panel-get-pro-elements, #elementor-panel-elements-navigation div[data-tab="global"], #elementor-panel-global, .elementor-panel-menu-items .elementor-panel-menu-item.elementor-panel-menu-item-site-editor, #elementor-template-library-templates[data-template-source="remote"] .elementor-template-library-template.elementor-template-library-pro-template, a.elementor-plugins-gopro, .elementor-context-menu-list__group-save, .elementor-control-custom_logo_promotion, .elementor-panel-menu-item.elementor-panel-menu-item-notes, div[data-collapse_id="section_custom_css_pro"], div[data-collapse_id="section_custom_css_pro"] + div, .elementor-control-custom_css_pro, .elementor-control-section_custom_css_pro, .elementor-control-section_custom_attributes_pro, .elementor-control-dynamic-switcher, .e-ai-button, .elementor-panel-category .elementor-element-wrapper.elementor-element--promotion, #elementor-panel-get-pro-elements-sticky, #elementor-navigator__footer__promotion, .elementor-pro-upsell, .e-app-banner, .elementor-template-library-cta, .elementor-panel-footer__pro-button, .elementor-panel-saver__button-upgrade, .elementor-panel-heading-promotion,.e-notice-bar, .elementor-template-library-filter-select-source .source-option[data-source="cloud"] { display: none !important; }';
		}

		if ( $css ) {
			$safe_css = wp_kses( wp_strip_all_tags( $css ), [] ); // No HTML tags allowed
    		echo '<style>' . $safe_css . '</style>';
		}
	}

	public function elementor_pro_hide_menu() {
		if (! did_action('elementor/loaded')) {
			return false;
		}
		if ( !empty(self::$ele_opt) && in_array( 'hide_elementor_pro', self::$ele_opt, true ) ) {
			remove_submenu_page('elementor', 'e-form-submissions');
			remove_submenu_page('elementor', 'elementor_custom_fonts');
			remove_submenu_page('elementor', 'elementor_custom_icons');
			remove_submenu_page('elementor', 'elementor_custom_custom_code');
			remove_submenu_page('elementor', 'go_elementor_pro');
		}
	}

}

 new Nexter_Ext_Elementor_Adfree();