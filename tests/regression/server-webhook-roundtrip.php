<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
function wcpos_settle_payment( $id, $patch ) { $GLOBALS['settlements'][] = array( $id, $patch ); return true; }
class WebhookClient extends MollieApiClient {
	public $payment;
	public $calls = 0;
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array {
		++$this->calls;
		expect( 'tr_x' === $payment_id, 'fetch the delivered id' );
		if ( $this->payment instanceof Exception ) { throw $this->payment; }
		return $this->payment;
	}
}
$client = new WebhookClient();
$provider = new Provider( null, $client );
$uuid = 'ABCDEF12-1234-1234-ABCD-123456789ABC';
$paid = array( 'id' => 'tr_x', 'status' => 'paid', 'mode' => 'test', 'amount' => array( 'value' => '12.50', 'currency' => 'EUR' ), 'metadata' => array( 'wcpos_payment_id' => $uuid ), 'details' => array( 'cardLabel' => 'Visa' ) );
$client->payment = $paid;
$request = new WP_REST_Request();
$request->set_param( 'id', 'tr_x' );
$r = $provider->verify_webhook( $request );
expect( ! is_wp_error( $r ), 'paid webhook verified' );
wcpos_settle_payment( $r['payment_id'], $r['patch'] );
expect( strtolower( $uuid ) === $settlements[0][0], 'UUID lowercased' );
expect( array( 'event_id' => 'tr_x:paid', 'status' => 'captured', 'amount' => '12.50', 'currency' => 'EUR', 'receipt' => array( 'card_label' => 'Visa', 'mollie_payment' => 'tr_x' ) ) === $settlements[0][1], 'money patch and receipt without provider_refs' );
foreach ( array( 'open', 'pending', 'authorized', 'canceled', 'expired', 'failed', 'unknown' ) as $status ) {
	$client->payment['status'] = $status;
	$r = $provider->verify_webhook( $request );
	wcpos_settle_payment( $r['payment_id'], $r['patch'] );
	expect( array( 'event_id' => 'tr_x:' . $status ) === $r['patch'], 'non-money webhook cannot force lifecycle: ' . $status );
}
$calls = $client->calls;
$request->set_param( 'id', 'tr_../invalid' );
$r = $provider->verify_webhook( $request );
expect( 'mollie_webhook_invalid_id' === $r->get_error_code() && 400 === $r->get_error_data()['status'] && $calls === $client->calls, 'malformed id rejected before fetch' );
$request->set_param( 'id', 'tr_x' );
$client->payment = $paid;
$client->payment['mode'] = 'live';
$r = $provider->verify_webhook( $request );
expect( 'mollie_webhook_mode_mismatch' === $r->get_error_code() && 403 === $r->get_error_data()['status'], 'wrong key mode rejected' );
$client->payment = $paid;
foreach ( array( array(), array( 'wcpos_payment_id' => 'not-a-uuid' ) ) as $metadata ) {
	$client->payment['metadata'] = $metadata;
	$r = $provider->verify_webhook( $request );
	expect( 'mollie_webhook_unknown_payment' === $r->get_error_code() && 404 === $r->get_error_data()['status'], 'legacy/invalid UUID rejected' );
}
$client->payment = new RuntimeException( 'offline' );
$r = $provider->verify_webhook( $request );
expect( 'wcpos_provider_error' === $r->get_error_code() && 502 === $r->get_error_data()['status'], 'transport failure' );
echo "server-webhook-roundtrip ok\n";
