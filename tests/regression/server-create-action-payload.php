<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
class PayloadOrder {
	public function get_id() { return 123; }
	public function get_total() { return '99.99'; }
	public function get_order_number() { return 'SHOP123'; }
	public function get_checkout_order_received_url() { return 'https://shop.example/order/123'; }
}
class PayloadClient extends MollieApiClient {
	public $calls = array();
	public $error;
	public function __construct() {}
	public function create_payment( array $payload, array $include = array(), string $idempotency_key = '' ): array {
		$this->calls[] = array( $payload, $include, $idempotency_key );
		if ( $this->error ) { throw $this->error; }
		return array( 'id' => 'tr_x', 'expiresAt' => '2026-09-08T12:00:00Z' );
	}
}
$orders[123] = new PayloadOrder();
$client = new PayloadClient();
$provider = new Provider( new Settings( array( 'mode' => 'live' ) ), $client );
$row = array( 'id' => '12345678-1234-1234-abcd-123456789abc', 'order_id' => 123, 'amount' => '12.34', 'currency' => 'EUR' );
$result = $provider->create_reader_action( $row, 'term_A' );
list( $payload, $include, $key ) = $client->calls[0];
expect( array( 'currency' => 'EUR', 'value' => '12.34' ) === $payload['amount'], 'charge leg amount, not order total' );
expect( 'pointofsale' === $payload['method'] && 'term_A' === $payload['terminalId'], 'terminal dispatch' );
expect( array( 'wcpos_payment_id' => $row['id'], 'order_id' => '123', 'terminal_id' => 'term_A' ) === $payload['metadata'], 'ledger metadata' );
expect( $row['id'] === $key, 'idempotency uses row UUID' );
expect( 'Order #SHOP123' === $payload['description'] && 'https://shop.example/order/123' === $payload['redirectUrl'], 'order description and redirect' );
expect( false !== strpos( $payload['webhookUrl'], 'wcpos/v2/payments/webhook' ) && false !== strpos( $payload['webhookUrl'], 'provider=mollie' ), 'Pro webhook route' );
expect( array( 'ref' => 'tr_x', 'expires_at' => '2026-09-08T12:00:00Z' ) === $result, 'action response' );
$row['currency'] = 'GBP';
$result = $provider->create_reader_action( $row, 'term_A' );
expect( is_wp_error( $result ) && 400 === $result->get_error_data()['status'] && 'currency_unsupported' === $result->get_error_data()['detail']['code'] && 1 === count( $client->calls ), 'unsupported currency never calls Mollie' );
$row['currency'] = 'EUR';
$client->error = new InvalidArgumentException( 'bad request' );
$result = $provider->create_reader_action( $row, 'term_A' );
expect( is_wp_error( $result ) && 502 === $result->get_error_data()['status'], 'client exceptions become provider errors' );
unset( $orders[123] );
expect( 'wcpos_order_not_found' === $provider->create_reader_action( $row, 'term_A' )->get_error_code(), 'missing order' );
expect( 'mollie' === $provider->provider(), 'provider family' );
expect( array( 'capabilities' => array( 'tips' => 'on_reader', 'refunds' => array( 'via' => 'provider', 'partial' => true ), 'void' => true ), 'provider_data' => array( 'mode' => 'live' ) ) === $provider->describe( new WC_Payment_Gateway() ), 'descriptor enables on-reader tips' );
expect( 501 === $provider->capture( 'tr_x' )->get_error_data()['status'], 'manual capture stays unsupported' );

// Exercise the real HTTP client too: passing the UUID to an override alone does not prove the header.
function wp_json_encode( $value ) { return json_encode( $value ); }
class PayloadLogger { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new PayloadLogger(); }
function wp_remote_request( $url, $args ) { $GLOBALS['http'][] = array( $url, $args ); return array(); }
function wp_remote_retrieve_response_code( $response ) { return 201; }
function wp_remote_retrieve_body( $response ) { return '{"id":"tr_x"}'; }
require_once __DIR__ . '/../../includes/Logger.php';
$real = new MollieApiClient( 'test_fixture' );
$real->create_payment( $payload, array(), $row['id'] );
$real->create_payment( $payload );
expect( $row['id'] === $http[0][1]['headers']['Idempotency-Key'], 'HTTP idempotency header' );
expect( ! isset( $http[1][1]['headers']['Idempotency-Key'] ), 'legacy requests omit idempotency header' );
expect( 'Bearer test_fixture' === $http[0][1]['headers']['Authorization'], 'auth header preserved' );
echo "server-create-action-payload ok\n";
