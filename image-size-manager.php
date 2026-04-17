<?php
/**
 * Plugin Name:       RW Image Size Manager
 * Plugin URI:        https://github.com/rosewoodmarketing/rw-image-size-manager
 * Description:       View, toggle, customize, and add image sizes. Control WooCommerce product image generation and auto-delete product images on trash deletion.
 * Version:           1.2.0
 * Author:            Anthony Burkholder
 * License:           GPL-2.0+
 * Text Domain:       image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ISM_VERSION',     '1.2.0' );
define( 'ISM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ISM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ISM_OPTION_KEY',  'ism_settings' );

// ─────────────────────────────────────────────────────────────────────────────
// GitHub Updater — checks for new releases and surfaces them in WP Admin > Updates
// Change these two constants if you rename the GitHub repo or transfer ownership.
// ─────────────────────────────────────────────────────────────────────────────
define( 'ISM_GITHUB_USER', 'rosewoodmarketing' );
define( 'ISM_GITHUB_REPO', 'rw-image-size-manager' );

require_once ISM_PLUGIN_DIR . 'includes/class-ism-github-updater.php';
require_once ISM_PLUGIN_DIR . 'includes/ajax-handlers.php';

if ( is_admin() ) {
	new ISM_GitHub_Updater( __FILE__, ISM_GITHUB_USER, ISM_GITHUB_REPO );
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu',           'ism_add_admin_menu' );
add_action( 'admin_enqueue_scripts','ism_enqueue_assets' );
add_action( 'admin_init',           'ism_register_settings' );
add_action( 'admin_post_ism_save',  'ism_handle_save' );

// Filter image sub-sizes before they are generated
add_filter( 'intermediate_image_sizes_advanced', 'ism_filter_image_sizes', 10, 3 );

// Remove disabled sizes from editor image-size dropdowns (Elementor, block editor, classic editor)
add_filter( 'image_size_names_choose', 'ism_filter_choosable_sizes', 10, 1 );

// Inject custom size data into the JS attachment payload so Elementor editor preview works
add_filter( 'wp_prepare_attachment_for_js', 'ism_prepare_attachment_for_js', 10, 3 );

// Delete post-type attachments when a post is permanently deleted from trash
add_action( 'before_delete_post', 'ism_delete_post_type_images', 10, 1 );

// AJAX: init + batch regeneration
add_action( 'wp_ajax_ism_regen_init',  'ism_ajax_regen_init' );
add_action( 'wp_ajax_ism_regen_batch', 'ism_ajax_regen_batch' );

// AJAX: media log
add_action( 'wp_ajax_ism_media_log', 'ism_ajax_media_log' );

// AJAX: bulk resize existing images to max-upload dims + remove -scaled files
add_action( 'wp_ajax_ism_bulk_resize_init',   'ism_ajax_bulk_resize_init' );
add_action( 'wp_ajax_ism_bulk_resize_batch',  'ism_ajax_bulk_resize_batch' );
add_action( 'wp_ajax_ism_descale_init',       'ism_ajax_descale_init' );
add_action( 'wp_ajax_ism_descale_batch',      'ism_ajax_descale_batch' );

// AJAX: image size usage scanner
add_action( 'wp_ajax_ism_size_usage_scan',    'ism_ajax_size_usage_scan' );

// Resize uploaded image originals to a configured maximum & suppress WP's own -scaled logic
add_filter( 'wp_handle_upload',         'ism_handle_upload_resize', 10, 2 );
add_filter( 'big_image_size_threshold', 'ism_big_image_threshold',  10, 1 );

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return saved settings array with defaults merged in.
 */
function ism_get_settings(): array {
	$defaults = [
		'disabled_sizes'   => [],  // array of size keys to suppress globally
		'custom_sizes'     => [],  // [ ['name'=>'', 'width'=>0, 'height'=>0, 'crop'=>false], … ]
		'post_type_rules'  => [],  // [ 'cpt_key' => ['restrict_enabled'=>'0','allowed_sizes'=>[],'delete_images'=>'0'] ]
		'max_upload_width' => 0,   // resize original on upload if wider than this (0 = disabled)
		'max_upload_height'=> 0,   // resize original on upload if taller than this (0 = disabled)
	];

	$saved = get_option( ISM_OPTION_KEY, [] );

	// Migrate legacy woo-specific keys → post_type_rules['product']
	if ( isset( $saved['woo_restrict_enabled'] ) && ! isset( $saved['post_type_rules']['product'] ) ) {
		if ( ! isset( $saved['post_type_rules'] ) ) {
			$saved['post_type_rules'] = [];
		}
		$saved['post_type_rules']['product'] = [
			'restrict_enabled' => $saved['woo_restrict_enabled'],
			'allowed_sizes'    => (array) ( $saved['woo_product_sizes'] ?? [] ),
			'delete_images'    => $saved['woo_delete_images'] ?? '1',
		];
		unset( $saved['woo_restrict_enabled'], $saved['woo_product_sizes'], $saved['woo_delete_images'] );
		update_option( ISM_OPTION_KEY, $saved );
	}

	return wp_parse_args( $saved, $defaults );
}

/**
 * Save settings to the database.
 */
function ism_save_settings( array $settings ): void {
	update_option( ISM_OPTION_KEY, $settings );
}

/**
 * Return all registered image sizes (WordPress core + theme/plugin registered sizes).
 * Returns: [ 'size_key' => ['width'=>int,'height'=>int,'crop'=>bool], … ]
 */
function ism_get_all_registered_sizes(): array {
	global $_wp_additional_image_sizes;

	$sizes = [];

	// Built-in sizes
	foreach ( [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ] as $key ) {
		$sizes[ $key ] = [
			'width'  => (int) get_option( "{$key}_size_w", 0 ),
			'height' => (int) get_option( "{$key}_size_h", 0 ),
			'crop'   => (bool) get_option( "{$key}_crop", false ),
		];
	}

	// Additional registered sizes
	if ( ! empty( $_wp_additional_image_sizes ) ) {
		foreach ( $_wp_additional_image_sizes as $key => $data ) {
			$sizes[ $key ] = [
				'width'  => (int) ( $data['width']  ?? 0 ),
				'height' => (int) ( $data['height'] ?? 0 ),
				'crop'   => (bool) ( $data['crop']   ?? false ),
			];
		}
	}

	return $sizes;
}

/**
 * Return all non-built-in public custom post types.
 * Returns: [ 'cpt_key' => ['label' => 'Singular Label'], … ]
 */
function ism_get_registered_cpts(): array {
	// Post types that serve no purpose in image-size management.
	$excluded = [
		'elementor_library',
		'e-floating-buttons',
		'e-landing-page',
		'e-contact-form',
	];

	$raw  = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
	$cpts = [];
	foreach ( $raw as $key => $obj ) {
		if ( in_array( $key, $excluded, true ) ) {
			continue;
		}
		$cpts[ $key ] = [
			'label' => ! empty( $obj->labels->singular_name )
				? $obj->labels->singular_name
				: $obj->label,
		];
	}
	return $cpts;
}

// ─────────────────────────────────────────────────────────────────────────────
// Register custom sizes on init so they appear everywhere
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'ism_register_custom_sizes' );

function ism_register_custom_sizes(): void {
	$settings = ism_get_settings();
	foreach ( $settings['custom_sizes'] as $size ) {
		$name = sanitize_key( $size['name'] ?? '' );
		if ( ! $name ) {
			continue;
		}
		add_image_size(
			$name,
			(int) ( $size['width']  ?? 0 ),
			(int) ( $size['height'] ?? 0 ),
			! empty( $size['crop'] )
		);
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Filter which sizes get generated on upload
// ─────────────────────────────────────────────────────────────────────────────

function ism_filter_image_sizes( array $sizes, array $image_meta, $attachment_id ): array {
	$settings        = ism_get_settings();
	$disabled_global = array_map( 'sanitize_key', (array) $settings['disabled_sizes'] );

	// Remove globally disabled sizes
	foreach ( $disabled_global as $key ) {
		unset( $sizes[ $key ] );
	}

	// Per-CPT size restriction
	$post_type_rules = (array) ( $settings['post_type_rules'] ?? [] );
	if ( ! empty( $post_type_rules ) ) {
		$att_id    = (int) ( $attachment_id ?? 0 );
		$parent_id = (int) wp_get_post_parent_id( $att_id );
		$post_type = '';

		if ( $parent_id ) {
			$post_type = get_post_type( $parent_id ) ?: '';
		}

		// Fallback: check the post_id in the current request (upload from post edit screen)
		if ( ! $post_type && isset( $_REQUEST['post_id'] ) ) {
			$req_post_id = (int) $_REQUEST['post_id'];
			$post_type   = get_post_type( $req_post_id ) ?: '';
		}

		// Fallback: regen context set by ism_regen_attachment()
		if ( ! $post_type ) {
			global $ism_regen_cpt;
			$post_type = $ism_regen_cpt ?? '';
		}

		if ( $post_type && isset( $post_type_rules[ $post_type ] ) ) {
			$rule = $post_type_rules[ $post_type ];
			if ( '1' === ( $rule['restrict_enabled'] ?? '0' ) ) {
				// Empty allowlist = generate nothing (original only); non-empty = whitelist filter.
				$allowed = array_map( 'sanitize_key', (array) ( $rule['allowed_sizes'] ?? [] ) );
				foreach ( array_keys( $sizes ) as $key ) {
					if ( ! in_array( $key, $allowed, true ) ) {
						unset( $sizes[ $key ] );
					}
				}
			}
		}
	}

	return $sizes;
}

/**
 * Remove globally disabled sizes from editor image-size dropdowns.
 * Affects Elementor, the block editor image block, and the classic editor.
 *
 * @param array $sizes [ 'size_key' => 'Human Label', … ]
 * @return array
 */
function ism_filter_choosable_sizes( array $sizes ): array {
	$settings        = ism_get_settings();
	$disabled_global = array_map( 'sanitize_key', (array) $settings['disabled_sizes'] );

	foreach ( $disabled_global as $key ) {
		unset( $sizes[ $key ] );
	}

	return $sizes;
}

/**
 * Ensure custom image sizes appear in the JS attachment payload used by
 * the Elementor editor (and block editor) for its live preview.
 *
 * WordPress's wp_prepare_attachment_for_js() only includes sizes that are
 * present in the attachment's stored metadata. If a size was added after the
 * image was uploaded its metadata entry may be missing, so we inject it here
 * directly from the raw metadata so Elementor's JS can resolve the URL.
 *
 * @param array    $response   Prepared attachment data for JS.
 * @param WP_Post  $attachment Attachment post object.
 * @param array    $meta       Raw attachment metadata from wp_get_attachment_metadata().
 * @return array
 */
function ism_prepare_attachment_for_js( array $response, WP_Post $attachment, $meta ): array {
	if ( 'image' !== $response['type'] || empty( $meta['sizes'] ) ) {
		return $response;
	}

	$upload_dir = wp_upload_dir();
	// Directory that contains the original file (sizes live alongside it).
	$file_dir_url = trailingslashit( dirname( $upload_dir['baseurl'] . '/' . $meta['file'] ) );
	$file_dir_abs = trailingslashit( dirname( $upload_dir['basedir'] . '/' . $meta['file'] ) );

	$settings     = ism_get_settings();
	$custom_sizes = (array) $settings['custom_sizes'];
	$disabled     = array_map( 'sanitize_key', (array) $settings['disabled_sizes'] );

	foreach ( $custom_sizes as $size_def ) {
		$key = sanitize_key( $size_def['name'] ?? '' );

		if ( ! $key || in_array( $key, $disabled, true ) ) {
			continue;
		}

		// Already in the payload — nothing to do.
		if ( ! empty( $response['sizes'][ $key ] ) ) {
			continue;
		}

		// Size must have been generated and stored in the attachment metadata.
		if ( empty( $meta['sizes'][ $key ] ) ) {
			continue;
		}

		$sz        = $meta['sizes'][ $key ];
		$file_name = $sz['file'];

		// Verify the physical file exists before advertising it.
		if ( ! file_exists( $file_dir_abs . $file_name ) ) {
			continue;
		}

		$response['sizes'][ $key ] = [
			'height'      => (int) $sz['height'],
			'width'       => (int) $sz['width'],
			'url'         => $file_dir_url . $file_name,
			'orientation' => $sz['height'] > $sz['width'] ? 'portrait' : 'landscape',
			'mime-type'   => $sz['mime-type'] ?? $response['mime'],
		];
	}

	return $response;
}

// ─────────────────────────────────────────────────────────────────────────────
// Delete product images on permanent trash deletion
// ─────────────────────────────────────────────────────────────────────────────

function ism_delete_post_type_images( int $post_id ): void {
	$settings  = ism_get_settings();
	$post_type = get_post_type( $post_id );
	if ( ! $post_type ) {
		return;
	}

	$rules = (array) ( $settings['post_type_rules'] ?? [] );
	if ( empty( $rules[ $post_type ] ) || '1' !== ( $rules[ $post_type ]['delete_images'] ?? '0' ) ) {
		return;
	}

	// All attachments directly parented to this post
	$attachments = get_posts( [
		'post_type'      => 'attachment',
		'post_parent'    => $post_id,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	foreach ( $attachments as $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
	}

	// Featured / thumbnail image
	$thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id ) {
		wp_delete_attachment( (int) $thumbnail_id, true );
	}

	// WooCommerce product gallery images (only relevant for 'product' CPT)
	if ( 'product' === $post_type ) {
		$gallery_ids = get_post_meta( $post_id, '_product_image_gallery', true );
		if ( $gallery_ids ) {
			foreach ( explode( ',', $gallery_ids ) as $gid ) {
				$gid = (int) trim( $gid );
				if ( $gid ) {
					wp_delete_attachment( $gid, true );
				}
			}
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Thumbnail regeneration helpers + AJAX
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Collect all attachment IDs associated with posts of a given CPT.
 * Includes: child attachments, featured images, WC gallery images.
 *
 * @param string $cpt_key Post type key.
 * @return int[]
 */
function ism_collect_cpt_attachment_ids( string $cpt_key ): array {
	$post_ids = get_posts( [
		'post_type'      => $cpt_key,
		'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	if ( empty( $post_ids ) ) {
		return [];
	}

	// Child attachments
	$child_ids = get_posts( [
		'post_type'       => 'attachment',
		'post_parent__in' => $post_ids,
		'post_status'     => 'any',
		'posts_per_page'  => -1,
		'fields'          => 'ids',
	] );

	// Featured (thumbnail) images
	$featured_ids = [];
	foreach ( $post_ids as $pid ) {
		$tid = (int) get_post_thumbnail_id( $pid );
		if ( $tid ) {
			$featured_ids[] = $tid;
		}
	}

	// WooCommerce product gallery images
	$gallery_ids = [];
	if ( 'product' === $cpt_key ) {
		foreach ( $post_ids as $pid ) {
			$raw = get_post_meta( $pid, '_product_image_gallery', true );
			if ( $raw ) {
				foreach ( explode( ',', $raw ) as $gid ) {
					$gid = (int) trim( $gid );
					if ( $gid ) {
						$gallery_ids[] = $gid;
					}
				}
			}
		}
	}

	$all = array_values(
		array_unique(
			array_filter(
				array_merge( $child_ids, $featured_ids, $gallery_ids )
			)
		)
	);

	return $all;
}

/**
 * Regenerate one attachment.
 * Forces the CPT context so our size filter applies even when the attachment
 * has no post_parent set (e.g. featured images stored only via post meta).
 * Deletes old size files that are no longer needed after regeneration.
 *
 * @param int    $attachment_id
 * @param string $cpt_key       Post type whose rules should apply.
 * @return true|WP_Error
 */
function ism_regen_attachment( int $attachment_id, string $cpt_key ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'missing_file', "File not found for attachment {$attachment_id}" );
	}

	// Capture old size filenames before regeneration.
	$old_meta  = wp_get_attachment_metadata( $attachment_id );
	$old_sizes = is_array( $old_meta ) ? (array) ( $old_meta['sizes'] ?? [] ) : [];

	$file_dir         = trailingslashit( dirname( $file ) );
	$scaled_to_delete = null;

	// Capture current _wp_attached_file so it can be restored if regeneration fails.
	$original_rel_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

	// ── Step 1: Recover the pre-scaled original if one exists ─────────────
	// When WordPress created a -scaled version it stored the full-resolution
	// backup filename in $meta['original_image'].  Restore it as the working
	// file so we regenerate from the true source, then delete the -scaled copy.
	if ( ! empty( $old_meta['original_image'] ) ) {
		$original_path = $file_dir . $old_meta['original_image'];
		if ( file_exists( $original_path ) ) {
			// The currently registered file IS the -scaled version — note for deletion.
			$scaled_to_delete = $file;

			// Re-point _wp_attached_file to the true original.
			$current_rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$rel_dir     = dirname( $current_rel );
			$new_rel     = ( $rel_dir && '.' !== $rel_dir )
				? trailingslashit( $rel_dir ) . $old_meta['original_image']
				: $old_meta['original_image'];
			update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );

			$file = $original_path;
		}
	}

	// ── Step 2: Apply max upload dimensions to the working original ────────
	$s     = ism_get_settings();
	$max_w = (int) $s['max_upload_width'];
	$max_h = (int) $s['max_upload_height'];

	if ( $max_w > 0 || $max_h > 0 ) {
		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$size   = $editor->get_size();
			$over_w = $max_w > 0 && (int) $size['width']  > $max_w;
			$over_h = $max_h > 0 && (int) $size['height'] > $max_h;
			if ( $over_w || $over_h ) {
				$editor->resize( $max_w > 0 ? $max_w : null, $max_h > 0 ? $max_h : null, false );
				$editor->save( $file );
			}
		}
	}

	// Force CPT context so ism_filter_image_sizes applies correctly.
	global $ism_regen_cpt;
	$ism_regen_cpt = $cpt_key;

	// Suppress PHP image editor notices.
	@ini_set( 'display_errors', '0' ); // phpcs:ignore
	$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );

	$ism_regen_cpt = null;

	if ( is_wp_error( $new_meta ) ) {
		update_post_meta( $attachment_id, '_wp_attached_file', $original_rel_file );
		return $new_meta;
	}
	if ( empty( $new_meta ) ) {
		update_post_meta( $attachment_id, '_wp_attached_file', $original_rel_file );
		return new WP_Error( 'regen_failed', "Regeneration returned empty metadata for attachment {$attachment_id}" );
	}

	// Strip any new original_image entry — we never want -scaled files created
	// by this regen run, even if WP's threshold filter fires for some reason.
	unset( $new_meta['original_image'] );

	$new_sizes  = (array) ( $new_meta['sizes'] ?? [] );
	$upload_dir = wp_upload_dir();
	$file_dir   = trailingslashit( dirname( $upload_dir['basedir'] . '/' . ( $new_meta['file'] ?? get_post_meta( $attachment_id, '_wp_attached_file', true ) ) ) );

	// Build the complete set of filenames still referenced by the new metadata
	// (covers both surviving sizes AND the original file itself).
	$new_files_referenced = [];
	foreach ( $new_sizes as $sz ) {
		if ( ! empty( $sz['file'] ) ) {
			$new_files_referenced[ $sz['file'] ] = true;
		}
	}
	// Always keep the original file.
	if ( ! empty( $new_meta['file'] ) ) {
		$new_files_referenced[ basename( $new_meta['file'] ) ] = true;
	}

	// Delete every old size file whose filename is no longer referenced.
	$files_deleted = 0;
	foreach ( $old_sizes as $size_data ) {
		if ( empty( $size_data['file'] ) ) {
			continue;
		}
		if ( isset( $new_files_referenced[ $size_data['file'] ] ) ) {
			continue; // File is still needed — keep it.
		}
		$old_file_path = $file_dir . $size_data['file'];
		if ( file_exists( $old_file_path ) && @unlink( $old_file_path ) ) { // phpcs:ignore
			$files_deleted++;
		}
	}

	// Delete the now-redundant -scaled file recovered in Step 1.
	if ( $scaled_to_delete && $scaled_to_delete !== $file && file_exists( $scaled_to_delete ) ) {
		if ( @unlink( $scaled_to_delete ) ) { // phpcs:ignore
			$files_deleted++;
		}
	}

	wp_update_attachment_metadata( $attachment_id, $new_meta );

	return [ 'deleted' => $files_deleted ];
}


// ─────────────────────────────────────────────────────────────────────────────

function ism_add_admin_menu(): void {
	add_menu_page(
		__( 'Image Sizes', 'image-size-manager' ),
		__( 'Image Sizes', 'image-size-manager' ),
		'manage_options',
		'image-size-manager',
		'ism_render_admin_page',
		'dashicons-images-alt2',
		81
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Enqueue admin assets
// ─────────────────────────────────────────────────────────────────────────────

function ism_enqueue_assets( string $hook ): void {
	if ( 'toplevel_page_image-size-manager' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'ism-admin',
		ISM_PLUGIN_URL . 'admin/admin.css',
		[],
		ISM_VERSION
	);
	wp_enqueue_script(
		'ism-admin',
		ISM_PLUGIN_URL . 'admin/admin.js',
		[ 'jquery' ],
		ISM_VERSION,
		true
	);
	wp_localize_script( 'ism-admin', 'ismData', [
		'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		'nonce'          => wp_create_nonce( 'ism_regen' ),
		'mediaLogNonce'  => wp_create_nonce( 'ism_media_log' ),
		'bulkResizeNonce'=> wp_create_nonce( 'ism_bulk_resize' ),
		'maxUploadWidth' => (int) ism_get_settings()['max_upload_width'],
		'maxUploadHeight'=> (int) ism_get_settings()['max_upload_height'],
		'sizeUsageNonce' => wp_create_nonce( 'ism_bulk_resize' ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Settings registration (nonce-based custom form, not Settings API)
// ─────────────────────────────────────────────────────────────────────────────

function ism_register_settings(): void {
	// Intentionally empty – we handle saving via admin_post action
}

// ─────────────────────────────────────────────────────────────────────────────
// Save handler
// ─────────────────────────────────────────────────────────────────────────────
// Max-upload resize
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Suppress WordPress's built-in big-image ( -scaled ) behaviour when the plugin
 * has its own max-upload dimensions configured.
 */
function ism_big_image_threshold( int $threshold ): int|false {
	$s     = ism_get_settings();
	$max_w = (int) $s['max_upload_width'];
	$max_h = (int) $s['max_upload_height'];
	$limit = max( $max_w, $max_h );

	// Suppress WP's -scaled only when the configured max is below the 2560px threshold.
	// If the max is ≥ 2560 (or unset), let WordPress handle its own scaling.
	if ( $limit > 0 && $limit < 2560 ) {
		return false;
	}
	return $threshold;
}

/**
 * After a file lands in /uploads, resize it in-place to the configured maximum
 * before WordPress generates any metadata or sub-sizes.
 *
 * @param array  $upload   { file, url, type }
 * @param string $context  'upload' | 'sideload'
 */
function ism_handle_upload_resize( array $upload, string $context ): array {
	// Only act on images uploaded through the media library (not programmatic sideloads)
	if ( $context !== 'upload' ) {
		return $upload;
	}

	// Only image files
	if ( ! isset( $upload['type'] ) || strpos( $upload['type'], 'image/' ) !== 0 ) {
		return $upload;
	}

	$s      = ism_get_settings();
	$max_w  = (int) $s['max_upload_width'];
	$max_h  = (int) $s['max_upload_height'];

	if ( $max_w <= 0 && $max_h <= 0 ) {
		return $upload;
	}

	$file   = $upload['file'];
	$editor = wp_get_image_editor( $file );

	if ( is_wp_error( $editor ) ) {
		return $upload;
	}

	$size   = $editor->get_size();
	$orig_w = (int) $size['width'];
	$orig_h = (int) $size['height'];

	$over_w = $max_w > 0 && $orig_w > $max_w;
	$over_h = $max_h > 0 && $orig_h > $max_h;

	if ( ! $over_w && ! $over_h ) {
		return $upload; // already within limits
	}

	// resize() with crop=false fits the image inside the given box, preserving aspect ratio.
	// Passing null for a dimension means that axis is unconstrained.
	$result = $editor->resize( $max_w > 0 ? $max_w : null, $max_h > 0 ? $max_h : null, false );

	if ( is_wp_error( $result ) ) {
		return $upload;
	}

	// Save back over the original file at the same quality/path.
	$saved = $editor->save( $file );

	if ( is_wp_error( $saved ) ) {
		return $upload;
	}

	return $upload;
}

// ─────────────────────────────────────────────────────────────────────────────

function ism_handle_save(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	check_admin_referer( 'ism_save_settings', 'ism_nonce' );

	$settings = ism_get_settings();

	// ── globally disabled sizes ──────────────────────────────────────────────
	$settings['disabled_sizes'] = array_map(
		'sanitize_key',
		(array) ( $_POST['ism_disabled_sizes'] ?? [] )
	);

	// ── custom sizes ─────────────────────────────────────────────────────────
	$raw_custom         = (array) ( $_POST['ism_custom_sizes'] ?? [] );
	$settings['custom_sizes'] = [];
	foreach ( $raw_custom as $row ) {
		$name = sanitize_key( $row['name'] ?? '' );
		if ( ! $name ) {
			continue;
		}
		$settings['custom_sizes'][] = [
			'name'   => $name,
			'width'  => max( 0, (int) ( $row['width']  ?? 0 ) ),
			'height' => max( 0, (int) ( $row['height'] ?? 0 ) ),
			'crop'   => ! empty( $row['crop'] ) ? '1' : '0',
		];
	}

	// ── Per-CPT rules ─────────────────────────────────────────────────────────
	$raw_cpt_rules            = (array) ( $_POST['ism_cpt_rules'] ?? [] );
	$settings['post_type_rules'] = [];
	foreach ( $raw_cpt_rules as $cpt_key => $rule ) {
		$cpt_key = sanitize_key( $cpt_key );
		if ( ! $cpt_key ) {
			continue;
		}
		$allowed = array_map( 'sanitize_key', (array) ( $rule['allowed_sizes'] ?? [] ) );
		// Strip globally disabled sizes from the per-CPT allowlist
		$allowed = array_values( array_diff( $allowed, $settings['disabled_sizes'] ) );
		$settings['post_type_rules'][ $cpt_key ] = [
			'restrict_enabled' => ! empty( $rule['restrict_enabled'] ) ? '1' : '0',
			'allowed_sizes'    => $allowed,
			'delete_images'    => ! empty( $rule['delete_images'] ) ? '1' : '0',
		];
	}

	// ── Max upload dimensions ────────────────────────────────────────────────
	$settings['max_upload_width']  = max( 0, (int) ( $_POST['ism_max_upload_width']  ?? 0 ) );
	$settings['max_upload_height'] = max( 0, (int) ( $_POST['ism_max_upload_height'] ?? 0 ) );

	// ── Built-in size dimension overrides ────────────────────────────────────
	$builtin_editable = [ 'thumbnail', 'medium', 'medium_large', 'large' ];
	$raw_builtin      = (array) ( $_POST['ism_builtin'] ?? [] );
	foreach ( $builtin_editable as $key ) {
		if ( ! isset( $raw_builtin[ $key ] ) ) {
			continue;
		}
		$w = max( 0, (int) ( $raw_builtin[ $key ]['width']  ?? 0 ) );
		$h = max( 0, (int) ( $raw_builtin[ $key ]['height'] ?? 0 ) );
		$c = ! empty( $raw_builtin[ $key ]['crop'] ) ? 1 : 0;
		update_option( "{$key}_size_w", $w );
		update_option( "{$key}_size_h", $h );
		if ( $key !== 'medium_large' ) {
			update_option( "{$key}_crop", $c );
		}
	}

	ism_save_settings( $settings );

	wp_redirect( add_query_arg( [ 'page' => 'image-size-manager', 'ism_saved' => '1' ], admin_url( 'admin.php' ) ) );
	exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Admin page render
// ─────────────────────────────────────────────────────────────────────────────

function ism_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings        = ism_get_settings();
	$all_sizes       = ism_get_all_registered_sizes();
	$disabled_sizes  = (array) $settings['disabled_sizes'];
	$custom_sizes    = (array) $settings['custom_sizes'];
	$post_type_rules = (array) $settings['post_type_rules'];
	$registered_cpts = ism_get_registered_cpts();
	$saved           = isset( $_GET['ism_saved'] );

	include ISM_PLUGIN_DIR . 'admin/admin-page.php';
}
