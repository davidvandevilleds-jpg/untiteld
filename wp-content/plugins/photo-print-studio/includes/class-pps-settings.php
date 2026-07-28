<?php
/**
 * Global wizard settings: DPI threshold, maximum custom print size and the
 * optional handling fee. Stored as a single option so it's one query.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Settings {

	const OPTION_KEY = 'pps_settings';

	/**
	 * @var PPS_Settings|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Defaults, used both as the fallback option value and to fill any
	 * settings missing after an upgrade.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'dpi_threshold'            => 200,
			'max_width_cm'             => 110,
			'max_height_cm'            => 1200,
			'min_size_cm'              => 10,
			'handling_fee'             => 0,
			'custom_size_step'         => 1,
			'order_notification_email' => 'order@bunker.gallery',
		);
	}

	/**
	 * @return array Current settings merged over the defaults.
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function get_value( $key ) {
		$settings = self::get();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function register_settings() {
		register_setting(
			'pps_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			if ( 'order_notification_email' === $key ) {
				$email         = isset( $input[ $key ] ) ? sanitize_email( $input[ $key ] ) : '';
				$clean[ $key ] = is_email( $email ) ? $email : $default;
				continue;
			}

			$clean[ $key ] = isset( $input[ $key ] ) && is_numeric( $input[ $key ] )
				? floatval( $input[ $key ] )
				: $default;
		}

		return $clean;
	}

	/**
	 * Renders both the settings form and a quick links panel to the four
	 * catalogue post types, so "Photo Print Studio" in wp-admin is a single
	 * one-stop screen for managing products and prices.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Photo Print Studio', 'photo-print-studio' ); ?></h1>
			<p><?php esc_html_e( 'Beheer hier de wizard-instellingen. Formaten, papieren, montages en afwerkingen (en hun prijzen) beheer je via de menu-items hieronder.', 'photo-print-studio' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Catalogus', 'photo-print-studio' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PPS_CPT::FORMAT ) ); ?>"><?php esc_html_e( 'Formaten beheren', 'photo-print-studio' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PPS_CPT::PAPER ) ); ?>"><?php esc_html_e( 'Papieren beheren', 'photo-print-studio' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PPS_CPT::MOUNT ) ); ?>"><?php esc_html_e( 'Montages beheren', 'photo-print-studio' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PPS_CPT::FINISH ) ); ?>"><?php esc_html_e( 'Afwerkingen beheren', 'photo-print-studio' ); ?></a>
			</p>

			<hr />

			<h2 class="title"><?php esc_html_e( 'Wizard-instellingen', 'photo-print-studio' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'pps_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pps_dpi_threshold"><?php esc_html_e( 'Minimale DPI', 'photo-print-studio' ); ?></label></th>
						<td>
							<input type="number" step="1" min="1" id="pps_dpi_threshold" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dpi_threshold]" value="<?php echo esc_attr( $settings['dpi_threshold'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Onder deze effectieve resolutie (pixels per inch op het gekozen formaat) tonen we een kwaliteitswaarschuwing bij het crop-voorbeeld.', 'photo-print-studio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_max_width_cm"><?php esc_html_e( 'Maximale breedte aangepast formaat (cm)', 'photo-print-studio' ); ?></label></th>
						<td><input type="number" step="0.1" min="1" id="pps_max_width_cm" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_width_cm]" value="<?php echo esc_attr( $settings['max_width_cm'] ); ?>" class="small-text" /> cm</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_max_height_cm"><?php esc_html_e( 'Maximale lengte aangepast formaat (cm)', 'photo-print-studio' ); ?></label></th>
						<td><input type="number" step="0.1" min="1" id="pps_max_height_cm" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_height_cm]" value="<?php echo esc_attr( $settings['max_height_cm'] ); ?>" class="small-text" /> cm</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_min_size_cm"><?php esc_html_e( 'Minimale zijde aangepast formaat (cm)', 'photo-print-studio' ); ?></label></th>
						<td><input type="number" step="0.1" min="1" id="pps_min_size_cm" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_size_cm]" value="<?php echo esc_attr( $settings['min_size_cm'] ); ?>" class="small-text" /> cm</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_handling_fee"><?php esc_html_e( 'Vaste behandelingskost per bestelling (€)', 'photo-print-studio' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="pps_handling_fee" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[handling_fee]" value="<?php echo esc_attr( $settings['handling_fee'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_custom_size_step"><?php esc_html_e( 'Stapgrootte aangepast formaat (cm)', 'photo-print-studio' ); ?></label></th>
						<td><input type="number" step="0.1" min="0.1" id="pps_custom_size_step" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_size_step]" value="<?php echo esc_attr( $settings['custom_size_step'] ); ?>" class="small-text" /> cm</td>
					</tr>
					<tr>
						<th scope="row"><label for="pps_order_notification_email"><?php esc_html_e( 'E-mailadres voor nieuwe bestellingen', 'photo-print-studio' ); ?></label></th>
						<td>
							<input type="email" id="pps_order_notification_email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[order_notification_email]" value="<?php echo esc_attr( $settings['order_notification_email'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Ontvangt bij elke nieuwe bestelling een e-mail met alle keuzes (formaat, papier, montage, afwerking) en de originele foto als bijlage.', 'photo-print-studio' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Instellingen opslaan', 'photo-print-studio' ) ); ?>
			</form>

			<hr />
			<h2 class="title"><?php esc_html_e( 'Wizard plaatsen', 'photo-print-studio' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Plaats de shortcode %s op een pagina om de bestelwizard te tonen.', 'photo-print-studio' ),
					'<code>[photo_print_wizard]</code>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
