<?php
/**
 * Price calculation engine. Everything is derived from the per-m² (or
 * fixed) prices stored on the catalogue posts, so changing a price in
 * wp-admin immediately changes what customers pay -- no code changes.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Pricing {

	/**
	 * Validate requested print dimensions against the configured bounds.
	 *
	 * @param float $width_cm
	 * @param float $height_cm
	 * @return true|WP_Error
	 */
	public static function validate_dimensions( $width_cm, $height_cm ) {
		$settings = PPS_Settings::get();

		$width_cm  = floatval( $width_cm );
		$height_cm = floatval( $height_cm );

		if ( $width_cm <= 0 || $height_cm <= 0 ) {
			return new WP_Error( 'pps_invalid_size', __( 'Geef een geldige breedte en hoogte op.', 'photo-print-studio' ) );
		}

		if ( $width_cm < $settings['min_size_cm'] || $height_cm < $settings['min_size_cm'] ) {
			return new WP_Error(
				'pps_size_too_small',
				sprintf(
					/* translators: %s: minimum size in cm */
					__( 'De kleinste zijde moet minstens %s cm zijn.', 'photo-print-studio' ),
					$settings['min_size_cm']
				)
			);
		}

		// The long side may run up to max_height_cm, the short side up to
		// max_width_cm, regardless of print orientation.
		$long  = max( $width_cm, $height_cm );
		$short = min( $width_cm, $height_cm );

		if ( $short > $settings['max_width_cm'] || $long > $settings['max_height_cm'] ) {
			return new WP_Error(
				'pps_size_too_large',
				sprintf(
					/* translators: 1: max width cm, 2: max length cm */
					__( 'Het formaat is te groot. Maximaal %1$s cm breed en %2$s cm lang.', 'photo-print-studio' ),
					$settings['max_width_cm'],
					$settings['max_height_cm']
				)
			);
		}

		return true;
	}

	/**
	 * @param float $width_cm
	 * @param float $height_cm
	 * @return float Square metres.
	 */
	public static function area_m2( $width_cm, $height_cm ) {
		return ( floatval( $width_cm ) / 100 ) * ( floatval( $height_cm ) / 100 );
	}

	/**
	 * Perimeter (omtrek) in running/linear metres -- used to price frames
	 * and edge trims, which are bought and cut by length rather than area.
	 *
	 * @param float $width_cm
	 * @param float $height_cm
	 * @return float Linear metres.
	 */
	public static function perimeter_m( $width_cm, $height_cm ) {
		return 2 * ( floatval( $width_cm ) + floatval( $height_cm ) ) / 100;
	}

	/**
	 * Effective print resolution in pixels-per-inch for the given image
	 * pixel dimensions printed at the given physical size.
	 *
	 * @param int   $px    Pixel dimension (width or height).
	 * @param float $cm    Physical dimension in cm, on the same axis.
	 * @return float
	 */
	public static function effective_dpi( $px, $cm ) {
		if ( $cm <= 0 ) {
			return 0;
		}
		$inches = $cm / 2.54;
		return $px / $inches;
	}

	/**
	 * Full price breakdown for a set of wizard choices.
	 *
	 * @param array $args {
	 *     @type float $width_cm
	 *     @type float $height_cm
	 *     @type int   $format_id Optional post ID of a pps_format (0 = custom size).
	 *     @type int   $paper_id  Required post ID of a pps_paper.
	 *     @type int   $mount_id  Required post ID of a pps_mount.
	 *     @type int   $finish_id Optional post ID of a pps_finish (0 = none).
	 * }
	 * @return array|WP_Error
	 */
	public static function calculate( $args ) {
		$defaults = array(
			'width_cm'  => 0,
			'height_cm' => 0,
			'format_id' => 0,
			'paper_id'  => 0,
			'mount_id'  => 0,
			'finish_id' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$size_check = self::validate_dimensions( $args['width_cm'], $args['height_cm'] );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		if ( ! $args['paper_id'] || PPS_CPT::PAPER !== get_post_type( $args['paper_id'] ) ) {
			return new WP_Error( 'pps_invalid_paper', __( 'Kies een geldig papier.', 'photo-print-studio' ) );
		}

		if ( ! $args['mount_id'] || PPS_CPT::MOUNT !== get_post_type( $args['mount_id'] ) ) {
			return new WP_Error( 'pps_invalid_mount', __( 'Kies een geldige montage.', 'photo-print-studio' ) );
		}

		$mount_requires_finish = '1' === get_post_meta( $args['mount_id'], '_pps_requires_finish', true );

		if ( $mount_requires_finish ) {
			if ( ! $args['finish_id'] || PPS_CPT::FINISH !== get_post_type( $args['finish_id'] ) ) {
				return new WP_Error( 'pps_invalid_finish', __( 'Kies een geldige afwerking.', 'photo-print-studio' ) );
			}
		} else {
			$args['finish_id'] = 0;
		}

		$area      = self::area_m2( $args['width_cm'], $args['height_cm'] );
		$perimeter = self::perimeter_m( $args['width_cm'], $args['height_cm'] );

		$paper_price_per_m2 = (float) get_post_meta( $args['paper_id'], '_pps_price_per_m2', true );
		$mount_price_per_m2 = (float) get_post_meta( $args['mount_id'], '_pps_price_per_m2', true );
		$format_surcharge   = $args['format_id'] ? (float) get_post_meta( $args['format_id'], '_pps_surcharge', true ) : 0;

		$finish_price_per_lm = 0;
		$finish_price_per_m2 = 0;
		$finish_price_fixed  = 0;
		if ( $args['finish_id'] ) {
			$finish_price_per_lm = (float) get_post_meta( $args['finish_id'], '_pps_price_per_lm', true );
			$finish_price_per_m2 = (float) get_post_meta( $args['finish_id'], '_pps_price_per_m2', true );
			$finish_price_fixed  = (float) get_post_meta( $args['finish_id'], '_pps_price_fixed', true );
		}

		$settings     = PPS_Settings::get();
		$handling_fee = (float) $settings['handling_fee'];

		$paper_cost  = round( $area * $paper_price_per_m2, 2 );
		$mount_cost  = round( $area * $mount_price_per_m2, 2 );
		$finish_cost = round( ( $perimeter * $finish_price_per_lm ) + ( $area * $finish_price_per_m2 ) + $finish_price_fixed, 2 );

		// This is the price for one print in this configuration. The
		// handling fee is charged once per order (not per print / not
		// multiplied by quantity), so it's applied separately as a
		// WooCommerce cart fee -- see PPS_WooCommerce -- and only reported
		// here for display.
		$total = round( $paper_cost + $mount_cost + $finish_cost + $format_surcharge, 2 );

		return array(
			'width_cm'             => floatval( $args['width_cm'] ),
			'height_cm'            => floatval( $args['height_cm'] ),
			'area_m2'              => round( $area, 4 ),
			'perimeter_m'          => round( $perimeter, 4 ),
			'paper_id'             => (int) $args['paper_id'],
			'paper_name'           => get_the_title( $args['paper_id'] ),
			'paper_cost'           => $paper_cost,
			'mount_id'             => (int) $args['mount_id'],
			'mount_name'           => get_the_title( $args['mount_id'] ),
			'mount_cost'           => $mount_cost,
			'mount_requires_finish'=> $mount_requires_finish,
			'finish_id'            => (int) $args['finish_id'],
			'finish_name'          => $args['finish_id'] ? get_the_title( $args['finish_id'] ) : '',
			'finish_cost'          => $finish_cost,
			'format_id'            => (int) $args['format_id'],
			'format_name'          => $args['format_id'] ? get_the_title( $args['format_id'] ) : __( 'Aangepast formaat', 'photo-print-studio' ),
			'format_surcharge'     => $format_surcharge,
			'handling_fee'         => $handling_fee,
			'total'                => $total,
		);
	}
}
