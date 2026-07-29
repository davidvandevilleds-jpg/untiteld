<?php
/**
 * Registers the [photo_print_wizard] shortcode and loads its assets only on
 * pages that actually use it.
 *
 * @package PhotoPrintStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPS_Shortcode {

	const TAG = 'photo_print_wizard';

	/**
	 * @var PPS_Shortcode|null
	 */
	private static $instance = null;

	/**
	 * @return PPS_Shortcode
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, self::TAG ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	private function enqueue_assets() {
		wp_enqueue_style(
			'pps-wizard',
			PPS_PLUGIN_URL . 'assets/css/wizard.css',
			array(),
			self::asset_version( 'assets/css/wizard.css' )
		);

		wp_enqueue_script(
			'pps-wizard',
			PPS_PLUGIN_URL . 'assets/js/wizard.js',
			array(),
			self::asset_version( 'assets/js/wizard.js' ),
			true
		);

		wp_localize_script(
			'pps-wizard',
			'PPS_CONFIG',
			array(
				'restUrl'          => esc_url_raw( rest_url( PPS_Rest::NAMESPACE_V1 ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'hasWooCommerce'   => class_exists( 'WooCommerce' ),
				'i18n'             => $this->i18n_strings(),
			)
		);
	}

	/**
	 * The file's own last-modified time as a cache-busting version string.
	 * This changes automatically whenever wizard.js/wizard.css are edited,
	 * so browsers/CDNs/page-cache plugins never keep serving a stale copy
	 * after an update (unlike a hand-maintained version constant, which is
	 * easy to forget to bump).
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$path = PPS_PLUGIN_DIR . $relative_path;
		return file_exists( $path ) ? (string) filemtime( $path ) : PPS_VERSION;
	}

	/**
	 * Strings used by the JS wizard, kept here so translators only need to
	 * deal with PHP .pot files, not JS source.
	 *
	 * @return array
	 */
	private function i18n_strings() {
		return array(
			'stepUpload'        => __( 'Foto uploaden', 'photo-print-studio' ),
			'stepCheck'         => __( 'Controle', 'photo-print-studio' ),
			'stepSize'          => __( 'Formaat', 'photo-print-studio' ),
			'stepMount'         => __( 'Montage', 'photo-print-studio' ),
			'stepPaper'         => __( 'Papier', 'photo-print-studio' ),
			'stepFinish'        => __( 'Afwerking', 'photo-print-studio' ),
			'stepSummary'       => __( 'Overzicht & bestellen', 'photo-print-studio' ),
			'dropText'          => __( 'Sleep uw foto\'s hierheen, of klik om te kiezen (tot 10 foto\'s)', 'photo-print-studio' ),
			'uploading'         => __( 'Bezig met uploaden…', 'photo-print-studio' ),
			'uploadError'       => __( 'Uploaden mislukt. Probeer een andere foto.', 'photo-print-studio' ),
			'photosHeading'     => __( 'Geüploade foto\'s', 'photo-print-studio' ),
			'quantityLabel'     => __( 'Aantal', 'photo-print-studio' ),
			'removePhoto'       => __( 'Verwijderen', 'photo-print-studio' ),
			'maxPhotosReached'  => __( 'Je kan maximaal 10 foto\'s per bestelling toevoegen.', 'photo-print-studio' ),
			'attentionBadge'    => __( 'Aandacht nodig', 'photo-print-studio' ),
			'sizeIntro'         => __( 'Kies voor elke foto een formaat. Papier, montage en afwerking kiest u nadien één keer voor de hele bestelling.', 'photo-print-studio' ),
			'noFormatChosen'    => __( 'Nog geen formaat gekozen', 'photo-print-studio' ),
			'chooseFormat'      => __( 'Formaat kiezen', 'photo-print-studio' ),
			'changeFormat'      => __( 'Formaat wijzigen', 'photo-print-studio' ),
			'subtotalLabel'     => __( 'Subtotaal', 'photo-print-studio' ),
			'handlingFeeLabel'  => __( 'Behandelingskost', 'photo-print-studio' ),
			'dpiWarningTitle'   => __( 'Let op: beperkte beeldkwaliteit', 'photo-print-studio' ),
			'dpiWarningBody'    => __( 'Op dit formaat wordt uw foto uitgerekt. Bekijk het voorbeeld hieronder -- de afdruk kan er zachter of korreliger uitzien dan het origineel.', 'photo-print-studio' ),
			'cropTitle'         => __( 'Pas uw uitsnede aan', 'photo-print-studio' ),
			'cropBody'          => __( 'Sleep het kader over uw foto om te kiezen wat er wordt afgedrukt. Gebruik de schuifregelaar om in of uit te zoomen, of draai de foto 90°.', 'photo-print-studio' ),
			'rotate'            => __( '90° draaien', 'photo-print-studio' ),
			'adjustCrop'        => __( 'Uitsnede zelf aanpassen', 'photo-print-studio' ),
			'customSize'        => __( 'Aangepast formaat', 'photo-print-studio' ),
			'widthLabel'        => __( 'Breedte (cm)', 'photo-print-studio' ),
			'heightLabel'       => __( 'Hoogte (cm)', 'photo-print-studio' ),
			'continue'          => __( 'Verder', 'photo-print-studio' ),
			'back'              => __( 'Terug', 'photo-print-studio' ),
			'addToCart'         => __( 'Bestelling afronden', 'photo-print-studio' ),
			'adding'            => __( 'Bezig…', 'photo-print-studio' ),
			'total'             => __( 'Totaalprijs', 'photo-print-studio' ),
			'noWooCommerce'     => __( 'Bestellen is momenteel niet beschikbaar. Neem contact met ons op.', 'photo-print-studio' ),
			'genericError'      => __( 'Er ging iets mis. Probeer het opnieuw.', 'photo-print-studio' ),
			'sizeTooLarge'      => __( 'Dit formaat overschrijdt de maximale afmetingen.', 'photo-print-studio' ),
		);
	}

	/**
	 * @return string
	 */
	public function render() {
		ob_start();
		?>
		<div id="pps-wizard-root" class="pps-wizard" data-pps-wizard>
			<noscript><p><?php esc_html_e( 'Schakel JavaScript in om een foto te bestellen.', 'photo-print-studio' ); ?></p></noscript>
		</div>
		<?php
		return ob_get_clean();
	}
}
