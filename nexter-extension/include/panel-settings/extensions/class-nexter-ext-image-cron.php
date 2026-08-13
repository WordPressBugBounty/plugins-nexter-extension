<?php
/**
 * WP-Cron Batch Processing Handler
 */
defined( 'ABSPATH' ) || exit;
class Nexter_Ext_Image_Cron {

	/**
	 * Recurring cron hook name.
	 *
	 * @var string
	 */
	const RECURRING_HOOK = 'nxt_ext_image_cron_optimize';

	/**
	 * Run every 10 minutes.
	 */
	const FREQUENCY = 600;

	/**
	 * Timeout.
	 */
	const TIMEOUT = 60;

	/**
	 * How many consecutive failures with the configured format (avif/smart/webp)
	 * before giving up on it. For avif/smart this triggers a fallback to webp;
	 * for webp itself (already the fallback target) it triggers a permanent skip.
	 */
	const MAX_PRIMARY_RETRIES = 3;

	/**
	 * How many consecutive webp fallback failures before permanently skipping the file.
	 */
	const MAX_FALLBACK_RETRIES = 3;

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_action( 'init', array( $this, 'check_schedule' ) );
		add_action( self::RECURRING_HOOK, array( $this, 'process_scheduled_optimization' ) );

		// Catch-up fallback for hosts where WP-Cron does not fire reliably. This is NOT limited to
		// DISABLE_WP_CRON: the far more common case is a blocked/unreliable loopback request
		// (shared hosts, LiteSpeed, staging, password-protected and firewalled sites) where WP-Cron
		// is nominally "enabled" but the queued recurring event silently never fires — so background
		// optimisation of on-upload images never happens (while manual/AJAX optimisation still works).
		// On full admin page loads, if the recurring event is overdue by more than one interval,
		// run the batch once behind a short transient lock. Since uploads happen inside wp-admin,
		// subsequent admin loads drain the queue on any host. Healthy hosts never reach this because
		// real cron keeps the event scheduled in the future.
		add_action( 'admin_init', array( $this, 'maybe_run_due_cron' ) );
	}

	/**
	 * Admin-side catch-up runner for the recurring optimiser cron.
	 *
	 * Fires the batch when the recurring event is clearly overdue (WP-Cron not firing on this host),
	 * so "Auto Optimise on Upload" with "Run in Background" enabled still processes images.
	 */
	public function maybe_run_due_cron() {
		if ( ! is_admin() ) {
			return;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}
		// Skip AJAX/heartbeat/async-upload requests so we never block the upload response or the
		// media uploader's polling; the batch runs on regular admin page loads instead.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		// Only when the module is on and set to background — otherwise there is nothing to run.
		$settings = Nexter_Ext_Image_Upload_Optimization::get_instance()->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['run_in_background'] ) ) {
			return;
		}
		$next = wp_next_scheduled( self::RECURRING_HOOK );
		if ( ! $next ) {
			// Not scheduled yet; check_schedule() (on init) will schedule it.
			return;
		}
		// Grace of one full interval: only step in when a tick has clearly been missed, so we do not
		// race a working WP-Cron and steal a tick that real cron is about to fire within seconds.
		if ( $next > ( time() - self::FREQUENCY ) ) {
			return;
		}
		// Short lock so overlapping admin requests don't run the batch twice; not deleted on success
		// so it also rate-limits the catch-up to at most once per lock window.
		$lock_key = 'nxt_ext_image_cron_catchup_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
		$this->process_scheduled_optimization();
	}

	/**
	 * Register custom cron intervals.
	 *
	 * Translated only after `init`. `cron_schedules` can fire as early as `plugins_loaded` — any
	 * plugin calling wp_schedule_event() there reaches it — and translating that early triggers
	 * WP 6.7+'s `_load_textdomain_just_in_time was called incorrectly` notice. The label is only
	 * read by cron-listing tools, which run well after `init`.
	 */
	public function register_cron_interval( $schedules ) {
		$schedules['nxt_ext_image_cron_interval'] = array(
			'interval' => self::FREQUENCY,
			'display'  => did_action( 'init' ) ? __( 'Every 10 minutes (Nexter Extension Image Optimiser)', 'nexter-extension' ) : 'Every 10 minutes (Nexter Extension Image Optimiser)',
		);
		return $schedules;
	}

	/**
	 * Check schedule on init.
	 */
	public function check_schedule() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}
		$settings      = Nexter_Ext_Image_Upload_Optimization::get_instance()->get_settings();
		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();

		$enabled       = ! empty( $settings['enabled'] ) && ! empty( $settings['run_in_background'] );
		$limit_reached = $limit_handler->is_limit_reached();

		if ( ! $enabled || $limit_reached ) {
			$this->stop_cron();
			return;
		} else {
			$limit_handler->mark_cron_stopped( false );
		}

		if ( ! wp_next_scheduled( self::RECURRING_HOOK ) ) {
			wp_schedule_event( time(), 'nxt_ext_image_cron_interval', self::RECURRING_HOOK );
		}
	}

	/**
	 * Process scheduled batch.
	 */
	public function process_scheduled_optimization() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		
		$settings = Nexter_Ext_Image_Upload_Optimization::get_instance()->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['run_in_background'] ) ) {
			return;
		}

		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();
		if ( $limit_handler->is_limit_reached() ) {
			$this->stop_cron();
			$limit_handler->mark_cron_stopped( true );
			return;
		}

		$last = (int) get_option( 'nxt_ext_image_cron_lastrun', 0 );
		if ( $last && ( time() - $last ) < self::TIMEOUT ) {
			return;
		}
		update_option( 'nxt_ext_image_cron_lastrun', time() );

		// Batch size based on processing speed setting.
		$batch_size = 5;
		if ( isset( $settings['processing_speed'] ) ) {
			switch ( $settings['processing_speed'] ) {
				case 'slow':
					$batch_size = 3;
					break;
				case 'fast':
					$batch_size = 10;
					break;
				case 'balanced':
				default:
					$batch_size = 5;
					break;
			}
		}

		$ids = $this->get_unoptimized_attachment_ids( $batch_size );
		if ( empty( $ids ) ) {
			return;
		}

		$this->process_batch( $ids, $settings );
	}

	/**
	 * Get unoptimised attachment IDs.
	 * 
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_unoptimized_attachment_ids( $limit = 5 ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_mime_type'         => array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' ),
			'post_status'            => 'inherit',
			// Candidate pool — some rows may already be optimised (their marker gets backfilled
			// and they are skipped below), so fetch more than $limit to still fill a batch.
			'posts_per_page'         => max( (int) $limit * 5, 25 ),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC', // Newest uploads first, so freshly uploaded images convert promptly.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				'relation' => 'AND',
				array(
					'key'     => 'nxt_optimized_file',
					'compare' => 'NOT EXISTS',
				),
				array(
					// Permanently-failed files (avif + webp fallback both failed) are
					// excluded so the cron does not retry them forever.
					'key'     => 'nxt_optimize_failed',
					'compare' => 'NOT EXISTS',
				),
			),
		);
		$query = new WP_Query( $args );
		$ids   = array();
		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return $ids;
		}

		foreach ( $query->posts as $attachment_id ) {
			if ( count( $ids ) >= (int) $limit ) {
				break;
			}
			$attachment_id = (int) $attachment_id;
			$file_path     = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			// Already optimised in a prior version — the data lives inside _wp_attachment_metadata
			// but the standalone marker is missing. Backfill the marker and skip it, so the cron
			// does not re-optimise already-done images and re-consume optimisation credits.
			$existing_meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $existing_meta ) && ! empty( $existing_meta['nxt_optimized_file'] ) ) {
				update_post_meta( $attachment_id, 'nxt_optimized_file', $existing_meta['nxt_optimized_file'] );
				continue;
			}

			$ids[] = $attachment_id;
		}
		return $ids;
	}

	/**
	 * Process batch of IDs.
	 *
	 * @param array $batch IDs.
	 * @param array $settings Settings.
	 */
	private function process_batch( $batch, $settings ) {
		$optimizer     = Nexter_Ext_Image_Upload_Optimization::get_instance();
		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();
		$optimizer->create_optimizer_folders();

		foreach ( $batch as $attachment_id ) {
			if ( $limit_handler->is_limit_reached() && ! $limit_handler->can_optimize( $attachment_id ) ) {
				break;
			}
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			$attempt_settings = $settings;
			$phase            = get_post_meta( $attachment_id, 'nxt_optimize_phase', true );
			$phase            = $phase ? $phase : 'primary';

			// Once the configured format has failed too many times in a row, fall
			// back to plain webp for this attachment (regardless of global setting).
			if ( 'fallback' === $phase && in_array( $settings['output_format'], array( 'smart', 'avif' ), true ) ) {
				$attempt_settings['output_format'] = 'webp';
			}

			$result = $optimizer->process_image( $file_path, $attempt_settings );

			if ( ! $result || empty( $result['success'] ) || empty( $result['file'] ) ) {
				$this->handle_failed_attempt( $attachment_id, $phase, $attempt_settings['output_format'] );
				continue;
			}

			// Success — clear any retry bookkeeping from earlier failed attempts.
			delete_post_meta( $attachment_id, 'nxt_optimize_attempts' );
			delete_post_meta( $attachment_id, 'nxt_optimize_phase' );

			$upload_dir         = wp_get_upload_dir();
			$basedir            = wp_normalize_path( $upload_dir['basedir'] );
			$original_path      = $file_path;
			$optimized_path     = $result['file'];
			$original_relative  = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $original_path ) ) );
			$original_relative  = ltrim( $original_relative, '/' );
			$optimized_relative = Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $optimized_path );

			$backup_dir    = WP_CONTENT_DIR . '/nexter-optimizer/backups';
			$backup_path   = wp_normalize_path( $backup_dir . '/' . $original_relative );
			$backup_parent = dirname( $backup_path );
			if ( ! is_dir( $backup_parent ) ) {
				wp_mkdir_p( $backup_parent );
			}
			if ( ! file_exists( $backup_path ) && file_exists( $original_path ) ) {
				@copy( $original_path, $backup_path );
			}
			$backup_relative = file_exists( $backup_path ) ? Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $backup_path ) : '';

			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! is_array( $metadata ) ) {
				$metadata = array();
			}
			$metadata['nxt_main_original_size']  = $result['original_size'];
			$metadata['nxt_main_optimized_size'] = $result['optimized_size'];
			$metadata['nxt_original_size']       = $result['original_size'];
			$metadata['nxt_optimized_size']      = $result['optimized_size'];
			$metadata['nxt_original_file']       = $original_relative;
			$metadata['nxt_optimized_file']      = $optimized_relative;
			$metadata['nxt_optimized_format']    = $result['format'];
			$metadata['nxt_original_mime']       = isset( $result['original_mime'] ) ? $result['original_mime'] : get_post_mime_type( $attachment_id );
			if ( $backup_relative ) {
				$metadata['nxt_backup_file'] = $backup_relative;
			}

			// Thumbnails.
			$total_original  = $result['original_size'];
			$total_optimized = $result['optimized_size'];
			$base_dir        = dirname( $file_path );
			$valid_mimes     = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );

			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$metadata['nxt_optimized_sizes'] = isset( $metadata['nxt_optimized_sizes'] ) ? $metadata['nxt_optimized_sizes'] : array();
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$size_file_path = wp_normalize_path( $base_dir . '/' . $size_data['file'] );
					if ( ! file_exists( $size_file_path ) ) {
						continue;
					}
					$ft        = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $size_file_path, null ) : null;
					$size_mime = is_array( $ft ) && ! empty( $ft['type'] ) ? $ft['type'] : '';
					if ( ! in_array( $size_mime, $valid_mimes, true ) ) {
						continue;
					}
					
					if ( $optimizer->is_path_excluded( $size_file_path, $settings['exclude_paths'] ) ) {
						continue;
					}

					// Backup thumbnail.
					$size_relative      = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $size_file_path ) ) );
					$size_relative      = ltrim( $size_relative, '/' );
					$size_backup_path   = wp_normalize_path( $backup_dir . '/' . $size_relative );
					$size_backup_parent = dirname( $size_backup_path );
					if ( ! is_dir( $size_backup_parent ) ) {
						wp_mkdir_p( $size_backup_parent );
					}
					if ( ! file_exists( $size_backup_path ) ) {
						@copy( $size_file_path, $size_backup_path );
					}
					$size_backup_rel = file_exists( $size_backup_path ) ? Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $size_backup_path ) : '';

					$size_result = $optimizer->process_image( $size_file_path, $settings );
					if ( $size_result && ! empty( $size_result['success'] ) && ! empty( $size_result['file'] ) ) {
						$total_original                               += $size_result['original_size'];
						$total_optimized                              += $size_result['optimized_size'];
						$metadata['nxt_optimized_sizes'][ $size_name ] = array(
							'file'           => Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $size_result['file'] ),
							'format'         => $size_result['format'],
							'original_size'  => $size_result['original_size'],
							'optimized_size' => $size_result['optimized_size'],
							'backup_file'    => $size_backup_rel,
						);
					}
				}
				$metadata['nxt_original_size']  = $total_original;
				$metadata['nxt_optimized_size'] = $total_optimized;
			}

			wp_update_attachment_metadata( $attachment_id, $metadata );
			// Standalone marker so get_unoptimized_attachment_ids() excludes this attachment on the
			// next run. Without it the "nxt_optimized_file NOT EXISTS" query never advances (the key
			// only lived inside the serialized _wp_attachment_metadata), so the cron would reprocess
			// the same lowest-ID images forever and never reach newer uploads.
			update_post_meta( $attachment_id, 'nxt_optimized_file', $optimized_relative );
			wp_cache_delete( $attachment_id, 'post_meta' );
			clean_post_cache( $attachment_id );

			// Credits = 1 (original) + number of thumbnail sizes optimized
			$credit_count = 1 + ( isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ? count( $metadata['nxt_optimized_sizes'] ) : 0 );
			$limit_handler->record_optimization( $attachment_id, (int) $total_original, (int) $total_optimized, $credit_count );
		}
	}

	/**
	 * Record a failed optimisation attempt and advance retry state.
	 *
	 * primary phase (avif/smart/webp, whatever is configured):
	 *   - under MAX_PRIMARY_RETRIES: just count the attempt, try again next run.
	 *   - at MAX_PRIMARY_RETRIES:
	 *       - if the format tried was avif/smart, switch to the webp fallback phase.
	 *       - if the format tried was already webp (no fallback left), skip permanently.
	 * fallback phase (webp):
	 *   - under MAX_FALLBACK_RETRIES: just count the attempt.
	 *   - at MAX_FALLBACK_RETRIES: skip permanently.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $phase         'primary' or 'fallback'.
	 * @param string $format_tried  Output format used for the failed attempt.
	 */
	private function handle_failed_attempt( $attachment_id, $phase, $format_tried ) {
		$attempts = (int) get_post_meta( $attachment_id, 'nxt_optimize_attempts', true ) + 1;

		if ( 'fallback' === $phase ) {
			if ( $attempts >= self::MAX_FALLBACK_RETRIES ) {
				$this->mark_optimize_failed( $attachment_id, 'webp_fallback_failed' );
				return;
			}
			update_post_meta( $attachment_id, 'nxt_optimize_attempts', $attempts );
			return;
		}

		// Primary phase.
		if ( $attempts < self::MAX_PRIMARY_RETRIES ) {
			update_post_meta( $attachment_id, 'nxt_optimize_attempts', $attempts );
			return;
		}

		if ( in_array( $format_tried, array( 'avif', 'smart' ), true ) ) {
			// avif/smart exhausted its retries — fall back to webp with a fresh counter.
			update_post_meta( $attachment_id, 'nxt_optimize_phase', 'fallback' );
			update_post_meta( $attachment_id, 'nxt_optimize_attempts', 0 );
			return;
		}

		// Already webp, or 'original' (no format to fall back to) — nothing left to try.
		$this->mark_optimize_failed( $attachment_id, 'webp' === $format_tried ? 'webp_failed' : 'original_failed' );
	}

	/**
	 * Permanently mark an attachment as un-optimisable so the cron stops retrying it.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $reason        Short machine-readable failure reason.
	 */
	private function mark_optimize_failed( $attachment_id, $reason ) {
		update_post_meta( $attachment_id, 'nxt_optimize_failed', $reason );
		delete_post_meta( $attachment_id, 'nxt_optimize_attempts' );
		delete_post_meta( $attachment_id, 'nxt_optimize_phase' );
	}

	/**
	 * Stop the recurring cron job.
	 */
	public function stop_cron() {
		$timestamp = wp_next_scheduled( self::RECURRING_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RECURRING_HOOK );
		}
	}
}
