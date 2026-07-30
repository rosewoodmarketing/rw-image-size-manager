<?php
/**
 * AJAX endpoints for the Image SEO tab.
 *
 * The browser drives generation one image at a time rather than the server
 * looping over a batch. That is not a stylistic choice: images this host cannot
 * decode are converted in the browser and posted back, so the client has to be
 * in the loop for every request. It also paces requests naturally and keeps
 * progress granular.
 *
 * Run state — the generated proposals — lives in an option with autoload off,
 * not a transient. Kinsta runs a Redis object cache and a transient can be
 * evicted mid-run; here that would mean losing reviewed output that cost real
 * money to produce. The state key is derived from the current user server-side
 * and never accepted over POST.
 *
 * Nothing is written to the media library except through ism_ajax_seo_apply(),
 * which only ever writes rows the user selected and only from values the user
 * saw. There is no auto-apply path.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Attachments classified per scan batch. Cheap work, so the batch is large. */
define( 'ISM_SEO_SCAN_BATCH', 100 );

/** Referencing pages named per row before the list is truncated. */
define( 'ISM_SEO_USED_ON_SHOWN', 2 );

// ─────────────────────────────────────────────────────────────────────────────
// Run state
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Option name holding this user's run.
 *
 * Derived from the session, never read from the request — accepting an option
 * name over POST would let a caller read or clobber arbitrary options.
 *
 * @return string
 */
function ism_seo_state_key(): string {
	return 'ism_seo_run_' . get_current_user_id();
}

/**
 * Current run state.
 *
 * @return array{results:array, usage:array, started:int}
 */
function ism_seo_get_state(): array {
	$state = get_option( ism_seo_state_key(), [] );

	if ( ! is_array( $state ) ) {
		$state = [];
	}

	return [
		'results' => (array) ( $state['results'] ?? [] ),
		'usage'   => (array) ( $state['usage'] ?? ism_ai_empty_usage() ),
		'started' => (int) ( $state['started'] ?? 0 ),
	];
}

/**
 * Persist run state.
 *
 * @param array $state
 */
function ism_seo_set_state( array $state ): void {
	update_option( ism_seo_state_key(), $state, false );
}

/**
 * Discard the run.
 */
function ism_seo_clear_state(): void {
	delete_option( ism_seo_state_key() );
}

// ─────────────────────────────────────────────────────────────────────────────
// Scanning
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every image attachment, oldest first.
 *
 * @return int[]
 */
function ism_seo_collect_attachment_ids(): array {
	global $wpdb;

	return array_map( 'intval', $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		   AND post_mime_type LIKE 'image/%'
		 ORDER BY ID ASC"
	) );
}

/**
 * Classify one attachment into a review group and gather what the table shows.
 *
 * Three groups, and only the first is ever generated for:
 *
 *   ready    Generatable. Appears somewhere, and the format is describable.
 *   skipped  SVG, icon-sized, or an unsupported mime. Listed so it can be
 *            renamed or merged by hand; never described, because prose alt
 *            text on a decorative mark is a correctness bug.
 *   unused   The usage index found it on no page. Listed, not generated:
 *            without page context the output is guesswork and needs a human.
 *
 * @param int $attachment_id
 * @return array
 */
function ism_seo_row( int $attachment_id ): array {
	$file = get_attached_file( $attachment_id );
	$mime = (string) get_post_mime_type( $attachment_id );
	$meta = wp_get_attachment_metadata( $attachment_id );
	$post = get_post( $attachment_id );

	$skip_reason = ism_context_skip_reason(
		$mime,
		(int) ( $meta['width'] ?? 0 ),
		(int) ( $meta['height'] ?? 0 )
	);

	$usages = ism_usage_get_detailed( $attachment_id );

	// Every place, not a sample. The whole point of the chart is to see where an
	// image actually lives before deciding what to say about it, and a merge
	// later has to repoint every reference rather than the first two.
	usort( $usages, function ( $a, $b ) {
		return ism_context_usage_rank( $a ) <=> ism_context_usage_rank( $b );
	} );

	$places = [];
	foreach ( $usages as $usage ) {
		$places[] = [
			'title'     => (string) $usage['title'],
			'type'      => (string) $usage['post_type'],
			'source'    => (string) $usage['source'],
			'featured'  => $usage['source'] === 'featured',
			'edit_url'  => (string) $usage['edit_url'],
			'permalink' => (string) $usage['permalink'],
		];
	}

	if ( $skip_reason !== '' ) {
		$group = 'skipped';
	} elseif ( empty( $usages ) ) {
		$group = 'unused';
	} else {
		$group = 'ready';
	}

	// Computed for every group, not just ready ones: an overridden SVG or unused
	// image still has to travel whichever route its format requires.
	$needs_client = ism_vision_needs_client_decode( $attachment_id );

	// An SVG has no raster the server can send, but a browser renders it to
	// canvas natively, so an override routes it through the same client
	// conversion path everything else uses.
	if ( $skip_reason === 'svg' ) {
		$needs_client = true;
	}

	$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

	return [
		'id'           => $attachment_id,
		'filename'     => $file ? basename( $file ) : '',
		'mime'         => $mime,
		'hash'         => (string) get_post_meta( $attachment_id, ISM_HASH_META_KEY, true ),
		'thumb'        => is_array( $thumb ) && ! empty( $thumb[0] ) ? (string) $thumb[0] : '',
		'edit_url'     => (string) ( get_edit_post_link( $attachment_id, 'raw' ) ?: '' ),
		'group'        => $group,
		'skip_reason'  => $skip_reason,
		'needs_client' => $needs_client,
		'client_url'   => $needs_client ? ism_vision_client_url( $attachment_id ) : '',
		'used_count'   => count( $usages ),
		'places'       => $places,
		'current'      => [
			'title'       => $post ? (string) $post->post_title : '',
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'description' => $post ? (string) $post->post_content : '',
		],
	];
}

/**
 * AJAX: begin a scan.
 */
function ism_ajax_seo_scan_init(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$ids = ism_seo_collect_attachment_ids();

	// Proposals already generated survive a rescan, so a reload does not throw
	// away output that has been paid for but not yet reviewed.
	$state = ism_seo_get_state();

	wp_send_json_success( [
		'total'        => count( $ids ),
		'has_key'      => ism_ai_has_key(),
		'model'        => ism_ai_get_model(),
		'context_max'  => ism_context_max_chars(),
		'index_built'  => (bool) ism_usage_index_status()['built_at'],
		'results'      => $state['results'],
		'usage'        => $state['usage'],
	] );
}

/**
 * AJAX: classify one batch of attachments.
 */
function ism_ajax_seo_scan_batch(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$ids    = ism_seo_collect_attachment_ids();
	$batch  = array_slice( $ids, $offset, ISM_SEO_SCAN_BATCH );

	$rows = [];
	foreach ( $batch as $id ) {
		$rows[] = ism_seo_row( (int) $id );
	}

	$new_offset = $offset + count( $batch );

	wp_send_json_success( [
		'rows'   => $rows,
		'offset' => $new_offset,
		'total'  => count( $ids ),
		'done'   => $new_offset >= count( $ids ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Generating
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: generate a proposal for one attachment.
 *
 * One image per request. The browser loops, which is what lets it convert an
 * image this host cannot decode and hand the result back in the same call.
 */
function ism_ajax_seo_generate(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
	if ( $attachment_id < 1 ) {
		wp_send_json_error( 'No attachment specified.' );
	}

	// Unslashed but not sanitised as text: this is base64 image data, and
	// ism_vision_accept_client_image() validates it properly by decoding it.
	$data_url = isset( $_POST['image_data_url'] )
		? trim( (string) wp_unslash( $_POST['image_data_url'] ) )
		: '';

	// Overriding the skip gate is explicit, per request, and never stored. A
	// decorative mark stays undescribed unless someone asks for this image.
	$override = ! empty( $_POST['override_skip'] );

	$generated = ism_ai_generate_for_attachment( $attachment_id, [
		'image_data_url' => $data_url,
		'override_skip'  => $override,
	] );

	if ( is_wp_error( $generated ) ) {
		ism_seo_record_error( $attachment_id, $generated );

		wp_send_json_error( [
			'attachment_id' => $attachment_id,
			'code'          => $generated->get_error_code(),
			'message'       => $generated->get_error_message(),
		] );
	}

	if ( $generated['result'] === null ) {
		$error = new WP_Error(
			'ism_seo_unparseable',
			'The model returned something that could not be parsed. Raw response kept for inspection.'
		);
		ism_seo_record_error( $attachment_id, $error, $generated['usage'] );

		wp_send_json_error( [
			'attachment_id' => $attachment_id,
			'code'          => 'ism_seo_unparseable',
			'message'       => $error->get_error_message(),
			'raw'           => mb_substr( $generated['raw'], 0, 500 ),
		] );
	}

	$proposal = [
		'title'       => $generated['result']['title'],
		'alt_text'    => $generated['result']['alt_text'],
		'description' => $generated['result']['description'],
		'error'       => '',
	];

	ism_seo_record_result( $attachment_id, $proposal, $generated['usage'] );

	$state = ism_seo_get_state();

	wp_send_json_success( [
		'attachment_id' => $attachment_id,
		'proposal'      => $proposal,
		'usage'         => $generated['usage'],
		'run_usage'     => $state['usage'],
		'source'        => $generated['image']['source'],
		'model'         => $generated['model'],
	] );
}

/**
 * Store one proposal, accumulating run usage.
 *
 * @param int   $attachment_id
 * @param array $proposal
 * @param array $usage
 */
function ism_seo_record_result( int $attachment_id, array $proposal, array $usage ): void {
	$state = ism_seo_get_state();

	if ( $state['started'] === 0 ) {
		$state['started'] = time();
	}

	$state['results'][ (string) $attachment_id ] = $proposal;

	foreach ( $usage as $field => $value ) {
		$state['usage'][ $field ] = (int) ( $state['usage'][ $field ] ?? 0 ) + (int) $value;
	}

	ism_seo_set_state( $state );
}

/**
 * Record a failure against a row so a reload still shows what went wrong.
 *
 * @param int      $attachment_id
 * @param WP_Error $error
 * @param array    $usage Tokens spent before the failure, if any.
 */
function ism_seo_record_error( int $attachment_id, WP_Error $error, array $usage = [] ): void {
	$state = ism_seo_get_state();

	$state['results'][ (string) $attachment_id ] = [
		'title'       => '',
		'alt_text'    => '',
		'description' => '',
		'error'       => $error->get_error_message(),
	];

	foreach ( $usage as $field => $value ) {
		$state['usage'][ $field ] = (int) ( $state['usage'][ $field ] ?? 0 ) + (int) $value;
	}

	ism_seo_set_state( $state );
}

// ─────────────────────────────────────────────────────────────────────────────
// Applying
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: write selected rows to the media library.
 *
 * The values written are the ones submitted from the table, not the ones
 * stored server-side, because the point of the review step is that they can be
 * edited. An empty field means "leave this one alone" rather than "clear it",
 * so a partially reviewed row cannot silently wipe existing metadata.
 */
function ism_ajax_seo_apply(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$rows = (array) ( $_POST['rows'] ?? [] );
	if ( empty( $rows ) ) {
		wp_send_json_error( 'Nothing selected.' );
	}

	$applied  = 0;
	$skipped  = 0;
	$messages = [];

	foreach ( $rows as $row ) {
		// A row can stand for several attachments: duplicates share one
		// proposal, so approving it once writes to every copy rather than
		// leaving the others stale.
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $row['ids'] ?? [ $row['id'] ?? 0 ] ) ) ) );
		if ( empty( $ids ) ) {
			$skipped++;
			continue;
		}

		$title       = sanitize_text_field( wp_unslash( (string) ( $row['title'] ?? '' ) ) );
		$alt         = sanitize_text_field( wp_unslash( (string) ( $row['alt_text'] ?? '' ) ) );
		$description = sanitize_textarea_field( wp_unslash( (string) ( $row['description'] ?? '' ) ) );

		if ( $title === '' && $description === '' && $alt === '' ) {
			$skipped++;
			continue;
		}

		$wrote = [];

		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) !== 'attachment' ) {
				continue;
			}

			$post_update = [ 'ID' => $id ];

			if ( $title !== '' ) {
				$post_update['post_title'] = $title;
			}
			if ( $description !== '' ) {
				$post_update['post_content'] = $description;
			}
			if ( count( $post_update ) > 1 ) {
				wp_update_post( $post_update );
			}

			if ( $alt !== '' ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}

			$wrote[] = $id;
			$applied++;
		}

		if ( empty( $wrote ) ) {
			$skipped++;
			continue;
		}

		$messages[] = sprintf(
			'Updated %s%s',
			implode( ', ', array_map( function ( $i ) { return '#' . $i; }, $wrote ) ),
			count( $wrote ) > 1 ? ' (duplicate group)' : ' ' . basename( (string) get_attached_file( $wrote[0] ) )
		);
	}

	// Applied rows leave the run: their proposals are now the live values, and
	// keeping them would offer the user the chance to apply them twice.
	$state = ism_seo_get_state();
	foreach ( $rows as $row ) {
		foreach ( (array) ( $row['ids'] ?? [ $row['id'] ?? 0 ] ) as $id ) {
			unset( $state['results'][ (string) (int) $id ] );
		}
	}
	ism_seo_set_state( $state );

	wp_send_json_success( [
		'applied'  => $applied,
		'skipped'  => $skipped,
		'messages' => array_slice( $messages, 0, 50 ),
	] );
}

/**
 * AJAX: discard the run.
 */
function ism_ajax_seo_reset(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	ism_seo_clear_state();

	wp_send_json_success( [ 'cleared' => true ] );
}
