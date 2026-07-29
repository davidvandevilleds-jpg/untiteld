<?php
/**
 * REST API surface consumed by the front-end wizard (assets/js/wizard.js).
 * All routes live under the pps/v1 namespace.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Rest {

	const NAMESPACE_V1 = 'pps/v1';

	/**
	 * @var PPS_Rest|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_Rest
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/upload/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_init' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/upload/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_chunk' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/upload/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_complete' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/options',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'options' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dpi-check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dpi_check' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'attachment_id' => array( 'required' => true ),
					'width_cm'      => array( 'required' => true ),
					'height_cm'     => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/price',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'price' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/add-to-cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_to_cart' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /upload/init -- starts a chunked upload session for one photo.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_init( WP_REST_Request $request ) {
		$result = PPS_Image::start_upload(
			(string) $request->get_param( 'filename' ),
			$request->get_param( 'filesize' ),
			(string) $request->get_param( 'mime_type' )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /upload/chunk -- appends one chunk (multipart field "chunk") to
	 * an upload session started via /upload/init.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_chunk( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['chunk']['tmp_name'] ) || UPLOAD_ERR_OK !== $files['chunk']['error'] ) {
			return new WP_Error( 'pps_upload_error', __( 'Fragment niet ontvangen. Probeer opnieuw.', 'photo-print-studio' ), array( 'status' => 400 ) );
		}

		$result = PPS_Image::write_chunk(
			(string) $request->get_param( 'upload_id' ),
			absint( $request->get_param( 'index' ) ),
			absint( $request->get_param( 'chunk_size' ) ),
			$files['chunk']['tmp_name']
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /upload/complete -- finalises a chunked upload session into a
	 * Media Library attachment once every chunk has been sent.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_complete( WP_REST_Request $request ) {
		$result = PPS_Image::complete_upload( (string) $request->get_param( 'upload_id' ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$settings  = PPS_Settings::get();
		$threshold = (float) $settings['dpi_threshold'];

		// Suggested maximum print size (in cm, on the long edge) at which
		// this photo still meets the DPI threshold, assuming no cropping.
		$suggested_long_cm = ( max( $result['width'], $result['height'] ) / $threshold ) * 2.54;

		return rest_ensure_response(
			array_merge(
				$result,
				array( 'suggested_max_long_cm' => round( $suggested_long_cm, 1 ) )
			)
		);
	}

	/**
	 * GET /options -- catalogue for the wizard's selection steps.
	 *
	 * @return WP_REST_Response
	 */
	public function options() {
		$formats = array_map(
			function ( $post ) {
				return array(
					'id'         => $post->ID,
					'name'       => get_the_title( $post ),
					'width_cm'   => (float) get_post_meta( $post->ID, '_pps_width_cm', true ),
					'height_cm'  => (float) get_post_meta( $post->ID, '_pps_height_cm', true ),
					'surcharge'  => (float) get_post_meta( $post->ID, '_pps_surcharge', true ),
				);
			},
			PPS_CPT::get_items( PPS_CPT::FORMAT )
		);

		$papers = array_map(
			function ( $post ) {
				return array(
					'id'            => $post->ID,
					'name'          => get_the_title( $post ),
					'description'   => wp_strip_all_tags( $post->post_content ),
					'price_per_m2'  => (float) get_post_meta( $post->ID, '_pps_price_per_m2', true ),
					'image'         => get_the_post_thumbnail_url( $post->ID, 'medium' ),
				);
			},
			PPS_CPT::get_items( PPS_CPT::PAPER )
		);

		$mounts = array_map(
			function ( $post ) {
				return array(
					'id'               => $post->ID,
					'name'             => get_the_title( $post ),
					'description'      => wp_strip_all_tags( $post->post_content ),
					'price_per_m2'     => (float) get_post_meta( $post->ID, '_pps_price_per_m2', true ),
					'requires_finish'  => '1' === get_post_meta( $post->ID, '_pps_requires_finish', true ),
					'image'            => get_the_post_thumbnail_url( $post->ID, 'medium' ),
				);
			},
			PPS_CPT::get_items( PPS_CPT::MOUNT )
		);

		$finishes = array_map(
			function ( $post ) {
				return array(
					'id'            => $post->ID,
					'name'          => get_the_title( $post ),
					'description'   => wp_strip_all_tags( $post->post_content ),
					'price_per_m2'  => (float) get_post_meta( $post->ID, '_pps_price_per_m2', true ),
					'price_fixed'   => (float) get_post_meta( $post->ID, '_pps_price_fixed', true ),
					'image'         => get_the_post_thumbnail_url( $post->ID, 'medium' ),
				);
			},
			PPS_CPT::get_items( PPS_CPT::FINISH )
		);

		$settings = PPS_Settings::get();

		return rest_ensure_response(
			array(
				'formats'  => $formats,
				'papers'   => $papers,
				'mounts'   => $mounts,
				'finishes' => $finishes,
				'settings' => $settings,
				'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€',
			)
		);
	}

	/**
	 * POST /dpi-check -- server-side confirmation of the effective DPI for
	 * an uploaded photo at a requested print size (used to decide whether
	 * to show the crop/quality-warning preview).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function dpi_check( WP_REST_Request $request ) {
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$width_cm      = floatval( $request->get_param( 'width_cm' ) );
		$height_cm     = floatval( $request->get_param( 'height_cm' ) );

		$dimensions = PPS_Image::get_dimensions( $attachment_id );
		if ( is_wp_error( $dimensions ) ) {
			$dimensions->add_data( array( 'status' => 404 ) );
			return $dimensions;
		}

		$size_check = PPS_Pricing::validate_dimensions( $width_cm, $height_cm );
		if ( is_wp_error( $size_check ) ) {
			$size_check->add_data( array( 'status' => 400 ) );
			return $size_check;
		}

		$dpi_w = PPS_Pricing::effective_dpi( $dimensions['width'], $width_cm );
		$dpi_h = PPS_Pricing::effective_dpi( $dimensions['height'], $height_cm );
		$dpi   = min( $dpi_w, $dpi_h );

		$threshold = (float) PPS_Settings::get_value( 'dpi_threshold' );

		$source_ratio  = $dimensions['width'] / $dimensions['height'];
		$target_ratio  = $width_cm / $height_cm;
		$needs_crop    = round( $source_ratio, 3 ) !== round( $target_ratio, 3 );

		return rest_ensure_response(
			array(
				'dpi'            => round( $dpi, 1 ),
				'threshold'      => $threshold,
				'below_threshold'=> $dpi < $threshold,
				'needs_crop'     => $needs_crop,
				'source_width'   => $dimensions['width'],
				'source_height'  => $dimensions['height'],
			)
		);
	}

	/**
	 * POST /price -- price breakdown for a set of selections.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function price( WP_REST_Request $request ) {
		$result = PPS_Pricing::calculate(
			array(
				'width_cm'  => floatval( $request->get_param( 'width_cm' ) ),
				'height_cm' => floatval( $request->get_param( 'height_cm' ) ),
				'format_id' => absint( $request->get_param( 'format_id' ) ),
				'paper_id'  => absint( $request->get_param( 'paper_id' ) ),
				'mount_id'  => absint( $request->get_param( 'mount_id' ) ),
				'finish_id' => absint( $request->get_param( 'finish_id' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /add-to-cart -- validates the shared configuration (format,
	 * paper, mount, finish) and every photo in "items", prices it once, and
	 * (if WooCommerce is active) adds one cart line per photo, each with
	 * its own quantity.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_to_cart( WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return new WP_Error( 'pps_no_woocommerce', __( 'WooCommerce is niet actief; bestellen is nog niet mogelijk.', 'photo-print-studio' ), array( 'status' => 503 ) );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error( 'pps_no_items', __( 'Geen foto\'s om te bestellen.', 'photo-print-studio' ), array( 'status' => 400 ) );
		}

		$pricing_args = array(
			'width_cm'  => floatval( $request->get_param( 'width_cm' ) ),
			'height_cm' => floatval( $request->get_param( 'height_cm' ) ),
			'format_id' => absint( $request->get_param( 'format_id' ) ),
			'paper_id'  => absint( $request->get_param( 'paper_id' ) ),
			'mount_id'  => absint( $request->get_param( 'mount_id' ) ),
			'finish_id' => absint( $request->get_param( 'finish_id' ) ),
		);

		$pricing = PPS_Pricing::calculate( $pricing_args );
		if ( is_wp_error( $pricing ) ) {
			$pricing->add_data( array( 'status' => 400 ) );
			return $pricing;
		}

		// Validate every photo before adding anything to the cart, so a bad
		// item in the batch doesn't leave a partial order behind.
		$validated = array();
		foreach ( $items as $item ) {
			$attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
			if ( ! $attachment_id || ! PPS_Image::is_customer_upload( $attachment_id ) ) {
				return new WP_Error( 'pps_invalid_photo', __( 'Een van de foto\'s is ongeldig. Probeer opnieuw te uploaden.', 'photo-print-studio' ), array( 'status' => 400 ) );
			}

			$crop     = isset( $item['crop'] ) && is_array( $item['crop'] ) ? array_map( 'floatval', $item['crop'] ) : array();
			$quantity = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;

			$validated[] = array(
				'attachment_id' => $attachment_id,
				'crop'          => $crop,
				'quantity'      => $quantity,
			);
		}

		foreach ( $validated as $entry ) {
			$cart_item_data = array(
				'pps_data'   => array_merge(
					$pricing,
					array(
						'attachment_id' => $entry['attachment_id'],
						'crop'          => $entry['crop'],
					)
				),
				'unique_key' => md5( wp_json_encode( $pricing ) . $entry['attachment_id'] . microtime() ),
			);

			$result = PPS_WooCommerce::add_to_cart( $cart_item_data, $pricing['total'], $entry['quantity'] );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'cart_url'     => wc_get_cart_url(),
				'checkout_url' => wc_get_checkout_url(),
				'pricing'      => $pricing,
			)
		);
	}
}
