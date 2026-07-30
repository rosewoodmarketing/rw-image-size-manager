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
 *   1. Which optional parameters a model accepts is not uniform, and the
 *      failure mode is a 400 on every request rather than a silently ignored
 *      field. `effort` is accepted by the Opus and Sonnet models and rejected
 *      by Haiku; `temperature`, `top_p`, `top_k` and `budget_tokens` are
 *      rejected by the current Opus and Sonnet models. None of the sampling
 *      parameters are ever sent, and `effort` is gated on
 *      ism_ai_model_profile() so that changing ISM_AI_MODEL stays a one-line
 *      edit.
 *   2. On a thinking model, `max_tokens` caps thinking *plus* the response
 *      text together, so ISM_AI_MAX_TOKENS is sized for both rather than for
 *      the ~250 tokens of JSON that come back.
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

/**
 * Model used for generation. Change this per deployment.
 *
 * Describing a photograph is a well-scoped, high-volume task, and cost is
 * multiplied by the size of the library — 791 generatable images on the pilot
 * site alone, before any re-run. The cheapest model that does the job is the
 * right default; the ladder, cheapest first:
 *
 *   claude-haiku-4-5   $1 / $5 per MTok    vision + structured outputs, no effort
 *   claude-sonnet-5    $3 / $15            full effort range
 *   claude-opus-5      $5 / $25            full effort range
 *
 * Model capabilities differ in ways that make a bare swap unsafe, which is
 * what ism_ai_model_profile() exists to absorb — see the note there before
 * changing this to something not in that table.
 */
define( 'ISM_AI_MODEL', 'claude-haiku-4-5' );

/**
 * Reasoning depth, for models that support it. Inside output_config, not top
 * level.
 *
 * Ignored entirely on a model whose profile reports no effort support, because
 * sending it there is a 400 rather than a no-op. Set to an empty string to omit
 * it even on a model that would accept it.
 */
define( 'ISM_AI_EFFORT', 'low' );

/**
 * Response ceiling.
 *
 * Only ~250 tokens of JSON come back, so this is mostly headroom. It stays
 * generous because on a thinking model the same budget has to cover thinking
 * as well as the reply — see ism_ai_model_profile(). max_tokens is a ceiling,
 * not a reservation; unused budget is not billed.
 */
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

/**
 * AJAX: remove the stored key.
 *
 * A button rather than a checkbox-plus-save: clearing a credential is a single
 * decisive act, and burying it behind "tick this, then scroll down and save"
 * makes it both easy to arm by accident and easy to leave half-done.
 */
function ism_ajax_ai_clear_key(): void {
	check_ajax_referer( 'ism_seo', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	ism_ai_set_key( '' );

	wp_send_json_success( [ 'has_key' => ism_ai_has_key() ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Model capabilities
// ─────────────────────────────────────────────────────────────────────────────

/**
 * What the configured model will actually accept.
 *
 * Changing ISM_AI_MODEL is meant to be a one-line edit, and it only stays that
 * way because the differences between models live here rather than inline in
 * the request. The differences are not cosmetic: sending `effort` to a model
 * that does not support it is a 400 on every request, not a silently ignored
 * field, so a bare constant swap to Haiku would break generation outright.
 *
 * Fields:
 *
 *   effort     Whether output_config.effort is accepted at all.
 *   thinking   'none'     — the model does not think unless asked, and this
 *                           file never asks. What Haiku does.
 *              'adaptive' — thinks by default. max_tokens then covers thinking
 *                           *and* the reply, so keep ISM_AI_MAX_TOKENS well
 *                           above the ~250 tokens of JSON. Do not "save money"
 *                           by disabling it on Opus 5: with thinking off that
 *                           model can leak internal XML into the visible
 *                           response, which corrupts the JSON being parsed.
 *                           Lower effort is the cost lever instead.
 *   cache_min  Minimum promptable prefix, in tokens, before a cache_control
 *              marker does anything. Below it caching silently no-ops — no
 *              error, just cache_read_input_tokens of 0 forever. The system
 *              prompt here is a few hundred tokens, so on Haiku's 4096 floor
 *              the marker never engages. That is expected, not a bug.
 *
 * Verified against GET /v1/models/{id} rather than assumed. Re-check there
 * when adding a row: `capabilities.effort.supported`,
 * `capabilities.thinking.types.adaptive.supported`, `capabilities.image_input`.
 *
 * An unrecognised model gets the conservative profile — no optional knobs sent
 * — which is a valid request everywhere and degrades quality rather than
 * failing outright.
 *
 * @param string $model
 * @return array{effort:bool, thinking:string, cache_min:int}
 */
function ism_ai_model_profile( string $model = '' ): array {
	if ( $model === '' ) {
		$model = ism_ai_get_model();
	}

	$models = ism_ai_models();

	if ( isset( $models[ $model ] ) ) {
		return [
			'effort'    => $models[ $model ]['effort'],
			'thinking'  => $models[ $model ]['thinking'],
			'cache_min' => $models[ $model ]['cache_min'],
		];
	}

	return [ 'effort' => false, 'thinking' => 'none', 'cache_min' => PHP_INT_MAX ];
}

/**
 * Models offered in the settings dropdown.
 *
 * Prices are per million tokens and exist so the admin screen can show the
 * cost consequence of a choice next to the choice itself, rather than making
 * someone go and look it up.
 *
 * @return array<string,array>
 */
function ism_ai_models(): array {
	return [
		'claude-haiku-4-5' => [
			'label'     => 'Haiku 4.5 — cheapest',
			'in'        => 1.00,
			'out'       => 5.00,
			'effort'    => false,
			'thinking'  => 'none',
			'cache_min' => 4096,
			'note'      => 'Fastest and cheapest. Least reliable on fine colour distinctions, which matters when colour is a product attribute.',
		],
		'claude-sonnet-5' => [
			'label'     => 'Sonnet 5 — balanced',
			'in'        => 3.00,
			'out'       => 15.00,
			'effort'    => true,
			'thinking'  => 'adaptive',
			'cache_min' => 1024,
			'note'      => 'Noticeably better on colour and material detail. A sensible default for product photography.',
		],
		'claude-opus-5' => [
			'label'     => 'Opus 5 — most capable',
			'in'        => 5.00,
			'out'       => 25.00,
			'effort'    => true,
			'thinking'  => 'adaptive',
			'cache_min' => 512,
			'note'      => 'Best judgement on ambiguous imagery. Roughly eight times the cost of Haiku.',
		],
		'claude-opus-4-8' => [
			'label'     => 'Opus 4.8 — previous generation',
			'in'        => 5.00,
			'out'       => 25.00,
			'effort'    => true,
			'thinking'  => 'adaptive',
			'cache_min' => 1024,
			'note'      => 'Kept for sites that validated against it and do not want the output to move.',
		],
	];
}

/**
 * Roughly what 100 images cost on a model.
 *
 * Per-million-token pricing is the wrong unit for someone deciding whether to
 * run a 700-image library — it needs mental arithmetic across two different
 * numbers. This converts to the figure the decision actually turns on, using
 * the token counts measured on a real run.
 *
 * @param string $model
 * @return float Dollars per 100 images.
 */
function ism_ai_cost_per_100( string $model ): float {
	$m = ism_ai_models()[ $model ] ?? null;
	if ( ! $m ) {
		return 0.0;
	}

	// Measured on the pilot site: a 300px image, a few hundred characters of
	// page context, and the JSON that comes back.
	$input_tokens  = 700;
	$output_tokens = 150;

	$per_image = ( $input_tokens * $m['in'] / 1000000 ) + ( $output_tokens * $m['out'] / 1000000 );

	return $per_image * 100;
}

/**
 * The model this site generates with.
 *
 * Settable from the admin screen; ISM_AI_MODEL is the fallback when nothing has
 * been chosen, and an unrecognised stored value falls back rather than being
 * sent to the API.
 *
 * @return string
 */
function ism_ai_get_model(): string {
	$settings = ism_get_settings();
	$model    = (string) ( $settings['ai_model'] ?? '' );

	return isset( ism_ai_models()[ $model ] ) ? $model : ISM_AI_MODEL;
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
 *     @type string $model          Override ISM_AI_MODEL for this call only, to
 *                                  compare models on one known image before
 *                                  committing a deployment to one.
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
		'model'          => ism_ai_get_model(),
		'override_skip'  => false,
	] );

	$model = (string) $args['model'];

	// Checked first so a site with no key never builds a request, never reaches
	// wp_remote_post, and never spends time on context or image decoding.
	if ( ! ism_ai_has_key() && ! $args['dry_run'] ) {
		return new WP_Error(
			'ism_ai_no_key',
			'No Anthropic API key is configured, so generation is unavailable. Everything else in this plugin works without one.'
		);
	}

	$context = ism_context_for_attachment( $attachment_id );

	// Decorative media is listed in the admin tab and never described unless the
	// user overrides it for a specific image. The override is per call and never
	// a stored default, so the safe behaviour stays the one you get by accident.
	if ( ! empty( $context['skip'] ) && empty( $args['override_skip'] ) ) {
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

	$prompt  = ism_context_render( $context );
	$profile = ism_ai_model_profile( $model );

	// format constrains the response to the schema server-side, which is a
	// second line of defence rather than a replacement for
	// ism_ai_parse_response() — a refusal can still return something that does
	// not match the schema.
	$output_config = [
		'format' => [
			'type'   => 'json_schema',
			'schema' => ism_ai_output_schema(),
		],
	];

	// effort belongs inside output_config, not at the top level — and only on a
	// model that accepts it. On one that does not, sending it fails the request
	// outright rather than being ignored.
	if ( $profile['effort'] && ISM_AI_EFFORT !== '' ) {
		$output_config['effort'] = ISM_AI_EFFORT;
	}

	$body = [
		'model'      => $model,
		'max_tokens' => ISM_AI_MAX_TOKENS,

		// Stable across every image in a run, so it sits first and carries the
		// cache breakpoint. Below the model's cacheable minimum it silently
		// does nothing, which is the case on Haiku — see ism_ai_model_profile().
		'system'     => [
			[
				'type'          => 'text',
				'text'          => ism_ai_system_prompt(),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		],

		'output_config' => $output_config,

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

	// No temperature, top_p, top_k or thinking configuration is sent. The
	// sampling parameters are rejected outright on the current Opus and Sonnet
	// models, and thinking is left at each model's default: off on Haiku,
	// adaptive on the thinking models.

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
			'model'         => $model,
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
		'model'         => (string) ( $response['model'] ?? $model ),
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

When the image is a logo, brand mark, or icon, alt text should name the thing it
stands for rather than describe how it looks. "Buckeye Metal Sales" tells a
screen-reader user what they need; an inventory of the colours, shapes and
layout of the mark does not. Judge for yourself which images this applies to.
Everything else gets the descriptive treatment above.

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
