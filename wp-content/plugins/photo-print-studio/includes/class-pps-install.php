<?php
/**
 * One-time setup on activation: seeds a realistic starter catalogue
 * (standard formats, the Hahnemühle Digital FineArt Collection papers,
 * mount types and Dibond finishes) so the wizard works out of the box, and
 * creates the single hidden WooCommerce product used to represent every
 * custom print in the cart.
 *
 * Seeding is idempotent -- re-activating the plugin (or a future update)
 * will not create duplicates, and never touches items a shop editor has
 * since renamed or re-priced.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Install {

	const SEED_FLAG_OPTION = 'pps_seeded_v1';

	public static function activate() {
		if ( ! get_option( self::SEED_FLAG_OPTION ) ) {
			self::seed_formats();
			self::seed_papers();
			self::seed_mounts();
			self::seed_finishes();
			update_option( self::SEED_FLAG_OPTION, 1 );
		}

		self::migrate_copper_finish_to_black();

		if ( class_exists( 'WooCommerce' ) ) {
			self::create_template_product();
		}
	}

	/**
	 * Renames the "randafwerking koper" finish seeded by earlier versions of
	 * this plugin to "randafwerking zwart" -- copper was dropped in favour
	 * of black. Only touches a post that still has the exact old default
	 * title, so a shop editor who has since renamed or removed it is left
	 * alone. Runs on every (re)activation; cheap no-op once already done.
	 */
	private static function migrate_copper_finish_to_black() {
		$old_title = __( 'Onzichtbaar ophangsysteem + randafwerking koper', 'photo-print-studio' );
		$new_title = __( 'Onzichtbaar ophangsysteem + randafwerking zwart', 'photo-print-studio' );

		$existing = get_posts(
			array(
				'post_type'      => PPS_CPT::FINISH,
				'post_status'    => 'any',
				'title'          => $old_title,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			wp_update_post(
				array(
					'ID'         => $existing[0],
					'post_title' => $new_title,
				)
			);
		}
	}

	/**
	 * Creates a catalogue post if one with the same title doesn't already
	 * exist for that post type.
	 *
	 * @param string $post_type
	 * @param string $title
	 * @param string $content
	 * @param array  $meta
	 * @param int    $menu_order
	 * @return int Post ID.
	 */
	private static function maybe_create( $post_type, $title, $content, $meta = array(), $menu_order = 0 ) {
		$existing = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'menu_order'   => $menu_order,
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	private static function seed_formats() {
		$formats = array(
			array( '20 x 30 cm', 20, 30 ),
			array( '30 x 40 cm', 30, 40 ),
			array( '40 x 60 cm', 40, 60 ),
			array( '50 x 70 cm', 50, 70 ),
			array( '60 x 90 cm', 60, 90 ),
			array( '70 x 100 cm', 70, 100 ),
			array( '80 x 120 cm', 80, 120 ),
			array( 'A4 (21 x 29,7 cm)', 21, 29.7 ),
			array( 'A3 (29,7 x 42 cm)', 29.7, 42 ),
			array( 'A2 (42 x 59,4 cm)', 42, 59.4 ),
			array( 'A1 (59,4 x 84,1 cm)', 59.4, 84.1 ),
			array( 'A0 (84,1 x 118,9 cm)', 84.1, 118.9 ),
		);

		foreach ( $formats as $index => $format ) {
			list( $title, $width, $height ) = $format;
			self::maybe_create(
				PPS_CPT::FORMAT,
				$title,
				'',
				array(
					'_pps_width_cm'  => $width,
					'_pps_height_cm' => $height,
					'_pps_surcharge' => 0,
				),
				$index * 10
			);
		}
	}

	/**
	 * A representative selection from Hahnemühle's Digital FineArt
	 * Collection. Prices are starter placeholders in €/m² -- adjust them
	 * (or add more papers) any time under Print Studio -> Papieren.
	 */
	private static function seed_papers() {
		$papers = array(
			array( 'Photo Rag® 308', 'Zuiver katoenen vezelpapier, mat, natuurlijk wit. Het meest gebruikte FineArt-papier voor fotografie.', 65 ),
			array( 'Photo Rag® Baryta 315', 'Katoenen baryta-papier met een subtiele glans, hoog contrast en diepe zwarttinten.', 72 ),
			array( 'German Etching® 310', 'Structuurrijk, mat kunstdrukpapier met een warme, natuurlijke witte tint.', 68 ),
			array( 'Museum Etching® 350', 'Zwaar, natuurlijk wit etspapier met een zachte textuur, voor tijdloze afdrukken.', 78 ),
			array( 'William Turner® 310', 'Uitgesproken canvas-achtige textuur, ideaal voor artistieke en schilderachtige beelden.', 74 ),
			array( 'Torchon 285', 'Fijne, egale structuur met een klassieke, aquarelachtige uitstraling.', 62 ),
			array( 'Albrecht Dürer® 210', 'Licht getextureerd, natuurlijk wit papier met een traditionele uitstraling.', 58 ),
			array( 'Photo Rag® Metallic 340', 'Parelmoerachtige glans die kleuren extra diepte en een metallic effect geeft.', 82 ),
			array( 'Fine Art Baryta 325', 'Zuivere alfa-cellulose baryta met uitzonderlijk contrast en scherpte.', 76 ),
		);

		foreach ( $papers as $index => $paper ) {
			list( $title, $description, $price ) = $paper;
			self::maybe_create(
				PPS_CPT::PAPER,
				$title,
				$description,
				array( '_pps_price_per_m2' => $price ),
				$index * 10
			);
		}
	}

	private static function seed_mounts() {
		self::maybe_create(
			PPS_CPT::MOUNT,
			__( 'Losse print', 'photo-print-studio' ),
			__( 'Uw foto afgedrukt op het gekozen FineArt-papier, zonder montage.', 'photo-print-studio' ),
			array(
				'_pps_price_per_m2'    => 0,
				'_pps_requires_finish' => '0',
			),
			10
		);

		self::maybe_create(
			PPS_CPT::MOUNT,
			__( 'Kleven op Dibond', 'photo-print-studio' ),
			__( 'Uw print wordt vlak opgeplakt op een stevige Dibond-aluminium plaat, inclusief afwerking naar keuze.', 'photo-print-studio' ),
			array(
				'_pps_price_per_m2'    => 45,
				'_pps_requires_finish' => '1',
			),
			20
		);
	}

	/**
	 * Frame mouldings and edge trims are bought and priced by running
	 * length, not by area -- so their cost is (per_lm x perimeter) rather
	 * than (per_m2 x oppervlakte). The plain hanging system has no visible
	 * trim, so it stays a flat price instead.
	 */
	private static function seed_finishes() {
		$finishes = array(
			array( __( 'Onzichtbaar ophangsysteem', 'photo-print-studio' ), 0, 0, 0 ),
			array( __( 'Onzichtbaar ophangsysteem + randafwerking zilver', 'photo-print-studio' ), 15, 0, 0 ),
			array( __( 'Onzichtbaar ophangsysteem + randafwerking goud', 'photo-print-studio' ), 15, 0, 0 ),
			array( __( 'Onzichtbaar ophangsysteem + randafwerking zwart', 'photo-print-studio' ), 15, 0, 0 ),
			array( __( 'Aluminium frame wenge light', 'photo-print-studio' ), 22, 0, 15 ),
			array( __( 'Aluminium frame wenge dark', 'photo-print-studio' ), 22, 0, 15 ),
		);

		foreach ( $finishes as $index => $finish ) {
			list( $title, $per_lm, $per_m2, $fixed ) = $finish;
			self::maybe_create(
				PPS_CPT::FINISH,
				$title,
				'',
				array(
					'_pps_price_per_lm' => $per_lm,
					'_pps_price_per_m2' => $per_m2,
					'_pps_price_fixed'  => $fixed,
				),
				$index * 10
			);
		}
	}

	/**
	 * Creates the single hidden WooCommerce product every custom print is
	 * represented by in the cart. Its own price is irrelevant -- each cart
	 * item overrides it (see PPS_WooCommerce::apply_cart_item_price()).
	 *
	 * @return int|WP_Error
	 */
	public static function create_template_product() {
		$product_id = (int) get_option( PPS_WooCommerce::PRODUCT_ID_OPTION );
		if ( $product_id && 'product' === get_post_type( $product_id ) ) {
			return $product_id;
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'pps_no_woocommerce', __( 'WooCommerce is niet actief.', 'photo-print-studio' ) );
		}

		$product = new WC_Product_Simple();
		$product->set_name( __( 'Foto print op maat', 'photo-print-studio' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_price( 0 );
		$product->set_regular_price( 0 );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product_id = $product->save();

		update_option( PPS_WooCommerce::PRODUCT_ID_OPTION, $product_id );

		return $product_id;
	}
}
