<?php
/**
 * Black Friday Notice
 * Free users see the Upgrade to Pro offer, Pro users see the Upgrade to Lifetime offer.
 *
 * @package Nexter Extension
 * @since 4.9.0
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nxt_Bfcm_Notice' ) ) {

	class Nxt_Bfcm_Notice {

		const FREE_ID = 'nexter_bfcm_2026_free';
		const PRO_ID  = 'nexter_bfcm_2026_pro';

		/**
		 * Notice ID for this request, or empty when nothing should render.
		 *
		 * @var string|null
		 */
		private $notice_id = null;

		public function __construct() {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
			add_filter( 'nexter_allowed_dismiss_notice_ids', array( $this, 'allow_dismiss_ids' ) );
			add_filter( 'nexter_per_user_dismiss_notice_ids', array( $this, 'allow_dismiss_ids' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_dismiss_js' ) );
		}

		/**
		 * Register both notice IDs with the shared dismiss handler (per-user).
		 *
		 * @param string[] $ids Allowed notice IDs.
		 * @return string[]
		 */
		public function allow_dismiss_ids( $ids ) {
			$ids   = (array) $ids;
			$ids[] = self::FREE_ID;
			$ids[] = self::PRO_ID;
			return $ids;
		}

		/**
		 * Resolve which notice (if any) to show on this request.
		 *
		 * @return string Notice ID or ''.
		 */
		private function get_notice_id() {
			if ( null !== $this->notice_id ) {
				return $this->notice_id;
			}
			$this->notice_id = '';

			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			// Campaign window in site time; filterable so the dates can move without a release.
			$start = apply_filters( 'nexter_bfcm_notice_start', '2026-11-12 00:00:00' );
			$end   = apply_filters( 'nexter_bfcm_notice_end', '2026-12-05 23:59:59' );
			$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			if ( $now < strtotime( $start ) || $now > strtotime( $end ) ) {
				return '';
			}

			$is_pro = defined( 'NXT_PRO_EXT' ) || defined( 'TPGBP_VERSION' );

			// White-label: never show a Nexter-branded promo on a rebranded install.
			if ( $is_pro && class_exists( 'Nxt_Options' ) ) {
				$wl = Nxt_Options::white_label();
				if ( is_array( $wl ) && ( ! empty( $wl['brand_name'] ) || ( ! empty( $wl['nxt_help_link'] ) && 'on' === $wl['nxt_help_link'] ) ) ) {
					return '';
				}
			}

			$id = self::FREE_ID;
			if ( $is_pro ) {
				// Already on the Lifetime Unlimited plan: nothing to upsell.
				$license = get_option( 'nxt_license_status', array() );
				if ( is_array( $license ) && isset( $license['expired'] ) && 'lifetime' === $license['expired'] && ! empty( $license['active_plan'] ) ) {
					return '';
				}
				$id = self::PRO_ID;
			}

			if ( get_user_meta( get_current_user_id(), $id . '_dismissed', true ) ) {
				return '';
			}

			$this->notice_id = $id;
			return $id;
		}

		/**
		 * Enqueue the shared dismiss JS where the notice shows (same gate as render).
		 */
		public function maybe_enqueue_dismiss_js() {
			if ( ! $this->get_notice_id() ) {
				return;
			}
			$minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
			wp_enqueue_script( 'nexter-ext-builder-js', NEXTER_EXT_URL . 'assets/js/admin/nexter-ext-admin' . $minified . '.js', array(), NEXTER_EXT_VER, true );

			// Only when the dashboard has not localised the full object (see Nxt_Seo_Notice).
			if ( ! wp_script_is( 'nexter-ext-dashscript', 'enqueued' ) ) {
				wp_localize_script(
					'nexter-ext-builder-js',
					'nxtext_ajax_object',
					array(
						'ajax_url'   => admin_url( 'admin-ajax.php' ),
						'ajax_nonce' => wp_create_nonce( 'nexter_admin_nonce' ),
						'adminUrl'   => admin_url(),
					)
				);
			}
		}

		/**
		 * Output the Black Friday notice.
		 */
		public function render_notice() {
			$id = $this->get_notice_id();
			if ( ! $id ) {
				return;
			}

			if ( self::PRO_ID === $id ) {
				$title = __( 'Upgrade to Lifetime Unlimited Sites – Black Friday Up to 40% OFF', 'nexter-extension' );
				$desc  = __( 'Pay once, use forever. Unlimited sites, lifetime updates and lifetime premium support at an unbeatable price.', 'nexter-extension' );
				$cta   = __( 'Upgrade Now', 'nexter-extension' );
				$url   = 'https://nexterwp.com/pricing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=bfcm-lifetime';
			} else {
				$title = __( 'Replace 50+ WordPress Plugins with Nexter Pro – Up to 40% OFF This Black Friday', 'nexter-extension' );
				$desc  = __( 'Our Black Friday Sale is live! Get the complete Nexter Pro toolkit at the lowest price of the year.', 'nexter-extension' );
				$cta   = __( 'Get Offer', 'nexter-extension' );
				$url   = 'https://nexterwp.com/pricing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=bfcm-pro';
			}

			$bullets = array(
				__( '1600+ WordPress Templates', 'nexter-extension' ),
				__( '120+ WordPress Blocks', 'nexter-extension' ),
				__( '50+ Powerful Extensions', 'nexter-extension' ),
				__( 'Trusted by 20K+ Users', 'nexter-extension' ),
			);
			?>
			<style>
				.nxt-bfcm-notice.notice{display:flex;gap:32px;align-items:center;padding:20px 40px 20px 32px;border:1px solid #c3c4c7;border-left:6px solid #e5261c;background:#fff}
				.nxt-bfcm-notice .nxt-bfcm-img{flex:0 0 134px;width:134px;height:134px;border-radius:6px;display:block}
				.nxt-bfcm-notice .nxt-bfcm-content h2{margin:0 0 10px;padding:0;font-size:16px;font-weight:600;line-height:1.4;color:#1d2327}
				.nxt-bfcm-notice .nxt-bfcm-content p{margin:0 0 8px;padding:0;font-size:13px;color:#50575e}
				.nxt-bfcm-notice .nxt-bfcm-list{display:flex;flex-wrap:wrap;gap:4px 28px;margin:0 0 14px;padding:0;list-style:none}
				.nxt-bfcm-notice .nxt-bfcm-list li{position:relative;margin:0;padding-left:12px;font-size:13px;color:#1d2327}
				.nxt-bfcm-notice .nxt-bfcm-list li:before{content:"";position:absolute;left:0;top:8px;width:5px;height:5px;border-radius:50%;background:#1d2327}
				.nxt-bfcm-notice .nxt-bfcm-btn{display:inline-block;padding:8px 26px;border-radius:4px;background:#e5261c;color:#fff;font-size:13px;font-weight:500;text-decoration:none}
				.nxt-bfcm-notice .nxt-bfcm-btn:hover,.nxt-bfcm-notice .nxt-bfcm-btn:focus{background:#c81e15;color:#fff}
				@media (max-width:782px){.nxt-bfcm-notice.notice{flex-direction:column;align-items:flex-start;gap:16px;padding:16px 40px 16px 16px}}
			</style>
			<div class="notice is-dismissible nxt-notice-wrap nxt-bfcm-notice" data-notice-id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'nexter_admin_nonce' ) ); ?>">
				<img class="nxt-bfcm-img" src="<?php echo esc_url( NEXTER_EXT_URL . 'assets/images/nxt-black-friday.png' ); ?>" width="134" height="134" alt="<?php esc_attr_e( 'Black Friday 40% OFF', 'nexter-extension' ); ?>">
				<div class="nxt-bfcm-content">
					<h2><?php echo esc_html( $title ); ?></h2>
					<p><?php echo esc_html( $desc ); ?></p>
					<ul class="nxt-bfcm-list">
						<?php foreach ( $bullets as $bullet ) : ?>
							<li><?php echo esc_html( $bullet ); ?></li>
						<?php endforeach; ?>
					</ul>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="nxt-bfcm-btn"><?php echo esc_html( $cta ); ?></a>
				</div>
			</div>
			<?php
		}
	}
}
