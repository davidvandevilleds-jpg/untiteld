<?php
/**
 * WooCommerce integration: a single hidden "template" product represents
 * every custom print in the cart. Each cart item carries its own computed
 * price and selection data (format, paper, mount, finish, source photo),
 * which we display in cart/checkout/order screens and persist as order
 * item meta for fulfilment.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_WooCommerce {

	const PRODUCT_ID_OPTION = 'pps_template_product_id';
	const CART_ITEM_KEY     = 'pps_data';

	/**
	 * @var PPS_WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_WooCommerce
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_item_price' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_handling_fee' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'persist_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_admin_photo_link' ), 10, 3 );
		add_filter( 'woocommerce_email_recipient_new_order', array( __CLASS__, 'ensure_order_notification_recipient' ), 10, 3 );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'attach_order_photos' ), 10, 4 );
	}

	/**
	 * @return int|WP_Error Product ID of the (auto-created) template product.
	 */
	public static function get_template_product_id() {
		$product_id = (int) get_option( self::PRODUCT_ID_OPTION );

		if ( $product_id && 'product' === get_post_type( $product_id ) ) {
			return $product_id;
		}

		return PPS_Install::create_template_product();
	}

	/**
	 * Adds a configured print to the WooCommerce cart.
	 *
	 * @param array $cart_item_data Must contain a 'pps_data' array and a
	 *                              unique 'unique_key' so identical-looking
	 *                              prints don't merge into one line.
	 * @param float $price          Computed per-print (unit) price.
	 * @param int   $quantity       Number of copies of this print (default 1).
	 * @return string|WP_Error Cart item key on success.
	 */
	public static function add_to_cart( $cart_item_data, $price, $quantity = 1 ) {
		$product_id = self::get_template_product_id();
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$cart_item_data[ self::CART_ITEM_KEY ]['total'] = $price;

		// A REST API request doesn't always go through WooCommerce's normal
		// frontend bootstrap, so the cart/session may not exist yet -- this
		// is WooCommerce's own documented way of forcing it to load.
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, max( 1, absint( $quantity ) ), 0, array(), $cart_item_data );

		if ( ! $cart_item_key ) {
			return new WP_Error( 'pps_cart_error', __( 'Kon dit product niet aan de winkelmand toevoegen.', 'photo-print-studio' ) );
		}

		return $cart_item_key;
	}

	/**
	 * Overrides the template product's price with the per-item computed
	 * price for every custom-print cart line. WooCommerce multiplies this
	 * unit price by the line's quantity automatically.
	 *
	 * @param WC_Cart $cart
	 */
	public static function apply_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item[ self::CART_ITEM_KEY ]['total'] ) ) {
				$cart_item['data']->set_price( floatval( $cart_item[ self::CART_ITEM_KEY ]['total'] ) );
			}
		}
	}

	/**
	 * Adds the configured handling fee once per order (not per print, not
	 * multiplied by quantity) when the cart contains at least one custom
	 * print. Runs on every fee recalculation, which is the standard,
	 * supported way to add a single cart-level fee in WooCommerce.
	 *
	 * @param WC_Cart $cart
	 */
	public static function apply_handling_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$handling_fee = (float) PPS_Settings::get_value( 'handling_fee' );
		if ( $handling_fee <= 0 ) {
			return;
		}

		$has_print = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
				$has_print = true;
				break;
			}
		}

		if ( $has_print ) {
			$cart->add_fee( __( 'Behandelingskost', 'photo-print-studio' ), $handling_fee, false );
		}
	}

	/**
	 * Shows the selection summary under the product name in cart/checkout.
	 *
	 * @param array $item_data
	 * @param array $cart_item
	 * @return array
	 */
	public static function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			return $item_data;
		}

		foreach ( self::summary_fields( $cart_item[ self::CART_ITEM_KEY ] ) as $label => $value ) {
			$item_data[] = array(
				'name'  => $label,
				'value' => $value,
			);
		}

		return $item_data;
	}

	/**
	 * @param array $data The pps_data payload (from pricing calculation).
	 * @return array Label => value pairs for customer-facing display.
	 */
	private static function summary_fields( $data ) {
		$fields = array(
			__( 'Formaat', 'photo-print-studio' )   => sprintf( '%s (%s x %s cm)', $data['format_name'], $data['width_cm'], $data['height_cm'] ),
			__( 'Papier', 'photo-print-studio' )    => $data['paper_name'],
			__( 'Montage', 'photo-print-studio' )   => $data['mount_name'],
		);

		if ( ! empty( $data['finish_name'] ) ) {
			$fields[ __( 'Afwerking', 'photo-print-studio' ) ] = $data['finish_name'];
		}

		return $fields;
	}

	/**
	 * Persists the full selection onto the order line item so shop staff
	 * can see everything (and re-derive pricing) after checkout.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 */
	public static function persist_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_ITEM_KEY ] ) ) {
			return;
		}

		$data = $values[ self::CART_ITEM_KEY ];

		foreach ( self::summary_fields( $data ) as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}

		$item->add_meta_data( '_pps_attachment_id', $data['attachment_id'], true );
		$item->add_meta_data( '_pps_crop', wp_json_encode( isset( $data['crop'] ) ? $data['crop'] : array() ), true );
		$item->add_meta_data( '_pps_breakdown', wp_json_encode( $data ), true );
	}

	/**
	 * In wp-admin's order edit screen, adds a direct download link to the
	 * customer's original uploaded photo plus the crop info, right under
	 * that line item's meta table.
	 *
	 * @param int                    $item_id
	 * @param WC_Order_Item_Product  $item
	 * @param WC_Product|false       $product
	 */
	public static function render_admin_photo_link( $item_id, $item, $product ) {
		if ( ! is_admin() || ! ( $item instanceof WC_Order_Item_Product ) ) {
			return;
		}

		$attachment_id = $item->get_meta( '_pps_attachment_id', true );
		if ( ! $attachment_id ) {
			return;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return;
		}

		printf(
			'<p class="pps-admin-photo-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_html__( 'Originele foto:', 'photo-print-studio' ),
			esc_url( $url ),
			esc_html__( 'Downloaden', 'photo-print-studio' )
		);

		$crop = json_decode( (string) $item->get_meta( '_pps_crop', true ), true );
		if ( empty( $crop ) || empty( $crop['sw'] ) ) {
			return;
		}

		$rotation = ! empty( $crop['rotation'] ) ? (int) $crop['rotation'] : 0;

		printf(
			'<p class="pps-admin-crop"><strong>%s</strong> %s: %d, %s: %d, %s: %d × %d px</p>',
			esc_html__( 'Uitsnede:', 'photo-print-studio' ),
			esc_html__( 'x', 'photo-print-studio' ),
			round( $crop['sx'] ),
			esc_html__( 'y', 'photo-print-studio' ),
			round( $crop['sy'] ),
			esc_html__( 'grootte', 'photo-print-studio' ),
			round( $crop['sw'] ),
			round( $crop['sh'] )
		);

		if ( $rotation ) {
			printf(
				'<p class="pps-admin-crop"><strong>%s</strong> %d° (%s)</p>',
				esc_html__( 'Rotatie:', 'photo-print-studio' ),
				$rotation,
				esc_html__( 'toepassen vóór uitsnede', 'photo-print-studio' )
			);
		}
	}

	/**
	 * Makes sure the configured order-notification address (Print Studio ->
	 * Instellingen, default order@bunker.gallery) always receives the "New
	 * order" admin email, in addition to whatever WooCommerce's own email
	 * settings already specify.
	 *
	 * @param string   $recipient
	 * @param WC_Order $order
	 * @return string
	 */
	public static function ensure_order_notification_recipient( $recipient, $order ) {
		$configured = sanitize_email( (string) PPS_Settings::get_value( 'order_notification_email' ) );
		if ( ! $configured || ! is_email( $configured ) ) {
			return $recipient;
		}

		$recipients = array_filter( array_map( 'trim', explode( ',', (string) $recipient ) ) );
		if ( ! in_array( $configured, $recipients, true ) ) {
			$recipients[] = $configured;
		}

		return implode( ', ', $recipients );
	}

	/**
	 * Attaches every ordered photo's original, full-resolution file to the
	 * admin "New order" email, so the print shop has everything (info +
	 * photo) in one place without needing to log into wp-admin first.
	 *
	 * Large files (beyond pps_max_email_attachment_bytes, 15 MB by default)
	 * are skipped to avoid mail servers rejecting oversized messages -- the
	 * order screen in wp-admin always has a direct download link regardless.
	 *
	 * @param string[] $attachments
	 * @param string   $email_id
	 * @param mixed    $object
	 * @return string[]
	 */
	public static function attach_order_photos( $attachments, $email_id, $object ) {
		if ( 'new_order' !== $email_id || ! ( $object instanceof WC_Order ) ) {
			return $attachments;
		}

		$max_bytes = (int) apply_filters( 'pps_max_email_attachment_bytes', 15 * MB_IN_BYTES );

		foreach ( $object->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$attachment_id = $item->get_meta( '_pps_attachment_id', true );
			if ( ! $attachment_id ) {
				continue;
			}

			$path = get_attached_file( $attachment_id );
			if ( $path && file_exists( $path ) && filesize( $path ) <= $max_bytes ) {
				$attachments[] = $path;
			}
		}

		return $attachments;
	}
}
