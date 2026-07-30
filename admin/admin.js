/* global jQuery */
( function ( $ ) {
	'use strict';

	// Escape a value for safe insertion into HTML content or attribute values.
	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// ── Tab switching ────────────────────────────────────────────────────────
	$( '.ism-tabs' ).on( 'click', '.ism-tab', function () {
		var $btn    = $( this );
		var target  = $btn.data( 'target' );

		$( '.ism-tab' ).removeClass( 'active' );
		$btn.addClass( 'active' );

		$( '.ism-panel' ).attr( 'hidden', true );
		$( '#' + target ).removeAttr( 'hidden' );
	} );

	// ── Sync a size key's disabled state across ALL CPT pickers ────────────
	function syncAllCptPickers( key, isDisabled ) {
		$( '.ism-cpt-sizes-picker' ).each( function () {
			var $cb = $( this ).find(
				'input[type="checkbox"][value="' + $.escapeSelector( key ) + '"]'
			);
			if ( ! $cb.length ) {
				return;
			}
			if ( isDisabled ) {
				$cb.prop( 'checked', false ).prop( 'disabled', true );
				$cb.closest( 'tr' ).addClass( 'ism-row-disabled' );
			} else {
				$cb.prop( 'disabled', false );
				$cb.closest( 'tr' ).removeClass( 'ism-row-disabled' );
			}
		} );
	}

	// ── Disable-toggle: dim/undim row + sync all CPT pickers ────────────────
	$( document ).on( 'change', '.ism-disable-toggle', function () {
		var $row       = $( this ).closest( 'tr' );
		var sizeKey    = $( this ).val();
		var isDisabled = $( this ).is( ':checked' );

		if ( isDisabled ) {
			$row.addClass( 'ism-row-disabled' );
		} else {
			$row.removeClass( 'ism-row-disabled' );
		}

		syncAllCptPickers( sizeKey, isDisabled );
	} );

	// ── On page load: apply disabled state to all CPT pickers immediately ───
	$( '.ism-disable-toggle:checked' ).each( function () {
		syncAllCptPickers( $( this ).val(), true );
	} );

	// ── CPT sidebar navigation ───────────────────────────────────────────────
	$( '#ism-panel-cpts' ).on( 'click', '.ism-cpt-nav-item', function () {
		var cpt = $( this ).data( 'cpt' );

		$( '.ism-cpt-nav-item' ).removeClass( 'active' );
		$( this ).addClass( 'active' );

		$( '.ism-cpt-panel' ).attr( 'hidden', true );
		$( '.ism-cpt-panel[data-cpt="' + cpt + '"]' ).removeAttr( 'hidden' );
	} );

	// ── Per-CPT restrict toggle: show/hide that CPT's size picker ───────────
	$( document ).on( 'change', '.ism-cpt-restrict-toggle', function () {
		var $picker = $( this ).closest( '.ism-cpt-panel' ).find( '.ism-cpt-sizes-picker' );
		if ( $( this ).is( ':checked' ) ) {
			$picker.removeAttr( 'hidden' );
		} else {
			$picker.attr( 'hidden', true );
		}
	} );

	// ── Custom sizes: add row ────────────────────────────────────────────────
	var customRowIndex = $( '#ism-custom-sizes-body .ism-custom-row' ).length;

	$( '#ism-add-custom-size' ).on( 'click', function () {
		var idx = customRowIndex++;
		var row = [
			'<tr class="ism-custom-row">',
			'  <td><input type="text" name="ism_custom_sizes[' + idx + '][name]"',
			'       placeholder="my-size-key" class="regular-text" required></td>',
			'  <td><input type="number" min="0" name="ism_custom_sizes[' + idx + '][width]"',
			'       value="0" class="ism-dim-input"></td>',
			'  <td><input type="number" min="0" name="ism_custom_sizes[' + idx + '][height]"',
			'       value="0" class="ism-dim-input"></td>',
			'  <td><input type="checkbox" name="ism_custom_sizes[' + idx + '][crop]" value="1"></td>',
			'  <td><button type="button" class="button ism-remove-row">Remove</button></td>',
			'</tr>'
		].join( '\n' );

		$( '#ism-custom-sizes-body' ).append( row );
	} );

	// ── Custom sizes: remove row ─────────────────────────────────────────────
	$( '#ism-custom-sizes-body' ).on( 'click', '.ism-remove-row', function () {
		$( this ).closest( 'tr' ).remove();
	} );

	// ── Thumbnail regeneration ───────────────────────────────────────────────
	var regenAborted = false;
	var ISM_LS_PREFIX = 'ism_regen_state_';

	function regenSaveState( cptKey, transientKey, offset, total ) {
		try {
			localStorage.setItem( ISM_LS_PREFIX + cptKey, JSON.stringify( {
				transientKey: transientKey,
				offset:       offset,
				total:        total,
				cptKey:       cptKey,
				savedAt:      Date.now(),
			} ) );
		} catch ( e ) { /* localStorage unavailable — silently skip */ }
	}

	function regenClearState( cptKey ) {
		try { localStorage.removeItem( ISM_LS_PREFIX + cptKey ); } catch ( e ) {}
	}

	function regenLoadState( cptKey ) {
		try {
			var raw = localStorage.getItem( ISM_LS_PREFIX + cptKey );
			if ( ! raw ) { return null; }
			var s = JSON.parse( raw );
			// Discard states older than 23 hours (transient expires at 24h).
			if ( Date.now() - ( s.savedAt || 0 ) > 23 * 3600 * 1000 ) {
				regenClearState( cptKey );
				return null;
			}
			return s;
		} catch ( e ) { return null; }
	}

	function regenSetState( $card, state ) {
		var $start     = $card.find( '.ism-regen-start' );
		var $cancel    = $card.find( '.ism-regen-cancel' );
		var $progress  = $card.find( '.ism-progress-wrap' );
		var $log       = $card.find( '.ism-regen-log' );
		var $resumeBar = $card.find( '.ism-regen-resume-bar' );
		var $controls  = $card.find( '.ism-regen-controls' );

		if ( state === 'idle' ) {
			$start.prop( 'disabled', false ).show();
			$cancel.hide();
			$controls.show();
			$resumeBar.hide();
			$progress.hide();
		} else if ( state === 'running' ) {
			$start.prop( 'disabled', true ).hide();
			$cancel.show();
			$controls.show();
			$resumeBar.hide();
			$progress.show();
			$log.show().empty();
		} else if ( state === 'done' ) {
			$start.prop( 'disabled', false ).show();
			$cancel.hide();
			$controls.show();
			$resumeBar.hide();
			$progress.show();
		} else if ( state === 'resume' ) {
			$controls.hide();
			$resumeBar.show();
			$progress.hide();
		}
	}

	function regenUpdateBar( $card, done, total, totalDeleted ) {
		var pct     = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var deleted = totalDeleted > 0 ? ' — ' + totalDeleted + ' file' + ( totalDeleted === 1 ? '' : 's' ) + ' deleted' : '';
		$card.find( '.ism-progress-bar-fill' ).css( 'width', pct + '%' );
		$card.find( '.ism-progress-status' ).text( done + ' / ' + total + ' — ' + pct + '%' + deleted );
	}

	function regenAppendLog( $card, messages ) {
		var $log = $card.find( '.ism-regen-log' );
		$.each( messages, function ( i, msg ) {
			var cls = msg.type === 'error' ? 'ism-regen-log-error' : 'ism-regen-log-ok';
			$log.append( '<li class="' + cls + '">' + $( '<span>' ).text( msg.text ).html() + '</li>' );
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function regenRunBatch( $card, cptKey, transientKey, offset, total, retries ) {
		retries = retries || 0;

		if ( regenAborted ) {
			regenSaveState( cptKey, transientKey, offset, total );
			regenSetState( $card, 'done' );
			$card.find( '.ism-progress-status' )
				.text( 'Paused at ' + offset + ' / ' + total + '. Reload the page to resume.' );
			return;
		}

		$.post( ismData.ajaxUrl, {
			action:        'ism_regen_batch',
			nonce:         ismData.nonce,
			cpt_key:       cptKey,
			transient_key: transientKey,
			offset:        offset,
		}, function ( res ) {
			if ( ! res.success ) {
				// Server returned an application-level error — save and surface it.
				regenSaveState( cptKey, transientKey, offset, total );
				regenSetState( $card, 'done' );
				$card.find( '.ism-progress-status' )
					.text( 'Error: ' + ( res.data || 'unknown' ) + ' \u2014 reload the page to resume.' );
				return;
			}

			var data = res.data;
			regenAppendLog( $card, data.messages );
			regenUpdateBar( $card, data.offset, data.total, data.total_deleted );

			if ( data.done ) {
				regenClearState( cptKey );
				regenSetState( $card, 'done' );
				var delMsg = data.total_deleted > 0 ? ' ' + data.total_deleted + ' old file' + ( data.total_deleted === 1 ? '' : 's' ) + ' deleted.' : '';
				$card.find( '.ism-progress-status' )
					.text( 'Done! ' + data.total + ' image(s) processed.' + delMsg );
			} else {
				regenSaveState( cptKey, transientKey, data.offset, data.total );
				setTimeout( function () {
					regenRunBatch( $card, cptKey, transientKey, data.offset, data.total, 0 );
				}, 300 );
			}
		} ).fail( function () {
			if ( retries < 3 ) {
				// Auto-retry with exponential back-off (2s, 4s, 8s).
				var delay = Math.pow( 2, retries + 1 ) * 1000;
				$card.find( '.ism-progress-status' )
					.text( 'Request failed \u2014 retrying in ' + ( delay / 1000 ) + 's\u2026 (' + ( retries + 1 ) + '/3)' );
				setTimeout( function () {
					regenRunBatch( $card, cptKey, transientKey, offset, total, retries + 1 );
				}, delay );
			} else {
				// All retries exhausted — save progress so user can resume.
				regenSaveState( cptKey, transientKey, offset, total );
				regenSetState( $card, 'done' );
				$card.find( '.ism-progress-status' )
					.text( 'Connection lost at ' + offset + ' / ' + total + '. Reload the page to resume.' );
			}
		} );
	}

	function regenDoInit( $card, cptKey, label, forceRestart ) {
		regenAborted = false;
		regenSetState( $card, 'running' );
		$card.find( '.ism-progress-bar-fill' ).css( 'width', '0%' );
		$card.find( '.ism-progress-status' ).text( 'Collecting images\u2026' );

		$.post( ismData.ajaxUrl, {
			action:         'ism_regen_init',
			nonce:          ismData.nonce,
			cpt_key:        cptKey,
			force_restart:  forceRestart ? 1 : 0,
		}, function ( res ) {
			if ( ! res.success ) {
				regenSetState( $card, 'idle' );
				// eslint-disable-next-line no-alert
				alert( 'Error: ' + ( res.data || 'unknown' ) );
				return;
			}
			var data = res.data;
			if ( data.total === 0 ) {
				regenSetState( $card, 'done' );
				$card.find( '.ism-progress-wrap' ).show();
				$card.find( '.ism-progress-status' ).text( 'No images found for this post type.' );
				return;
			}
			// If resuming, start from the locally saved offset (more reliable than server).
			var startOffset = 0;
			if ( ! forceRestart ) {
				var saved = regenLoadState( cptKey );
				if ( saved && saved.transientKey === data.transient_key ) {
					startOffset = saved.offset;
				}
			} else {
				regenClearState( cptKey );
			}
			regenUpdateBar( $card, startOffset, data.total, 0 );
			$card.find( '.ism-regen-log' ).show().empty();
			regenRunBatch( $card, cptKey, data.transient_key, startOffset, data.total, 0 );
		} ).fail( function () {
			regenSetState( $card, 'idle' );
			// eslint-disable-next-line no-alert
			alert( 'Init request failed. Check your server error log.' );
		} );
	}

	// Check localStorage on load and show resume bar for any in-progress runs.
	$( '.ism-regen-card' ).each( function () {
		var $card  = $( this );
		var cptKey = $card.data( 'cpt' );
		var saved  = regenLoadState( cptKey );
		if ( saved && saved.offset > 0 && saved.offset < saved.total ) {
			$card.find( '.ism-regen-resume-info' )
				.text( 'Previous run paused at ' + saved.offset + ' of ' + saved.total + ' images.' );
			regenSetState( $card, 'resume' );
		}
	} );

	// Start button (always starts fresh)
	$( document ).on( 'click', '.ism-regen-start', function () {
		var $btn   = $( this );
		var cptKey = $btn.data( 'cpt' );
		var label  = $btn.data( 'label' );
		var $card  = $btn.closest( '.ism-regen-card' );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Regenerate thumbnails for all \u201c' + label + '\u201d images? Old size files will be permanently deleted.' ) ) {
			return;
		}
		regenDoInit( $card, cptKey, label, true );
	} );

	// Resume button
	$( document ).on( 'click', '.ism-regen-resume-btn', function () {
		var $card  = $( this ).closest( '.ism-regen-card' );
		var cptKey = $card.data( 'cpt' );
		regenDoInit( $card, cptKey, '', false );
	} );

	// Start Fresh button (from resume bar)
	$( document ).on( 'click', '.ism-regen-fresh-btn', function () {
		var $card  = $( this ).closest( '.ism-regen-card' );
		var cptKey = $card.data( 'cpt' );
		var label  = $card.find( '.ism-regen-start' ).data( 'label' );
		regenClearState( cptKey );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Regenerate thumbnails for all \u201c' + label + '\u201d images? Old size files will be permanently deleted.' ) ) {
			regenSetState( $card, 'idle' );
			return;
		}
		regenDoInit( $card, cptKey, label, true );
	} );

	// Cancel button
	$( document ).on( 'click', '.ism-regen-cancel', function () {
		regenAborted = true;
	} );

        // ── Media Log ────────────────────────────────────────────────────────
        var logPage    = 1;
        var logSearch  = '';
        var logLoading = false;

        function logLoad( page, search ) {
                if ( logLoading ) { return; }
                logLoading = true;
                logPage    = page;
                logSearch  = search;

                var $panel   = $( '#ism-panel-media-log' );
                var $list    = $panel.find( '.ism-log-list' );
                var $loading = $panel.find( '.ism-log-loading' );
                var $summary = $panel.find( '.ism-log-summary' );
                var $prev    = $( '#ism-log-prev' );
                var $next    = $( '#ism-log-next' );
                var $pInfo   = $panel.find( '.ism-log-page-info' );

                $list.html( '' );
                $loading.show();
                $prev.prop( 'disabled', true );
                $next.prop( 'disabled', true );

                $.post( ismData.ajaxUrl, {
                        action: 'ism_media_log',
                        nonce:  ismData.mediaLogNonce,
                        page:   page,
                        search: search,
                }, function ( res ) {
                        $loading.hide();
                        logLoading = false;
                        if ( ! res.success ) {
                                $list.html( '<p>Error loading media log.</p>' );
                                return;
                        }
                        var d = res.data;
                        $summary.text( d.total + ' image' + ( d.total === 1 ? '' : 's' ) );
                        $pInfo.text( 'Page ' + d.page + ' of ' + d.total_pages );
                        $prev.prop( 'disabled', d.page <= 1 );
                        $next.prop( 'disabled', d.page >= d.total_pages );

                        if ( d.items.length === 0 ) {
                                $list.html( '<p>No images found.</p>' );
                                return;
                        }

                        $.each( d.items, function ( i, item ) {
                                var thumb = item.thumb_url ? '<img src="' + esc( item.thumb_url ) + '" class="ism-log-thumb" alt="" />' : '<div class="ism-log-thumb ism-log-thumb-missing"></div>';
                                var parentInfo = item.parent_title ? '<a href="' + esc( item.parent_url ) + '">' + esc( item.parent_title ) + '</a>' : '<span class="ism-log-dim">—</span>';
                                var origStatus = item.orig_exists ? '' : ' ism-log-missing';

                                var sizesHtml = '';
                                if ( item.sizes.length > 0 ) {
                                        sizesHtml += '<table class="ism-log-sizes-table"><thead><tr><th>Size key</th><th>File</th><th>Dimensions</th><th>File size</th><th>Status</th></tr></thead><tbody>';
                                        $.each( item.sizes, function ( j, s ) {
                                                var statusClass = s.exists ? 'ism-log-ok' : 'ism-log-miss';
                                                var statusText  = s.exists ? '&#10003; exists' : '&#10007; missing';
                                                sizesHtml += '<tr class="' + statusClass + '"><td><code>' + esc( s.key ) + '</code></td><td>' + esc( s.file ) + '</td><td>' + esc( s.width ) + ' &times; ' + esc( s.height ) + '</td><td>' + esc( s.filesize ) + '</td><td>' + statusText + '</td></tr>';
                                        } );
                                        sizesHtml += '</tbody></table>';
                                } else {
                                        sizesHtml = '<p class="ism-log-dim">No size variations on record.</p>';
                                }

                                var row = '<div class="ism-log-entry">' +
                                        '<div class="ism-log-entry-header">' +
                                        thumb +
                                        '<div class="ism-log-entry-meta">' +
                                        '<strong class="' + origStatus + '">' + esc( item.filename ) + '</strong>' +
                                        '<span class="ism-log-dim">' + esc( item.orig_width ) + ' &times; ' + esc( item.orig_height ) + ' &nbsp;&bull;&nbsp; ' + esc( item.orig_filesize ) + '</span>' +
                                        '<span class="ism-log-dim">Uploaded: ' + esc( item.date ) + '</span>' +
                                        '<span class="ism-log-dim">Attached to: ' + parentInfo + '</span>' +
                                        '</div>' +
                                        '<button type="button" class="button button-small ism-log-toggle">Show ' + item.sizes.length + ' variation' + ( item.sizes.length === 1 ? '' : 's' ) + '</button>' +
                                        '</div>' +
                                        '<div class="ism-log-sizes" style="display:none">' + sizesHtml + '</div>' +
                                        '</div>';
                                $list.append( row );
                        } );
                } ).fail( function () {
                        $loading.hide();
                        logLoading = false;
                        $list.html( '<p>Request failed.</p>' );
                } );
        }

        // Load when tab becomes active
        $( document ).on( 'click', '.ism-tab[data-target="ism-panel-media-log"]', function () {
                if ( $( '.ism-log-list' ).is( ':empty' ) ) {
                        logLoad( 1, '' );
                }
        } );

        // Search
        $( '#ism-log-search-btn' ).on( 'click', function () {
                logLoad( 1, $( '#ism-log-search' ).val().trim() );
        } );
        $( '#ism-log-search' ).on( 'keydown', function ( e ) {
                if ( e.which === 13 ) { logLoad( 1, $( this ).val().trim() ); }
        } );

        // Clear
        $( '#ism-log-clear-btn' ).on( 'click', function () {
                $( '#ism-log-search' ).val( '' );
                logLoad( 1, '' );
        } );

        // Pagination
        $( '#ism-log-prev' ).on( 'click', function () { logLoad( logPage - 1, logSearch ); } );
        $( '#ism-log-next' ).on( 'click', function () { logLoad( logPage + 1, logSearch ); } );

        // Expand/collapse size list
        $( document ).on( 'click', '.ism-log-toggle', function () {
                var $entry  = $( this ).closest( '.ism-log-entry' );
                var $sizes  = $entry.find( '.ism-log-sizes' );
                var isOpen  = $sizes.is( ':visible' );
                var $btn    = $( this );
                $sizes.slideToggle( 150 );
                var count = $entry.find( '.ism-log-sizes-table tbody tr' ).length || 0;
                $btn.text( ( isOpen ? 'Show ' : 'Hide ' ) + count + ' variation' + ( count === 1 ? '' : 's' ) );
        } );

	// ── Bulk batch runner (shared by both bulk tools) ────────────────────────
	function bulkRunBatch( cfg ) {
		// cfg = { ajaxUrl, nonce, batchAction, transientKey, offset, total,
		//         $bar, $status, $log, $cancelBtn, abortFlag, onDone }
		if ( cfg.abortFlag() ) {
			cfg.$status.text( 'Cancelled at ' + cfg.offset + ' / ' + cfg.total + '.' );
			cfg.$cancelBtn.hide();
			return;
		}

		$.post( ismData.ajaxUrl, {
			action:        cfg.batchAction,
			nonce:         ismData.bulkResizeNonce,
			transient_key: cfg.transientKey,
			offset:        cfg.offset,
		}, function ( res ) {
			if ( ! res.success ) {
				cfg.$status.text( 'Error: ' + ( res.data || 'unknown' ) );
				cfg.$cancelBtn.hide();
				return;
			}
			var d   = res.data;
			var pct = d.total > 0 ? Math.round( ( d.offset / d.total ) * 100 ) : 100;

			cfg.$bar.css( 'width', pct + '%' );
			cfg.$status.text( d.offset + ' / ' + d.total + ' — ' + pct + '%' );
			cfg.$log.show();

			$.each( d.messages, function ( i, msg ) {
				var cls = msg.type === 'error' ? 'ism-regen-log-error' : 'ism-regen-log-ok';
				cfg.$log.append( '<li class="' + cls + '">' + $( '<span>' ).text( msg.text ).html() + '</li>' );
				cfg.$log.scrollTop( cfg.$log[ 0 ].scrollHeight );
			} );

			if ( d.done ) {
				cfg.$cancelBtn.hide();
				cfg.onDone( d );
			} else {
				cfg.offset = d.offset;
				setTimeout( function () { bulkRunBatch( cfg ); }, 200 );
			}
		} ).fail( function () {
			cfg.$status.text( 'Request failed. Reload and try again.' );
			cfg.$cancelBtn.hide();
		} );
	}

	// ── Bulk resize ──────────────────────────────────────────────────────────
	( function () {
		var aborted = false;

		$( '#ism-bulk-resize-start' ).on( 'click', function () {
			if ( ! window.confirm( 'This will resize every image in your library that exceeds the configured max-upload dimensions. The originals will be overwritten. Continue?' ) ) {
				return;
			}
			aborted = false;
			var $startBtn = $( '#ism-bulk-resize-start' );
			var $cancelBtn = $( '#ism-bulk-resize-cancel' );
			var $progress  = $( '#ism-bulk-resize-progress' );
			var $bar       = $( '#ism-bulk-resize-bar' );
			var $status    = $( '#ism-bulk-resize-status' );
			var $log       = $( '#ism-bulk-resize-log' );

			$startBtn.prop( 'disabled', true );
			$cancelBtn.prop( 'disabled', false ).text( 'Cancel' ).show();
			$progress.show();
			$bar.css( 'width', '0%' );
			$status.text( 'Collecting images…' );
			$log.hide().empty();

			$.post( ismData.ajaxUrl, {
				action: 'ism_bulk_resize_init',
				nonce:  ismData.bulkResizeNonce,
			}, function ( res ) {
				if ( ! res.success ) {
					$status.text( 'Error: ' + ( res.data || 'unknown' ) );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				var d = res.data;
				if ( d.total === 0 ) {
					$status.text( 'No images found in library.' );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				$status.text( '0 / ' + d.total + ' — 0%' );
				bulkRunBatch( {
					batchAction:  'ism_bulk_resize_batch',
					transientKey: d.transient_key,
					offset:       0,
					total:        d.total,
					$bar:         $bar,
					$status:      $status,
					$log:         $log,
					$cancelBtn:   $cancelBtn,
					abortFlag:    function () { return aborted; },
					onDone: function ( data ) {
						$startBtn.prop( 'disabled', false );
						$status.text( 'Done! ' + data.total + ' image(s) checked, ' + ( data.total_resized || 0 ) + ' resized.' );
					},
				} );
			} ).fail( function () {
				$status.text( 'Init request failed.' );
				$startBtn.prop( 'disabled', false );
				$cancelBtn.hide();
			} );
		} );

		$( '#ism-bulk-resize-cancel' ).on( 'click', function () {
			aborted = true;
			$( this ).prop( 'disabled', true ).text( 'Cancelling…' );
		} );
	}() );

	// ── Remove -scaled ───────────────────────────────────────────────────────
	( function () {
		var aborted = false;

		$( '#ism-descale-start' ).on( 'click', function () {
			if ( ! window.confirm( 'This will permanently delete all -scaled image files and update the media library to point to the originals. Continue?' ) ) {
				return;
			}
			aborted = false;
			var $startBtn  = $( '#ism-descale-start' );
			var $cancelBtn = $( '#ism-descale-cancel' );
			var $progress  = $( '#ism-descale-progress' );
			var $bar       = $( '#ism-descale-bar' );
			var $status    = $( '#ism-descale-status' );
			var $log       = $( '#ism-descale-log' );

			$startBtn.prop( 'disabled', true );
			$cancelBtn.prop( 'disabled', false ).text( 'Cancel' ).show();
			$progress.show();
			$bar.css( 'width', '0%' );
			$status.text( 'Scanning for -scaled images…' );
			$log.hide().empty();

			$.post( ismData.ajaxUrl, {
				action: 'ism_descale_init',
				nonce:  ismData.bulkResizeNonce,
			}, function ( res ) {
				if ( ! res.success ) {
					$status.text( 'Error: ' + ( res.data || 'unknown' ) );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				var d = res.data;
				if ( d.total === 0 ) {
					$status.text( 'No -scaled images found.' );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				$status.text( 'Found ' + d.total + ' -scaled image(s). Processing…' );
				bulkRunBatch( {
					batchAction:  'ism_descale_batch',
					transientKey: d.transient_key,
					offset:       0,
					total:        d.total,
					$bar:         $bar,
					$status:      $status,
					$log:         $log,
					$cancelBtn:   $cancelBtn,
					abortFlag:    function () { return aborted; },
					onDone: function ( data ) {
						$startBtn.prop( 'disabled', false );
						$status.text( 'Done! ' + ( data.total_cleaned || 0 ) + ' -scaled file(s) removed.' );
					},
				} );
			} ).fail( function () {
				$status.text( 'Init request failed.' );
				$startBtn.prop( 'disabled', false );
				$cancelBtn.hide();
			} );
		} );

		$( '#ism-descale-cancel' ).on( 'click', function () {
			aborted = true;
			$( this ).prop( 'disabled', true ).text( 'Cancelling…' );
		} );
	}() );

	// ── Regenerate All Images ───────────────────────────────────────────────
	( function () {
		var aborted = false;

		$( '#ism-regen-all-start' ).on( 'click', function () {
			if ( ! window.confirm( 'This will regenerate thumbnails for every image in the media library and delete any old size files that are no longer needed. Continue?' ) ) {
				return;
			}
			aborted = false;
			var $startBtn  = $( '#ism-regen-all-start' );
			var $cancelBtn = $( '#ism-regen-all-cancel' );
			var $progress  = $( '#ism-regen-all-progress' );
			var $bar       = $( '#ism-regen-all-bar' );
			var $status    = $( '#ism-regen-all-status' );
			var $log       = $( '#ism-regen-all-log' );

			$startBtn.prop( 'disabled', true );
			$cancelBtn.prop( 'disabled', false ).text( 'Cancel' ).show();
			$progress.show();
			$bar.css( 'width', '0%' );
			$status.text( 'Collecting images…' );
			$log.hide().empty();

			$.post( ismData.ajaxUrl, {
				action: 'ism_regen_all_init',
				nonce:  ismData.bulkResizeNonce,
			}, function ( res ) {
				if ( ! res.success ) {
					$status.text( 'Error: ' + ( res.data || 'unknown' ) );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				var d = res.data;
				if ( d.total === 0 ) {
					$status.text( 'No images found in library.' );
					$startBtn.prop( 'disabled', false );
					$cancelBtn.hide();
					return;
				}
				var startOffset = Math.max( 0, parseInt( d.offset || 0, 10 ) );
				var startDeleted = Math.max( 0, parseInt( d.total_deleted || 0, 10 ) );
				var pct = d.total > 0 ? Math.round( ( startOffset / d.total ) * 100 ) : 0;
				var resumeMsg = d.resumed ? ' (resuming previous run)' : '';
				var deletedMsg = startDeleted > 0 ? ' — ' + startDeleted + ' file' + ( startDeleted === 1 ? '' : 's' ) + ' deleted' : '';
				$bar.css( 'width', pct + '%' );
				$status.text( startOffset + ' / ' + d.total + ' — ' + pct + '%' + deletedMsg + resumeMsg );
				bulkRunBatch( {
					batchAction:  'ism_regen_all_batch',
					transientKey: d.transient_key,
					offset:       startOffset,
					total:        d.total,
					$bar:         $bar,
					$status:      $status,
					$log:         $log,
					$cancelBtn:   $cancelBtn,
					abortFlag:    function () { return aborted; },
					onDone: function ( data ) {
						$startBtn.prop( 'disabled', false );
						$status.text( 'Done! ' + data.total + ' image(s) processed, ' + ( data.total_deleted || 0 ) + ' old file(s) deleted.' );
					},
				} );
			} ).fail( function () {
				$status.text( 'Init request failed.' );
				$startBtn.prop( 'disabled', false );
				$cancelBtn.hide();
			} );
		} );

		$( '#ism-regen-all-cancel' ).on( 'click', function () {
			aborted = true;
			$( this ).prop( 'disabled', true ).text( 'Cancelling…' );
		} );
	}() );

	// ── Image size usage scanner ─────────────────────────────────────────────
	( function () {
		var currentSizes = null;

		var statusLabels = {
			core:    { text: 'Core',    cls: 'ism-badge-core' },
			in_use:  { text: 'In Use',  cls: 'ism-badge-in-use' },
			plugin:  { text: 'Plugin',  cls: 'ism-badge-plugin' },
			unused:  { text: 'Unused',  cls: 'ism-badge-unused' },
		};

		$( '#ism-size-scan-start' ).on( 'click', function () {
			var $btn     = $( this );
			var $results = $( '#ism-size-scan-results' );
			var $tbody   = $( '#ism-scan-tbody' );
			var $summary = $( '#ism-scan-summary' );

			$btn.prop( 'disabled', true ).text( 'Scanning…' );
			$results.hide();
			$tbody.empty();
			currentSizes = null;

			$.post( ismData.ajaxUrl, {
				action: 'ism_size_usage_scan',
				nonce:  ismData.sizeUsageNonce,
			}, function ( res ) {
				$btn.prop( 'disabled', false ).text( 'Scan Now' );

				if ( ! res.success ) {
					$summary.text( 'Error: ' + ( res.data || 'Unknown error' ) );
					$results.show();
					return;
				}

				var d     = res.data;
				var sizes = d.sizes;
				var unused = 0;
				currentSizes = sizes;

				Object.keys( sizes ).forEach( function ( slug ) {
					var s        = sizes[ slug ];
					if ( s.status === 'unused' ) unused++;

					var label    = statusLabels[ s.status ] || { text: s.status, cls: '' };
					var dims     = ( s.width || '?' ) + ' × ' + ( s.height || '?' )
					             + ( s.crop ? ' (crop)' : '' );
					var note     = s.plugin_note ? ' <span class="ism-scan-plugin-note">(' + $( '<span>' ).text( s.plugin_note ).html() + ')</span>' : '';
					var safeSlug = $( '<span>' ).text( slug ).html();

					var $totalTd;
					if ( s.usages && s.usages.length ) {
						$totalTd = $( '<td>' ).html(
							'<button type="button" class="button-link ism-usage-expand" data-slug="' + safeSlug + '">' +
							'<strong>' + s.total + '</strong>&thinsp;<span class="ism-expand-arrow">&#9660;</span></button>'
						);
					} else {
						$totalTd = $( '<td>' ).html( '<strong>' + ( s.total || 0 ) + '</strong>' );
					}

					var $tr = $( '<tr>' ).append(
						$( '<td>' ).html( '<code>' + safeSlug + '</code>' ),
						$( '<td>' ).text( dims ),
						$( '<td>' ).html( '<span class="ism-badge ' + label.cls + '">' + label.text + '</span>' + note ),
						$( '<td>' ).text( s.post_content || 0 ),
						$( '<td>' ).text( s.elementor    || 0 ),
						$totalTd,
						$( '<td>' ).text( s.file_count   || 0 )
					);

					if ( s.status === 'unused' ) $tr.addClass( 'ism-row-unused' );
					$tbody.append( $tr );
				} );

				var Ep = d.elementor_pages > 0
					? ', ' + d.elementor_pages + ' Elementor page' + ( d.elementor_pages === 1 ? '' : 's' )
					: ' (Elementor not found or no Elementor pages)';
				$summary.text(
					'Scanned ' + d.posts_scanned + ' post' + ( d.posts_scanned === 1 ? '' : 's' )
					+ Ep + '. '
					+ unused + ' size' + ( unused === 1 ? '' : 's' ) + ' appear unused.'
				);

				$results.show();
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( 'Scan Now' );
				$summary.text( 'Request failed. Please reload and try again.' );
				$results.show();
			} );
		} );

		// Expand / collapse detail row showing which posts reference each size.
		$( document ).on( 'click', '.ism-usage-expand', function () {
			if ( ! currentSizes ) return;

			var $btn    = $( this );
			var slug    = $btn.data( 'slug' );
			var $row    = $btn.closest( 'tr' );
			var $detail = $row.next( '.ism-usage-detail-row' );

			if ( $detail.length ) {
				$detail.toggle();
				$btn.find( '.ism-expand-arrow' ).html( $detail.is( ':visible' ) ? '&#9650;' : '&#9660;' );
				return;
			}

			var sizeData = currentSizes[ slug ];
			if ( ! sizeData || ! sizeData.usages || ! sizeData.usages.length ) return;

			var html = '<ul class="ism-usage-list">';
			sizeData.usages.forEach( function ( u ) {
				var safeTitle = $( '<span>' ).text( u.title || '(no title)' ).html();
				var safeUrl   = u.url ? esc( u.url ) : '';
				var srcLabel  = u.source === 'elementor'
					? ' <span class="ism-source-label ism-source-elementor">Elementor</span>'
					: ' <span class="ism-source-label ism-source-content">Content</span>';
				if ( u.url ) {
					html += '<li><a href="' + safeUrl + '" target="_blank">' + safeTitle + '</a>' + srcLabel + '</li>';
				} else {
					html += '<li>' + safeTitle + srcLabel + '</li>';
				}
			} );
			html += '</ul>';

			$row.after(
				'<tr class="ism-usage-detail-row"><td colspan="7" class="ism-usage-detail-cell">' + html + '</td></tr>'
			);
			$btn.find( '.ism-expand-arrow' ).html( '&#9650;' );
		} );
	}() );

	// ── Vision image conversion (browser fallback) ──────────────────────────
	//
	// Some hosts cannot decode every image format the media library holds. AVIF
	// is the case that forced this: a container whose ImageMagick predates the
	// format and whose libgd was built without libavif can read none of it,
	// while reporting through gd_info() that it can. On such a host
	// ism_vision_source() returns ism_vision_client_decode and the work lands
	// here instead.
	//
	// Browsers decode AVIF natively, so a canvas round-trip converts to JPEG
	// with nothing required of the server. The result posts back and is
	// re-validated by ism_vision_accept_client_image() before it goes anywhere.

	// Keep in step with ISM_VISION_MAX_EDGE and ISM_VISION_JPEG_QUALITY.
	var VISION_MAX_EDGE = 1024;
	var VISION_QUALITY  = 0.82;

	// Whether this browser can do the conversion at all.
	function visionCanConvert() {
		var canvas = document.createElement( 'canvas' );
		return !! ( canvas.getContext && canvas.getContext( '2d' ) && canvas.toDataURL );
	}

	// Draw a loaded image onto a white canvas and read it back as JPEG.
	// Separate from the loading step so the retry below can reuse it.
	function visionCanvasToJpeg( img ) {
		var width  = img.naturalWidth  || img.width;
		var height = img.naturalHeight || img.height;

		if ( ! width || ! height ) {
			return null;
		}

		var scale = Math.min( 1, VISION_MAX_EDGE / Math.max( width, height ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width  = Math.round( width  * scale );
		canvas.height = Math.round( height * scale );

		var ctx = canvas.getContext( '2d' );

		// JPEG has no alpha, so anything transparent encodes as black unless it
		// is composited first. Matches the flatten-onto-white the PHP path does.
		ctx.fillStyle = '#ffffff';
		ctx.fillRect( 0, 0, canvas.width, canvas.height );
		ctx.drawImage( img, 0, 0, canvas.width, canvas.height );

		return canvas.toDataURL( 'image/jpeg', VISION_QUALITY );
	}

	/**
	 * Convert an image URL to a JPEG data URL.
	 *
	 * @param {string}   url      Same-origin uploads URL, from ism_vision_client_url().
	 * @param {Function} onDone   Called as onDone( dataUrl, errorMessage ).
	 */
	function visionConvert( url, onDone ) {
		if ( ! visionCanConvert() ) {
			onDone( null, 'This browser cannot convert images. Use a current Chrome, Firefox or Safari.' );
			return;
		}

		// Two passes. The first is plain, which is what same-origin uploads need
		// and what most sites will use. If the canvas comes back tainted the
		// uploads are being served from another origin, so the second pass asks
		// for CORS — which only helps if that origin sends the headers, but
		// costs one request to find out.
		var attemptedCors = false;

		function attempt() {
			var img = new Image();

			if ( attemptedCors ) {
				img.crossOrigin = 'anonymous';
			}

			img.onload = function () {
				var dataUrl;

				try {
					dataUrl = visionCanvasToJpeg( img );
				} catch ( e ) {
					// SecurityError: cross-origin image tainted the canvas.
					if ( ! attemptedCors ) {
						attemptedCors = true;
						attempt();
						return;
					}
					onDone( null, 'Images are served from another domain without CORS headers, so the browser cannot convert them.' );
					return;
				}

				if ( ! dataUrl ) {
					onDone( null, 'Image loaded with no dimensions.' );
					return;
				}

				onDone( dataUrl, null );
			};

			img.onerror = function () {
				if ( ! attemptedCors ) {
					attemptedCors = true;
					attempt();
					return;
				}
				onDone( null, 'Could not load the image. The file may be missing, or the format unsupported by this browser.' );
			};

			// Cache-bust nothing: a warm cache is desirable across a long run.
			img.src = url;
		}

		attempt();
	}

	// Exposed so the Image SEO tab can call it, and so a single conversion can
	// be tried from the console when tuning against one known image.
	window.ismVision = {
		convert:    visionConvert,
		canConvert: visionCanConvert,
		maxEdge:    VISION_MAX_EDGE,
		quality:    VISION_QUALITY
	};

	// ── Image SEO ───────────────────────────────────────────────────────────
	//
	// The browser drives generation one image at a time. That is required, not
	// stylistic: an image this host cannot decode — and any SVG — is rasterised
	// here via canvas and posted back in the same request, so the client has to
	// be in the loop. It also paces requests and keeps progress honest.
	//
	// Rows are grouped by content hash. Two attachments that are byte-identical
	// are one row, one generation, and one apply that writes to every copy.
	//
	// Nothing reaches the media library except through Apply, over ticked rows,
	// using whatever is in the boxes at that moment.

	var seoRows      = [];   // every scanned row, one per attachment
	var seoGroups    = [];   // rows folded by hash
	var seoProposals = {};   // attachment id -> { title, alt_text, description, error }
	var seoChecked   = {};   // group key -> true
	var seoEdits     = {};   // group key -> { title, alt_text, description }
	var seoAborted   = false;
	var seoHasKey    = false;
	var seoPage      = 1;
	var SEO_PER_PAGE = 50;

	function seoPost( action, data, done, fail ) {
		data = $.extend( { action: action, nonce: ismData.seoNonce }, data || {} );
		return $.post( ismData.ajaxUrl, data, done ).fail( fail || function () {} );
	}

	// Transport and server-side hiccups are worth another attempt. A refusal, a
	// missing key, or a skipped attachment will fail identically forever.
	function seoIsRetryable( code ) {
		if ( ! code ) { return true; }
		if ( code === 'ism_ai_transport' ) { return true; }
		return /^ism_ai_http_(429|5\d\d)$/.test( code );
	}

	// ── Usage index ─────────────────────────────────────────────────────────

	function seoIndexRun( offset ) {
		$.post( ismData.ajaxUrl, {
			action: 'ism_usage_index_batch',
			nonce:  ismData.usageIndexNonce,
			offset: offset
		}, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-usage-index-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-usage-index-start' ).prop( 'disabled', false );
				return;
			}
			var d = res.data;
			$( '#ism-usage-index-bar' ).css( 'width', ( d.total ? Math.round( ( d.offset / d.total ) * 100 ) : 100 ) + '%' );
			$( '#ism-usage-index-status' ).text( d.offset + ' / ' + d.total + ' posts scanned, ' + d.found + ' images found' );
			if ( d.done ) {
				$( '#ism-usage-index-status' ).text( 'Done. ' + d.total + ' posts, ' + d.found + ' images indexed. Now scan the library.' );
				$( '#ism-usage-index-start' ).prop( 'disabled', false );
				return;
			}
			setTimeout( function () { seoIndexRun( d.offset ); }, 100 );
		} ).fail( function () {
			$( '#ism-usage-index-status' ).text( 'Request failed. Reload and try again.' );
			$( '#ism-usage-index-start' ).prop( 'disabled', false );
		} );
	}

	$( document ).on( 'click', '#ism-usage-index-start', function () {
		$( this ).prop( 'disabled', true );
		$( '#ism-usage-index-progress' ).show();
		$( '#ism-usage-index-bar' ).css( 'width', '0%' );
		$( '#ism-usage-index-status' ).text( 'Starting…' );
		$.post( ismData.ajaxUrl, {
			action: 'ism_usage_index_init', nonce: ismData.usageIndexNonce, force_restart: 1
		}, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-usage-index-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-usage-index-start' ).prop( 'disabled', false );
				return;
			}
			seoIndexRun( res.data.offset );
		} );
	} );

	// ── Scan: hash, then classify ───────────────────────────────────────────

	function seoHashRun( offset, onDone ) {
		seoPost( 'ism_hash_batch', { offset: offset }, function ( res ) {
			if ( ! res.success ) { onDone(); return; }
			var d = res.data;
			$( '#ism-seo-scan-bar' ).css( 'width', ( d.total ? Math.round( ( d.offset / d.total ) * 50 ) : 50 ) + '%' );
			$( '#ism-seo-scan-status' ).text( 'Fingerprinting files for duplicate detection — ' + d.offset + ' / ' + d.total );
			if ( d.done ) { onDone( d.duplicates ); return; }
			setTimeout( function () { seoHashRun( d.offset, onDone ); }, 40 );
		}, onDone );
	}

	function seoScanRun( offset, cached ) {
		seoPost( 'ism_seo_scan_batch', { offset: offset, cached: cached ? 1 : 0 }, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-seo-scan-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-seo-scan-start' ).prop( 'disabled', false );
				return;
			}
			var d = res.data;
			seoRows = seoRows.concat( d.rows );
			$( '#ism-seo-scan-bar' ).css( 'width', ( cached ? 0 : 50 ) + ( d.total ? Math.round( ( d.offset / d.total ) * ( cached ? 100 : 50 ) ) : 50 ) + '%' );
			$( '#ism-seo-scan-status' ).text( d.offset + ' / ' + d.total + ( cached ? ' images loaded' : ' images classified' ) );
			if ( d.done ) {
				$( '#ism-seo-scan-status' ).text( ( cached ? 'Loaded ' : 'Scan complete — ' ) + d.total + ' images.' );
				$( '#ism-seo-scan-start' ).prop( 'disabled', false );
				$( '#ism-seo-rescan' ).prop( 'disabled', false );
				seoPage = 1;
				seoBuildGroups();

				// A reload with work still pending should open on that work.
				if ( Object.keys( seoProposals ).length ) {
					$( '#ism-seo-proposal-filter' ).val( 'proposals' );
					$( '#ism-seo-review-filter' ).val( 'all' );
				}
				seoRenderSummary();
				seoRenderChart();
				seoRenderReview();
				return;
			}
			setTimeout( function () { seoScanRun( d.offset, cached ); }, cached ? 10 : 50 );
		}, function () {
			$( '#ism-seo-scan-status' ).text( 'Request failed. Reload and try again.' );
			$( '#ism-seo-scan-start' ).prop( 'disabled', false );
			$( '#ism-seo-rescan' ).prop( 'disabled', false );
		} );
	}

	// force = true rebuilds the classification from scratch. Otherwise a stored
	// scan is loaded straight back, because classifying 800 images is work whose
	// answer does not change between page loads.
	function seoStartScan( force ) {
		seoRows = [];
		seoGroups = [];
		$( '#ism-seo-scan-start, #ism-seo-rescan' ).prop( 'disabled', true );
		$( '#ism-seo-scan-progress' ).show();
		$( '#ism-seo-scan-bar' ).css( 'width', '0%' );
		$( '#ism-seo-scan-status' ).text( force ? 'Rescanning…' : 'Loading…' );

		seoPost( 'ism_seo_scan_init', { force: force ? 1 : 0 }, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-seo-scan-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-seo-scan-start, #ism-seo-rescan' ).prop( 'disabled', false );
				return;
			}
			// Proposals generated earlier survive a reload, so this restores them
			// rather than discarding work that has already been paid for.
			seoProposals = res.data.results || {};
			seoHasKey    = !! res.data.has_key;
			seoSyncWeights();

			if ( ! res.data.index_built ) {
				$( '#ism-seo-scan-status' ).text( 'The usage index has not been built — every image will look unused. Build it first.' );
			}

			if ( res.data.cached ) {
				$( '.ism-seo-scan-state' ).text( 'Using the scan from ' + res.data.scanned_ago + ' ago. Rescan after uploading or deleting images.' );
				seoScanRun( 0, true );
				return;
			}

			$( '.ism-seo-scan-state' ).text( 'No stored scan — classifying the library now.' );
			seoHashRun( 0, function () { seoScanRun( 0, false ); } );
		} );
	}

	$( document ).on( 'click', '#ism-seo-scan-start', function () { seoStartScan( false ); } );
	$( document ).on( 'click', '#ism-seo-rescan', function () {
		if ( ! window.confirm( 'Rescan the whole library? This re-reads every file and takes a minute or so. Your proposals and reviewed marks are kept.' ) ) { return; }
		seoStartScan( true );
	} );

	// ── Grouping ────────────────────────────────────────────────────────────

	// A group is one picture. Byte-identical attachments collapse into a single
	// row so the generator runs once and Apply writes to every copy; anything
	// without a hash stands alone under its own id.
	function seoBuildGroups() {
		var byKey = {};

		seoRows.forEach( function ( r ) {
			var key = r.hash ? 'h:' + r.hash : 'i:' + r.id;
			if ( ! byKey[ key ] ) {
				byKey[ key ] = { key: key, ids: [], rows: [], places: [], group: r.group, needs_client: false };
			}
			var g = byKey[ key ];
			g.ids.push( r.id );
			g.rows.push( r );
			g.places = g.places.concat( r.places || [] );
			if ( r.needs_client ) { g.needs_client = true; }
		} );

		seoGroups = Object.keys( byKey ).map( function ( k ) {
			var g = byKey[ k ];

			// Represent the group with the copy carrying the most metadata —
			// usually the one editors have actually curated. Ties keep the
			// lowest id, which is the oldest upload.
			g.rows.sort( function ( a, b ) {
				var sa = ( a.current.title ? 1 : 0 ) + ( a.current.alt_text ? 1 : 0 ) + ( a.current.description ? 1 : 0 );
				var sb = ( b.current.title ? 1 : 0 ) + ( b.current.alt_text ? 1 : 0 ) + ( b.current.description ? 1 : 0 );
				return sb - sa || a.id - b.id;
			} );

			g.rep    = g.rows[ 0 ];
			g.copies = g.ids.length;

			// Reviewed only counts when every copy is marked, so a duplicate
			// group cannot disappear from the chart while half of it is unchecked.
			g.reviewed = g.rows.every( function ( r ) { return r.reviewed; } );
			g.missing  = g.rep.missing || [];

			// A group is only "ready" if some copy is; a duplicate that happens
			// to be referenced nowhere should not drag the whole picture into
			// the unused bucket.
			var groups = g.rows.map( function ( r ) { return r.group; } );
			g.group = groups.indexOf( 'ready' ) > -1 ? 'ready'
				: ( groups.indexOf( 'unused' ) > -1 ? 'unused' : 'skipped' );

			return g;
		} );

		seoGroups.sort( function ( a, b ) { return a.rep.id - b.rep.id; } );
	}

	// A proposal that is still awaiting a decision. Applying and discarding both
	// remove it from seoProposals, so "generated but not yet applied" needs no
	// extra bookkeeping — a live proposal with no error is exactly that.
	function seoHasOpenProposal( g ) {
		var p = seoGroupProposal( g );
		return !! ( p && ! p.error );
	}

	// Mirrors the server's ism_seo_row() so the two agree about what "missing"
	// means. Kept here as well because after an apply the browser knows what it
	// wrote and should not need a rescan to reflect it.
	function seoMissingFields( current ) {
		var out = [];
		[ 'title', 'alt_text', 'description' ].forEach( function ( f ) {
			if ( ! ( current[ f ] || '' ).toString().trim() ) { out.push( f ); }
		} );
		return out;
	}

	function seoGroupProposal( g ) {
		for ( var i = 0; i < g.ids.length; i++ ) {
			if ( seoProposals[ String( g.ids[ i ] ) ] ) { return seoProposals[ String( g.ids[ i ] ) ]; }
		}
		return null;
	}

	// What is currently in this group's boxes: an edit if one was made,
	// otherwise the proposal, otherwise what is already on the attachment.
	function seoGroupValues( g ) {
		if ( seoEdits[ g.key ] ) { return seoEdits[ g.key ]; }
		var p = seoGroupProposal( g );
		if ( p && ! p.error ) {
			return { title: p.title, alt_text: p.alt_text, description: p.description };
		}
		return {
			title:       g.rep.current.title,
			alt_text:    g.rep.current.alt_text,
			description: g.rep.current.description
		};
	}

	// ── Summary ─────────────────────────────────────────────────────────────

	function seoRenderSummary() {
		if ( ! seoGroups.length ) {
			$( '#ism-seo-summary' ).hide();
			return;
		}

		var ready = 0, skipped = 0, unused = 0, dupes = 0, client = 0, noalt = 0, open = 0;
		seoGroups.forEach( function ( g ) {
			if ( g.group === 'ready' ) { ready++; } else if ( g.group === 'unused' ) { unused++; } else { skipped++; }
			if ( g.copies > 1 ) { dupes++; }
			if ( g.needs_client ) { client++; }
			if ( g.group === 'ready' && ! g.rep.current.alt_text ) { noalt++; }
			if ( seoHasOpenProposal( g ) ) { open++; }
		} );

		var html = '<ul class="ism-seo-stats">'
			+ '<li><strong>' + seoGroups.length + '</strong> distinct images</li>'
			+ '<li><strong>' + ready + '</strong> ready to generate</li>'
			+ '<li><strong>' + noalt + '</strong> missing alt text</li>'
			+ '<li><strong>' + skipped + '</strong> decorative / unsupported</li>'
			+ '<li><strong>' + unused + '</strong> on no page</li>'
			+ '<li><strong>' + dupes + '</strong> duplicate groups</li>'
			+ ( open ? '<li class="ism-stat-open"><strong>' + open + '</strong> awaiting apply</li>' : '' )
			+ '</ul>';

		if ( seoRows.length > seoGroups.length ) {
			html += '<p class="description">' + ( seoRows.length - seoGroups.length )
				+ ' attachment(s) are byte-identical copies of another image. They are folded into one row each, so you generate once and Apply writes to every copy.</p>';
		}
		if ( client > 0 ) {
			html += '<p class="description">' + client + ' image(s) will be converted in the browser — this server cannot read their format.</p>';
		}

		$( '#ism-seo-summary' ).html( html ).show();
		$( '#ism-seo-chart-card' ).show();
		$( '#ism-seo-dupe-card' ).toggle( dupes > 0 );
		$( '#ism-seo-generate-card' ).show();
	}

	// ── Chart ───────────────────────────────────────────────────────────────

	// Three independent axes rather than one dropdown mixing them. Which
	// population of images you are looking at, whether you have already dealt
	// with them, and whether they currently carry a proposal are separate
	// questions, and folding them together is what made the old list confusing.
	function seoVisibleGroups() {
		var pop      = $( '#ism-seo-filter' ).val() || 'usable';
		var review   = $( '#ism-seo-review-filter' ).val() || 'unreviewed';
		var proposal = $( '#ism-seo-proposal-filter' ).val() || 'all';
		var metadata = $( '#ism-seo-metadata-filter' ).val() || 'all';
		var term     = ( $( '#ism-seo-search' ).val() || '' ).toLowerCase();

		return seoGroups.filter( function ( g ) {
			// "On the site" means describable and actually referenced — the two
			// conditions that make generation possible at all.
			if ( pop === 'usable'  && g.group !== 'ready' ) { return false; }
			if ( pop === 'skipped' && g.group !== 'skipped' ) { return false; }
			if ( pop === 'unused'  && g.group !== 'unused' ) { return false; }

			if ( review === 'unreviewed' && g.reviewed ) { return false; }
			if ( review === 'reviewed'   && ! g.reviewed ) { return false; }

			if ( proposal === 'proposals' && ! seoHasOpenProposal( g ) ) { return false; }

			// What is actually empty on the image today. Title is deliberately
			// not offered: WordPress fills one in from the filename on every
			// upload, so "missing title" is empty on every normal site and
			// would read as a broken filter rather than a finished job.
			if ( metadata === 'any'      && ! g.missing.length ) { return false; }
			if ( metadata === 'complete' && g.missing.length ) { return false; }
			if ( ( metadata === 'alt_text' || metadata === 'description' )
				&& g.missing.indexOf( metadata ) === -1 ) { return false; }

			if ( term ) {
				var hay = g.rep.filename.toLowerCase() + ' '
					+ g.places.map( function ( p ) { return p.title; } ).join( ' ' ).toLowerCase();
				if ( hay.indexOf( term ) === -1 ) { return false; }
			}
			return true;
		} );
	}

	function seoPlacesHtml( g ) {
		if ( ! g.places.length ) {
			return '<span class="ism-seo-noplace">Not referenced by any page, template or custom field</span>';
		}
		// Duplicate copies can reference the same page twice; show it once.
		var seen = {};
		var out  = [];
		g.places.forEach( function ( p ) {
			var k = p.title + '|' + p.source;
			if ( seen[ k ] ) { return; }
			seen[ k ] = true;
			var label = esc( p.title || '(no title)' );
			var cls   = p.featured ? 'ism-seo-place ism-seo-place-featured' : 'ism-seo-place';
			if ( ! p.link ) { cls += ' ism-seo-place-nolink'; }
			var tip   = p.reason || ( p.featured ? 'featured image' : p.source );
			var inner = p.link ? '<a href="' + esc( p.link ) + '" target="_blank">' + label + '</a>' : label;
			out.push( '<span class="' + cls + '" title="' + esc( tip ) + '">' + inner
				+ '<em>' + esc( p.type ) + ( p.featured ? ' · featured' : '' )
				+ ( p.edit ? ' · <a href="' + esc( p.edit ) + '" target="_blank">edit</a>' : '' )
				+ ( p.link ? '' : ' · not linkable' ) + '</em></span>' );
		} );
		return out.join( '' );
	}

	function seoBadges( g ) {
		var b = '';
		if ( g.copies > 1 ) { b += '<span class="ism-badge ism-badge-dupe">×' + g.copies + ' copies</span>'; }
		if ( g.group === 'skipped' ) {
			var why = g.rep.skip_reason === 'svg' ? 'SVG' : ( g.rep.skip_reason === 'icon_size' ? 'icon' : 'unsupported' );
			b += '<span class="ism-badge ism-badge-skip">' + esc( why ) + '</span>';
		}
		if ( g.group === 'unused' ) { b += '<span class="ism-badge ism-badge-unused">no page</span>'; }
		if ( g.needs_client ) { b += '<span class="ism-badge ism-badge-client">browser convert</span>'; }
		if ( g.missing.length ) {
			b += '<span class="ism-badge ism-badge-noalt">missing ' + esc( g.missing.map( function ( f ) {
				return f === 'alt_text' ? 'alt' : f;
			} ).join( ', ' ) ) + '</span>';
		}
		if ( g.reviewed ) { b += '<span class="ism-badge ism-badge-reviewed">reviewed</span>'; }
		var p = seoGroupProposal( g );
		if ( p && ! p.error ) { b += '<span class="ism-badge ism-badge-proposed">proposed</span>'; }
		if ( p && p.error )   { b += '<span class="ism-badge ism-badge-failed">failed</span>'; }
		return b;
	}

	function seoRowHtml( g ) {
		var v = seoGroupValues( g );
		var p = seoGroupProposal( g );

		var idList = g.ids.map( function ( i ) { return '#' + i; } ).join( ' ' );

		return '<div class="ism-seo-row" data-key="' + esc( g.key ) + '">'
			+ '<div class="ism-seo-row-head">'
			+ '<label class="ism-seo-check"><input type="checkbox" class="ism-seo-tick" data-key="' + esc( g.key ) + '"'
				+ ( seoChecked[ g.key ] ? ' checked' : '' ) + ' /></label>'
			+ ( g.rep.thumb ? '<img src="' + esc( g.rep.thumb ) + '" alt="" width="56" height="56" loading="lazy" />' : '<span class="ism-seo-nothumb"></span>' )
			+ '<div class="ism-seo-row-meta">'
			+ '<div class="ism-seo-row-name">'
			+ ( g.rep.edit_url ? '<a href="' + esc( g.rep.edit_url ) + '" target="_blank">' + esc( g.rep.filename ) + '</a>' : esc( g.rep.filename ) )
			+ ' <span class="ism-seo-ids">' + esc( idList ) + '</span>'
			+ '</div>'
			+ '<div class="ism-seo-badges">' + seoBadges( g ) + '</div>'
			+ ( seoHasOpenProposal( g )
				? '<div class="ism-seo-proposal-actions">'
					+ '<button type="button" class="button button-small ism-seo-reject" data-key="' + esc( g.key ) + '">Reject proposal</button>'
					+ '<span class="description">Drops the suggestion. Does not mark the image reviewed.</span>'
					+ '</div>'
				: '' )
			+ '<div class="ism-seo-places">' + seoPlacesHtml( g ) + '</div>'
			+ '</div>'
			+ '<div class="ism-seo-row-actions">'
			+ ( g.group !== 'ready'
				? '<button type="button" class="button button-small ism-seo-override" data-key="' + esc( g.key ) + '">Generate anyway</button>'
				: '' )
			+ '<button type="button" class="button button-small ism-seo-review" data-key="' + esc( g.key ) + '">'
			+ ( g.reviewed ? 'Un-review' : 'Reviewed' ) + '</button>'
			+ '</div>'
			+ '</div>'
			+ ( p && p.error ? '<div class="ism-seo-error">' + esc( p.error ) + '</div>' : '' )
			+ '<div class="ism-seo-fields">'
			+ seoFieldHtml( g, 'title', 'Title', v.title, g.rep.current.title )
			+ seoFieldHtml( g, 'alt_text', 'Alt text', v.alt_text, g.rep.current.alt_text )
			+ seoFieldHtml( g, 'description', 'Description', v.description, g.rep.current.description )
			+ '</div></div>';
	}

	function seoFieldHtml( g, key, label, value, current ) {
		var changed = value !== current;
		var input = key === 'description'
			? '<textarea rows="2" class="large-text ism-seo-input" data-key="' + esc( g.key ) + '" data-field="' + key + '">' + esc( value ) + '</textarea>'
			: '<input type="text" class="large-text ism-seo-input" data-key="' + esc( g.key ) + '" data-field="' + key + '" value="' + esc( value ) + '" />';

		var was = changed
			? ( current ? '<span class="ism-seo-was">currently: ' + esc( current ) + '</span>'
			            : '<span class="ism-seo-was ism-seo-was-empty">currently empty</span>' )
			: '';

		return '<div class="ism-seo-field' + ( changed ? ' ism-seo-field-changed' : '' ) + '">'
			+ '<label>' + esc( label ) + '</label>' + input + was + '</div>';
	}

	function seoRenderChart() {
		// Redrawn alongside the chart rather than only after a scan: the
		// awaiting-apply count moves every time something is generated, applied
		// or discarded, and a stale headline is worse than none.
		seoRenderSummary();

		var vis = seoVisibleGroups();
		var checked = 0;
		seoGroups.forEach( function ( g ) { if ( seoChecked[ g.key ] ) { checked++; } } );

		$( '.ism-seo-chart-count' ).text( vis.length + ' shown · ' + checked + ' selected' );
		$( '.ism-seo-selected-count' ).text( checked ? '(' + checked + ' selected)' : '(none selected yet)' );
		seoSyncSelectAllButton();
		seoRenderEstimate();

		if ( ! vis.length ) {
			$( '#ism-seo-chart' ).html( '<p class="description">Nothing matches this filter.</p>' );
			return;
		}

		// Paged rather than capped: a 700-row chart with three inputs each is
		// unusable, but silently hiding the tail is worse.
		var pages = Math.max( 1, Math.ceil( vis.length / SEO_PER_PAGE ) );
		if ( seoPage > pages ) { seoPage = pages; }

		var start = ( seoPage - 1 ) * SEO_PER_PAGE;
		var slice = vis.slice( start, start + SEO_PER_PAGE );

		$( '#ism-seo-chart' ).html( slice.map( seoRowHtml ).join( '' ) );
		seoRenderPager( vis.length, pages, start, slice.length );
	}

	function seoRenderPager( total, pages, start, shown ) {
		if ( pages < 2 ) {
			$( '.ism-seo-pager' ).empty();
			return;
		}

		var html = '<span class="ism-seo-pager-info">'
			+ ( start + 1 ) + '–' + ( start + shown ) + ' of ' + total + '</span>'
			+ '<button type="button" class="button ism-seo-page-first"' + ( seoPage === 1 ? ' disabled' : '' ) + '>«</button>'
			+ '<button type="button" class="button ism-seo-page-prev"' + ( seoPage === 1 ? ' disabled' : '' ) + '>‹ Prev</button>'
			+ '<span class="ism-seo-pager-pos">Page <input type="number" class="small-text ism-seo-page-input" value="' + seoPage
			+ '" min="1" max="' + pages + '" /> of ' + pages + '</span>'
			+ '<button type="button" class="button ism-seo-page-next"' + ( seoPage === pages ? ' disabled' : '' ) + '>Next ›</button>'
			+ '<button type="button" class="button ism-seo-page-last"' + ( seoPage === pages ? ' disabled' : '' ) + '>»</button>';

		$( '.ism-seo-pager' ).html( html );
	}

	function seoGoToPage( n ) {
		var pages = Math.max( 1, Math.ceil( seoVisibleGroups().length / SEO_PER_PAGE ) );
		seoPage = Math.min( pages, Math.max( 1, n ) );
		seoRenderChart();
		var top = $( '#ism-seo-chart-card' ).offset();
		if ( top ) { $( 'html, body' ).animate( { scrollTop: top.top - 40 }, 150 ); }
	}

	$( document ).on( 'click', '.ism-seo-page-first', function () { seoGoToPage( 1 ); } );
	$( document ).on( 'click', '.ism-seo-page-prev',  function () { seoGoToPage( seoPage - 1 ); } );
	$( document ).on( 'click', '.ism-seo-page-next',  function () { seoGoToPage( seoPage + 1 ); } );
	$( document ).on( 'click', '.ism-seo-page-last',  function () {
		seoGoToPage( Math.ceil( seoVisibleGroups().length / SEO_PER_PAGE ) );
	} );
	$( document ).on( 'change', '.ism-seo-page-input', function () {
		seoGoToPage( parseInt( $( this ).val(), 10 ) || 1 );
	} );

	// Reviewed is stored on the attachment, so it survives a rescan and a reload.
	$( document ).on( 'click', '.ism-seo-review', function () {
		var g = seoGroupByKey( String( $( this ).data( 'key' ) ) );
		if ( ! g ) { return; }

		var next = ! g.reviewed;
		var $btn = $( this ).prop( 'disabled', true );

		seoPost( 'ism_seo_review', { ids: g.ids, reviewed: next ? 1 : 0 }, function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! res.success ) { return; }
			g.reviewed = next;
			g.rows.forEach( function ( r ) { r.reviewed = next; } );
			seoRenderChart();
		}, function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'change', '#ism-seo-filter, #ism-seo-review-filter, #ism-seo-proposal-filter, #ism-seo-metadata-filter', function () {
		seoPage = 1;
		seoRenderChart();
	} );
	$( document ).on( 'input', '#ism-seo-search', function () {
		clearTimeout( window.ismSeoSearchTimer );
		window.ismSeoSearchTimer = setTimeout( function () { seoPage = 1; seoRenderChart(); }, 250 );
	} );

	$( document ).on( 'change', '.ism-seo-tick', function () {
		var k = String( $( this ).data( 'key' ) );
		if ( $( this ).is( ':checked' ) ) { seoChecked[ k ] = true; } else { delete seoChecked[ k ]; }
		var checked = Object.keys( seoChecked ).length;
		$( '.ism-seo-chart-count' ).text( seoVisibleGroups().length + ' shown · ' + checked + ' selected' );
		$( '.ism-seo-selected-count' ).text( checked ? '(' + checked + ' selected)' : '(none selected yet)' );
		seoSyncSelectAllButton();
		seoRenderEstimate();
	} );

	// One button that reflects what it will do next, rather than two that both
	// stay clickable when only one of them is meaningful.
	$( document ).on( 'click', '#ism-seo-check-all', function () {
		var $btn = $( this );

		if ( $btn.data( 'mode' ) === 'select' ) {
			seoVisibleGroups().forEach( function ( g ) { seoChecked[ g.key ] = true; } );
		} else {
			seoVisibleGroups().forEach( function ( g ) { delete seoChecked[ g.key ]; } );
		}

		seoRenderChart();
	} );

	function seoSyncSelectAllButton() {
		var vis = seoVisibleGroups();
		var all = vis.length > 0 && vis.every( function ( g ) { return seoChecked[ g.key ]; } );

		$( '#ism-seo-check-all' )
			.data( 'mode', all ? 'unselect' : 'select' )
			.text( all ? 'Unselect all' : 'Select all' );
	}

	// Rejecting is not reviewing: the suggestion was wrong, so the image drops
	// out of the proposals view and goes back to needing a decision. Marking it
	// reviewed stays a separate, deliberate act.
	$( document ).on( 'click', '.ism-seo-reject', function () {
		var g = seoGroupByKey( String( $( this ).data( 'key' ) ) );
		if ( ! g ) { return; }

		var $btn = $( this ).prop( 'disabled', true ).text( 'Rejecting…' );

		seoPost( 'ism_seo_reject', { ids: g.ids }, function ( res ) {
			if ( ! res.success ) {
				$btn.prop( 'disabled', false ).text( 'Reject proposal' );
				return;
			}
			g.ids.forEach( function ( id ) { delete seoProposals[ String( id ) ]; } );
			delete seoEdits[ g.key ];
			delete seoChecked[ g.key ];
			seoRenderChart();
			seoRenderReview();
		}, function () {
			$btn.prop( 'disabled', false ).text( 'Reject proposal' );
		} );
	} );

	// Edits are held per group so a re-render never loses typing.
	$( document ).on( 'input change', '.ism-seo-input', function () {
		var $f = $( this );
		var k  = String( $f.data( 'key' ) );
		var g  = seoGroupByKey( k );
		if ( ! g ) { return; }
		if ( ! seoEdits[ k ] ) { seoEdits[ k ] = seoGroupValues( g ); }
		seoEdits[ k ][ $f.data( 'field' ) ] = $f.val();
	} );

	function seoGroupByKey( key ) {
		for ( var i = 0; i < seoGroups.length; i++ ) {
			if ( seoGroups[ i ].key === key ) { return seoGroups[ i ]; }
		}
		return null;
	}

	// ── Override ────────────────────────────────────────────────────────────

	$( document ).on( 'click', '.ism-seo-override', function () {
		var g = seoGroupByKey( String( $( this ).data( 'key' ) ) );
		if ( ! g ) { return; }
		if ( ! seoHasKey ) {
			window.alert( 'Add an API key and save before generating.' );
			return;
		}
		var what = g.rep.skip_reason === 'svg'
			? 'This SVG will be rendered to a bitmap in your browser and sent for description. Decorative marks are usually better with short or empty alt text — generate anyway?'
			: ( g.group === 'unused'
				? 'This image was not found on any page, so there is no page context to work from and the output will be based on the picture alone. Generate anyway?'
				: 'This format is not normally described. Generate anyway?' );
		if ( ! window.confirm( what ) ) { return; }

		var $btn = $( this ).prop( 'disabled', true ).text( 'Generating…' );
		seoGenerateOne( g, 0, true, function ( err ) {
			$btn.prop( 'disabled', false ).text( 'Generate anyway' );
			if ( err ) { window.alert( 'Generation failed: ' + err ); }
			seoRenderChart();
			seoRenderReview();
		} );
	} );

	// ── Generate ────────────────────────────────────────────────────────────

	function seoGenerateOne( g, retries, override, onDone ) {
		retries = retries || 0;
		var id  = g.rep.id;

		function send( dataUrl ) {
			seoPost( 'ism_seo_generate', {
				attachment_id:  id,
				image_data_url: dataUrl || '',
				override_skip:  override ? 1 : 0,
				weights:        seoWeights(),
				extra_prompt:   $( '#ism-ai-extra-prompt' ).val() || ''
			}, function ( res ) {
				if ( res.success ) {
					seoProposals[ String( id ) ] = res.data.proposal;
					delete seoEdits[ g.key ];
					onDone( null, res.data );
					return;
				}
				var d = res.data || {};
				if ( seoIsRetryable( d.code ) && retries < 3 ) {
					setTimeout( function () { seoGenerateOne( g, retries + 1, override, onDone ); }, Math.pow( 2, retries + 1 ) * 1000 );
					return;
				}
				seoProposals[ String( id ) ] = { title: '', alt_text: '', description: '', error: d.message || 'Generation failed' };
				onDone( d.message || 'failed', null );
			}, function () {
				if ( retries < 3 ) {
					setTimeout( function () { seoGenerateOne( g, retries + 1, override, onDone ); }, Math.pow( 2, retries + 1 ) * 1000 );
					return;
				}
				seoProposals[ String( id ) ] = { title: '', alt_text: '', description: '', error: 'Connection lost' };
				onDone( 'connection lost', null );
			} );
		}

		if ( g.needs_client && g.rep.client_url ) {
			window.ismVision.convert( g.rep.client_url, function ( dataUrl, err ) {
				if ( err ) {
					seoProposals[ String( id ) ] = { title: '', alt_text: '', description: '', error: err };
					onDone( err, null );
					return;
				}
				send( dataUrl );
			} );
			return;
		}
		send( '' );
	}

	function seoGenerateLoop( queue, index, stats ) {
		if ( seoAborted || index >= queue.length ) {
			$( '#ism-seo-generate-start' ).prop( 'disabled', false );
			$( '#ism-seo-generate-cancel' ).hide();
			$( '#ism-seo-generate-status' ).text(
				( seoAborted ? 'Stopped at ' : 'Done — ' ) + index + ' / ' + queue.length
				+ ' · ' + stats.ok + ' generated, ' + stats.failed + ' failed'
				+ ( stats.tokens ? ' · ' + stats.tokens.toLocaleString() + ' tokens' : '' )
			);
			// Finishing a run means the proposals are the thing to look at, so
			// the view switches to them instead of leaving them buried behind
			// whatever filter was set before.
			if ( ! seoAborted && stats.ok > 0 ) {
				$( '#ism-seo-proposal-filter' ).val( 'proposals' );
				$( '#ism-seo-review-filter' ).val( 'all' );
				seoPage = 1;
			}

			seoRenderChart();
			seoRenderReview();
			return;
		}

		var g = queue[ index ];
		$( '#ism-seo-generate-bar' ).css( 'width', Math.round( ( index / queue.length ) * 100 ) + '%' );
		$( '#ism-seo-generate-status' ).text(
			( index + 1 ) + ' / ' + queue.length + ' — ' + g.rep.filename
			+ ( g.needs_client ? ' (converting in browser)' : '' )
			+ ( g.copies > 1 ? ' [×' + g.copies + ']' : '' )
		);

		seoGenerateOne( g, 0, g.group !== 'ready', function ( err, data ) {
			var $log = $( '#ism-seo-generate-log' ).show();
			if ( err ) {
				stats.failed++;
				$log.append( $( '<li>' ).addClass( 'ism-log-error' ).text( g.rep.filename + ' — ' + err ) );
			} else {
				stats.ok++;
				if ( data && data.run_usage ) { stats.tokens = data.run_usage.total_tokens || stats.tokens; }
				$log.append( $( '<li>' ).text( g.rep.filename + ' ✓' ) );
			}
			$log.scrollTop( $log[ 0 ].scrollHeight );
			if ( ( index + 1 ) % 5 === 0 ) { seoRenderChart(); }
			setTimeout( function () { seoGenerateLoop( queue, index + 1, stats ); }, 250 );
		} );
	}

	$( document ).on( 'click', '#ism-seo-generate-start', function () {
		if ( ! seoWeightsValid() ) {
			window.alert( 'Source weights total ' + seoWeightTotal() + '%. They must total 100% before generating.' );
			return;
		}
		var mode  = $( 'input[name="ism_seo_mode"]:checked' ).val();
		var queue;

		if ( mode === 'selected' ) {
			queue = seoGroups.filter( function ( g ) { return seoChecked[ g.key ]; } );
		} else {
			var limit = Math.max( 1, parseInt( $( '#ism-seo-limit' ).val(), 10 ) || 20 );
			queue = seoGroups.filter( function ( g ) {
				return g.group === 'ready' && ! seoGroupProposal( g );
			} ).slice( 0, limit );
		}

		if ( ! queue.length ) {
			$( '#ism-seo-generate-progress' ).show();
			$( '#ism-seo-generate-status' ).text( mode === 'selected'
				? 'Nothing ticked in the chart.'
				: 'Every ready image already has a proposal.' );
			return;
		}

		var overrides = queue.filter( function ( g ) { return g.group !== 'ready'; } ).length;
		if ( overrides && ! window.confirm(
			overrides + ' of these are decorative or unreferenced images that are normally left alone. Generate for them anyway?'
		) ) { return; }

		seoAborted = false;
		$( this ).prop( 'disabled', true );
		$( '#ism-seo-generate-cancel' ).show();
		$( '#ism-seo-generate-progress' ).show();
		$( '#ism-seo-generate-log' ).empty();
		$( '#ism-seo-generate-bar' ).css( 'width', '0%' );
		seoGenerateLoop( queue, 0, { ok: 0, failed: 0, tokens: 0 } );
	} );

	$( document ).on( 'click', '#ism-seo-generate-cancel', function () {
		seoAborted = true;
		$( this ).hide();
		$( '#ism-seo-generate-status' ).text( 'Stopping after the current image…' );
	} );

	// ── Review / apply ──────────────────────────────────────────────────────

	function seoRenderReview() {
		var pending = seoGroups.filter( function ( g ) {
			var p = seoGroupProposal( g );
			return ( p && ! p.error ) || seoEdits[ g.key ];
		} );
		$( '#ism-seo-review-card' ).toggle( pending.length > 0 );
		$( '#ism-seo-review-list' ).html( pending.length
			? '<p class="description">' + pending.length + ' image(s) have unapplied changes. '
				+ '<button type="button" class="button button-small ism-seo-goto-proposals">Show only these</button> '
				+ 'then tick the ones you want and Apply.</p>'
			: '' );
	}

	$( document ).on( 'click', '.ism-seo-goto-proposals', function () {
		$( '#ism-seo-filter' ).val( 'proposals' );
		$( '#ism-seo-search' ).val( '' );
		seoPage = 1;
		seoRenderChart();
		var top = $( '#ism-seo-chart-card' ).offset();
		if ( top ) { $( 'html, body' ).animate( { scrollTop: top.top - 40 }, 150 ); }
	} );

	$( document ).on( 'click', '#ism-seo-apply', function () {
		var rows = [];
		seoGroups.forEach( function ( g ) {
			if ( ! seoChecked[ g.key ] ) { return; }
			var v = seoGroupValues( g );
			rows.push( { ids: g.ids, title: v.title, alt_text: v.alt_text, description: v.description } );
		} );

		if ( ! rows.length ) {
			$( '.ism-seo-apply-status' ).text( 'Nothing ticked.' );
			return;
		}

		var copies = rows.reduce( function ( n, r ) { return n + r.ids.length; }, 0 );
		if ( ! window.confirm( 'Write ' + rows.length + ' image(s) — ' + copies
			+ ' attachment(s) including duplicate copies — to the media library? Fields you left empty are not touched.' ) ) {
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );
		$( '.ism-seo-apply-status' ).text( 'Applying…' );

		seoPost( 'ism_seo_apply', { rows: rows }, function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! res.success ) {
				$( '.ism-seo-apply-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				return;
			}
			// The written values are the live ones now, so the row resets to them.
			rows.forEach( function ( r ) {
				r.ids.forEach( function ( id ) { delete seoProposals[ String( id ) ]; } );
			} );
			seoGroups.forEach( function ( g ) {
				if ( ! seoChecked[ g.key ] ) { return; }
				var v = seoGroupValues( g );
				g.reviewed = true;
				g.rows.forEach( function ( row ) { row.reviewed = true; } );
				g.rows.forEach( function ( row ) {
					if ( v.title ) { row.current.title = v.title; }
					if ( v.alt_text ) { row.current.alt_text = v.alt_text; }
					if ( v.description ) { row.current.description = v.description; }
					row.missing = seoMissingFields( row.current );
				} );

				// Derived from the values just written, so a row that has been
				// filled in leaves the missing-metadata filters immediately
				// rather than lingering there until the next scan.
				g.missing = g.rep.missing;
				delete seoEdits[ g.key ];
			} );
			seoChecked = {};
			$( '.ism-seo-apply-status' ).text( 'Applied ' + res.data.applied + ' attachment(s)'
				+ ( res.data.skipped ? ', skipped ' + res.data.skipped : '' ) + '.' );
			seoRenderChart();
			seoRenderReview();
		}, function () {
			$btn.prop( 'disabled', false );
			$( '.ism-seo-apply-status' ).text( 'Request failed — nothing was written.' );
		} );
	} );

	$( document ).on( 'click', '#ism-seo-discard', function () {
		if ( ! window.confirm( 'Discard every unapplied proposal? The tokens already spent are not refundable.' ) ) { return; }
		seoPost( 'ism_seo_reset', {}, function () {
			seoProposals = {};
			seoEdits = {};
			seoRenderChart();
			seoRenderReview();
			$( '.ism-seo-apply-status' ).text( 'Proposals discarded.' );
		} );
	} );

	$( document ).on( 'click', '#ism-remove-key', function () {
		if ( ! window.confirm( 'Remove the stored API key? Generation stops working until a new one is saved. Everything else in this tab keeps working.' ) ) { return; }
		var $btn = $( this ).prop( 'disabled', true );
		$( '.ism-key-remove-status' ).text( 'Removing…' );
		seoPost( 'ism_ai_clear_key', {}, function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! res.success ) {
				$( '.ism-key-remove-status' ).text( 'Could not remove the key.' );
				return;
			}
			seoHasKey = false;
			$( '.ism-key-state' ).html( '<span style="color:#996800;font-weight:500">No key — generation disabled</span>' );
			$( '.ism-key-actions' ).hide();
			$( '.ism-key-remove-status' ).text( '' );
			$( '#ism-seo-generate-start' ).prop( 'disabled', true );
		}, function () {
			$btn.prop( 'disabled', false );
			$( '.ism-key-remove-status' ).text( 'Request failed.' );
		} );
	} );

	// ── Duplicate review (read-only) ────────────────────────────────────────
	//
	// Nothing in here deletes, trashes, merges or repoints anything. Every copy
	// links to its own attachment edit screen, and removing one is a deliberate
	// act performed there by someone who has looked at what references it.

	var seoDupesLoaded = false;

	function seoDupeCopy( c, conflict ) {
		var fields = [ [ 'title', 'Title' ], [ 'alt', 'Alt text' ], [ 'description', 'Description' ] ];

		var checks = fields.map( function ( f ) {
			var on = c.has[ f[ 0 ] ];
			return '<li class="' + ( on ? 'ism-dupe-has' : 'ism-dupe-hasnt' ) + '">'
				+ ( on ? '✓ ' : '— ' ) + esc( f[ 1 ] ) + '</li>';
		} ).join( '' );

		var values = [
			[ 'Title', c.current.title ],
			[ 'Alt text', c.current.alt_text ],
			[ 'Description', c.current.description ]
		].map( function ( v ) {
			return '<div class="ism-dupe-value"><span>' + esc( v[ 0 ] ) + '</span>'
				+ ( v[ 1 ]
					? '<em title="' + esc( v[ 1 ] ) + '">' + esc( v[ 1 ].length > 120 ? v[ 1 ].slice( 0, 120 ) + '…' : v[ 1 ] ) + '</em>'
					: '<em class="ism-dupe-empty">empty</em>' )
				+ '</div>';
		} ).join( '' );

		var places = c.places.length
			? '<ul class="ism-dupe-places">' + c.places.map( function ( p ) {
				var label = esc( p.title || '(no title)' );
				return '<li>' + ( p.link ? '<a href="' + esc( p.link ) + '" target="_blank">' + label + '</a>' : label )
					+ ' <span class="description">' + esc( p.type ) + ( p.featured ? ' · featured' : '' ) + '</span></li>';
			} ).join( '' ) + '</ul>'
			: '<p class="ism-dupe-noplace">Nothing references this copy.</p>';

		// Only meaningful when the keeper suggestion and actual usage disagree.
		var warn = ( c.is_primary && conflict )
			? '<p class="ism-dupe-warn">Suggested as keeper on metadata alone, but no page references it.</p>'
			: '';

		return '<div class="ism-dupe-copy' + ( c.is_primary ? ' ism-dupe-primary' : '' ) + '">'
			+ '<div class="ism-dupe-copy-head">'
			+ ( c.thumb ? '<img src="' + esc( c.thumb ) + '" alt="" width="64" height="64" loading="lazy" />' : '<span class="ism-seo-nothumb"></span>' )
			+ '<div>'
			+ ( c.is_primary ? '<span class="ism-badge ism-badge-keeper">suggested keeper</span>' : '' )
			+ '<div class="ism-dupe-name">'
			+ ( c.edit_url ? '<a href="' + esc( c.edit_url ) + '" target="_blank">' + esc( c.filename ) + '</a>' : esc( c.filename ) )
			+ '</div>'
			+ '<div class="description">#' + c.id + ' · uploaded ' + esc( c.uploaded )
			+ ' · metadata ' + c.score + '/3 · used on ' + c.used_count + ' page' + ( c.used_count === 1 ? '' : 's' ) + '</div>'
			+ '</div></div>'
			+ warn
			+ '<ul class="ism-dupe-checks">' + checks + '</ul>'
			+ '<div class="ism-dupe-values">' + values + '</div>'
			+ '<div class="ism-dupe-usage"><strong>References</strong>' + places + '</div>'
			+ ( c.edit_url ? '<p class="ism-dupe-edit"><a href="' + esc( c.edit_url ) + '" target="_blank">Open this copy’s edit screen →</a></p>' : '' )
			+ '</div>';
	}

	function seoRenderDupes( data ) {
		var groups = data.groups || [];

		$( '.ism-dupe-summary' ).html(
			'<ul class="ism-seo-stats">'
			+ '<li><strong>' + groups.length + '</strong> duplicate groups</li>'
			+ '<li><strong>' + data.redundant + '</strong> redundant copies</li>'
			+ ( data.conflicts ? '<li class="ism-stat-warn"><strong>' + data.conflicts + '</strong> where the suggested keeper is unused</li>' : '' )
			+ '</ul>'
			+ ( data.conflicts
				? '<p class="ism-dupe-warn">The keeper suggestion scores metadata completeness only — it does not consider usage. In '
					+ data.conflicts + ' group' + ( data.conflicts === 1 ? '' : 's' )
					+ ' the suggested copy is referenced by no page while another copy is in use. Read the References list on each copy before removing anything.</p>'
				: '' )
		);

		if ( ! groups.length ) {
			$( '#ism-dupe-list' ).html( '<p class="description">No byte-identical duplicates found.</p>' );
			return;
		}

		$( '#ism-dupe-list' ).html( groups.map( function ( g ) {
			return '<div class="ism-dupe-group' + ( g.usage_conflict ? ' ism-dupe-group-conflict' : '' ) + '">'
				+ '<div class="ism-dupe-group-head">'
				+ '<strong>' + g.copies.length + ' identical copies</strong>'
				+ ' <span class="description">' + esc( g.reason ) + '</span>'
				+ '</div>'
				+ '<div class="ism-dupe-copies">' + g.copies.map( function ( c ) {
					return seoDupeCopy( c, g.usage_conflict );
				} ).join( '' ) + '</div>'
				+ '</div>';
		} ).join( '' ) );
	}

	$( document ).on( 'click', '#ism-dupe-toggle', function () {
		var $p   = $( '#ism-dupe-panel' );
		var open = $p.is( '[hidden]' );

		$p.attr( 'hidden', open ? null : 'hidden' );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' )
			.find( '.ism-advanced-caret' ).text( open ? '▾' : '▸' );

		if ( ! open || seoDupesLoaded ) { return; }

		$( '.ism-dupe-status' ).text( 'Loading…' );
		seoPost( 'ism_seo_duplicates', {}, function ( res ) {
			if ( ! res.success ) {
				$( '.ism-dupe-status' ).text( 'Could not load duplicates.' );
				return;
			}
			seoDupesLoaded = true;
			$( '.ism-dupe-status' ).text( '' );
			seoRenderDupes( res.data );
		}, function () {
			$( '.ism-dupe-status' ).text( 'Request failed.' );
		} );
	} );

	// ── Advanced AI settings ────────────────────────────────────────────────
	//
	// Weights are read from the DOM at Generate time, not from saved settings,
	// so they can be adjusted immediately before a run. "Save as default" only
	// decides what the boxes start at next visit.

	function seoWeights() {
		var w = {};
		$( '.ism-weight-number' ).each( function () {
			w[ $( this ).data( 'weight' ) ] = parseInt( $( this ).val(), 10 ) || 0;
		} );
		return w;
	}

	function seoWeightTotal() {
		var t = 0;
		$.each( seoWeights(), function ( k, v ) { t += v; } );
		return t;
	}

	function seoWeightsValid() {
		return seoWeightTotal() === 100;
	}

	function seoSyncWeights() {
		var total = seoWeightTotal();
		$( '.ism-weight-total' ).text( total );

		var bad = total !== 100;
		$( '.ism-weight-total-row' ).toggleClass( 'ism-weight-bad', bad );
		$( '.ism-weight-error' ).text( bad
			? ( total > 100 ? 'Over by ' + ( total - 100 ) + '% — must total 100%.'
			                : 'Short by ' + ( 100 - total ) + '% — must total 100%.' )
			: '' );

		// Generation is blocked rather than quietly rescaled, so a mistake is
		// visible before it costs anything.
		$( '#ism-seo-generate-start' ).prop( 'disabled', bad || ! seoHasKey );

		seoRenderEstimate();
	}

	$( document ).on( 'click', '#ism-advanced-toggle', function () {
		var $p = $( '#ism-advanced-panel' );
		var open = $p.is( '[hidden]' );
		$p.attr( 'hidden', ! open ? 'hidden' : null );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' )
			.find( '.ism-advanced-caret' ).text( open ? '▾' : '▸' );
		if ( open ) { seoSyncWeights(); }
	} );

	// The slider and the number box are two views of one value.
	$( document ).on( 'input change', '.ism-weight-range', function () {
		var k = $( this ).data( 'weight' );
		$( '.ism-weight-number[data-weight="' + k + '"]' ).val( $( this ).val() );
		seoSyncWeights();
	} );
	$( document ).on( 'input change', '.ism-weight-number', function () {
		var k = $( this ).data( 'weight' );
		$( '.ism-weight-range[data-weight="' + k + '"]' ).val( $( this ).val() );
		seoSyncWeights();
	} );
	$( document ).on( 'input', '#ism-ai-extra-prompt', function () {
		clearTimeout( window.ismPromptTimer );
		window.ismPromptTimer = setTimeout( seoRenderEstimate, 200 );
	} );

	$( document ).on( 'click', '#ism-advanced-reset', function () {
		var d = { image: 50, prompt: 0, context: 25, metadata: 25 };
		$.each( d, function ( k, v ) {
			$( '.ism-weight-number[data-weight="' + k + '"], .ism-weight-range[data-weight="' + k + '"]' ).val( v );
		} );
		seoSyncWeights();
	} );

	$( document ).on( 'click', '#ism-advanced-save', function () {
		if ( ! seoWeightsValid() ) {
			$( '.ism-advanced-save-status' ).text( 'Fix the total first.' );
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );
		$( '.ism-advanced-save-status' ).text( 'Saving…' );
		seoPost( 'ism_seo_save_advanced', {
			weights:      seoWeights(),
			extra_prompt: $( '#ism-ai-extra-prompt' ).val() || ''
		}, function ( res ) {
			$btn.prop( 'disabled', false );
			$( '.ism-advanced-save-status' ).text( res.success ? 'Saved as default.' : ( res.data || 'Could not save.' ) );
		}, function () {
			$btn.prop( 'disabled', false );
			$( '.ism-advanced-save-status' ).text( 'Request failed.' );
		} );
	} );

	// ── Cost estimate ───────────────────────────────────────────────────────
	//
	// Mirrors ism_ai_estimate_tokens(). Component figures come from measured
	// runs, but page text and reply length vary per image, so it is labelled
	// approximate rather than presented as a quote.

	var SEO_PRICES = {
		'claude-haiku-4-5': { in: 1, out: 5 },
		'claude-sonnet-5':  { in: 3, out: 15 },
		'claude-opus-5':    { in: 5, out: 25 },
		'claude-opus-4-8':  { in: 5, out: 25 }
	};

	function seoQueueSize() {
		var mode = $( 'input[name="ism_seo_mode"]:checked' ).val();
		if ( mode === 'selected' ) {
			return seoGroups.filter( function ( g ) { return seoChecked[ g.key ]; } ).length;
		}
		var limit = Math.max( 1, parseInt( $( '#ism-seo-limit' ).val(), 10 ) || 20 );
		return Math.min( limit, seoGroups.filter( function ( g ) {
			return g.group === 'ready' && ! seoGroupProposal( g );
		} ).length );
	}

	function seoRenderEstimate() {
		var $box = $( '#ism-seo-estimate' );
		if ( ! $box.length ) { return; }

		var w      = seoWeights();
		var model  = $( '#ism-ai-model' ).val() || 'claude-haiku-4-5';
		var price  = SEO_PRICES[ model ] || SEO_PRICES[ 'claude-haiku-4-5' ];
		var chars  = parseInt( $( '#ism-context-max-chars' ).val(), 10 ) || 1000;
		var extra  = ( $( '#ism-ai-extra-prompt' ).val() || '' ).length;
		var n      = seoQueueSize();

		var input = 340;
		var parts = [];
		if ( w.image > 0 )    { input += 130; parts.push( 'image' ); }
		if ( w.context > 0 )  { input += Math.ceil( chars / 4 ) + 60; parts.push( 'page context' ); }
		if ( w.metadata > 0 ) { input += 60; parts.push( 'existing metadata' ); }
		if ( w.prompt > 0 && extra > 0 ) { input += Math.ceil( extra / 4 ); parts.push( 'your instructions' ); }

		var active = 0;
		$.each( w, function ( k, v ) { if ( v > 0 ) { active++; } } );
		if ( active > 1 ) { input += 25 * active; }

		var output    = 150;
		var perImage  = ( input * price.in / 1e6 ) + ( output * price.out / 1e6 );
		var total     = perImage * n;

		if ( ! seoWeightsValid() ) {
			$box.html( '<p class="ism-estimate-blocked">Weights total ' + seoWeightTotal()
				+ '%. Generation is disabled until they total 100%.</p>' ).show();
			return;
		}

		if ( ! n ) {
			$box.html( '<p class="description">Nothing queued yet — tick images in the chart, or switch to the first-N option.</p>' ).show();
			return;
		}

		$box.html(
			'<div class="ism-estimate-head"><strong>' + n + '</strong> image' + ( n === 1 ? '' : 's' )
			+ ' · approx <strong>$' + total.toFixed( total < 1 ? 3 : 2 ) + '</strong> total'
			+ ' <span class="description">(about $' + perImage.toFixed( 4 ) + ' each)</span></div>'
			+ '<p class="description">Sending ' + ( parts.length ? parts.join( ', ' ) : 'the instructions only' )
			+ ' — roughly ' + input.toLocaleString() + ' tokens in and ' + output + ' out per image, at '
			+ esc( model ) + ' rates. Page text varies per image, so treat this as a ballpark, not a quote.</p>'
			+ ( w.image === 0 ? '<p class="ism-estimate-warn">The image itself is weighted 0%, so nothing will actually look at the picture. Output will be inferred from text alone.</p>' : '' )
		).show();
	}

	$( document ).on( 'change', 'input[name="ism_seo_mode"], #ism-seo-limit, #ism-ai-model, #ism-context-max-chars', seoRenderEstimate );

	// Model note follows the dropdown.
	$( document ).on( 'change', '#ism-ai-model', function () {
		var m = $( this ).val();
		$( '.ism-model-note' ).hide().filter( '[data-model="' + m + '"]' ).show();
	} );

	// ── Broken images ───────────────────────────────────────────────────────
	//
	// Detection and suggestion only. There is no repair path in this build:
	// repointing has to rewrite Elementor JSON and ACF meta correctly, which is
	// being built and tested separately. Choosing a replacement here records
	// the decision — the slow, human part — ready for that step.

	var brokenRefs    = [];
	var brokenChoices = {};   // ref key -> { id, filename, thumb }
	var brokenSuggest = {};   // filename -> suggestions
	var brokenPage    = 1;
	var BROKEN_PER_PAGE = 25;

	function brokenRun( offset ) {
		seoPost( 'ism_broken_batch', { offset: offset }, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-broken-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', false );
				return;
			}
			var d = res.data;
			brokenRefs = brokenRefs.concat( d.refs );
			$( '#ism-broken-bar' ).css( 'width', ( d.total ? Math.round( ( d.offset / d.total ) * 100 ) : 100 ) + '%' );
			$( '#ism-broken-status' ).text( d.offset + ' / ' + d.total + ' posts checked · ' + brokenRefs.length + ' broken references' );

			if ( d.done ) {
				$( '#ism-broken-status' ).text( 'Scan complete — ' + d.total + ' posts checked.' );
				$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', false );
				brokenPage = 1;
				brokenRenderSummary();
				brokenRenderList();
				return;
			}
			setTimeout( function () { brokenRun( d.offset ); }, 40 );
		}, function () {
			$( '#ism-broken-status' ).text( 'Request failed. Reload and try again.' );
			$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', false );
		} );
	}

	function brokenStart( force ) {
		brokenRefs = [];
		$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', true );
		$( '#ism-broken-progress' ).show();
		$( '#ism-broken-bar' ).css( 'width', '0%' );
		$( '#ism-broken-status' ).text( force ? 'Rescanning…' : 'Loading…' );

		seoPost( 'ism_broken_init', { force: force ? 1 : 0 }, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-broken-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', false );
				return;
			}

			brokenChoices = {};
			$.each( res.data.choices || {}, function ( k, v ) { brokenChoices[ k ] = { id: v }; } );

			if ( res.data.cached ) {
				brokenRefs = res.data.refs || [];
				$( '.ism-broken-state' ).text( 'Using the scan from ' + res.data.scanned_ago + ' ago.' );
				$( '#ism-broken-status' ).text( 'Loaded ' + brokenRefs.length + ' broken references.' );
				$( '#ism-broken-bar' ).css( 'width', '100%' );
				$( '#ism-broken-scan, #ism-broken-rescan' ).prop( 'disabled', false );
				brokenPage = 1;
				brokenRenderSummary();
				brokenRenderList();
				return;
			}

			$( '.ism-broken-state' ).text( 'No stored scan — checking every post now.' );
			brokenRun( 0 );
		} );
	}

	$( document ).on( 'click', '#ism-broken-scan', function () { brokenStart( false ); } );
	$( document ).on( 'click', '#ism-broken-rescan', function () { brokenStart( true ); } );

	function brokenRenderSummary() {
		var stale = 0, missing = 0, unrec = 0, chosen = 0;
		var posts = {};

		brokenRefs.forEach( function ( r ) {
			if ( r.type === 'stale_id' ) { stale++; } else { missing++; }
			if ( ! r.recoverable ) { unrec++; }
			if ( brokenChoices[ r.key ] ) { chosen++; }
			posts[ r.post_id ] = true;
		} );

		$( '.ism-broken-summary' ).html(
			'<ul class="ism-seo-stats">'
			+ '<li><strong>' + brokenRefs.length + '</strong> broken references</li>'
			+ '<li><strong>' + Object.keys( posts ).length + '</strong> pages affected</li>'
			+ '<li><strong>' + stale + '</strong> deleted attachment</li>'
			+ '<li><strong>' + missing + '</strong> file missing on disk</li>'
			+ '<li><strong>' + unrec + '</strong> no filename to match on</li>'
			+ ( chosen ? '<li class="ism-stat-open"><strong>' + chosen + '</strong> replacement chosen</li>' : '' )
			+ '</ul>'
		).show();

		$( '#ism-broken-list-card' ).toggle( brokenRefs.length > 0 );
	}

	function brokenVisible() {
		var filter = $( '#ism-broken-filter' ).val() || 'all';
		var term   = ( $( '#ism-broken-search' ).val() || '' ).toLowerCase();

		return brokenRefs.filter( function ( r ) {
			if ( filter === 'stale_id'      && r.type !== 'stale_id' ) { return false; }
			if ( filter === 'missing_file'  && r.type !== 'missing_file' ) { return false; }
			if ( filter === 'recoverable'   && ! r.recoverable ) { return false; }
			if ( filter === 'unrecoverable' && r.recoverable ) { return false; }
			if ( filter === 'chosen'        && ! brokenChoices[ r.key ] ) { return false; }
			if ( filter === 'visible'       && r.severity !== 'likely_visible' ) { return false; }
			if ( filter === 'harmless'      && r.severity === 'likely_visible' ) { return false; }

			if ( term ) {
				var hay = ( r.filename + ' ' + r.post_title + ' ' + r.field + ' ' + r.ref ).toLowerCase();
				if ( hay.indexOf( term ) === -1 ) { return false; }
			}
			return true;
		} );
	}

	function brokenSourceLabel( r ) {
		if ( r.source === 'elementor' ) { return 'Elementor'; }
		if ( r.source === 'acf' )       { return 'Custom field'; }
		if ( r.source === 'featured' )  { return 'Featured image'; }
		if ( r.source === 'content' )   { return 'Post content'; }
		return r.source;
	}

	function brokenRowHtml( r ) {
		var chosen = brokenChoices[ r.key ];

		var what = r.type === 'stale_id'
			? '<span class="ism-badge ism-badge-failed">attachment #' + esc( r.ref ) + ' deleted</span>'
			: '<span class="ism-badge ism-badge-noalt">file missing</span>';

		var sev = {
			likely_visible: [ 'ism-sev-visible', 'probably visible' ],
			renders_ok:     [ 'ism-sev-ok', 'renders fine — stale reference only' ],
			responsive:     [ 'ism-sev-minor', 'tablet/mobile only' ],
			template:       [ 'ism-sev-minor', 'on a saved template' ]
		}[ r.severity ] || [ 'ism-sev-minor', 'unknown' ];

		what += ' <span class="ism-badge ' + sev[ 0 ] + '">' + esc( sev[ 1 ] ) + '</span>';

		var name = r.recoverable
			? '<code>' + esc( r.filename ) + '</code>'
			: '<em class="ism-broken-unknown">filename not recoverable — this reference stores only an ID</em>';

		var chosenHtml = chosen
			? '<div class="ism-broken-chosen">'
				+ ( chosen.thumb ? '<img src="' + esc( chosen.thumb ) + '" alt="" width="36" height="36" />' : '' )
				+ '<span>Chosen replacement: <strong>#' + chosen.id + '</strong>'
				+ ( chosen.filename ? ' ' + esc( chosen.filename ) : '' ) + '</span>'
				+ '<button type="button" class="button button-small ism-broken-clear" data-key="' + esc( r.key ) + '">Clear</button>'
				+ '</div>'
			: '';

		return '<div class="ism-broken-row" data-key="' + esc( r.key ) + '">'
			+ '<div class="ism-broken-head">'
			+ '<div class="ism-broken-what">' + what + ' ' + name + '</div>'
			+ '<div class="description">'
			+ esc( brokenSourceLabel( r ) ) + ' · <code>' + esc( r.field ) + '</code> · on '
			// The live page, so clicking through shows whether anything is
			// actually broken. The editor stays reachable as a separate link.
			+ ( r.permalink
				? '<a href="' + esc( r.permalink ) + '" target="_blank">' + esc( r.post_title ) + '</a>'
				: esc( r.post_title ) )
			+ ' <span class="description">(' + esc( r.post_type ) + ')</span>'
			+ ' <a href="' + esc( ismData.adminUrl + 'post.php?post=' + r.post_id + '&action=edit' ) + '" target="_blank" class="ism-broken-editlink">edit</a>'
			+ '</div>'
			+ ( r.url ? '<div class="ism-broken-url" title="' + esc( r.url ) + '">' + esc( r.url ) + '</div>' : '' )
			+ '</div>'
			+ chosenHtml
			+ '<div class="ism-broken-actions">'
			+ ( r.recoverable
				? '<button type="button" class="button button-small ism-broken-find" data-key="' + esc( r.key ) + '" data-filename="' + esc( r.filename ) + '">Suggest matches</button> '
				: '' )
			+ '<button type="button" class="button button-small ism-broken-verify" data-key="' + esc( r.key ) + '">Is it actually broken?</button> '
			+ '<button type="button" class="button button-small ism-broken-pick" data-key="' + esc( r.key ) + '">Choose from media library…</button>'
			+ ( chosen
				? ' <button type="button" class="button button-small button-primary ism-broken-preview" data-key="' + esc( r.key ) + '" data-id="' + chosen.id + '">Preview the fix</button>'
				: '' )
			+ '</div>'
			+ '<div class="ism-broken-verdict"></div>'
			+ '<div class="ism-broken-suggestions"></div>'
			+ '<div class="ism-broken-preview-box"></div>'
			+ '</div>';
	}

	// Grouped by page. A flat list of 110 references is a pile; the unit of work
	// is "this page is broken", and fixing it means working through that page's
	// images together.
	function brokenPages() {
		var vis = brokenVisible();
		var by  = {};

		vis.forEach( function ( r ) {
			if ( ! by[ r.post_id ] ) {
				by[ r.post_id ] = {
					post_id: r.post_id, title: r.post_title, type: r.post_type,
					permalink: r.permalink || '', refs: [], chosen: 0
				};
			}
			by[ r.post_id ].refs.push( r );
			if ( brokenChoices[ r.key ] ) { by[ r.post_id ].chosen++; }
		} );

		return Object.keys( by ).map( function ( k ) { return by[ k ]; } )
			.sort( function ( a, b ) { return b.refs.length - a.refs.length || a.title.localeCompare( b.title ); } );
	}

	function brokenPageHtml( p ) {
		var open = !! brokenOpenPages[ p.post_id ];

		return '<div class="ism-broken-page' + ( open ? ' ism-broken-page-open' : '' ) + '" data-post="' + p.post_id + '">'
			+ '<button type="button" class="ism-broken-page-head" data-post="' + p.post_id + '">'
			+ '<span class="ism-broken-caret">' + ( open ? '▾' : '▸' ) + '</span>'
			+ '<span class="ism-broken-page-title">' + esc( p.title ) + '</span>'
			+ '<span class="description">' + esc( p.type ) + '</span>'
			+ '<span class="ism-broken-page-count">' + p.refs.length + ' broken</span>'
			+ ( p.chosen ? '<span class="ism-badge ism-badge-proposed">' + p.chosen + ' chosen</span>' : '' )
			+ '</button>'
			+ '<div class="ism-broken-page-body"' + ( open ? '' : ' hidden' ) + '>'
			+ '<p class="ism-broken-page-links">'
			+ ( p.permalink ? '<a href="' + esc( p.permalink ) + '" target="_blank">View the live page →</a> ' : '' )
			+ '<a href="' + esc( ismData.adminUrl + 'post.php?post=' + p.post_id + '&action=edit' ) + '" target="_blank" class="ism-broken-editlink">edit</a>'
			+ '</p>'
			+ p.refs.map( brokenRowHtml ).join( '' )
			+ '</div></div>';
	}

	function brokenRenderList() {
		var pages = brokenPages();
		var total = brokenVisible().length;
		var count = Math.max( 1, Math.ceil( pages.length / BROKEN_PER_PAGE ) );
		if ( brokenPage > count ) { brokenPage = count; }

		var start = ( brokenPage - 1 ) * BROKEN_PER_PAGE;
		var slice = pages.slice( start, start + BROKEN_PER_PAGE );

		$( '.ism-broken-count' ).text( pages.length + ' page' + ( pages.length === 1 ? '' : 's' ) + ' · ' + total + ' broken image' + ( total === 1 ? '' : 's' ) );
		$( '#ism-broken-list' ).html( slice.length
			? slice.map( brokenPageHtml ).join( '' )
			: '<p class="description">Nothing matches this filter.</p>' );

		if ( count < 2 ) {
			$( '.ism-broken-pager-top, .ism-broken-pager-bottom' ).empty();
			return;
		}

		$( '.ism-broken-pager-top, .ism-broken-pager-bottom' ).html(
			'<span class="ism-seo-pager-info">Pages ' + ( start + 1 ) + '–' + ( start + slice.length ) + ' of ' + pages.length + '</span>'
			+ '<button type="button" class="button ism-broken-prev"' + ( brokenPage === 1 ? ' disabled' : '' ) + '>‹ Prev</button>'
			+ '<span class="ism-seo-pager-pos">Page ' + brokenPage + ' of ' + count + '</span>'
			+ '<button type="button" class="button ism-broken-next"' + ( brokenPage === count ? ' disabled' : '' ) + '>Next ›</button>'
		);
	}

	var brokenOpenPages = {};

	$( document ).on( 'change', '#ism-broken-filter', function () {
		brokenPage = 1;
		brokenRenderList();
	} );

	$( document ).on( 'input', '#ism-broken-search', function () {
		clearTimeout( window.ismBrokenTimer );
		window.ismBrokenTimer = setTimeout( function () { brokenPage = 1; brokenRenderList(); }, 250 );
	} );

	$( document ).on( 'click', '.ism-broken-prev', function () { brokenPage--; brokenRenderList(); } );
	$( document ).on( 'click', '.ism-broken-next', function () { brokenPage++; brokenRenderList(); } );

	$( document ).on( 'click', '.ism-broken-page-head', function () {
		var id = String( $( this ).data( 'post' ) );
		if ( brokenOpenPages[ id ] ) { delete brokenOpenPages[ id ]; } else { brokenOpenPages[ id ] = true; }
		brokenRenderList();
	} );

	// ── Suggestions ─────────────────────────────────────────────────────────

	function brokenShowSuggestions( $row, key, list ) {
		if ( ! list.length ) {
			$row.find( '.ism-broken-suggestions' ).html(
				'<p class="description">No candidate in the library resembles this filename. Use the media library picker.</p>'
			);
			return;
		}

		$row.find( '.ism-broken-suggestions' ).html(
			'<p class="description">Ranked by filename similarity only — the picture is not compared. Check before choosing.</p>'
			+ '<div class="ism-broken-cands">' + list.map( function ( c ) {
				return '<div class="ism-broken-cand">'
					+ ( c.thumb ? '<img src="' + esc( c.thumb ) + '" alt="" width="48" height="48" loading="lazy" />' : '<span class="ism-seo-nothumb"></span>' )
					+ '<div class="ism-broken-cand-meta">'
					+ '<span class="ism-broken-score">' + c.percent + '%</span> '
					+ ( c.edit_url ? '<a href="' + esc( c.edit_url ) + '" target="_blank">' + esc( c.filename ) + '</a>' : esc( c.filename ) )
					+ '<div class="description">#' + c.id + ' · ' + esc( c.reason ) + '</div>'
					+ '</div>'
					+ '<button type="button" class="button button-small ism-broken-choose" data-key="' + esc( key ) + '" data-id="' + c.id + '">Choose</button>'
					+ '</div>';
			} ).join( '' ) + '</div>'
		);
	}

	$( document ).on( 'click', '.ism-broken-find', function () {
		var $btn = $( this );
		var key  = String( $btn.data( 'key' ) );
		var file = String( $btn.data( 'filename' ) );
		var $row = $btn.closest( '.ism-broken-row' );

		if ( brokenSuggest[ file ] ) {
			brokenShowSuggestions( $row, key, brokenSuggest[ file ] );
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Matching…' );
		seoPost( 'ism_broken_suggest', { filename: file }, function ( res ) {
			$btn.prop( 'disabled', false ).text( 'Suggest matches' );
			if ( ! res.success ) { return; }
			brokenSuggest[ file ] = res.data.suggestions;
			brokenShowSuggestions( $row, key, res.data.suggestions );
		}, function () {
			$btn.prop( 'disabled', false ).text( 'Suggest matches' );
		} );
	} );

	function brokenSaveChoice( key, id ) {
		seoPost( 'ism_broken_choose', { key: key, attachment_id: id }, function ( res ) {
			if ( ! res.success ) { return; }
			if ( id ) {
				brokenChoices[ key ] = { id: res.data.attachment_id, filename: res.data.filename, thumb: res.data.thumb };
			} else {
				delete brokenChoices[ key ];
			}
			brokenRenderSummary();
			brokenRenderList();
		} );
	}

	$( document ).on( 'click', '.ism-broken-choose', function () {
		brokenSaveChoice( String( $( this ).data( 'key' ) ), parseInt( $( this ).data( 'id' ), 10 ) );
	} );

	$( document ).on( 'click', '.ism-broken-clear', function () {
		brokenSaveChoice( String( $( this ).data( 'key' ) ), 0 );
	} );

	// Detection reasons about where a reference sits; only the rendered page
	// settles whether anyone actually sees a hole. This fetches it and looks.
	$( document ).on( 'click', '.ism-broken-verify', function () {
		var $btn = $( this );
		var $box = $btn.closest( '.ism-broken-row' ).find( '.ism-broken-verdict' );

		$btn.prop( 'disabled', true ).text( 'Loading the page…' );

		seoPost( 'ism_broken_verify', { key: String( $btn.data( 'key' ) ) }, function ( res ) {
			$btn.prop( 'disabled', false ).text( 'Is it actually broken?' );

			if ( ! res.success ) {
				$box.html( '<p class="ism-repoint-error">' + esc( res.data || 'Could not check.' ) + '</p>' );
				return;
			}

			var d   = res.data;
			var cls = ! d.checked ? 'ism-verdict-unknown' : ( d.found ? 'ism-verdict-broken' : 'ism-verdict-fine' );
			var tag = ! d.checked ? 'Could not check' : ( d.found ? 'Visitors see this break' : 'Not on the rendered page' );

			$box.html( '<div class="ism-broken-verdict-box ' + cls + '">'
				+ '<strong>' + esc( tag ) + '</strong> ' + esc( d.message )
				+ ( d.url ? ' <a href="' + esc( d.url ) + '" target="_blank">Open it →</a>' : '' )
				+ '</div>' );
		}, function () {
			$btn.prop( 'disabled', false ).text( 'Is it actually broken?' );
			$box.html( '<p class="ism-repoint-error">Request failed.</p>' );
		} );
	} );

	// ── Preview and repoint ─────────────────────────────────────────────────
	//
	// One reference at a time, previewed first, and never in bulk. The preview
	// is regenerated server-side immediately before the write, so a reference
	// edited since the scan is caught rather than written over.

	$( document ).on( 'click', '.ism-broken-preview', function () {
		var $btn = $( this );
		var key  = String( $btn.data( 'key' ) );
		var id   = parseInt( $btn.data( 'id' ), 10 );
		var $box = $btn.closest( '.ism-broken-row' ).find( '.ism-broken-preview-box' );

		$btn.prop( 'disabled', true ).text( 'Checking…' );

		seoPost( 'ism_repoint_preview', { key: key, attachment_id: id }, function ( res ) {
			$btn.prop( 'disabled', false ).text( 'Preview the fix' );

            if ( ! res.success ) {
				$box.html( '<p class="ism-repoint-error">' + esc( res.data || 'Could not preview.' ) + '</p>' );
				return;
			}

			$box.html(
				'<div class="ism-repoint-preview">'
				+ '<p class="ism-repoint-head"><strong>' + esc( res.data.message ) + '</strong></p>'
				+ '<table class="ism-repoint-diff"><tbody>'
				+ res.data.changes.map( function ( c ) {
					return '<tr><th>' + esc( c.label ) + '</th>'
						+ '<td class="ism-repoint-from">' + esc( c.from ) + '</td>'
						+ '<td class="ism-repoint-arrow">→</td>'
						+ '<td class="ism-repoint-to">' + esc( c.to ) + '</td></tr>';
				} ).join( '' )
				+ '</tbody></table>'
				+ '<p><button type="button" class="button button-primary ism-broken-apply" data-key="' + esc( key ) + '" data-id="' + id + '">Apply this fix</button>'
				+ ' <span class="description">Writes to this page. One reference only.</span></p>'
				+ '</div>'
			);
		}, function () {
			$btn.prop( 'disabled', false ).text( 'Preview the fix' );
			$box.html( '<p class="ism-repoint-error">Request failed.</p>' );
		} );
	} );

	$( document ).on( 'click', '.ism-broken-apply', function () {
		var $btn = $( this );
		var key  = String( $btn.data( 'key' ) );
		var id   = parseInt( $btn.data( 'id' ), 10 );

		if ( ! window.confirm( 'Apply this fix? It rewrites this one reference on this one page. Nothing else changes.' ) ) { return; }

		$btn.prop( 'disabled', true ).text( 'Applying…' );

		seoPost( 'ism_repoint_apply', { key: key, attachment_id: id }, function ( res ) {
			if ( ! res.success ) {
				$btn.prop( 'disabled', false ).text( 'Apply this fix' );
				$btn.closest( '.ism-broken-preview-box' ).find( '.ism-repoint-error' ).remove();
				$btn.closest( '.ism-broken-preview' ).append( '<p class="ism-repoint-error">' + esc( res.data || 'Failed.' ) + '</p>' );
				return;
			}

			// Fixed references leave the list; the server has already dropped it
			// from the cached scan.
			brokenRefs = brokenRefs.filter( function ( r ) { return r.key !== key; } );
			delete brokenChoices[ key ];
			brokenRenderSummary();
			brokenRenderList();
		}, function () {
			$btn.prop( 'disabled', false ).text( 'Apply this fix' );
		} );
	} );

	// Core's media modal, so any attachment can be picked when the automatic
	// match is wrong or there is nothing to match on.
	var brokenFrame = null;

	$( document ).on( 'click', '.ism-broken-pick', function () {
		var key = String( $( this ).data( 'key' ) );

		if ( ! window.wp || ! window.wp.media ) {
			window.alert( 'The WordPress media library could not be opened on this screen.' );
			return;
		}

		if ( ! brokenFrame ) {
			brokenFrame = wp.media( {
				title: 'Choose the replacement image',
				library: { type: 'image' },
				button: { text: 'Use this image' },
				multiple: false
			} );

			brokenFrame.on( 'select', function () {
				var a = brokenFrame.state().get( 'selection' ).first().toJSON();
				brokenSaveChoice( brokenFrame.ismKey, a.id );
			} );
		}

		brokenFrame.ismKey = key;
		brokenFrame.open();
	} );

} )( jQuery );