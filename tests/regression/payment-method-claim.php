<?php
// Regression: a Mollie payment is created and completed over AJAX/webhook,
// never through the WooCommerce pay form that stamps the chosen gateway on
// the order. The order must still end up with this gateway as its payment
// method, because WooCommerce POS resolves its per-gateway order status (and
// WooCommerce routes refunds) from $order->get_payment_method(). Before 0.5.5
// a POS order paid by Mollie kept an empty or default (cash) method and always
// landed on the default status, "Completed", whatever the merchant configured.
// The gateway is claimed only once Mollie confirms the payment: an abandoned
// attempt must not leave Mollie on an order that is then paid another way.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'attempt-uuid'; }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForClaim { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForClaim(); }
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

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

// Mirrors the parts of WC_Order the flow touches. The WooCommerce POS status
// filter runs inside payment_complete() and reads get_payment_method() at that
// moment, so the fake captures the method as seen from inside payment_complete().
class FakeOrderForClaim {
	public $meta = array();
	public $notes = array();
	public $saves = 0;
	public $payment_method = '';
	public $payment_method_title = '';
	public $transaction_id = '';
	public $method_seen_by_payment_complete = null;
	public function __construct( string $payment_method = '' ) { $this->payment_method = $payment_method; }
	public function get_id() { return 321; }
	public function get_total() { return '12.34'; }
	public function get_currency() { return 'EUR'; }
	public function get_order_number() { return '321'; }
	public function get_checkout_order_received_url() { return 'https://webshop.example.org/order/321/'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saves++; }
	public function is_paid() { return '' !== $this->transaction_id; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function get_payment_method() { return $this->payment_method; }
	public function set_payment_method( $method ) { $this->payment_method = (string) $method; }
	public function get_payment_method_title() { return $this->payment_method_title; }
	public function set_payment_method_title( $title ) { $this->payment_method_title = (string) $title; }
	public function payment_complete( $id ) { $this->method_seen_by_payment_complete = $this->payment_method; $this->transaction_id = $id; $this->saves++; }
}

class ClaimMollieClient extends MollieApiClient {
	public function __construct() {}
	public function create_payment( array $payload, array $include = array(), string $idempotency_key = '' ): array {
		return array( 'id' => 'tr_claim', 'status' => 'open', 'amount' => $payload['amount'], 'method' => $payload['method'], 'mode' => 'live', 'metadata' => $payload['metadata'] );
	}
}

class ClaimTerminalService extends TerminalService {
	public function __construct() {}
	public function validate_terminal( string $terminal_id ): array { return array( 'id' => $terminal_id, 'status' => 'active' ); }
}

function paid_payment(): array {
	return array( 'id' => 'tr_claim', 'status' => 'paid', 'method' => 'pointofsale', 'mode' => 'live', 'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ), 'metadata' => array( 'order_id' => '321' ) );
}

$settings = new Settings( array( 'mode' => 'live', 'default_terminal_id' => 'term_default' ) );
$service  = new MolliePaymentService( new ClaimMollieClient(), $settings, new ClaimTerminalService() );

// --- Starting a payment leaves the order's gateway alone -------------------
// A cashier may abandon the terminal attempt and take cash instead; the order
// must not be attributed to Mollie until Mollie confirms a payment.

$order = new FakeOrderForClaim( 'pos_cash' ); // the POS default gateway, as a fresh POS order carries it
$service->start_payment_for_order( $order, 'term_1' );
expect( 'pos_cash' === $order->get_payment_method(), 'starting a terminal payment must not change the order payment method' );

$order = new FakeOrderForClaim( '' );
( new MolliePaymentService( new ClaimMollieClient(), new Settings( array( 'mode' => 'live', 'qr_methods' => array( 'ideal' ) ) ), new ClaimTerminalService() ) )->start_qr_payment_for_order( $order, 'ideal' );
expect( '' === $order->get_payment_method(), 'starting a QR payment must not change the order payment method' );

// --- Completing a payment stamps the gateway before payment_complete() -----
// The webhook, poll and sweeper paths all end here, where the WooCommerce POS
// status filter runs inside payment_complete().

foreach ( array( '' => 'an empty', 'pos_cash' => 'the POS default' ) as $method_before => $label ) {
	$order = new FakeOrderForClaim( $method_before );
	PaymentAttempt::record_new( $order, array( 'id' => 'tr_claim', 'status' => 'open', 'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ) ), 'term_1', 'live', 'pointofsale' );
	$result = ( new PaymentReconciler( $settings ) )->reconcile( $order, paid_payment(), 'webhook' );
	expect( 'paid' === ( $result['status'] ?? '' ), 'the paid payment should complete the order' );
	expect( Settings::GATEWAY_ID === $order->method_seen_by_payment_complete, "payment_complete() must see Mollie as the payment method, not $label one" );
	expect( 'Mollie Terminal' === $order->get_payment_method_title(), 'completion should set the gateway title' );
}

// The configured title is used once Mollie confirms the payment.
$order = new FakeOrderForClaim( '' );
PaymentAttempt::record_new( $order, array( 'id' => 'tr_claim', 'status' => 'open', 'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ) ), 'term_1', 'live', 'pointofsale' );
( new PaymentReconciler( new Settings( array( 'mode' => 'live', 'title' => 'Pin / QR' ) ) ) )->reconcile( $order, paid_payment(), 'poll' );
expect( 'Pin / QR' === $order->get_payment_method_title(), 'the configured gateway title should be used' );

// A custom title is never overwritten once the order is ours, but an empty one is filled.
$order = new FakeOrderForClaim( Settings::GATEWAY_ID );
$order->set_payment_method_title( 'Custom label' );
PaymentAttempt::claim_order_gateway( $order, 'Mollie Terminal' );
expect( 'Custom label' === $order->get_payment_method_title(), 'an order already on this gateway keeps its title' );
$order = new FakeOrderForClaim( Settings::GATEWAY_ID );
PaymentAttempt::claim_order_gateway( $order, 'Mollie Terminal' );
expect( 'Mollie Terminal' === $order->get_payment_method_title(), 'an order already on this gateway with no title gets one' );

echo "payment-method-claim ok\n";
