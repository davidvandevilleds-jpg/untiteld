<?php
/**
 * Admin-only UI: meta boxes for the catalogue post types (formats, papers,
 * mounts, finishes) and the top-level "Photo Print Studio" menu that groups
 * everything (catalogue + global settings) in one place.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Admin {

	/**
	 * @var PPS_Admin|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_pps_format_posts_columns', array( $this, 'format_columns' ) );
		add_action( 'manage_pps_format_posts_custom_column', array( $this, 'format_column_content' ), 10, 2 );
		add_filter( 'manage_pps_paper_posts_columns', array( $this, 'price_columns' ) );
		add_action( 'manage_pps_paper_posts_custom_column', array( $this, 'price_column_content' ), 10, 2 );
		add_filter( 'manage_pps_mount_posts_columns', array( $this, 'price_columns' ) );
		add_action( 'manage_pps_mount_posts_custom_column', array( $this, 'price_column_content' ), 10, 2 );
		add_filter( 'manage_pps_finish_posts_columns', array( $this, 'price_columns' ) );
		add_action( 'manage_pps_finish_posts_custom_column', array( $this, 'price_column_content' ), 10, 2 );
	}

	/**
	 * Top-level admin menu. The first submenu item WordPress creates
	 * automatically duplicates the parent, so we relabel it as "Dashboard"
	 * and use it for the settings screen (handled in PPS_Settings).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Photo Print Studio', 'photo-print-studio' ),
			__( 'Print Studio', 'photo-print-studio' ),
			'manage_options',
			PPS_CPT::MENU_SLUG,
			array( 'PPS_Settings', 'render_settings_page' ),
			'dashicons-images-alt2',
			56
		);

		add_submenu_page(
			PPS_CPT::MENU_SLUG,
			__( 'Instellingen', 'photo-print-studio' ),
			__( 'Instellingen', 'photo-print-studio' ),
			'manage_options',
			PPS_CPT::MENU_SLUG,
			array( 'PPS_Settings', 'render_settings_page' )
		);
	}

	/**
	 * Field definitions per post type. Keeping this in one place is what
	 * makes "add a product / change a price" trivial: everything renders
	 * and saves generically from this map.
	 *
	 * @return array
	 */
	public static function field_map() {
		return array(
			PPS_CPT::FORMAT => array(
				'title'  => __( 'Formaatgegevens', 'photo-print-studio' ),
				'fields' => array(
					'_pps_width_cm'  => array(
						'label' => __( 'Breedte (cm)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.1',
					),
					'_pps_height_cm' => array(
						'label' => __( 'Hoogte (cm)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.1',
					),
					'_pps_surcharge' => array(
						'label'       => __( 'Toeslag (€, optioneel)', 'photo-print-studio' ),
						'type'        => 'number',
						'step'        => '0.01',
						'description' => __( 'Vaste extra kost bovenop de berekende m²-prijs voor dit formaat. Laat op 0 voor gewone formaten.', 'photo-print-studio' ),
					),
				),
			),
			PPS_CPT::PAPER  => array(
				'title'  => __( 'Papiergegevens', 'photo-print-studio' ),
				'fields' => array(
					'_pps_price_per_m2' => array(
						'label' => __( 'Prijs per m² (€)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'_pps_weight_gsm'   => array(
						'label' => __( 'Gewicht (g/m², optioneel)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '1',
					),
				),
			),
			PPS_CPT::MOUNT  => array(
				'title'  => __( 'Montagegegevens', 'photo-print-studio' ),
				'fields' => array(
					'_pps_price_per_m2'  => array(
						'label' => __( 'Prijs per m² (€)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'_pps_requires_finish' => array(
						'label'       => __( 'Vraagt om een afwerkingskeuze', 'photo-print-studio' ),
						'type'        => 'checkbox',
						'description' => __( 'Vink aan voor montages zoals Dibond, waarbij de klant nadien een afwerking moet kiezen (bv. ophangsysteem of frame).', 'photo-print-studio' ),
					),
				),
			),
			PPS_CPT::FINISH => array(
				'title'  => __( 'Afwerkingsgegevens', 'photo-print-studio' ),
				'fields' => array(
					'_pps_price_per_lm' => array(
						'label'       => __( 'Prijs per lopende meter (€, optioneel)', 'photo-print-studio' ),
						'type'        => 'number',
						'step'        => '0.01',
						'description' => __( 'Voor randafwerkingen en frames: de kost wordt berekend als de omtrek van het formaat (2 × (breedte + hoogte)) in lopende meter, maal dit bedrag.', 'photo-print-studio' ),
					),
					'_pps_price_per_m2' => array(
						'label' => __( 'Prijs per m² (€, optioneel)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
					'_pps_price_fixed'  => array(
						'label' => __( 'Vaste prijs (€, optioneel)', 'photo-print-studio' ),
						'type'  => 'number',
						'step'  => '0.01',
					),
				),
			),
		);
	}

	public function add_meta_boxes() {
		foreach ( self::field_map() as $post_type => $config ) {
			add_meta_box(
				'pps_fields_' . $post_type,
				$config['title'],
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	public function render_meta_box( $post ) {
		$map = self::field_map();
		if ( empty( $map[ $post->post_type ] ) ) {
			return;
		}

		wp_nonce_field( 'pps_save_meta_' . $post->ID, 'pps_meta_nonce' );

		echo '<table class="form-table"><tbody>';
		foreach ( $map[ $post->post_type ]['fields'] as $key => $field ) {
			$value = get_post_meta( $post->ID, $key, true );
			echo '<tr><th style="width:220px;"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

			if ( 'checkbox' === $field['type'] ) {
				printf(
					'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $key ),
					checked( $value, '1', false ),
					esc_html__( 'Ja', 'photo-print-studio' )
				);
			} else {
				printf(
					'<input type="number" step="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $field['step'] ),
					esc_attr( $key ),
					esc_attr( $value )
				);
			}

			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( PPS_CPT::FORMAT === $post->post_type ) {
			echo '<p class="description">' . esc_html__( 'Tip: gebruik het veld "Volgorde" (in het Pagina-attributen-blok) om de weergavevolgorde van formaten te bepalen, bv. klein naar groot.', 'photo-print-studio' ) . '</p>';
		}
	}

	public function save_meta( $post_id, $post ) {
		$map = self::field_map();
		if ( empty( $map[ $post->post_type ] ) ) {
			return;
		}

		if ( ! isset( $_POST['pps_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['pps_meta_nonce'] ), 'pps_save_meta_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( $map[ $post->post_type ]['fields'] as $key => $field ) {
			if ( 'checkbox' === $field['type'] ) {
				update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? '1' : '0' );
				continue;
			}

			if ( isset( $_POST[ $key ] ) ) {
				$val = wp_unslash( $_POST[ $key ] );
				update_post_meta( $post_id, $key, is_numeric( $val ) ? floatval( $val ) : sanitize_text_field( $val ) );
			}
		}
	}

	public function format_columns( $columns ) {
		$columns['pps_dimensions'] = __( 'Afmetingen', 'photo-print-studio' );
		$columns['pps_surcharge']  = __( 'Toeslag', 'photo-print-studio' );
		return $columns;
	}

	public function format_column_content( $column, $post_id ) {
		if ( 'pps_dimensions' === $column ) {
			$w = get_post_meta( $post_id, '_pps_width_cm', true );
			$h = get_post_meta( $post_id, '_pps_height_cm', true );
			echo esc_html( $w && $h ? "{$w} x {$h} cm" : '—' );
		} elseif ( 'pps_surcharge' === $column ) {
			$s = get_post_meta( $post_id, '_pps_surcharge', true );
			echo esc_html( $s ? pps_format_price( $s ) : '—' );
		}
	}

	public function price_columns( $columns ) {
		$columns['pps_price'] = __( 'Prijs', 'photo-print-studio' );
		return $columns;
	}

	public function price_column_content( $column, $post_id ) {
		if ( 'pps_price' !== $column ) {
			return;
		}
		$per_lm = get_post_meta( $post_id, '_pps_price_per_lm', true );
		$per_m2 = get_post_meta( $post_id, '_pps_price_per_m2', true );
		$fixed  = get_post_meta( $post_id, '_pps_price_fixed', true );

		$parts = array();
		if ( $per_lm ) {
			$parts[] = pps_format_price( $per_lm ) . ' / lm';
		}
		if ( $per_m2 ) {
			$parts[] = pps_format_price( $per_m2 ) . ' / m²';
		}
		if ( $fixed ) {
			$parts[] = pps_format_price( $fixed ) . ' ' . __( '(vast)', 'photo-print-studio' );
		}
		echo esc_html( $parts ? implode( ' + ', $parts ) : '—' );
	}
}

if ( ! function_exists( 'pps_format_price' ) ) {
	/**
	 * Format a number as a currency string for the admin list columns.
	 * Deliberately doesn't use wc_price() -- it embeds the currency symbol
	 * as an HTML entity (e.g. "&euro;"), which shows up as literal
	 * "&euro;" text once re-escaped via esc_html(). PPS_Pricing builds the
	 * symbol as a plain character instead.
	 *
	 * @param float $amount
	 * @return string
	 */
	function pps_format_price( $amount ) {
		return PPS_Pricing::format_price( $amount );
	}
}
