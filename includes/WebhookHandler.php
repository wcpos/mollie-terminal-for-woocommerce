<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class WebhookHandler {
	public function __construct() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_ajax_mtfwc_mollie_webhook', array( $this, 'handle' ) );
			add_action( 'wp_ajax_nopriv_mtfwc_mollie_webhook', array( $this, 'handle' ) );
		}
	}

	public function handle(): void {
		$payment_id = sanitize_text_field( wp_unslash( $_POST['id'] ?? $_GET['id'] ?? '' ) );
		if ( '' === $payment_id ) {
			Diagnostics::record( 'warning', 'Mollie webhook received without payment ID.' );
			status_header( 200 ); echo 'OK'; exit;
		}
		Diagnostics::record( 'info', 'Mollie webhook received.', array( 'payment_id' => $payment_id ) );
		try {
			$settings = new Settings();
			$payment = ( new MollieApiClient( $settings->api_key() ) )->get_payment( $payment_id );
			$order = $this->find_order_for_payment( $payment_id, $payment );
			if ( ! $order ) {
				Diagnostics::record( 'warning', 'Mollie webhook received for unknown payment.', array( 'payment_id' => $payment_id ) );
				status_header( 200 ); echo 'OK'; exit;
			}
			( new PaymentReconciler( $settings ) )->reconcile( $order, $payment, 'webhook' );
			Diagnostics::record( 'success', 'Mollie webhook reconciled.', array( 'payment_id' => $payment_id, 'order_id' => (int) $order->get_id(), 'status' => $payment['status'] ?? '' ) );
		} catch ( Exception $e ) {
			Diagnostics::record( 'error', 'Mollie webhook failed: ' . $e->getMessage(), array( 'payment_id' => $payment_id ) );
		}
		status_header( 200 ); echo 'OK'; exit;
	}

	private function find_order_for_payment( string $payment_id, array $payment ) {
		$order_id = (int) ( $payment['metadata']['order_id'] ?? 0 );
		if ( $order_id ) { $order = wc_get_order( $order_id ); if ( $order ) { return $order; } }
		$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => PaymentAttempt::META_CURRENT_PAYMENT_ID, 'meta_value' => $payment_id ) );
		return $orders[0] ?? null;
	}
}
