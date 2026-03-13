/**
 * Media Library: Convert to optimised Format button (Image Upload optimise extension).
 * Compatible with Elementor editor media popup (widget image select); script is enqueued
 * via elementor/editor/after_enqueue_scripts and elementor/preview/enqueue_scripts.
 */
(function( $ ) {
	'use strict';

	$( document ).on( 'click', '.nxt-ext-image-convert-btn', function( e ) {
		e.preventDefault();

		var $btn    = $( this ),
			$w      = $btn.closest( '.nxt-ext-image-convert-wrapper' ).length ? $btn.closest( '.nxt-ext-image-convert-wrapper' ) : $btn.closest( '.nxt-opt-btn-wrap' ),
			$msg    = $w.find( '.nxt-ext-image-convert-message' ),
			id      = $btn.data( 'attachment-id' ),
			originalBtnText = $btn.text().trim();

		if ( ! id || typeof nxtExtImageOptimise === 'undefined' ) {
			return;
		}

		$btn.prop( 'disabled', true );
		$msg.removeClass( 'success error' ).hide();

		// Update button text to show "Optimising..." if button text exists
		if ( originalBtnText ) {
			$btn.data( 'original-text', originalBtnText );
			$btn.html( nxtExtImageOptimise.converting );
		}

		$.post( nxtExtImageOptimise.ajaxUrl, {
			action: 'nxt_ext_image_convert_attachment',
			nonce: nxtExtImageOptimise.nonce,
			attachment_id: id
		} ).done( function( r ) {
			if ( r && r.success ) {
				$msg.removeClass( 'error' ).addClass( 'success' ).text( nxtExtImageOptimise.success ).show();
				if ( typeof wp !== 'undefined' && wp.media && wp.media.model && wp.media.model.Attachment ) {
					var m = wp.media.model.Attachment.get( id );
					if ( m ) {
						m.fetch( { reset: true } );
					}
				}
				
				// Build and inject the updated "Image Successfully Optimised" UI without reloading.
				try {
					var data         = r.data || {};
					var originalSize = parseInt( data.original_size, 10 ) || 0;
					var optimizedSize = parseInt( data.optimized_size, 10 ) || 0;
					var savedPercent = typeof data.saved_percent !== 'undefined' ? parseFloat( data.saved_percent ) : 0;
					var isColumnView = $btn.closest( '.column-nxt_optimize, .nxt_optimize' ).length > 0;

					if ( isColumnView ) {
						// List column: render simple status HTML (same as PHP nxt-opt-status-wrap).
						var pctText = ( typeof nxtExtImageOptimise.smallerLabel !== 'undefined' )
							? nxtExtImageOptimise.smallerLabel.replace( '%s', savedPercent.toFixed( 2 ) + '%' )
							: savedPercent.toFixed( 2 ) + '% smaller';
						var statusHtml = '<div class="nxt-opt-status-wrap">';
						statusHtml += '<div style="font-size:13px;color:#1a1a1a;margin-bottom:2px;">' + ( nxtExtImageOptimise.imageOptimisedLabel || 'Image Optimised' ) + '</div>';
						if ( originalSize > 0 ) {
							statusHtml += '<div style="font-size:13px;color:#1717cc;">' + pctText + '</div>';
						}
						statusHtml += '</div>';
						$w.replaceWith( statusHtml );
						return;
					}

					var stats        = data.stats || {};

					var usageCount = parseInt( stats.monthly_count, 10 ) || 0;
					var usageLimit = parseInt( stats.monthly_limit, 10 ) || 0;
					var isPro      = !! stats.is_pro;
					var limitReached = ! isPro && usageLimit > 0 && usageCount >= usageLimit;
					var usagePct   = usageLimit > 0 ? Math.min( 100, ( usageCount / usageLimit ) * 100 ) : 0;
					var fillColor  = limitReached ? '#FF1400' : '#1717CC';
					var resetDays  = parseInt( stats.resets_in_days, 10 ) || 0;

					// Simple size formatter to mimic WP size_format() output for KB/MB ranges.
					var formatBytes = function( bytes ) {
						if ( ! bytes || bytes <= 0 ) {
							return '0 KB';
						}
						var kb = bytes / 1024;
						if ( kb < 1024 ) {
							return kb.toFixed( 2 ) + ' KB';
						}
						var mb = kb / 1024;
						return mb.toFixed( 2 ) + ' MB';
					};

					// Optimised format is always part of the server-side process; fall back to "WEBP".
					var format = ( data.format || 'webp' ).toString().toUpperCase();

					var cardHtml  = '';
					cardHtml += '<div class="nxt-opt-header">';
					cardHtml += '<div class="nxt-opt-icon"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="12" fill="none" viewBox="0 0 11 12"><path stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.05" d="M1.052 6.827a.525.525 0 0 1-.41-.856L5.84.616a.263.263 0 0 1 .451.242l-1.008 3.16a.525.525 0 0 0 .494.709h3.675a.525.525 0 0 1 .41.856l-5.198 5.355a.262.262 0 0 1-.452-.242l1.008-3.16a.525.525 0 0 0-.493-.71z"/></svg></div>';
					cardHtml += '<div class="nxt-opt-title-grp">';
					cardHtml += '<h3>' + nxtExtImageOptimise.successTitle + '</h3>';
					cardHtml += '<p class="nxt-opt-desc">' + nxtExtImageOptimise.successDesc
						.replace( '%s1', format )
						.replace( '%s2', savedPercent.toFixed( 2 ) ) + '</p>';
					cardHtml += '</div>';
					cardHtml += '</div>';

					cardHtml += '<div class="nxt-opt-stats">';
					cardHtml += '<div class="nxt-opt-row"><span>' + nxtExtImageOptimise.originalSizeLabel + '</span><span style="color: #1A1A1A">' + formatBytes( originalSize ) + '</span></div>';
					cardHtml += '<div class="nxt-opt-row"><span>' + nxtExtImageOptimise.optimizedSizeLabel + '</span><span class="nxt-opt-val-green">' + formatBytes( optimizedSize ) + '</span></div>';
					cardHtml += '</div>';

					cardHtml += '<div class="nxt-opt-btn-wrap">';
					if ( limitReached ) {
						cardHtml += '<a href="' + nxtExtImageOptimise.upgradeUrl + '" target="_blank" class="nxt-ext-image-upgrade-btn nxt-opt-primary-btn">' + nxtExtImageOptimise.upgradeLabel + '</a>';
					} else {
						cardHtml += '<button type="button" class="button nxt-ext-image-convert-btn" data-attachment-id="' + id + '">';
						cardHtml += nxtExtImageOptimise.reconvertLabel;
						cardHtml += '</button>';
						cardHtml += '<span class="nxt-ext-image-convert-spinner spinner" style="display:none;position:absolute;right:10px;top:8px;"></span>';
						cardHtml += '<div class="nxt-ext-image-convert-message"></div>';
					}
					cardHtml += '</div>';

					// Usage block (only when not pro and we have limits).
					if ( ! isPro && usageLimit > 0 ) {
						cardHtml += '<div class="nxt-opt-usage-wrap">';
						cardHtml += '<div class="nxt-opt-usage-hdr"><span>' + nxtExtImageOptimise.monthlyUsageLabel + '</span><span>' + usageCount + ' / ' + usageLimit + '</span></div>';
						cardHtml += '<div class="nxt-opt-progress-bar"><div class="nxt-opt-progress-fill" style="width: ' + usagePct + '%; background: ' + fillColor + ';"></div></div>';
						cardHtml += '<div class="nxt-opt-reset-days"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#666" clip-path="url(#aasdda)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333Z"/><path stroke-linecap="round" d="M8 4.445v3.556l2.222 2.222"/></g><defs><clipPath id="aasdda"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg>' +
							nxtExtImageOptimise.resetsInDaysLabel.replace( '%d', resetDays ) +
							'</div>';
						cardHtml += '</div>';
					}

					var $card = $btn.closest( '.nxt-opt-card' );
					if ( $card.length ) {
						$card.html( cardHtml );
					} else {
						$w.html( cardHtml );
					}
				} catch ( err ) {
					// If anything goes wrong while building the dynamic card, fall back to reloading.
					setTimeout( function() {
						location.reload();
					}, 500 );
				}
			} else {
				$btn.prop( 'disabled', false );
				// Restore original button text
				if ( $btn.data( 'original-text' ) ) {
					$btn.html( $btn.data( 'original-text' ) );
				}
				var errIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 15 15" aria-hidden="true"><path fill="#ff1400" d="M7.334 0a7.334 7.334 0 1 1-.002 14.668A7.334 7.334 0 0 1 7.334 0m0 1.334a6 6 0 1 0 0 12 6 6 0 0 0 0-12m.003 8.002a.667.667 0 0 1 0 1.334H7.33a.667.667 0 0 1 0-1.334zM7.33 4c.368 0 .667.299.667.667v2.667a.667.667 0 0 1-1.334 0V4.667c0-.368.299-.667.667-.667"/></svg>';
				var errText = ( r && r.data && r.data.message ) ? r.data.message : nxtExtImageOptimise.error;
				$msg.removeClass( 'success' ).addClass( 'error' ).html( errIcon + ' ' + errText ).show();
			}
		} ).fail( function( xhr ) {
			$btn.prop( 'disabled', false );
			// Restore original button text
			if ( $btn.data( 'original-text' ) ) {
				$btn.html( $btn.data( 'original-text' ) );
			}
			var errIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 15 15" aria-hidden="true"><path fill="#ff1400" d="M7.334 0a7.334 7.334 0 1 1-.002 14.668A7.334 7.334 0 0 1 7.334 0m0 1.334a6 6 0 1 0 0 12 6 6 0 0 0 0-12m.003 8.002a.667.667 0 0 1 0 1.334H7.33a.667.667 0 0 1 0-1.334zM7.33 4c.368 0 .667.299.667.667v2.667a.667.667 0 0 1-1.334 0V4.667c0-.368.299-.667.667-.667"/></svg>';
			var msg = nxtExtImageOptimise.error;
			if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
			$msg.removeClass( 'success' ).addClass( 'error' ).html( errIcon + ' ' + msg ).show();
		} );
	} );
})( jQuery );
