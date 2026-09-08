<?php
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
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/RefundReconciler.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\RefundReconciler;
class RefundOrder {
	public function get_id() { return 123; }
	public function get_transaction_id() { return ''; }
	public function get_meta( $key ) { return ''; }
	public function get_currency() { return 'EUR'; }
}
class WC_Order_Refund {
	public $meta = array();
	public function get_id() { return 456; }
	public function get_reason() { return 'Returned item'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
}
class RefundClient extends MollieApiClient {
	public $status;
	public $calls = array();
	public $refunds = array();
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array { $this->calls[] = $payment_id; return array( 'amount' => array( 'value' => '30.00' ) ); }
	public function list_refunds( string $payment_id ): array { $this->calls[] = $payment_id; return $this->refunds; }
	public $refreshed = null;
	public function get_refund( string $payment_id, string $refund_id ): array {
		$this->calls[] = 'refund:' . $refund_id;
		if ( $this->refreshed instanceof Exception ) { throw $this->refreshed; }
		return array( 'id' => $refund_id, 'status' => $this->refreshed );
	}
	public function create_refund( string $payment_id, array $payload ): array {
		$this->calls[] = $payment_id;
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
