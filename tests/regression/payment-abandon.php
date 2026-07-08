<?php
// Covers the cancel/abandon fallback: when Mollie will not cancel an open
// payment (unresponsive terminal), the attempt is detached locally so the
// cashier regains control, and a genuinely cancelable payment is canceled.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'attempt-uuid'; }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForAbandon { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForAbandon(); }
function admin_url( $path = '' ) { return 'https://webshop.example.org/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';

use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;

class FakeOrderForAbandon {
	public $meta = array();
	public $notes = array();
	public $saved = false;
	public $paid = false;
	public $transaction_id = '';
	public function is_paid() { return $this->paid; }
	public function get_id() { return 5555; }
	public function get_total() { return '0.01'; }
	public function get_currency() { return 'EUR'; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function payment_complete( $id ) { $this->paid = true; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saved = true; }
}

class ScriptedMollieClient extends MollieApiClient {
	public $get_responses;
	public $cancel_calls = 0;
	public function __construct( array $get_responses ) { $this->get_responses = $get_responses; }
	public function get_payment( string $payment_id ): array { return array_shift( $this->get_responses ); }
	public function cancel_payment( string $payment_id ): array { $this->cancel_calls++; return array( 'id' => $payment_id, 'status' => 'canceled' ); }
}

function seed_order(): FakeOrderForAbandon {
	$order = new FakeOrderForAbandon();
	$order->meta[ PaymentAttempt::META_CURRENT_ATTEMPT_ID ] = 'attempt-uuid';
	$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_open';
	$order->meta[ PaymentAttempt::META_CURRENT_TERMINAL_ID ] = 'term_default';
	$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = 'open';
	$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_CREATED_AT ] = gmdate( 'c' );
	$order->meta[ PaymentAttempt::META_ATTEMPTS ] = array( array( 'payment_id' => 'tr_open', 'status' => 'open' ) );
	return $order;
}

$settings  = new Settings( array( 'mode' => 'live', 'default_terminal_id' => 'term_default' ) );
$terminals = new TerminalService( new MollieApiClient( 'live_key' ), $settings );

// Case 1: terminal unresponsive — payment open and NOT cancelable -> abandon.
$order  = seed_order();
$client = new ScriptedMollieClient( array( array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => false ) ) );
$result = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_order_payment( $order );
expect( 'abandoned' === ( $result['status'] ?? '' ), 'a non-cancelable open payment should be abandoned' );
expect( 0 === $client->cancel_calls, 'a non-cancelable payment must not be sent to Mollie cancel' );
expect( null === PaymentAttempt::current( $order ), 'abandon must detach the current attempt pointer' );
expect( ! empty( $order->notes ), 'abandon should leave an order note' );
$history = PaymentAttempt::history( $order );
expect( 'abandoned' === ( $history[0]['status'] ?? '' ), 'the attempt should be marked abandoned in history' );
// The payment is still open at Mollie: keep its ID where the stale sweep looks,
// otherwise clearing the current pointer hides it from the WP-Cron backstop.
expect( array( 'tr_open' ) === PaymentAttempt::abandoned( $order ), 'abandon must park the payment ID for the stale sweep' );

// Case 2: terminal was off but payment still cancelable -> Mollie cancels it.
$order  = seed_order();
$client = new ScriptedMollieClient( array(
	array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => true ),
	array( 'id' => 'tr_open', 'status' => 'canceled', 'amount' => array( 'value' => '0.01', 'currency' => 'EUR' ), 'method' => 'pointofsale', 'mode' => 'live', 'metadata' => array( 'order_id' => '5555' ) ),
) );
$result = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_order_payment( $order );
expect( 1 === $client->cancel_calls, 'a cancelable payment should be canceled at Mollie' );
expect( 'canceled' === ( $result['status'] ?? '' ), 'a cancelable payment should reconcile to canceled' );

// Case 3: cancel attempt fails to take (still open after the DELETE) -> abandon.
$order  = seed_order();
$client = new ScriptedMollieClient( array(
	array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => true ),
	array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => true ),
) );
$result = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_order_payment( $order );
expect( 1 === $client->cancel_calls, 'the cancel should be attempted once' );
expect( 'abandoned' === ( $result['status'] ?? '' ), 'a payment still open after cancel should be abandoned' );
expect( null === PaymentAttempt::current( $order ), 'a stuck-open payment should be detached' );
expect( array( 'tr_open' ) === PaymentAttempt::abandoned( $order ), 'a stuck-open payment stays visible to the sweep' );

// Case 4: the sweep retries an abandoned payment; Mollie now cancels it, so the
// ID is dropped and the order stops being chased.
$order = seed_order();
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_open' );
unset( $order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] );
$client = new ScriptedMollieClient( array(
	array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => true ),
	array( 'id' => 'tr_open', 'status' => 'canceled', 'amount' => array( 'value' => '0.01', 'currency' => 'EUR' ), 'method' => 'pointofsale', 'mode' => 'live', 'metadata' => array( 'order_id' => '5555' ) ),
) );
$results = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_abandoned_payments( $order );
expect( 1 === $client->cancel_calls, 'the sweep should cancel an abandoned payment Mollie still allows canceling' );
expect( 'canceled' === ( $results['tr_open'] ?? '' ), 'the abandoned payment should resolve as canceled' );
expect( array() === PaymentAttempt::abandoned( $order ), 'a finalized abandoned payment is dropped from the sweep list' );

// Case 5: the terminal approved after all — the sweep completes the order rather
// than leaving the money unreconciled.
$order = seed_order();
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_open' );
unset( $order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] );
$client = new ScriptedMollieClient( array(
	array( 'id' => 'tr_open', 'status' => 'paid', 'amount' => array( 'value' => '0.01', 'currency' => 'EUR' ), 'method' => 'pointofsale', 'mode' => 'live', 'metadata' => array( 'order_id' => '5555' ) ),
) );
$results = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_abandoned_payments( $order );
expect( 0 === $client->cancel_calls, 'a paid abandoned payment must never be canceled' );
expect( 'paid' === ( $results['tr_open'] ?? '' ), 'a paid abandoned payment should resolve as paid' );
expect( $order->paid, 'a paid abandoned payment should complete the order' );
expect( array() === PaymentAttempt::abandoned( $order ), 'a paid abandoned payment is dropped from the sweep list' );

// Case 6: Mollie still refuses to cancel — keep the ID so the next sweep retries.
$order = seed_order();
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_open' );
unset( $order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] );
$client = new ScriptedMollieClient( array( array( 'id' => 'tr_open', 'status' => 'open', 'isCancelable' => false ) ) );
$results = ( new MolliePaymentService( $client, $settings, $terminals ) )->cancel_abandoned_payments( $order );
expect( 'still_open' === ( $results['tr_open'] ?? '' ), 'a non-cancelable abandoned payment stays open' );
expect( array( 'tr_open' ) === PaymentAttempt::abandoned( $order ), 'a still-open abandoned payment must be retried next sweep' );

echo "payment-abandon ok\n";
