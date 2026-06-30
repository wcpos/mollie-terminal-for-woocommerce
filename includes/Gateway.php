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
		$last_error = Diagnostics::last_api_error();
		$last_webhook = get_option( 'mtfwc_last_webhook_event', array() );
		$recent_events = Diagnostics::recent_events();
		echo '<h2>' . esc_html__( 'Mollie Terminal Diagnostics', 'mollie-terminal-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		$this->row( __( 'Active environment', 'mollie-terminal-for-woocommerce' ), $settings->mode() );
		$this->row( __( 'Profile ID', 'mollie-terminal-for-woocommerce' ), $settings->profile_id() );
		$this->row( __( 'Selected default terminal', 'mollie-terminal-for-woocommerce' ), $settings->default_terminal_id() );
		$this->row( __( 'Webhook URL', 'mollie-terminal-for-woocommerce' ), $settings->webhook_url() );
		$this->row( __( 'Pairing-code controls', 'mollie-terminal-for-woocommerce' ), 'live' === $settings->mode() ? __( 'Available', 'mollie-terminal-for-woocommerce' ) : __( 'Use Mollie test terminals in test mode.', 'mollie-terminal-for-woocommerce' ) );
		$this->row( __( 'Last API error', 'mollie-terminal-for-woocommerce' ), is_string( $last_error ) ? $last_error : '' );
		$this->row( __( 'Last webhook event', 'mollie-terminal-for-woocommerce' ), is_array( $last_webhook ) ? wp_json_encode( $last_webhook ) : '' );
		$this->row( __( 'Recent diagnostic events', 'mollie-terminal-for-woocommerce' ), empty( $recent_events ) ? '[]' : wp_json_encode( array_slice( $recent_events, -10 ) ) );
		echo '</tbody></table>';
	}

	public function payment_fields(): void {
		global $wp;

		$description = apply_filters( 'woocommerce_gateway_description', $this->get_option( 'description' ), $this->id );
		if ( $description ) {
			echo '<p>' . wp_kses_post( $description ) . '</p>';
		}

		$settings = new Settings();
		$order_id = 0;
		$order_token = '';
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			if ( $order_id && wc_get_order( $order_id ) ) {
				$order_token = AjaxHandler::order_token( $order_id );
			}
		}

		echo '<div id="mtfwc-payment-interface" class="mtfwc-payment-interface" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '" data-default-terminal-id="' . esc_attr( $settings->default_terminal_id() ) . '">';
		echo '<div class="mtfwc-payment-card">';
		echo '<h4>' . esc_html__( 'Mollie Terminal', 'mollie-terminal-for-woocommerce' ) . '</h4>';
		if ( $order_id ) {
			echo '<p class="mtfwc-payment-help">' . esc_html__( 'Send this order to the configured Mollie terminal and copy the logs if support needs to diagnose the payment.', 'mollie-terminal-for-woocommerce' ) . '</p>';
			echo '<div class="mtfwc-payment-actions">';
			echo '<button type="button" class="button button-primary mtfwc-start-payment" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '">' . esc_html__( 'Start Terminal Payment', 'mollie-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button mtfwc-poll-payment" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '">' . esc_html__( 'Check Status', 'mollie-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button mtfwc-cancel-payment" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '">' . esc_html__( 'Cancel Payment', 'mollie-terminal-for-woocommerce' ) . '</button>';
			echo '</div>';
			echo '<div class="mtfwc-payment-status" role="status" aria-live="polite"></div>';
		} else {
			echo '<p class="mtfwc-payment-help">' . esc_html__( 'Payment activity logs will appear here during checkout. If payment creation fails, copy these logs for support.', 'mollie-terminal-for-woocommerce' ) . '</p>';
		}
		echo '</div>';

		echo '<div class="mtfwc-logging-section">';
		echo '<div class="mtfwc-logging-header">';
		echo '<h4>' . esc_html__( 'Logs', 'mollie-terminal-for-woocommerce' ) . '</h4>';
		echo '<div class="mtfwc-logging-actions">';
		echo '<button type="button" class="button mtfwc-toggle-log" data-expanded="false">' . esc_html__( 'Show logs', 'mollie-terminal-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="button mtfwc-copy-log">' . esc_html__( 'Copy', 'mollie-terminal-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="button mtfwc-clear-log">' . esc_html__( 'Clear', 'mollie-terminal-for-woocommerce' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '<div class="mtfwc-log-content" style="display: none;">';
		echo '<textarea class="mtfwc-payment-log-textarea" readonly placeholder="' . esc_attr__( 'Mollie Terminal payment activity will appear here...', 'mollie-terminal-for-woocommerce' ) . '"></textarea>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Mollie Terminal integration.', 'mollie-terminal-for-woocommerce' ) . '</noscript>';
	}

	private function row( string $label, string $value ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>'; }
	public function enqueue_admin_scripts(): void { wp_enqueue_script( 'mtfwc-admin', MTFWC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), MTFWC_VERSION, true ); }
	public function enqueue_payment_scripts(): void {
		wp_enqueue_script( 'mtfwc-payment', MTFWC_PLUGIN_URL . 'assets/js/payment.js', array(), MTFWC_VERSION, true );
		wp_enqueue_style( 'mtfwc-payment', MTFWC_PLUGIN_URL . 'assets/css/payment.css', array(), MTFWC_VERSION );
		wp_localize_script(
			'mtfwc-payment',
			'mtfwcPaymentData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'defaultTerminalId' => ( new Settings() )->default_terminal_id(),
				'i18n' => array(
					'logsShown' => __( 'Hide logs', 'mollie-terminal-for-woocommerce' ),
					'logsHidden' => __( 'Show logs', 'mollie-terminal-for-woocommerce' ),
					'copied' => __( 'Logs copied to clipboard.', 'mollie-terminal-for-woocommerce' ),
					'copyFailed' => __( 'Unable to copy logs automatically.', 'mollie-terminal-for-woocommerce' ),
				),
			)
		);
	}
	public function process_refund( $order_id, $amount = null, $reason = '' ) { $order = wc_get_order( $order_id ); if ( ! $order ) { return new \WP_Error( 'mtfwc_invalid_order', __( 'Invalid order.', 'mollie-terminal-for-woocommerce' ) ); } return ( new RefundHandler( new MollieApiClient( ( new Settings() )->api_key() ) ) )->process_refund( $order, $amount, $reason ); }
}
