<?php
/**
 * Vision image source for RW Image Manager.
 *
 * Turns an attachment ID into image bytes a vision API will accept. The API
 * takes JPEG, PNG, GIF and WebP; it does not take AVIF, and AVIF is the
 * majority format on at least one Rosewood client site, so conversion is a
 * requirement rather than an edge case.
 *
 * Conversion cannot be assumed to work on the server. Host image libraries
 * vary widely:
 *
 *   - Imagick is authoritative about its own formats via queryFormats(), but
 *     builds older than ImageMagick 7 have no AVIF delegate at all.
 *   - GD lies. On a host with libgd built without libavif, imagecreatefromavif()
 *     still exists, gd_info()['AVIF Support'] still reports true, and
 *     imagetypes() still sets IMG_AVIF — while every decode fails with
 *     "AVIF image support has been disabled". Capability flags cannot be
 *     trusted; only a real decode can.
 *   - wp_get_image_editor() returns "File is not an image." when no editor
 *     accepts the mime, which describes the file rather than the host and
 *     sends debugging in the wrong direction.
 *
 * So this file never asks whether the host can decode. It tries, remembers the
 * answer per mime type, and reports ISM_VISION_ERR_CLIENT when it cannot — at
 * which point the browser converts the image instead, via canvas, and posts the
 * result back through ism_vision_accept_client_image(). Browsers decode AVIF
 * natively, so the fallback needs nothing from the host.
 *
 * The result is that generation works on a modern Kinsta container (server
 * path) and on a DevKinsta container with a 2019 ImageMagick and no libavif
 * (browser path) without either being configured for.
 *
 * No external API is called from this file.
 *
 * @package image-size-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Mime types a vision API accepts without conversion. */
define( 'ISM_VISION_MIME_OK', [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ] );

/** Longest edge sent to the API. Larger costs more and identifies no better. */
define( 'ISM_VISION_MAX_EDGE', 1024 );

/** Raw byte ceiling before re-encoding. Keeps base64 clear of the 5 MB API cap. */
define( 'ISM_VISION_MAX_BYTES', 4 * MB_IN_BYTES );

/** JPEG quality for converted images. Ample for subject identification. */
define( 'ISM_VISION_JPEG_QUALITY', 82 );

/** Cached per-mime decode capability. Option, not transient — Redis evicts. */
define( 'ISM_VISION_CAPS_KEY', 'ism_vision_decode_caps' );

/** Error code meaning: this host cannot decode it, let the browser try. */
define( 'ISM_VISION_ERR_CLIENT', 'ism_vision_client_decode' );

// ─────────────────────────────────────────────────────────────────────────────
// Public entry point
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Produce API-ready image bytes for one attachment.
 *
 * On success returns a block shaped for a vision request, plus diagnostics the
 * admin UI can display:
 *
 *   media_type  string  Always one of ISM_VISION_MIME_OK.
 *   data        string  Base64 payload.
 *   bytes       int     Decoded byte length, for cost and limit reporting.
 *   size_name   string  Which registered size was used, or 'full'.
 *   source_mime string  The mime on disk, before any conversion.
 *   converted   bool    Whether this host had to transcode it.
 *
 * A WP_Error with code ISM_VISION_ERR_CLIENT is not a failure. It means the
 * caller should route this attachment through the browser fallback rather than
 * give up on it.
 *
 * @param int $attachment_id
 * @return array|WP_Error
 */
function ism_vision_source( int $attachment_id ) {
	$file = ism_vision_pick_file( $attachment_id );
	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$mime = $file['mime'];

	// Directly usable, and small enough to send as-is. The common path on a
	// site with JPEG originals, and the reason nothing here touches an image
	// library unless it has to.
	if ( in_array( $mime, ISM_VISION_MIME_OK, true ) && $file['bytes'] <= ISM_VISION_MAX_BYTES ) {
		$bytes = file_get_contents( $file['path'] );
		if ( $bytes === false ) {
			return new WP_Error( 'ism_vision_unreadable', 'Could not read ' . basename( $file['path'] ) . '.' );
		}

		return [
			'media_type'  => $mime,
			'data'        => base64_encode( $bytes ),
			'bytes'       => strlen( $bytes ),
			'size_name'   => $file['size_name'],
			'source_mime' => $mime,
			'converted'   => false,
		];
	}

	// Needs transcoding. Skip the attempt entirely if this host has already
	// proven it cannot decode this format, so a 700-image run does not repeat
	// a known-failing decode 700 times.
	if ( ism_vision_cap( $mime ) === false ) {
		return ism_vision_client_error( $mime );
	}

	$jpeg = ism_vision_transcode( $file['path'], $mime );

	if ( is_wp_error( $jpeg ) ) {
		// Remember the failure only when nothing on the host could decode the
		// format. A single corrupt file should not condemn the whole mime.
		if ( $jpeg->get_error_code() === ISM_VISION_ERR_CLIENT ) {
			ism_vision_set_cap( $mime, false );
		}
		return $jpeg;
	}

	ism_vision_set_cap( $mime, true );

	return [
		'media_type'  => 'image/jpeg',
		'data'        => base64_encode( $jpeg ),
		'bytes'       => strlen( $jpeg ),
		'size_name'   => $file['size_name'],
		'source_mime' => $mime,
		'converted'   => true,
	];
}

/**
 * Whether this attachment will need the browser to convert it.
 *
 * Lets the admin screen mark rows before a run starts instead of discovering it
 * mid-batch. Answers from cached capability only; it never decodes.
 *
 * @param int $attachment_id
 * @return bool
 */
function ism_vision_needs_client_decode( int $attachment_id ): bool {
	$mime = (string) get_post_mime_type( $attachment_id );

	if ( in_array( $mime, ISM_VISION_MIME_OK, true ) ) {
		return false;
	}

	return ism_vision_cap( $mime ) === false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Choosing which file on disk to send
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pick the smallest generated size that still shows what the image is.
 *
 * Sending the original is the expensive mistake: a 2000px photo costs several
 * times a 300px one and adds nothing to a description of its subject. On an
 * AVIF site the sub-sizes are AVIF too, so this narrows what has to be
 * transcoded rather than avoiding transcoding.
 *
 * @param int $attachment_id
 * @return array{path:string, mime:string, bytes:int, size_name:string}|WP_Error
 */
function ism_vision_pick_file( int $attachment_id ) {
	$original = get_attached_file( $attachment_id );
	if ( ! $original ) {
		return new WP_Error( 'ism_vision_no_file', 'Attachment ' . $attachment_id . ' has no file path.' );
	}

	$meta      = wp_get_attachment_metadata( $attachment_id );
	$dir       = dirname( $original );
	$preferred = [ 'medium', 'medium_large', 'large' ];

	foreach ( $preferred as $size_name ) {
		$size = $meta['sizes'][ $size_name ] ?? null;
		if ( ! is_array( $size ) || empty( $size['file'] ) ) {
			continue;
		}

		$path = $dir . '/' . basename( (string) $size['file'] );
		if ( ! ism_vision_readable( $path ) ) {
			continue;
		}

		return [
			'path'      => $path,
			'mime'      => ism_vision_mime_of( $path, (string) ( $size['mime-type'] ?? '' ) ),
			'bytes'     => (int) filesize( $path ),
			'size_name' => $size_name,
		];
	}

	// No usable sub-size. Common for images uploaded below the medium
	// threshold, and for anything whose sizes were never generated.
	if ( ! ism_vision_readable( $original ) ) {
		return new WP_Error(
			'ism_vision_missing',
			'File missing on disk: ' . basename( $original ) . '. The uploads directory may be incomplete or offloaded.'
		);
	}

	return [
		'path'      => $original,
		'mime'      => ism_vision_mime_of( $original, (string) get_post_mime_type( $attachment_id ) ),
		'bytes'     => (int) filesize( $original ),
		'size_name' => 'full',
	];
}

/**
 * Readable, non-empty, and inside the uploads directory.
 *
 * The boundary check matters more here than in the deletion paths it mirrors:
 * whatever this returns gets base64-encoded and sent to a third party.
 *
 * @param string $path
 * @return bool
 */
function ism_vision_readable( string $path ): bool {
	if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
		return false;
	}
	if ( filesize( $path ) < 1 ) {
		return false;
	}

	$uploads = wp_get_upload_dir();
	$base    = realpath( $uploads['basedir'] );
	$real    = realpath( $path );

	if ( $base === false || $real === false ) {
		return false;
	}

	return str_starts_with( $real, $base . DIRECTORY_SEPARATOR );
}

/**
 * Mime for a file, trusting the bytes over the stored metadata.
 *
 * WordPress's recorded mime-type can be stale after a format migration, and
 * sending the wrong media_type to the API fails the whole request.
 *
 * @param string $path
 * @param string $fallback
 * @return string
 */
function ism_vision_mime_of( string $path, string $fallback = '' ): string {
	$detected = wp_get_image_mime( $path );

	return is_string( $detected ) && $detected !== '' ? $detected : $fallback;
}

// ─────────────────────────────────────────────────────────────────────────────
// Transcoding
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convert an image on disk to JPEG bytes, downscaling to ISM_VISION_MAX_EDGE.
 *
 * Imagick first: it reports its own formats accurately, so a negative there is
 * a real answer rather than a guess. GD second, and only ever judged by whether
 * a decode actually returned a resource.
 *
 * @param string $path
 * @param string $mime
 * @return string|WP_Error JPEG bytes.
 */
function ism_vision_transcode( string $path, string $mime ) {
	$jpeg = ism_vision_transcode_imagick( $path );
	if ( is_string( $jpeg ) ) {
		return $jpeg;
	}

	$jpeg = ism_vision_transcode_gd( $path, $mime );
	if ( is_string( $jpeg ) ) {
		return $jpeg;
	}

	return ism_vision_client_error( $mime );
}

/**
 * Imagick transcode, or null when Imagick cannot help.
 *
 * @param string $path
 * @return string|null
 */
function ism_vision_transcode_imagick( string $path ): ?string {
	if ( ! class_exists( 'Imagick' ) ) {
		return null;
	}

	try {
		$im = new Imagick();
		$im->readImage( $path );

		// Flatten first: AVIF and PNG carry alpha, and JPEG has none, so an
		// unflattened transparent region encodes as black.
		$im->setImageBackgroundColor( 'white' );
		$im = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );

		$width  = (int) $im->getImageWidth();
		$height = (int) $im->getImageHeight();

		if ( max( $width, $height ) > ISM_VISION_MAX_EDGE ) {
			$im->thumbnailImage(
				$width >= $height ? ISM_VISION_MAX_EDGE : 0,
				$width >= $height ? 0 : ISM_VISION_MAX_EDGE
			);
		}

		$im->setImageFormat( 'jpeg' );
		$im->setImageCompressionQuality( ISM_VISION_JPEG_QUALITY );
		$im->stripImage();

		$bytes = $im->getImageBlob();
		$im->clear();
		$im->destroy();

		return is_string( $bytes ) && $bytes !== '' ? $bytes : null;
	} catch ( Throwable $e ) {
		// An unsupported format throws here, which is the expected negative on
		// an ImageMagick build predating the format.
		return null;
	}
}

/**
 * GD transcode, or null when GD cannot decode the file.
 *
 * Deliberately ignores gd_info() and imagetypes(): both report AVIF support on
 * builds where every AVIF decode fails. The return value of the decode is the
 * only trustworthy signal.
 *
 * @param string $path
 * @param string $mime
 * @return string|null
 */
function ism_vision_transcode_gd( string $path, string $mime ): ?string {
	$loaders = [
		'image/avif' => 'imagecreatefromavif',
		'image/webp' => 'imagecreatefromwebp',
		'image/png'  => 'imagecreatefrompng',
		'image/jpeg' => 'imagecreatefromjpeg',
		'image/gif'  => 'imagecreatefromgif',
	];

	$loader = $loaders[ $mime ] ?? null;
	$image  = false;

	if ( $loader !== null && function_exists( $loader ) ) {
		$image = @$loader( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// imagecreatefromstring sniffs the format itself, which recovers the case
	// where the recorded mime is wrong but the bytes are fine.
	if ( ! $image ) {
		$raw = file_get_contents( $path );
		if ( $raw !== false ) {
			$image = @imagecreatefromstring( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	if ( ! $image ) {
		return null;
	}

	$width  = imagesx( $image );
	$height = imagesy( $image );

	if ( max( $width, $height ) > ISM_VISION_MAX_EDGE ) {
		$scale  = ISM_VISION_MAX_EDGE / max( $width, $height );
		$scaled = imagescale( $image, (int) round( $width * $scale ), (int) round( $height * $scale ) );
		if ( $scaled !== false ) {
			imagedestroy( $image );
			$image  = $scaled;
			$width  = imagesx( $image );
			$height = imagesy( $image );
		}
	}

	// Composite onto white so transparency does not encode as black.
	$flat = imagecreatetruecolor( $width, $height );
	if ( $flat === false ) {
		imagedestroy( $image );
		return null;
	}

	imagefill( $flat, 0, 0, imagecolorallocate( $flat, 255, 255, 255 ) );
	imagecopy( $flat, $image, 0, 0, 0, 0, $width, $height );
	imagedestroy( $image );

	ob_start();
	$ok = imagejpeg( $flat, null, ISM_VISION_JPEG_QUALITY );
	$bytes = (string) ob_get_clean();
	imagedestroy( $flat );

	return $ok && $bytes !== '' ? $bytes : null;
}

/**
 * The "hand this to the browser" error, with a message a dev can act on.
 *
 * @param string $mime
 * @return WP_Error
 */
function ism_vision_client_error( string $mime ): WP_Error {
	return new WP_Error(
		ISM_VISION_ERR_CLIENT,
		sprintf(
			'This server cannot decode %s. The browser will convert it instead.',
			str_replace( 'image/', '', $mime ) ?: 'this format'
		),
		[ 'mime' => $mime ]
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Cached host capability
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Cached decode capability for one mime: true, false, or null when untested.
 *
 * @param string $mime
 * @return bool|null
 */
function ism_vision_cap( string $mime ): ?bool {
	$caps = get_option( ISM_VISION_CAPS_KEY, [] );

	if ( ! is_array( $caps ) || ( $caps['env'] ?? '' ) !== ism_vision_env_signature() ) {
		return null;
	}

	$value = $caps['mimes'][ $mime ] ?? null;

	return is_bool( $value ) ? $value : null;
}

/**
 * Record what this host turned out to be able to decode.
 *
 * @param string $mime
 * @param bool   $can
 */
function ism_vision_set_cap( string $mime, bool $can ): void {
	$caps = get_option( ISM_VISION_CAPS_KEY, [] );
	$env  = ism_vision_env_signature();

	if ( ! is_array( $caps ) || ( $caps['env'] ?? '' ) !== $env ) {
		$caps = [ 'env' => $env, 'mimes' => [] ];
	}

	$caps['mimes'][ $mime ] = $can;

	update_option( ISM_VISION_CAPS_KEY, $caps, false );
}

/**
 * Discard cached capabilities so the next attempt re-probes.
 */
function ism_vision_reset_caps(): void {
	delete_option( ISM_VISION_CAPS_KEY );
}

/**
 * Fingerprint of the image stack, so a host upgrade invalidates the cache.
 *
 * A site moved from a container without libavif to one with it should start
 * using the server path without anyone remembering to clear anything.
 *
 * @return string
 */
function ism_vision_env_signature(): string {
	$gd      = function_exists( 'gd_info' ) ? ( gd_info()['GD Version'] ?? '' ) : '';
	$imagick = '';

	if ( class_exists( 'Imagick' ) ) {
		$version = Imagick::getVersion();
		$imagick = (string) ( $version['versionString'] ?? '' );
	}

	return md5( PHP_VERSION . '|' . $gd . '|' . $imagick );
}

/**
 * What this host can actually do, for the admin screen and the setup guide.
 *
 * Reports capability flags alongside cached real-world results precisely
 * because they disagree on some hosts, and a dev debugging a site needs to see
 * that they disagree rather than trust either one alone.
 *
 * @return array
 */
function ism_vision_host_report(): array {
	$imagick_formats = [];
	$imagick_version = '';

	if ( class_exists( 'Imagick' ) ) {
		$version         = Imagick::getVersion();
		$imagick_version = (string) ( $version['versionString'] ?? '' );
		$imagick_formats = array_values( array_intersect(
			Imagick::queryFormats(),
			[ 'AVIF', 'HEIC', 'HEIF', 'WEBP', 'JPEG', 'PNG', 'GIF' ]
		) );
	}

	$caps = get_option( ISM_VISION_CAPS_KEY, [] );

	return [
		'php'             => PHP_VERSION,
		'gd'              => function_exists( 'gd_info' ) ? ( gd_info()['GD Version'] ?? '' ) : '',
		'gd_claims_avif'  => defined( 'IMG_AVIF' ) && (bool) ( imagetypes() & IMG_AVIF ),
		'imagick'         => $imagick_version,
		'imagick_formats' => $imagick_formats,
		'proven'          => is_array( $caps ) && ( $caps['env'] ?? '' ) === ism_vision_env_signature()
			? ( $caps['mimes'] ?? [] )
			: [],
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// Browser fallback
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate and decode a JPEG the browser converted on our behalf.
 *
 * Everything here is attacker-controlled by definition — it arrives over POST —
 * so the payload is checked for shape, size and actual image-ness before any of
 * it is passed on. The declared mime is discarded in favour of what the decoded
 * bytes really are.
 *
 * @param string $data_url A data: URL produced by canvas.toDataURL().
 * @return array{media_type:string, data:string, bytes:int}|WP_Error
 */
function ism_vision_accept_client_image( string $data_url ) {
	if ( ! preg_match( '~^data:image/(jpeg|png|webp);base64,~i', $data_url, $match ) ) {
		return new WP_Error( 'ism_vision_bad_payload', 'Converted image was not a supported data URL.' );
	}

	$encoded = substr( $data_url, strlen( $match[0] ) );

	// Base64 inflates by about a third, so cap before decoding rather than
	// after — a caller should not be able to spend the memory first.
	if ( strlen( $encoded ) > (int) ceil( ISM_VISION_MAX_BYTES * 1.4 ) ) {
		return new WP_Error( 'ism_vision_too_large', 'Converted image exceeds the size limit.' );
	}

	$bytes = base64_decode( $encoded, true );
	if ( $bytes === false || $bytes === '' ) {
		return new WP_Error( 'ism_vision_bad_payload', 'Converted image was not valid base64.' );
	}

	if ( strlen( $bytes ) > ISM_VISION_MAX_BYTES ) {
		return new WP_Error( 'ism_vision_too_large', 'Converted image exceeds the size limit.' );
	}

	// Confirm it decodes as an image and take the mime from the bytes.
	$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
		return new WP_Error( 'ism_vision_bad_payload', 'Converted image did not decode as an image.' );
	}

	if ( ! in_array( $info['mime'], ISM_VISION_MIME_OK, true ) ) {
		return new WP_Error( 'ism_vision_bad_payload', 'Converted image was not an accepted format.' );
	}

	return [
		'media_type' => (string) $info['mime'],
		'data'       => base64_encode( $bytes ),
		'bytes'      => strlen( $bytes ),
	];
}

/**
 * URL the browser should load in order to convert an attachment itself.
 *
 * Mirrors ism_vision_pick_file()'s preference so the browser converts the same
 * small file the server would have, rather than pulling a full-size original
 * across the wire.
 *
 * @param int $attachment_id
 * @return string Empty when no suitable URL exists.
 */
function ism_vision_client_url( int $attachment_id ): string {
	foreach ( [ 'medium', 'medium_large', 'large', 'full' ] as $size_name ) {
		$src = wp_get_attachment_image_src( $attachment_id, $size_name );
		if ( is_array( $src ) && ! empty( $src[0] ) ) {
			return (string) $src[0];
		}
	}

	return '';
}
