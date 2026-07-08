<?php
// Covers the stale-payment sweep per-order decision: only still-payable orders
// whose current Mollie attempt has been open past the threshold get canceled.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForSweeper { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForSweeper(); }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/PaymentSweeper.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

class FakeOrderForSweeper {
	public $meta = array();
	public $paid = false;
	public $notes = array();
	public function is_paid() { return $this->paid; }
	public function get_id() { return 4242; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() {}
}

class CountingCancelService extends MolliePaymentService {
	public $cancel_calls = 0;
	public $abandoned_calls = 0;
	public $abandoned_result = array( 'tr_abandoned' => 'canceled' );
	public function __construct() {}
	public function cancel_order_payment( $order ): array { $this->cancel_calls++; return array( 'status' => 'canceled' ); }
	public function cancel_abandoned_payments( $order ): array {
		if ( empty( PaymentAttempt::abandoned( $order ) ) ) { return array(); }
		$this->abandoned_calls++;
		return $this->abandoned_result;
	}
}

function make_sweeper_order( string $status, int $age_seconds, bool $paid = false ): FakeOrderForSweeper {
	$order = new FakeOrderForSweeper();
	$order->paid = $paid;
	if ( '' !== $status ) {
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_sweep';
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = $status;
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_CREATED_AT ] = gmdate( 'c', time() - $age_seconds );
	}
	return $order;
}

// Stale open payment (20 min old) on a still-payable order -> swept.
$service = new CountingCancelService();
$sweeper = new PaymentSweeper( $service );
$order = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS );
expect( true === $sweeper->sweep_order( $order ), 'a stale open payment should be swept' );
expect( 1 === $service->cancel_calls, 'the sweep should cancel the stale payment' );
expect( ! empty( $order->notes ), 'the sweep should leave an order note' );

// Fresh open payment (30 s old) -> left alone.
$order = make_sweeper_order( 'open', 30 );
expect( false === $sweeper->sweep_order( $order ), 'a fresh open payment must not be swept' );
expect( 1 === $service->cancel_calls, 'no extra cancel for a fresh payment' );

// Paid order -> left alone even with an old attempt.
$order = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS, true );
expect( false === $sweeper->sweep_order( $order ), 'a paid order must not be swept' );

// No attempt -> left alone.
$order = make_sweeper_order( '', 0 );
expect( false === $sweeper->sweep_order( $order ), 'an order without an attempt must not be swept' );

// Final (canceled) attempt -> left alone.
$order = make_sweeper_order( 'canceled', 20 * MINUTE_IN_SECONDS );
expect( false === $sweeper->sweep_order( $order ), 'a final attempt must not be swept' );

expect( 1 === $service->cancel_calls, 'only the one stale open payment should have been canceled' );
expect( 0 === $service->abandoned_calls, 'orders without abandoned payments must not hit the abandoned path' );

// An abandoned payment has no current-attempt pointer, but it is still open at
// Mollie: the sweep must find it via META_ABANDONED_PAYMENT_IDS and resolve it.
$order = make_sweeper_order( '', 0 );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
expect( true === $sweeper->sweep_order( $order ), 'an abandoned payment should be swept' );
expect( 1 === $service->abandoned_calls, 'the sweep should resolve the abandoned payment' );
expect( 1 === $service->cancel_calls, 'an order without a current attempt needs no current-attempt cancel' );
expect( ! empty( $order->notes ), 'resolving an abandoned payment should leave an order note' );

// A paid order still gets its abandoned payments chased: the customer may have
// paid in cash while the terminal payment stayed open at Mollie.
$order = make_sweeper_order( '', 0, true );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
expect( true === $sweeper->sweep_order( $order ), 'a paid order with an abandoned payment should be swept' );
expect( 2 === $service->abandoned_calls, 'the abandoned payment is resolved even on a paid order' );
expect( 1 === $service->cancel_calls, 'a paid order must not have its current attempt canceled' );

// A still-open abandoned payment leaves no note (nothing was resolved yet) but
// still counts as swept so the next run retries it.
$order = make_sweeper_order( '', 0 );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
$service->abandoned_result = array( 'tr_abandoned' => 'still_open' );
expect( true === $sweeper->sweep_order( $order ), 'a still-open abandoned payment counts as swept' );
expect( empty( $order->notes ), 'an unresolved abandoned payment must not spam order notes' );

echo "payment-sweeper ok\n";
