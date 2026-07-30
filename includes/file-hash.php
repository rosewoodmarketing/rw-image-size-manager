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
		$score = ism_hash_metadata_score( $id )['score'];

		// Strictly greater, so an equal score leaves the lower ID in place.
		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = $id;
		}
	}

	return $best;
}

/**
 * How complete one attachment's metadata is, field by field.
 *
 * Broken out from ism_hash_pick_primary() so the duplicate review panel can
 * show *why* a copy was picked rather than asserting it. A keeper suggestion
 * nobody can check is not much use when the decision it informs — which copy
 * to keep — is irreversible.
 *
 * @param int $attachment_id
 * @return array{title:bool, description:bool, alt:bool, score:int}
 */
function ism_hash_metadata_score( int $attachment_id ): array {
	$post = get_post( $attachment_id );

	$has = [
		'title'       => $post && trim( (string) $post->post_title ) !== '',
		'description' => $post && trim( (string) $post->post_content ) !== '',
		'alt'         => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) !== '',
	];

	$has['score'] = count( array_filter( [ $has['title'], $has['description'], $has['alt'] ] ) );

	return $has;
}

/**
 * Full detail for every duplicate group, for the read-only review panel.
 *
 * Deliberately reports each copy's *own* usage rather than the group's merged
 * usage. The chart folds duplicates into one row because that is the right
 * unit for generating a description; deciding which copy is redundant is the
 * opposite problem, and needs to show that copy #5042 is referenced by nothing
 * while #3927 is on four pages.
 *
 * @return array[]
 */
function ism_hash_duplicate_report(): array {
	$report = [];

	foreach ( ism_hash_duplicate_groups() as $hash => $ids ) {
		$primary = ism_hash_pick_primary( $ids );
		$copies  = [];

		foreach ( $ids as $id ) {
			$post  = get_post( $id );
			$file  = get_attached_file( $id );
			$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
			$score = ism_hash_metadata_score( $id );

			$places = [];
			foreach ( ism_usage_get_detailed( $id ) as $usage ) {
				$link = (string) $usage['edit_url'];
				if ( $link === '' ) {
					$link = (string) $usage['permalink'];
				}

				$places[] = [
					'title'    => (string) $usage['title'],
					'type'     => (string) $usage['post_type'],
					'source'   => (string) $usage['source'],
					'featured' => $usage['source'] === 'featured',
					'link'     => $link,
				];
			}

			$copies[] = [
				'id'          => (int) $id,
				'filename'    => $file ? basename( $file ) : '',
				'thumb'       => is_array( $thumb ) && ! empty( $thumb[0] ) ? (string) $thumb[0] : '',
				'edit_url'    => (string) ( get_edit_post_link( $id, 'raw' ) ?: '' ),
				'uploaded'    => $post ? mysql2date( 'j M Y', $post->post_date ) : '',
				'uploaded_ts' => $post ? (int) mysql2date( 'U', $post->post_date ) : 0,
				'is_primary'  => (int) $id === $primary,
				'score'       => (int) $score['score'],
				'has'         => [
					'title'       => $score['title'],
					'description' => $score['description'],
					'alt'         => $score['alt'],
				],
				'current'     => [
					'title'       => $post ? (string) $post->post_title : '',
					'alt_text'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'description' => $post ? (string) $post->post_content : '',
				],
				'used_count'  => count( $places ),
				'places'      => $places,
			];
		}

		// Highest-scoring first so the suggested keeper leads, then oldest —
		// the same order the tie-break uses.
		usort( $copies, function ( $a, $b ) {
			return ( $b['score'] <=> $a['score'] ) ?: ( $a['id'] <=> $b['id'] );
		} );

		// ism_hash_pick_primary() scores metadata completeness and nothing else,
		// which is right for generation — you want the best existing text as a
		// starting point. It is the wrong question for deletion, and on real
		// data the two answers frequently disagree: the copy with the fullest
		// metadata is often the one no page references, while the referenced
		// copy is bare. Trusting the suggestion blindly would trash the copy
		// that is actually in use, so the conflict is flagged rather than left
		// for someone to notice.
		$primary_used = 0;
		$others_used  = 0;

		foreach ( $copies as $copy ) {
			if ( $copy['is_primary'] ) {
				$primary_used = $copy['used_count'];
			} else {
				$others_used += $copy['used_count'];
			}
		}

		$report[] = [
			'hash'           => (string) $hash,
			'primary'        => $primary,
			'copies'         => $copies,
			'reason'         => ism_hash_primary_reason( $copies ),
			'usage_conflict' => $primary_used === 0 && $others_used > 0,
			'total_used'     => $primary_used + $others_used,
		];
	}

	return $report;
}

/**
 * Plain-English justification for the keeper suggestion.
 *
 * @param array $copies Sorted, best first.
 * @return string
 */
function ism_hash_primary_reason( array $copies ): string {
	if ( count( $copies ) < 2 ) {
		return '';
	}

	$best   = $copies[0];
	$runner = $copies[1];

	if ( $best['score'] === $runner['score'] ) {
		return sprintf(
			'All copies carry the same amount of metadata, so the oldest upload (#%d) is suggested.',
			$best['id']
		);
	}

	$fields = array_keys( array_filter( $best['has'] ) );

	return sprintf(
		'#%d has %s, more than any other copy.',
		$best['id'],
		empty( $fields ) ? 'no metadata' : implode( ' and ', array_map( function ( $f ) {
			return $f === 'alt' ? 'alt text' : $f;
		}, $fields ) )
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — duplicate review
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: the duplicate review report.
 *
 * Read-only. Nothing in this endpoint or the panel it feeds deletes, trashes,
 * merges or repoints anything — removing a copy is done from that copy's own
 * edit screen, deliberately, by a human who has looked at what references it.
 */
function ism_ajax_seo_duplicates(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$report = ism_hash_duplicate_report();

	$redundant = 0;
	$conflicts = 0;
	$unused    = 0;

	foreach ( $report as $group ) {
		$redundant += count( $group['copies'] ) - 1;
		if ( ! empty( $group['usage_conflict'] ) ) {
			$conflicts++;
		}
		if ( (int) $group['total_used'] === 0 ) {
			$unused++;
		}
	}

	wp_send_json_success( [
		'groups'    => $report,
		'redundant' => $redundant,
		'conflicts' => $conflicts,
		'unused'    => $unused,
	] );
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
