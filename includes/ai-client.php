<?php
/**
 * Anthropic API client for RW Image Manager.
 *
 * Turns one attachment into a generated title, alt text and description, using
 * the context assembled by context-builder.php and the image bytes resolved by
 * image-source.php. Raw HTTP through wp_remote_post — no SDK, no Composer, no
 * autoloader for a plugin distributed as a zip through a GitHub updater.
 *
 * Everything here is unreachable without an API key. With no key stored,
 * ism_ai_generate_for_attachment() returns a WP_Error before any request is
 * built, so a site that never configures one keeps a fully working audit tool
 * and never sees a fatal or a nag.
 *
 * The key lives in its own option with autoload off — never in ism_settings,
 * which ism_get_settings() reads and partially hands to wp_localize_script().
 * Nothing in this file returns the key to a caller or writes it to a log.
 *
 * Three things about the current API are easy to get wrong and are handled
 * deliberately below:
 *
 *   1. `temperature`, `top_p`, `top_k` and `budget_tokens` are rejected with a
 *      400 on this model. None of them are sent. Depth is set with `effort`,
 *      which lives inside `output_config`, not at the top level.
 *   2. Thinking is on by default, and `max_tokens` caps thinking *plus* the
 *      response text together. ISM_AI_MAX_TOKENS is sized for both, not for
 *      the ~200 tokens of JSON that come back.
 *   3. A safety classifier can decline a request and still return HTTP 200,
 *      with `stop_reason` of "refusal" and an empty or partial content array.
 *      Reading content[0] before checking stop_reason would fatal mid-batch.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own option, autoload off. Never ISM_OPTION_KEY. */
define( 'ISM_AI_KEY_OPTION', 'ism_anthropic_api_key' );

define( 'ISM_AI_ENDPOINT', 'https://api.anthropic.com/v1/messages' );
define( 'ISM_AI_VERSION', '2023-06-01' );
define( 'ISM_AI_MODEL', 'claude-opus-5' );

/**
 * Reasoning depth. Inside output_config, not top level.
 *
 * Describing a photograph is not an intelligence-sensitive task, and cost here
 * is multiplied by the size of the library — 791 generatable images on the
 * pilot site. Low is the deliberate starting point; raise it only if a sample
 * of real output justifies the spend.
 */
define( 'ISM_AI_EFFORT', 'low' );

/** Budget for thinking and response text combined, not just the JSON. */
define( 'ISM_AI_MAX_TOKENS', 2000 );

/** WordPress defaults to 5 seconds, which this call will exceed. */
define( 'ISM_AI_TIMEOUT', 60 );

/** Retries for 429 and 5xx, on top of the first attempt. */
define( 'ISM_AI_MAX_RETRIES', 3 );

/** Ceiling on any single backoff sleep, so one retry cannot stall a request. */
define( 'ISM_AI_MAX_BACKOFF', 30 );

// ─────────────────────────────────────────────────────────────────────────────
// The key
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Stored API key, or an empty string.
 *
 * @return string
 */
function ism_ai_get_key(): string {
	$key = get_option( ISM_AI_KEY_OPTION, '' );

	return is_string( $key ) ? trim( $key ) : '';
}

/**
 * Store or clear the API key.
 *
 * Autoload is off: the key is needed only on the handful of requests that
 * generate, and there is no reason for it to sit in the options cache of every
 * page load on the front end.
 *
 * @param string $key Empty string deletes the option.
 */
function ism_ai_set_key( string $key ): void {
	$key = trim( $key );

	if ( $key === '' ) {
		delete_option( ISM_AI_KEY_OPTION );
		return;
	}

	update_option( ISM_AI_KEY_OPTION, $key, false );
}

/**
 * Whether generation is available at all.
 *
 * The admin screen uses this to render the Generate button disabled with a
 * one-line notice rather than hiding it or failing on click.
 *
 * @return bool
 */
function ism_ai_has_key(): bool {
	return ism_ai_get_key() !== '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Public entry point — one attachment
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate metadata for a single attachment.
 *
 * This is the whole surface of the file, and the equivalent of the prior Node
 * script's `--id N`: everything needed to tune a prompt against one known image
 * without a batch layer, an admin screen, or a browser.
 *
 *     wpb eval 'print_r( ism_ai_generate_for_attachment( 5908 ) );'
 *
 * Returns both the parsed result and the raw response text, because when a
 * response fails to parse the raw text is the only thing worth reading.
 *
 * @param int   $attachment_id
 * @param array $args {
 *     @type string $image_data_url Pre-converted image from the browser, for
 *                                  hosts that cannot decode the source format.
 *     @type bool   $dry_run        Build and return the request without sending.
 * }
 * @return array{
 *     attachment_id:int, result:?array, raw:string, prompt:string,
 *     usage:array, model:string, stop_reason:string, image:array
 * }|WP_Error
 */
function ism_ai_generate_for_attachment( int $attachment_id, array $args = [] ) {
	$args = wp_parse_args( $args, [
		'image_data_url' => '',
		'dry_run'        => false,
	] );

	// Checked first so a site with no key never builds a request, never reaches
	// wp_remote_post, and never spends time on context or image decoding.
	if ( ! ism_ai_has_key() && ! $args['dry_run'] ) {
		return new WP_Error(
			'ism_ai_no_key',
			'No Anthropic API key is configured, so generation is unavailable. Everything else in this plugin works without one.'
		);
	}

	$context = ism_context_for_attachment( $attachment_id );

	// Decorative media is listed in the admin tab and never described. Enforced
	// here as well as in the UI, so no future caller can route around it.
	if ( ! empty( $context['skip'] ) ) {
		return new WP_Error(
			'ism_ai_skipped',
			sprintf(
				'Attachment %d is not eligible for generation (%s). It should be listed for manual review, not described.',
				$attachment_id,
				(string) $context['skip_reason']
			),
			[ 'skip_reason' => $context['skip_reason'] ]
		);
	}

	$image = ism_ai_image_block( $attachment_id, (string) $args['image_data_url'] );
	if ( is_wp_error( $image ) ) {
		return $image;
	}

	$prompt = ism_context_render( $context );

	$body = [
		'model'      => ISM_AI_MODEL,
		'max_tokens' => ISM_AI_MAX_TOKENS,

		// Stable across every image in a run, so it sits first and carries the
		// cache breakpoint. Below the cacheable minimum it simply does nothing.
		'system'     => [
			[
				'type'          => 'text',
				'text'          => ism_ai_system_prompt(),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		],

		// effort belongs inside output_config, not at the top level. format
		// constrains the response to the schema server-side, which is a second
		// line of defence rather than a replacement for ism_ai_parse_response()
		// — a refusal can still return something that does not match.
		'output_config' => [
			'effort' => ISM_AI_EFFORT,
			'format' => [
				'type'   => 'json_schema',
				'schema' => ism_ai_output_schema(),
			],
		],

		'messages' => [
			[
				'role'    => 'user',
				'content' => [
					$image['block'],
					[ 'type' => 'text', 'text' => $prompt ],
				],
			],
		],
	];

	// No temperature, top_p, top_k or thinking budget: all four are rejected
	// with a 400 on this model. Thinking runs adaptively by default.

	if ( $args['dry_run'] ) {
		// The image payload is megabytes of base64 and unreadable; summarise it
		// so a dry run can be printed in a terminal.
		$preview                             = $body;
		$preview['messages'][0]['content'][0] = [
			'type'  => 'image',
			'bytes' => $image['bytes'],
			'mime'  => $image['media_type'],
		];

		return [
			'attachment_id' => $attachment_id,
			'result'        => null,
			'raw'           => '',
			'prompt'        => $prompt,
			'usage'         => ism_ai_empty_usage(),
			'model'         => ISM_AI_MODEL,
			'stop_reason'   => 'dry_run',
			'image'         => $image['meta'],
			'request'       => $preview,
		];
	}

	$response = ism_ai_request( $body );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$text = ism_ai_response_text( $response );

	return [
		'attachment_id' => $attachment_id,
		'result'        => ism_ai_parse_response( $text ),
		'raw'           => $text,
		'prompt'        => $prompt,
		'usage'         => ism_ai_usage( $response ),
		'model'         => (string) ( $response['model'] ?? ISM_AI_MODEL ),
		'stop_reason'   => (string) ( $response['stop_reason'] ?? '' ),
		'image'         => $image['meta'],
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// The prompt
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The system prompt.
 *
 * Ported in spirit from the Node run that completed a 500-image pass on this
 * stack. Two things there are load-bearing and are kept verbatim in intent:
 * the JSON-only output contract, and the instruction to treat the filename as
 * a hint to improve on rather than an answer to copy.
 *
 * @return string
 */
function ism_ai_system_prompt(): string {
	return <<<'PROMPT'
You write image metadata for a WordPress site, for accessibility and SEO.

You are given one image and context describing the pages it appears on. Use
that context: the same photograph means something different on a product page
than in a case study, and the page it lives on tells you which reading is
right.

Return three fields:

- title: a short human-readable name for the image in the media library.
  Sentence case, no file extension, no dimensions, under about 60 characters.
- alt_text: what a screen-reader user needs in order to understand why this
  image is on the page. Describe what is actually visible, specifically and
  concretely. Do not begin with "image of", "picture of", or "photo of" — a
  screen reader already announces that it is an image. Do not stuff keywords.
  Under about 125 characters.
- description: two or three sentences of additional detail for the media
  library, covering what the image shows and how it relates to the page it
  appears on.

If the context includes a filename hint, use it as a hint only. Write a better
and more specific title than the filename suggests — never copy it back. If the
context says the image was not found on any page, describe only what you can
actually see and do not invent a purpose for it.

Describe only what is visible in the image. Do not guess at brands, model
numbers, locations, or people's names that the context does not establish.
PROMPT;
}

/**
 * Schema the response is constrained to.
 *
 * @return array
 */
function ism_ai_output_schema(): array {
	return [
		'type'       => 'object',
		'properties' => [
			'title'       => [ 'type' => 'string' ],
			'alt_text'    => [ 'type' => 'string' ],
			'description' => [ 'type' => 'string' ],
		],
		'required'             => [ 'title', 'alt_text', 'description' ],
		'additionalProperties' => false,
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// The image
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the image into an API content block.
 *
 * A browser-supplied `image_data_url` is a first-class input rather than a
 * fallback. On a site whose optimisation plugin discards the pre-conversion
 * originals, every image routes through it, and the server path never runs.
 *
 * @param int    $attachment_id
 * @param string $data_url Empty to read from disk.
 * @return array{block:array, media_type:string, bytes:int, meta:array}|WP_Error
 */
function ism_ai_image_block( int $attachment_id, string $data_url = '' ) {
	if ( $data_url !== '' ) {
		$image = ism_vision_accept_client_image( $data_url );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$meta = [
			'source'      => 'client',
			'media_type'  => $image['media_type'],
			'bytes'       => $image['bytes'],
			'size_name'   => '',
			'converted'   => true,
			'source_mime' => (string) get_post_mime_type( $attachment_id ),
		];
	} else {
		$image = ism_vision_source( $attachment_id );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$meta = [
			'source'      => 'server',
			'media_type'  => $image['media_type'],
			'bytes'       => $image['bytes'],
			'size_name'   => $image['size_name'],
			'converted'   => $image['converted'],
			'source_mime' => $image['source_mime'],
		];
	}

	return [
		'block'      => [
			'type'   => 'image',
			'source' => [
				'type'       => 'base64',
				'media_type' => $image['media_type'],
				'data'       => $image['data'],
			],
		],
		'media_type' => $image['media_type'],
		'bytes'      => $image['bytes'],
		'meta'       => $meta,
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Transport
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send one request, retrying rate limits and server errors.
 *
 * @param array $body
 * @return array|WP_Error Decoded response body.
 */
function ism_ai_request( array $body ) {
	$key = ism_ai_get_key();
	if ( $key === '' ) {
		return new WP_Error( 'ism_ai_no_key', 'No Anthropic API key is configured.' );
	}

	$payload = wp_json_encode( $body );
	if ( ! is_string( $payload ) ) {
		return new WP_Error( 'ism_ai_encode_failed', 'Could not encode the request body.' );
	}

	$attempt = 0;

	while ( true ) {
		$response = wp_remote_post( ISM_AI_ENDPOINT, [
			'timeout' => ISM_AI_TIMEOUT,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => ISM_AI_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => $payload,
		] );

		// Network-level failure: no response at all.
		if ( is_wp_error( $response ) ) {
			if ( $attempt >= ISM_AI_MAX_RETRIES ) {
				return new WP_Error(
					'ism_ai_transport',
					'Could not reach the Anthropic API: ' . $response->get_error_message()
				);
			}
			ism_ai_backoff( $attempt );
			$attempt++;
			continue;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code === 200 ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'ism_ai_bad_json', 'The API returned a body that was not JSON.' );
			}
			return ism_ai_check_stop_reason( $decoded );
		}

		// 429 and 5xx are worth another attempt; 4xx is a request problem and
		// will fail identically however many times it is sent.
		if ( ( $code === 429 || $code >= 500 ) && $attempt < ISM_AI_MAX_RETRIES ) {
			ism_ai_backoff( $attempt, (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
			$attempt++;
			continue;
		}

		return new WP_Error(
			'ism_ai_http_' . $code,
			sprintf( 'Anthropic API returned %d: %s', $code, ism_ai_error_message( $raw ) ),
			[ 'status' => $code ]
		);
	}
}

/**
 * Wait before a retry, honouring a retry-after header when the API sends one.
 *
 * @param int    $attempt     Zero-based attempt already made.
 * @param string $retry_after Header value, if any.
 */
function ism_ai_backoff( int $attempt, string $retry_after = '' ): void {
	$seconds = (int) pow( 2, $attempt + 1 );

	if ( $retry_after !== '' && is_numeric( $retry_after ) ) {
		$seconds = (int) ceil( (float) $retry_after );
	}

	sleep( max( 1, min( $seconds, ISM_AI_MAX_BACKOFF ) ) );
}

/**
 * Pull a readable message out of an API error body.
 *
 * @param string $raw
 * @return string
 */
function ism_ai_error_message( string $raw ): string {
	$decoded = json_decode( $raw, true );

	if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
		return (string) $decoded['error']['message'];
	}

	return $raw !== '' ? mb_substr( $raw, 0, 300 ) : 'no response body';
}

/**
 * Reject a response that stopped for a reason making its content unusable.
 *
 * A declined request is a normal HTTP 200 with an empty or partial content
 * array, so this has to run before anything reads content. A truncated
 * response is caught here too: its JSON is cut off mid-string, and failing
 * with a clear reason beats returning a parse failure that looks like a bad
 * prompt.
 *
 * @param array $decoded
 * @return array|WP_Error
 */
function ism_ai_check_stop_reason( array $decoded ) {
	$stop = (string) ( $decoded['stop_reason'] ?? '' );

	if ( $stop === 'refusal' ) {
		$category = (string) ( $decoded['stop_details']['category'] ?? '' );

		return new WP_Error(
			'ism_ai_refusal',
			'The request was declined by a safety classifier' . ( $category !== '' ? ' (' . $category . ')' : '' ) . '. Skip this image and continue.',
			[ 'category' => $category ]
		);
	}

	if ( $stop === 'max_tokens' ) {
		return new WP_Error(
			'ism_ai_truncated',
			'The response hit the token limit before finishing. Raise ISM_AI_MAX_TOKENS — it covers thinking as well as the reply.'
		);
	}

	return $decoded;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the response
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Concatenate the text blocks of a response.
 *
 * Thinking blocks arrive alongside the text and are skipped by type rather
 * than by position, since the reply is not reliably the first block.
 *
 * @param array $decoded
 * @return string
 */
function ism_ai_response_text( array $decoded ): string {
	$parts = [];

	foreach ( (array) ( $decoded['content'] ?? [] ) as $block ) {
		if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) {
			$parts[] = (string) ( $block['text'] ?? '' );
		}
	}

	return trim( implode( '', $parts ) );
}

/**
 * Parse a response into the three fields, or null.
 *
 * Returning null rather than throwing is the point: one malformed response in
 * a 700-image run must cost that one image, not the run. The caller keeps the
 * raw text and can show it in the preview row for the failed image.
 *
 * The fence stripping is deliberate belt-and-braces. The schema constraint on
 * the request should make fenced output impossible, but it survived a real run
 * at scale without one and costs nothing to keep.
 *
 * @param string $text
 * @return array{title:string, alt_text:string, description:string}|null
 */
function ism_ai_parse_response( string $text ): ?array {
	$text = trim( $text );
	if ( $text === '' ) {
		return null;
	}

	$text = trim( (string) preg_replace( '/^```(?:json)?|```$/mi', '', $text ) );

	// A model that ignores the contract usually still emits a valid object with
	// prose around it; take the outermost braces rather than giving up.
	if ( $text !== '' && $text[0] !== '{' ) {
		$open  = strpos( $text, '{' );
		$close = strrpos( $text, '}' );
		if ( $open !== false && $close !== false && $close > $open ) {
			$text = substr( $text, $open, $close - $open + 1 );
		}
	}

	try {
		$decoded = json_decode( $text, true, 512, JSON_THROW_ON_ERROR );
	} catch ( Throwable $e ) {
		return null;
	}

	if ( ! is_array( $decoded ) ) {
		return null;
	}

	foreach ( [ 'title', 'alt_text', 'description' ] as $field ) {
		if ( ! isset( $decoded[ $field ] ) || ! is_string( $decoded[ $field ] ) ) {
			return null;
		}
	}

	return [
		'title'       => ism_ai_clean_field( $decoded['title'] ),
		'alt_text'    => ism_ai_clean_field( $decoded['alt_text'] ),
		'description' => ism_ai_clean_field( $decoded['description'] ),
	];
}

/**
 * Normalise one generated field.
 *
 * @param string $value
 * @return string
 */
function ism_ai_clean_field( string $value ): string {
	$value = wp_strip_all_tags( $value, true );
	$value = preg_replace( '/\s+/u', ' ', $value );

	return trim( (string) $value );
}

// ─────────────────────────────────────────────────────────────────────────────
// Usage
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Token counts from a response, for per-run cost reporting.
 *
 * Cached and uncached input are reported separately because they are billed at
 * very different rates, and a run that shows no cache reads is worth noticing.
 *
 * @param array $decoded
 * @return array<string,int>
 */
function ism_ai_usage( array $decoded ): array {
	$usage = (array) ( $decoded['usage'] ?? [] );

	$input        = (int) ( $usage['input_tokens'] ?? 0 );
	$output       = (int) ( $usage['output_tokens'] ?? 0 );
	$cache_read   = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
	$cache_create = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );

	return [
		'input_tokens'                => $input,
		'output_tokens'               => $output,
		'cache_read_input_tokens'     => $cache_read,
		'cache_creation_input_tokens' => $cache_create,

		// input_tokens counts only the uncached remainder, so a sum of the
		// three input figures is the real prompt size.
		'total_input_tokens'          => $input + $cache_read + $cache_create,
		'total_tokens'                => $input + $cache_read + $cache_create + $output,
	];
}

/**
 * Zeroed usage, for paths that never sent a request.
 *
 * @return array<string,int>
 */
function ism_ai_empty_usage(): array {
	return [
		'input_tokens'                => 0,
		'output_tokens'               => 0,
		'cache_read_input_tokens'     => 0,
		'cache_creation_input_tokens' => 0,
		'total_input_tokens'          => 0,
		'total_tokens'                => 0,
	];
}
