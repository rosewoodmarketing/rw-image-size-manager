<?php
/**
 * Content hashing and duplicate grouping for RW Image Manager.
 *
 * Two attachments can be the same picture. Media gets re-uploaded, imported
 * twice, or copied between staging and live, and WordPress happily stores each
 * as an unrelated attachment with its own ID, title and alt text. Nothing in
 * core notices.
 *
 * The hash is stored on the attachment as `_ism_file_hash`, which is
 * deliberately the same shape the duplicate-merge feature will need: group by
 * hash, pick a keeper, repoint every reference the usage index knows about,
 * trash the losers. Building it here means generation and merge share one
 * index rather than each growing their own.
 *
 * An exact content hash, not a perceptual one. It will not match a photograph
 * re-saved at a different quality or resized on re-upload, and that is the
 * right trade for this use: a false positive would merge two genuinely
 * different images, or write one image's alt text onto another. Missing a
 * near-duplicate costs one extra generation; a wrong match corrupts data.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where the hash lives. Shared with the future merge tool. */
define( 'ISM_HASH_META_KEY', '_ism_file_hash' );

/** Attachments hashed per batch. Bounded by disk, not CPU. */
define( 'ISM_HASH_BATCH_SIZE', 60 );

/**
 * Hash one attachment's original file, caching the result on the attachment.
 *
 * @param int  $attachment_id
 * @param bool $force Re-hash even when a value is already stored.
 * @return string Empty when the file is unreadable.
 */
function ism_hash_attachment( int $attachment_id, bool $force = false ): string {
	if ( ! $force ) {
		$stored = get_post_meta( $attachment_id, ISM_HASH_META_KEY, true );
		if ( is_string( $stored ) && $stored !== '' ) {
			return $stored;
		}
	}

	$file = get_attached_file( $attachment_id );

	// Reuses the uploads-boundary check rather than hashing whatever path the
	// database happens to contain.
	if ( ! $file || ! ism_vision_readable( $file ) ) {
		return '';
	}

	$hash = @md5_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_string( $hash ) || $hash === '' ) {
		return '';
	}

	update_post_meta( $attachment_id, ISM_HASH_META_KEY, $hash );

	return $hash;
}

/**
 * Hash a batch of attachments.
 *
 * @param int[] $attachment_ids
 * @return int Number hashed.
 */
function ism_hash_batch( array $attachment_ids ): int {
	$done = 0;

	foreach ( $attachment_ids as $id ) {
		if ( ism_hash_attachment( (int) $id ) !== '' ) {
			$done++;
		}
	}

	return $done;
}

/**
 * Every stored hash, as attachment_id => hash.
 *
 * @return array<int,string>
 */
function ism_hash_map(): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
		ISM_HASH_META_KEY
	) );

	$map = [];
	foreach ( $rows as $row ) {
		$map[ (int) $row->post_id ] = (string) $row->meta_value;
	}

	return $map;
}

/**
 * Groups of attachments sharing a hash, keyed by hash.
 *
 * Only hashes with more than one attachment are returned — a group of one is
 * not a duplicate and would just make callers filter it back out.
 *
 * @return array<string,int[]>
 */
function ism_hash_duplicate_groups(): array {
	$groups = [];

	foreach ( ism_hash_map() as $id => $hash ) {
		if ( $hash === '' ) {
			continue;
		}
		$groups[ $hash ][] = $id;
	}

	foreach ( $groups as $hash => $ids ) {
		if ( count( $ids ) < 2 ) {
			unset( $groups[ $hash ] );
			continue;
		}
		sort( $groups[ $hash ] );
	}

	return $groups;
}

/**
 * Which attachment should represent a duplicate group.
 *
 * The one carrying the most existing metadata wins, and the lowest ID breaks a
 * tie — the oldest copy is usually the one editors have linked to and curated.
 * Generation then runs once for the group and applies to every copy.
 *
 * @param int[] $ids
 * @return int
 */
function ism_hash_pick_primary( array $ids ): int {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return 0;
	}

	sort( $ids );

	$best       = $ids[0];
	$best_score = -1;

	foreach ( $ids as $id ) {
		$post  = get_post( $id );
		$score = 0;

		if ( $post && trim( (string) $post->post_title ) !== '' ) {
			$score++;
		}
		if ( $post && trim( (string) $post->post_content ) !== '' ) {
			$score++;
		}
		if ( trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) !== '' ) {
			$score++;
		}

		// Strictly greater, so an equal score leaves the lower ID in place.
		if ( $score > $best_score ) {
			$best_score = $score;
			$best        = $id;
		}
	}

	return $best;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — batched hashing, driven from the Image SEO tab
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: hash one batch of attachments.
 */
function ism_ajax_hash_batch(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$ids    = ism_seo_collect_attachment_ids();
	$batch  = array_slice( $ids, $offset, ISM_HASH_BATCH_SIZE );

	ism_hash_batch( $batch );

	$new_offset = $offset + count( $batch );
	$done       = $new_offset >= count( $ids );

	wp_send_json_success( [
		'offset'     => $new_offset,
		'total'      => count( $ids ),
		'done'       => $done,
		'duplicates' => $done ? count( ism_hash_duplicate_groups() ) : 0,
	] );
}
