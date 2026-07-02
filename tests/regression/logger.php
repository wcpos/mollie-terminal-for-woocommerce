<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

// Capture everything that would go to the WooCommerce status logs.
$log_calls = array();
class CapturingWooLogger {
	public function log( $level, $message, $context = array() ) {
		global $log_calls;
		$log_calls[] = array( 'level' => $level, 'message' => $message, 'context' => $context );
	}
}
function wc_get_logger() { return new CapturingWooLogger(); }
function wp_json_encode( $value ) { return json_encode( $value ); }

// The Logger must never touch the options table. Record any write so we fail.
$option_writes = array();
function update_option( $key, $value, $autoload = null ) { global $option_writes; $option_writes[] = $key; return true; }
function add_option( $key, $value = '', $d = '', $a = null ) { global $option_writes; $option_writes[] = $key; return true; }

require_once __DIR__ . '/../../includes/Logger.php';

use WCPOS\WooCommercePOS\MollieTerminal\Logger;

expect( 'mollie-terminal-for-woocommerce' === Logger::WC_LOG_FILENAME, 'log source should match the plugin slug (WCPOS terminal convention)' );

$secret = 'live_abcdefghijklmnopqrstuvwxyz123456';

Logger::log_api_error(
	'Mollie API error for Bearer ' . $secret,
	array( 'api_key' => $secret, 'payment_id' => 'tr_123', 'nested' => array( 'token' => 'Bearer ' . $secret ) )
);

expect( count( $log_calls ) === 1, 'log_api_error should write exactly one WC log line' );
$call = $log_calls[0];
expect( 'error' === $call['level'], 'API errors should log at error level' );
expect( 'mollie-terminal-for-woocommerce' === ( $call['context']['source'] ?? '' ), 'logs should use the plugin log source' );
expect( false !== strpos( $call['message'], 'Mollie API error' ), 'the safe message should be logged' );
expect( false === strpos( $call['message'], $secret ), 'secrets must be redacted from the message' );
expect( false !== strpos( $call['message'], 'live_***' ) || false !== strpos( $call['message'], 'Bearer ***' ), 'redaction marker should be present' );
expect( false !== strpos( $call['message'], '"api_key":"***"' ), 'sensitive context keys should be replaced with ***' );

// "success" is an internal level; WC_Logger only understands PSR-3 levels.
$log_calls = array();
Logger::log( 'Mollie terminal payment created.', array(), 'success' );
expect( 'info' === $log_calls[0]['level'], 'the internal success level should map to info for WC_Logger' );

// Default level is info (matches the sibling terminal loggers).
$log_calls = array();
Logger::log( 'plain message' );
expect( 'info' === $log_calls[0]['level'], 'the default level should be info' );

// Nothing should ever be written to the options table.
expect( array() === $option_writes, 'Logger must not write to the options table' );

echo "logger ok\n";
