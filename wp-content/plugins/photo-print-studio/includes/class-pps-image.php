<?php
/**
 * Handles validation and storage of customer-uploaded photos: mime/size
 * checks, saving as a real Media Library attachment (so shop staff can find
 * and download the original from the order screen), and reading pixel
 * dimensions for the DPI check.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Image {

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
	 * @return int Maximum upload size in bytes (default 200 MB, filterable
	 *             for large TIFFs used in fine-art printing).
	 */
	public static function max_upload_bytes() {
		return (int) apply_filters( 'pps_max_upload_bytes', 200 * MB_IN_BYTES );
	}

	/**
	 * Validate and store an uploaded file (from $_FILES) as a Media Library
	 * attachment.
	 *
	 * @param array $file A single entry from $_FILES.
	 * @return array|WP_Error {
	 *     @type int    $attachment_id
	 *     @type string $url
	 *     @type int    $width
	 *     @type int    $height
	 * }
	 */
	public static function handle_upload( $file ) {
		if ( empty( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) ) {
			return new WP_Error( 'pps_no_file', __( 'Geen bestand ontvangen.', 'photo-print-studio' ) );
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'pps_upload_error', __( 'Upload mislukt. Probeer opnieuw.', 'photo-print-studio' ) );
		}

		if ( $file['size'] > self::max_upload_bytes() ) {
			return new WP_Error(
				'pps_file_too_large',
				sprintf(
					/* translators: %s: max size in MB */
					__( 'Bestand is te groot. Maximum is %s MB.', 'photo-print-studio' ),
					round( self::max_upload_bytes() / MB_IN_BYTES )
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => self::allowed_mime_types(),
		);

		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		$sideloaded = wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );

		if ( isset( $sideloaded['error'] ) ) {
			return new WP_Error( 'pps_upload_error', $sideloaded['error'] );
		}

		$dimensions = @getimagesize( $sideloaded['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $dimensions ) {
			wp_delete_file( $sideloaded['file'] );
			return new WP_Error( 'pps_invalid_image', __( 'Dit lijkt geen geldige afbeelding te zijn.', 'photo-print-studio' ) );
		}

		$attachment = array(
			'post_mime_type' => $sideloaded['type'],
			'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'private',
		);

		$attachment_id = wp_insert_attachment( $attachment, $sideloaded['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		update_post_meta( $attachment_id, '_pps_customer_upload', '1' );
		update_post_meta( $attachment_id, '_pps_pixel_width', $dimensions[0] );
		update_post_meta( $attachment_id, '_pps_pixel_height', $dimensions[1] );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $sideloaded['url'],
			'width'         => $dimensions[0],
			'height'        => $dimensions[1],
		);
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
}
