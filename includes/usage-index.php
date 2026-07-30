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
		// Records written before non-post sources existed carry no object_type
		// and are posts by definition.
		$type = (string) ( $usage['object_type'] ?? 'post' );

		if ( $type === 'term' ) {
			$term = get_term( (int) ( $usage['object_id'] ?? 0 ) );
			if ( ! $term || is_wp_error( $term ) ) {
				continue; // Term deleted since the last scan.
			}

			$link = get_term_link( $term );

			$out[] = [
				'post_id'     => 0,
				'source'      => (string) $usage['source'],
				'title'       => $term->name !== '' ? $term->name : '(no name)',
				'post_type'   => $term->taxonomy,
				'object_type' => 'term',
				'object_id'   => (int) $term->term_id,
				'edit_url'    => (string) ( get_edit_term_link( $term->term_id, $term->taxonomy ) ?: '' ),
				'permalink'   => is_wp_error( $link ) ? '' : (string) $link,
			];
			continue;
		}

		if ( $type === 'option' ) {
			$out[] = [
				'post_id'     => 0,
				'source'      => (string) $usage['source'],
				'title'       => 'Site-wide option',
				'post_type'   => 'option',
				'object_type' => 'option',
				'object_id'   => (string) ( $usage['object_id'] ?? '' ),
				'edit_url'    => '',
				'permalink'   => '',
			];
			continue;
		}

		$post = get_post( (int) ( $usage['post_id'] ?? 0 ) );
		if ( ! $post ) {
			continue; // Post deleted since the last scan.
		}

		$out[] = [
			'post_id'     => (int) $post->ID,
			'source'      => (string) $usage['source'],
			'title'       => $post->post_title !== '' ? $post->post_title : '(no title)',
			'post_type'   => $post->post_type,
			'object_type' => 'post',
			'object_id'   => (int) $post->ID,
			'edit_url'    => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			'permalink'   => get_permalink( $post->ID ) ?: '',
		];
	}

	return $out;
}

/**
 * Stable identity for one usage record, used to merge idempotently.
 *
 * @param array $usage
 * @return string
 */
function ism_usage_record_key( array $usage ): string {
	$type = (string) ( $usage['object_type'] ?? 'post' );

	if ( $type === 'post' ) {
		return 'post:' . (int) ( $usage['post_id'] ?? 0 );
	}

	return $type . ':' . (string) ( $usage['object_id'] ?? '' );
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

	// ── Custom fields ────────────────────────────────────────────────────────
	foreach ( ism_usage_extract_from_meta( $post_id ) as $id => $source ) {
		$found[ (int) $id ] = $found[ (int) $id ] ?? $source;
	}

	// ── Bare uploads URLs in post_content ────────────────────────────────────
	// A theme or custom plugin can emit an <img> with no wp-image-N class, so
	// the markup above finds nothing. Resolving the URL catches those.
	if ( $content !== '' ) {
		foreach ( ism_usage_ids_from_urls( $content ) as $id ) {
			$found[ (int) $id ] = $found[ (int) $id ] ?? 'content';
		}
	}

	unset( $found[0] );

	return $found;
}

// ─────────────────────────────────────────────────────────────────────────────
// Custom fields
//
// The three meta keys handled above are the ones WordPress and WooCommerce
// define. Everything else a site stores is invented by a theme or plugin, and
// on an ACF site that is where most non-Elementor imagery lives: a custom
// widget renders get_field( 'warranty_badge', $post_id ) and nothing in the
// post content, block markup or Elementor tree ever mentions the attachment.
//
// Two independent passes, because they fail in different ways:
//
//   1. ACF-owned fields, identified exactly. ACF writes a companion `_key`
//      row holding the field key for every value it stores, and the field
//      definition names its own type. Joining the two means only fields
//      actually declared as image, gallery or file are read, so a numeric
//      field that merely happens to equal an attachment ID is never mistaken
//      for a reference.
//   2. Any meta value containing an uploads URL, for plugins that store a URL
//      or that do not use ACF at all.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Attachment IDs referenced by a post's custom fields.
 *
 * @param int $post_id
 * @return array<int,string> attachment_id => source label.
 */
function ism_usage_extract_from_meta( int $post_id ): array {
	global $wpdb;

	$found = [];

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.meta_key, m.meta_value, c.meta_value AS field_key
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->postmeta} c
		    ON c.post_id = m.post_id AND c.meta_key = CONCAT( '_', m.meta_key )
		 WHERE m.post_id = %d
		   AND c.meta_value LIKE %s",
		$post_id,
		$wpdb->esc_like( 'field_' ) . '%'
	) );

	$media_keys = ism_usage_acf_media_field_keys();

	foreach ( $rows as $row ) {
		// When field definitions are unreadable the map comes back empty, in
		// which case every ACF value is considered and
		// ism_usage_filter_image_attachments() remains the only guard. Better
		// a wide net than silently indexing nothing.
		if ( ! empty( $media_keys ) && ! isset( $media_keys[ (string) $row->field_key ] ) ) {
			continue;
		}

		foreach ( ism_usage_ids_from_value( $row->meta_value ) as $id ) {
			$found[ $id ] = 'acf';
		}
	}

	// Second pass: uploads URLs anywhere in this post's meta. Elementor data is
	// excluded because the walker above already read it properly, and it is by
	// far the largest value on the row.
	$meta_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE post_id = %d
		   AND meta_key NOT IN ( '_elementor_data', %s )
		   AND meta_value LIKE %s",
		$post_id,
		ISM_USAGE_META_KEY,
		'%' . $wpdb->esc_like( '/uploads/' ) . '%'
	) );

	foreach ( $meta_rows as $row ) {
		foreach ( ism_usage_ids_from_urls( (string) $row->meta_value ) as $id ) {
			$found[ $id ] = $found[ $id ] ?? 'custom_field';
		}
	}

	return $found;
}

/**
 * ACF field keys whose definition declares them as media.
 *
 * Returns an empty array on sites whose field groups live in local JSON or PHP
 * rather than the database, which callers treat as "no filter available".
 *
 * @return array<string,bool> field_key => true
 */
function ism_usage_acf_media_field_keys(): array {
	static $keys = null;

	if ( $keys !== null ) {
		return $keys;
	}

	global $wpdb;

	$keys = [];

	$rows = $wpdb->get_results(
		"SELECT post_name, post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field'"
	);

	foreach ( $rows as $row ) {
		$definition = maybe_unserialize( $row->post_content );
		$type       = is_array( $definition ) ? (string) ( $definition['type'] ?? '' ) : '';

		if ( in_array( $type, [ 'image', 'gallery', 'file' ], true ) ) {
			$keys[ (string) $row->post_name ] = true;
		}
	}

	return $keys;
}

/**
 * Attachment IDs held in a single meta value.
 *
 * ACF stores an image field as a bare ID and a gallery as a serialised array of
 * IDs, whatever return format the field is configured to hand back at runtime.
 *
 * @param mixed $value
 * @return int[]
 */
function ism_usage_ids_from_value( $value ): array {
	$out   = [];
	$value = maybe_unserialize( $value );

	if ( is_numeric( $value ) ) {
		$id = (int) $value;
		if ( $id > 0 ) {
			$out[] = $id;
		}
		return $out;
	}

	if ( is_array( $value ) ) {
		array_walk_recursive( $value, function ( $item ) use ( &$out ) {
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$out[] = (int) $item;
			}
		} );
	}

	return $out;
}

/**
 * Resolve uploads URLs in a blob of text back to attachment IDs.
 *
 * @param string $text
 * @return int[]
 */
function ism_usage_ids_from_urls( string $text ): array {
	if ( strpos( $text, '/uploads/' ) === false ) {
		return [];
	}

	if ( ! preg_match_all( '~/uploads/((?:[\w.-]+/)*[^\s"\'<>?)\\\\]+\.[a-z0-9]{2,5})~i', $text, $matches ) ) {
		return [];
	}

	[ $exact, $stripped ] = ism_usage_attachment_maps();

	$out = [];

	foreach ( array_unique( $matches[1] ) as $relative ) {
		$relative = urldecode( $relative );

		if ( isset( $exact[ $relative ] ) ) {
			$out[] = $exact[ $relative ];
			continue;
		}

		// Sub-size filename: strip the -300x200 suffix back to the original.
		$full = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $relative );
		if ( is_string( $full ) && isset( $exact[ $full ] ) ) {
			$out[] = $exact[ $full ];
			continue;
		}

		// Converted variant: same path, different extension. Optimisation
		// plugins that rewrite JPEG to WebP or AVIF leave URLs like this.
		$key = is_string( $full ) ? preg_replace( '/\.[a-z0-9]+$/i', '', $full ) : '';
		if ( is_string( $key ) && $key !== '' && isset( $stripped[ $key ] ) ) {
			$out[] = $stripped[ $key ];
		}
	}

	return $out;
}

/**
 * Lookup tables from uploads-relative path to attachment ID.
 *
 * Built once per request. The second table drops the extension so a URL
 * pointing at a converted variant still resolves to the attachment.
 *
 * @return array{0:array<string,int>, 1:array<string,int>}
 */
function ism_usage_attachment_maps(): array {
	static $maps = null;

	if ( $maps !== null ) {
		return $maps;
	}

	global $wpdb;

	$exact    = [];
	$stripped = [];

	$rows = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
	);

	foreach ( $rows as $row ) {
		$path = (string) $row->meta_value;
		$id   = (int) $row->post_id;

		$exact[ $path ] = $id;

		$key = preg_replace( '/\.[a-z0-9]+$/i', '', $path );
		if ( is_string( $key ) && $key !== '' && ! isset( $stripped[ $key ] ) ) {
			$stripped[ $key ] = $id;
		}
	}

	$maps = [ $exact, $stripped ];

	return $maps;
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
function ism_usage_walk_elementor( array $elements, array &$found, array &$urls = [] ): void {
	foreach ( $elements as $key => $value ) {
		if ( ! is_array( $value ) ) {
			continue;
		}

		// Media control shape: numeric id + uploads-ish url.
		if ( isset( $value['id'], $value['url'] ) && is_string( $value['url'] ) ) {
			$id = $value['id'];
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$found[ (int) $id ] = true;

				// The URL is kept alongside the ID because it is the only thing
				// that survives the attachment being deleted. Once the post is
				// gone there is no _wp_attached_file to look the filename up
				// from, so a stale Elementor reference is only recoverable at
				// all because Elementor stored both.
				$urls[ (int) $id ] = (string) $value['url'];
			}
		}

		// Recurse into everything: elements, settings, gallery arrays, repeaters.
		ism_usage_walk_elementor( $value, $found, $urls );
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
		// Re-key existing entries so merging is idempotent. Keying on the full
		// record rather than the post ID also means a post-only pass leaves
		// term and option records untouched instead of dropping them.
		$merged = [];
		foreach ( ism_usage_get( $att_id ) as $usage ) {
			$merged[ ism_usage_record_key( $usage ) ] = $usage;
		}

		foreach ( $map[ $att_id ] as $post_id => $source ) {
			$record = [ 'post_id' => (int) $post_id, 'source' => (string) $source ];
			$merged[ ism_usage_record_key( $record ) ] = $record;
		}

		update_post_meta( $att_id, ISM_USAGE_META_KEY, array_values( $merged ) );
		$touched++;
	}

	return $touched;
}

/**
 * Index image references held outside the posts table.
 *
 * Taxonomy terms and site-wide options are not posts, so they never appear in
 * the batched post scan, but a custom widget reading
 * get_field( 'icon', $term ) or get_field( 'logo', 'option' ) puts real
 * imagery there. Both are small enough to sweep in one pass at the end of a
 * build rather than batching them.
 *
 * @return int Number of distinct attachments touched.
 */
function ism_usage_index_objects(): int {
	$map = [];

	foreach ( [ ism_usage_collect_term_refs(), ism_usage_collect_option_refs() ] as $source_set ) {
		foreach ( $source_set as $att_id => $records ) {
			foreach ( $records as $record ) {
				$map[ (int) $att_id ][ ism_usage_record_key( $record ) ] = $record;
			}
		}
	}

	if ( empty( $map ) ) {
		return 0;
	}

	$valid   = ism_usage_filter_image_attachments( array_keys( $map ) );
	$touched = 0;

	foreach ( $valid as $att_id ) {
		$merged = [];
		foreach ( ism_usage_get( $att_id ) as $usage ) {
			$merged[ ism_usage_record_key( $usage ) ] = $usage;
		}

		foreach ( $map[ $att_id ] as $key => $record ) {
			$merged[ $key ] = $record;
		}

		update_post_meta( $att_id, ISM_USAGE_META_KEY, array_values( $merged ) );
		$touched++;
	}

	return $touched;
}

/**
 * Image references stored in term meta.
 *
 * @return array<int,array<int,array>> attachment_id => records
 */
function ism_usage_collect_term_refs(): array {
	global $wpdb;

	$out        = [];
	$media_keys = ism_usage_acf_media_field_keys();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT t.term_id, t.meta_value, c.meta_value AS field_key
		 FROM {$wpdb->termmeta} t
		 INNER JOIN {$wpdb->termmeta} c
		    ON c.term_id = t.term_id AND c.meta_key = CONCAT( '_', t.meta_key )
		 WHERE c.meta_value LIKE %s",
		$wpdb->esc_like( 'field_' ) . '%'
	) );

	foreach ( $rows as $row ) {
		if ( ! empty( $media_keys ) && ! isset( $media_keys[ (string) $row->field_key ] ) ) {
			continue;
		}

		foreach ( ism_usage_ids_from_value( $row->meta_value ) as $id ) {
			$out[ $id ][] = [
				'post_id'     => 0,
				'source'      => 'acf_term',
				'object_type' => 'term',
				'object_id'   => (int) $row->term_id,
			];
		}
	}

	$url_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE %s",
		'%' . $wpdb->esc_like( '/uploads/' ) . '%'
	) );

	foreach ( $url_rows as $row ) {
		foreach ( ism_usage_ids_from_urls( (string) $row->meta_value ) as $id ) {
			$out[ $id ][] = [
				'post_id'     => 0,
				'source'      => 'term_field',
				'object_type' => 'term',
				'object_id'   => (int) $row->term_id,
			];
		}
	}

	return $out;
}

/**
 * Image references stored in site options, as ACF options pages do.
 *
 * @return array<int,array<int,array>> attachment_id => records
 */
function ism_usage_collect_option_refs(): array {
	global $wpdb;

	$out        = [];
	$media_keys = ism_usage_acf_media_field_keys();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT o.option_name, o.option_value, c.option_value AS field_key
		 FROM {$wpdb->options} o
		 INNER JOIN {$wpdb->options} c ON c.option_name = CONCAT( '_', o.option_name )
		 WHERE c.option_value LIKE %s",
		$wpdb->esc_like( 'field_' ) . '%'
	) );

	foreach ( $rows as $row ) {
		if ( ! empty( $media_keys ) && ! isset( $media_keys[ (string) $row->field_key ] ) ) {
			continue;
		}

		foreach ( ism_usage_ids_from_value( $row->option_value ) as $id ) {
			$out[ $id ][] = [
				'post_id'     => 0,
				'source'      => 'acf_option',
				'object_type' => 'option',
				'object_id'   => (string) $row->option_name,
			];
		}
	}

	return $out;
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

	// Terms and options last, so their records merge into attachments the post
	// pass has already written rather than being overwritten by it.
	ism_usage_index_objects();

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
		// Same ordering as ism_usage_build_all(): the non-post sweep runs once,
		// after every post batch has been written.
		ism_usage_index_objects();

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