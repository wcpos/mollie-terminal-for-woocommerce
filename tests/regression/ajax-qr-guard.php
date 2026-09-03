<?php
// The QR start guard: a request may only start a QR payment for a method the
// merchant enabled in the gateway settings, regardless of what the client sends.
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

class SilentWooLoggerForQrGuard { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentWooLoggerForQrGuard(); }

class FakeOrderForQrGuard { public function get_id() { return 123; } public function is_paid() { return false; } }
function wc_get_order( $id ) { return 123 === (int) $id ? new FakeOrderForQrGuard() : false; }

class JsonResponseForQrGuard extends Error {
	public $data;
	public $status;
	public function __construct( $data, int $status ) { parent::__construct( 'json response', $status ); $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForQrGuard( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForQrGuard( $data, 200 ); }

require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;

function start_qr( array $settings, string $method ): JsonResponseForQrGuard {
	global $options;
	$options = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => $settings );
	$_POST   = array( 'order_id' => '123', 'order_token' => AjaxHandler::order_token( 123 ), 'channel' => 'qr', 'qr_method' => $method );
	try {
		( new AjaxHandler() )->mtfwc_start_payment();
	} catch ( JsonResponseForQrGuard $response ) {
		return $response;
	}
	fwrite( STDERR, "Expected a JSON response\n" );
	exit( 1 );
}

$disabled = start_qr( array(), 'ideal' );
expect( 400 === $disabled->status, 'QR start must be rejected when no QR method is enabled' );
expect( 'QR code payments are not enabled for this method.' === $disabled->data, 'the rejection should say QR is not enabled' );

$other = start_qr( array( 'qr_methods' => array( 'bancontact' ) ), 'ideal' );
expect( 400 === $other->status, 'QR start must be rejected for a method the merchant did not enable' );

$bogus = start_qr( array( 'qr_methods' => array( 'ideal', 'bancontact' ) ), 'creditcard' );
expect( 400 === $bogus->status, 'QR start must be rejected for a method that is not a QR method' );

echo "ajax-qr-guard ok\n";
