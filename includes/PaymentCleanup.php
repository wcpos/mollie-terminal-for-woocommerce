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
	/** One-off cron event retrying a cancel the status-change hook could not finish. */
	public const RETRY_HOOK = 'mtfwc_retry_open_payment_cancel';
	// Seconds before the first retry; each later retry doubles it (60, 120, 240, 480).
	private const RETRY_DELAY = 60;
	// Four retries (about fifteen minutes) outlast a busy order or a short Mollie
	// outage; a payment still open after that gets an error log and an order note.
	private const RETRY_LIMIT = 4;
	// Order statuses under which an open terminal payment must not stay open.
	public const NON_PAYABLE = array( 'processing', 'completed', 'cancelled', 'failed' );

	private $service;

	public function __construct( ?MolliePaymentService $service = null ) {
		$this->service = $service;
		if ( ! function_exists( 'add_action' ) ) { return; }
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_cancel_abandoned_payment' ), 20, 4 );
		add_action( self::RETRY_HOOK, array( $this, 'retry_cancel' ), 10, 3 );
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) { wp_unschedule_hook( self::RETRY_HOOK ); }
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
		if ( ! in_array( (string) $to_status, self::NON_PAYABLE, true ) ) {
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
		Logger::log( 'Order left the payable state with an open Mollie terminal payment; canceling it.', array( 'order_id' => (int) $order_id, 'to_status' => (string) $to_status, 'payment_id' => $current['payment_id'] ), 'info' );
		$this->cancel_open_payment( $order, (string) $current['payment_id'], (string) $to_status, 0 );
	}

	/**
	 * Cron retry of a cancel that failed in the status-change hook (the order's
	 * abandoned list was busy, or Mollie did not answer). This hook is one-shot
	 * and the stale-payment sweep only scans payable orders for current
	 * attempts, so without this nothing would try again. Runs only while the
	 * order is still non-payable and the same payment is still its open current
	 * attempt: a cashier who reopened the order collects that same payment
	 * again, and one who started a new payment must not have it canceled. The
	 * service checks the id once more under its own lock.
	 */
	public function retry_cancel( $order_id, $payment_id = '', $attempt = 1 ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order || ! in_array( (string) $order->get_status(), self::NON_PAYABLE, true ) ) { return; }
		$current = PaymentAttempt::current( $order );
		if ( ! $current || (string) ( $current['payment_id'] ?? '' ) !== (string) $payment_id || ! PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) {
			return;
		}
		$this->cancel_open_payment( $order, (string) $payment_id, (string) $order->get_status(), (int) $attempt );
	}

	private function cancel_open_payment( $order, string $payment_id, string $order_status, int $attempt ): void {
		try {
			$result = $this->service()->cancel_order_payment( $order, $payment_id );
			$status   = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';
			$order->add_order_note( sprintf( 'Mollie Terminal: open payment auto-cancel after order became %s (result: %s).', $order_status, $status ) );
			$order->save();
		} catch ( Exception $e ) {
			Logger::log( 'Auto-cancel of open Mollie terminal payment failed: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id, 'attempt' => $attempt ), 'error' );
			$this->schedule_retry( $order, $payment_id, $attempt + 1 );
		}
	}

	private function schedule_retry( $order, string $payment_id, int $attempt ): void {
		$order_id = (int) $order->get_id();
		if ( $attempt > self::RETRY_LIMIT || ! function_exists( 'wp_schedule_single_event' ) ) {
			Logger::log( 'Giving up on the automatic cancel of an open Mollie terminal payment.', array( 'order_id' => $order_id, 'payment_id' => $payment_id ), 'error' );
			$order->add_order_note( sprintf( 'Mollie Terminal: open payment %s could not be canceled automatically. Check it in the Mollie dashboard.', $payment_id ) );
			$order->save();
			return;
		}
		$args = array( $order_id, $payment_id, $attempt );
		if ( wp_next_scheduled( self::RETRY_HOOK, $args ) ) { return; }
		if ( true !== wp_schedule_single_event( time() + ( self::RETRY_DELAY << ( $attempt - 1 ) ), self::RETRY_HOOK, $args ) ) {
			// WordPress could not store the event: no retry will run, so say so
			// on the order now rather than after a retry that never comes.
			$this->schedule_retry( $order, $payment_id, self::RETRY_LIMIT + 1 );
			return;
		}
		Logger::log( 'Automatic cancel of an open Mollie terminal payment will be retried.', array( 'order_id' => $order_id, 'payment_id' => $payment_id, 'attempt' => $attempt ), 'warning' );
	}
}
