<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$options = array();
$actions = array();
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { global $actions; $actions[ $hook ] = $callback; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
$scheduled = array();
$schedule_result = true;
function wp_next_scheduled( $hook, $args = array() ) { global $scheduled; foreach ( $scheduled as $event ) { if ( $event['hook'] === $hook && $event['args'] === $args ) { return $event['at']; } } return false; }
function wp_schedule_single_event( $at, $hook, $args = array() ) { global $scheduled, $schedule_result; if ( true !== $schedule_result ) { return $schedule_result; } $scheduled[] = array( 'at' => $at, 'hook' => $hook, 'args' => $args ); return true; }
function wc_get_order( $id ) { return $GLOBALS['mtfwc_cleanup_order'] ?? false; }
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
	public $status = 'processing';
	public function get_status() { return $this->status; }
	public $payment_method = '';
	public $payment_method_title = '';
	public function get_payment_method() { return $this->payment_method; }
	public function set_payment_method( $m ) { $this->payment_method = (string) $m; }
	public function set_payment_method_title( $t ) { $this->payment_method_title = (string) $t; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saved = true; }
}

class FakeCancelService extends MolliePaymentService {
	public $cancel_calls = 0;
	public $fail = false;
	public $only_ids = array();
	public function __construct() {}
	public function cancel_order_payment( $order, string $only_payment_id = '' ): array {
		$this->cancel_calls++;
		$this->only_ids[] = $only_payment_id;
		if ( $this->fail ) { throw new RuntimeException( 'Another Mollie Terminal operation is finishing on this order.' ); }
		return array( 'status' => 'canceled' );
	}
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

// #34: a cancel the hook could not finish (the order's abandoned list was busy,
// or Mollie did not answer) is retried by a one-off cron event, since the hook
// is one-shot and the sweep does not scan non-payable orders for current attempts.
expect( isset( $actions[ PaymentCleanup::RETRY_HOOK ] ), 'cleanup should hook its retry event' );
$service->fail = true;
$order = make_order( 'open' );
$GLOBALS['mtfwc_cleanup_order'] = $order;
$before = time();
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'processing', $order );
expect( 3 === $service->cancel_calls && array() === $order->notes, 'a failed cancel leaves no result note' );
expect( 1 === count( $scheduled ) && PaymentCleanup::RETRY_HOOK === $scheduled[0]['hook'] && array( 321, 'tr_cleanup_test', 1 ) === $scheduled[0]['args'], 'a failed cancel schedules one retry for that payment (scheduled: ' . json_encode( $scheduled ) . ')' );
expect( $scheduled[0]['at'] >= $before + 60 && $scheduled[0]['at'] <= time() + 60, 'the first retry runs about a minute later' );
// The same failure again does not queue a duplicate.
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'processing', $order );
expect( 1 === count( $scheduled ), 'a repeated failure must not queue a second identical retry' );
// The retry fires while the payment is still the open current attempt: it cancels.
$service->fail = false;
$cleanup->retry_cancel( 321, 'tr_cleanup_test', 1 );
expect( 5 === $service->cancel_calls && 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'processing' ), 'the retry cancels and notes the order\'s status at that time' );
expect( 'tr_cleanup_test' === end( $service->only_ids ), 'the retry names the payment it may cancel, for the check under the cancel lock' );
// The order was reopened (pending) before the retry ran: the cashier is collecting
// that same payment again, so the retry must not touch it.
$order = make_order( 'open' );
$order->status = 'pending';
$GLOBALS['mtfwc_cleanup_order'] = $order;
$cleanup->retry_cancel( 321, 'tr_cleanup_test', 1 );
expect( 5 === $service->cancel_calls, 'a retry must not cancel the payment of an order that is payable again' );
// The retry finds a different current payment (the cashier reopened the order and started again): it must not cancel.
$order = make_order( 'open' );
$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_newer_attempt';
$GLOBALS['mtfwc_cleanup_order'] = $order;
$cleanup->retry_cancel( 321, 'tr_cleanup_test', 1 );
expect( 5 === $service->cancel_calls, 'a retry must not cancel a newer current payment' );
// A retry that fails again backs off, doubling the delay, and the last one gives up with a note.
$service->fail = true;
$order = make_order( 'open' );
$GLOBALS['mtfwc_cleanup_order'] = $order;
$scheduled = array();
$cleanup->retry_cancel( 321, 'tr_cleanup_test', 1 );
expect( 1 === count( $scheduled ) && array( 321, 'tr_cleanup_test', 2 ) === $scheduled[0]['args'] && $scheduled[0]['at'] >= time() + 120, 'a failed retry schedules the next with a longer delay' );
$scheduled = array();
$cleanup->retry_cancel( 321, 'tr_cleanup_test', 4 );
expect( array() === $scheduled && 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'could not be canceled automatically' ), 'after the last retry the order gets a note and nothing more is scheduled' );
// WordPress refuses to store the event: no retry will come, so the note is left now.
$order = make_order( 'open' );
$GLOBALS['mtfwc_cleanup_order'] = $order;
$schedule_result = false;
$cleanup->maybe_cancel_abandoned_payment( 321, 'pending', 'completed', $order );
expect( array() === $scheduled && 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'could not be canceled automatically' ), 'a retry that cannot be scheduled leaves the note at once' );
$schedule_result = true;

echo "payment-cleanup ok\n";
