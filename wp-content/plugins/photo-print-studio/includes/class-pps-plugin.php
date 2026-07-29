<?php
/**
 * Core plugin bootstrap: wires up all subsystems once WordPress (and
 * optionally WooCommerce) has loaded.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Plugin {

	/**
	 * @var PPS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes_setup();
		$this->hooks();
	}

	private function includes_setup() {
		PPS_CPT::instance();
		PPS_Settings::instance();

		if ( is_admin() ) {
			PPS_Admin::instance();
		}

		PPS_Rest::instance();
		PPS_Shortcode::instance();

		// WooCommerce is optional but required for checkout to function.
		if ( class_exists( 'WooCommerce' ) ) {
			PPS_WooCommerce::instance();
		}
	}

	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_woocommerce_notice' ) );
		add_action( 'pps_cleanup_tmp_uploads', array( 'PPS_Image', 'cleanup_stale_tmp_files' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'photo-print-studio', false, dirname( PPS_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * WooCommerce is not a hard dependency so the plugin can still be
	 * activated and configured (products, prices) before WooCommerce is
	 * installed, but checkout needs it -- warn the admin if it's missing.
	 */
	public function maybe_show_woocommerce_notice() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' .
			wp_kses_post( __( '<strong>Photo Print Studio</strong> vereist WooCommerce om bestellingen te kunnen afronden. Installeer en activeer WooCommerce om de bestelwizard volledig te laten werken.', 'photo-print-studio' ) ) .
			'</p></div>';
	}
}
