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

/** Characters of page text to keep per referencing post. */
define( 'ISM_CONTEXT_MAX_CHARS', 1000 );

/** Referencing posts to include before truncating the list. */
define( 'ISM_CONTEXT_MAX_POSTS', 3 );

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
		return $context;
	}

	$usages = ism_usage_get_detailed( $attachment_id );

	// Prefer the pages most likely to describe the image: real content before
	// Elementor templates, since a header or footer says little about a photo.
	usort( $usages, function ( $a, $b ) {
		$rank = function ( array $u ): int {
			if ( $u['post_type'] === 'elementor_library' ) {
				return 2;
			}
			if ( $u['source'] === 'featured' ) {
				return 0;
			}
			return 1;
		};
		return $rank( $a ) <=> $rank( $b );
	} );

	foreach ( array_slice( $usages, 0, ISM_CONTEXT_MAX_POSTS ) as $usage ) {
		$usage['text'] = ism_context_post_text( (int) $usage['post_id'] );
		$usage['seo']  = ism_context_post_seo( (int) $usage['post_id'] );
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
function ism_context_post_text( int $post_id ): string {
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

	$text = ism_context_clean_text( implode( ' ', $parts ) );

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, ISM_CONTEXT_MAX_CHARS );
	}

	return substr( $text, 0, ISM_CONTEXT_MAX_CHARS );
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
function ism_context_render( array $context ): string {
	$lines = [];

	$site = $context['site']['name'];
	if ( $context['site']['tagline'] !== '' ) {
		$site .= ' (' . $context['site']['tagline'] . ')';
	}
	$lines[] = 'Website: ' . $site;

	if ( ! empty( $context['usages'] ) ) {
		foreach ( $context['usages'] as $i => $usage ) {
			$lines[] = '';
			$lines[] = sprintf(
				'Appears on %s "%s"',
				$usage['post_type'] === 'elementor_library' ? 'template' : $usage['post_type'],
				$usage['title']
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

	$current = array_filter( [
		'title'   => $context['current']['title'],
		'alt'     => $context['current']['alt'],
		'caption' => $context['current']['caption'],
	] );
	if ( ! empty( $current ) ) {
		$lines[] = '';
		$lines[] = 'Existing metadata:';
		foreach ( $current as $label => $value ) {
			$lines[] = '  ' . $label . ': ' . $value;
		}
	}

	return implode( "\n", $lines );
}