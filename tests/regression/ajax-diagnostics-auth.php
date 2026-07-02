<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$options = array();
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability, $object_id = null ) { return false; }
function __( $text, $domain = null ) { return $text; }
function wp_hash( $data ) { return hash( 'sha256', $data ); }
function wp_salt( $scheme = '' ) { return 'test-salt'; }
function wp_doing_ajax() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }

// Capture anything that would be written to the WooCommerce status logs.
$log_calls = array();
class CapturingWooLoggerForAuth {
	public function log( $level, $message, $context = array() ) { global $log_calls; $log_calls[] = $message; }
}
function wc_get_logger() { return new CapturingWooLoggerForAuth(); }

class JsonResponseForAjaxDiagnostics extends Error {
	public $data;
	public $status;
	public function __construct( $data, int $status ) { parent::__construct( 'json response', $status ); $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForAjaxDiagnostics( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForAjaxDiagnostics( $data, (int) $status_code ); }

require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;

$_POST = array( 'order_id' => '123', 'order_token' => 'invalid-token' );
$handler = new AjaxHandler();

try {
	$handler->mtfwc_poll_payment();
	fwrite( STDERR, "Expected AJAX error response\n" );
	exit( 1 );
} catch ( JsonResponseForAjaxDiagnostics $response ) {
	expect( 403 === $response->status, 'unauthorized AJAX request should be rejected' );
}

expect( array() === $log_calls, 'unauthorized AJAX requests should not log any diagnostics' );

echo "ajax-diagnostics-auth ok\n";
