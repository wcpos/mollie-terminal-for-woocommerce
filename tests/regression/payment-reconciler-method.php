<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

class FakeOrderForMethodVerification {
	public $meta = array();
	public $notes = array();
	public function __construct( string $method ) {
		$this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_method';
		$this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = 'open';
		if ( '' !== $method ) { $this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_METHOD ] = $method; }
	}
	public function get_id() { return 321; }
	public function get_total() { return '12.34'; }
	public function get_currency() { return 'EUR'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() {}
}

function reconcile_method( string $recorded, string $remote ): array {
	$order = new FakeOrderForMethodVerification( $recorded );
	$payment = array(
		'id' => 'tr_method',
		'status' => 'open',
		'method' => $remote,
		'mode' => 'live',
		'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ),
		'metadata' => array( 'order_id' => '321' ),
	);
	return ( new PaymentReconciler( new Settings( array( 'mode' => 'live' ) ) ) )->reconcile( $order, $payment, 'test' );
}

expect( 'open' === ( reconcile_method( 'ideal', 'ideal' )['status'] ?? '' ), 'a payment matching the recorded iDEAL method should pass' );
$mismatch = reconcile_method( 'ideal', 'pointofsale' );
expect( 'verification_failed' === ( $mismatch['status'] ?? '' ), 'a payment that differs from the recorded method should fail' );
expect( in_array( 'payment method mismatch', $mismatch['errors'] ?? array(), true ), 'a recorded-method failure should identify the mismatch' );
expect( 'open' === ( reconcile_method( '', 'bancontact' )['status'] ?? '' ), 'Bancontact should pass for a pre-existing attempt without a recorded method' );
$unsupported = reconcile_method( '', 'creditcard' );
expect( 'verification_failed' === ( $unsupported['status'] ?? '' ), 'an unsupported unrecorded method should fail' );
expect( in_array( 'payment method is not supported', $unsupported['errors'] ?? array(), true ), 'an unsupported method should be identified' );

echo "payment-reconciler-method ok\n";
