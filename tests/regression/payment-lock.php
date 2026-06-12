<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
$transients = array();
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_generate_uuid4() { return 'uuid'; }
function get_transient( $key ) { global $transients; return $transients[$key] ?? false; }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[$key] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[$key] ); }
require_once __DIR__ . '/../../includes/PaymentLock.php';
use WCPOS\WooCommercePOS\MollieTerminal\PaymentLock;

expect( PaymentLock::acquire( 123, 'create_payment' ) === true );
expect( PaymentLock::acquire( 123, 'create_payment' ) === false );
PaymentLock::release( 123, 'create_payment' );
expect( PaymentLock::acquire( 123, 'create_payment' ) === true );
echo "payment-lock ok\n";
