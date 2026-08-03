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
function ism_ai_cost_per_100( string $model, ?array $fields = null ): float {
	$m = ism_ai_models()[ $model ] ?? null;
	if ( ! $m ) {
		return 0.0;
	}

	$fields = $fields === null ? ism_ai_get_fields() : ism_ai_sanitise_fields( $fields );

	// Measured on the pilot site: a 300px image, a few hundred characters of
	// page context, and the JSON that comes back. Output dominates the spread
	// between configurations — a description is two or three sentences, a title
	// is a few words — so it is counted per field rather than as one flat
	// figure. Input barely moves, since the image and the page context are the
	// bulk of it and neither depends on what is being written.
	$per_field = [ 'title' => 20, 'alt_text' => 40, 'description' => 95 ];

	$input_tokens  = 700 + ( 40 * count( $fields ) );
	$output_tokens = 12;
	foreach ( $fields as $field ) {
		$output_tokens += $per_field[ $field ];
	}

	$per_image = ( $input_tokens * $m['in'] / 1000000 ) + ( $output_tokens * $m['out'] / 1000000 );

	return $per_image * 100;
}

/**
 * Estimated token cost of one image under a given configuration.
 *
 * Component figures are measured from real runs on the pilot site, not
 * guessed, but they are still an estimate: page text varies per image and the
 * model's reply length varies with the subject. The admin screen labels the
 * result as approximate for that reason.
 *
 * @param array<string,int> $weights
 * @param int               $context_chars
 * @param int               $extra_prompt_chars
 * @return array{input:int, output:int}
 */
function ism_ai_estimate_tokens( array $weights, int $context_chars, int $extra_prompt_chars ): array {
	// The instructions, sent on every request whatever else is included.
	$input = 340;

	// A 300px medium, which is what ism_vision_pick_file() prefers.
	if ( $weights['image'] > 0 ) {
		$input += 130;
	}

	// Roughly four characters to a token, plus the page titles and keyword
	// lines that are sent regardless of the body-text budget.
	if ( $weights['context'] > 0 ) {
		$input += (int) ceil( $context_chars / 4 ) + 60;
	}

	if ( $weights['metadata'] > 0 ) {
		$input += 60;
	}

	if ( $weights['prompt'] > 0 && $extra_prompt_chars > 0 ) {
		$input += (int) ceil( $extra_prompt_chars / 4 );
	}

	// The weighting instruction itself, once more than one source is in play.
	$active = count( array_filter( $weights ) );
	if ( $active > 1 ) {
		$input += 25 * $active;
	}

	return [ 'input' => $input, 'output' => 150 ];
}

/**
 * Estimated dollar cost for a run.
 *
 * @param string            $model
 * @param int               $images
 * @param array<string,int> $weights
 * @param int               $context_chars
 * @param int               $extra_prompt_chars
 * @return array{per_image:float, total:float, input:int, output:int}
 */
function ism_ai_estimate_cost( string $model, int $images, array $weights, int $context_chars, int $extra_prompt_chars ): array {
	$m = ism_ai_models()[ $model ] ?? null;
	$t = ism_ai_estimate_tokens( $weights, $context_chars, $extra_prompt_chars );

	if ( ! $m ) {
		return [ 'per_image' => 0.0, 'total' => 0.0, 'input' => $t['input'], 'output' => $t['output'] ];
	}

	$per = ( $t['input'] * $m['in'] / 1000000 ) + ( $t['output'] * $m['out'] / 1000000 );

	return [
		'per_image' => $per,
		'total'     => $per * max( 0, $images ),
		'input'     => $t['input'],
		'output'    => $t['output'],
	];
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
		'weights'        => null,
		'extra_prompt'   => null,
		'fields'         => null,
	] );

	$model   = (string) $args['model'];
	$weights = $args['weights'] === null ? ism_ai_get_weights() : ism_ai_normalise_weights( (array) $args['weights'] );
	$extra   = $args['extra_prompt'] === null ? ism_ai_get_extra_prompt() : trim( (string) $args['extra_prompt'] );
	$fields  = $args['fields'] === null ? ism_ai_get_fields() : ism_ai_sanitise_fields( $args['fields'] );

	// A source weighted at zero is not sent at all, so an empty instruction and
	// a zeroed instruction weight mean the same thing.
	if ( $weights['prompt'] < 1 ) {
		$extra = '';
	}

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

	// Weighting the image at zero means describing without looking. It is a
	// legitimate configuration — text-only generation from page context — but a
	// very different job, so the admin screen warns before allowing it.
	$image = null;
	if ( $weights['image'] > 0 ) {
		$image = ism_ai_image_block( $attachment_id, (string) $args['image_data_url'] );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
	}

	$prompt = ism_context_render( $context, $weights );

	$weighting = ism_ai_render_weighting( $weights );
	if ( $weighting !== '' ) {
		$prompt .= "\n\n" . $weighting;
	}

	if ( $extra !== '' ) {
		$prompt .= "\n\nAdditional instructions from the site owner:\n" . $extra;
	}

	$profile = ism_ai_model_profile( $model );

	// format constrains the response to the schema server-side, which is a
	// second line of defence rather than a replacement for
	// ism_ai_parse_response() — a refusal can still return something that does
	// not match the schema.
	$output_config = [
		'format' => [
			'type'   => 'json_schema',
			'schema' => ism_ai_output_schema( $fields ),
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
				'text'          => ism_ai_system_prompt( $fields ),
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		],

		'output_config' => $output_config,

		'messages' => [
			[
				'role'    => 'user',
				'content' => array_values( array_filter( [
					$image ? $image['block'] : null,
					[ 'type' => 'text', 'text' => $prompt ],
				] ) ),
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
		$preview = $body;
		if ( $image ) {
			$preview['messages'][0]['content'][0] = [
				'type'  => 'image',
				'bytes' => $image['bytes'],
				'mime'  => $image['media_type'],
			];
		}

		return [
			'attachment_id' => $attachment_id,
			'result'        => null,
			'raw'           => '',
			'prompt'        => $prompt,
			'usage'         => ism_ai_empty_usage(),
			'model'         => $model,
			'stop_reason'   => 'dry_run',
			'image'         => $image ? $image['meta'] : [ 'source' => 'none', 'media_type' => '', 'bytes' => 0 ],
			'weights'       => $weights,
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
		'result'        => ism_ai_parse_response( $text, $fields ),
		'raw'           => $text,
		'prompt'        => $prompt,
		'usage'         => ism_ai_usage( $response ),
		'model'         => (string) ( $response['model'] ?? $model ),
		'stop_reason'   => (string) ( $response['stop_reason'] ?? '' ),
		'image'         => $image ? $image['meta'] : [ 'source' => 'none', 'media_type' => '', 'bytes' => 0 ],
		'weights'       => $weights,
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Weighting
//
// A percentage cannot literally steer how much attention a model pays to one
// part of a prompt, and pretending otherwise would be dressing a guess up as a
// dial. These do two things that are real:
//
//   1. A source at 0 is omitted from the request entirely. No image block, no
//      page context, no existing metadata, no extra instruction. That is a
//      mechanical change with a measurable effect on both output and cost.
//   2. The remaining shares are turned into an explicit instruction telling the
//      model which source to trust when they disagree, which is the question
//      weighting is actually trying to answer.
//
// So the numbers are honest as priorities and as an on/off switch, and the
// admin screen says so rather than implying calibrated attention control.
// ─────────────────────────────────────────────────────────────────────────────

/** The four inputs a generation can draw on, and their default shares. */
function ism_ai_default_weights(): array {
	return [
		'image'    => 50,
		'prompt'   => 0,
		'context'  => 25,
		'metadata' => 25,
	];
}

/** Human labels, used in the prompt and the admin screen. */
function ism_ai_weight_labels(): array {
	return [
		'image'    => 'what is visible in the image itself',
		'prompt'   => 'the instructions and keywords supplied by the site owner',
		'context'  => 'the pages this image appears on',
		'metadata' => 'the metadata already stored on this image',
	];
}

/**
 * Normalise a submitted weight set.
 *
 * Missing keys fall back to the default; values are clamped to 0-100. The
 * sum is *not* forced to 100 here — the admin screen refuses to submit an
 * invalid set, and silently rescaling behind someone's back would hide a
 * mistake rather than surface it.
 *
 * @param array $weights
 * @return array<string,int>
 */
function ism_ai_normalise_weights( array $weights ): array {
	$out = [];

	foreach ( ism_ai_default_weights() as $key => $default ) {
		$value       = isset( $weights[ $key ] ) ? (int) $weights[ $key ] : $default;
		$out[ $key ] = max( 0, min( 100, $value ) );
	}

	return $out;
}

/**
 * The weights this site generates with, unless a run overrides them.
 *
 * @return array<string,int>
 */
function ism_ai_get_weights(): array {
	$settings = ism_get_settings();
	$stored   = (array) ( $settings['ai_weights'] ?? [] );

	return empty( $stored ) ? ism_ai_default_weights() : ism_ai_normalise_weights( $stored );
}

/**
 * The standing extra instruction, unless a run overrides it.
 *
 * @return string
 */
function ism_ai_get_extra_prompt(): string {
	$settings = ism_get_settings();

	return trim( (string) ( $settings['ai_extra_prompt'] ?? '' ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Which fields to generate
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The fields generation can produce, in the order they are written and shown.
 *
 * @return array<string,string> Field key => human label.
 */
function ism_ai_fields(): array {
	return [
		'title'       => 'Title',
		'alt_text'    => 'Alt text',
		'description' => 'Description',
	];
}

/**
 * What a run generates when nobody has said otherwise.
 *
 * Description is deliberately absent. It is the attachment's post_content,
 * which surfaces on the attachment page template most themes never link to and
 * in a handful of lightbox plugins — on a typical site it is never rendered in
 * the DOM at all. The field that does render under an image is the caption
 * (post_excerpt), which is a different field this plugin does not write. So
 * generating descriptions by default spends output tokens on every image in
 * the library to populate something most sites never display; anyone who does
 * use it can tick the box.
 *
 * @return string[]
 */
function ism_ai_default_fields(): array {
	return [ 'title', 'alt_text' ];
}

/**
 * Reduce arbitrary input to a valid, canonically-ordered field list.
 *
 * Falls back to the default rather than returning nothing, because an empty
 * list would mean a request that spends input tokens on an image and a page of
 * context and asks for no output at all.
 *
 * @param mixed $fields
 * @return string[]
 */
function ism_ai_sanitise_fields( $fields ): array {
	$valid = array_keys( ism_ai_fields() );
	$given = array_map( 'strval', (array) $fields );
	$out   = array_values( array_intersect( $valid, $given ) );

	return $out === [] ? ism_ai_default_fields() : $out;
}

/**
 * The fields this site generates, unless a run overrides them.
 *
 * @return string[]
 */
function ism_ai_get_fields(): array {
	$settings = ism_get_settings();

	if ( ! isset( $settings['ai_fields'] ) ) {
		return ism_ai_default_fields();
	}

	return ism_ai_sanitise_fields( $settings['ai_fields'] );
}

/**
 * Turn a weight set into an instruction about which source wins a disagreement.
 *
 * @param array<string,int> $weights
 * @return string Empty when there is nothing useful to say.
 */
function ism_ai_render_weighting( array $weights ): string {
	$labels = ism_ai_weight_labels();

	$used = array_filter( $weights, function ( $w ) {
		return $w > 0;
	} );

	if ( count( $used ) < 2 ) {
		return '';
	}

	arsort( $used );

	$lines   = [];
	$ordered = array_keys( $used );

	foreach ( $ordered as $key ) {
		$share = $used[ $key ];

		if ( $share >= 60 ) {
			$how = 'This is your primary source. Where sources disagree, follow this one.';
		} elseif ( $share >= 35 ) {
			$how = 'Treat this as a major input.';
		} elseif ( $share >= 15 ) {
			$how = 'Treat this as supporting detail.';
		} else {
			$how = 'Use this only as a light cross-check.';
		}

		$lines[] = sprintf( '- %s (%d%%). %s', $labels[ $key ], $share, $how );
	}

	return "How to weigh what you have been given:\n" . implode( "\n", $lines );
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
function ism_ai_system_prompt( ?array $fields = null ): string {
	$fields = $fields === null ? ism_ai_default_fields() : ism_ai_sanitise_fields( $fields );

	$specs = [
		'title' => '- title: a short human-readable name for the image in the media library.
  Sentence case, no file extension, no dimensions, under about 60 characters.',

		'alt_text' => '- alt_text: what a screen-reader user needs in order to understand why this
  image is on the page. Describe what is actually visible, specifically and
  concretely. Do not begin with "image of", "picture of", or "photo of" — a
  screen reader already announces that it is an image. Do not stuff keywords.
  Under about 125 characters.',

		'description' => '- description: two or three sentences of additional detail for the media
  library, covering what the image shows and how it relates to the page it
  appears on.',
	];

	$wanted = [];
	foreach ( $fields as $field ) {
		$wanted[] = $specs[ $field ];
	}

	$count = count( $fields ) === 1 ? 'one field' : ( count( $fields ) === 2 ? 'two fields' : 'three fields' );
	$list  = implode( "\n", $wanted );

	$prompt = <<<PROMPT
You write image metadata for a WordPress site, for accessibility and SEO.

You are given one image and context describing the pages it appears on. Use
that context: the same photograph means something different on a product page
than in a case study, and the page it lives on tells you which reading is
right.

Return exactly {$count}, and nothing else:

{$list}
PROMPT;

	// Only earns its place in the prompt when alt text is actually being
	// written; it is guidance about one specific field, not general style.
	if ( in_array( 'alt_text', $fields, true ) ) {
		$prompt .= "\n\n" . <<<'PROMPT'
When the image is a logo, brand mark, or icon, alt text should name the thing it
stands for rather than describe how it looks. "Buckeye Metal Sales" tells a
screen-reader user what they need; an inventory of the colours, shapes and
layout of the mark does not. Judge for yourself which images this applies to.
Everything else gets the descriptive treatment above.
PROMPT;
	}

	if ( in_array( 'title', $fields, true ) ) {
		$prompt .= "\n\n" . <<<'PROMPT'
If the context includes a filename hint, use it as a hint only. Write a better
and more specific title than the filename suggests — never copy it back.
PROMPT;
	}

	$prompt .= "\n\n" . <<<'PROMPT'
If the context says the image was not found on any page, describe only what you
can actually see and do not invent a purpose for it.

Describe only what is visible in the image. Do not guess at brands, model
numbers, locations, or people's names that the context does not establish.
PROMPT;

	return $prompt;
}

/**
 * Schema the response is constrained to.
 *
 * @return array
 */
function ism_ai_output_schema( ?array $fields = null ): array {
	$fields = $fields === null ? ism_ai_default_fields() : ism_ai_sanitise_fields( $fields );

	$properties = [];
	foreach ( $fields as $field ) {
		$properties[ $field ] = [ 'type' => 'string' ];
	}

	return [
		'type'                 => 'object',
		'properties'           => $properties,
		'required'             => $fields,
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
function ism_ai_parse_response( string $text, ?array $fields = null ): ?array {
	$fields = $fields === null ? ism_ai_default_fields() : ism_ai_sanitise_fields( $fields );

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

	// Only the requested fields are required. A model that volunteers an extra
	// one is not treated as a failure, but the extra is dropped rather than
	// written — the run asked for a specific set and that is what it gets.
	foreach ( $fields as $field ) {
		if ( ! isset( $decoded[ $field ] ) || ! is_string( $decoded[ $field ] ) ) {
			return null;
		}
	}

	// Every key is always present so callers, the stored proposal and the review
	// table keep one stable shape. A field that was not generated is an empty
	// string, which the apply path already treats as "leave this alone".
	$out = [ 'title' => '', 'alt_text' => '', 'description' => '' ];
	foreach ( $fields as $field ) {
		$out[ $field ] = ism_ai_clean_field( $decoded[ $field ] );
	}

	return $out;
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
