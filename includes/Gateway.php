<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
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
			'api_key' => array( 'title' => __( 'Mollie API Key', 'mollie-terminal-for-woocommerce' ), 'type' => 'password', 'default' => '', 'description' => __( 'Use your live API key. Mollie terminals are only available on live accounts; the test API key cannot drive a physical terminal.', 'mollie-terminal-for-woocommerce' ) ),
			'default_terminal_id' => $this->default_terminal_field(),
		);
	}

	/**
	 * Build the "Default terminal" field. When an API key is present and we are
	 * on this gateway's settings screen, the terminals are fetched live from
	 * Mollie so the merchant picks from a dropdown instead of pasting an ID.
	 * Falls back to a plain text field when the list cannot be loaded.
	 */
	private function default_terminal_field(): array {
		$base = array(
			'title'       => __( 'Default terminal', 'mollie-terminal-for-woocommerce' ),
			'description' => __( 'Terminal used by default at checkout. Cashiers can still pick another terminal per order. Fetched live from your Mollie account.', 'mollie-terminal-for-woocommerce' ),
			'desc_tip'    => true,
			'default'     => '',
		);
		$options = $this->fetch_terminal_options();
		if ( null === $options ) {
			return array_merge( $base, array( 'type' => 'text' ) );
		}
		return array_merge( $base, array( 'type' => 'select', 'options' => $options ) );
	}

	/**
	 * Fetch terminals as id => label options for the settings dropdown.
	 * Returns null (caller falls back to a text field) when we should not or
	 * cannot fetch: not on this settings screen, no API key, or an API error.
	 */
	private function fetch_terminal_options(): ?array {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) { return null; }
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return null; }
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Settings::GATEWAY_ID !== $section ) { return null; }
		$settings = new Settings();
		if ( '' === $settings->api_key() ) { return null; }
		try {
			$terminals = ( new TerminalService( new MollieApiClient( $settings->api_key() ), $settings ) )->list_terminals();
		} catch ( \Exception $e ) {
			return null;
		}
		$options = array( '' => __( '— Select a terminal —', 'mollie-terminal-for-woocommerce' ) );
		foreach ( AjaxHandler::normalize_terminals( $terminals ) as $terminal ) {
			$options[ $terminal['id'] ] = sprintf( '%s (%s)', $terminal['label'], $terminal['id'] );
		}
		return $options;
	}

	public function admin_options(): void {
		parent::admin_options();
		$settings = new Settings();
		$last_error = Diagnostics::last_api_error();
		$last_webhook = get_option( 'mtfwc_last_webhook_event', array() );
		$recent_events = Diagnostics::recent_events();
		echo '<h2>' . esc_html__( 'Mollie Terminal Diagnostics', 'mollie-terminal-for-woocommerce' ) . '</h2>';
		if ( 'live' !== $settings->mode() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Test mode is selected. Mollie terminals exist only on live accounts, so the test API key cannot drive a physical terminal. Switch to Live mode with your live API key to take terminal payments.', 'mollie-terminal-for-woocommerce' ) . '</p></div>';
		}
		echo '<table class="form-table"><tbody>';
		$this->row( __( 'Active environment', 'mollie-terminal-for-woocommerce' ), $settings->mode() );
		$this->row( __( 'Selected default terminal', 'mollie-terminal-for-woocommerce' ), $settings->default_terminal_id() );
		$this->row( __( 'Webhook URL (sent automatically on every payment)', 'mollie-terminal-for-woocommerce' ), $settings->webhook_url() );
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
			echo '<p class="mtfwc-payment-help">' . esc_html__( 'Send this order to a Mollie terminal. The payment completes automatically once the terminal confirms.', 'mollie-terminal-for-woocommerce' ) . '</p>';
			echo '<div class="mtfwc-terminal-field">';
			echo '<label class="mtfwc-terminal-label" for="mtfwc-terminal-select">' . esc_html__( 'Terminal', 'mollie-terminal-for-woocommerce' ) . '</label>';
			echo '<select id="mtfwc-terminal-select" class="mtfwc-terminal-select" disabled aria-busy="true">';
			$default_terminal = $settings->default_terminal_id();
			if ( '' !== $default_terminal ) {
				echo '<option value="' . esc_attr( $default_terminal ) . '" selected>' . esc_html( $default_terminal ) . '</option>';
			} else {
				echo '<option value="">' . esc_html__( 'Loading terminals…', 'mollie-terminal-for-woocommerce' ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
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
				'pollIntervalMs' => (int) apply_filters( 'mtfwc_poll_interval_ms', 2000 ),
				'pollTimeoutMs' => (int) apply_filters( 'mtfwc_poll_timeout_ms', 300000 ),
				'i18n' => array(
					'logsShown' => __( 'Hide logs', 'mollie-terminal-for-woocommerce' ),
					'logsHidden' => __( 'Show logs', 'mollie-terminal-for-woocommerce' ),
					'copied' => __( 'Logs copied to clipboard.', 'mollie-terminal-for-woocommerce' ),
					'copyFailed' => __( 'Unable to copy logs automatically.', 'mollie-terminal-for-woocommerce' ),
					'sending' => __( 'Sending to terminal…', 'mollie-terminal-for-woocommerce' ),
					'waiting' => __( 'Waiting for terminal…', 'mollie-terminal-for-woocommerce' ),
					'completing' => __( 'Payment complete — finishing order…', 'mollie-terminal-for-woocommerce' ),
					'selectTerminal' => __( 'Select a terminal first.', 'mollie-terminal-for-woocommerce' ),
					'failed' => __( 'Payment failed. You can try again.', 'mollie-terminal-for-woocommerce' ),
					'canceled' => __( 'Payment canceled.', 'mollie-terminal-for-woocommerce' ),
					'timedOut' => __( 'Timed out waiting for the terminal. Check the terminal or try again.', 'mollie-terminal-for-woocommerce' ),
					'notCancelable' => __( 'This payment can no longer be canceled.', 'mollie-terminal-for-woocommerce' ),
					'noTerminals' => __( 'No terminals found on this Mollie account.', 'mollie-terminal-for-woocommerce' ),
					'terminalsFailed' => __( 'Could not load terminals.', 'mollie-terminal-for-woocommerce' ),
				),
			)
		);
	}
	/**
	 * Completes the order when the terminal payment has already succeeded.
	 *
	 * The terminal payment is created and confirmed out-of-band via AJAX/webhook,
	 * so by the time WooCommerce submits the order-pay form the payment is
	 * usually already reconciled. We confirm it is paid (polling Mollie once more
	 * if needed) and hand WooCommerce the thank-you redirect the POS listens for.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( ! $order->is_paid() ) {
			try {
				$settings = new Settings();
				$service  = new MolliePaymentService( new MollieApiClient( $settings->api_key() ), $settings );
				$result   = $service->poll_order( $order );
				if ( 'paid' === ( $result['status'] ?? '' ) ) {
					$refreshed = wc_get_order( $order_id );
					if ( $refreshed ) {
						$order = $refreshed;
					}
				}
			} catch ( \Exception $e ) {
				Logger::log( 'Mollie Terminal process_payment could not verify payment: ' . $e->getMessage() );
			}
		}
		if ( $order->is_paid() ) {
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}
		wc_add_notice( __( 'No completed Mollie Terminal payment was found for this order yet. Complete the payment on the terminal and try again.', 'mollie-terminal-for-woocommerce' ), 'error' );
		return array( 'result' => 'failure' );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) { $order = wc_get_order( $order_id ); if ( ! $order ) { return new \WP_Error( 'mtfwc_invalid_order', __( 'Invalid order.', 'mollie-terminal-for-woocommerce' ) ); } return ( new RefundHandler( new MollieApiClient( ( new Settings() )->api_key() ) ) )->process_refund( $order, $amount, $reason ); }
}
