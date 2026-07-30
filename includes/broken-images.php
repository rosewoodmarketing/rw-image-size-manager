<?php
/**
 * Broken image detection and match suggestion for RW Image Manager.
 *
 * Two different faults, both of which leave a page visibly broken and neither
 * of which WordPress reports:
 *
 *   stale_id   A reference to an attachment ID that no longer exists. The
 *              attachment was deleted; the page still points at it.
 *              ism_usage_filter_image_attachments() already identifies these —
 *              the usage index throws them away so a deleted image does not
 *              linger in the index. Here the discarded set is the payload.
 *
 *   missing_file  A reference that resolves to a URL under uploads/ with no
 *              file behind it. The attachment row may still exist; the bytes
 *              do not.
 *
 * Recoverability is the interesting part. Once an attachment post is deleted
 * there is no _wp_attached_file to look a filename up from, so a bare ID —
 * which is all ACF and gallery shortcodes store — is unrecoverable on its own.
 * Elementor stores `{id, url}` together and block markup pairs `wp-image-N`
 * with an `<img src>`, so those carry their own filename. Every record says
 * which case it is rather than pretending to a filename it does not have.
 *
 * This file detects and suggests. It does not repoint anything: there is no
 * write path here, by design, because repointing has to rewrite Elementor JSON
 * and ACF meta correctly and that deserves to be built and tested on its own.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cached scan results. Option, autoload off — same reasoning as the SEO scan. */
define( 'ISM_BROKEN_CACHE_KEY', 'ism_broken_scan_cache' );

/** Row-shape version; a cache from an older shape is discarded, not served. */
define( 'ISM_BROKEN_CACHE_VERSION', 2 );

/** Posts examined per batch. Elementor JSON makes this the expensive walk. */
define( 'ISM_BROKEN_BATCH', 40 );

/** Suggestions offered per broken reference. */
define( 'ISM_BROKEN_SUGGESTIONS', 5 );

// ─────────────────────────────────────────────────────────────────────────────
// Cache
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{refs:array, scanned_at:int}|null
 */
function ism_broken_cache_get(): ?array {
	$cache = get_option( ISM_BROKEN_CACHE_KEY, null );

	if ( ! is_array( $cache ) || ! isset( $cache['refs'] ) ) {
		return null;
	}

	if ( (int) ( $cache['version'] ?? 0 ) !== ISM_BROKEN_CACHE_VERSION ) {
		return null;
	}

	return [
		'refs'       => (array) $cache['refs'],
		'scanned_at' => (int) ( $cache['scanned_at'] ?? 0 ),
	];
}

/**
 * @param array $refs
 */
function ism_broken_cache_set( array $refs ): void {
	update_option( ISM_BROKEN_CACHE_KEY, [
		'refs'       => $refs,
		'scanned_at' => time(),
		'version'    => ISM_BROKEN_CACHE_VERSION,
	], false );
}

function ism_broken_cache_clear(): void {
	delete_option( ISM_BROKEN_CACHE_KEY );
	delete_option( ISM_BROKEN_CACHE_KEY . '_partial' );
}

// ─────────────────────────────────────────────────────────────────────────────
// Detection
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every broken image reference in one post.
 *
 * @param int $post_id
 * @return array[]
 */
function ism_broken_scan_post( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return [];
	}

	$refs = array_merge(
		ism_broken_stale_ids( $post ),
		ism_broken_missing_files( $post )
	);

	return $refs;
}

/**
 * References to attachment IDs that no longer exist.
 *
 * @param WP_Post $post
 * @return array[]
 */
function ism_broken_stale_ids( WP_Post $post ): array {
	$post_id = (int) $post->ID;

	// Where each id came from, so a later repoint knows what to rewrite.
	$sources = [];
	$urls    = [];

	// ── Featured image ───────────────────────────────────────────────────────
	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( $thumb ) {
		$sources[ $thumb ][] = [ 'source' => 'featured', 'field' => '_thumbnail_id' ];
	}

	// ── Block and classic content ────────────────────────────────────────────
	$content = (string) $post->post_content;
	if ( $content !== '' ) {
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$sources[ (int) $id ][] = [ 'source' => 'content', 'field' => 'post_content' ];
			}
		}

		// The <img> that carries the class also carries the filename, which is
		// the only way a deleted attachment stays identifiable.
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/wp-image-(\d+)/', $tag, $idm )
					&& preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $srcm ) ) {
					$urls[ (int) $idm[1] ] = $srcm[1];
				}
			}
		}
	}

	// ── Elementor ────────────────────────────────────────────────────────────
	$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $elementor_raw ) && $elementor_raw !== '' ) {
		$elements = json_decode( $elementor_raw, true );
		if ( is_array( $elements ) ) {
			$found = [];
			$eurls = [];
			$ekeys = [];
			ism_usage_walk_elementor( $elements, $found, $eurls, $ekeys );

			foreach ( array_keys( $found ) as $id ) {
				$sources[ (int) $id ][] = [
					'source'  => 'elementor',
					'field'   => '_elementor_data',
					'control' => (string) ( $ekeys[ (int) $id ] ?? '' ),
				];
			}
			foreach ( $eurls as $id => $url ) {
				$urls[ (int) $id ] = $url;
			}
		}
	}

	// ── ACF and other custom fields ──────────────────────────────────────────
	// Reuses the usage index's field identification so the two agree about what
	// counts as a media field.
	global $wpdb;

	$media_keys = ism_usage_acf_media_field_keys();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.meta_key, m.meta_value, c.meta_value AS field_key
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->postmeta} c
		    ON c.post_id = m.post_id AND c.meta_key = CONCAT( '_', m.meta_key )
		 WHERE m.post_id = %d AND c.meta_value LIKE %s",
		$post_id,
		$wpdb->esc_like( 'field_' ) . '%'
	) );

	foreach ( $rows as $row ) {
		if ( ! empty( $media_keys ) && ! isset( $media_keys[ (string) $row->field_key ] ) ) {
			continue;
		}
		foreach ( ism_usage_ids_from_value( $row->meta_value ) as $id ) {
			$sources[ $id ][] = [ 'source' => 'acf', 'field' => (string) $row->meta_key ];
		}
	}

	if ( empty( $sources ) ) {
		return [];
	}

	// The index drops these; here they are the point.
	$valid  = array_flip( ism_usage_filter_image_attachments( array_keys( $sources ) ) );
	$broken = [];

	foreach ( $sources as $id => $places ) {
		if ( isset( $valid[ $id ] ) ) {
			continue;
		}

		// An ID that still resolves to a non-image attachment (a PDF, say) is
		// not a broken image — it is simply not an image.
		if ( get_post_type( $id ) === 'attachment' ) {
			continue;
		}

		$url      = (string) ( $urls[ $id ] ?? '' );
		$filename = $url !== '' ? ism_broken_filename_from_url( $url ) : '';

		foreach ( $places as $place ) {
			$broken[] = [
				'key'         => ism_broken_key( $post_id, $place['source'], $place['field'], (string) $id ),
				'type'        => 'stale_id',
				'post_id'     => $post_id,
				'post_title'  => $post->post_title !== '' ? $post->post_title : '(no title)',
				'post_type'   => $post->post_type,
				'permalink'   => (string) ( get_permalink( $post_id ) ?: '' ),
				'source'      => $place['source'],
				'field'       => $place['field'],
				'ref'         => (string) $id,
				'url'         => $url,
				'control'     => (string) ( $place['control'] ?? '' ),
				'filename'    => $filename,
				'recoverable' => $filename !== '',
				'severity'    => ism_broken_severity( 'stale_id', $post, $url, (string) ( $place['control'] ?? '' ) ),
			];
		}
	}

	return $broken;
}

/**
 * References whose file is not on disk.
 *
 * post_content is scanned as the primary case. Elementor URLs are checked too:
 * on a builder site post_content is usually empty, so restricting this to
 * post_content alone would report almost nothing on exactly the sites that
 * need it most.
 *
 * @param WP_Post $post
 * @return array[]
 */
function ism_broken_missing_files( WP_Post $post ): array {
	$post_id = (int) $post->ID;
	$found   = [];

	$candidates = [];

	$content = (string) $post->post_content;
	if ( $content !== '' && preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $m ) ) {
		foreach ( $m[1] as $src ) {
			$candidates[ $src ] = [ 'source' => 'content', 'field' => 'post_content' ];
		}
	}

	$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $elementor_raw ) && $elementor_raw !== '' ) {
		$elements = json_decode( $elementor_raw, true );
		if ( is_array( $elements ) ) {
			$ids  = [];
			$urls = [];
			ism_usage_walk_elementor( $elements, $ids, $urls );
			foreach ( $urls as $url ) {
				if ( ! isset( $candidates[ $url ] ) ) {
					$candidates[ $url ] = [ 'source' => 'elementor', 'field' => '_elementor_data' ];
				}
			}
		}
	}

	foreach ( $candidates as $url => $place ) {
		$path = ism_broken_url_to_path( (string) $url );
		if ( $path === '' || file_exists( $path ) ) {
			continue;
		}

		$found[] = [
			'key'         => ism_broken_key( $post_id, $place['source'], $place['field'], (string) $url ),
			'type'        => 'missing_file',
			'post_id'     => $post_id,
			'post_title'  => $post->post_title !== '' ? $post->post_title : '(no title)',
			'post_type'   => $post->post_type,
			'permalink'   => (string) ( get_permalink( $post_id ) ?: '' ),
			'source'      => $place['source'],
			'field'       => $place['field'],
			'ref'         => (string) $url,
			'url'         => (string) $url,
			'control'     => '',
			'filename'    => ism_broken_filename_from_url( (string) $url ),
			'recoverable' => true,
			'severity'    => ism_broken_severity( 'missing_file', $post, (string) $url, '' ),
		];
	}

	return $found;
}

/**
 * How likely this reference is to be a hole a visitor actually sees.
 *
 * Detection finds references; it cannot see a rendered page. Several kinds of
 * broken reference never appear to anyone, and reporting them at the same
 * weight as a genuine gap is what makes a list of 110 useless:
 *
 *   renders_ok     The attachment row is gone but the file it points at is
 *                  still on disk. Both Elementor and post markup render the
 *                  URL, not the ID, so the page looks correct. The stale ID
 *                  still costs a broken srcset and a failed alt-text lookup,
 *                  and it will confuse the block editor, but nobody sees a gap.
 *   responsive     Sits under a *_tablet or *_mobile control, so it only ever
 *                  renders at that breakpoint. Invisible on a desktop check.
 *   template       Lives on a saved Elementor template. Whether it renders at
 *                  all depends on whether that template is used, and where.
 *                  Widget settings there are frequently defaults that dynamic
 *                  or ACF data replaces at render time.
 *   likely_visible Everything else: no file to render, on a real page.
 *
 * This is a heuristic and is labelled as one. ism_broken_verify_live() is the
 * way to actually settle it.
 *
 * @param string  $type
 * @param WP_Post $post
 * @param string  $url
 * @param string  $control
 * @return string
 */
function ism_broken_severity( string $type, WP_Post $post, string $url, string $control ): string {
	if ( $control !== '' && preg_match( '/_(tablet|mobile)(_\w+)?$/', $control ) ) {
		return 'responsive';
	}

	if ( $post->post_type === 'elementor_library' ) {
		return 'template';
	}

	if ( $type === 'stale_id' && $url !== '' ) {
		$path = ism_broken_url_to_path( $url );
		if ( $path !== '' && file_exists( $path ) ) {
			return 'renders_ok';
		}
	}

	return 'likely_visible';
}

/**
 * Fetch the live page and report whether the broken image is really on it.
 *
 * The only way to settle the question. The heuristic above reasons about where
 * a reference sits; this looks at what the site actually sends a visitor.
 *
 * @param array $ref
 * @return array{checked:bool, found:bool, status:int, message:string, url:string}
 */
function ism_broken_verify_live( array $ref ): array {
	$permalink = get_permalink( (int) $ref['post_id'] );

	if ( ! $permalink ) {
		return [ 'checked' => false, 'found' => false, 'status' => 0, 'url' => '',
			'message' => 'This item has no public URL, so it cannot be checked directly.' ];
	}

	$response = wp_remote_get( $permalink, [
		'timeout'   => 25,
		'sslverify' => false, // local development certificates
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'checked' => false, 'found' => false, 'status' => 0, 'url' => $permalink,
			'message' => 'Could not load the page: ' . $response->get_error_message() ];
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$html   = (string) wp_remote_retrieve_body( $response );

	if ( $status !== 200 ) {
		return [ 'checked' => false, 'found' => false, 'status' => $status, 'url' => $permalink,
			'message' => 'The page returned HTTP ' . $status . '.' ];
	}

	// Match on the filename rather than the whole URL: the rendered markup may
	// use a different size variant or a CDN host for the same file.
	$needle = $ref['filename'] !== '' ? $ref['filename'] : basename( (string) $ref['url'] );
	$stem   = (string) preg_replace( '/\.[a-z0-9]+$/i', '', $needle );

	if ( $stem === '' ) {
		return [ 'checked' => false, 'found' => false, 'status' => $status, 'url' => $permalink,
			'message' => 'This reference stores no filename, so there is nothing to search the page for.' ];
	}

	$found = stripos( $html, $stem ) !== false;

	return [
		'checked' => true,
		'found'   => $found,
		'status'  => $status,
		'url'     => $permalink,
		'message' => $found
			? 'The rendered page still requests this file, so a visitor sees a broken image here. Worth fixing.'
			: 'The rendered page never requests this file. Nothing visibly broken — the reference is leftover data, safe to leave or clean up at your convenience.',
	];
}

/**
 * AJAX: check one reference against the live page.
 */
function ism_ajax_broken_verify(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 60 ); // phpcs:ignore

	$key = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
	$ref = ism_repoint_find_ref( $key );

	if ( ! $ref ) {
		wp_send_json_error( 'That reference is not in the current scan.' );
	}

	wp_send_json_success( ism_broken_verify_live( $ref ) );
}

/**
 * Stable identity for one broken reference.
 *
 * Survives a rescan so a chosen replacement stays attached to the reference it
 * was chosen for, and is what the repoint step will address when it exists.
 *
 * @param int    $post_id
 * @param string $source
 * @param string $field
 * @param string $ref
 * @return string
 */
function ism_broken_key( int $post_id, string $source, string $field, string $ref ): string {
	return substr( md5( $post_id . '|' . $source . '|' . $field . '|' . $ref ), 0, 16 );
}

/**
 * Replacements a human has picked, keyed by reference.
 *
 * Recorded but never acted on: there is no repoint path in this file. Choosing
 * is useful on its own — it is the decision, and it is the slow part — and it
 * is the input the repoint step will consume once that is built and tested.
 *
 * @return array<string,int>
 */
function ism_broken_choices(): array {
	$choices = get_option( ISM_BROKEN_CACHE_KEY . '_choices', [] );

	return is_array( $choices ) ? array_map( 'intval', $choices ) : [];
}

/**
 * Record or clear a chosen replacement.
 *
 * @param string $key
 * @param int    $attachment_id 0 clears.
 */
function ism_broken_set_choice( string $key, int $attachment_id ): void {
	$choices = ism_broken_choices();

	if ( $attachment_id < 1 ) {
		unset( $choices[ $key ] );
	} else {
		$choices[ $key ] = $attachment_id;
	}

	update_option( ISM_BROKEN_CACHE_KEY . '_choices', $choices, false );
}

/**
 * Drop one reference from the cached scan, once it has been repaired.
 *
 * @param string $key
 */
function ism_broken_remove_ref( string $key ): void {
	$cache = ism_broken_cache_get();
	if ( $cache === null ) {
		return;
	}

	$refs = array_values( array_filter( $cache['refs'], function ( $ref ) use ( $key ) {
		return (string) ( $ref['key'] ?? '' ) !== $key;
	} ) );

	update_option( ISM_BROKEN_CACHE_KEY, [
		'refs'       => $refs,
		'scanned_at' => $cache['scanned_at'],
		'version'    => ISM_BROKEN_CACHE_VERSION,
	], false );
}

/**
 * Local path for an uploads URL, or an empty string when it is not one.
 *
 * @param string $url
 * @return string
 */
function ism_broken_url_to_path( string $url ): string {
	if ( strpos( $url, '/wp-content/uploads/' ) === false ) {
		return '';
	}

	// Data URIs and anything with a query string are not files on disk.
	$url = strtok( $url, '?' );

	$uploads  = wp_get_upload_dir();
	$relative = substr( $url, strpos( $url, '/wp-content/uploads/' ) + strlen( '/wp-content/uploads/' ) );

	if ( $relative === '' || strpos( $relative, '..' ) !== false ) {
		return '';
	}

	return trailingslashit( $uploads['basedir'] ) . urldecode( $relative );
}

/**
 * Original filename from a URL, with any size suffix removed.
 *
 * @param string $url
 * @return string
 */
function ism_broken_filename_from_url( string $url ): string {
	$name = basename( (string) strtok( $url, '?' ) );

	if ( $name === '' ) {
		return '';
	}

	// A -300x200 suffix belongs to a generated size, not the upload.
	return (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $name );
}

// ─────────────────────────────────────────────────────────────────────────────
// Suggestion
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Token index of the current media library, for matching.
 *
 * Built once per request. Uses the same de-slugifier the prompt builder uses,
 * so "buckeye-standing-seam-roofs-177-1024x768.jpg" reduces to the same words
 * whichever side of the comparison it is on.
 *
 * @return array<int,array{tokens:array, base:string}>
 */
function ism_broken_library_index(): array {
	static $index = null;

	if ( $index !== null ) {
		return $index;
	}

	global $wpdb;

	$index = [];

	$rows = $wpdb->get_results(
		"SELECT p.ID, m.meta_value AS file
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
	);

	foreach ( $rows as $row ) {
		$base = basename( (string) $row->file );
		$hint = ism_context_name_hint( $base );

		$index[ (int) $row->ID ] = [
			'base'   => $base,
			'stem'   => strtolower( (string) preg_replace( '/\.[a-z0-9]+$/i', '', $base ) ),
			'tokens' => $hint === '' ? [] : array_values( array_unique( explode( ' ', $hint ) ) ),
		];
	}

	return $index;
}

/**
 * Ranked candidate replacements for a broken reference.
 *
 * A shortlist, never a single answer. Filenames are a weak signal — two
 * unrelated photographs from the same shoot share most of their tokens — so
 * the decision stays with a person and the score is shown rather than hidden.
 *
 * @param string $filename
 * @param int    $limit
 * @return array[]
 */
function ism_broken_suggest( string $filename, int $limit = ISM_BROKEN_SUGGESTIONS ): array {
	if ( $filename === '' ) {
		return [];
	}

	$stem   = strtolower( (string) preg_replace( '/\.[a-z0-9]+$/i', '', $filename ) );
	$hint   = ism_context_name_hint( $filename );
	$tokens = $hint === '' ? [] : array_values( array_unique( explode( ' ', $hint ) ) );

	$scored = [];

	foreach ( ism_broken_library_index() as $id => $entry ) {
		$score  = 0.0;
		$reason = '';

		// Same filename, different extension — exactly what a format
		// conversion leaves behind, and the strongest signal available.
		if ( $entry['stem'] === $stem ) {
			$score  = 1.0;
			$reason = 'same filename';
		} elseif ( $stem !== '' && ( strpos( $entry['stem'], $stem ) === 0 || strpos( $stem, $entry['stem'] ) === 0 ) ) {
			$score  = 0.85;
			$reason = 'filename prefix match';
		} elseif ( ! empty( $tokens ) && ! empty( $entry['tokens'] ) ) {
			$shared = array_intersect( $tokens, $entry['tokens'] );
			$union  = array_unique( array_merge( $tokens, $entry['tokens'] ) );
			if ( ! empty( $shared ) ) {
				$score  = count( $shared ) / max( 1, count( $union ) );
				$reason = count( $shared ) . ' of ' . count( $union ) . ' words in common';
			}
		}

		if ( $score <= 0.0 ) {
			continue;
		}

		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );

		$scored[] = [
			'id'       => (int) $id,
			'filename' => $entry['base'],
			'score'    => round( $score, 3 ),
			'percent'  => (int) round( $score * 100 ),
			'reason'   => $reason,
			'thumb'    => is_array( $thumb ) && ! empty( $thumb[0] ) ? (string) $thumb[0] : '',
			'edit_url' => (string) ( get_edit_post_link( $id, 'raw' ) ?: '' ),
		];
	}

	usort( $scored, function ( $a, $b ) {
		return ( $b['score'] <=> $a['score'] ) ?: ( $a['id'] <=> $b['id'] );
	} );

	return array_slice( $scored, 0, $limit );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: start a broken-image scan.
 */
function ism_ajax_broken_init(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$force = ! empty( $_POST['force'] );
	$cache = $force ? null : ism_broken_cache_get();

	if ( $force ) {
		ism_broken_cache_clear();
	}

	wp_send_json_success( [
		'total'       => count( ism_usage_collect_post_ids() ),
		'cached'      => $cache !== null,
		'scanned_ago' => $cache && $cache['scanned_at'] ? human_time_diff( $cache['scanned_at'] ) : '',
		'refs'        => $cache ? $cache['refs'] : [],
		'choices'     => ism_broken_choices(),
	] );
}

/**
 * AJAX: scan one batch of posts.
 */
function ism_ajax_broken_batch(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	@set_time_limit( 120 ); // phpcs:ignore

	$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$ids    = ism_usage_collect_post_ids();
	$batch  = array_slice( $ids, $offset, ISM_BROKEN_BATCH );

	$refs = [];
	foreach ( $batch as $pid ) {
		foreach ( ism_broken_scan_post( (int) $pid ) as $ref ) {
			$refs[] = $ref;
		}
	}

	$new_offset = $offset + count( $batch );
	$done       = $new_offset >= count( $ids );

	$partial = get_option( ISM_BROKEN_CACHE_KEY . '_partial', [] );
	if ( $offset === 0 || ! is_array( $partial ) ) {
		$partial = [];
	}
	$partial = array_merge( $partial, $refs );

	if ( $done ) {
		ism_broken_cache_set( $partial );
		delete_option( ISM_BROKEN_CACHE_KEY . '_partial' );
	} else {
		update_option( ISM_BROKEN_CACHE_KEY . '_partial', $partial, false );
	}

	wp_send_json_success( [
		'refs'   => $refs,
		'offset' => $new_offset,
		'total'  => count( $ids ),
		'done'   => $done,
	] );
}

/**
 * AJAX: record which attachment a human picked for a broken reference.
 *
 * Stores the decision only. Nothing is rewritten — the repoint step that will
 * consume these does not exist yet, and the panel says so.
 */
function ism_ajax_broken_choose(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$key = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
	$id  = (int) ( $_POST['attachment_id'] ?? 0 );

	if ( $key === '' ) {
		wp_send_json_error( 'No reference specified.' );
	}

	if ( $id > 0 && get_post_type( $id ) !== 'attachment' ) {
		wp_send_json_error( 'That is not an attachment.' );
	}

	ism_broken_set_choice( $key, $id );

	$thumb = $id ? wp_get_attachment_image_src( $id, 'thumbnail' ) : null;

	wp_send_json_success( [
		'key'           => $key,
		'attachment_id' => $id,
		'filename'      => $id ? basename( (string) get_attached_file( $id ) ) : '',
		'thumb'         => is_array( $thumb ) && ! empty( $thumb[0] ) ? (string) $thumb[0] : '',
	] );
}

/**
 * AJAX: suggestions for one filename.
 */
function ism_ajax_broken_suggest(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$filename = sanitize_text_field( wp_unslash( (string) ( $_POST['filename'] ?? '' ) ) );

	wp_send_json_success( [
		'filename'    => $filename,
		'suggestions' => ism_broken_suggest( $filename ),
	] );
}
