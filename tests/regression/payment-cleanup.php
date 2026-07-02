<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$options = array();
$actions = array();
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { global $actions; $actions[ $hook ] = $callback; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForCleanup { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForCleanup(); }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/PaymentCleanup.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentCleanup;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

class FakeOrderForCleanup {
	public $meta = array();
	public $notes = array();
	public $saved = false;
	public function get_id() { return 321; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saved = true; }
}

class FakeCancelService extends MolliePaymentService {
	public $cancel_calls = 0;
	public function __construct() {}
	public function cancel_order_payment( $order ): array { $this->cancel_calls++; return array( 'status' => 'canceled' ); }
}

function make_order( string $payment_status ): FakeOrderForCleanup {
	$order = new FakeOrderForCleanup();
	$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_cleanup_test';
	$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = $payment_status;
	return $order;
}

// Registers on the order status change hook.
$service = new FakeCancelService();
$cleanup = new PaymentCleanup( $service );
expect( isset( $actions['woocommerce_order_status_changed'] ), 'cleanup should hook woocommerce_order_status_changed' );

// Open Mollie payment + order completed another way (e.g. cash) -> cancel.
$order = make_order( 'open' );
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'processing', $order );
expect( 1 === $service->cancel_calls, 'an open payment should be canceled when the order is paid another way' );
expect( ! empty( $order->notes ), 'auto-cancel should leave an order note' );

// Order cancelled in WooCommerce -> cancel the open payment too.
$order = make_order( 'pending' );
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'cancelled', $order );
expect( 2 === $service->cancel_calls, 'an open payment should be canceled when the order is cancelled' );

// Our own gateway path: the attempt is already final (paid) -> no cancel.
$order = make_order( 'paid' );
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'processing', $order );
expect( 2 === $service->cancel_calls, 'a final payment attempt must not be canceled' );

// No Mollie attempt at all -> no cancel.
$order = new FakeOrderForCleanup();
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'processing', $order );
expect( 2 === $service->cancel_calls, 'orders without a Mollie attempt are ignored' );

// Status changes that keep the order payable -> no cancel.
$order = make_order( 'open' );
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'on-hold', $order );
expect( 2 === $service->cancel_calls, 'a still-payable status change must not cancel the payment' );

echo "payment-cleanup ok\n";
