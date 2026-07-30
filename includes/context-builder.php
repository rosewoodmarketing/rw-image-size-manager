<?php
/**
 * Context Builder for RW Image Manager.
 *
 * Turns an attachment ID into the context used to generate a title, alt text
 * and description for it. Reads the usage index built by usage-index.php.
 *
 * Three layers of context, matching the approach validated on the Buckeye
 * media run:
 *
 *   1. The pages the image actually appears on — title, type, and a slice of
 *      their text. This is what distinguishes a roofing panel photo on a
 *      product page from the same panel in a case study.
 *   2. The filename, de-slugified into readable words and passed as a hint
 *      rather than an answer. Hash and UUID filenames contribute nothing and
 *      are dropped.
 *   3. Whatever metadata already exists on the attachment, so generation can
 *      improve on it rather than ignore it.
 *
 * Page text on Elementor sites cannot be read from post_content. Elementor
 * stores the page as JSON in _elementor_data and leaves post_content empty or
 * stubbed, so text is walked out of that JSON instead.
 *
 * No external API is required by anything in this file.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Default total characters of page text across every referencing post. */
define( 'ISM_CONTEXT_MAX_CHARS', 1000 );

/** Floor on the per-post slice, so a large budget never starves later pages. */
define( 'ISM_CONTEXT_MIN_SLICE', 200 );

/**
 * How many characters of page text this site includes, in total.
 *
 * A budget rather than a per-post cap: every page an image appears on is named
 * in the context, and their body text is spent against one shared allowance in
 * weight order, so the pages most likely to explain the image are the ones that
 * survive truncation.
 *
 * @return int
 */
function ism_context_max_chars(): int {
	$settings = ism_get_settings();
	$chars    = (int) ( $settings['context_max_chars'] ?? 0 );

	if ( $chars < 100 ) {
		return ISM_CONTEXT_MAX_CHARS;
	}

	return min( $chars, 20000 );
}

/**
 * Ranking for one usage. Lower sorts first and gets text before the budget runs out.
 *
 * A featured image is the page's own image and is ranked above everything: if
 * any page explains what an image is, it is that one. Body and builder
 * placements come next, then custom fields, and template chrome last — a header
 * or footer says almost nothing about a photograph, and on a site built from
 * saved templates it would otherwise crowd out the pages that do.
 *
 * @param array $usage
 * @return int
 */
function ism_context_usage_rank( array $usage ): int {
	$source = (string) ( $usage['source'] ?? '' );

	if ( $source === 'featured' ) {
		return 0;
	}

	if ( ( $usage['post_type'] ?? '' ) === 'elementor_library' ) {
		return 3;
	}

	if ( in_array( $source, [ 'acf', 'acf_term', 'acf_option', 'custom_field', 'term_field' ], true ) ) {
		return 2;
	}

	return 1;
}

// ─────────────────────────────────────────────────────────────────────────────
// Public entry point
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Assemble everything known about one attachment's context.
 *
 * @param int $attachment_id
 * @return array{
 *     attachment_id:int, filename:string, name_hint:string, mime:string,
 *     width:int, height:int, current:array, usages:array, site:array,
 *     skip:bool, skip_reason:string
 * }
 */
function ism_context_for_attachment( int $attachment_id ): array {
	$file = get_attached_file( $attachment_id );
	$meta = wp_get_attachment_metadata( $attachment_id );
	$mime = (string) get_post_mime_type( $attachment_id );

	$context = [
		'attachment_id' => $attachment_id,
		'filename'      => $file ? basename( $file ) : '',
		'name_hint'     => $file ? ism_context_name_hint( basename( $file ) ) : '',
		'mime'          => $mime,
		'width'         => (int) ( $meta['width']  ?? 0 ),
		'height'        => (int) ( $meta['height'] ?? 0 ),
		'current'       => ism_context_current_metadata( $attachment_id ),
		'usages'        => [],
		'site'          => [
			'name'    => (string) get_bloginfo( 'name' ),
			'tagline' => (string) get_bloginfo( 'description' ),
		],
		'skip'          => false,
		'skip_reason'   => '',
	];

	// Decorative media should end up with empty alt text, not a description of
	// itself, so it is flagged here rather than filtered silently upstream.
	$skip = ism_context_skip_reason( $mime, $context['width'], $context['height'] );
	if ( $skip !== '' ) {
		$context['skip']        = true;
		$context['skip_reason'] = $skip;
	}

	$usages = ism_usage_get_detailed( $attachment_id );

	// Weight order, so the pages most likely to explain the image are the ones
	// that get text before the character budget runs out. Stable within a rank:
	// equal-ranked pages keep index order rather than being reshuffled.
	usort( $usages, function ( $a, $b ) {
		return ism_context_usage_rank( $a ) <=> ism_context_usage_rank( $b );
	} );

	// Every referencing page is listed, whatever the budget — a title and a
	// focus keyword cost almost nothing and are often the most useful line in
	// the whole context. Body text is what gets rationed.
	//
	// Skipped media is listed too. An SVG is never described by the generator
	// unless explicitly overridden, but it still appears in the admin tab so it
	// can be renamed or merged by hand, and a merge has to repoint every
	// reference rather than a sample of them.
	$budget = $context['skip'] ? 0 : ism_context_max_chars();
	$slice  = max( ISM_CONTEXT_MIN_SLICE, (int) floor( $budget / 3 ) );

	foreach ( $usages as $usage ) {
		$usage['text'] = '';
		$usage['seo']  = [ 'focus_keyword' => '', 'meta_description' => '' ];

		if ( $budget > 0 ) {
			$usage['seo']  = ism_context_post_seo( (int) $usage['post_id'] );
			$usage['text'] = ism_context_post_text( (int) $usage['post_id'], min( $slice, $budget ) );
			$budget       -= mb_strlen( $usage['text'] );
		}

		$context['usages'][] = $usage;
	}

	return $context;
}

// ─────────────────────────────────────────────────────────────────────────────
// Attachment-side context
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Existing title, alt, caption and description for an attachment.
 *
 * @param int $attachment_id
 * @return array{title:string, alt:string, caption:string, description:string}
 */
function ism_context_current_metadata( int $attachment_id ): array {
	$post = get_post( $attachment_id );

	return [
		'title'       => $post ? (string) $post->post_title   : '',
		'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'caption'     => $post ? (string) $post->post_excerpt : '',
		'description' => $post ? (string) $post->post_content : '',
	];
}

/**
 * Decide whether an attachment should be skipped rather than described.
 *
 * @param string $mime
 * @param int    $width
 * @param int    $height
 * @return string Reason, or an empty string to proceed.
 */
function ism_context_skip_reason( string $mime, int $width, int $height ): string {
	// SVGs are almost always logos, icons or UI chrome on these builds, and the
	// vision request would need a raster conversion regardless.
	if ( $mime === 'image/svg+xml' ) {
		return 'svg';
	}

	if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ], true ) ) {
		return 'unsupported_mime';
	}

	// Anything this small is an icon, spacer or tracking pixel.
	if ( $width > 0 && $height > 0 && $width <= 64 && $height <= 64 ) {
		return 'icon_size';
	}

	return '';
}

/**
 * Turn a filename into readable words usable as a weak hint.
 *
 * Returns an empty string when the filename carries no meaning, so that a hash
 * or UUID name is not offered to the model as though it described anything.
 *
 * buckeye-standing-seam-roofs-177-1024x768.jpg → "buckeye standing seam roofs"
 * d438c3f7-fe38-38ff-a961-cdd4d3ba0c03.jpg     → ""
 *
 * @param string $filename
 * @return string
 */
function ism_context_name_hint( string $filename ): string {
	$name = preg_replace( '/\.[a-z0-9]+$/i', '', $filename );
	$name = (string) $name;

	// WordPress and plugin suffixes that describe the file, not the subject.
	$name = preg_replace( '/-scaled$/i', '', $name );
	$name = preg_replace( '/-\d+x\d+$/', '', $name );
	$name = preg_replace( '/-e\d{10,}$/', '', $name ); // -e1699999999 edit suffix
	$name = (string) $name;

	// Hash-like names carry no information.
	if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name ) ) {
		return '';
	}
	if ( preg_match( '/^[0-9a-f]{16,}$/i', $name ) ) {
		return '';
	}

	$words = preg_split( '/[-_\s]+/', $name ) ?: [];
	$kept  = [];

	foreach ( $words as $word ) {
		$word = trim( $word );
		if ( $word === '' ) {
			continue;
		}
		// Long digit runs are serials, dates or sequence numbers and mean
		// nothing. Short ones are usually specifications worth keeping, such as
		// the 5 in "5-Rib" or the 29 in "29-gauge", so they stay.
		if ( preg_match( '/^\d{3,}$/', $word ) ) {
			continue;
		}
		// Camera prefixes and editing-workflow noise.
		if ( preg_match( '/^(img|dsc|dscn|pxl|screenshot|photo|image|untitled|final|copy|edit|edited|new|v\d+|small|large|web|crop|cropped|resize|resized)$/i', $word ) ) {
			continue;
		}
		$kept[] = strtolower( $word );
	}

	// A lone number is not a hint.
	if ( count( $kept ) === 1 && preg_match( '/^\d+$/', $kept[0] ) ) {
		return '';
	}

	if ( count( $kept ) < 2 ) {
		return ''; // A single word is rarely worth passing along.
	}

	return implode( ' ', $kept );
}

// ─────────────────────────────────────────────────────────────────────────────
// Post-side context
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Readable text for a post, from whichever builder actually holds it.
 *
 * @param int $post_id
 * @return string
 */
function ism_context_post_text( int $post_id, int $max_chars = 0 ): string {
	if ( $max_chars < 1 ) {
		$max_chars = ism_context_max_chars();
	}

	$parts = [];

	// Elementor first: on Elementor pages post_content is empty or a stub.
	$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_string( $elementor_raw ) && $elementor_raw !== '' ) {
		$elements = json_decode( $elementor_raw, true );
		if ( is_array( $elements ) ) {
			$strings = [];
			ism_context_walk_elementor_text( $elements, $strings );

			// Desktop, tablet and mobile variants of a control hold the same
			// value, so the same headline arrives several times. Deduplicate
			// while preserving document order.
			$parts[] = implode( ' ', array_unique( $strings ) );
		}
	}

	$post = get_post( $post_id );
	if ( $post && trim( (string) $post->post_content ) !== '' ) {
		$parts[] = (string) $post->post_content;
	}

	// Custom fields. On a CPT built out of ACF — product pages here — the entire
	// body copy lives in text, textarea and wysiwyg fields, and neither
	// post_content nor _elementor_data holds a word of it. Without this a
	// product page contributes its title and nothing else.
	$acf = ism_context_acf_text( $post_id );
	if ( $acf !== '' ) {
		$parts[] = $acf;
	}

	$text = ism_context_clean_text( implode( ' ', $parts ) );

	return mb_substr( $text, 0, $max_chars );
}

/**
 * Recursively collect human-readable strings from an Elementor elements tree.
 *
 * Elementor Pro and third-party widget packs (Ultimate Elementor and similar)
 * each add their own setting keys, so an allowlist of keys would go stale.
 * Instead every string in the tree is considered and the ones that are plainly
 * machine values — URLs, colours, CSS lengths, slugs, icon classes — are
 * rejected.
 *
 * @param array             $node
 * @param array<int,string> &$out
 */
function ism_context_walk_elementor_text( array $node, array &$out ): void {
	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			ism_context_walk_elementor_text( $value, $out );
			continue;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			continue;
		}

		if ( ism_context_is_machine_string( (string) $key, $value ) ) {
			continue;
		}

		$out[] = $value;
	}
}

/**
 * Whether a string is configuration rather than prose.
 *
 * @param string $key
 * @param string $value
 * @return bool
 */
function ism_context_is_machine_string( string $key, string $value ): bool {
	$trimmed = trim( $value );

	if ( strlen( $trimmed ) < 3 ) {
		return true;
	}

	// Any purely numeric value is a control setting, never prose. Elementor
	// stores dimensions as {top,right,bottom,left} rather than under a "size"
	// key, so bare values like "8.5", ".625" and "-2.5" arrive at innocuous
	// key names and have to be caught by shape instead.
	if ( is_numeric( $trimmed ) ) {
		return true;
	}

	// Keys that never hold prose. Layout, typography and widget-chrome
	// settings, plus the dimension sub-keys used by spacing controls.
	$config_keys = 'url|link|id|size|color|colour|align|position|type|class|icon|library|source|key|css|selector|animation|unit|repeat|attachment'
		. '|font|family|weight|style|decoration|transform|spacing'
		. '|width|height|margin|padding|top|right|bottom|left|gap|radius|shadow|border|offset|opacity|order|ratio'
		. '|label|placeholder|message|format|target|rel';

	if ( preg_match( '/(^|_)(' . $config_keys . ')(_|$)/i', $key ) ) {
		return true;
	}

	// Values that are plainly machine data.
	if ( preg_match( '~^(https?:)?//~i', $trimmed ) ) {
		return true;
	}
	if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $trimmed ) ) {
		return true;
	}
	if ( preg_match( '/^rgba?\(/i', $trimmed ) ) {
		return true;
	}
	if ( preg_match( '/^[\d.]+(px|em|rem|%|vh|vw|deg|s|ms)$/i', $trimmed ) ) {
		return true;
	}
	if ( preg_match( '/^(fa|fas|far|fab|eicon|elementor)[\w-]*$/i', $trimmed ) ) {
		return true;
	}
	// Slug-like with no spaces and no sentence punctuation.
	if ( ! preg_match( '/\s/', $trimmed ) && preg_match( '/^[a-z0-9_-]+$/', $trimmed ) ) {
		return true;
	}

	return false;
}

/**
 * Prose held in a post's ACF text fields.
 *
 * Identified the same way usage-index.php identifies media fields: ACF writes a
 * companion `_key` row beside every value, and the field definition names its
 * own type. Only text, textarea and wysiwyg are read, so a URL, a select value
 * or a colour never lands in the prompt as though it were copy.
 *
 * @param int $post_id
 * @return string
 */
function ism_context_acf_text( int $post_id ): string {
	global $wpdb;

	$keys = ism_context_acf_text_field_keys();
	if ( empty( $keys ) ) {
		return '';
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.meta_value, c.meta_value AS field_key
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->postmeta} c
		    ON c.post_id = m.post_id AND c.meta_key = CONCAT( '_', m.meta_key )
		 WHERE m.post_id = %d
		   AND c.meta_value LIKE %s",
		$post_id,
		$wpdb->esc_like( 'field_' ) . '%'
	) );

	$parts = [];

	foreach ( $rows as $row ) {
		if ( ! isset( $keys[ (string) $row->field_key ] ) ) {
			continue;
		}

		$value = maybe_unserialize( $row->meta_value );
		if ( is_string( $value ) && trim( $value ) !== '' ) {
			$parts[] = $value;
		}
	}

	return implode( ' ', $parts );
}

/**
 * ACF field keys whose definition declares them as prose.
 *
 * @return array<string,bool>
 */
function ism_context_acf_text_field_keys(): array {
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

		if ( in_array( $type, [ 'text', 'textarea', 'wysiwyg' ], true ) ) {
			$keys[ (string) $row->post_name ] = true;
		}
	}

	return $keys;
}

/**
 * Yoast or Rank Math signals for a post, when present.
 *
 * @param int $post_id
 * @return array{focus_keyword:string, meta_description:string}
 */
function ism_context_post_seo( int $post_id ): array {
	$focus = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
	if ( $focus === '' ) {
		$focus = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		// Rank Math stores a comma-separated list; the first is primary.
		if ( $focus !== '' && strpos( $focus, ',' ) !== false ) {
			$focus = trim( explode( ',', $focus )[0] );
		}
	}

	$desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
	if ( $desc === '' ) {
		$desc = (string) get_post_meta( $post_id, 'rank_math_description', true );
	}

	return [
		'focus_keyword'    => $focus,
		'meta_description' => ism_context_clean_text( $desc ),
	];
}

/**
 * Strip markup and shortcodes, decode entities, collapse whitespace.
 *
 * @param string $text
 * @return string
 */
function ism_context_clean_text( string $text ): string {
	// Elementor dynamic tags and any other shortcode-shaped token. Removed by
	// pattern rather than by strip_shortcodes(), which only handles shortcodes
	// registered in the current request and leaves [elementor-tag …] intact.
	$text = preg_replace( '/\[\/?[a-z][\w-]*[^\]]*\]/i', ' ', $text );
	$text = strip_shortcodes( (string) $text );
	$text = wp_strip_all_tags( $text, true );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', (string) $text );

	return trim( (string) $text );
}

// ─────────────────────────────────────────────────────────────────────────────
// Rendering for a prompt
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Flatten a context array into the text block sent alongside the image.
 *
 * Kept separate from ism_context_for_attachment() so the context can be
 * inspected, logged and tested without involving any external service.
 *
 * @param array $context Output of ism_context_for_attachment().
 * @return string
 */
function ism_context_render( array $context, array $weights = [] ): string {
	$lines = [];

	// A source weighted at zero is left out of the request altogether, which is
	// the only part of weighting that is mechanically enforceable.
	$want_context  = ! isset( $weights['context'] )  || $weights['context'] > 0;
	$want_metadata = ! isset( $weights['metadata'] ) || $weights['metadata'] > 0;

	$site = $context['site']['name'];
	if ( $context['site']['tagline'] !== '' ) {
		$site .= ' (' . $context['site']['tagline'] . ')';
	}
	$lines[] = 'Website: ' . $site;

	if ( ! $want_context ) {
		// Nothing about the pages at all, not even how many there are.
	} elseif ( ! empty( $context['usages'] ) ) {
		$lines[] = '';
		$lines[] = sprintf(
			'This image appears in %d place(s) on the site, listed below most relevant first.',
			count( $context['usages'] )
		);

		foreach ( $context['usages'] as $usage ) {
			$lines[] = '';
			$lines[] = sprintf(
				'Appears on %s "%s"%s',
				$usage['post_type'] === 'elementor_library' ? 'template' : $usage['post_type'],
				$usage['title'],
				$usage['source'] === 'featured' ? ' — as that page\'s featured image' : ''
			);
			if ( $usage['seo']['focus_keyword'] !== '' ) {
				$lines[] = 'Target keyword for that page: ' . $usage['seo']['focus_keyword'];
			}
			if ( $usage['text'] !== '' ) {
				$lines[] = 'Text from that page: ' . $usage['text'];
			}
		}
	} else {
		$lines[] = '';
		$lines[] = 'This image was not found on any page, so no page context is available.';
	}

	if ( $context['name_hint'] !== '' ) {
		$lines[] = '';
		$lines[] = 'Filename suggests: ' . $context['name_hint'];
	}

	$current = $want_metadata ? array_filter( [
		'title'   => $context['current']['title'],
		'alt'     => $context['current']['alt'],
		'caption' => $context['current']['caption'],
	] ) : [];
	if ( ! empty( $current ) ) {
		$lines[] = '';
		$lines[] = 'Existing metadata:';
		foreach ( $current as $label => $value ) {
			$lines[] = '  ' . $label . ': ' . $value;
		}
	}

	return implode( "\n", $lines );
}