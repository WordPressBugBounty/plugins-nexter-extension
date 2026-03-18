<?php
/**
 * Bulk Image Optimisation Extension
 *
 * Adds Media → Bulk Images menu for bulk optimising images when Image Optimisation is enabled.
 *
 * @since 4.2.0
 */
defined( 'ABSPATH' ) || exit;

/**
 * Class Nexter_Ext_Bulk_Images
 */
class Nexter_Ext_Bulk_Images {

	/** Singleton instance */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Nexter_Ext_Bulk_Images
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$settings = $this->get_image_optimizer_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_bulk_images_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_nxt_bulk_images_get_queue', array( $this, 'ajax_get_queue' ) );
	}

	/**
	 * Get image optimiser settings.
	 * Same settings used by Image Upload Optimise (on upload) and Bulk Images (convert).
	 *
	 * @return array
	 */
	private function get_image_optimizer_settings() {
		if ( ! class_exists( 'Nexter_Ext_Image_Upload_Optimization' ) ) {
			return array( 'enabled' => false );
		}
		return Nexter_Ext_Image_Upload_Optimization::get_instance()->get_settings();
	}

	/**
	 * Add Bulk Images submenu under Media.
	 */
	public function add_bulk_images_menu() {
		add_submenu_page(
			'upload.php',
			__( 'Bulk Image Optimisation', 'nexter-extension' ),
			__( 'Bulk Images', 'nexter-extension' ),
			'upload_files',
			'nxt_bulk_images',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JS for Bulk Images page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'media_page_nxt_bulk_images' !== $hook_suffix ) {
			return;
		}

		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style(
			'nxt-bulk-images',
			NEXTER_EXT_URL . 'assets/css/admin/nxt-bulk-images' . $min . '.css',
			array(),
			NEXTER_EXT_VER
		);
		wp_enqueue_script(
			'nxt-bulk-images',
			NEXTER_EXT_URL . 'assets/js/admin/nxt-bulk-images' . $min . '.js',
			array( 'jquery' ),
			NEXTER_EXT_VER,
			true
		);
		wp_localize_script(
			'nxt-bulk-images',
			'nxtBulkImages',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'nxt_bulk_images' ),
				'restoreNonce' => wp_create_nonce( 'nexter_admin_nonce' ),
				'convertNonce' => wp_create_nonce( 'nxt_ext_image_convert' ),
				'i18n'         => array(
					'startBulk'        => __( 'Start Bulk Optimisation', 'nexter-extension' ),
					'optimizing'       => __( 'Optimising Images', 'nexter-extension' ),
					'reoptimise'       => __( 'Reoptimise Images', 'nexter-extension' ),
					'readyToOptimize'  => __( 'Ready to optimise %d images and save storage space', 'nexter-extension' ),
					'imagesOptimized'  => __( '%d of %d images optimised', 'nexter-extension' ),
					'needToOptimise'   => __( 'Need to Optimise', 'nexter-extension' ),
					'done'             => __( 'Done', 'nexter-extension' ),
					'failed'           => __( 'Failed', 'nexter-extension' ),
					'readyToOptimise'  => __( 'Ready to Optimise', 'nexter-extension' ),
					'completed'        => __( 'Completed', 'nexter-extension' ),
					'errorsFound'      => __( 'Errors Found', 'nexter-extension' ),
					'optimising'       => __( 'Optimising...', 'nexter-extension' ),
					'before'           => __( 'Before', 'nexter-extension' ),
					'after'            => __( 'After', 'nexter-extension' ),
					'originalSize'     => __( 'Original Size', 'nexter-extension' ),
					'saved'            => __( '%s% saved', 'nexter-extension' ),
					'itemsLeft'        => __( '%d items left', 'nexter-extension' ),
					'retryFailedCount' => __( 'Retry Failed (%d)', 'nexter-extension' ),
					'toastAllComplete' => __( 'All Optimised Successfully', 'nexter-extension' ),
					'toastError'       => __( 'An error occurred. Please try again.', 'nexter-extension' ),
					'noImages'         => __( 'No images to optimise.', 'nexter-extension' ),
					'loadMore'          => __( 'Load More', 'nexter-extension' ),
					'loadingMore'       => __( 'Loading...', 'nexter-extension' ),
					'unlimited'         => __( 'Unlimited', 'nexter-extension' ),
					'monthlyLimitReached' => __( 'Monthly Limit Reached', 'nexter-extension' ),
					'monthlyLimitNotice' => __( 'You have reached your monthly limit of %d images. Upgrade to Pro for unlimited optimisation or wait until next month.', 'nexter-extension' ),
					'upgradeToPro'      => __( 'Upgrade to Pro', 'nexter-extension' ),
					'failedLoadQueue'   => __( 'Failed to load queue.', 'nexter-extension' ),
					'bulkRunningInOtherTab' => __( 'Optimisation in progress in another tab or window. Only one bulk optimisation can run at a time. This page will update when it finishes.', 'nexter-extension' ),
					'bulkAlreadyRunning'    => __( 'Optimisation is already running in another tab or window. Please wait for it to complete.', 'nexter-extension' ),
				),
			)
		);
	}

	/**
	 * Render Bulk Images admin page.
	 */
	public function render_page() {
		$settings_url = admin_url( 'admin.php?page=nexter_welcome#/performance/image-upload-optimize' );
		$queue_data = array( 'queue' => array(), 'stats' => array( 'total' => 0, 'optimized' => 0, 'unoptimized' => 0, 'skipped' => 0, 'storage_saved' => 0, 'avg_compression' => 0, 'total_savings_mb' => 0, 'monthly_count' => 0, 'monthly_limit' => Nexter_Ext_Image_Optimization_Limit::get_instance()->get_monthly_limit(), 'is_pro' => false ), 'total_unoptimized' => 0, 'has_more' => false );
		if ( current_user_can( 'upload_files' ) ) {
			$queue_data = $this->get_queue_data( 6, array() );
		}
		$s = $queue_data['stats'];
		$stat_saved     = ( ! empty( $s['storage_saved'] ) && $s['storage_saved'] > 0 ) ? size_format( (int) $s['storage_saved'], 2 ) : '-';
		$stat_compression = ( ! empty( $s['avg_compression'] ) && $s['avg_compression'] > 0 ) ? $s['avg_compression'] . '%' : '-';
		$stat_count     = ( isset( $s['optimized'], $s['total'] ) ) ? (int) $s['optimized'] . '/' . (int) $s['total'] : '0/0';
		$stat_remaining = isset( $s['unoptimized'] ) ? (int) $s['unoptimized'] : 0;
		$stat_skipped   = isset( $s['skipped'] ) ? (int) $s['skipped'] : 0;
		$stat_savings   = ( ! empty( $s['total_savings_mb'] ) && $s['total_savings_mb'] > 0 ) ? $s['total_savings_mb'] . ' MB' : '-';
		$monthly_count  = isset( $s['monthly_count'] ) ? (int) $s['monthly_count'] : 0;
		$monthly_limit  = isset( $s['monthly_limit'] ) ? (int) $s['monthly_limit'] : Nexter_Ext_Image_Optimization_Limit::get_instance()->get_monthly_limit();
		$is_pro         = ! empty( $s['is_pro'] );
		$monthly_usage  = $is_pro ? __( 'Unlimited', 'nexter-extension' ) : $monthly_count . ' / ' . $monthly_limit;
		$remaining      = $is_pro ? 999999 : max( 0, $monthly_limit - $monthly_count );
		$resets_in_days = isset( $s['resets_in_days'] ) ? (int) $s['resets_in_days'] : 0;

		// Check Nexter Pro plugin status for CTA button.
		$pro_plugin_file = 'nexter-pro-extensions/nexter-pro-extensions.php';
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$pro_installed        = file_exists( WP_PLUGIN_DIR . '/' . $pro_plugin_file );
		$pro_active           = $pro_installed && is_plugin_active( $pro_plugin_file );
		$show_activate_button = $pro_installed && ! $pro_active;

		$limit_cta_url    = $show_activate_button ? wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . $pro_plugin_file ), 'activate-plugin_' . $pro_plugin_file ) : 'https://nexterwp.com/pricing/';
		$limit_cta_target = $show_activate_button ? '_self' : '_blank';
		$limit_cta_label  = $show_activate_button ? __( 'Activate Now', 'nexter-extension' ) : __( 'Upgrade to Pro', 'nexter-extension' );
		
		$queue_count    = count( $queue_data['queue'] );
		$has_optimized   = ! empty( $s['optimized'] ) && (int) $s['optimized'] > 0;
		$has_unoptimized = $queue_count > 0 || ( ! empty( $s['unoptimized'] ) && (int) $s['unoptimized'] > 0 );
		$all_optimized   = $has_optimized && empty( $s['unoptimized'] );
		$show_start      = ! $all_optimized && $has_unoptimized;
		$stat_status      = $all_optimized ? __( 'Completed', 'nexter-extension' ) : __( 'Ready to Optimise', 'nexter-extension' );
		$stat_status_icon = $all_optimized ? 'icon-completed' : 'icon-ready';
		$stat_status_svg  = $all_optimized
			? '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#058645" fill-opacity=".1" rx="10"/><path stroke="#00a63e" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M24 34c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10"/><path stroke="#00a63e" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 24 2 2 4-4"/></svg>'
			: '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#f5f7fe" rx="10"/><g clip-path="url(#adfg)"><path stroke="#1717cc" stroke-width="1.501" d="M24 34c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10Z"/></g><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.602" d="M24 19.996V24l3.203 1.602"/><defs><clipPath id="adfg"><path fill="#fff" d="M13.242 13.242h21.516v21.516H13.242z"/></clipPath></defs></svg>';
		?>
		<script type="application/json" id="nxt-bulk-initial-data"><?php echo wp_json_encode( $queue_data ); ?></script>
		<div class="wrap nxt-bulk-images-wrap">
			<div class="nxt-bulk-images-header">
				<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="nxt-bulk-back"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"><path stroke="#1a1a1a" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 12H5M12 19l-7-7 7-7"/></svg>
				<h1 class="nxt-bulk-title"><?php esc_html_e( 'Bulk Image Optimisation', 'nexter-extension' ); ?></h1></a>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="nxt-bulk-settings-btn">
				<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 12 12"><g stroke="#1a1a1a" clip-path="url(#a)"><path stroke-linecap="round" d="m10.659 3.57-.247-.428c-.187-.324-.28-.485-.439-.55s-.338-.014-.697.088l-.61.172a1 1 0 0 1-.68-.085l-.168-.097a1 1 0 0 1-.394-.484l-.167-.498c-.11-.33-.165-.495-.296-.59s-.304-.094-.651-.094h-.557c-.348 0-.521 0-.652.094-.13.095-.185.26-.295.59l-.167.498a1 1 0 0 1-.394.484l-.169.097a1 1 0 0 1-.679.085l-.61-.172c-.36-.102-.539-.153-.698-.088s-.252.226-.438.55l-.247.429c-.175.303-.263.455-.246.617s.134.292.369.552l.515.576c.126.16.216.438.216.688s-.09.528-.216.687l-.515.577c-.235.26-.352.39-.369.552s.07.313.246.617l.247.428c.186.324.28.486.438.55.16.065.339.014.698-.087l.61-.172a1 1 0 0 1 .68.084l.168.098a1 1 0 0 1 .394.483l.167.5c.11.33.164.494.295.589s.304.094.652.094h.557c.347 0 .52 0 .651-.094.131-.095.186-.26.296-.59l.167-.499a1 1 0 0 1 .394-.483l.168-.098a1 1 0 0 1 .68-.084l.61.172c.359.101.538.152.697.088.159-.065.252-.227.439-.55l.247-.429c.175-.304.262-.455.245-.617s-.134-.292-.368-.552l-.516-.577c-.126-.16-.215-.437-.215-.687s.09-.528.215-.688l.516-.576c.234-.26.351-.39.368-.552s-.07-.314-.245-.617Z"/><path d="M7.76 6a1.75 1.75 0 1 1-3.5 0 1.75 1.75 0 0 1 3.5 0Z"/></g><defs><clipPath id="a"><path fill="#fff" d="M0 0h12v12H0z"/></clipPath></defs></svg>
					<?php esc_html_e( 'Image Optimisation Settings', 'nexter-extension' ); ?>
				</a>
			</div>

			<div class="nxt-bulk-content">
				<div class="nxt-bulk-center-card">
					<div class="nxt-bulk-center-header">
						<div class="nxt-bulk-center-icon">
							<?php 
							$theme_logo = '';
							if(defined('NXT_PRO_EXT') || defined('TPGBP_VERSION')){
								$options = get_option( 'nexter_white_label' );
								if(isset($options['theme_logo']) && !empty($options['theme_logo'])){
									$theme_logo = $options['theme_logo'];
								}
							}
							if(empty($theme_logo)){
								echo '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 40 40"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h20c5.523 0 10 4.477 10 10v20c0 5.523-4.477 10-10 10H10C4.477 40 0 35.523 0 30z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 22a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 21 18h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 19 22z"/></svg>';
							}else{
								echo '<img src="'.esc_url($theme_logo).'" alt="'.esc_attr__( 'Image Optimisation Center', 'nexter-extension' ).'" style="width: 40px;height: 40px;object-fit: cover;" />';
							}
							?>
						</div>
						<div class="nxt-bulk-center-text">
							<?php 
							$brand_name = __( 'Nexter', 'nexter-extension' );
							if(defined('NXT_PRO_EXT') || defined('TPGBP_VERSION')){
							$options = get_option( 'nexter_white_label' );
								if(isset($options['brand_name']) && !empty($options['brand_name'])){
									$brand_name = $options['brand_name'];
								}
							}
							?>
							<h2><?php echo esc_html( $brand_name ); ?> <?php esc_html_e( 'Image Optimisation Center', 'nexter-extension' ); ?></h2>
						</div>
						<div class="nxt-bulk-center-actions">
							<button type="button" class="nxt-bulk-btn-primary nxt-bulk-start-btn" id="nxt-bulk-start-btn"<?php echo $show_start ? '' : ' style="display:none;"'; ?>>
								<svg class="nxt-bulk-btn-icon-play" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#fff" stroke-linecap="round" stroke-linejoin="round" clip-path="url(#aasd)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333"/><path fill="#fff" d="m6.667 5.334 4 2.667-4 2.666z"/></g><defs><clipPath id="aasd"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>
								<svg class="nxt-bulk-btn-icon-spinner" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16" style="display:none;"><g clip-path="url(#afg)"><path stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M14.667 8a6.667 6.667 0 1 1-6.666-6.666"/></g><defs><clipPath id="afg"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>
								<span class="nxt-bulk-btn-text"><?php esc_html_e( 'Start Bulk Optimisation', 'nexter-extension' ); ?></span>
							</button>
							<button type="button" class="nxt-bulk-btn-secondary nxt-bulk-stop-btn" id="nxt-bulk-stop-btn" style="display:none;">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#1a1a1a" clip-path="url(#ahjk)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333Z"/><path fill="#1a1a1a" d="M6.26 10.108c.336.225.804.225 1.74.225.937 0 1.405 0 1.741-.225a1.3 1.3 0 0 0 .368-.368c.225-.336.225-.804.225-1.74 0-.937 0-1.405-.225-1.741a1.3 1.3 0 0 0-.368-.368c-.336-.225-.804-.225-1.74-.225-.937 0-1.405 0-1.741.225q-.22.147-.368.368c-.225.336-.225.804-.225 1.74 0 .937 0 1.405.225 1.741q.147.22.368.368Z"/></g><defs><clipPath id="ahjk"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>
								<?php esc_html_e( 'Stop', 'nexter-extension' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="nxt-bulk-stats-row">
					<div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Storage Saved', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-saved"><?php echo esc_html( $stat_saved ); ?></span>
						</div>
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-db"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M34 24H14M17.45 17.11 14 24v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 28.76 16h-9.52a2 2 0 0 0-1.79 1.11M18 28h.01M22 28h.01"/></svg></div>
					</div>
					<!-- <div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php //esc_html_e( 'Average Compression', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-compression"><?php //echo esc_html( $stat_compression ); ?></span>
						</div>
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-wave"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m34 29-8.5-8.5-5 5L14 19"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M28 29h6v-6"/></svg></div>
					</div> -->
					<div class="nxt-bulk-stat-card nxt-bulk-monthly-usage-card">
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label" style="display: flex; align-items: center; gap: 5px;"><?php esc_html_e( 'Monthly Usage', 'nexter-extension' ); ?><div class='nxtext_tooltip'>
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M12.48 7A5.48 5.48 0 1 1 1.52 7 5.48 5.48 0 0 1 12.48 7z" stroke="#1A1A1A" stroke-width="0.705882" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M7 9.333V7" stroke="#1A1A1A" stroke-width="0.705882" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M7 4.666h.006" stroke="#1A1A1A" stroke-width="0.705882" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="nxtext_tooltiptext"><?php echo ( $is_pro ) ? esc_html__( 'You have unlimited image optimization credits with Nexter Pro.', 'nexter-extension' ) : sprintf( esc_html__( 'Free version includes %d image optimization credits per month. This resets at the start of each month.', 'nexter-extension' ), (int) $monthly_limit ); ?></span>
                  </div></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-monthly-usage"><?php echo esc_html( $monthly_usage ); ?></span>
							<?php /* if ( ! $is_pro && $resets_in_days > 0 ) : ?>
								<span class="nxt-bulk-stat-reset" style="font-size: 11px; color: #666; display: block; margin-top: 4px;"><?php printf( esc_html__( 'Resets in %d days', 'nexter-extension' ), (int) $resets_in_days ); ?></span>
							<?php endif; */ ?>
						</div>
						<div class="nxt-bulk-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path fill="#1717cc" d="M24 13.25c2.275 0 4.368.345 5.92.927.773.29 1.451.653 1.95 1.095.496.44.88 1.022.88 1.728v14c0 .706-.384 1.288-.88 1.728-.499.442-1.177.805-1.95 1.095-1.552.582-3.645.927-5.92.927s-4.368-.345-5.92-.927c-.773-.29-1.451-.653-1.95-1.095-.496-.44-.88-1.022-.88-1.728V17c0-.706.384-1.288.88-1.729.499-.441 1.177-.804 1.95-1.094 1.552-.582 3.645-.927 5.92-.927m7.25 12.934a7.6 7.6 0 0 1-1.33.64c-1.552.581-3.645.926-5.92.926a21 21 0 0 1-3.25-.249V29a.75.75 0 0 1-1.5 0v-1.81q-.626-.164-1.17-.367a7.6 7.6 0 0 1-1.33-.64V31c0 .123.064.33.376.606.312.277.806.56 1.48.813 1.344.504 3.251.831 5.394.831s4.05-.327 5.394-.831c.674-.253 1.168-.536 1.48-.813s.376-.483.376-.606zm0-7a7.6 7.6 0 0 1-1.33.64c-1.552.581-3.645.926-5.92.926a21 21 0 0 1-3.25-.249V22a.75.75 0 0 1-1.5 0v-1.81q-.626-.164-1.17-.367a7.6 7.6 0 0 1-1.33-.64V24c0 .123.064.33.376.606.312.277.806.56 1.48.813 1.344.504 3.251.831 5.394.831s4.05-.327 5.394-.831c.674-.253 1.168-.536 1.48-.813s.376-.483.376-.606zM24 14.75c-2.143 0-4.05.327-5.394.831-.674.253-1.168.536-1.48.813s-.376.483-.376.606.064.33.376.606c.312.277.806.56 1.48.813 1.344.504 3.251.831 5.394.831s4.05-.327 5.394-.831c.674-.253 1.168-.536 1.48-.813s.376-.483.376-.606-.064-.33-.376-.606c-.312-.277-.806-.56-1.48-.813-1.344-.504-3.251-.831-5.394-.831"/></svg></div>
					</div>
					<div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Total Count', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-count"><?php echo esc_html( $stat_count ); ?></span>
						</div>
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-img"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M31 15H17a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V17a2 2 0 0 0-2-2"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 23a2 2 0 1 0 0-4 2 2 0 0 0 0 4M33 26.672l-3.086-3.086a2 2 0 0 0-2.828 0L18 32.672"/></svg></div>
					</div>
					<div class="nxt-bulk-stat-card nxt-bulk-stat-status-card">
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Status', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-status"><?php echo esc_html( $stat_status ); ?></span>
						</div>
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-status-icon <?php echo esc_attr( $stat_status_icon ); ?>" id="nxt-bulk-stat-status-icon"><?php echo $stat_status_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					</div>
					
				</div>

				<?php if ( ! $is_pro && $remaining <= 0 ) : ?>
					<div class="nxt-bulk-limit-notice">
						<div class="nxt-bulk-limit-icon"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 40 40"><path fill="#ff1400" fill-opacity=".1" d="M0 10C0 4.477 4.477 0 10 0h20c5.523 0 10 4.477 10 10v20c0 5.523-4.477 10-10 10H10C4.477 40 0 35.523 0 30z"/><path stroke="#ff1400" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 30c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10M20 16v4M20 24h.01"/></svg></div>
						<div class="nxt-bulk-limit-text">
							<h4><?php esc_html_e( 'Monthly Limit Reached', 'nexter-extension' ); ?></h4>
							<?php if ( $show_activate_button ) : ?>
								<p><?php printf( esc_html__( 'You have reached your monthly limit of %d images. Please activate Nexter Extension Pro to unlock unlimited access.', 'nexter-extension' ), (int) $monthly_limit ); ?></p>
							<?php else : ?>
								<p><?php printf( esc_html__( 'You have reached your monthly limit of %d images. Upgrade to Pro for unlimited optimisation or wait until next month.', 'nexter-extension' ), (int) $monthly_limit ); ?></p>
							<?php endif; ?>
						</div>
						<a href="<?php echo esc_url( $limit_cta_url ); ?>" target="<?php echo esc_attr( $limit_cta_target ); ?>" class="<?php echo $show_activate_button ? esc_attr('nxt-bulk-btn-primary') : esc_attr('nxt-bulk-upgrade-btn'); ?>"><?php echo esc_html( $limit_cta_label ); ?></a>
					</div>
				<?php endif; ?>

				<div class="nxt-bulk-progress-wrapper">
				<div class="nxt-bulk-progress-section">
					<div class="nxt-progress-section-header">
						<div class="nxt-progress-section-content">
							<h3><?php esc_html_e( 'Overall Progress', 'nexter-extension' ); ?></h3>
							<?php /* translators: 1: number of images optimised so far, 2: total number of images in queue */ ?>
							<p class="nxt-progress-text" id="nxt-progress-text"><?php echo esc_html( sprintf( __( '%1$d of %2$d images optimised', 'nexter-extension' ), 0, (int) $queue_count ) ); ?></p>
						</div>
						<div class="nxt-progress-section-content nxt-progress-right">
							<span class="nxt-progress-percentage" id="nxt-progress-percentage">0%</span>
							<span class="nxt-progress-completed"><?php esc_html_e( 'Complete', 'nexter-extension' ); ?></span>
						</div>
					</div>
					<div class="nxt-bulk-progress-bar-row">
						<div class="nxt-bulk-progress-bar-wrap">
							<div class="nxt-bulk-progress-bar" id="nxt-bulk-progress-bar" style="width: 0%"></div>
						</div>
					</div>
				</div>

				<div class="nxt-bulk-stats-row nxt-bulk-stats-row-small">
					<div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-db"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M34 24H14M17.45 17.11 14 24v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 28.76 16h-9.52a2 2 0 0 0-1.79 1.11M18 28h.01M22 28h.01"/></svg></div>
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Images Remaining', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-remaining"><?php echo esc_html( (string) $stat_remaining ); ?></span>
						</div>
					</div>
					<div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-trash"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#f5f7fe" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M33 15H15a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1M16 20v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V20M22 24h4"/></svg></div>
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Skipped Images', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-skipped"><?php echo esc_html( (string) $stat_skipped ); ?></span>
						</div>
					</div>
					<div class="nxt-bulk-stat-card">
						<div class="nxt-bulk-stat-icon nxt-bulk-stat-savings-icon"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><path fill="#058645" fill-opacity=".1" d="M0 10C0 4.477 4.477 0 10 0h28c5.523 0 10 4.477 10 10v28c0 5.523-4.477 10-10 10H10C4.477 48 0 43.523 0 38z"/><path stroke="#058645" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m34 29-8.5-8.5-5 5L14 19"/><path stroke="#058645" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M28 29h6v-6"/></svg></div>
						<div class="nxt-bulk-stat-content">
							<span class="nxt-bulk-stat-label"><?php esc_html_e( 'Total Savings', 'nexter-extension' ); ?></span>
							<span class="nxt-bulk-stat-value" id="nxt-bulk-stat-total-savings"><?php echo esc_html( $stat_savings ); ?></span>
						</div>
					</div>
				</div>
				</div>

				<div class="nxt-bulk-queue-section">
				<div class="nxt-bulk-queue-section nxt-bulk-queue-card">
					<div class="nxt-bulk-queue-header">
						<h3><?php esc_html_e( 'Image Queue', 'nexter-extension' ); ?></h3>
						<button type="button" class="nxt-bulk-retry-failed-btn" id="nxt-bulk-retry-failed-btn" style="display:none;">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#ff1400" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.333" clip-path="url(#aert)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333M8 5.334v2.667M8 10.666h.007"/></g><defs><clipPath id="aert"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>
							<span id="nxt-bulk-retry-failed-text"><?php esc_html_e( 'Retry Failed (0)', 'nexter-extension' ); ?></span>
						</button>
						<span class="nxt-bulk-queue-items" id="nxt-bulk-queue-items"></span>
					</div>
					<div class="nxt-bulk-queue-list" id="nxt-bulk-queue-list" style="<?php echo $queue_count > 0 ? '' : 'display:none;'; ?>">
						<!-- Populated by JS -->
					</div>
					<div class="nxt-bulk-queue-load-more" id="nxt-bulk-queue-load-more" style="display:none;">
						<button type="button" class="nxt-bulk-load-more-btn" id="nxt-bulk-load-more-btn">
							<?php esc_html_e( 'Load next 6 images', 'nexter-extension' ); ?>
						</button>
					</div>
					<div class="nxt-bulk-queue-empty" id="nxt-bulk-queue-empty" style="<?php echo ( $queue_count === 0 && ( empty( $s['optimized'] ) || (int) $s['unoptimized'] > 0 ) ) ? '' : 'display:none;'; ?>">
						<div class="nxt-bulk-queue-empty-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
						</div>
						<p><?php esc_html_e( 'No images to optimise.', 'nexter-extension' ); ?></p>
					</div>
					<div class="nxt-bulk-queue-complete" id="nxt-bulk-queue-complete" style="<?php echo ( $queue_count === 0 && ! empty( $s['optimized'] ) && (int) $s['unoptimized'] === 0 ) ? '' : 'display:none;'; ?>">
						<div class="nxt-bulk-queue-complete-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" fill="none" viewBox="0 0 30 30"><path stroke="#666" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 27.5c6.904 0 12.5-5.596 12.5-12.5S21.904 2.5 15 2.5 2.5 8.096 2.5 15 8.096 27.5 15 27.5"/><path stroke="#666" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m11.25 15 2.5 2.5 5-5"/></svg>
						</div>
						<h4><?php esc_html_e( 'All Images Optimised!', 'nexter-extension' ); ?></h4>
						<p><?php esc_html_e( 'Great job! All images in the queue have been successfully optimised. Upload more images to continue optimising.', 'nexter-extension' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="nxt-bulk-toast" id="nxt-bulk-toast"></div>
		<?php
	}

	/**
	 * Get queue and stats data (used for initial page load and AJAX refresh).
	 *
	 * @param int   $limit  Max items to return (default 10 for pagination).
	 * @param int[] $exclude_ids Attachment IDs to exclude (for load-more).
	 * @return array{queue: array, stats: array, total_unoptimised: int, has_more: bool}
	 */
	public function get_queue_data( $limit = 6, $exclude_ids = array() ) {
		$queue = array();
		if ( ! current_user_can( 'upload_files' ) ) {
			return array( 'queue' => $queue, 'stats' => array(), 'total_unoptimized' => 0, 'has_more' => false );
		}

		$ids = $this->get_unoptimized_attachment_ids( 1000, $exclude_ids );
		$total_unoptimized = count( $ids );
		$ids = array_slice( $ids, 0, max( 1, (int) $limit ) );

		foreach ( $ids as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}
			$filename = basename( $file_path );
			$thumb = wp_get_attachment_image_src( $attachment_id, array( 80, 80 ) );
			$queue[] = array(
				'id'            => $attachment_id,
				'filename'      => $filename,
				'status'        => 'pending',
				'original_size' => filesize( $file_path ),
				'thumbnail_url' => is_array( $thumb ) && ! empty( $thumb[0] ) ? $thumb[0] : '',
			);
		}

		$stats = $this->compute_stats();
		$has_more = $total_unoptimized > count( $queue );
		return array( 'queue' => $queue, 'stats' => $stats, 'total_unoptimized' => $total_unoptimized, 'has_more' => $has_more );
	}

	/**
	 * AJAX: Get image queue (unoptimised images) - for refresh after finish/restore.
	 * Supports pagination: limit (default 10), exclude_ids (JSON array of IDs to exclude).
	 */
	public function ajax_get_queue() {
		check_ajax_referer( 'nxt_bulk_images', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nexter-extension' ) ) );
		}
		$limit = isset( $_POST['limit'] ) ? max( 1, min( 50, (int) $_POST['limit'] ) ) : 6;
		$exclude_ids = array();
		if ( isset( $_POST['exclude_ids'] ) && is_string( $_POST['exclude_ids'] ) ) {
			$decoded = json_decode( stripslashes( $_POST['exclude_ids'] ), true );
			if ( is_array( $decoded ) ) {
				$exclude_ids = array_map( 'absint', $decoded );
				$exclude_ids = array_filter( $exclude_ids );
			}
		}
		$data = $this->get_queue_data( $limit, $exclude_ids );
		wp_send_json_success( $data );
	}

	/**
	 * Get unoptimised attachment IDs.
	 * Checks metadata for nxt_optimized_file (stored in _wp_attachment_metadata).
	 *
	 * @param int   $limit       Max number to return.
	 * @param int[] $exclude_ids IDs to exclude (for load-more pagination).
	 * @return int[]
	 */
	private function get_unoptimized_attachment_ids( $limit = 1000, $exclude_ids = array() ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => min( 1000, max( (int) $limit, 100 ) ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'update_post_meta_cache' => true,
		);
		if ( ! empty( $exclude_ids ) ) {
			$args['post__not_in'] = array_map( 'absint', $exclude_ids );
		}
		$query = new WP_Query( $args );
		$ids = array();
		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return $ids;
		}
		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			$file_path = get_attached_file( $id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['nxt_optimized_file'] ) ) {
				$opt_path = class_exists( 'Nexter_Ext_Image_Upload_Optimization' )
					? Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $meta['nxt_optimized_file'] )
					: null;
				if ( $opt_path && file_exists( $opt_path ) ) {
					continue;
				}
			}
			$ids[] = $id;
			if ( count( $ids ) >= (int) $limit ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Get stats for display (used by convert Ajax to avoid separate stats call).
	 *
	 * @return array
	 */
	public function get_stats() {
		return $this->compute_stats();
	}

	/**
	 * Compute overall stats for display using the optimized limit handler.
	 *
	 * @return array
	 */
	private function compute_stats() {
		return Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats();
	}
}
