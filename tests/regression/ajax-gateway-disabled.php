<?php
// The checkout AJAX actions must refuse to act when the merchant has switched
// the gateway off in WooCommerce → Payments, even for an otherwise valid
// order token (issue #12).
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$options = array();
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability, $object_id = null ) { return false; }
function __( $text, $domain = null ) { return $text; }
function wp_hash( $data ) { return hash( 'sha256', $data ); }
function wp_salt( $scheme = '' ) { return 'test-salt'; }
function wp_doing_ajax() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function apply_filters( $tag, $value ) { return $value; }

$log_calls = array();
class CapturingWooLoggerForGatewayDisabled { public function log( $level, $message, $context = array() ) { global $log_calls; $log_calls[] = $message; } }
function wc_get_logger() { return new CapturingWooLoggerForGatewayDisabled(); }

// Transient stubs for PaymentLock (cancel takes the per-order lock).
$transients = array();
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); return true; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key ) ); }

// An order with no payment attempt: poll and cancel answer "idle" without
// touching Mollie, which is all we need to prove they were not refused.
class FakeOrderForGatewayDisabled {
	public function get_id() { return 123; }
	public function is_paid() { return false; }
	public function get_meta( $key ) { return null; }
}
function wc_get_order( $id ) { return 123 === (int) $id ? new FakeOrderForGatewayDisabled() : false; }

class JsonResponseForGatewayDisabled extends Error {
	public $data;
	public $status;
	public function __construct( $data, int $status ) { parent::__construct( 'json response', $status ); $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForGatewayDisabled( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForGatewayDisabled( $data, 200 ); }

require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;

function call_action( string $action, array $settings, array $post = array() ): JsonResponseForGatewayDisabled {
	global $options, $log_calls;
	$options   = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => $settings );
	$log_calls = array();
	$_POST     = array_merge( array( 'order_id' => '123', 'order_token' => AjaxHandler::order_token( 123 ) ), $post );
	try {
		( new AjaxHandler() )->$action();
	} catch ( JsonResponseForGatewayDisabled $response ) {
		return $response;
	}
	fwrite( STDERR, "Expected a JSON response from $action\n" );
	exit( 1 );
}

$disabled = array( 'enabled' => 'no', 'qr_methods' => array( 'ideal' ) );
foreach ( array( 'mtfwc_start_payment', 'mtfwc_list_terminals' ) as $action ) {
	$response = call_action( $action, $disabled, array( 'channel' => 'qr', 'qr_method' => 'ideal' ) );
	expect( 403 === $response->status, "$action must be refused while the gateway is disabled" );
	expect( 'Mollie Terminal is disabled.' === $response->data, "$action should say the gateway is disabled" );
}

// Poll and cancel act on a payment that already exists, so they must keep
// working after the gateway is switched off: the cashier can still see it
// settle or cancel it. With no attempt on the order they answer "idle".
foreach ( array( 'mtfwc_poll_payment', 'mtfwc_cancel_payment' ) as $action ) {
	$response = call_action( $action, $disabled );
	expect( 200 === $response->status, "$action must still reach the payment service while the gateway is disabled" );
	expect( 'idle' === ( $response->data['status'] ?? '' ), "$action should report the order's payment state as usual" );
}

// A missing 'enabled' option (never saved) counts as disabled, matching the
// gateway's own default.
expect( 403 === call_action( 'mtfwc_start_payment', array() )->status, 'an unsaved gateway must be treated as disabled' );

// With the gateway enabled the request proceeds past the guard: the QR method
// check is the next gate, so a disabled QR method now yields 400, not 403.
$enabled = call_action( 'mtfwc_start_payment', array( 'enabled' => 'yes' ), array( 'channel' => 'qr', 'qr_method' => 'ideal' ) );
expect( 400 === $enabled->status, 'an enabled gateway must let the request through to the next check' );

echo "ajax-gateway-disabled ok\n";
