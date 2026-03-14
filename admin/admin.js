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

        // ── Orphaned Files ────────────────────────────────────────────────────
        function orphanUpdateDeleteBtn() {
                var checked = $( '#ism-orphan-tbody input[type="checkbox"]:checked' ).length;
                $( '#ism-orphan-delete-btn' ).prop( 'disabled', checked === 0 );
        }

        $( '#ism-orphan-scan-btn' ).on( 'click', function () {
                var $btn    = $( this );
                var $status = $( '.ism-orphan-status' );

                $btn.prop( 'disabled', true );
                $status.text( 'Scanning\u2026' );
                $( '#ism-orphan-results' ).hide();
                $( '#ism-orphan-tbody' ).html( '' );
                $( '#ism-orphan-select-all' ).prop( 'checked', false );

                $.post( ismData.ajaxUrl, {
                        action: 'ism_orphan_scan',
                        nonce:  ismData.orphanNonce,
                }, function ( res ) {
                        $btn.prop( 'disabled', false );
                        if ( ! res.success ) {
                                $status.text( 'Error: ' + ( res.data || 'scan failed' ) );
                                return;
                        }
                        var count = res.data.count;
                        var size  = res.data.total_size;

                        if ( count === 0 ) {
                                $status.text( 'No orphaned files found.' );
                                return;
                        }

                        $status.text( '' );
                        $( '.ism-orphan-summary' ).html(
                                '<strong>' + count + '</strong> orphaned file' + ( count === 1 ? '' : 's' ) +
                                ' found &mdash; <strong>' + size + '</strong> total'
                        );

                        var rows = '';
                        $.each( res.data.orphans, function ( i, f ) {
                                rows += '<tr>' +
                                        '<td><input type="checkbox" class="ism-orphan-cb" data-path="' + esc( f.rel_path ) + '"></td>' +
                                        '<td><code>' + esc( f.rel_path ) + '</code></td>' +
                                        '<td>' + esc( f.filesize ) + '</td>' +
                                        '<td>' + esc( f.modified ) + '</td>' +
                                        '</tr>';
                        } );
                        $( '#ism-orphan-tbody' ).html( rows );
                        $( '#ism-orphan-results' ).show();
                        orphanUpdateDeleteBtn();
                } ).fail( function () {
                        $btn.prop( 'disabled', false );
                        $status.text( 'Request failed.' );
                } );
        } );

        $( '#ism-orphan-select-all' ).on( 'change', function () {
                $( '#ism-orphan-tbody input[type="checkbox"]' ).prop( 'checked', $( this ).is( ':checked' ) );
                orphanUpdateDeleteBtn();
        } );

        $( document ).on( 'change', '.ism-orphan-cb', function () {
                orphanUpdateDeleteBtn();
        } );

        $( '#ism-orphan-delete-btn' ).on( 'click', function () {
                var paths = [];
                $( '#ism-orphan-tbody input[type="checkbox"]:checked' ).each( function () {
                        paths.push( $( this ).data( 'path' ) );
                } );
                if ( paths.length === 0 ) { return; }

                // eslint-disable-next-line no-alert
                if ( ! confirm( 'Permanently delete ' + paths.length + ' file' + ( paths.length === 1 ? '' : 's' ) + '? This cannot be undone.' ) ) {
                        return;
                }

                var $btn    = $( this );
                var $status = $( '.ism-orphan-status' );
                $btn.prop( 'disabled', true );
                $status.text( 'Deleting\u2026' );

                $.post( ismData.ajaxUrl, {
                        action: 'ism_orphan_delete',
                        nonce:  ismData.orphanNonce,
                        paths:  paths,
                }, function ( res ) {
                        $btn.prop( 'disabled', false );
                        if ( ! res.success ) {
                                $status.text( 'Error during deletion.' );
                                return;
                        }
                        var deleted = res.data.deleted;
                        var errors  = res.data.errors;

                        // Remove successfully deleted rows
                        $( '#ism-orphan-tbody input[type="checkbox"]:checked' ).each( function () {
                                var path = $( this ).data( 'path' );
                                if ( deleted.indexOf( path ) !== -1 ) {
                                        $( this ).closest( 'tr' ).remove();
                                }
                        } );

                        var msg = deleted.length + ' file' + ( deleted.length === 1 ? '' : 's' ) + ' deleted.';
                        if ( errors.length > 0 ) { msg += ' ' + errors.length + ' could not be deleted.'; }
                        $status.text( msg );

                        $( '#ism-orphan-select-all' ).prop( 'checked', false );
                        orphanUpdateDeleteBtn();

                        var remaining = $( '#ism-orphan-tbody tr' ).length;
                        if ( remaining === 0 ) {
                                $( '.ism-orphan-summary' ).text( 'All selected files deleted.' );
                                $( '#ism-orphan-results' ).hide();
                        } else {
                                $( '.ism-orphan-summary' ).html(
                                        '<strong>' + remaining + '</strong> orphaned file' + ( remaining === 1 ? '' : 's' ) + ' remaining'
                                );
                        }
                } ).fail( function () {
                        $btn.prop( 'disabled', false );
                        $status.text( 'Request failed.' );
                } );
        } );

} )( jQuery );