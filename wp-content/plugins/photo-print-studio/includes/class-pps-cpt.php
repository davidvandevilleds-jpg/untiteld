<?php
/**
 * Registers the four product-catalogue post types that make the wizard's
 * options editable from wp-admin without touching code:
 *
 *  - pps_format : standard print sizes (10x15 ... A0) + their dimensions.
 *  - pps_paper  : Hahnemühle Digital FineArt Collection papers.
 *  - pps_mount  : "losse print" / "kleven op Dibond" / future mount types.
 *  - pps_finish : Dibond finishing options (hanging system, frame, ...).
 *
 * Each is a simple CPT with a couple of price/number meta fields, so a shop
 * editor can add a new paper or change a price the same way they'd edit a
 * page -- no code changes required.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_CPT {

	const FORMAT = 'pps_format';
	const PAPER  = 'pps_paper';
	const MOUNT  = 'pps_mount';
	const FINISH = 'pps_finish';

	const MENU_SLUG = 'photo-print-studio';

	/**
	 * @var PPS_CPT|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_CPT
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	/**
	 * Register all four catalogue post types. Also called directly on
	 * plugin activation so rewrite rules are correct on first load.
	 */
	public static function register_post_types() {
		self::register_format();
		self::register_paper();
		self::register_mount();
		self::register_finish();
	}

	private static function common_args( $singular, $plural, $menu_icon ) {
		return array(
			'labels'              => array(
				'name'               => $plural,
				'singular_name'      => $singular,
				'add_new'            => sprintf( __( 'Nieuw: %s', 'photo-print-studio' ), $singular ),
				'add_new_item'       => sprintf( __( 'Nieuw item toevoegen: %s', 'photo-print-studio' ), $singular ),
				'edit_item'          => sprintf( __( '%s bewerken', 'photo-print-studio' ), $singular ),
				'new_item'           => sprintf( __( 'Nieuw(e) %s', 'photo-print-studio' ), $singular ),
				'view_item'          => sprintf( __( '%s bekijken', 'photo-print-studio' ), $singular ),
				'search_items'       => sprintf( __( '%s zoeken', 'photo-print-studio' ), $plural ),
				'not_found'          => __( 'Niets gevonden.', 'photo-print-studio' ),
				'not_found_in_trash' => __( 'Niets gevonden in prullenbak.', 'photo-print-studio' ),
				'all_items'          => $plural,
				'menu_name'          => $plural,
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => self::MENU_SLUG,
			'show_in_rest'        => false,
			'menu_icon'           => $menu_icon,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
			'has_archive'         => false,
			'exclude_from_search' => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);
	}

	private static function register_format() {
		register_post_type(
			self::FORMAT,
			self::common_args( __( 'Formaat', 'photo-print-studio' ), __( 'Formaten', 'photo-print-studio' ), 'dashicons-fullscreen-alt' )
		);
	}

	private static function register_paper() {
		register_post_type(
			self::PAPER,
			self::common_args( __( 'Papier', 'photo-print-studio' ), __( 'Papieren (Hahnemühle)', 'photo-print-studio' ), 'dashicons-media-document' )
		);
	}

	private static function register_mount() {
		register_post_type(
			self::MOUNT,
			self::common_args( __( 'Montage', 'photo-print-studio' ), __( 'Montage-opties', 'photo-print-studio' ), 'dashicons-layout' )
		);
	}

	private static function register_finish() {
		register_post_type(
			self::FINISH,
			self::common_args( __( 'Afwerking', 'photo-print-studio' ), __( 'Afwerkingen (Dibond)', 'photo-print-studio' ), 'dashicons-admin-appearance' )
		);
	}

	/**
	 * Helper: published items of a catalogue post type, ordered by
	 * menu_order (drag-and-drop order in the admin list, if supported)
	 * then title.
	 *
	 * @param string $post_type One of the class constants above.
	 * @return WP_Post[]
	 */
	public static function get_items( $post_type ) {
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	}
}
