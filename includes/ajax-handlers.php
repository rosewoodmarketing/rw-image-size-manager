<?php
/**
 * AJAX handlers for RW Image Size Manager.
 *
 * Loaded by image-size-manager.php via require_once.
 * All functions rely on helpers defined in the main plugin file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Regeneration handlers
// ─────────────────────────────────────────────────────────────────────────────
/**
 * AJAX: initialise a regeneration run for a given CPT.
 * Collects all attachment IDs, stores them in a transient, returns the total.
 */
function ism_ajax_regen_init(): void {
	check_ajax_referer( 'ism_regen', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$cpt_key = sanitize_key( $_POST['cpt_key'] ?? '' );
	if ( ! $cpt_key ) {
		wp_send_json_error( 'Missing cpt_key' );
	}

	$transient_key = 'ism_regen_' . $cpt_key . '_' . get_current_user_id();
	$force_restart = ! empty( $_POST['force_restart'] );

	// If a previous run is saved and the caller just wants to resume, return it.
	if ( ! $force_restart ) {
		$existing = get_transient( $transient_key );
		if ( is_array( $existing ) && ! empty( $existing['ids'] ) ) {
			// Refresh TTL so it doesn't expire mid-resume.
			set_transient( $transient_key, $existing, DAY_IN_SECONDS );
			wp_send_json_success( [
				'transient_key' => $transient_key,
				'total'         => count( $existing['ids'] ),
				'batch_size'    => 3,
				'resumed'       => true,
			] );
		}
	}

	$ids = ism_collect_cpt_attachment_ids( $cpt_key );

	set_transient( $transient_key, [ 'ids' => $ids ], DAY_IN_SECONDS );

	wp_send_json_success( [
		'transient_key' => $transient_key,
		'total'         => count( $ids ),
		'batch_size'    => 3,
		'resumed'       => false,
	] );
}

/**
 * AJAX: process one batch of attachments.
 */
function ism_ajax_regen_batch(): void {
	check_ajax_referer( 'ism_regen', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	// Give each batch request its own generous time limit.
	@set_time_limit( 120 ); // phpcs:ignore

	$transient_key = sanitize_key( $_POST['transient_key'] ?? '' );
	$offset        = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$batch_size    = 3;
	$cpt_key       = sanitize_key( $_POST['cpt_key'] ?? '' );

	$saved = get_transient( $transient_key );

	// Support both old (plain array) and new ({ids, ...}) transient formats.
	if ( is_array( $saved ) && isset( $saved['ids'] ) ) {
		$ids = $saved['ids'];
	} elseif ( is_array( $saved ) ) {
		$ids = $saved;
	} else {
		wp_send_json_error( 'Session expired — please restart to regenerate.' );
	}

	$batch             = array_slice( $ids, $offset, $batch_size );
	$messages          = [];
	$batch_deleted     = 0;

	foreach ( $batch as $att_id ) {
		$att_id   = (int) $att_id;
		$filename = basename( get_attached_file( $att_id ) ?: "ID {$att_id}" );
		$result   = ism_regen_attachment( $att_id, $cpt_key );

		if ( is_wp_error( $result ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: " . $result->get_error_message() ];
		} else {
			$n             = (int) ( $result['deleted'] ?? 0 );
			$batch_deleted += $n;
			$suffix        = $n > 0 ? " ({$n} file" . ( $n === 1 ? '' : 's' ) . " deleted)" : '';
			$messages[]    = [ 'type' => 'ok', 'text' => $filename . $suffix ];
		}
	}

	$new_offset    = $offset + count( $batch );
	$done          = $new_offset >= count( $ids );

	// Accumulate total deleted count across all batches in the transient.
	$total_deleted = (int) ( is_array( $saved ) ? ( $saved['total_deleted'] ?? 0 ) : 0 ) + $batch_deleted;

	if ( $done ) {
		delete_transient( $transient_key );
	} else {
		// Refresh TTL on every batch so a long run never expires mid-way.
		set_transient( $transient_key, [ 'ids' => $ids, 'total_deleted' => $total_deleted ], DAY_IN_SECONDS );
	}

	wp_send_json_success( [
		'messages'      => $messages,
		'offset'        => $new_offset,
		'total'         => count( $ids ),
		'done'          => $done,
		'total_deleted' => $total_deleted,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Regenerate All Images (library-wide) handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: initialise a library-wide regeneration run.
 * Collects every image attachment ID, stores in a transient, supports resume.
 */
function ism_ajax_regen_all_init(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$transient_key = 'ism_regen_all_' . get_current_user_id();
	$force_restart = ! empty( $_POST['force_restart'] );

	// Resume a previous run if one exists and the caller didn't ask for a fresh start.
	if ( ! $force_restart ) {
		$existing = get_transient( $transient_key );
		if ( is_array( $existing ) && ! empty( $existing['ids'] ) ) {
			set_transient( $transient_key, $existing, DAY_IN_SECONDS );
			wp_send_json_success( [
				'transient_key' => $transient_key,
				'total'         => count( $existing['ids'] ),
				'offset'        => max( 0, (int) ( $existing['offset'] ?? 0 ) ),
				'total_deleted' => (int) ( $existing['total_deleted'] ?? 0 ),
				'resumed'       => true,
			] );
		}
	}

	$ids = get_posts( [
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$ids = array_map( 'intval', $ids );

	set_transient( $transient_key, [ 'ids' => $ids, 'offset' => 0, 'total_deleted' => 0 ], DAY_IN_SECONDS );

	wp_send_json_success( [
		'transient_key' => $transient_key,
		'total'         => count( $ids ),
		'offset'        => 0,
		'total_deleted' => 0,
		'resumed'       => false,
	] );
}

/**
 * AJAX: process one batch of attachments for library-wide regeneration.
 * Applies global settings and per-CPT restrictions when an attachment has
 * a parent post whose post type has rules configured.
 */
function ism_ajax_regen_all_batch(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$transient_key = sanitize_key( $_POST['transient_key'] ?? '' );
	$offset        = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$batch_size    = 3;

	$saved = get_transient( $transient_key );
	if ( ! is_array( $saved ) || ! isset( $saved['ids'] ) ) {
		wp_send_json_error( 'Session expired — please restart to regenerate.' );
	}

	$ids           = $saved['ids'];
	$saved_offset  = max( 0, (int) ( $saved['offset'] ?? 0 ) );
	if ( $saved_offset > $offset ) {
		$offset = $saved_offset;
	}
	$batch         = array_slice( $ids, $offset, $batch_size );
	$messages      = [];
	$batch_deleted = 0;

	foreach ( $batch as $att_id ) {
		$att_id   = (int) $att_id;
		$filename = basename( get_attached_file( $att_id ) ?: "ID {$att_id}" );

		// Resolve CPT context from parent, featured-image owners, or WC gallery owners.
		$cpt_key = ism_resolve_attachment_cpt_context( $att_id );
		$result    = ism_regen_attachment( $att_id, $cpt_key );

		if ( is_wp_error( $result ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: " . $result->get_error_message() ];
		} else {
			$n             = (int) ( $result['deleted'] ?? 0 );
			$batch_deleted += $n;
			$suffix        = $n > 0 ? " ({$n} file" . ( $n === 1 ? '' : 's' ) . ' deleted)' : '';
			$messages[]    = [ 'type' => 'ok', 'text' => $filename . $suffix ];
		}
	}

	$new_offset    = $offset + count( $batch );
	$done          = $new_offset >= count( $ids );
	$total_deleted = (int) ( $saved['total_deleted'] ?? 0 ) + $batch_deleted;

	if ( $done ) {
		delete_transient( $transient_key );
	} else {
		set_transient( $transient_key, [
			'ids'           => $ids,
			'offset'        => $new_offset,
			'total_deleted' => $total_deleted,
		], DAY_IN_SECONDS );
	}

	wp_send_json_success( [
		'messages'      => $messages,
		'offset'        => $new_offset,
		'total'         => count( $ids ),
		'done'          => $done,
		'total_deleted' => $total_deleted,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Media Log AJAX handler
// ─────────────────────────────────────────────────────────────────────────────

function ism_ajax_media_log(): void {
	check_ajax_referer( 'ism_media_log', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	$page    = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$search  = sanitize_text_field( $_POST['search'] ?? '' );
	$per     = 20;
	$offset  = ( $page - 1 ) * $per;

	$args = [
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'posts_per_page' => $per,
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
	];

	if ( $search !== '' ) {
		$args['s'] = $search;
	}

	$count_args              = $args;
	$count_args['posts_per_page'] = -1;
	$count_args['offset']    = 0;
	$total_ids               = get_posts( $count_args );
	$total                   = count( $total_ids );

	$ids = get_posts( $args );

	$upload_dir = wp_upload_dir();
	$base_dir   = trailingslashit( $upload_dir['basedir'] );

	$items = [];
	foreach ( $ids as $id ) {
		$meta     = wp_get_attachment_metadata( $id );
		$file     = get_attached_file( $id );
		$rel_file = str_replace( $base_dir, '', $file );

		$parent_id    = (int) get_post_field( 'post_parent', $id );
		$parent_title = $parent_id ? get_the_title( $parent_id ) : '';
		$parent_url   = $parent_id ? get_edit_post_link( $parent_id, 'raw' ) : '';

		$thumb_url = wp_get_attachment_image_url( $id, 'thumbnail' ) ?: '';

		$sizes_list = [];
		if ( ! empty( $meta['sizes'] ) ) {
			// Build directory of the original file
			$file_dir = $file ? trailingslashit( dirname( $file ) ) : '';
			foreach ( $meta['sizes'] as $size_key => $size_data ) {
				$size_path   = $file_dir . $size_data['file'];
				$exists      = $file_dir && file_exists( $size_path );
				$filesize    = $exists ? size_format( filesize( $size_path ) ) : '—';
				$sizes_list[] = [
					'key'      => $size_key,
					'file'     => $size_data['file'],
					'width'    => $size_data['width'],
					'height'   => $size_data['height'],
					'mime'     => $size_data['mime-type'] ?? '',
					'exists'   => $exists,
					'filesize' => $filesize,
				];
			}
		}

		// Original file info
		$orig_exists   = $file && file_exists( $file );
		$orig_filesize = $orig_exists ? size_format( filesize( $file ) ) : '—';

		$items[] = [
			'id'           => $id,
			'filename'     => basename( $file ),
			'rel_path'     => $rel_file,
			'thumb_url'    => $thumb_url,
			'orig_width'   => $meta['width'] ?? 0,
			'orig_height'  => $meta['height'] ?? 0,
			'orig_filesize'=> $orig_filesize,
			'orig_exists'  => $orig_exists,
			'parent_title' => $parent_title,
			'parent_url'   => $parent_url,
			'date'         => get_the_date( 'Y-m-d', $id ),
			'edit_url'     => get_edit_post_link( $id, 'raw' ),
			'sizes'        => $sizes_list,
		];
	}

	wp_send_json_success( [
		'items'      => $items,
		'total'      => $total,
		'page'       => $page,
		'per'        => $per,
		'total_pages'=> (int) ceil( $total / $per ),
	] );
}



// ─────────────────────────────────────────────────────────────────────────────
// Bulk resize existing images to the configured max-upload dimensions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: collect all image attachment IDs that exceed the current max-upload dims.
 */
function ism_ajax_bulk_resize_init(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$s     = ism_get_settings();
	$max_w = (int) $s['max_upload_width'];
	$max_h = (int) $s['max_upload_height'];

	if ( $max_w <= 0 && $max_h <= 0 ) {
		wp_send_json_error( 'No max upload dimensions are configured. Set them in the Max Upload Dimensions section first.' );
	}

	$ids = get_posts( [
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$transient_key = 'ism_bulk_resize_' . get_current_user_id();
	set_transient( $transient_key, [ 'ids' => array_map( 'intval', $ids ) ], DAY_IN_SECONDS );

	wp_send_json_success( [
		'transient_key' => $transient_key,
		'total'         => count( $ids ),
	] );
}

/**
 * AJAX: process one batch – resize any image that exceeds the max-upload dims.
 */
function ism_ajax_bulk_resize_batch(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$transient_key = sanitize_key( $_POST['transient_key'] ?? '' );
	$offset        = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$batch_size    = 5;

	$saved = get_transient( $transient_key );
	if ( ! is_array( $saved ) || empty( $saved['ids'] ) ) {
		wp_send_json_error( 'Session expired — please restart.' );
	}

	$ids      = $saved['ids'];
	$batch    = array_slice( $ids, $offset, $batch_size );
	$messages = [];
	$resized  = 0;

	$s     = ism_get_settings();
	$max_w = (int) $s['max_upload_width'];
	$max_h = (int) $s['max_upload_height'];

	foreach ( $batch as $att_id ) {
		$att_id   = (int) $att_id;
		$file     = get_attached_file( $att_id );
		$filename = $file ? basename( $file ) : "ID {$att_id}";

		// When WordPress previously ran its big-image threshold it created a
		// -scaled copy and updated _wp_attached_file to point to that copy.
		// get_attached_file() therefore returns the -scaled version, which may
		// already be within the limits — causing the true original (which is
		// still oversized on disk) to be silently skipped.  WordPress stores
		// the original filename in _wp_attachment_metadata['original_image'],
		// so use that as the resize target when it exists.
		$att_meta    = wp_get_attachment_metadata( $att_id );
		$scaled_file = null;
		if ( ! is_array( $att_meta ) ) {
			$att_meta = [];
		}

		$current_file = $file;
		if ( ! empty( $att_meta['original_image'] ) ) {
			$upload_dir   = wp_upload_dir();
			$current_rel  = get_post_meta( $att_id, '_wp_attached_file', true );
			$current_dir  = $file ? dirname( $file ) : '';
			if ( ! $current_dir && ! empty( $att_meta['file'] ) ) {
				$current_dir = dirname( path_join( $upload_dir['basedir'], $att_meta['file'] ) );
			}
			if ( ! $current_dir && $current_rel ) {
				$current_dir = dirname( path_join( $upload_dir['basedir'], $current_rel ) );
			}
			$orig_path = path_join( $current_dir ?: $upload_dir['basedir'], $att_meta['original_image'] );
			if ( file_exists( $orig_path ) ) {
				$scaled_file = $current_file; // will be cleaned up after a successful resize
				$file        = $orig_path;
				$filename    = basename( $file );
			}
		}

		if ( ! $file || ! file_exists( $file ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: file not found on disk" ];
			continue;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: " . $editor->get_error_message() ];
			continue;
		}

		$size   = $editor->get_size();
		$orig_w = (int) $size['width'];
		$orig_h = (int) $size['height'];

		$over_w = $max_w > 0 && $orig_w > $max_w;
		$over_h = $max_h > 0 && $orig_h > $max_h;

		if ( ! $over_w && ! $over_h ) {
			$messages[] = [ 'type' => 'ok', 'text' => "{$filename}: already within limits ({$orig_w}×{$orig_h})" ];
			continue;
		}

		$result = $editor->resize( $max_w > 0 ? $max_w : null, $max_h > 0 ? $max_h : null, false );
		if ( is_wp_error( $result ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: " . $result->get_error_message() ];
			continue;
		}

		$saved_file = $editor->save( $file );
		if ( is_wp_error( $saved_file ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: " . $saved_file->get_error_message() ];
			continue;
		}

		// Refresh attachment metadata so the library reflects the new dimensions.
		$meta = wp_generate_attachment_metadata( $att_id, $file );
		wp_update_attachment_metadata( $att_id, $meta );

		// If we resized the original in place of a -scaled copy, repoint
		// _wp_attached_file to the original and delete the stale -scaled file.
		if ( $scaled_file ) {
			// Every page that already inserted the -scaled URL has to follow the
			// file, or it is left pointing at something this loop is about to
			// delete. Repointing the media library alone is what silently broke
			// images on the pilot site: the row moved, the pages did not.
			$repointed = ism_repoint_after_file_move( $att_id, $scaled_file, $file );
			if ( $repointed['content'] || $repointed['elementor'] ) {
				$messages[] = [
					'type' => 'ok',
					'text' => sprintf(
						'%s: repointed %d reference(s) across %d page(s)',
						$filename,
						$repointed['content'] + $repointed['elementor'],
						count( $repointed['posts'] )
					),
				];
			}

			$relative = _wp_relative_upload_path( $file );
			if ( $relative ) {
				update_post_meta( $att_id, '_wp_attached_file', $relative );
			}

			// Keep attachment mime in sync with the true canonical file.
			$filetype = wp_check_filetype( basename( $file ) );
			if ( ! empty( $filetype['type'] ) ) {
				wp_update_post( [
					'ID'             => $att_id,
					'post_mime_type' => $filetype['type'],
				] );
			}

			if ( $scaled_file !== $file && strpos( basename( $scaled_file ), '-scaled.' ) !== false ) {
				$real_scaled_del = realpath( $scaled_file );
				$uploads_real    = realpath( wp_upload_dir()['basedir'] );
				if ( $real_scaled_del && $uploads_real
					&& strpos( $real_scaled_del, trailingslashit( $uploads_real ) ) === 0
					&& file_exists( $real_scaled_del ) ) {
					@unlink( $real_scaled_del ); // phpcs:ignore
				}
			}
		}

		$new_size = $editor->get_size();
		$new_w    = (int) $new_size['width'];
		$new_h    = (int) $new_size['height'];
		$resized++;
		$messages[] = [ 'type' => 'ok', 'text' => "{$filename}: resized {$orig_w}×{$orig_h} → {$new_w}×{$new_h}" ];
	}

	$new_offset = $offset + count( $batch );
	$done       = $new_offset >= count( $ids );

	$total_resized = (int) ( $saved['total_resized'] ?? 0 ) + $resized;

	if ( $done ) {
		delete_transient( $transient_key );
	} else {
		set_transient( $transient_key, [ 'ids' => $ids, 'total_resized' => $total_resized ], DAY_IN_SECONDS );
	}

	wp_send_json_success( [
		'messages'      => $messages,
		'offset'        => $new_offset,
		'total'         => count( $ids ),
		'done'          => $done,
		'total_resized' => $total_resized,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Remove WordPress -scaled images from the library
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: collect all attachment IDs whose stored file path contains -scaled.
 */
function ism_ajax_descale_init(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT post_id, meta_value
		FROM {$wpdb->postmeta}
		WHERE meta_key = '_wp_attachment_metadata'"
	);

	$ids = [];
	foreach ( $rows as $row ) {
		$meta = maybe_unserialize( $row->meta_value );
		if ( isset( $meta['file'] ) && strpos( $meta['file'], '-scaled.' ) !== false ) {
			$ids[] = (int) $row->post_id;
		}
	}

	$transient_key = 'ism_descale_' . get_current_user_id();
	set_transient( $transient_key, [ 'ids' => $ids ], DAY_IN_SECONDS );

	wp_send_json_success( [
		'transient_key' => $transient_key,
		'total'         => count( $ids ),
	] );
}

/**
 * AJAX: process one batch – delete the -scaled file and repoint metadata to the original.
 */
function ism_ajax_descale_batch(): void {
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$transient_key = sanitize_key( $_POST['transient_key'] ?? '' );
	$offset        = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$batch_size    = 10;

	$saved = get_transient( $transient_key );
	if ( ! is_array( $saved ) || ! isset( $saved['ids'] ) ) {
		wp_send_json_error( 'Session expired — please restart.' );
	}

	$ids      = $saved['ids'];
	$batch    = array_slice( $ids, $offset, $batch_size );
	$messages = [];
	$cleaned  = 0;

	$uploads_dir = wp_upload_dir();
	$base_dir    = trailingslashit( $uploads_dir['basedir'] );
	$base_real   = realpath( $uploads_dir['basedir'] );

	foreach ( $batch as $att_id ) {
		$att_id = (int) $att_id;
		$meta   = wp_get_attachment_metadata( $att_id );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "ID {$att_id}: no metadata" ];
			continue;
		}

		$attached      = (string) get_post_meta( $att_id, '_wp_attached_file', true );
		$meta_file_rel = (string) $meta['file'];

		$scaled_rel = '';
		if ( strpos( $meta_file_rel, '-scaled.' ) !== false ) {
			$scaled_rel = $meta_file_rel;
		} elseif ( $attached && strpos( $attached, '-scaled.' ) !== false ) {
			$scaled_rel = $attached;
		}

		$dir_rel = '';
		if ( $meta_file_rel ) {
			$dir_rel = dirname( $meta_file_rel );
		} elseif ( $attached ) {
			$dir_rel = dirname( $attached );
		}

		$original_rel = '';
		if ( ! empty( $meta['original_image'] ) ) {
			$original_rel = ( $dir_rel && '.' !== $dir_rel )
				? trailingslashit( $dir_rel ) . $meta['original_image']
				: $meta['original_image'];
		}

		if ( ! $original_rel && $scaled_rel ) {
			$original_rel = str_replace( '-scaled.', '.', $scaled_rel );
		}

		if ( ! $original_rel ) {
			$messages[] = [ 'type' => 'ok', 'text' => "ID {$att_id}: no -scaled/original mapping found (skipped)" ];
			continue;
		}

		$original_path = path_join( $base_dir, $original_rel );
		$real_original = realpath( $original_path );
		if ( ! $real_original || strpos( $real_original, $base_real ) !== 0 || ! file_exists( $real_original ) ) {
			$messages[] = [ 'type' => 'error', 'text' => basename( $meta_file_rel ?: $attached ?: "ID {$att_id}" ) . ": original file not found at " . basename( $original_path ) ];
			continue;
		}

		$scaled_path = $scaled_rel ? path_join( $base_dir, $scaled_rel ) : '';
		$real_scaled = $scaled_path ? realpath( $scaled_path ) : false;

		// Repoint metadata to the original file before deleting anything.
		$meta['file'] = $original_rel;
		unset( $meta['original_image'] );
		if ( $img_size = wp_getimagesize( $real_original ) ) {
			$meta['width']  = (int) $img_size[0];
			$meta['height'] = (int) $img_size[1];
		}
		if ( file_exists( $real_original ) ) {
			$meta['filesize'] = (int) filesize( $real_original );
		}
		wp_update_attachment_metadata( $att_id, $meta );

		// Repoint _wp_attached_file too.
		// Same reasoning as the bulk resize path: pages holding the -scaled URL
		// must follow the file before it is unlinked below, or this tool deletes
		// a file that live content still points at.
		if ( $real_scaled && $real_scaled !== $real_original ) {
			$repointed = ism_repoint_after_file_move( $att_id, $real_scaled, $real_original );
			if ( $repointed['content'] || $repointed['elementor'] ) {
				$messages[] = [
					'type' => 'ok',
					'text' => sprintf(
						'%s: repointed %d reference(s) across %d page(s)',
						basename( $original_rel ),
						$repointed['content'] + $repointed['elementor'],
						count( $repointed['posts'] )
					),
				];
			}
		}

		update_post_meta( $att_id, '_wp_attached_file', $original_rel );

		// Keep attachment mime in sync with the repointed file.
		$filetype = wp_check_filetype( basename( $real_original ) );
		if ( ! empty( $filetype['type'] ) ) {
			wp_update_post( [
				'ID'             => $att_id,
				'post_mime_type' => $filetype['type'],
			] );
		}

		if ( $real_scaled && strpos( $real_scaled, $base_real ) === 0 && file_exists( $real_scaled ) && $real_scaled !== $real_original ) {
			@unlink( $real_scaled ); // phpcs:ignore
		}

		$cleaned++;
		$messages[] = [ 'type' => 'ok', 'text' => basename( $meta_file_rel ?: $attached ?: "ID {$att_id}" ) . ': attachment repointed to original, metadata updated' ];
	}

	$new_offset    = $offset + count( $batch );
	$done          = $new_offset >= count( $ids );
	$total_cleaned = (int) ( $saved['total_cleaned'] ?? 0 ) + $cleaned;

	if ( $done ) {
		delete_transient( $transient_key );
	} else {
		set_transient( $transient_key, [ 'ids' => $ids, 'total_cleaned' => $total_cleaned ], DAY_IN_SECONDS );
	}

	wp_send_json_success( [
		'messages'      => $messages,
		'offset'        => $new_offset,
		'total'         => count( $ids ),
		'done'          => $done,
		'total_cleaned' => $total_cleaned,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Image size usage scanner
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: scan all published content to report which registered image sizes
 * are actually referenced, and classify them as core / in_use / plugin / unused.
 *
 * Sources scanned:
 *  - post_content: Gutenberg block "sizeSlug", classic-editor CSS classes (size-*,
 *    attachment-*), gallery shortcode [gallery size="..."]
 *  - _elementor_data: any JSON key ending in _size whose value is a size slug
 *    (covers image_size, thumbnail_size, background_image_size, etc.)
 *
 * File counts come from _wp_attachment_metadata so no filesystem access is needed.
 */
function ism_ajax_size_usage_scan(): void {
	check_ajax_referer( 'ism_size_usage_scan', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	global $wpdb;

	$registered = ism_get_all_registered_sizes();

	// WordPress admin always needs thumbnail regardless of content usage.
	$always_needed = [ 'thumbnail' ];

	// Known plugin-registered sizes used in PHP templates (not detectable from DB content).
	$plugin_template_sizes = [
		'woocommerce_thumbnail'         => 'WooCommerce',
		'woocommerce_single'            => 'WooCommerce',
		'woocommerce_gallery_thumbnail' => 'WooCommerce',
		'wc_gallery_thumbnail'          => 'WooCommerce',
		'shop_catalog'                  => 'WooCommerce (legacy)',
		'shop_single'                   => 'WooCommerce (legacy)',
		'shop_thumbnail'                => 'WooCommerce (legacy)',
	];

	// Initialise the usage map.
	$usage = [];
	foreach ( $registered as $slug => $size_data ) {
		$usage[ $slug ] = [
			'width'       => $size_data['width'],
			'height'      => $size_data['height'],
			'crop'        => $size_data['crop'],
			'post_content' => 0,
			'elementor'   => 0,
			'total'       => 0,
			'file_count'  => 0,
			'status'      => 'unused',
			'plugin_note' => $plugin_template_sizes[ $slug ] ?? '',
		];
	}

	// ── Step 1: scan post_content ──────────────────────────────────────────────
	$posts = $wpdb->get_results(
		"SELECT ID, post_content
		FROM {$wpdb->posts}
		WHERE post_status IN ('publish','draft','private','future')
		AND post_content != ''
		AND post_type NOT IN ('attachment','revision')"
	);

	$posts_scanned = count( $posts );

	// Track unique post IDs per size for "where used" detail view.
	$size_posts = [];

	foreach ( $posts as $post ) {
		$content = $post->post_content;

		// Collect all matched size slugs for this post into a local set so a
		// single post is never counted more than once per size, even if multiple
		// patterns match it (e.g. Gutenberg saves both sizeSlug JSON and a
		// size-* CSS class in the same post_content).
		$found = [];

		// Gutenberg block: {"sizeSlug":"large"}
		if ( preg_match_all( '/"sizeSlug"\s*:\s*"([\w-]+)"/', $content, $m ) ) {
			foreach ( $m[1] as $slug ) {
				if ( isset( $usage[ $slug ] ) ) {
					$found[ $slug ] = true;
				}
			}
		}

		// Classic editor / block HTML: class="size-large" or class="attachment-large"
		if ( preg_match_all( '/\bclass=["\'][^"\']*\b(?:size|attachment)-([\w-]+)\b/', $content, $m ) ) {
			foreach ( $m[1] as $slug ) {
				if ( isset( $usage[ $slug ] ) ) {
					$found[ $slug ] = true;
				}
			}
		}

		// Gallery shortcode: [gallery ... size="thumbnail"]
		if ( preg_match_all( '/\[gallery[^\]]*\bsize=["\']?([\w-]+)/', $content, $m ) ) {
			foreach ( $m[1] as $slug ) {
				if ( isset( $usage[ $slug ] ) ) {
					$found[ $slug ] = true;
				}
			}
		}

		// Record once per post per slug.
		foreach ( array_keys( $found ) as $slug ) {
			$usage[ $slug ]['post_content']++;
			$size_posts[ $slug ][ $post->ID ] = 'content';
		}
	}

	// ── Step 2: scan Elementor widget data ────────────────────────────────────
	// Elementor stores size selections in _elementor_data JSON. Most widgets
	// store e.g. "image_size":"medium" explicitly, but the built-in Image widget
	// omits "image_size" entirely when it equals its default value of "large".
	// Regex alone cannot detect the absence of a key, so we use json_decode and
	// a recursive walker that applies per-widget-type defaults.
	$elementor_rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = '_elementor_data'
		AND pm.meta_value != ''
		AND p.post_status IN ('publish','draft','private','future')
		AND p.post_type NOT IN ('attachment','revision')"
	);

	$elementor_pages = count( $elementor_rows );

	/**
	 * Recursively walk an Elementor elements tree and collect all image-size
	 * slugs referenced by widgets on the page into $found (keyed by slug).
	 *
	 * Widget-type defaults applied:
	 *   image  → "large"  (Elementor omits image_size when it equals large)
	 */
	$walk_elementor = function ( array $elements, array &$found ) use ( &$walk_elementor ): void {
		foreach ( $elements as $element ) {
			// Recurse into nested containers / sections / columns.
			if ( ! empty( $element['elements'] ) ) {
				$walk_elementor( $element['elements'], $found );
			}

			if ( empty( $element['widgetType'] ) || empty( $element['settings'] ) ) {
				continue;
			}

			$settings = $element['settings'];

			if ( $element['widgetType'] === 'image' ) {
				// Default is 'large'; only omitted from storage when equal to default.
				$found[ $settings['image_size'] ?? 'large' ] = true;
			}

			// All other widgets: scan every setting key that ends in "_size".
			foreach ( $settings as $key => $value ) {
				if ( is_string( $value ) && $value !== '' && str_ends_with( $key, '_size' ) ) {
					$found[ $value ] = true;
				}
			}
		}
	};

	foreach ( $elementor_rows as $row ) {
		$elements = json_decode( $row->meta_value, true );
		if ( ! is_array( $elements ) ) {
			// Fall back to regex if JSON is invalid / compressed.
			$found = [];
			if ( preg_match_all( '/"[\w_]*_?size"\s*:\s*"([\w-]+)"/', $row->meta_value, $m ) ) {
				foreach ( $m[1] as $slug ) {
					if ( isset( $usage[ $slug ] ) ) {
						$found[ $slug ] = true;
					}
				}
			}
		} else {
			$found = [];
			$walk_elementor( $elements, $found );
			// Keep only slugs that are registered sizes.
			$found = array_intersect_key( $found, $usage );
		}
		foreach ( array_keys( $found ) as $slug ) {
			$usage[ $slug ]['elementor']++;
			if ( ! isset( $size_posts[ $slug ][ $row->post_id ] ) ) {
				$size_posts[ $slug ][ $row->post_id ] = 'elementor';
			}
		}
	}

	// ── Build post title map for "where used" detail view ────────────────────
	$all_used_ids = [];
	foreach ( $size_posts as $id_map ) {
		foreach ( array_keys( $id_map ) as $pid ) {
			$all_used_ids[ $pid ] = true;
		}
	}
	$post_map = [];
	if ( ! empty( $all_used_ids ) ) {
		$ids_in      = implode( ',', array_fill( 0, count( $all_used_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- format string is built from %d placeholders only
		$post_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ids_in)", ...array_keys( $all_used_ids ) ) );
		foreach ( $post_rows as $pr ) {
			$post_map[ (int) $pr->ID ] = [
				'title' => $pr->post_title ?: '(no title)',
				'url'   => get_edit_post_link( (int) $pr->ID, 'raw' ) ?: '',
			];
		}
	}

	// ── Step 3: count generated files per size from attachment metadata ──────
	$meta_rows = $wpdb->get_col(
		"SELECT meta_value
		FROM {$wpdb->postmeta}
		WHERE meta_key = '_wp_attachment_metadata'"
	);

	foreach ( $meta_rows as $raw ) {
		$meta = maybe_unserialize( $raw );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			continue;
		}
		foreach ( $meta['sizes'] as $size_key => $size_data ) {
			if ( isset( $usage[ $size_key ] ) ) {
				$usage[ $size_key ]['file_count']++;
			}
		}
	}

	// ── Step 4: classify ──────────────────────────────────────────────────────
	foreach ( $usage as $slug => &$data ) {
		$data['total'] = $data['post_content'] + $data['elementor'];
		if ( in_array( $slug, $always_needed, true ) ) {
			$data['status'] = 'core';
		} elseif ( $data['plugin_note'] !== '' ) {
			$data['status'] = 'plugin';
		} elseif ( $data['total'] > 0 ) {
			$data['status'] = 'in_use';
		} else {
			$data['status'] = 'unused';
		}
		// Build per-post usage list for "where used" detail view.
		$data['usages'] = [];
		if ( isset( $size_posts[ $slug ] ) ) {
			foreach ( $size_posts[ $slug ] as $pid => $source ) {
				$data['usages'][] = [
					'id'     => (int) $pid,
					'source' => $source,
					'title'  => $post_map[ (int) $pid ]['title'] ?? '(post #' . $pid . ')',
					'url'    => $post_map[ (int) $pid ]['url']   ?? '',
				];
			}
		}
	}
	unset( $data );

	// Sort: core → in_use → plugin → unused; within each group by file_count desc.
	$order = [ 'core' => 0, 'in_use' => 1, 'plugin' => 2, 'unused' => 3 ];
	uasort( $usage, function ( $a, $b ) use ( $order ) {
		$oa = $order[ $a['status'] ] ?? 9;
		$ob = $order[ $b['status'] ] ?? 9;
		if ( $oa !== $ob ) {
			return $oa <=> $ob;
		}
		return $b['file_count'] <=> $a['file_count'];
	} );

	wp_send_json_success( [
		'sizes'           => $usage,
		'posts_scanned'   => $posts_scanned,
		'elementor_pages' => $elementor_pages,
	] );
}
