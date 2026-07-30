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
	// stylistic: an image this host cannot decode is converted here via canvas
	// and posted back in the same request, so the client has to be in the loop.
	// It also paces requests and keeps the progress bar honest.
	//
	// Nothing is written to the media library from this code except through the
	// explicit Apply button, over checked rows only.

	var seoRows      = [];   // every scanned row
	var seoProposals = {};   // attachment id -> { title, alt_text, description, error }
	var seoAborted   = false;

	function seoPost( action, data, done, fail ) {
		data = $.extend( { action: action, nonce: ismData.seoNonce }, data || {} );
		return $.post( ismData.ajaxUrl, data, done ).fail( fail || function () {} );
	}

	// Transport and server-side hiccups are worth another attempt. A refusal, a
	// missing key, or a skipped attachment will fail identically forever.
	function seoIsRetryable( code ) {
		if ( ! code ) {
			return true; // network-level failure with no code at all
		}
		if ( code === 'ism_ai_transport' ) {
			return true;
		}
		return /^ism_ai_http_(429|5\d\d)$/.test( code );
	}

	// ── Usage index ─────────────────────────────────────────────────────────

	function seoIndexRun( offset, total ) {
		$.post( ismData.ajaxUrl, {
			action: 'ism_usage_index_batch',
			nonce:  ismData.usageIndexNonce,
			offset: offset
		}, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-usage-index-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				return;
			}
			var d   = res.data;
			var pct = d.total ? Math.round( ( d.offset / d.total ) * 100 ) : 100;
			$( '#ism-usage-index-bar' ).css( 'width', pct + '%' );
			$( '#ism-usage-index-status' ).text( d.offset + ' / ' + d.total + ' posts scanned, ' + d.found + ' images found' );

			if ( d.done ) {
				$( '#ism-usage-index-status' ).text( 'Done. ' + d.total + ' posts scanned, ' + d.found + ' images indexed. Now scan the library.' );
				$( '#ism-usage-index-start' ).prop( 'disabled', false );
				return;
			}
			setTimeout( function () { seoIndexRun( d.offset, d.total ); }, 100 );
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
			action:        'ism_usage_index_init',
			nonce:         ismData.usageIndexNonce,
			force_restart: 1
		}, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-usage-index-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-usage-index-start' ).prop( 'disabled', false );
				return;
			}
			seoIndexRun( res.data.offset, res.data.total );
		} );
	} );

	// ── Scan ────────────────────────────────────────────────────────────────

	function seoScanRun( offset, total ) {
		seoPost( 'ism_seo_scan_batch', { offset: offset }, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-seo-scan-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-seo-scan-start' ).prop( 'disabled', false );
				return;
			}
			var d = res.data;
			seoRows = seoRows.concat( d.rows );

			var pct = d.total ? Math.round( ( d.offset / d.total ) * 100 ) : 100;
			$( '#ism-seo-scan-bar' ).css( 'width', pct + '%' );
			$( '#ism-seo-scan-status' ).text( d.offset + ' / ' + d.total + ' images classified' );

			if ( d.done ) {
				$( '#ism-seo-scan-status' ).text( 'Scan complete — ' + d.total + ' images.' );
				$( '#ism-seo-scan-start' ).prop( 'disabled', false );
				seoRenderSummary();
				seoRenderGroups();
				seoRenderReview();
				return;
			}
			setTimeout( function () { seoScanRun( d.offset, d.total ); }, 60 );
		}, function () {
			$( '#ism-seo-scan-status' ).text( 'Request failed. Reload and try again.' );
			$( '#ism-seo-scan-start' ).prop( 'disabled', false );
		} );
	}

	$( document ).on( 'click', '#ism-seo-scan-start', function () {
		$( this ).prop( 'disabled', true );
		seoRows = [];
		$( '#ism-seo-scan-progress' ).show();
		$( '#ism-seo-scan-bar' ).css( 'width', '0%' );
		$( '#ism-seo-scan-status' ).text( 'Starting…' );

		seoPost( 'ism_seo_scan_init', {}, function ( res ) {
			if ( ! res.success ) {
				$( '#ism-seo-scan-status' ).text( 'Error: ' + ( res.data || 'unknown' ) );
				$( '#ism-seo-scan-start' ).prop( 'disabled', false );
				return;
			}
			// Proposals generated earlier survive a reload, so a scan restores
			// them into the review table rather than discarding paid-for work.
			seoProposals = res.data.results || {};

			if ( ! res.data.index_built ) {
				$( '#ism-seo-scan-status' ).text( 'The usage index has not been built. Build it first or every image will look unused.' );
			}
			seoScanRun( 0, res.data.total );
		} );
	} );

	function seoCount( group ) {
		var n = 0;
		seoRows.forEach( function ( r ) { if ( r.group === group ) { n++; } } );
		return n;
	}

	function seoPending() {
		return seoRows.filter( function ( r ) {
			return r.group === 'ready' && ! seoProposals[ r.id ];
		} );
	}

	function seoRenderSummary() {
		var ready   = seoCount( 'ready' );
		var skipped = seoCount( 'skipped' );
		var unused  = seoCount( 'unused' );
		var client  = seoRows.filter( function ( r ) { return r.needs_client; } ).length;

		var html = '<ul class="ism-seo-stats">'
			+ '<li><strong>' + ready + '</strong> ready to generate</li>'
			+ '<li><strong>' + skipped + '</strong> decorative or unsupported</li>'
			+ '<li><strong>' + unused + '</strong> found on no page</li>'
			+ '<li><strong>' + seoRows.length + '</strong> images total</li>'
			+ '</ul>';

		if ( client > 0 ) {
			html += '<p class="description">' + client + ' image(s) are in a format this server cannot read; the browser will convert them as it goes.</p>';
		}

		$( '#ism-seo-summary' ).html( html ).show();
		$( '#ism-seo-generate-card' ).toggle( ready > 0 );
	}

	// ── Groups that are listed but never generated for ──────────────────────

	function seoSimpleRow( r, note ) {
		var thumb = r.thumb
			? '<img src="' + esc( r.thumb ) + '" alt="" width="40" height="40" loading="lazy" />'
			: '<span class="ism-seo-nothumb"></span>';
		var name = r.edit_url
			? '<a href="' + esc( r.edit_url ) + '" target="_blank">' + esc( r.filename ) + '</a>'
			: esc( r.filename );

		return '<tr>'
			+ '<td class="ism-seo-thumb-cell">' + thumb + '</td>'
			+ '<td>' + name + '<br><span class="description">' + esc( r.current.title || '(no title)' ) + '</span></td>'
			+ '<td>' + esc( r.current.alt_text || '—' ) + '</td>'
			+ '<td>' + esc( note ) + '</td>'
			+ '</tr>';
	}

	function seoRenderGroups() {
		var skipped = seoRows.filter( function ( r ) { return r.group === 'skipped'; } );
		var unused  = seoRows.filter( function ( r ) { return r.group === 'unused'; } );

		function table( rows, noteFor ) {
			var html = '<table class="widefat ism-seo-table"><thead><tr>'
				+ '<th></th><th>File</th><th>Current alt text</th><th>Why</th>'
				+ '</tr></thead><tbody>';
			rows.forEach( function ( r ) { html += seoSimpleRow( r, noteFor( r ) ); } );
			return html + '</tbody></table>';
		}

		if ( skipped.length ) {
			$( '#ism-seo-skipped-list' ).html( table( skipped, function ( r ) {
				if ( r.skip_reason === 'svg' ) { return 'SVG — decorative'; }
				if ( r.skip_reason === 'icon_size' ) { return '64px or smaller — icon'; }
				return 'Unsupported format (' + r.mime + ')';
			} ) );
			$( '#ism-seo-skipped-card' ).show();
		} else {
			$( '#ism-seo-skipped-card' ).hide();
		}

		if ( unused.length ) {
			$( '#ism-seo-unused-list' ).html( table( unused, function () {
				return 'No page references it';
			} ) );
			$( '#ism-seo-unused-card' ).show();
		} else {
			$( '#ism-seo-unused-card' ).hide();
		}
	}

	// ── Review table ────────────────────────────────────────────────────────

	function seoField( id, key, label, value, current ) {
		var input = key === 'description'
			? '<textarea rows="3" class="large-text ism-seo-input" data-id="' + id + '" data-field="' + key + '">' + esc( value ) + '</textarea>'
			: '<input type="text" class="large-text ism-seo-input" data-id="' + id + '" data-field="' + key + '" value="' + esc( value ) + '" />';

		var was = current
			? '<span class="ism-seo-was">was: ' + esc( current ) + '</span>'
			: '<span class="ism-seo-was ism-seo-was-empty">was empty</span>';

		return '<div class="ism-seo-field"><label>' + esc( label ) + '</label>' + input + was + '</div>';
	}

	function seoRenderReview() {
		var ids = Object.keys( seoProposals );
		if ( ! ids.length ) {
			$( '#ism-seo-review-card' ).hide();
			$( '#ism-seo-review-list' ).empty();
			return;
		}

		var byId = {};
		seoRows.forEach( function ( r ) { byId[ r.id ] = r; } );

		var html = '';
		ids.forEach( function ( id ) {
			var p = seoProposals[ id ];
			var r = byId[ id ] || { id: id, filename: '#' + id, thumb: '', edit_url: '', used_on: [], current: { title: '', alt_text: '', description: '' } };

			if ( p.error ) {
				html += '<div class="ism-seo-review-row ism-seo-row-error">'
					+ '<div class="ism-seo-review-head">'
					+ ( r.thumb ? '<img src="' + esc( r.thumb ) + '" alt="" width="48" height="48" loading="lazy" />' : '' )
					+ '<div><strong>' + esc( r.filename ) + '</strong>'
					+ '<div class="ism-seo-error">' + esc( p.error ) + '</div></div></div></div>';
				return;
			}

			var usedOn = r.used_on && r.used_on.length
				? 'Appears on: ' + r.used_on.map( function ( t ) { return esc( t ); } ).join( ', ' )
					+ ( r.used_count > r.used_on.length ? ' +' + ( r.used_count - r.used_on.length ) + ' more' : '' )
				: '';

			html += '<div class="ism-seo-review-row" data-id="' + id + '">'
				+ '<div class="ism-seo-review-head">'
				+ '<label class="ism-seo-check"><input type="checkbox" class="ism-seo-row-check" data-id="' + id + '" checked /></label>'
				+ ( r.thumb ? '<img src="' + esc( r.thumb ) + '" alt="" width="48" height="48" loading="lazy" />' : '' )
				+ '<div class="ism-seo-review-meta">'
				+ ( r.edit_url ? '<a href="' + esc( r.edit_url ) + '" target="_blank"><strong>' + esc( r.filename ) + '</strong></a>' : '<strong>' + esc( r.filename ) + '</strong>' )
				+ ( usedOn ? '<div class="description">' + usedOn + '</div>' : '' )
				+ '</div></div>'
				+ '<div class="ism-seo-fields">'
				+ seoField( id, 'title', 'Title', p.title, r.current.title )
				+ seoField( id, 'alt_text', 'Alt text', p.alt_text, r.current.alt_text )
				+ seoField( id, 'description', 'Description', p.description, r.current.description )
				+ '</div></div>';
		} );

		$( '#ism-seo-review-list' ).html( html );
		$( '#ism-seo-review-card' ).show();
	}

	// Keep edits in the in-memory proposal so a re-render does not lose them.
	$( document ).on( 'input change', '.ism-seo-input', function () {
		var $f = $( this );
		var id = String( $f.data( 'id' ) );
		if ( seoProposals[ id ] ) {
			seoProposals[ id ][ $f.data( 'field' ) ] = $f.val();
		}
	} );

	$( document ).on( 'click', '#ism-seo-select-all', function () {
		$( '.ism-seo-row-check' ).prop( 'checked', true );
	} );
	$( document ).on( 'click', '#ism-seo-select-none', function () {
		$( '.ism-seo-row-check' ).prop( 'checked', false );
	} );

	// ── Generate ────────────────────────────────────────────────────────────

	function seoGenerateOne( row, retries, onDone ) {
		retries = retries || 0;

		function send( dataUrl ) {
			seoPost( 'ism_seo_generate', {
				attachment_id:  row.id,
				image_data_url: dataUrl || ''
			}, function ( res ) {
				if ( res.success ) {
					seoProposals[ String( row.id ) ] = res.data.proposal;
					onDone( null, res.data );
					return;
				}
				var d = res.data || {};
				if ( seoIsRetryable( d.code ) && retries < 3 ) {
					var delay = Math.pow( 2, retries + 1 ) * 1000;
					setTimeout( function () { seoGenerateOne( row, retries + 1, onDone ); }, delay );
					return;
				}
				seoProposals[ String( row.id ) ] = {
					title: '', alt_text: '', description: '',
					error: ( d.message || 'Generation failed' )
				};
				onDone( d.message || 'failed', null );
			}, function () {
				// No structured response at all — always worth a retry.
				if ( retries < 3 ) {
					var delay = Math.pow( 2, retries + 1 ) * 1000;
					setTimeout( function () { seoGenerateOne( row, retries + 1, onDone ); }, delay );
					return;
				}
				seoProposals[ String( row.id ) ] = {
					title: '', alt_text: '', description: '',
					error: 'Connection lost'
				};
				onDone( 'connection lost', null );
			} );
		}

		if ( row.needs_client && row.client_url ) {
			window.ismVision.convert( row.client_url, function ( dataUrl, err ) {
				if ( err ) {
					seoProposals[ String( row.id ) ] = {
						title: '', alt_text: '', description: '', error: err
					};
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
			seoRenderReview();
			return;
		}

		var row = queue[ index ];
		var pct = Math.round( ( index / queue.length ) * 100 );
		$( '#ism-seo-generate-bar' ).css( 'width', pct + '%' );
		$( '#ism-seo-generate-status' ).text(
			( index + 1 ) + ' / ' + queue.length + ' — ' + row.filename
			+ ( row.needs_client ? ' (converting in browser)' : '' )
		);

		seoGenerateOne( row, 0, function ( err, data ) {
			var $log = $( '#ism-seo-generate-log' ).show();
			if ( err ) {
				stats.failed++;
				$log.append( $( '<li>' ).addClass( 'ism-log-error' ).text( row.filename + ' — ' + err ) );
			} else {
				stats.ok++;
				if ( data && data.run_usage ) {
					stats.tokens = data.run_usage.total_tokens || stats.tokens;
				}
				$log.append( $( '<li>' ).text( row.filename + ' ✓' ) );
			}
			$log.scrollTop( $log[ 0 ].scrollHeight );

			// Re-render periodically so results are readable during a long run
			// rather than only at the end.
			if ( ( index + 1 ) % 5 === 0 ) {
				seoRenderReview();
			}

			setTimeout( function () { seoGenerateLoop( queue, index + 1, stats ); }, 250 );
		} );
	}

	$( document ).on( 'click', '#ism-seo-generate-start', function () {
		var limit = Math.max( 1, parseInt( $( '#ism-seo-limit' ).val(), 10 ) || 20 );
		var queue = seoPending().slice( 0, limit );

		if ( ! queue.length ) {
			$( '#ism-seo-generate-status' ).text( 'Nothing left to generate — every ready image already has a proposal.' );
			$( '#ism-seo-generate-progress' ).show();
			return;
		}

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

	// ── Apply ───────────────────────────────────────────────────────────────

	$( document ).on( 'click', '#ism-seo-apply', function () {
		var rows = [];
		$( '.ism-seo-row-check:checked' ).each( function () {
			var id = String( $( this ).data( 'id' ) );
			var p  = seoProposals[ id ];
			if ( ! p || p.error ) {
				return;
			}
			rows.push( {
				id:          id,
				title:       p.title,
				alt_text:    p.alt_text,
				description: p.description
			} );
		} );

		if ( ! rows.length ) {
			$( '.ism-seo-apply-status' ).text( 'No rows selected.' );
			return;
		}

		if ( ! window.confirm( 'Write ' + rows.length + ' image(s) to the media library? Existing values for the fields you filled in will be overwritten.' ) ) {
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
			rows.forEach( function ( r ) { delete seoProposals[ r.id ]; } );
			$( '.ism-seo-apply-status' ).text(
				'Applied ' + res.data.applied + ' image(s)'
				+ ( res.data.skipped ? ', skipped ' + res.data.skipped : '' ) + '.'
			);
			seoRenderReview();
		}, function () {
			$btn.prop( 'disabled', false );
			$( '.ism-seo-apply-status' ).text( 'Request failed — nothing was written.' );
		} );
	} );

	$( document ).on( 'click', '#ism-seo-discard', function () {
		if ( ! window.confirm( 'Discard every unapplied proposal? The tokens already spent on them are not refundable.' ) ) {
			return;
		}
		seoPost( 'ism_seo_reset', {}, function () {
			seoProposals = {};
			seoRenderReview();
			$( '.ism-seo-apply-status' ).text( 'Proposals discarded.' );
		} );
	} );

} )( jQuery );