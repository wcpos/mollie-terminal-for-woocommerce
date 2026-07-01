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

class JsonResponseForTerminalList extends Error {
	public $data;
	public $status;
	public function __construct( $data, int $status ) { parent::__construct( 'json response', $status ); $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForTerminalList( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForTerminalList( $data, (int) $status_code ); }

require_once __DIR__ . '/../../includes/Diagnostics.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;

// --- normalize_terminals maps Mollie payloads onto compact frontend items ----
$raw = array(
	array( 'id' => 'term_A', 'description' => 'Front desk', 'status' => 'active', 'mode' => 'live' ),
	array( 'id' => 'term_B', 'brand' => 'PAX', 'status' => 'inactive' ),
	array( 'description' => 'no id, dropped' ),
	'not-an-array',
);
$items = AjaxHandler::normalize_terminals( $raw );
expect( 2 === count( $items ), 'terminals without an id (or non-arrays) should be dropped' );
expect( 'term_A' === $items[0]['id'], 'first terminal id should be preserved' );
expect( 'Front desk' === $items[0]['label'], 'description should be used as the label' );
expect( 'live' === $items[0]['mode'], 'mode should be preserved' );
expect( 'PAX' === $items[1]['label'], 'brand should be used as the label when description is missing' );
expect( 'inactive' === $items[1]['status'], 'status should be preserved' );

// --- the endpoint rejects requests without a valid order token ---------------
$_POST = array( 'order_id' => '123', 'order_token' => 'invalid-token' );
$handler = new AjaxHandler();
try {
	$handler->mtfwc_list_terminals();
	fwrite( STDERR, "Expected unauthorized terminal-list request to be rejected\n" );
	exit( 1 );
} catch ( JsonResponseForTerminalList $response ) {
	expect( 403 === $response->status, 'unauthorized terminal-list request should return 403' );
}

echo "terminal-list ok\n";
