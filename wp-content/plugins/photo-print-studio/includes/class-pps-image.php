<?php
/**
 * Handles validation and storage of customer-uploaded photos: mime/size
 * checks, saving as a real Media Library attachment (so shop staff can find
 * and download the original from the order screen), and reading pixel
 * dimensions for the DPI check.
 *
 * Uploads happen in small chunks (see PPS_Rest's /upload/init, /upload/chunk
 * and /upload/complete routes) instead of one single multipart request.
 * Many hosts cap a single request's body at a few MB via upload_max_filesize
 * / post_max_size (a setting this plugin cannot change from PHP at runtime),
 * so chunking keeps every individual request small regardless of how large
 * the customer's original photo is.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Image {

	const SESSION_PREFIX = 'pps_upload_';
	const SESSION_TTL    = 3 * HOUR_IN_SECONDS;

	/**
	 * @return array Allowed upload mime types, keyed by extension.
	 */
	public static function allowed_mime_types() {
		return array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'tif|tiff' => 'image/tiff',
			'webp'     => 'image/webp',
		);
	}

	/**
	 * @return int Maximum total upload size in bytes (default 200 MB,
	 *             filterable for large TIFFs used in fine-art printing).
	 */
	public static function max_upload_bytes() {
		return (int) apply_filters( 'pps_max_upload_bytes', 200 * MB_IN_BYTES );
	}

	/**
	 * @return int Maximum size of a single chunk (defensive upper bound;
	 *             the wizard itself sends much smaller chunks).
	 */
	public static function max_chunk_bytes() {
		return (int) apply_filters( 'pps_max_chunk_bytes', 8 * MB_IN_BYTES );
	}

	/**
	 * @return string Absolute path to the directory temp chunks are
	 *                 assembled in.
	 */
	private static function tmp_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'pps-uploads/tmp/';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Starts a new chunked-upload session.
	 *
	 * @param string $filename  Original filename (for extension/type checks).
	 * @param int    $filesize  Declared total size in bytes.
	 * @param string $mime_type Declared mime type.
	 * @return array|WP_Error { @type string $upload_id }
	 */
	public static function start_upload( $filename, $filesize, $mime_type ) {
		$filesize = (int) $filesize;

		if ( $filesize <= 0 ) {
			return new WP_Error( 'pps_invalid_size', __( 'Ongeldige bestandsgrootte.', 'photo-print-studio' ) );
		}

		if ( $filesize > self::max_upload_bytes() ) {
			return new WP_Error(
				'pps_file_too_large',
				sprintf(
					/* translators: %s: max size in MB */
					__( 'Bestand is te groot. Maximum is %s MB.', 'photo-print-studio' ),
					round( self::max_upload_bytes() / MB_IN_BYTES )
				)
			);
		}

		$filetype = wp_check_filetype( $filename, self::allowed_mime_types() );
		if ( ! $filetype['ext'] ) {
			return new WP_Error( 'pps_invalid_type', __( 'Dit bestandstype wordt niet ondersteund. Gebruik JPG, PNG, TIFF of WEBP.', 'photo-print-studio' ) );
		}

		$upload_id = wp_generate_uuid4();
		$tmp_path  = self::tmp_dir() . $upload_id . '.part';

		// Pre-allocate an empty file so chunk writes can seek freely.
		if ( false === file_put_contents( $tmp_path, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'pps_upload_error', __( 'Kon de upload niet starten. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		set_transient(
			self::SESSION_PREFIX . $upload_id,
			array(
				'filename'  => sanitize_file_name( $filename ),
				'ext'       => $filetype['ext'],
				'filesize'  => $filesize,
				'tmp_path'  => $tmp_path,
			),
			self::SESSION_TTL
		);

		return array( 'upload_id' => $upload_id );
	}

	/**
	 * Writes one chunk of a previously-started upload session at its
	 * correct byte offset, so retried/out-of-order chunks are harmless.
	 *
	 * @param string $upload_id
	 * @param int    $index      Zero-based chunk index.
	 * @param int    $chunk_size The chunk size the client is using (bytes).
	 * @param string $tmp_file   Path to the chunk's own PHP temp upload file.
	 * @return true|WP_Error
	 */
	public static function write_chunk( $upload_id, $index, $chunk_size, $tmp_file ) {
		$session = get_transient( self::SESSION_PREFIX . $upload_id );
		if ( ! $session ) {
			return new WP_Error( 'pps_unknown_upload', __( 'Onbekende of verlopen upload-sessie. Begin opnieuw.', 'photo-print-studio' ) );
		}

		$chunk_bytes = @filesize( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $chunk_bytes || $chunk_bytes > self::max_chunk_bytes() ) {
			return new WP_Error( 'pps_chunk_too_large', __( 'Ongeldig fragment ontvangen.', 'photo-print-studio' ) );
		}

		$offset = absint( $index ) * absint( $chunk_size );

		$handle = @fopen( $session['tmp_path'], 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return new WP_Error( 'pps_upload_error', __( 'Kon fragment niet opslaan. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		fseek( $handle, $offset );
		fwrite( $handle, file_get_contents( $tmp_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
		fclose( $handle );

		// Refresh the session TTL on activity.
		set_transient( self::SESSION_PREFIX . $upload_id, $session, self::SESSION_TTL );

		return true;
	}

	/**
	 * Finalises a chunked upload: validates the assembled file, stores it as
	 * a Media Library attachment, and returns the same shape the wizard
	 * expects.
	 *
	 * @param string $upload_id
	 * @return array|WP_Error {
	 *     @type int    $attachment_id
	 *     @type string $url
	 *     @type int    $width
	 *     @type int    $height
	 * }
	 */
	public static function complete_upload( $upload_id ) {
		$session = get_transient( self::SESSION_PREFIX . $upload_id );
		if ( ! $session ) {
			return new WP_Error( 'pps_unknown_upload', __( 'Onbekende of verlopen upload-sessie. Begin opnieuw.', 'photo-print-studio' ) );
		}

		$tmp_path    = $session['tmp_path'];
		$actual_size = @filesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $actual_size || $actual_size < $session['filesize'] ) {
			return new WP_Error( 'pps_incomplete_upload', __( 'De upload is niet volledig aangekomen. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		$dimensions = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $dimensions ) {
			self::cleanup_session( $upload_id, $session );
			return new WP_Error( 'pps_invalid_image', __( 'Dit lijkt geen geldige afbeelding te zijn.', 'photo-print-studio' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		$upload_dir = wp_upload_dir();
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );

		if ( ! wp_mkdir_p( $upload_dir['path'] ) ) {
			self::cleanup_session( $upload_id, $session );
			return new WP_Error( 'pps_upload_error', __( 'Kon de foto niet opslaan. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		$final_filename = wp_unique_filename( $upload_dir['path'], $session['filename'] );
		$final_path     = trailingslashit( $upload_dir['path'] ) . $final_filename;

		if ( ! @rename( $tmp_path, $final_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::cleanup_session( $upload_id, $session );
			return new WP_Error( 'pps_upload_error', __( 'Kon de foto niet opslaan. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		$mime_type  = self::allowed_mime_types();
		$filetype   = wp_check_filetype( $final_filename, $mime_type );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( pathinfo( $session['filename'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'private',
		);

		$attachment_id = wp_insert_attachment( $attachment, $final_path );
		if ( is_wp_error( $attachment_id ) ) {
			self::cleanup_session( $upload_id, $session );
			return $attachment_id;
		}

		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $final_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		update_post_meta( $attachment_id, '_pps_customer_upload', '1' );
		update_post_meta( $attachment_id, '_pps_pixel_width', $dimensions[0] );
		update_post_meta( $attachment_id, '_pps_pixel_height', $dimensions[1] );

		delete_transient( self::SESSION_PREFIX . $upload_id );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'width'         => $dimensions[0],
			'height'        => $dimensions[1],
		);
	}

	/**
	 * Removes a failed/abandoned upload session's temp file and transient.
	 *
	 * @param string $upload_id
	 * @param array  $session
	 */
	private static function cleanup_session( $upload_id, $session ) {
		if ( ! empty( $session['tmp_path'] ) && file_exists( $session['tmp_path'] ) ) {
			wp_delete_file( $session['tmp_path'] );
		}
		delete_transient( self::SESSION_PREFIX . $upload_id );
	}

	/**
	 * Deletes temp chunk files left behind by abandoned uploads (started but
	 * never completed within SESSION_TTL). Hooked to a daily cron event.
	 */
	public static function cleanup_stale_tmp_files() {
		$dir = self::tmp_dir();
		foreach ( glob( $dir . '*.part' ) as $path ) {
			if ( filemtime( $path ) < time() - self::SESSION_TTL ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Stores customer uploads under a dedicated uploads sub-folder instead
	 * of mixing them into the regular media uploads directory.
	 *
	 * @param array $dirs
	 * @return array
	 */
	public static function filter_upload_dir( $dirs ) {
		$dirs['subdir'] = '/pps-uploads' . $dirs['subdir'];
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/**
	 * Whether an attachment ID was created through our own upload endpoint,
	 * so REST callers can't point add-to-cart at an arbitrary Media Library
	 * item.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_customer_upload( $attachment_id ) {
		return 'attachment' === get_post_type( $attachment_id )
			&& '1' === get_post_meta( $attachment_id, '_pps_customer_upload', true );
	}

	/**
	 * Re-reads pixel dimensions for an existing attachment. Used server-side
	 * to double check the client-reported dimensions before pricing/adding
	 * to cart.
	 *
	 * @param int $attachment_id
	 * @return array|WP_Error
	 */
	public static function get_dimensions( $attachment_id ) {
		$width  = get_post_meta( $attachment_id, '_pps_pixel_width', true );
		$height = get_post_meta( $attachment_id, '_pps_pixel_height', true );

		if ( ! $width || ! $height ) {
			return new WP_Error( 'pps_unknown_dimensions', __( 'Kan de afmetingen van deze foto niet bepalen.', 'photo-print-studio' ) );
		}

		return array(
			'width'  => (int) $width,
			'height' => (int) $height,
		);
	}

	/**
	 * @return int Above this source-file size, crop-file generation is
	 *             skipped (falls back to the original) to avoid a very
	 *             large rotate/crop operation blocking checkout.
	 */
	public static function max_crop_source_bytes() {
		return (int) apply_filters( 'pps_max_crop_source_bytes', 80 * MB_IN_BYTES );
	}

	/**
	 * Produces the actual print-ready file for one ordered photo: the
	 * original, rotated and cropped exactly the way the customer set it up
	 * in the wizard, saved as a new Media Library attachment. Used so the
	 * shop and the "new order" email receive the file that should actually
	 * be printed, not just the untouched original plus coordinates.
	 *
	 * @param int   $original_attachment_id
	 * @param array $crop { @type float $sx, $sy, $sw, $sh, $rotation }
	 * @return array|WP_Error { @type int $attachment_id, @type string $url }
	 */
	public static function generate_crop_attachment( $original_attachment_id, $crop ) {
		if ( empty( $crop ) || ! isset( $crop['sw'], $crop['sh'], $crop['sx'], $crop['sy'] ) || ! $crop['sw'] || ! $crop['sh'] ) {
			return new WP_Error( 'pps_invalid_crop', __( 'Geen geldige uitsnede-gegevens.', 'photo-print-studio' ) );
		}

		$original_path = get_attached_file( $original_attachment_id );
		if ( ! $original_path || ! file_exists( $original_path ) ) {
			return new WP_Error( 'pps_missing_file', __( 'Origineel bestand niet gevonden.', 'photo-print-studio' ) );
		}

		if ( filesize( $original_path ) > self::max_crop_source_bytes() ) {
			return new WP_Error( 'pps_source_too_large', __( 'Bestand te groot om automatisch uit te snijden.', 'photo-print-studio' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor( $original_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		// The wizard's rotation is clockwise (matching <canvas> rotate()),
		// while WP_Image_Editor::rotate() turns counter-clockwise -- so the
		// two need converting between one another.
		$rotation = ! empty( $crop['rotation'] ) ? ( (int) round( $crop['rotation'] ) ) % 360 : 0;
		if ( $rotation ) {
			$rotated = $editor->rotate( ( 360 - $rotation ) % 360 );
			if ( is_wp_error( $rotated ) ) {
				return $rotated;
			}
		}

		$cropped = $editor->crop(
			(int) round( $crop['sx'] ),
			(int) round( $crop['sy'] ),
			(int) round( $crop['sw'] ),
			(int) round( $crop['sh'] ),
			null,
			null,
			true // Absolute pixel coordinates (not percentages).
		);
		if ( is_wp_error( $cropped ) ) {
			return $cropped;
		}

		$editor->set_quality( 95 );

		$upload_dir = wp_upload_dir();
		$crop_dir   = trailingslashit( $upload_dir['basedir'] ) . 'pps-uploads/crops/';
		wp_mkdir_p( $crop_dir );

		$filename = wp_unique_filename( $crop_dir, 'print-crop-' . $original_attachment_id . '.jpg' );
		$saved    = $editor->save( $crop_dir . $filename, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$attachment = array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'print-crop-' . $original_attachment_id,
			'post_content'   => '',
			'post_status'    => 'private',
		);

		$attachment_id = wp_insert_attachment( $attachment, $saved['path'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $saved['path'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
		);
	}
}
