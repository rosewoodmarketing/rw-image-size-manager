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
			$cancelBtn.show();
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
			$cancelBtn.show();
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
				var srcLabel  = u.source === 'elementor'
					? ' <span class="ism-source-label ism-source-elementor">Elementor</span>'
					: ' <span class="ism-source-label ism-source-content">Content</span>';
				if ( u.url ) {
					html += '<li><a href="' + u.url + '" target="_blank">' + safeTitle + '</a>' + srcLabel + '</li>';
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

} )( jQuery );