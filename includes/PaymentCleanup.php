<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

/**
 * Cancels open Mollie terminal payments the moment the order stops needing
 * them, so no payment lingers "open" on the Mollie side when:
 * - the order is completed with a different payment method (customer changed
 *   their mind and paid cash), or
 * - the order is cancelled or failed in WooCommerce.
 *
 * Our own gateway never trips this: the reconciler records the final payment
 * status on the attempt before payment_complete() fires the status change, so
 * by the time this hook runs the attempt is no longer non-final.
 */
class PaymentCleanup {
	private $service;

	public function __construct( ?MolliePaymentService $service = null ) {
		$this->service = $service;
		if ( ! function_exists( 'add_action' ) ) { return; }
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_cancel_abandoned_payment' ), 20, 4 );
	}

	private function service(): MolliePaymentService {
		if ( ! $this->service ) {
			$settings = new Settings();
			// Short API timeout: this runs inside the order-status-change hook,
			// so a Mollie outage must not hang checkout for the default 30s.
			$this->service = new MolliePaymentService( new MollieApiClient( $settings->api_key(), 8 ), $settings );
		}
		return $this->service;
	}

	public function maybe_cancel_abandoned_payment( $order_id, $from_status, $to_status, $order = null ): void {
		if ( ! in_array( (string) $to_status, array( 'processing', 'completed', 'cancelled', 'failed' ), true ) ) {
			return;
		}
		$order = $order ?: wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$current = PaymentAttempt::current( $order );
		if ( ! $current || empty( $current['payment_id'] ) || ! PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) {
			return;
		}
		Diagnostics::record( 'info', 'Order left the payable state with an open Mollie terminal payment; canceling it.', array( 'order_id' => (int) $order_id, 'to_status' => (string) $to_status, 'payment_id' => $current['payment_id'] ) );
		try {
			$result = $this->service()->cancel_order_payment( $order );
			$status   = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';
			$order->add_order_note( sprintf( 'Mollie Terminal: open payment auto-cancel after order became %s (result: %s).', (string) $to_status, $status ) );
			$order->save();
		} catch ( Exception $e ) {
			Diagnostics::record( 'error', 'Auto-cancel of open Mollie terminal payment failed: ' . $e->getMessage() );
		}
	}
}
