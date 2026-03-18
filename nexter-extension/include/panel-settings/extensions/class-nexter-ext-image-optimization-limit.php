<?php
/**
 * Image Optimisation limit and statistics handler
 * 
 * @since 4.5.3
 */
defined( 'ABSPATH' ) || exit;

class Nexter_Ext_Image_Optimization_Limit {

	/** 
	 * Singleton instance 
	 * @var self|null
	 */
	private static $instance = null;

	/** 
	 * Usage and stats data cache for current request 
	 * @var array|null
	 */
	private $data = null;

	/** @var int Monthly limit for free version */
	const LIMIT = 500;

	/** @var string Option name for all image optimisation stats */
	const OPTION_NAME = 'nxt_image_opt_stats';

	/**
	 * Get singleton instance.
	 * 
	 * @return self
	 */
	public static function get_instance() : self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {}

	/**
	 * Check if Nexter Pro is active.
	 * 
	 * @return bool
	 */
	public function is_pro() : bool {
		return defined( 'NXT_PRO_EXT_VER' ) && class_exists( 'Nexter_Ext_Image_Optimization_Pro' );
	}

	/**
	 * Get current month key (YYYY-MM).
	 * 
	 * @return string
	 */
	public function get_current_month_key() : string {
		return current_time( 'Y-m' );
	}

	/**
	 * Get usage and statistics data with lazy loading and monthly reset.
	 * Only monthly usage (c, csb) resets at the start of each month; lifetime stats (to, ts, tc, cs) are never reset by month.
	 *
	 * @return array
	 */
	private function get_stored_data() : array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		$current_month = $this->get_current_month_key();
		$data          = get_option( self::OPTION_NAME, array() );

		// Run migration when option is empty or missing lifetime stats (first install, or option lost, or old format). Preserves monthly count from old options; restores lifetime stats from attachment metadata scan.
		if ( empty( $data ) || ! is_array( $data ) || ! isset( $data['to'] ) ) {
			$data = $this->migrate_old_data();
			update_option( self::OPTION_NAME, $data );
		}

		$defaults = array(
			'm'   => $current_month,
			'c'   => 0,
			'to'  => 0,
			'ts'  => 0,
			'tc'  => 0,
			'cs'  => 0.0,
			'csb' => 0,
		);
		$data = wp_parse_args( $data, $defaults );

		// Repair: when Storage Saved / Total Savings (ts) is zero, recalc lifetime stats from attachments so they are restored. Do not touch monthly usage (m, c, csb).
		if ( (int) ( $data['ts'] ?? 0 ) === 0 ) {
			$lifetime = $this->scan_lifetime_stats_from_attachments();
			if ( $lifetime['to'] > 0 || $lifetime['ts'] > 0 ) {
				$data['to'] = $lifetime['to'];
				$data['ts'] = $lifetime['ts'];
				$data['tc'] = $lifetime['tc'];
				$data['cs'] = $lifetime['cs'];
				update_option( self::OPTION_NAME, $data );
			}
		}

		// Monthly reset: only monthly usage (c) and cron-stopped flag (csb) reset at start of each month. Lifetime stats (to, ts, tc, cs) are never reset here.
		if ( $data['m'] !== $current_month ) {
			$data['m']   = $current_month;
			$data['c']   = 0;
			$data['csb'] = 0;
			update_option( self::OPTION_NAME, $data );
		}

		// After "Restore all images": if no optimized images remain, set lifetime stats (to, ts, tc, cs) to 0 so Storage Saved / Total Savings show 0. Monthly usage (m, c, csb) is not changed — only start of month resets credits.
		if ( ( (int) ( $data['to'] ?? 0 ) > 0 || (int) ( $data['ts'] ?? 0 ) > 0 ) ) {
			$lifetime = $this->scan_lifetime_stats_from_attachments();
			if ( $lifetime['to'] === 0 && $lifetime['ts'] === 0 ) {
				$data['to'] = 0;
				$data['ts'] = 0;
				$data['tc'] = 0;
				$data['cs'] = 0.0;
				update_option( self::OPTION_NAME, $data );
			}
		}

		$this->data = $data;
		return $this->data;
	}

	/**
	 * Scan all attachments for nxt_optimized_file in metadata and return lifetime stats (to, ts, tc, cs). Used by migration and repair.
	 *
	 * @return array{to: int, ts: int, tc: int, cs: float}
	 */
	private function scan_lifetime_stats_from_attachments() : array {
		$total_opt = 0;
		$sum_orig  = 0.0;
		$sum_opt   = 0.0;
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => true,
		) );
		if ( ! empty( $query->posts ) && is_array( $query->posts ) ) {
			foreach ( $query->posts as $post_id ) {
				$meta = wp_get_attachment_metadata( (int) $post_id );
				if ( ! is_array( $meta ) || empty( $meta['nxt_optimized_file'] ) ) {
					continue;
				}
				$total_opt++;
				// Prefer total (main + thumbnails): nxt_original_size / nxt_optimized_size; fallback to main-only for Storage Saved / Total Savings.
				$sum_orig += (float) ( $meta['nxt_original_size'] ?? $meta['nxt_main_original_size'] ?? 0 );
				$sum_opt  += (float) ( $meta['nxt_optimized_size'] ?? $meta['nxt_main_optimized_size'] ?? 0 );
			}
		}
		$total_ts = (int) max( 0, $sum_orig - $sum_opt );
		$cs = 0.0;
		if ( $total_opt > 0 && $sum_orig > 0 ) {
			$cs = ( ( $sum_orig - $sum_opt ) / $sum_orig ) * 100 * $total_opt;
		}
		return array(
			'to' => $total_opt,
			'ts' => $total_ts,
			'tc' => $total_opt,
			'cs' => (float) $cs,
		);
	}

	/**
	 * Migrate data from old options and scan for existing optimizations.
	 * Preserves monthly count (c) from old options; lifetime stats from attachment scan.
	 *
	 * @return array Initialized stats array
	 */
	private function migrate_old_data() : array {
		$current_month = $this->get_current_month_key();

		// 1. Get monthly usage from old options (only these reset at start of month; we preserve them here)
		$old_count = (int) get_option( 'nxt_image_opt_monthly_count', 0 );
		$old_month = get_option( 'nxt_image_opt_current_month', $current_month );

		// 2. Lifetime stats from attachment metadata scan
		$lifetime = $this->scan_lifetime_stats_from_attachments();

		$data = array(
			'm'   => (string) $old_month,
			'c'   => $old_count,
			'to'  => $lifetime['to'],
			'ts'  => $lifetime['ts'],
			'tc'  => $lifetime['tc'],
			'cs'  => $lifetime['cs'],
			'csb' => 0,
		);

		// Cleanup old options after migration
		delete_option( 'nxt_image_opt_monthly_count' );
		delete_option( 'nxt_image_opt_current_month' );
		delete_option( 'nxt_image_opt_usage' );

		return $data;
	}

	/**
	 * Update the stored data.
	 */
	private function save_data( array $data ) : bool {
		$this->data = $data;
		return update_option( self::OPTION_NAME, $data );
	}

	/**
	 * Get monthly optimized count.
	 */
	public function get_monthly_count() : int {
		$data = $this->get_stored_data();
		return (int) ( $data['c'] ?? 0 );
	}

	/**
	 * Get monthly limit. Pro has unlimited usage; free has LIMIT per month (resets at start of each month).
	 */
	public function get_monthly_limit() : int {
		return $this->is_pro() ? 999999999 : self::LIMIT;
	}

	/**
	 * Check if limit is reached.
	 */
	public function is_limit_reached() : bool {
		if ( $this->is_pro() ) {
			return false;
		}
		return $this->get_monthly_count() >= self::LIMIT;
	}

	/**
	 * Check if specific attachment can be optimized.
	 */
	public function can_optimize( $attachment_id ) : bool {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		if ( $this->is_pro() ) {
			return true;
		}

		// Re-optimisation of an already optimised image in the same month doesn't count.
		$image_month = get_post_meta( $attachment_id, 'nxt_image_opt_month', true );
		if ( $image_month === $this->get_current_month_key() ) {
			return true;
		}

		return ! $this->is_limit_reached();
	}

	/**
	 * Record a successful Optimisation.
	 * 
	 * @param int $attachment_id
	 * @param int $original_size  Total original bytes (main + sizes).
	 * @param int $optimized_size Total optimized bytes (main + sizes).
	 * @param int $credit_count   Number of credits used (1 original + each size; default 1).
	 */
	public function record_optimization( int $attachment_id, int $original_size, int $optimized_size, int $credit_count = 1 ) : void {
		$credit_count = max( 1, (int) $credit_count );
		$data = $this->get_stored_data();
		$current_month = $this->get_current_month_key();

		// 1. Handle monthly counter (first time per image per month): add credit_count (original + sizes) – free version only.
		if ( ! $this->is_pro() ) {
			$image_month = get_post_meta( $attachment_id, 'nxt_image_opt_month', true );
			if ( $image_month !== $current_month ) {
				$data['c'] = (int) ( $data['c'] ?? 0 ) + $credit_count;
				update_post_meta( $attachment_id, 'nxt_image_opt_month', $current_month );
				update_post_meta( $attachment_id, 'nxt_image_opt_credits', $credit_count );
			}
		}

		// 2. Handle global statistics - Only increment 'total optimized' if it's new
		$ever_optimized = get_post_meta( $attachment_id, 'nxt_ever_optimized', true );
		$is_first_time = empty( $ever_optimized );
		
		// Fallback: If stats are empty but this image succeeded, ensure we have at least 1 in stats
		if ( (int) ( $data['to'] ?? 0 ) === 0 ) {
			$is_first_time = true;
		}

		if ( $is_first_time ) {
			$data['to'] = (int) ( $data['to'] ?? 0 ) + 1;
			update_post_meta( $attachment_id, 'nxt_ever_optimized', '1' );
			
			// Only add to average compression on first-time optimization
			if ( $original_size > 0 ) {
				$data['tc'] = (int) ( $data['tc'] ?? 0 ) + 1;
				$saved = max( 0, $original_size - $optimized_size );
				$data['cs'] = (float) ( $data['cs'] ?? 0 ) + ( ( $saved / $original_size ) * 100 );
			}
		}
		
		// 3. Update total savings (Bytes)
		$saved = max( 0, $original_size - $optimized_size );
		if ( $is_first_time ) {
			$data['ts'] = (float) ( $data['ts'] ?? 0 ) + $saved;
		}

		// 4. Mark cron stopped if limit reached (free version only).
		if ( ! $this->is_pro() && (int) $data['c'] >= self::LIMIT ) {
			$data['csb'] = 1;
		}

		$this->save_data( $data );
	}

	/**
	 * Record a restoration: subtract from lifetime stats (to, ts, tc, cs) only.
	 * Monthly usage (c) is NOT changed here — it resets only at the start of each month.
	 */
	public function record_restoration( int $attachment_id, int $original_size, int $optimized_size ) : void {
		$data = $this->get_stored_data();

		// Do not subtract from monthly credits (c). Monthly usage resets only at start of month.
		delete_post_meta( $attachment_id, 'nxt_image_opt_credits' );
		delete_post_meta( $attachment_id, 'nxt_image_opt_month' );
		delete_post_meta( $attachment_id, 'nxt_ever_optimized' );

		// Subtract from lifetime stats only (Storage Saved, Total Optimized, etc.)
		if ( isset( $data['to'] ) && $data['to'] > 0 ) {
			$data['to']--;
		}

		$saved = max( 0, $original_size - $optimized_size );
		if ( isset( $data['ts'] ) ) {
			$data['ts'] = max( 0.0, (float) $data['ts'] - $saved );
		}

		if ( $original_size > 0 && isset( $data['tc'] ) && $data['tc'] > 0 ) {
			$data['tc']--;
			$data['cs'] = max( 0.0, (float) $data['cs'] - ( ( $saved / $original_size ) * 100 ) );
		}

		$this->save_data( $data );
	}

	/**
	 * Legacy/Simplified increment (just count).
	 */
	public function increment_count( $attachment_id ) : void {
		$data = $this->get_stored_data();
		$current_month = $this->get_current_month_key();
		
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id > 0 ) {
			$image_month = get_post_meta( $attachment_id, 'nxt_image_opt_month', true );
			if ( $image_month === $current_month ) {
				return;
			}
			update_post_meta( $attachment_id, 'nxt_image_opt_month', $current_month );
		}
		
		$data['c']++;
		$this->save_data( $data );
	}

	/**
	 * Count all image attachments (any image/* mime type, excluding trash).
	 *
	 * @return int
	 */
	private function count_total_images() : int {
		if ( function_exists( 'wp_count_attachments' ) ) {
			$counts = wp_count_attachments( 'image' );
			if ( $counts instanceof \stdClass ) {
				$total = 0;
				foreach ( (array) $counts as $mime => $count ) {
					// Skip trashed items and SVGs so they are not counted in "Images Remaining".
					if ( 'trash' === $mime || 'image/svg+xml' === $mime ) {
						continue;
					}
					$total += (int) $count;
				}
				return $total;
			}
		}
		$count_attachments = wp_count_posts( 'attachment' );
		return (int) ( $count_attachments->inherit ?? 0 );
	}

	/**
	 * Count attachments that have been optimised by Nexter (nxt_optimized_file present and file exists).
	 *
	 * @return int
	 */
	private function count_optimized_attachments() : int {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => 5000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		$q = new \WP_Query( $args );
		if ( empty( $q->posts ) || ! is_array( $q->posts ) ) {
			return 0;
		}
		$optimized = 0;
		foreach ( $q->posts as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( ! is_array( $meta ) || empty( $meta['nxt_optimized_file'] ) ) {
				continue;
			}
			$opt_path = class_exists( 'Nexter_Ext_Image_Upload_Optimization' )
				? Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $meta['nxt_optimized_file'] )
				: null;
			if ( $opt_path && file_exists( $opt_path ) ) {
				$optimized++;
			}
		}
		return $optimized;
	}

	/**
	 * Count WebP/AVIF attachments that we did NOT create (no nxt_optimized_file).
	 * These are "native" uploads; converted WebP/AVIF are counted in optimized, not skipped.
	 *
	 * @return int
	 */
	private function count_native_webp_avif() : int {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/webp', 'image/avif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => 5000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		$q = new \WP_Query( $args );
		if ( empty( $q->posts ) || ! is_array( $q->posts ) ) {
			return 0;
		}
		$skipped = 0;
		foreach ( $q->posts as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( ! is_array( $meta ) || empty( $meta['nxt_optimized_file'] ) ) {
				$skipped++;
			}
		}
		return $skipped;
	}

	/**
	 * Get all stats for UI.
	 * Skipped = native WebP/AVIF only (not our converted ones). Remaining = total - optimized - skipped.
	 */
	public function get_ui_stats() : array {
		$data = $this->get_stored_data();

		// Recompute counts directly from attachments so totals match actual media library.
		$total_images    = $this->count_total_images();
		$optimized_count = $this->count_optimized_attachments();
		$skipped         = $this->count_native_webp_avif();
		$unoptimized = max( 0, $total_images - $optimized_count - $skipped );

		$avg_compression = ( isset( $data['tc'] ) && $data['tc'] > 0 ) ? round( (float) $data['cs'] / (int) $data['tc'], 1 ) : 0;

		$now            = current_time( 'timestamp' );
		$next_month     = strtotime( 'first day of next month 00:00:00', $now );
		$resets_in_days = max( 1, ceil( ( $next_month - $now ) / DAY_IN_SECONDS ) );

		return array(
			'total'            => $total_images,
			'optimized'        => $optimized_count,
			'unoptimized'      => $unoptimized,
			'skipped'          => $skipped,
			'storage_saved'    => (int) ( $data['ts'] ?? 0 ),
			'avg_compression'  => $avg_compression,
			'total_savings_mb' => isset( $data['ts'] ) ? round( (int) $data['ts'] / ( 1024 * 1024 ), 2 ) : 0,
			'monthly_count'    => (int) ( $data['c'] ?? 0 ),
			'monthly_limit'    => $this->get_monthly_limit(),
			'resets_in_days'   => (int) $resets_in_days,
			'cron_limit_reached' => ! empty( $data['csb'] ),
			'is_pro'           => $this->is_pro(),
		);
	}

	/**
	 * Mark cron status as stopped by limit.
	 * 
	 * @param bool $stopped
	 */
	public function mark_cron_stopped( bool $stopped = true ) : void {
		$data = $this->get_stored_data();
		$data['csb'] = $stopped ? 1 : 0;
		$this->save_data( $data );
	}
}
