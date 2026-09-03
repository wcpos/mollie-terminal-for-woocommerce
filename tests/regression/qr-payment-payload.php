<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'attempt-uuid'; }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForQrPayload { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForQrPayload(); }
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
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

class FakeOrderForQrPayload {
	public $meta = array();
	public function is_paid() { return false; }
	public function get_id() { return 12345; }
	public function get_total() { return '12.34'; }
	public function get_currency() { return 'EUR'; }
	public function get_order_number() { return 'QR-123'; }
	public function get_checkout_order_received_url() { return 'https://webshop.example.org/order/12345/'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
}

class CapturingQrMollieClient extends MollieApiClient {
	public $payload = array();
	public $include = array();
	public function __construct() {}
	public function create_payment( array $payload, array $include = array() ): array {
		$this->payload = $payload;
		$this->include = $include;
		return array(
			'id' => 'tr_qr_test',
			'status' => 'open',
			'amount' => $payload['amount'],
			'method' => $payload['method'],
			'mode' => 'live',
			'metadata' => $payload['metadata'],
			'details' => array( 'qrCode' => array( 'src' => 'https://example.test/qr.png', 'width' => 200, 'height' => 200 ) ),
		);
	}
}

$order = new FakeOrderForQrPayload();
$client = new CapturingQrMollieClient();
$settings = new Settings( array( 'mode' => 'live' ) );
$result = ( new MolliePaymentService( $client, $settings ) )->start_qr_payment_for_order( $order, 'ideal' );

expect( 'ideal' === ( $client->payload['method'] ?? '' ), 'the QR payload should use the selected method' );
expect( ! isset( $client->payload['terminalId'] ), 'a QR payload must not contain terminalId' );
expect( 'qr' === ( $client->payload['metadata']['channel'] ?? '' ), 'the QR payload should identify its channel' );
expect( array( 'details.qrCode' ) === $client->include, 'QR creation should request details.qrCode' );
expect( 'https://example.test/qr.png' === ( $result['qr_code']['src'] ?? '' ), 'an open QR payment should return its QR code' );
expect( 'ideal' === ( PaymentAttempt::current( $order )['method'] ?? '' ), 'the QR method should be recorded on the attempt' );

echo "qr-payment-payload ok\n";
