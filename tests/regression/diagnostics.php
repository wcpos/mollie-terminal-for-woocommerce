<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$options = array();
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[ $key ] = $value; return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class FakeWooLogger { public function info( $message, $context = array() ) {} }
function wc_get_logger() { return new FakeWooLogger(); }

$diagnostics_file = __DIR__ . '/../../includes/Diagnostics.php';
if ( ! file_exists( $diagnostics_file ) ) {
	fwrite( STDERR, "Diagnostics.php is missing\n" );
	exit( 1 );
}

require_once $diagnostics_file;
require_once __DIR__ . '/../../includes/Logger.php';

use WCPOS\WooCommercePOS\MollieTerminal\Diagnostics;
use WCPOS\WooCommercePOS\MollieTerminal\Logger;

$secret = 'live_abcdefghijklmnopqrstuvwxyz123456';
Diagnostics::record_api_error(
	'Mollie API error for Bearer ' . $secret,
	array( 'api_key' => $secret, 'payment_id' => 'tr_123', 'nested' => array( 'token' => 'Bearer ' . $secret ) )
);

$last_error = get_option( 'mtfwc_last_api_error', '' );
expect( false !== strpos( $last_error, 'Mollie API error' ), 'last API error should include the safe message' );
expect( false === strpos( $last_error, $secret ), 'last API error should redact API keys' );
expect( false !== strpos( $last_error, 'live_***' ) || false !== strpos( $last_error, 'Bearer ***' ), 'last API error should show redaction marker' );

$events = get_option( 'mtfwc_recent_diagnostic_events', array() );
expect( count( $events ) === 1, 'record_api_error should append one event' );
expect( 'error' === $events[0]['level'], 'API error event should be level error' );
expect( false === strpos( wp_json_encode( $events[0] ), $secret ), 'diagnostic event should redact secrets' );
expect( '***' === $events[0]['context']['api_key'], 'sensitive context keys should be replaced' );

Logger::log( 'Mollie AJAX failed with Bearer ' . $secret, array( 'terminal_id' => 'term_123' ) );
$events = Diagnostics::recent_events();
expect( count( $events ) === 2, 'Logger::log should append a diagnostic event' );
expect( false === strpos( wp_json_encode( $events ), $secret ), 'logger diagnostic event should be redacted' );

Logger::log( 'Mollie AJAX failed with Bearer ' . $secret, array( '_mtfwc_diagnostics_recorded' => true, 'terminal_id' => 'term_123' ) );
$events = Diagnostics::recent_events();
expect( count( $events ) === 2, 'Logger::log should not duplicate diagnostics when the caller already recorded one' );

for ( $i = 0; $i < 60; $i++ ) {
	Diagnostics::record( 'info', 'event ' . $i );
}
$events = Diagnostics::recent_events();
expect( count( $events ) === 50, 'diagnostic events should be capped at 50' );
expect( 'event 10' === $events[0]['message'], 'diagnostic events should retain the newest entries' );
expect( 'event 59' === $events[49]['message'], 'diagnostic events should keep the most recent event' );

echo "diagnostics ok\n";
