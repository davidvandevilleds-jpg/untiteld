<?php
/**
 * Plugin Name:       Photo Print Studio
 * Plugin URI:        https://www.bunker.gallery
 * Description:       Stapsgewijze bestelwizard voor fotoprints: upload, formaat/DPI-controle, papierkeuze (Hahnemühle Digital FineArt Collection), montage op Dibond met afwerkingsopties, en bestellen via WooCommerce.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bunker Gallery
 * Text Domain:       photo-print-studio
 * Domain Path:       /languages
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'PPS_VERSION', '1.2.0' );
define( 'PPS_PLUGIN_FILE', __FILE__ );
define( 'PPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load all class files.
 */
function pps_load_includes() {
	$includes = array(
		'includes/class-pps-plugin.php',
		'includes/class-pps-cpt.php',
		'includes/class-pps-settings.php',
		'includes/class-pps-admin.php',
		'includes/class-pps-image.php',
		'includes/class-pps-pricing.php',
		'includes/class-pps-rest.php',
		'includes/class-pps-woocommerce.php',
		'includes/class-pps-shortcode.php',
		'includes/class-pps-install.php',
	);

	foreach ( $includes as $file ) {
		$path = PPS_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
pps_load_includes();

/**
 * Boot the plugin.
 */
function pps_boot() {
	return PPS_Plugin::instance();
}
add_action( 'plugins_loaded', 'pps_boot' );

/**
 * Activation: register CPTs first so rewrite rules flush correctly, seed
 * default data, and create the hidden WooCommerce template product.
 */
function pps_activate() {
	require_once PPS_PLUGIN_DIR . 'includes/class-pps-cpt.php';
	require_once PPS_PLUGIN_DIR . 'includes/class-pps-install.php';

	PPS_CPT::register_post_types();
	PPS_Install::activate();

	if ( ! wp_next_scheduled( 'pps_cleanup_tmp_uploads' ) ) {
		wp_schedule_event( time(), 'daily', 'pps_cleanup_tmp_uploads' );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pps_activate' );

/**
 * Deactivation: just flush rewrite rules. We never delete data on
 * deactivation -- only on uninstall (see uninstall.php), and only if the
 * shop owner opted in via settings.
 */
function pps_deactivate() {
	wp_clear_scheduled_hook( 'pps_cleanup_tmp_uploads' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pps_deactivate' );
