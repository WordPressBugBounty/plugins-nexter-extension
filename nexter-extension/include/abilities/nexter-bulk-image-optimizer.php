<?php
/**
 * Abilities: Bulk Image Optimizer (Media → Bulk Images dashboard).
 *
 * Mirrors the actions available in the Nexter Bulk Image Optimisation dashboard
 * so changes made through MCP reflect on the dashboard page on the next refresh.
 *
 * @package SproutOS_MCP
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'nexter/get-bulk-image-optimizer-status',
	array(
		'label'               => __( 'Get Bulk Image Optimizer Status', 'nexter-extension' ),
		'description'         => __( 'Returns Nexter Bulk Image Optimizer stats (totals, savings, monthly usage) and the next batch of unoptimized attachments — the same data shown on Media → Bulk Images.', 'nexter-extension' ),
		'category'            => 'nexter-extension',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'       => array(
					'type'        => 'integer',
					'description' => 'Maximum queue items to return (1-50). Defaults to 6, matching the dashboard.',
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'exclude_ids' => array(
					'type'        => 'array',
					'description' => 'Attachment IDs to exclude from the queue (for pagination / load-more).',
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_get_bulk_image_optimizer_status',
		'permission_callback' => 'sprout_mcp_permission_callback',
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'instructions' => 'Inspect the Bulk Image Optimizer state: total/optimized/skipped counts, storage saved, monthly usage and limit, and the next batch of unoptimized attachments (id, filename, original_size, thumbnail_url). Returns enabled=false if the underlying Image Optimization extension is disabled — call nexter/update-image-optimization with enabled=true to turn it on.',
				'readonly'     => true,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

wp_register_ability(
	'nexter/bulk-optimize-images',
	array(
		'label'               => __( 'Bulk Optimize Images', 'nexter-extension' ),
		'description'         => __( 'Optimizes one or more attachments using the configured Nexter Image Optimization settings. Same effect as clicking Start Bulk Optimisation on Media → Bulk Images.', 'nexter-extension' ),
		'category'            => 'nexter-extension',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => 'Specific attachment IDs to optimize. If omitted, the next "limit" unoptimized attachments are processed.',
					'items'       => array( 'type' => 'integer' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => 'When attachment_ids is omitted, how many unoptimized images to process in this call (1-25). Default 5. Keep modest to avoid PHP timeouts.',
					'minimum'     => 1,
					'maximum'     => 25,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_bulk_optimize_images',
		'permission_callback' => 'sprout_mcp_permission_callback',
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'instructions' => 'Optimize attachments via the Nexter Image Optimizer. Provide attachment_ids for targeted optimization, or omit them to grab the next limit (default 5) unoptimized images. Each item returns its own status (success / skipped / failed) with bytes saved. Heavy: each image can take several seconds — keep batches small. Requires the Image Optimization extension enabled; per-image skip reasons mirror what the dashboard would show.',
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => true,
			),
		),
	)
);

wp_register_ability(
	'nexter/restore-original-images',
	array(
		'label'               => __( 'Restore Original Images', 'nexter-extension' ),
		'description'         => __( 'Restores all images that were optimized by Nexter back to their original files from backup, and clears optimization metadata.', 'nexter-extension' ),
		'category'            => 'nexter-extension',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'execute_callback'    => 'nexter_mcp_restore_original_images',
		'permission_callback' => 'sprout_mcp_permission_callback',
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'instructions' => 'Reverts every Nexter-optimized image back to its backed-up original and removes nxt_optimized_file metadata. Site-wide and irreversible without re-running optimization. Use only when the user explicitly asks to roll back bulk optimization.',
				'readonly'     => false,
				'destructive'  => true,
				'idempotent'   => true,
			),
		),
	)
);

/**
 * Check the bulk image optimizer dependencies exist.
 *
 * Returns an error payload if the Nexter Extension image optimization
 * classes are missing, otherwise null.
 *
 * @since 2.1.0
 *
 * @return array<string,mixed>|null
 */
function nexter_mcp_bulk_image_optimizer_deps(): ?array {
	if ( ! class_exists( 'Nexter_Ext_Image_Upload_Optimization' ) ) {
		return array(
			'success' => false,
			'message' => 'Nexter Extension Image Optimisation class is not available. Activate Nexter Extension and ensure the Image Optimisation feature is loaded.',
		);
	}
	if ( ! class_exists( 'Nexter_Ext_Image_Optimization_Limit' ) ) {
		return array(
			'success' => false,
			'message' => 'Nexter Image Optimisation limit handler is not available.',
		);
	}
	return null;
}

/**
 * Ability callback: return Bulk Image Optimizer status, stats and queue.
 *
 * @since 2.1.0
 *
 * @param array<string,mixed> $input Ability input. Supported keys:
 *                                   - limit (int) Queue page size (1-50, default 6).
 *                                   - exclude_ids (int[]) Attachment IDs to skip.
 * @return array<string,mixed>
 */
function nexter_mcp_get_bulk_image_optimizer_status( array $input ): array {
	$deps = nexter_mcp_bulk_image_optimizer_deps();
	if ( null !== $deps ) {
		return $deps;
	}

	$limit       = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 6;
	$exclude_ids = array();
	if ( isset( $input['exclude_ids'] ) && is_array( $input['exclude_ids'] ) ) {
		$exclude_ids = array_values( array_filter( array_map( 'absint', $input['exclude_ids'] ) ) );
	}

	$optimizer = Nexter_Ext_Image_Upload_Optimization::get_instance();
	$settings  = $optimizer->get_settings();
	$enabled   = ! empty( $settings['enabled'] );

	$stats = Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats();

	$queue             = array();
	$total_unoptimized = 0;
	$has_more          = false;
	$dashboard_url     = admin_url( 'upload.php?page=nxt_bulk_images' );

	if ( $enabled && class_exists( 'Nexter_Ext_Bulk_Images' ) ) {
		$data              = Nexter_Ext_Bulk_Images::get_instance()->get_queue_data( $limit, $exclude_ids );
		$queue             = isset( $data['queue'] ) ? $data['queue'] : array();
		$total_unoptimized = isset( $data['total_unoptimized'] ) ? (int) $data['total_unoptimized'] : 0;
		$has_more          = ! empty( $data['has_more'] );
	}

	return array(
		'success'           => true,
		'enabled'           => $enabled,
		'dashboard_url'     => $dashboard_url,
		'stats'             => $stats,
		'queue'             => $queue,
		'total_unoptimized' => $total_unoptimized,
		'has_more'          => $has_more,
	);
}

/**
 * Ability callback: optimize a set of attachments.
 *
 * If `attachment_ids` is empty, the next `limit` unoptimized attachments are
 * pulled from the bulk queue (matching the dashboard's Start Bulk button).
 *
 * @since 2.1.0
 *
 * @param array<string,mixed> $input Ability input. Supported keys:
 *                                   - attachment_ids (int[]) Specific IDs.
 *                                   - limit (int) Batch size (1-25, default 5).
 * @return array<string,mixed>
 */
function nexter_mcp_bulk_optimize_images( array $input ): array {
	$deps = nexter_mcp_bulk_image_optimizer_deps();
	if ( null !== $deps ) {
		return $deps;
	}

	$optimizer = Nexter_Ext_Image_Upload_Optimization::get_instance();
	$settings  = $optimizer->get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return array(
			'success' => false,
			'message' => 'Image Optimisation is disabled. Enable it with nexter/update-image-optimization first.',
		);
	}

	$ids = array();
	if ( isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ) {
		$ids = array_values( array_filter( array_map( 'absint', $input['attachment_ids'] ) ) );
	}

	if ( empty( $ids ) ) {
		$limit = isset( $input['limit'] ) ? max( 1, min( 25, (int) $input['limit'] ) ) : 5;
		if ( class_exists( 'Nexter_Ext_Bulk_Images' ) ) {
			$data  = Nexter_Ext_Bulk_Images::get_instance()->get_queue_data( $limit, array() );
			$queue = isset( $data['queue'] ) ? $data['queue'] : array();
			foreach ( $queue as $item ) {
				if ( ! empty( $item['id'] ) ) {
					$ids[] = (int) $item['id'];
				}
			}
		}
	}

	$empty_summary = array(
		'processed'   => 0,
		'optimized'   => 0,
		'skipped'     => 0,
		'failed'      => 0,
		'bytes_saved' => 0,
	);

	if ( empty( $ids ) ) {
		return array(
			'success' => true,
			'message' => 'No unoptimized images to process.',
			'results' => array(),
			'summary' => $empty_summary,
			'stats'   => Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats(),
		);
	}

	$results = array();
	$summary = $empty_summary;

	foreach ( $ids as $attachment_id ) {
		++$summary['processed'];
		$result    = nexter_mcp_optimize_single_attachment( (int) $attachment_id );
		$results[] = $result;

		if ( ! empty( $result['skipped'] ) ) {
			++$summary['skipped'];
		} elseif ( ! empty( $result['success'] ) ) {
			++$summary['optimized'];
			$summary['bytes_saved'] += isset( $result['bytes_saved'] ) ? (int) $result['bytes_saved'] : 0;
		} else {
			++$summary['failed'];
		}
	}

	return array(
		'success' => true,
		'message' => sprintf(
			'%1$d optimized, %2$d skipped, %3$d failed (out of %4$d).',
			$summary['optimized'],
			$summary['skipped'],
			$summary['failed'],
			$summary['processed']
		),
		'results' => $results,
		'summary' => $summary,
		'stats'   => Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats(),
	);
}

/**
 * Optimize a single attachment via Nexter's image optimization pipeline.
 *
 * Replicates the persistence logic of
 * Nexter_Ext_Image_Upload_Optimization::ajax_convert_attachment() without the
 * AJAX/nonce wrapping so it can be called server-side from ability callbacks.
 *
 * @since 2.1.0
 *
 * @param int $attachment_id Attachment post ID to optimize.
 * @return array<string,mixed> {
 *     Result row describing the outcome for this attachment.
 *
 *     @type int    $attachment_id   Attachment ID processed.
 *     @type bool   $success         True when the image was optimized.
 *     @type bool   $skipped         True when skipped (e.g. excluded path).
 *     @type string $message         Human-readable status.
 *     @type int    $bytes_saved     Bytes removed from the main image.
 * }
 */
function nexter_mcp_optimize_single_attachment( int $attachment_id ): array {
	$optimizer     = Nexter_Ext_Image_Upload_Optimization::get_instance();
	$limit_handler = Nexter_Ext_Image_Optimization_Limit::get_instance();

	if ( $attachment_id <= 0 ) {
		return array(
			'attachment_id' => 0,
			'success'       => false,
			'skipped'       => false,
			'message'       => 'Invalid attachment ID.',
		);
	}

	$skip = $optimizer->get_optimization_skip_reason( $attachment_id );
	if ( ! empty( $skip['skip'] ) ) {
		return array(
			'attachment_id' => $attachment_id,
			'success'       => false,
			'skipped'       => true,
			'message'       => isset( $skip['message'] ) ? (string) $skip['message'] : 'Skipped.',
		);
	}

	$file_path   = get_attached_file( $attachment_id );
	$mime_type   = get_post_mime_type( $attachment_id );
	$valid_mimes = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif' );
	$settings    = $optimizer->get_settings();

	$optimizer->create_optimizer_folders();
	$result = $optimizer->process_image( $file_path, $settings );

	if ( ! $result || empty( $result['success'] ) || empty( $result['file'] ) || ! file_exists( $result['file'] ) ) {
		return array(
			'attachment_id' => $attachment_id,
			'success'       => false,
			'skipped'       => false,
			'message'       => ( is_array( $result ) && ! empty( $result['message'] ) )
				? (string) $result['message']
				: 'Optimisation failed.',
		);
	}

	$upload_dir         = Nexter_Ext_Image_Upload_Optimization::get_upload_dir();
	$basedir            = wp_normalize_path( $upload_dir['basedir'] );
	$original_path      = $file_path;
	$optimized_path     = $result['file'];
	$original_relative  = ltrim( str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $original_path ) ) ), '/' );
	$optimized_relative = Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $optimized_path );

	$backup_dir    = WP_CONTENT_DIR . '/nexter-optimizer/backups';
	$backup_path   = wp_normalize_path( $backup_dir . '/' . $original_relative );
	$backup_parent = dirname( $backup_path );
	if ( ! is_dir( $backup_parent ) ) {
		wp_mkdir_p( $backup_parent );
	}
	if ( ! file_exists( $backup_path ) && file_exists( $original_path ) ) {
		// Native copy mirrors how Nexter Extension persists its backup files.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
		@copy( $original_path, $backup_path );
	}
	$backup_relative = file_exists( $backup_path )
		? Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $backup_path )
		: '';

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
	$metadata['nxt_optimized_format']    = isset( $result['format'] ) ? $result['format'] : 'webp';
	$metadata['nxt_original_mime']       = isset( $result['original_mime'] ) ? $result['original_mime'] : $mime_type;
	if ( '' !== $backup_relative ) {
		$metadata['nxt_backup_file'] = $backup_relative;
	}

	$total_original  = (int) $result['original_size'];
	$total_optimized = (int) $result['optimized_size'];
	$sizes_converted = 0;
	$base_dir        = dirname( $file_path );

	if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		if ( ! isset( $metadata['nxt_optimized_sizes'] ) || ! is_array( $metadata['nxt_optimized_sizes'] ) ) {
			$metadata['nxt_optimized_sizes'] = array();
		}

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

			$exclude_paths = isset( $settings['exclude_paths'] ) ? $settings['exclude_paths'] : array();
			if ( $optimizer->is_path_excluded( $size_file_path, $exclude_paths ) ) {
				continue;
			}

			$size_relative      = ltrim( str_replace( $basedir . '/', '', wp_normalize_path( str_replace( '\\', '/', $size_file_path ) ) ), '/' );
			$size_backup_path   = wp_normalize_path( $backup_dir . '/' . $size_relative );
			$size_backup_parent = dirname( $size_backup_path );
			if ( ! is_dir( $size_backup_parent ) ) {
				wp_mkdir_p( $size_backup_parent );
			}
			if ( ! file_exists( $size_backup_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
				@copy( $size_file_path, $size_backup_path );
			}
			$size_backup_rel = file_exists( $size_backup_path )
				? Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $size_backup_path )
				: '';

			$size_result = $optimizer->process_image( $size_file_path, $settings );
			if ( $size_result && ! empty( $size_result['success'] ) && ! empty( $size_result['file'] ) ) {
				$total_original  += (int) $size_result['original_size'];
				$total_optimized += (int) $size_result['optimized_size'];
				++$sizes_converted;
				$metadata['nxt_optimized_sizes'][ $size_name ] = array(
					'file'           => Nexter_Ext_Image_Upload_Optimization::absolute_to_relative_content( $size_result['file'] ),
					'format'         => isset( $size_result['format'] ) ? $size_result['format'] : 'webp',
					'original_size'  => (int) $size_result['original_size'],
					'optimized_size' => (int) $size_result['optimized_size'],
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

	$thumb_count  = ( isset( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) )
		? count( $metadata['nxt_optimized_sizes'] )
		: 0;
	$credit_count = 1 + $thumb_count;
	$limit_handler->record_optimization( $attachment_id, $total_original, $total_optimized, $credit_count );

	$saved     = (int) $result['original_size'] - (int) $result['optimized_size'];
	$saved_pct = $result['original_size'] > 0 ? round( ( $saved / $result['original_size'] ) * 100, 2 ) : 0;

	return array(
		'attachment_id'   => $attachment_id,
		'success'         => true,
		'skipped'         => false,
		'message'         => sprintf( 'Optimised (%d sizes converted).', $sizes_converted + 1 ),
		'format'          => isset( $result['format'] ) ? $result['format'] : 'webp',
		'original_size'   => (int) $result['original_size'],
		'optimized_size'  => (int) $result['optimized_size'],
		'bytes_saved'     => max( 0, $saved ),
		'saved_percent'   => $saved_pct,
		'sizes_converted' => $sizes_converted + 1,
	);
}

/**
 * Ability callback: restore every Nexter-optimized image from backup.
 *
 * Walks every attachment with `nxt_optimized_file` metadata, copies the backup
 * back over the original file, deletes the optimized variants, and strips the
 * `nxt_*` metadata keys so the Bulk Image Optimizer dashboard re-detects the
 * image as unoptimized.
 *
 * @since 2.1.0
 *
 * @param array<string,mixed> $input Ability input (no parameters accepted).
 * @return array<string,mixed>
 */
function nexter_mcp_restore_original_images( array $input ): array {
	unset( $input );

	$deps = nexter_mcp_bulk_image_optimizer_deps();
	if ( null !== $deps ) {
		return $deps;
	}

	$args = array(
		'post_type'              => 'attachment',
		'post_mime_type'         => 'image',
		'post_status'            => 'inherit',
		// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Full-site restore mirrors Nexter Extension's own AJAX handler.
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	$query    = new WP_Query( $args );
	$restored = 0;
	$failed   = 0;
	$skipped  = 0;

	foreach ( $query->posts as $post_id ) {
		$attachment_id = (int) $post_id;
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata['nxt_optimized_file'] ) ) {
			++$skipped;
			continue;
		}

		$restore_target = get_attached_file( $attachment_id );
		if ( ! $restore_target ) {
			++$failed;
			continue;
		}
		$restore_target = wp_normalize_path( $restore_target );
		$backup_path    = ! empty( $metadata['nxt_backup_file'] )
			? Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $metadata['nxt_backup_file'] )
			: null;

		if ( $backup_path && file_exists( $backup_path ) ) {
			$target_dir = dirname( $restore_target );
			if ( ! is_dir( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $backup_path, $restore_target ) ) {
				++$failed;
				continue;
			}
		}

		$optimized_path = Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $metadata['nxt_optimized_file'] );
		if ( $optimized_path && file_exists( $optimized_path ) ) {
			wp_delete_file( $optimized_path );
		}

		if ( ! empty( $metadata['nxt_optimized_sizes'] ) && is_array( $metadata['nxt_optimized_sizes'] ) ) {
			foreach ( $metadata['nxt_optimized_sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_abs = Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $size['file'] );
					if ( $size_abs && file_exists( $size_abs ) ) {
						wp_delete_file( $size_abs );
					}
				}
				if ( ! empty( $size['backup_file'] ) ) {
					$size_backup_abs = Nexter_Ext_Image_Upload_Optimization::get_absolute_path( $size['backup_file'] );
					if ( $size_backup_abs && file_exists( $size_backup_abs ) ) {
						$size_target = wp_normalize_path( dirname( $restore_target ) . '/' . basename( $size_backup_abs ) );
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
						@copy( $size_backup_abs, $size_target );
					}
				}
			}
		}

		$strip_keys = array(
			'nxt_main_original_size',
			'nxt_main_optimized_size',
			'nxt_original_size',
			'nxt_optimized_size',
			'nxt_original_file',
			'nxt_optimized_file',
			'nxt_optimized_format',
			'nxt_original_mime',
			'nxt_backup_file',
			'nxt_optimized_sizes',
		);
		foreach ( $strip_keys as $key ) {
			unset( $metadata[ $key ] );
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
		wp_cache_delete( $attachment_id, 'post_meta' );
		clean_post_cache( $attachment_id );
		++$restored;
	}

	return array(
		'success'  => true,
		'message'  => sprintf( 'Restored %1$d images. %2$d skipped (not optimised), %3$d failed.', $restored, $skipped, $failed ),
		'restored' => $restored,
		'skipped'  => $skipped,
		'failed'   => $failed,
		'stats'    => Nexter_Ext_Image_Optimization_Limit::get_instance()->get_ui_stats(),
	);
}
