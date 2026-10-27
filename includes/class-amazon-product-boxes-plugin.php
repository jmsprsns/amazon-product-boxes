<?php
/**
 * Main plugin orchestrator: owns the settings, decides between API mode and
 * no-API mode, and boots each part.
 *
 * API mode needs Creators API credentials AND an Associates account that
 * Amazon currently lets use the API (10 qualifying sales in the last 30
 * days). Until both are true the plugin runs in no-API mode, and it checks
 * again every day, so it switches over by itself once the site qualifies
 * (and back again if the account stops qualifying).
 *
 * @package Amazon_Product_Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Amazon_Product_Boxes_Plugin {

	/**
	 * Option key holding the settings.
	 */
	const OPTION = 'amazon_product_boxes_settings';

	/**
	 * Option key holding the API connection state (not a setting).
	 */
	const STATUS_OPTION = 'amazon_product_boxes_status';

	/**
	 * Single shared instance.
	 *
	 * @var Amazon_Product_Boxes_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Amazon_Product_Boxes_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Amazon_Product_Boxes_Products::maybe_install();

		if ( is_admin() ) {
			new Amazon_Product_Boxes_Admin( $this );
		}

		Amazon_Product_Boxes_Render::init();
		Amazon_Product_Boxes_Rest::init();
		Amazon_Product_Boxes_Refresh::init();

		add_action( 'admin_init', array( $this, 'privacy_policy_text' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Amazon account.
			'marketplace'       => 'www.amazon.com',
			'tag'               => '',
			'mode'              => 'auto',
			'credential_id'     => '',
			'credential_secret' => '',
			'credential_ver'    => '3.1',
			'refresh_hours'     => 12,

			// Display.
			'layout'            => 'horizontal',
			'button_text'       => __( 'Buy on Amazon', 'amazon-product-boxes' ),
			'button_text_noapi' => __( 'Check price on Amazon', 'amazon-product-boxes' ),
			'button_color'      => '#ffa41c',
			'accent_color'      => '#232f3e',
			'features'          => 3,
			'show_prime'        => 1,
			'show_rating'       => 1,
			'show_savings'      => 1,
			'new_tab'           => 1,
			'unavailable'       => 'show',
			'disclosure'        => 'first',
			'disclosure_text'   => __( 'As an Amazon Associate I earn from qualifying purchases.', 'amazon-product-boxes' ),
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Change some settings, keeping the rest.
	 *
	 * @param array<string,mixed> $changes Setting => value (already clean).
	 * @return void
	 */
	public function update_settings( array $changes ) {
		$settings = array_intersect_key( array_merge( $this->get_settings(), $changes ), self::defaults() );

		update_option( self::OPTION, $settings );
	}

	/**
	 * The Creators API credential secret, decrypted.
	 *
	 * @return string '' when there's none (or the site's salts changed).
	 */
	public function credential_secret() {
		return Amazon_Product_Boxes_Crypto::decrypt( (string) $this->get_setting( 'credential_secret' ) );
	}

	/**
	 * Whether Creators API credentials and a Store ID are saved.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== (string) $this->get_setting( 'credential_id' )
			&& '' !== $this->credential_secret()
			&& '' !== (string) $this->get_setting( 'tag' );
	}

	/**
	 * The API connection state.
	 *
	 * State: none (no credentials), ok, not_eligible (fewer than 10 sales in
	 * 30 days), auth (credentials rejected), error (anything else, retried).
	 *
	 * @return array{state:string,message:string,checked:int,since:int,switched:int}
	 */
	public static function status() {
		$saved = get_option( self::STATUS_OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'state'    => 'none',
				'message'  => '',
				'checked'  => 0,
				'since'    => 0,
				'switched' => 0,
			)
		);
	}

	/**
	 * Record the result of talking to the API.
	 *
	 * @param string $state   none, ok, not_eligible, auth or error.
	 * @param string $message Amazon's message ('' when fine).
	 * @return void
	 */
	public static function set_status( $state, $message = '' ) {
		$old = self::status();
		$new = array(
			'state'    => $state,
			'message'  => substr( (string) $message, 0, 500 ),
			'checked'  => time(),
			'since'    => $state === $old['state'] ? (int) $old['since'] : time(),
			'switched' => (int) $old['switched'],
		);

		// Note the moment the site moved into API mode by itself, for the
		// one-time "switched to API mode" notice.
		if ( 'ok' === $state && 'ok' !== $old['state'] && 'none' !== $old['state'] ) {
			$new['switched'] = time();
		}

		update_option( self::STATUS_OPTION, $new, false );
	}

	/**
	 * Whether boxes use live API data right now.
	 *
	 * @return bool
	 */
	public function api_mode() {
		$status = self::status();

		return 'auto' === $this->get_setting( 'mode' ) && $this->has_credentials() && 'ok' === $status['state'];
	}

	/**
	 * The marketplace domain, e.g. www.amazon.com.
	 *
	 * @return string
	 */
	public function marketplace() {
		$marketplace = (string) $this->get_setting( 'marketplace' );

		return isset( self::marketplaces()[ $marketplace ] ) ? $marketplace : 'www.amazon.com';
	}

	/**
	 * Amazon stores the Creators API serves: domain => [label, region version].
	 *
	 * The region only picks the token endpoint suggested for new credentials;
	 * credentials work in every store the account is approved for.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function marketplaces() {
		return array(
			'www.amazon.com'    => array( __( 'United States (amazon.com)', 'amazon-product-boxes' ), '3.1' ),
			'www.amazon.ca'     => array( __( 'Canada (amazon.ca)', 'amazon-product-boxes' ), '3.1' ),
			'www.amazon.com.mx' => array( __( 'Mexico (amazon.com.mx)', 'amazon-product-boxes' ), '3.1' ),
			'www.amazon.com.br' => array( __( 'Brazil (amazon.com.br)', 'amazon-product-boxes' ), '3.1' ),
			'www.amazon.co.uk'  => array( __( 'United Kingdom (amazon.co.uk)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.de'     => array( __( 'Germany (amazon.de)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.fr'     => array( __( 'France (amazon.fr)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.it'     => array( __( 'Italy (amazon.it)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.es'     => array( __( 'Spain (amazon.es)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.nl'     => array( __( 'Netherlands (amazon.nl)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.com.be' => array( __( 'Belgium (amazon.com.be)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.ie'     => array( __( 'Ireland (amazon.ie)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.se'     => array( __( 'Sweden (amazon.se)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.pl'     => array( __( 'Poland (amazon.pl)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.com.tr' => array( __( 'Turkey (amazon.com.tr)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.ae'     => array( __( 'United Arab Emirates (amazon.ae)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.sa'     => array( __( 'Saudi Arabia (amazon.sa)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.eg'     => array( __( 'Egypt (amazon.eg)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.in'     => array( __( 'India (amazon.in)', 'amazon-product-boxes' ), '3.2' ),
			'www.amazon.co.jp'  => array( __( 'Japan (amazon.co.jp)', 'amazon-product-boxes' ), '3.3' ),
			'www.amazon.sg'     => array( __( 'Singapore (amazon.sg)', 'amazon-product-boxes' ), '3.3' ),
			'www.amazon.com.au' => array( __( 'Australia (amazon.com.au)', 'amazon-product-boxes' ), '3.3' ),
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 *
	 * @return void
	 */
	public function privacy_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'Some pages include Amazon product boxes. Their product images are loaded from Amazon\'s servers, so Amazon receives your IP address and browser details when you view them. The links are Amazon affiliate links: if you click one and buy something, Amazon tells us a purchase was made (not who made it) and pays us a commission. Amazon\'s own privacy notice applies on Amazon\'s site.', 'amazon-product-boxes' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Amazon Product Boxes', 'amazon-product-boxes' ), wp_kses_post( $text ) );
	}
}
