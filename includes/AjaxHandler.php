<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;

class AjaxHandler {
	public function __construct() {
		if ( ! function_exists( 'add_action' ) || ! wp_doing_ajax() ) { return; }
		foreach ( array( 'mtfwc_start_payment', 'mtfwc_poll_payment', 'mtfwc_cancel_payment' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $action ) );
		}
		add_action( 'wp_ajax_mtfwc_pair_terminal', array( $this, 'mtfwc_pair_terminal' ) );
	}

	public function mtfwc_start_payment(): void { $this->with_order( function ( $order ) { return $this->payment_service()->start_payment_for_order( $order, sanitize_text_field( wp_unslash( $_POST['terminal_id'] ?? '' ) ) ); } ); }
	public function mtfwc_poll_payment(): void { $this->with_order( function ( $order ) { return $this->payment_service()->poll_order( $order ); } ); }
	public function mtfwc_cancel_payment(): void { $this->with_order( function ( $order ) { return $this->payment_service()->cancel_order_payment( $order ); } ); }

	public function mtfwc_pair_terminal(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'mtfwc_admin_actions', 'nonce', false ) ) { wp_send_json_error( __( 'Security check failed', 'mollie-terminal-for-woocommerce' ), 403 ); }
		try {
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? 'WCPOS Terminal' ) );
			wp_send_json_success( $this->terminal_service()->create_pairing_code( $name ) );
		} catch ( Exception $e ) { Logger::log( 'Terminal pairing failed: ' . $e->getMessage() ); wp_send_json_error( $e->getMessage(), 500 ); }
	}

	private function with_order( callable $callback ): void {
		try {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! $order_id ) { wp_send_json_error( __( 'Order ID is required.', 'mollie-terminal-for-woocommerce' ), 400 ); }
			if ( ! $this->can_access_order( $order_id ) ) { wp_send_json_error( __( 'Unauthorized request.', 'mollie-terminal-for-woocommerce' ), 403 ); }
			$order = wc_get_order( $order_id );
			if ( ! $order ) { wp_send_json_error( __( 'Invalid order.', 'mollie-terminal-for-woocommerce' ), 404 ); }
			wp_send_json_success( $callback( $order ) );
		} catch ( Exception $e ) { Logger::log( 'Mollie Terminal AJAX failed: ' . $e->getMessage() ); wp_send_json_error( $e->getMessage(), 500 ); }
	}

	private function can_access_order( int $order_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_order', $order_id ) ) { return true; }
		$token = sanitize_text_field( wp_unslash( $_POST['order_token'] ?? '' ) );
		return $token && hash_equals( $this->order_token( $order_id ), $token );
	}

	private function order_token( int $order_id ): string { return substr( wp_hash( 'mtfwc_order_' . $order_id . wp_salt( 'nonce' ) ), 0, 16 ); }
	private function settings(): Settings { return new Settings(); }
	private function client(): MollieApiClient { return new MollieApiClient( $this->settings()->api_key() ); }
	private function terminal_service(): TerminalService { return new TerminalService( $this->client(), $this->settings() ); }
	private function payment_service(): MolliePaymentService { return new MolliePaymentService( $this->client(), $this->settings(), $this->terminal_service() ); }
}
