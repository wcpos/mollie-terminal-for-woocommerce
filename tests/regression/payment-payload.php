<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'attempt-uuid'; }
function get_transient( $key ) { global $transients; return $transients[$key] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[$key] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[$key] ); }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForPayload { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForPayload(); }
function admin_url( $path = '' ) { return 'https://webshop.example.org/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Diagnostics.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';

use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;

class FakeOrderForPayload {
	public $meta = array();
	public $saved = false;
	public function is_paid() { return false; }
	public function get_id() { return 12345; }
	public function get_total() { return '0.01'; }
	public function get_currency() { return 'EUR'; }
	public function get_order_number() { return 'HOIHOI'; }
	public function get_checkout_order_received_url() { return 'https://webshop.example.org/order/12345/'; }
	public function get_meta( $key ) { return $this->meta[$key] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
	public function save() { $this->saved = true; }
}

class CapturingMollieClient extends MollieApiClient {
	public $created_payload = array();
	public function __construct() {}
	public function create_payment( array $payload ): array {
		$this->created_payload = $payload;
		return array(
			'id' => 'tr_payload_test',
			'status' => 'open',
			'amount' => $payload['amount'],
			'method' => $payload['method'],
			'mode' => 'live',
			'metadata' => $payload['metadata'],
		);
	}
}

class AcceptingTerminalService extends TerminalService {
	public $validated_terminal_id = '';
	public function __construct() {}
	public function validate_terminal( string $terminal_id ): array {
		$this->validated_terminal_id = $terminal_id;
		return array( 'id' => $terminal_id, 'status' => 'active' );
	}
}

$order = new FakeOrderForPayload();
$client = new CapturingMollieClient();
$terminals = new AcceptingTerminalService();
$settings = new Settings( array( 'mode' => 'live', 'profile_id' => 'pfl_should_not_be_sent', 'default_terminal_id' => 'term_default' ) );
$service = new MolliePaymentService( $client, $settings, $terminals );

$service->start_payment_for_order( $order, 'term_qgkudeAxt84erst5vYCTJ' );
$payload = $client->created_payload;
$errors = array();

if ( isset( $payload['profileId'] ) ) { $errors[] = 'profileId must not be sent when creating payments with an API key'; }
if ( ( $payload['redirectUrl'] ?? '' ) !== 'https://webshop.example.org/order/12345/' ) { $errors[] = 'redirectUrl must use the WooCommerce order received URL'; }
if ( ( $payload['method'] ?? '' ) !== 'pointofsale' ) { $errors[] = 'method must be pointofsale'; }
if ( ( $payload['terminalId'] ?? '' ) !== 'term_qgkudeAxt84erst5vYCTJ' ) { $errors[] = 'terminalId must be sent'; }

if ( $errors ) { fwrite( STDERR, implode( "\n", $errors ) . "\n" ); exit( 1 ); }

echo "payment-payload ok\n";
