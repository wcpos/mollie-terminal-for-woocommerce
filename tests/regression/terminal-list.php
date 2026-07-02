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

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

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

// --- selectable_terminals hides inactive terminals and applies the allowlist -
$normalized = array(
	array( 'id' => 'term_A', 'label' => 'Front desk', 'status' => 'active' ),
	array( 'id' => 'term_B', 'label' => 'Retired', 'status' => 'inactive' ),
	array( 'id' => 'term_C', 'label' => 'Broken', 'status' => 'disabled' ),
	array( 'id' => 'term_D', 'label' => 'Spare', 'status' => 'active' ),
	array( 'id' => 'term_E', 'label' => 'New', 'status' => 'pending' ),
);
$selectable = AjaxHandler::selectable_terminals( $normalized );
expect( array( 'term_A', 'term_D', 'term_E' ) === array_column( $selectable, 'id' ), 'inactive and disabled terminals should be hidden from selection' );

$allowlisted = AjaxHandler::selectable_terminals( $normalized, array( 'term_D' ) );
expect( array( 'term_D' ) === array_column( $allowlisted, 'id' ), 'the enabled-terminals allowlist should limit selectable terminals' );

$unrestricted = AjaxHandler::selectable_terminals( $normalized, array() );
expect( 3 === count( $unrestricted ), 'an empty allowlist should allow all non-inactive terminals' );

// --- Settings: enabled terminals + lock semantics ----------------------------
$settings = new Settings( array( 'enabled_terminals' => array( 'term_A', '', 'term_D' ) ) );
expect( array( 'term_A', 'term_D' ) === $settings->enabled_terminal_ids(), 'enabled_terminal_ids should drop empty entries' );
$settings = new Settings( array( 'enabled_terminals' => '' ) );
expect( array() === $settings->enabled_terminal_ids(), 'an empty multiselect value means no restriction' );
$settings = new Settings( array( 'enabled_terminals' => array( 'term_B' ), 'default_terminal_id' => 'term_A' ) );
expect( array( 'term_B', 'term_A' ) === $settings->enabled_terminal_ids(), 'the default terminal is always part of a configured allowlist' );
$settings = new Settings( array( 'enabled_terminals' => array(), 'default_terminal_id' => 'term_A' ) );
expect( array() === $settings->enabled_terminal_ids(), 'no restriction stays unrestricted even with a default terminal' );
$settings = new Settings( array( 'lock_terminal' => 'yes', 'default_terminal_id' => 'term_A' ) );
expect( true === $settings->lock_terminal(), 'lock_terminal should be active with a default terminal set' );
$settings = new Settings( array( 'lock_terminal' => 'yes', 'default_terminal_id' => '' ) );
expect( false === $settings->lock_terminal(), 'lock_terminal must be ignored without a default terminal' );

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
