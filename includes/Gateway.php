<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;

class Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = Settings::GATEWAY_ID;
		$this->method_title = __( 'Mollie Terminal', 'mollie-terminal-for-woocommerce' );
		$this->method_description = __( 'Accept in-person payments using Mollie Terminal.', 'mollie-terminal-for-woocommerce' );
		$this->supports = array( 'products', 'refunds' );
		$this->init_settings();
		$this->init_form_fields();
		$this->title = $this->get_option( 'title', __( 'Mollie Terminal', 'mollie-terminal-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay in person using Mollie Terminal.', 'mollie-terminal-for-woocommerce' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ) );
	}

	public static function register_gateway( array $methods ): array { $methods[] = __CLASS__; return $methods; }

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable/Disable', 'mollie-terminal-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Enable Mollie Terminal for checkout/POS.', 'mollie-terminal-for-woocommerce' ), 'default' => 'no' ),
			'title' => array( 'title' => __( 'Title', 'mollie-terminal-for-woocommerce' ), 'type' => 'text', 'default' => __( 'Mollie Terminal', 'mollie-terminal-for-woocommerce' ) ),
			'description' => array( 'title' => __( 'Description', 'mollie-terminal-for-woocommerce' ), 'type' => 'textarea', 'default' => __( 'Pay in person using Mollie Terminal.', 'mollie-terminal-for-woocommerce' ) ),
			'mode' => array( 'title' => __( 'Mode', 'mollie-terminal-for-woocommerce' ), 'type' => 'select', 'default' => 'test', 'options' => array( 'test' => __( 'Test', 'mollie-terminal-for-woocommerce' ), 'live' => __( 'Live', 'mollie-terminal-for-woocommerce' ) ) ),
			'api_key' => array( 'title' => __( 'Mollie API Key', 'mollie-terminal-for-woocommerce' ), 'type' => 'password', 'default' => '' ),
			'profile_id' => array( 'title' => __( 'Mollie Profile ID', 'mollie-terminal-for-woocommerce' ), 'type' => 'text', 'default' => '' ),
			'default_terminal_id' => array( 'title' => __( 'Default Terminal ID', 'mollie-terminal-for-woocommerce' ), 'type' => 'text', 'default' => '' ),
		);
	}

	public function admin_options(): void {
		parent::admin_options();
		$settings = new Settings();
		$last_error = get_option( 'mtfwc_last_api_error', '' );
		$last_webhook = get_option( 'mtfwc_last_webhook_event', array() );
		echo '<h2>' . esc_html__( 'Mollie Terminal Diagnostics', 'mollie-terminal-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		$this->row( __( 'Active environment', 'mollie-terminal-for-woocommerce' ), $settings->mode() );
		$this->row( __( 'Profile ID', 'mollie-terminal-for-woocommerce' ), $settings->profile_id() );
		$this->row( __( 'Selected default terminal', 'mollie-terminal-for-woocommerce' ), $settings->default_terminal_id() );
		$this->row( __( 'Webhook URL', 'mollie-terminal-for-woocommerce' ), $settings->webhook_url() );
		$this->row( __( 'Pairing-code controls', 'mollie-terminal-for-woocommerce' ), 'live' === $settings->mode() ? __( 'Available', 'mollie-terminal-for-woocommerce' ) : __( 'Use Mollie test terminals in test mode.', 'mollie-terminal-for-woocommerce' ) );
		$this->row( __( 'Last API error', 'mollie-terminal-for-woocommerce' ), is_string( $last_error ) ? $last_error : '' );
		$this->row( __( 'Last webhook event', 'mollie-terminal-for-woocommerce' ), is_array( $last_webhook ) ? wp_json_encode( $last_webhook ) : '' );
		echo '</tbody></table>';
	}

	private function row( string $label, string $value ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>'; }
	public function enqueue_admin_scripts(): void { wp_enqueue_script( 'mtfwc-admin', MTFWC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), MTFWC_VERSION, true ); }
	public function enqueue_payment_scripts(): void { wp_enqueue_script( 'mtfwc-payment', MTFWC_PLUGIN_URL . 'assets/js/payment.js', array( 'jquery' ), MTFWC_VERSION, true ); wp_enqueue_style( 'mtfwc-payment', MTFWC_PLUGIN_URL . 'assets/css/payment.css', array(), MTFWC_VERSION ); }
	public function process_refund( $order_id, $amount = null, $reason = '' ) { $order = wc_get_order( $order_id ); if ( ! $order ) { return new \WP_Error( 'mtfwc_invalid_order', __( 'Invalid order.', 'mollie-terminal-for-woocommerce' ) ); } return ( new RefundHandler( new MollieApiClient( ( new Settings() )->api_key() ) ) )->process_refund( $order, $amount, $reason ); }
}
