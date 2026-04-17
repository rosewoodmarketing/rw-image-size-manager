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
// Admin menu


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

		if ( strpos( $meta['file'], '-scaled.' ) === false ) {
			$messages[] = [ 'type' => 'ok', 'text' => "ID {$att_id}: no -scaled file (skipped)" ];
			continue;
		}

		$scaled_path   = $base_dir . $meta['file'];
		$original_file = str_replace( '-scaled.', '.', $meta['file'] );
		$original_path = $base_dir . $original_file;
		$filename      = basename( $meta['file'] );

		// Safety: both paths must resolve inside uploads.
		$real_scaled   = realpath( $scaled_path );
		$real_original = realpath( $original_path );

		if ( $real_scaled && strpos( $real_scaled, $base_real ) === 0 && file_exists( $real_scaled ) ) {
			@unlink( $real_scaled ); // phpcs:ignore
		}

		if ( ! $real_original || ! file_exists( $real_original ) ) {
			$messages[] = [ 'type' => 'error', 'text' => "{$filename}: original file not found at " . basename( $original_path ) ];
			continue;
		}

		// Repoint metadata to the original file.
		$meta['file'] = $original_file;
		wp_update_attachment_metadata( $att_id, $meta );

		// Repoint _wp_attached_file too.
		$attached = get_post_meta( $att_id, '_wp_attached_file', true );
		if ( $attached && strpos( $attached, '-scaled.' ) !== false ) {
			update_post_meta( $att_id, '_wp_attached_file', str_replace( '-scaled.', '.', $attached ) );
		}

		$cleaned++;
		$messages[] = [ 'type' => 'ok', 'text' => "{$filename}: -scaled removed, metadata updated" ];
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
	check_ajax_referer( 'ism_bulk_resize', 'nonce' );
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
		$ids_in    = implode( ',', array_map( 'intval', array_keys( $all_used_ids ) ) );
		$post_rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ids_in)" ); // phpcs:ignore WordPress.DB.PreparedSQL
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
