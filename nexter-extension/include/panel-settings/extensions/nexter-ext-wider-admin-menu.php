<?php 
/*
 * Wilder Admin Menu Extension
 * @since 4.2.0
 */
defined('ABSPATH') or die();

 class Nexter_Ext_Wilder_Admin_Menu {
    
	public static $admin_menu_width = 160; // Default fallback width

    /**
     * Constructor
     */
    public function __construct() {
		$option = get_option( 'nexter_extra_ext_options' );
		
		if(!empty($option) && isset($option['wider-admin-menu']) && !empty($option['wider-admin-menu']['switch']) && !empty($option['wider-admin-menu']['values']) ){
			self::$admin_menu_width = $option['wider-admin-menu']['values'];

			add_action( 'admin_head', [$this, 'set_admin_menu_width'], 99 );
		}

    }

	public function set_admin_menu_width() {
		$width   = esc_attr(self::$admin_menu_width) . 'px';
		$version = get_bloginfo('version');
		$is_rtl  = is_rtl();
		$margin  = $is_rtl ? 'margin-right' : 'margin-left';
		$pos     = $is_rtl ? 'right' : 'left';

		?>
		<style>
			#wpcontent, #wpfooter {
				<?php echo $margin; ?>: <?php echo $width; ?>;
			}
			#adminmenuback, #adminmenuwrap, #adminmenu, #adminmenu .wp-submenu {
				width: <?php echo $width; ?>;
			}
			#adminmenu .wp-submenu {
				<?php echo $pos; ?>: <?php echo $width; ?>;
			}
			#adminmenu .wp-not-current-submenu .wp-submenu,
			.folded #adminmenu .wp-has-current-submenu .wp-submenu {
				min-width: <?php echo $width; ?>;
			}
			@media (min-width: 960px) {
				.woocommerce-layout__header,
				#e-admin-top-bar-root {
					width: calc(100% - <?php echo $width; ?>);
				}
			}
			.auto-fold .interface-interface-skeleton {
				<?php echo $pos; ?>: <?php echo $width; ?>;
			}
			.fb-header-nav {
				width: calc(100% - <?php echo $width; ?>) !important;
			}
		</style>
		<?php
	}

}

 new Nexter_Ext_Wilder_Admin_Menu();