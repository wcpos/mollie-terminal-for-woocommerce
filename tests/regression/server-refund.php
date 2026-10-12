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
		$this->attempt_persisted_at_post = $GLOBALS['orders'][456]->saved[ RefundReconciler::META_ATTEMPT_ID . ':' . $payment_id ] ?? null;
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
	expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->refund( $row, 456, '5.00' ) && 'refunded' === $orders[456]->meta[ RefundReconciler::META_STATUS . ':tr_explicit' ], 'replay refreshes a settled refund: ' . $status );
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
expect( is_string( $attempt ) && '' !== $attempt && $attempt === $orders[456]->meta[ RefundReconciler::META_ATTEMPT_ID . ':tr_explicit' ], 'the attempt id is saved before the refund is posted' );
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
$fresh = new WC_Order_Refund(); $fresh->meta = array( RefundReconciler::META_ATTEMPT_ID . ':tr_explicit' => $attempt, RefundReconciler::META_LEGS => '1' ); $orders[456] = $fresh;
$client->refunds = array( array( 'id' => 're_lost', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => $attempt ) ) );
$client->calls = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 1, $client );
expect( 2 === count( $client->calls ) && 're_lost' === $orders[456]->meta[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' ] && 're_lost' === $orders[456]->meta[ RefundReconciler::META_MOLLIE_REFUND_ID ] && 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'confirmed with Mollie (refund re_lost, refunded)' ), 'the ask adopts the refund Mollie made and posts nothing; the plain id is kept for the old panel' );
// Another operation holds the order when the ask fires: the try is not spent, the ask is rescheduled.
$client->refunds = array();
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); // a record from before legs were kept apart
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
$wpdb->rows['mtfwc_lock_order_123_refund'] = json_encode( array( 'token' => 'other', 'expires_at' => time() + 60 ) );
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( array( 456, 'tr_explicit', '5.00', 2 ) === ( $GLOBALS['scheduled'][0][2] ?? null ) && array() === $orders[123]->notes, 'a held order lock reschedules the same try without a note' );
unset( $wpdb->rows['mtfwc_lock_order_123_refund'] );
// The database refusing the lock spends a try, so a persistent fault ends with the dashboard note.
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array(); $wpdb->insert_error = true;
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( array( 456, 'tr_explicit', '5.00', 3 ) === ( $GLOBALS['scheduled'][0][2] ?? null ) && array() === $orders[123]->notes, 'a database error on the lock spends the try' );
$wpdb->insert_error = false;
// Mollie answers the re-ask with a refusal: no refund was made, the note says so.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); // a record from before legs were kept apart
$client->status = new RuntimeException( 'The refund amount exceeds the refundable amount' );
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( array() === $GLOBALS['scheduled'] && 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'no refund was made' ), 'a refusal on the re-ask is noted as a refusal, not an unconfirmed refund' );
// A failed read on the re-ask (a key Mollie rejects) proves nothing about the first POST: unconfirmed, never "no refund".
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); // a record from before legs were kept apart
$client->status = 'refunded'; $client->list_throws = new RuntimeException( 'Missing authentication, or failed to authenticate' );
$GLOBALS['scheduled'] = array(); $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( array() === $GLOBALS['scheduled'] && 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'could not be confirmed' ) && false === strpos( $orders[123]->notes[0], 'no refund was made' ), 'a failed read on the re-ask is unconfirmed, not a refusal' );
// An amount that no longer fits (staff refunded in the dashboard meanwhile) is caught, noted as unconfirmed, and never escapes the cron.
$client->list_throws = new InvalidArgumentException( 'Refund amount exceeds the remaining balance' );
$orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'could not be confirmed' ), 'an amount error is caught and noted as unconfirmed' );
$client->list_throws = null;
// Mollie reports the refund failed: the note says failed, not confirmed.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); // a record from before legs were kept apart
$client->status = 'failed'; $orders[123]->notes = array();
RefundReconciler::reask( 456, 'tr_explicit', '5.00', 2, $client );
expect( 1 === count( $orders[123]->notes ) && false !== strpos( $orders[123]->notes[0], 'as failed' ), 'a failed refund is noted as failed' );
// Mollie silent again: the ask waits another step, and gives up with a note after the last.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => $attempt ); // a record from before legs were kept apart
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
// One refund record across two Mollie payments (the POS allocates one refund over its rows): each leg is
// its own POST under its own attempt id, and neither leg reads the other's refund back as its own.
$orders[456] = new WC_Order_Refund(); $client->refunds = array(); $client->status = 'queued'; $client->calls = array(); $client->post_keys = array();
expect( array( 'status' => 'pending', 'provider_ref' => 're_x' ) === $provider->refund( array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_a' ) ), 456, '5.00' ), 'leg A posted' );
$client->refunds = array(); // tr_b lists nothing: re_x belongs to tr_a
expect( array( 'status' => 'pending', 'provider_ref' => 're_x' ) === $provider->refund( array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_b' ) ), 456, '5.00' ), 'leg B posted too' );
expect( array( 'tr_a', 'tr_a', 'tr_a', 'tr_b', 'tr_b', 'tr_b' ) === $client->calls, 'the second leg is a POST of its own, not a re-read of the first leg' );
expect( 2 === count( $client->post_keys ) && $client->post_keys[0] !== $client->post_keys[1], 'each leg carries its own Idempotency-Key' );
expect( $client->post_keys[0] === $orders[456]->meta[ RefundReconciler::META_ATTEMPT_ID . ':tr_a' ] && $client->post_keys[1] === $orders[456]->meta[ RefundReconciler::META_ATTEMPT_ID . ':tr_b' ], 'the attempt ids are kept per payment' );
expect( '1' === $orders[456]->meta[ RefundReconciler::META_LEGS ] && 're_x' === $orders[456]->meta[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_a' ] && 're_x' === $orders[456]->meta[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_b' ], 'the refund ids are kept per payment' );
// A replay of leg A refreshes leg A's refund against payment A only.
$client->refreshed = 'refunded'; $client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->refund( array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_a' ) ), 456, '5.00' ) && array( 'refund:re_x' ) === $client->calls && 'refunded' === $orders[456]->meta[ RefundReconciler::META_STATUS . ':tr_a' ] && 'queued' === $orders[456]->meta[ RefundReconciler::META_STATUS . ':tr_b' ], 'a replay refreshes its own leg only' );
$client->refunds = array();
$orders[456] = new WC_Order_Refund();
$client->status = new RuntimeException( 'offline' );
expect( 502 === $provider->refund( $row, 456, '5.00' )->get_error_data()['status'], 'refund exception converted' );
$row['provider_refs'] = array();
$r = $provider->refund( $row, 456, '5.00' );
expect( 'wcpos_provider_error' === $r->get_error_code() && 'missing_payment_ref' === $r->get_error_data()['detail']['code'], 'missing action refused' );
unset( $orders[456] );
expect( 'wcpos_refund_not_found' === $provider->refund( $row, 456, '5.00' )->get_error_code(), 'missing refund refused' );

// --- Pro's refund sweep: fetch_refund() reads back a pending allocation and never makes a refund.
$row = array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_explicit' ) );
$pending = array( 'id' => 456, 'amount' => '5.00', 'status' => 'pending', 'provider_ref' => 're_x' );
// By reference: Mollie has processed it.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_MOLLIE_REFUND_ID => 're_x', RefundReconciler::META_STATUS => 'queued' ); // a record from before legs
$client->refreshed = 'refunded'; $client->calls = array(); $client->post_keys = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->fetch_refund( $row, $pending ) && array( 'refund:re_x' ) === $client->calls, 'a pending allocation is read back by its reference and settles' );
expect( 'refunded' === $orders[456]->saved[ RefundReconciler::META_STATUS . ':tr_explicit' ] && 're_x' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' ], 'the record keeps the status Mollie reported, per payment' );
foreach ( array( 'queued' => 'pending', 'processing' => 'pending', 'failed' => 'failed', 'canceled' => 'failed', 'something_new' => 'pending' ) as $mollie => $pro ) {
	$client->refreshed = $mollie;
	expect( array( 'status' => $pro, 'provider_ref' => 're_x' ) === $provider->fetch_refund( $row, $pending ), 'fetch_refund maps ' . $mollie );
}
// Mollie silent: indeterminate, the sweep keeps the order marked and asks next run.
$client->refreshed = new WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException( 'timed out' );
$r = $provider->fetch_refund( $row, $pending );
expect( is_wp_error( $r ) && true === $r->get_error_data()['indeterminate'] && 'something_new' === $orders[456]->saved[ RefundReconciler::META_STATUS . ':tr_explicit' ], 'an unanswered read is indeterminate and changes nothing' );
// The record's own reference for this payment wins over a stale allocation reference.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' => 're_record', RefundReconciler::META_LEGS => '1' );
$client->refreshed = 'refunded'; $client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_record' ) === $provider->fetch_refund( $row, $pending ) && array( 'refund:re_record' ) === $client->calls && 're_record' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' ], 'the record\'s reference for the payment wins over the allocation\'s' );
// Mollie refuses the read (a key it rejects): an error without "indeterminate", still not a settlement.
$client->refreshed = new RuntimeException( 'Missing authentication' );
$r = $provider->fetch_refund( $row, $pending );
expect( is_wp_error( $r ) && 'wcpos_provider_error' === $r->get_error_code() && ! isset( $r->get_error_data()['indeterminate'] ), 'a refused read is a provider error, not a settlement' );
// The refund and its scheduled ask hold the order: not an answer from Mollie.
$wpdb->rows['mtfwc_lock_order_123_refund'] = json_encode( array( 'token' => 'other', 'expires_at' => time() + 60 ) );
$client->refreshed = 'refunded'; $client->calls = array();
$r = $provider->fetch_refund( $row, $pending );
expect( is_wp_error( $r ) && true === $r->get_error_data()['indeterminate'] && array() === $client->calls, 'a held order lock is indeterminate and Mollie is not asked' );
unset( $wpdb->rows['mtfwc_lock_order_123_refund'] );
// No reference on the allocation (the POST went unanswered): found by the metadata the POST carried,
// stored on the record, and handed back while still queued. Nothing is posted.
$unreferenced = array( 'id' => 456, 'amount' => '5.00', 'status' => 'pending', 'provider_ref' => null );
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => 'attempt_lost' );
$client->refunds = array(
	array( 'id' => 're_other', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '999', 'refund_attempt_id' => 'attempt_lost' ) ),
	array( 'id' => 're_stale', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => 'attempt_older' ) ),
	array( 'id' => 're_lost', 'status' => 'queued', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => 'attempt_lost' ) ),
);
$client->calls = array(); $client->post_keys = array();
expect( array( 'status' => 'pending', 'provider_ref' => 're_lost' ) === $provider->fetch_refund( $row, $unreferenced ), 'a reference-less allocation is found by record, order and attempt id, and the reference comes back while pending' );
expect( array( 'tr_explicit' ) === $client->calls && array() === $client->post_keys, 'one list, no POST' );
expect( 're_lost' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' ] && 'queued' === $orders[456]->saved[ RefundReconciler::META_STATUS . ':tr_explicit' ] && 're_lost' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID ], 'the record learns the refund Mollie made' );
// Another order's refund with this record id in its metadata is not this record's.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => 'attempt_lost' );
$client->refunds = array( array( 'id' => 're_foreign', 'status' => 'refunded', 'metadata' => array( 'order_id' => '124', 'woo_refund_id' => '456', 'refund_attempt_id' => 'attempt_lost' ) ) );
expect( array( 'status' => 'pending', 'provider_ref' => null ) === $provider->fetch_refund( $row, $unreferenced ), 'a refund naming another order is not matched' );
// A split record: leg A has no reference yet, leg B's refund id is on the record; leg A is found by its
// own metadata on its own payment, never read through leg B's id.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_LEGS => '1', RefundReconciler::META_ATTEMPT_ID . ':tr_a' => 'attempt_a', RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_b' => 're_b', RefundReconciler::META_MOLLIE_REFUND_ID => 're_b' );
$client->refunds = array( array( 'id' => 're_a', 'status' => 'queued', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456', 'refund_attempt_id' => 'attempt_a' ) ) );
$client->calls = array();
expect( array( 'status' => 'pending', 'provider_ref' => 're_a' ) === $provider->fetch_refund( array( 'order_id' => 123, 'provider_refs' => array( 'action' => 'tr_a' ) ), $unreferenced ) && array( 'tr_a' ) === $client->calls && 're_a' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_a' ] && 're_b' === $orders[456]->saved[ RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_b' ], 'a split record\'s leg is found on its own payment, and the other leg\'s id is untouched' );
// Next run the allocation carries the reference: read by it.
$client->refreshed = 'refunded'; $client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_lost' ) === $provider->fetch_refund( $row, array( 'id' => 456, 'amount' => '5.00', 'status' => 'pending', 'provider_ref' => 're_lost' ) ) && array( 'refund:re_lost' ) === $client->calls, 'the learned reference is read directly' );
// A record whose scheduled ask already found the refund: the record's id is used even when the allocation has none.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_MOLLIE_REFUND_ID . ':tr_explicit' => 're_by_ask', RefundReconciler::META_LEGS => '1' );
$client->refreshed = 'refunded'; $client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_by_ask' ) === $provider->fetch_refund( $row, $unreferenced ) && array( 'refund:re_by_ask' ) === $client->calls, 'the reference the scheduled ask stored is used' );
// Mollie lists nothing for the record: still pending, no reference, nothing posted (the scheduled ask owns making it).
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_ATTEMPT_ID => 'attempt_lost' );
$client->refunds = array(); $client->calls = array(); $client->post_keys = array();
expect( array( 'status' => 'pending', 'provider_ref' => null ) === $provider->fetch_refund( $row, $unreferenced ) && array( 'tr_explicit' ) === $client->calls && array() === $client->post_keys, 'an unlisted refund stays pending and nothing is posted' );
// A record from before attempt ids matches on order and record alone.
$orders[456] = new WC_Order_Refund();
$client->refunds = array( array( 'id' => 're_old', 'status' => 'refunded', 'metadata' => array( 'order_id' => '123', 'woo_refund_id' => '456' ) ) );
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_old' ) === $provider->fetch_refund( $row, $unreferenced ), 'a record without an attempt id matches on order and record' );
// A historical stand-in row names the Mollie payment as its transaction reference.
$orders[456] = new WC_Order_Refund(); $orders[456]->meta = array( RefundReconciler::META_MOLLIE_REFUND_ID => 're_x' );
$client->refreshed = 'refunded'; $client->calls = array();
expect( array( 'status' => 'succeeded', 'provider_ref' => 're_x' ) === $provider->fetch_refund( array( 'provider_refs' => array( 'transaction_id' => 'tr_hist' ) ), $pending ) && array( 'refund:re_x' ) === $client->calls, 'a historical row is read through its transaction reference' );
// No payment reference, no record: refused without asking Mollie.
$client->calls = array();
expect( 'missing_payment_ref' === $provider->fetch_refund( array( 'provider_refs' => array() ), $pending )->get_error_data()['detail']['code'] && array() === $client->calls, 'a row without a payment reference is refused' );
unset( $orders[456] );
expect( 'wcpos_refund_not_found' === $provider->fetch_refund( $row, $pending )->get_error_code(), 'a missing record is refused' );
echo "server-refund ok\n";
