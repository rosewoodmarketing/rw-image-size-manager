<?php
/**
 * Repointing broken image references for RW Image Manager.
 *
 * The write half of the Broken Images tab, kept in its own file because it is
 * the only code in this plugin that edits page content. Everything here is
 * one reference at a time, previewed first, and triggered by an explicit
 * per-reference button. There is no bulk path and no "fix all".
 *
 * Three storage shapes, three genuinely different rewrites:
 *
 *   post_content     Markup. The attachment ID appears as a `wp-image-N`
 *                    class, inside block-comment JSON, and implicitly via the
 *                    `<img src>`. All three have to move together or the block
 *                    editor will flag the block as invalid.
 *
 *   _elementor_data  A JSON string in postmeta. It must be decoded, the node
 *                    carrying the old ID rewritten, and re-encoded — a string
 *                    replace on the raw JSON risks corrupting a page builder's
 *                    entire layout. Elementor also caches rendered CSS per
 *                    post, so the cache is cleared or the page keeps serving
 *                    the old image.
 *
 *   ACF postmeta     The simplest: the value is the ID. Still goes through the
 *                    same preview and confirmation as the others.
 *
 * Meta slashing is the trap worth naming: get_post_meta() returns unslashed
 * data and update_post_meta() re-slashes on the way in, so JSON written back
 * without wp_slash() loses a level of escaping and Elementor stops being able
 * to parse it.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find one reference in the cached scan by its key.
 *
 * Looked up rather than accepted from the request: a caller must not be able
 * to name an arbitrary post and meta key to rewrite.
 *
 * @param string $key
 * @return array|null
 */
function ism_repoint_find_ref( string $key ): ?array {
	$cache = ism_broken_cache_get();
	if ( $cache === null ) {
		return null;
	}

	foreach ( $cache['refs'] as $ref ) {
		if ( (string) ( $ref['key'] ?? '' ) === $key ) {
			return $ref;
		}
	}

	return null;
}

/**
 * Work out what a repoint would change, without changing it.
 *
 * @param array $ref
 * @param int   $new_id
 * @return array{ok:bool, message:string, source:string, changes:array}|WP_Error
 */
function ism_repoint_preview( array $ref, int $new_id ) {
	if ( get_post_type( $new_id ) !== 'attachment' ) {
		return new WP_Error( 'ism_repoint_bad_target', 'The replacement is not an attachment.' );
	}

	$post = get_post( (int) $ref['post_id'] );
	if ( ! $post ) {
		return new WP_Error( 'ism_repoint_no_post', 'The page holding this reference no longer exists.' );
	}

	switch ( $ref['source'] ) {
		case 'content':
			return ism_repoint_preview_content( $post, $ref, $new_id );

		case 'elementor':
			return ism_repoint_preview_elementor( $post, $ref, $new_id );

		case 'acf':
			return ism_repoint_preview_acf( $post, $ref, $new_id );

		case 'featured':
			return [
				'ok'      => true,
				'source'  => 'featured',
				'message' => 'Featured image will be changed.',
				'changes' => [ [
					'label' => 'Featured image',
					'from'  => '#' . $ref['ref'],
					'to'    => '#' . $new_id,
				] ],
			];
	}

	return new WP_Error( 'ism_repoint_unsupported', 'Repointing is not supported for this reference type yet.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// post_content
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rewrite an attachment reference inside post markup.
 *
 * @param WP_Post $post
 * @param array   $ref
 * @param int     $new_id
 * @param bool    $write
 * @return array|WP_Error
 */
function ism_repoint_content( WP_Post $post, array $ref, int $new_id, bool $write ) {
	$content = (string) $post->post_content;
	$before  = $content;
	$changes = [];

	$new_url = (string) wp_get_attachment_url( $new_id );
	if ( $new_url === '' ) {
		return new WP_Error( 'ism_repoint_no_url', 'The replacement attachment has no URL.' );
	}

	if ( $ref['type'] === 'stale_id' ) {
		$old_id = (int) $ref['ref'];

		// The class, the block attribute and the src all carry the same ID and
		// have to move together — a mismatched pair makes the block editor
		// report the block as invalid on next open.
		$content = preg_replace(
			'/\bwp-image-' . $old_id . '\b/',
			'wp-image-' . $new_id,
			$content,
			-1,
			$class_hits
		);
		if ( $class_hits ) {
			$changes[] = [ 'label' => 'Image class', 'from' => 'wp-image-' . $old_id, 'to' => 'wp-image-' . $new_id ];
		}

		$content = preg_replace(
			'/("(?:id|mediaId)"\s*:\s*)' . $old_id . '\b/',
			'${1}' . $new_id,
			$content,
			-1,
			$attr_hits
		);
		if ( $attr_hits ) {
			$changes[] = [ 'label' => 'Block attribute', 'from' => '"id":' . $old_id, 'to' => '"id":' . $new_id ];
		}
	}

	// Both fault types need the URL swapped: a stale ID's markup still points
	// at the deleted file, and a missing file is nothing but a URL.
	$old_url = (string) $ref['url'];
	if ( $old_url !== '' ) {
		$count   = 0;
		$content = str_replace( $old_url, $new_url, $content, $count );
		if ( $count ) {
			$changes[] = [ 'label' => 'Image URL', 'from' => $old_url, 'to' => $new_url ];
		}

		// srcset entries reference the same base filename at other sizes and
		// would otherwise keep serving the missing file on responsive layouts.
		$stem  = preg_replace( '/\.[a-z0-9]+$/i', '', basename( $old_url ) );
		$count = 0;
		if ( is_string( $stem ) && $stem !== '' ) {
			$content = preg_replace(
				'~' . preg_quote( dirname( $old_url ), '~' ) . '/' . preg_quote( $stem, '~' ) . '-\d+x\d+\.[a-z0-9]+~i',
				$new_url,
				$content,
				-1,
				$count
			);
			if ( $count ) {
				$changes[] = [ 'label' => 'Sized variants in srcset', 'from' => $stem . '-…x….ext (' . $count . ')', 'to' => $new_url ];
			}
		}
	}

	if ( empty( $changes ) ) {
		return new WP_Error( 'ism_repoint_nothing', 'Nothing in this page\'s content matched the reference. It may already have been fixed.' );
	}

	if ( $write && $content !== $before ) {
		wp_update_post( [ 'ID' => $post->ID, 'post_content' => wp_slash( $content ) ] );
	}

	return [ 'ok' => true, 'source' => 'content', 'message' => 'Post content will be rewritten.', 'changes' => $changes ];
}

function ism_repoint_preview_content( WP_Post $post, array $ref, int $new_id ) {
	return ism_repoint_content( $post, $ref, $new_id, false );
}

// ─────────────────────────────────────────────────────────────────────────────
// Elementor
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rewrite an image node inside _elementor_data.
 *
 * Decoded and re-encoded rather than string-replaced. The raw meta is one long
 * JSON document describing an entire page; a stray replacement inside it can
 * break the layout in ways that are not obvious until someone opens the editor.
 *
 * @param WP_Post $post
 * @param array   $ref
 * @param int     $new_id
 * @param bool    $write
 * @return array|WP_Error
 */
function ism_repoint_elementor( WP_Post $post, array $ref, int $new_id, bool $write ) {
	$raw = get_post_meta( $post->ID, '_elementor_data', true );
	if ( ! is_string( $raw ) || $raw === '' ) {
		return new WP_Error( 'ism_repoint_no_elementor', 'This page has no Elementor data.' );
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'ism_repoint_bad_json', 'Elementor data on this page is not valid JSON — refusing to touch it.' );
	}

	$new_url = (string) wp_get_attachment_url( $new_id );
	if ( $new_url === '' ) {
		return new WP_Error( 'ism_repoint_no_url', 'The replacement attachment has no URL.' );
	}

	$old_id  = $ref['type'] === 'stale_id' ? (int) $ref['ref'] : 0;
	$old_url = (string) $ref['url'];
	$changes = [];

	ism_repoint_walk_elementor( $data, $old_id, $old_url, $new_id, $new_url, $changes );

	if ( empty( $changes ) ) {
		return new WP_Error( 'ism_repoint_nothing', 'No matching image node was found in this page\'s Elementor data. It may already have been fixed.' );
	}

	if ( $write ) {
		$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'ism_repoint_encode', 'Could not re-encode the Elementor data — nothing was written.' );
		}

		// wp_slash is required: update_post_meta strips one level on the way in,
		// and JSON written without it comes back unparseable.
		update_post_meta( $post->ID, '_elementor_data', wp_slash( $encoded ) );

		// Elementor renders CSS per post and caches it. Without this the page
		// keeps serving the old image even though the data is correct.
		ism_repoint_clear_elementor_cache( (int) $post->ID );
	}

	return [ 'ok' => true, 'source' => 'elementor', 'message' => 'Elementor data will be rewritten.', 'changes' => $changes ];
}

function ism_repoint_preview_elementor( WP_Post $post, array $ref, int $new_id ) {
	return ism_repoint_elementor( $post, $ref, $new_id, false );
}

/**
 * Walk the tree, rewriting media nodes that match.
 *
 * Matches the same shape ism_usage_walk_elementor() looks for — a numeric `id`
 * beside a `url` string — so detection and repair cannot disagree about what
 * counts as an image node.
 *
 * @param array  $node
 * @param int    $old_id
 * @param string $old_url
 * @param int    $new_id
 * @param string $new_url
 * @param array  &$changes
 */
function ism_repoint_walk_elementor( array &$node, int $old_id, string $old_url, int $new_id, string $new_url, array &$changes ): void {
	foreach ( $node as $key => &$value ) {
		if ( ! is_array( $value ) ) {
			continue;
		}

		if ( isset( $value['id'], $value['url'] ) && is_string( $value['url'] ) ) {
			$matches_id  = $old_id > 0 && (int) $value['id'] === $old_id;
			$matches_url = $old_url !== '' && (string) $value['url'] === $old_url;

			if ( $matches_id || $matches_url ) {
				$changes[] = [
					'label' => 'Elementor image node',
					'from'  => '{"id":' . $value['id'] . ',"url":"' . $value['url'] . '"}',
					'to'    => '{"id":' . $new_id . ',"url":"' . $new_url . '"}',
				];

				$value['id']  = $new_id;
				$value['url'] = $new_url;
			}
		}

		ism_repoint_walk_elementor( $value, $old_id, $old_url, $new_id, $new_url, $changes );
	}
}

/**
 * Drop Elementor's cached CSS for one post.
 *
 * @param int $post_id
 */
function ism_repoint_clear_elementor_cache( int $post_id ): void {
	delete_post_meta( $post_id, '_elementor_css' );

	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// ACF
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rewrite an ACF media field value.
 *
 * @param WP_Post $post
 * @param array   $ref
 * @param int     $new_id
 * @param bool    $write
 * @return array|WP_Error
 */
function ism_repoint_acf( WP_Post $post, array $ref, int $new_id, bool $write ) {
	$field = (string) $ref['field'];
	if ( $field === '' ) {
		return new WP_Error( 'ism_repoint_no_field', 'This reference has no field recorded.' );
	}

	$current = get_post_meta( $post->ID, $field, true );
	$old_id  = (int) $ref['ref'];

	// Confirmed against the stored value rather than assumed from the scan: the
	// field may have been edited since, and overwriting a value someone has
	// already changed would be worse than reporting a stale scan.
	if ( is_numeric( $current ) ) {
		if ( (int) $current !== $old_id ) {
			return new WP_Error(
				'ism_repoint_changed',
				sprintf( 'The field now holds #%d, not #%d. Rescan before repointing.', (int) $current, $old_id )
			);
		}

		if ( $write ) {
			update_post_meta( $post->ID, $field, $new_id );
		}

		return [
			'ok'      => true,
			'source'  => 'acf',
			'message' => 'Custom field will be updated.',
			'changes' => [ [ 'label' => $field, 'from' => '#' . $old_id, 'to' => '#' . $new_id ] ],
		];
	}

	// Gallery fields hold a serialised list; only the matching entry moves.
	$list = maybe_unserialize( $current );
	if ( is_array( $list ) ) {
		$replaced = false;
		foreach ( $list as $i => $v ) {
			if ( (int) $v === $old_id ) {
				$list[ $i ] = $new_id;
				$replaced   = true;
			}
		}

		if ( ! $replaced ) {
			return new WP_Error( 'ism_repoint_changed', 'That ID is no longer in this field. Rescan before repointing.' );
		}

		if ( $write ) {
			update_post_meta( $post->ID, $field, $list );
		}

		return [
			'ok'      => true,
			'source'  => 'acf',
			'message' => 'Gallery field will be updated.',
			'changes' => [ [ 'label' => $field . ' (gallery)', 'from' => '#' . $old_id, 'to' => '#' . $new_id ] ],
		];
	}

	return new WP_Error( 'ism_repoint_unsupported', 'This field holds a value shape repointing does not handle.' );
}

function ism_repoint_preview_acf( WP_Post $post, array $ref, int $new_id ) {
	return ism_repoint_acf( $post, $ref, $new_id, false );
}

// ─────────────────────────────────────────────────────────────────────────────
// Apply
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Perform one repoint.
 *
 * @param array $ref
 * @param int   $new_id
 * @return array|WP_Error
 */
function ism_repoint_apply( array $ref, int $new_id ) {
	// Previewed again immediately before writing, so a reference that changed
	// between the user reading the preview and pressing the button is caught
	// rather than written over.
	$check = ism_repoint_preview( $ref, $new_id );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$post = get_post( (int) $ref['post_id'] );

	switch ( $ref['source'] ) {
		case 'content':
			return ism_repoint_content( $post, $ref, $new_id, true );

		case 'elementor':
			return ism_repoint_elementor( $post, $ref, $new_id, true );

		case 'acf':
			return ism_repoint_acf( $post, $ref, $new_id, true );

		case 'featured':
			set_post_thumbnail( $post->ID, $new_id );
			return [
				'ok'      => true,
				'source'  => 'featured',
				'message' => 'Featured image updated.',
				'changes' => [ [ 'label' => 'Featured image', 'from' => '#' . $ref['ref'], 'to' => '#' . $new_id ] ],
			];
	}

	return new WP_Error( 'ism_repoint_unsupported', 'Repointing is not supported for this reference type.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AJAX: preview one repoint.
 */
function ism_ajax_repoint_preview(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$key    = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
	$new_id = (int) ( $_POST['attachment_id'] ?? 0 );
	$ref    = ism_repoint_find_ref( $key );

	if ( ! $ref ) {
		wp_send_json_error( 'That reference is not in the current scan. Rescan and try again.' );
	}

	$preview = ism_repoint_preview( $ref, $new_id );
	if ( is_wp_error( $preview ) ) {
		wp_send_json_error( $preview->get_error_message() );
	}

	wp_send_json_success( $preview );
}

/**
 * AJAX: apply one repoint.
 */
function ism_ajax_repoint_apply(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$key    = sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
	$new_id = (int) ( $_POST['attachment_id'] ?? 0 );
	$ref    = ism_repoint_find_ref( $key );

	if ( ! $ref ) {
		wp_send_json_error( 'That reference is not in the current scan. Rescan and try again.' );
	}

	$result = ism_repoint_apply( $ref, $new_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	// The reference is fixed, so it leaves the cached scan and its recorded
	// choice is spent.
	ism_broken_remove_ref( $key );
	ism_broken_set_choice( $key, 0 );

	$result['key'] = $key;

	wp_send_json_success( $result );
}

// ─────────────────────────────────────────────────────────────────────────────
// Site-wide URL repointing
//
// The per-reference functions above fix one broken reference a human has
// reviewed. This is the other shape: a tool is about to rename or delete a
// file it knows the new location of, and every page pointing at the old one
// has to follow. No judgement is involved — the mapping is certain — so it
// runs without review, but only ever from a tool that is itself making the
// change.
//
// This is the gap that broke images on the pilot site. Both bulk tools moved
// the file and repointed the media library, which is what their UI copy said
// they did, while every page that had already inserted the old URL kept
// pointing at a file that no longer existed.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every URL form that resolves to one file: the file itself and its sized
 * variants.
 *
 * A page rarely stores the bare original — it stores `name-1024x768.jpg` in an
 * img src and the whole set again in srcset. Replacing only the exact URL
 * leaves those pointing at a deleted file.
 *
 * @param string $url
 * @return string Regex matching the URL and any -WxH variant of it.
 */
function ism_repoint_url_pattern( string $url ): string {
	$dir  = dirname( $url );
	$base = basename( $url );
	$ext  = pathinfo( $base, PATHINFO_EXTENSION );
	$stem = preg_replace( '/\.[a-z0-9]+$/i', '', $base );

	return '~' . preg_quote( $dir, '~' ) . '/' . preg_quote( (string) $stem, '~' )
		. '(?:-\d+x\d+)?\.' . preg_quote( $ext, '~' ) . '~i';
}

/**
 * Rewrite every reference to one URL, across post content and Elementor data.
 *
 * @param string $old_url
 * @param string $new_url
 * @param bool   $write   False to report what would change without changing it.
 * @return array{posts:int[], content:int, elementor:int}
 */
function ism_repoint_url_sitewide( string $old_url, string $new_url, bool $write = true ): array {
	global $wpdb;

	$result = [ 'posts' => [], 'content' => 0, 'elementor' => 0 ];

	if ( $old_url === '' || $new_url === '' || $old_url === $new_url ) {
		return $result;
	}

	$pattern = ism_repoint_url_pattern( $old_url );

	// Matched on the filename stem so a slashed or protocol-relative copy of
	// the same URL is still found.
	$needle = '%' . $wpdb->esc_like( preg_replace( '/\.[a-z0-9]+$/i', '', basename( $old_url ) ) ) . '%';

	// ── post_content ─────────────────────────────────────────────────────────
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_content FROM {$wpdb->posts}
		 WHERE post_content LIKE %s
		   AND post_type NOT IN ( 'revision', 'attachment' )
		   AND post_status NOT IN ( 'auto-draft', 'trash' )",
		$needle
	) );

	foreach ( $rows as $row ) {
		$updated = preg_replace( $pattern, $new_url, (string) $row->post_content, -1, $hits );
		if ( ! $hits || ! is_string( $updated ) ) {
			continue;
		}

		$result['content'] += $hits;
		$result['posts'][]  = (int) $row->ID;

		if ( $write ) {
			wp_update_post( [ 'ID' => (int) $row->ID, 'post_content' => wp_slash( $updated ) ] );
		}
	}

	// ── _elementor_data ──────────────────────────────────────────────────────
	//
	// Joined to posts and filtered to live content. Elementor writes
	// _elementor_data onto every revision, and on this site that is 3,612 rows
	// against 70 real ones — rewriting them would be slow, would bloat the
	// database, and would silently edit history nobody asked to change.
	$meta = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.post_id, m.meta_value
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_elementor_data'
		   AND m.meta_value LIKE %s
		   AND p.post_type NOT IN ( 'revision', 'attachment' )
		   AND p.post_status NOT IN ( 'auto-draft', 'trash' )",
		$needle
	) );

	foreach ( $meta as $row ) {
		$raw  = (string) $row->meta_value;
		$data = json_decode( $raw, true );

		// A page whose builder data will not decode is left alone and reported
		// rather than string-replaced: a bad substitution inside that JSON
		// breaks the whole layout, which is worse than a stale URL.
		if ( ! is_array( $data ) ) {
			continue;
		}

		$hits = 0;
		ism_repoint_walk_urls( $data, $pattern, $new_url, $hits );

		if ( ! $hits ) {
			continue;
		}

		$result['elementor'] += $hits;
		$result['posts'][]    = (int) $row->post_id;

		if ( $write ) {
			$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $encoded ) ) {
				update_post_meta( (int) $row->post_id, '_elementor_data', wp_slash( $encoded ) );
				ism_repoint_clear_elementor_cache( (int) $row->post_id );
			}
		}
	}

	$result['posts'] = array_values( array_unique( $result['posts'] ) );

	return $result;
}

/**
 * Replace matching URLs anywhere in a decoded Elementor tree.
 *
 * Every string is considered, not just media-control `url` keys: background
 * images, srcset copies and inline HTML widgets all hold the same URL in
 * different shapes.
 *
 * @param array  $node
 * @param string $pattern
 * @param string $new_url
 * @param int    &$hits
 */
function ism_repoint_walk_urls( array &$node, string $pattern, string $new_url, int &$hits ): void {
	foreach ( $node as $key => &$value ) {
		if ( is_array( $value ) ) {
			ism_repoint_walk_urls( $value, $pattern, $new_url, $hits );
			continue;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			continue;
		}

		$replaced = preg_replace( $pattern, $new_url, $value, -1, $n );
		if ( $n && is_string( $replaced ) ) {
			$value = $replaced;
			$hits += $n;
		}
	}
}

/**
 * Repoint references after a tool has moved an attachment's file.
 *
 * Called by the bulk tools with the paths they are about to change between.
 *
 * @param int    $attachment_id
 * @param string $old_path Absolute path the attachment used to live at.
 * @param string $new_path Absolute path it now lives at.
 * @return array
 */
function ism_repoint_after_file_move( int $attachment_id, string $old_path, string $new_path ): array {
	$uploads = wp_get_upload_dir();

	$to_url = function ( string $path ) use ( $uploads ) {
		$relative = _wp_relative_upload_path( $path );
		return $relative ? trailingslashit( $uploads['baseurl'] ) . $relative : '';
	};

	$old_url = $to_url( $old_path );
	$new_url = $to_url( $new_path );

	if ( $old_url === '' || $new_url === '' ) {
		return [ 'posts' => [], 'content' => 0, 'elementor' => 0 ];
	}

	return ism_repoint_url_sitewide( $old_url, $new_url, true );
}
