<?php
require_once __DIR__ . '/support/fake-wpdb.php';
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/../../includes/PaymentLock.php';
use WCPOS\WooCommercePOS\MollieTerminal\PaymentLock;

expect( PaymentLock::acquire( 123, 'create_payment' ) === true );
expect( PaymentLock::acquire( 123, 'create_payment' ) === false );
PaymentLock::release( 123, 'create_payment' );
expect( PaymentLock::acquire( 123, 'create_payment' ) === true );
PaymentLock::release( 123, 'create_payment' );

$key = 'mtfwc_lock_order_123_create_payment';
$expired = json_encode( array( 'token' => 'old', 'expires_at' => time() - 60 ) );
$live = json_encode( array( 'token' => 'competitor', 'expires_at' => time() + 120 ) );
$wpdb->rows[ $key ] = $expired;
expect( PaymentLock::acquire( 123, 'create_payment' ), 'an expired claim must be taken over' );
$taken = json_decode( $wpdb->rows[ $key ], true );
expect( 'old' !== $taken['token'] && $taken['expires_at'] >= time(), 'takeover must store a fresh token and expiry' );
PaymentLock::release( 123, 'create_payment' );

// First insert loses to the expired row; another request wins the second.
$wpdb->rows[ $key ] = $expired;
$wpdb->before_insert = function () use ( $wpdb, $key, $live ) {
	$wpdb->before_insert = function () use ( $wpdb, $key, $live ) { $wpdb->rows[ $key ] = $live; };
};
expect( ! PaymentLock::acquire( 123, 'create_payment' ), 'a competing claim between attempts must win' );
expect( $live === $wpdb->rows[ $key ], 'the competing claim must stay untouched' );
PaymentLock::release( 123, 'create_payment' );
expect( $live === $wpdb->rows[ $key ], 'release without ownership must leave an existing row' );
unset( $wpdb->rows[ $key ] );

expect( PaymentLock::acquire( 123, 'create_payment' ), 'acquire before replacement' );
$wpdb->rows[ $key ] = $live;
PaymentLock::release( 123, 'create_payment' );
expect( $live === $wpdb->rows[ $key ], 'an old holder must not delete a replacement claim' );
unset( $wpdb->rows[ $key ] );

expect( PaymentLock::acquire( 123, 'create_payment' ), 'acquire before with_lock contention' );
try {
	PaymentLock::with_lock( 123, 'create_payment', function () { expect( false, 'a busy callback must not run' ); } );
	expect( false, 'with_lock must throw while held' );
} catch ( RuntimeException $e ) {
	expect( 'Another Mollie Terminal operation is already running for this order.' === $e->getMessage(), 'busy exception must stay unchanged' );
}
PaymentLock::release( 123, 'create_payment' );
$result = PaymentLock::with_lock( 123, 'create_payment', function () use ( $wpdb, $key ) {
	expect( isset( $wpdb->rows[ $key ] ), 'the callback must run with the claim held' );
	return 'callback result';
} );
expect( 'callback result' === $result && ! isset( $wpdb->rows[ $key ] ), 'successful callback must return its result and release' );
try {
	PaymentLock::with_lock( 123, 'create_payment', function () { throw new RuntimeException( 'callback failed' ); } );
	expect( false, 'callback exception must propagate' );
} catch ( RuntimeException $e ) {
	expect( 'callback failed' === $e->getMessage(), 'the original callback exception must propagate' );
}
expect( ! isset( $wpdb->rows[ $key ] ), 'throwing callback must release the claim' );

foreach ( array( '{}', '{broken', '{"expires_at":"invalid"}' ) as $invalid ) {
	$wpdb->rows[ $key ] = $invalid;
	expect( PaymentLock::acquire( 123, 'create_payment' ), 'missing or unparsable expiry must allow takeover' );
	PaymentLock::release( 123, 'create_payment' );
}

// The incumbent can release between a failed insert and the subsequent read.
$GLOBALS['wpdb'] = new class extends FakeWpdb {
	public function get_var( $prepared ) { $this->rows = array(); return parent::get_var( $prepared ); }
};
$wpdb->rows[ $key ] = $live;
expect( PaymentLock::acquire( 123, 'create_payment' ), 'a row missing at read time must permit one more claim' );
PaymentLock::release( 123, 'create_payment' );
echo "payment-lock ok\n";
