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

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_action( 'init', array( $this, 'check_schedule' ) );
		add_action( self::RECURRING_HOOK, array( $this, 'process_scheduled_optimization' ) );
	}

	/**
	 * Register custom cron intervals.
	 */
	public function register_cron_interval( $schedules ) {
		$schedules['nxt_ext_image_cron_interval'] = array(
			'interval' => self::FREQUENCY,
			'display'  => __( 'Every 10 minutes (Nexter Extension Image Optimiser)', 'nexter-extension' ),
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
		$settings = Nexter_Ext_Image_Upload_Optimization::get_instance()->get_settings();
		$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();

		$enabled  = ! empty( $settings['enabled'] ) && ! empty( $settings['run_in_background'] );
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
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => (int) $limit,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query' => array(
				array(
					'key'     => 'nxt_optimized_file',
					'compare' => 'NOT EXISTS',
				),
			),
		);
		$query = new WP_Query( $args );
		$ids = array();
		if ( empty( $query->posts ) || ! is_array( $query->posts ) ) {
			return $ids;
		}

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
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
		$optimizer = Nexter_Ext_Image_Upload_Optimization::get_instance();
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

			$result = $optimizer->process_image( $file_path, $settings );

			if ( ! $result || empty( $result['success'] ) || empty( $result['file'] ) ) {
				continue;
			}

			$upload_dir = wp_get_upload_dir();
			$basedir    = wp_normalize_path( $upload_dir['basedir'] );
			$original_path = $file_path;
			$optimized_path = $result['file'];
			$original_relative = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $original_path ) ) );
			$original_relative = ltrim( $original_relative, '/' );
			$optimized_relative = Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $optimized_path );

			$backup_dir = WP_CONTENT_DIR . '/nexter-optimizer/backups';
			$backup_path = wp_normalize_path( $backup_dir . '/' . $original_relative );
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
			$metadata['nxt_main_original_size'] = $result['original_size'];
			$metadata['nxt_main_optimized_size'] = $result['optimized_size'];
			$metadata['nxt_original_size'] = $result['original_size'];
			$metadata['nxt_optimized_size'] = $result['optimized_size'];
			$metadata['nxt_original_file'] = $original_relative;
			$metadata['nxt_optimized_file'] = $optimized_relative;
			$metadata['nxt_optimized_format'] = $result['format'];
			$metadata['nxt_original_mime'] = isset( $result['original_mime'] ) ? $result['original_mime'] : get_post_mime_type( $attachment_id );
			if ( $backup_relative ) {
				$metadata['nxt_backup_file'] = $backup_relative;
			}

			// Thumbnails.
			$total_original = $result['original_size'];
			$total_optimized = $result['optimized_size'];
			$base_dir = dirname( $file_path );
			$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );

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
					$ft = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $size_file_path, null ) : null;
					$size_mime = is_array( $ft ) && ! empty( $ft['type'] ) ? $ft['type'] : '';
					if ( ! in_array( $size_mime, $valid_mimes, true ) ) {
						continue;
					}
					
					if ( $optimizer->is_path_excluded( $size_file_path, $settings['exclude_paths'] ) ) {
						continue;
					}

					// Backup thumbnail.
					$size_relative = str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $size_file_path ) ) );
					$size_relative = ltrim( $size_relative, '/' );
					$size_backup_path = wp_normalize_path( $backup_dir . '/' . $size_relative );
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
						$total_original += $size_result['original_size'];
						$total_optimized += $size_result['optimized_size'];
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
			wp_cache_delete( $attachment_id, 'post_meta' );
			clean_post_cache( $attachment_id );

			// Credits = 1 (original) + number of thumbnail sizes optimized
			$credit_count = 1 + ( isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ? count( $metadata['nxt_optimized_sizes'] ) : 0 );
			$limit_handler->record_optimization( $attachment_id, (int) $total_original, (int) $total_optimized, $credit_count );
		}
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
