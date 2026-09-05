<?php
// The gateway must reuse WooCommerce's refund, never create a duplicate.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function wc_create_refund( $args ) { expect( false, 'the gateway must not create another WooCommerce refund' ); }
function wc_format_decimal( $value, $dp = false, $trim_zeros = false ) { return number_format( (float) $value, false === $dp ? 2 : $dp, '.', '' ); }
function __( $text, $domain = null ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'refund-attempt-uuid'; }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); }

require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/RefundReconciler.php';
require_once __DIR__ . '/../../includes/RefundHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\RefundHandler;
use WCPOS\WooCommercePOS\MollieTerminal\RefundReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class FakeRefund {
	public $meta = array();
	public $saved = false;
	private $id;
	private $amount;
	public function __construct( $id, $amount ) { $this->id = $id; $this->amount = $amount; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return 123; }
	public function get_amount() { return $this->amount; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saved = true; }
}
class FakeOrderForRefund {
	public $refunds;
	public function __construct( array $refunds ) { $this->refunds = $refunds; }
	public function get_id() { return 123; }
	public function get_refunds() { return $this->refunds; }
	public function get_transaction_id() { return 'tr_paid'; }
	public function get_currency() { return 'EUR'; }
}
class FakeRefundClient extends MollieApiClient {
	public $calls = 0;
	public $payload;
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array { $this->calls++; return array( 'amount' => array( 'value' => '30.00', 'currency' => 'EUR' ) ); }
	public function list_refunds( string $payment_id ): array { $this->calls++; return array(); }
	public function create_refund( string $payment_id, array $payload ): array { $this->calls++; $this->payload = $payload; return array( 'id' => 're_new', 'status' => 'queued' ); }
}

// Newest first, as returned by WooCommerce; decimal representations may differ.
$older = new FakeRefund( 10, '10.00' );
$older->meta[ RefundReconciler::META_MOLLIE_REFUND_ID ] = 're_old';
$original_older = clone $older;
$newer = new FakeRefund( 11, '10.000' );
$order = new FakeOrderForRefund( array( $newer, $older ) );
$client = new FakeRefundClient();
$result = ( new RefundHandler( $client ) )->process_refund( $order, 10, 'Returned item' );
expect( array( 'status' => 'refunded', 'refund_id' => 're_new' ) === $result, 'the existing refund should be reconciled' );
expect( 're_new' === $newer->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ) && $newer->saved, 'the newer refund must store the Mollie refund id' );
expect( $original_older == $older, 'the older refund must remain untouched' );
expect( 3 === $client->calls && '11' === $client->payload['metadata']['woo_refund_id'], 'Mollie must receive the newer WooCommerce refund id' );
expect( '10.00' === $client->payload['amount']['value'] && 'Returned item' === $client->payload['description'], 'amount and reason must reach Mollie unchanged' );

// Neither an unmatched different amount nor an already-sent amount qualifies.
$different = new FakeRefund( 12, '9.99' );
$order = new FakeOrderForRefund( array( $different, $older ) );
$client = new FakeRefundClient();
$result = ( new RefundHandler( $client ) )->process_refund( $order, '10.00' );
expect( is_wp_error( $result ) && 'mtfwc_refund_not_found' === $result->get_error_code(), 'no matching refund must return mtfwc_refund_not_found' );
expect( 0 === $client->calls, 'no matching refund must not call Mollie' );
expect( array() === $different->meta && ! $different->saved && $original_older == $older, 'a missing match must leave refunds untouched' );

// The woocommerce_create_refund hook binds the gateway to the exact refund of
// this request, even when a newer same-amount refund exists (two refunds in
// flight); the remembered refund is consumed so it cannot leak to the next call.
$exact = new FakeRefund( 13, '10.00' );
$other = new FakeRefund( 14, '10.00' );
$order = new FakeOrderForRefund( array( $other, $exact ) );
$client = new FakeRefundClient();
RefundHandler::remember_refund( $exact, array( 'refund_payment' => true ) );
$result = ( new RefundHandler( $client ) )->process_refund( $order, '10.00' );
expect( 're_new' === $exact->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ) && '13' === $client->payload['metadata']['woo_refund_id'], 'the remembered refund must be the one reconciled' );
expect( array() === $other->meta && ! $other->saved, 'the newer same-amount refund must be untouched' );
$client = new FakeRefundClient();
$result = ( new RefundHandler( $client ) )->process_refund( $order, '10.00' );
expect( 're_new' === $other->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ), 'without a remembered refund the newest unlinked match is used' );

// A refund that did not ask for a payment reversal (manual refund) is never
// remembered, and a remembered record whose amount differs from the request
// (or that is already linked) is discarded rather than reused.
$manual = new FakeRefund( 15, '10.00' );
RefundHandler::remember_refund( $manual, array( 'refund_payment' => false ) );
$fresh = new FakeRefund( 16, '10.00' );
$order = new FakeOrderForRefund( array( $fresh, $manual ) );
$client = new FakeRefundClient();
( new RefundHandler( $client ) )->process_refund( $order, '10.00' );
expect( array() === $manual->meta && 're_new' === $fresh->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ), 'a manual refund must not be remembered; the unlinked match is used instead' );
$stale = new FakeRefund( 17, '5.00' );
RefundHandler::remember_refund( $stale, array( 'refund_payment' => true ) );
$order = new FakeOrderForRefund( array( $stale ) );
$client = new FakeRefundClient();
$result = ( new RefundHandler( $client ) )->process_refund( $order, '10.00' );
expect( is_wp_error( $result ) && 'mtfwc_refund_not_found' === $result->get_error_code() && array() === $stale->meta && 0 === $client->calls, 'a remembered refund with a different amount must be discarded, not reused' );

echo "refund-uses-existing-refund ok\n";
