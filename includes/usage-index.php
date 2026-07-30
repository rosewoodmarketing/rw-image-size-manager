<?php
/**
 * Usage Index for RW Image Manager.
 *
 * Builds a reverse map of attachment ID → the posts that reference it.
 *
 * WordPress has no such map. post_parent records where an image was uploaded
 * from (frequently empty or wrong) and _thumbnail_id only covers featured
 * images. An image placed in page content or an Elementor widget has no
 * recorded link back to that page at all.
 *
 * The map is stored as `_ism_usage` postmeta on each attachment:
 *
 *   [ [ 'post_id' => 88, 'source' => 'elementor' ], … ]
 *
 * Consumed by:
 *   - Image SEO tab, to build page context for AI generation
 *   - Duplicates tab, to repoint references during a merge
 *   - Media Log, to replace the unreliable "parent post" column
 *
 * No external API is required to build or read this index.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ISM_USAGE_META_KEY',   '_ism_usage' );
define( 'ISM_USAGE_STATE_KEY',  'ism_usage_index_state' );
define( 'ISM_USAGE_BATCH_SIZE', 25 );

/**
 * Post statuses that count as live content worth indexing.
 *
 * Matches the filter used by the size usage scanner so the two tools agree.
 * Deliberately includes `elementor_library`, since Elementor headers, footers,
 * popups and saved templates hold image references that appear site-wide.
 */
function ism_usage_post_statuses(): array {
	return [ 'publish', 'draft', 'private', 'future', 'pending' ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the index
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return the recorded usages for one attachment.
 *
 * @param int $attachment_id
 * @return array[] List of [ 'post_id' => int, 'source' => string ].
 */
function ism_usage_get( int $attachment_id ): array {
	$raw = get_post_meta( $attachment_id, ISM_USAGE_META_KEY, true );

	return is_array( $raw ) ? $raw : [];
}

/**
 * Return usages enriched with post title, type and edit link, ready for display
 * or for building AI prompt context.
 *
 * @param int $attachment_id
 * @return array[]
 */
function ism_usage_get_detailed( int $attachment_id ): array {
	$out = [];

	foreach ( ism_usage_get( $attachment_id ) as $usage ) {
		$post = get_post( (int) $usage['post_id'] );
		if ( ! $post ) {
			continue; // Post deleted since the last scan.
		}
		$out[] = [
			'post_id'   => (int) $post->ID,
			'source'    => (string) $usage['source'],
			'title'     => $post->post_title !== '' ? $post->post_title : '(no title)',
			'post_type' => $post->post_type,
			'edit_url'  => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			'permalink' => get_permalink( $post->ID ) ?: '',
		];
	}

	return $out;
}

/**
 * Index build status for the admin UI.
 *
 * @return array{built_at:int, posts_indexed:int, attachments_found:int, running:bool}
 */
function ism_usage_index_status(): array {
	$state = get_option( ISM_USAGE_STATE_KEY, [] );

	return [
		'built_at'          => (int)  ( $state['built_at']          ?? 0 ),
		'posts_indexed'     => (int)  ( $state['posts_indexed']     ?? 0 ),
		'attachments_found' => (int)  ( $state['attachments_found'] ?? 0 ),
		'running'           => (bool) ( $state['running']           ?? false ),
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Extraction — the heart of the index
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Extract every attachment ID referenced by a single post.
 *
 * @param int $post_id
 * @return array<int,string> attachment_id => source label.
 */
function ism_usage_extract_from_post( int $post_id ): array {
	$found = [];

	$post = get_post( $post_id );
	if ( ! $post ) {
		return $found;
	}

	// ── Featured image ───────────────────────────────────────────────────────
	$thumb_id = (int) get_post_thumbnail_id( $post_id );
	if ( $thumb_id ) {
		$found[ $thumb_id ] = 'featured';
	}

	// ── post_content ─────────────────────────────────────────────────────────
	$content = (string) $post->post_content;

	if ( $content !== '' ) {
		// Classic editor and core image block markup: class="… wp-image-123"
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$found[ (int) $id ] = $found[ (int) $id ] ?? 'content';
			}
		}

		// Block attributes, read only from inside wp: block comments.
		//
		// Scanning the whole document for "id":N would collect IDs belonging to
		// forms, embeds and any other block that happens to store a numeric id,
		// so the JSON is isolated to block delimiters first. Covers cover and
		// media-text blocks (which emit no wp-image-N class) and gallery blocks.
		if ( preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+\/?-->/s', $content, $m ) ) {
			foreach ( $m[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				foreach ( [ 'id', 'mediaId' ] as $key ) {
					if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
						$id = (int) $attrs[ $key ];
						if ( $id ) {
							$found[ $id ] = $found[ $id ] ?? 'content';
						}
					}
				}
				if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					foreach ( $attrs['ids'] as $id ) {
						$id = (int) $id;
						if ( $id ) {
							$found[ $id ] = $found[ $id ] ?? 'content';
						}
					}
				}
			}
		}

		// Gallery shortcode: [gallery ids="12,13,14"]
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']?([\d,\s]+)/', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( explode( ',', $list ) as $id ) {
					$id = (int) trim( $id );
					if ( $id ) {
						$found[ $id ] = $found[ $id ] ?? 'content';
					}
				}
			}
		}
	}

	// ── WooCommerce product gallery ──────────────────────────────────────────
	$wc_gallery = get_post_meta( $post_id, '_product_image_gallery', true );
	if ( $wc_gallery ) {
		foreach ( explode( ',', (string) $wc_gallery ) as $id ) {
			$id = (int) trim( $id );
			if ( $id ) {
				$found[ $id ] = 'wc_gallery';
			}
		}
	}

	// ── Elementor ────────────────────────────────────────────────────────────
	$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( $elementor_raw ) {
		$elements = json_decode( is_string( $elementor_raw ) ? $elementor_raw : '', true );

		if ( is_array( $elements ) ) {
			$el_found = [];
			ism_usage_walk_elementor( $elements, $el_found );
			foreach ( array_keys( $el_found ) as $id ) {
				$found[ (int) $id ] = 'elementor';
			}
		} elseif ( is_string( $elementor_raw ) ) {
			// Fallback for invalid or compressed JSON: regex for {"id":123,"url":"…"}
			if ( preg_match_all( '~"id"\s*:\s*"?(\d+)"?\s*,\s*"url"\s*:\s*"[^"]*/wp-content/uploads/~', $elementor_raw, $m ) ) {
				foreach ( $m[1] as $id ) {
					$found[ (int) $id ] = 'elementor';
				}
			}
		}
	}

	unset( $found[0] );

	return $found;
}

/**
 * Recursively collect attachment IDs from an Elementor elements tree.
 *
 * Mirrors the traversal used by the size usage scanner, but harvests media IDs
 * rather than size slugs.
 *
 * Rather than enumerating every image control Elementor ships (image,
 * background_image, background_image_mobile, gallery, carousel, icon, and the
 * responsive variants of each), this relies on the shape Elementor uses for
 * every media control: an array carrying a numeric `id` alongside a `url`
 * string. That one rule covers current controls and any added later.
 *
 * Elementor's link control is `{ url, is_external, nofollow }` with no `id`, so
 * links are not mistaken for media. String IDs (used by some non-media
 * controls) are rejected by the numeric check.
 *
 * @param array              $elements Elements tree or settings subtree.
 * @param array<int,bool>   &$found    Collected attachment IDs, keyed by ID.
 */
function ism_usage_walk_elementor( array $elements, array &$found ): void {
	foreach ( $elements as $key => $value ) {
		if ( ! is_array( $value ) ) {
			continue;
		}

		// Media control shape: numeric id + uploads-ish url.
		if ( isset( $value['id'], $value['url'] ) && is_string( $value['url'] ) ) {
			$id = $value['id'];
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$found[ (int) $id ] = true;
			}
		}

		// Recurse into everything: elements, settings, gallery arrays, repeaters.
		ism_usage_walk_elementor( $value, $found );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Building the index
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Collect the IDs of every post worth scanning.
 *
 * @return int[]
 */
function ism_usage_collect_post_ids(): array {
	global $wpdb;

	$statuses     = ism_usage_post_statuses();
	$status_in    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only
	$sql = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_status IN ($status_in)
		 AND post_type NOT IN ('attachment','revision')
		 ORDER BY ID ASC",
		...$statuses
	);

	return array_map( 'intval', $wpdb->get_col( $sql ) );
}

/**
 * Wipe every stored usage record.
 *
 * Called once at the start of a build so batches only ever append, which keeps
 * a resumed run from double-counting and clears references to deleted posts.
 */
function ism_usage_clear_all(): void {
	global $wpdb;

	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => ISM_USAGE_META_KEY ] );

	// A bulk DELETE bypasses the object cache, so cached postmeta would still
	// serve the rows just removed. wp_cache_flush_group() landed in WP 6.1 and
	// the plugin supports 6.0, hence the guard and the blunt fallback.
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	} else {
		wp_cache_flush();
	}
}

/**
 * Index one batch of posts and merge the results into attachment meta.
 *
 * @param int[] $post_ids
 * @return int Number of distinct attachments touched.
 */
function ism_usage_index_posts( array $post_ids ): int {
	// attachment_id => [ post_id => source ]
	$map = [];

	foreach ( $post_ids as $post_id ) {
		foreach ( ism_usage_extract_from_post( (int) $post_id ) as $att_id => $source ) {
			$map[ $att_id ][ (int) $post_id ] = $source;
		}
	}

	if ( empty( $map ) ) {
		return 0;
	}

	// Discard IDs that are not real image attachments. Elementor and block
	// content can carry stale IDs for media deleted long ago.
	$valid = ism_usage_filter_image_attachments( array_keys( $map ) );

	$touched = 0;

	foreach ( $valid as $att_id ) {
		$existing = ism_usage_get( $att_id );

		// Re-key existing entries so merging is idempotent.
		$merged = [];
		foreach ( $existing as $usage ) {
			$merged[ (int) $usage['post_id'] ] = (string) $usage['source'];
		}
		foreach ( $map[ $att_id ] as $post_id => $source ) {
			$merged[ $post_id ] = $source;
		}

		$records = [];
		foreach ( $merged as $post_id => $source ) {
			$records[] = [ 'post_id' => (int) $post_id, 'source' => $source ];
		}

		update_post_meta( $att_id, ISM_USAGE_META_KEY, $records );
		$touched++;
	}

	return $touched;
}

/**
 * Reduce a list of IDs to those that are genuinely image attachments.
 *
 * @param int[] $ids
 * @return int[]
 */
function ism_usage_filter_image_attachments( array $ids ): array {
	global $wpdb;

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	if ( empty( $ids ) ) {
		return [];
	}

	$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only
	$sql = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE ID IN ($in)
		 AND post_type = 'attachment'
		 AND post_mime_type LIKE 'image/%%'",
		...$ids
	);

	return array_map( 'intval', $wpdb->get_col( $sql ) );
}

/**
 * Count the attachments that currently carry a usage record.
 *
 * Recalculated rather than accumulated, because one attachment is touched once
 * per referencing post and a running total would over-count.
 *
 * @return int
 */
function ism_usage_count_indexed(): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			ISM_USAGE_META_KEY
		)
	);
}

/**
 * Record a completed build.
 *
 * @param int $posts_indexed
 */
function ism_usage_set_status( int $posts_indexed ): void {
	update_option( ISM_USAGE_STATE_KEY, [
		'built_at'          => time(),
		'posts_indexed'     => $posts_indexed,
		'attachments_found' => ism_usage_count_indexed(),
		'running'           => false,
	], false );
}

/**
 * Build the whole index in one synchronous pass.
 *
 * Intended for WP-CLI and testing. The admin UI uses the batched AJAX handlers
 * instead, since a large site will exceed a single request's time limit.
 *
 * @return array Status array, as returned by ism_usage_index_status().
 */
function ism_usage_build_all(): array {
	ism_usage_clear_all();

	$ids = ism_usage_collect_post_ids();

	foreach ( array_chunk( $ids, ISM_USAGE_BATCH_SIZE ) as $chunk ) {
		ism_usage_index_posts( $chunk );
	}

	ism_usage_set_status( count( $ids ) );

	return ism_usage_index_status();
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — init / batch, following the regen_all pattern
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: start (or resume) a usage index build.
 */
function ism_ajax_usage_index_init(): void {
	check_ajax_referer( 'ism_usage_index', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$state_key     = 'ism_usage_index_' . get_current_user_id();
	$force_restart = ! empty( $_POST['force_restart'] );

	if ( ! $force_restart ) {
		$existing = get_option( $state_key, [] );
		if ( is_array( $existing ) && ! empty( $existing['ids'] ) ) {
			wp_send_json_success( [
				'state_key' => $state_key,
				'total'     => count( $existing['ids'] ),
				'offset'    => max( 0, (int) ( $existing['offset'] ?? 0 ) ),
				'found'     => (int) ( $existing['found'] ?? 0 ),
				'resumed'   => true,
			] );
		}
	}

	ism_usage_clear_all();

	$ids = ism_usage_collect_post_ids();

	// Long-running accumulator state lives in an option, not a transient.
	// Kinsta runs a Redis object cache, and a transient can be evicted
	// mid-run; an option with autoload off survives.
	update_option( $state_key, [ 'ids' => $ids, 'offset' => 0, 'found' => 0 ], false );

	update_option( ISM_USAGE_STATE_KEY, [
		'built_at'          => 0,
		'posts_indexed'     => 0,
		'attachments_found' => 0,
		'running'           => true,
	], false );

	wp_send_json_success( [
		'state_key' => $state_key,
		'total'     => count( $ids ),
		'offset'    => 0,
		'found'     => 0,
		'resumed'   => false,
	] );
}

/**
 * AJAX: index one batch of posts.
 */
function ism_ajax_usage_index_batch(): void {
	check_ajax_referer( 'ism_usage_index', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	// Derived server-side rather than read from POST. Accepting an option name
	// from the request would let a caller read or clobber arbitrary options.
	$state_key = 'ism_usage_index_' . get_current_user_id();
	$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

	$saved = get_option( $state_key, [] );
	if ( ! is_array( $saved ) || empty( $saved['ids'] ) ) {
		wp_send_json_error( 'Index state missing — please restart the scan.' );
	}

	$ids          = $saved['ids'];
	$saved_offset = max( 0, (int) ( $saved['offset'] ?? 0 ) );
	if ( $saved_offset > $offset ) {
		$offset = $saved_offset;
	}

	$batch   = array_slice( $ids, $offset, ISM_USAGE_BATCH_SIZE );
	$touched = ism_usage_index_posts( $batch );

	$new_offset = $offset + count( $batch );
	$found      = (int) ( $saved['found'] ?? 0 ) + $touched;
	$done       = $new_offset >= count( $ids );

	if ( $done ) {
		delete_option( $state_key );
		ism_usage_set_status( count( $ids ) );
		$found = ism_usage_count_indexed();
	} else {
		update_option( $state_key, [
			'ids'    => $ids,
			'offset' => $new_offset,
			'found'  => $found,
		], false );
	}

	wp_send_json_success( [
		'offset' => $new_offset,
		'total'  => count( $ids ),
		'found'  => $found,
		'done'   => $done,
	] );
}