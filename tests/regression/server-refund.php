<?php
require_once __DIR__ . '/support/fake-wpdb.php';
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
function sanitize_key( $key ) { return $key; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/MollieUnansweredException.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/RefundReconciler.php';
if ( ! function_exists( 'wc_get_logger' ) ) { class SilentLoggerForServerRefund { public function log( $level, $message, $context = array() ) {} } function wc_get_logger() { return new SilentLoggerForServerRefund(); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\RefundReconciler;
class RefundOrder {
	public $notes = array();
	public function get_id() { return 123; }
	public function get_transaction_id() { return ''; }
	public function get_meta( $key ) { return ''; }
	public function get_currency() { return 'EUR'; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() {}
}
$GLOBALS['scheduled'] = array();
function wp_schedule_single_event( $at, $hook, $args = array() ) { $GLOBALS['scheduled'][] = array( $at, $hook, $args ); return true; }
function wp_next_scheduled( $hook, $args = array() ) { foreach ( $GLOBALS['scheduled'] as $e ) { if ( $e[1] === $hook && $e[2] === $args ) { return $e[0]; } } return false; }
function wp_unschedule_event( $at, $hook, $args = array() ) {}
class WC_Order_Refund {
	public $meta = array();
	public $saved = array(); // the meta as persisted by each save()
	public function get_id() { return 456; }
	public function get_parent_id() { return 123; }
	public function get_reason() { return 'Returned item'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saved = $this->meta; }
}
class RefundClient extends MollieApiClient {
	public $status;
	public $calls = array();
	public $refunds = array();
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array { $this->calls[] = $payment_id; return array( 'amount' => array( 'value' => '30.00' ) ); }
	public $list_throws = null;
	public function list_refunds( string $payment_id ): array { $this->calls[] = $payment_id; if ( $this->list_throws ) { throw $this->list_throws; } return $this->refunds; }
	public $refreshed = null;
	public function get_refund( string $payment_id, string $refund_id ): array {
		$this->calls[] = 'refund:' . $refund_id;
		if ( $this->refreshed instanceof Exception ) { throw $this->refreshed; }
		return array( 'id' => $refund_id, 'status' => $this->refreshed );
	}
	public $attempt_persisted_at_post = null;
	public $post_keys = array();
	public function create_refund( string $payment_id, array $payload, string $idempotency_key = '' ): array {
		$this->calls[] = $payment_id;
		$this->post_keys[] = $idempotency_key;
		$this->attempt_persisted_at_post = $GLOBALS['orders'][456]->saved[ RefundReconciler::META_ATTEMPT_ID ] ?? null;
		expect( 'Returned item' === $payload['description'] && '5.00' === $payload['amount']['value'], 'refund amount/reason' );
		if ( $this->status instanceof Exception ) { throw $this->status; }
		return array( 'id' => 're_x', 'status' => $this->status );
	}
}
$orders[123] = new RefundOrder();
$client = new RefundClient();
$provider = new Provider( null, $client );
$row = array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_explicit' ) );
foreach ( array( 'refunded' => 'succeeded', 'queued' => 'pending', 'pending' => 'pending', 'processing' => 'pending', 'failed' => 'failed', 'canceled' => 'failed', 'unknown' => 'pending' ) as $status => $expected ) {
	$orders[456] = new WC_Order_Refund();
	$client->status = $status;
	$client->calls = array();
	$result = $provider->refund( $row, 456, '5.00' );
	expect( array( 'status' => $expected, 'provider_ref' => 're_x' ) === $result, 'refund map: ' . $status );
	expect( array( 'tr_explicit', 'tr_explicit', 'tr_explicit' ) === $client->calls, 'explicit row ref used without order transaction' );
	$client->refreshed = new RuntimeException( 'offline' );
	expect( $result === $provider->refund( $row, 456, '5.00' ) && array( 'tr_explicit', 'tr_explicit', 'tr_explicit', 'refund:re_x' ) === $client->calls, 'replay re-reads the stored refund and keeps the last status when Mollie is unreachable' );
	$client->refreshed = 'refunded';
	expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->refund( $row, 456, '5.00' ) && 'refunded' === $orders[456]->meta[ RefundReconciler::META_STATUS ], 'replay refreshes a settled refund: ' . $status );
	$client->refreshed = 'failed';
	expect( array( 'status' => 'failed', 'provider_ref' => 're_x' ) === $provider->refund( $row, 456, '5.00' ), 'replay refreshes a failed refund' );
}
// A historical webview row names no order and carries the Mollie payment id as its transaction reference.
$orders[456] = new WC_Order_Refund();
$client->status = 'refunded';
$client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->refund( array( 'provider_refs' => array( 'transaction_id' => 'tr_hist' ) ), 456, '5.00' ) && array( 'tr_hist', 'tr_hist', 'tr_hist' ) === $client->calls, 'a historical row refunds its transaction reference, with the order from the refund record' );
$orders[456] = new WC_Order_Refund();
$client->status = new WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException( 'Response lost' );
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
$result = $provider->refund( $row, 456, '5.00' );
expect( array( 'status' => 'pending', 'provider_ref' => null ) === $result, 'an unanswered refund POST is pending with its record kept, never an error WooCommerce would turn into a new record' );
// The attempt id was persisted before the POST and sent as its Idempotency-Key, so the ask that follows
// carries the same id and finds the refund Mollie made by its metadata (or Mollie dedupes the POST).
$attempt = $client->attempt_persisted_at_post;
expect( is_string( $attempt ) && '' !== $attempt && $attempt === $orders[456]->meta[ RefundReconciler::META_ATTEMPT_ID ], 'the attempt id is saved before the refund is posted' );
expect( array( $attempt ) === array_slice( $client->post_keys, -1 ), 'the refund POST carries the attempt id as its Idempotency-Key' );
expect( 1 === count( $GLOBALS['scheduled'] ) && RefundReconciler::REASK_HOOK === $GLOBALS['scheduled'][0][1] && array( 456, 'tr_explicit', '5.00', 1 ) === $GLOBALS['scheduled'][0][2], 'an ask is scheduled for the record' );
expect( 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'unconfirmed' ), 'the order says the refund is unconfirmed' );
// An unanswered READ before the POST created nothing: an error, so the merchant sees it; no ask, no note.
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array(); $orders[456] = new WC_Order_Refund();
$client->status = 'refunded'; $client->list_throws = new WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException( 'rate limited' ); $client->calls = array();
$result = $provider->refund( $row, 456, '5.00' );
expect( is_wp_error( $result ) && 'wcpos_provider_error' === $result->get_error_code() && array() === $GLOBALS['scheduled'] && array() === $orders[123]->notes && 2 === count( $client->calls ), 'an unanswered read before the POST is an error: nothing created, nothing scheduled' );
$client->list_throws = null;
// The scheduled ask: a fresh read of the record carries the saved attempt id and adopts the refund Mollie made.
$fresh = new WC_Order_Refund(); $fresh->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); $orders[456] = $fresh;
$client->refunds = array( array( 'id' => 're_lost', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => $attempt ) ) );
$client->calls = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 1, $client );
expect( 2 === count( $client->calls ) && 're_lost' === $orders[456]->meta[ RefundReconciler::META_MOLLIE_REFUND_ID ] && 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'confirmed with Mollie (refund re_lost, refunded)' ), 'the ask adopts the refund Mollie made and posts nothing' );
// Mollie silent again: the ask waits another step, and gives up with a note after the last.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt );
$client->refunds = array(); $client->status = new WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException( 'Response lost' );
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( array( 456, 'tr_explicit', '5.00', 3 ) === ( $GLOBALS['scheduled'][0][2] ?? null ), 'a silent Mollie schedules the next ask' );
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', RefundReconciler::REASK_LIMIT, $client );
expect( array() === $GLOBALS['scheduled'] && 1 === count( $orders[123]->notes ) && false !== strpos( end( $orders[123]->notes ), 'Check it in the Mollie dashboard' ), 'after the last ask staff are told to check the dashboard' );
$client->refunds = array();
$orders[456] = new WC_Order_Refund();
$orders[456]->meta[ RefundReconciler::META_ATTEMPT_ID ] = 'attempt';
$client->refunds = array( array( 'id' => 're_found', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => 'attempt' ) ) );
$client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_found' ) === $provider->refund( $row, 456, '5.00' ) && 2 === count( $client->calls ), 'metadata match reused' );
$client->refunds = array();
$orders[456] = new WC_Order_Refund();
$client->status = new RuntimeException( 'offline' );
expect( 502 === $provider->refund( $row, 456, '5.00' )->get_error_data()['status'], 'refund exception converted' );
$row['provider_refs'] = array();
$r = $provider->refund( $row, 456, '5.00' );
expect( 'wcpos_provider_error' === $r->get_error_code() && 'missing_payment_ref' === $r->get_error_data()['detail']['code'], 'missing action refused' );
unset( $orders[456] );
expect( 'wcpos_refund_not_found' === $provider->refund( $row, 456, '5.00' )->get_error_code(), 'missing refund refused' );
echo "server-refund ok\n";
