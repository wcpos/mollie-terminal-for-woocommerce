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
	public function __construct( string $method, string $current_id = 'tr_method', array $history = array() ) {
		if ( '' !== $current_id ) {
			$this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = $current_id;
			$this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = 'open';
			if ( '' !== $method ) { $this->meta[ PaymentAttempt::META_CURRENT_PAYMENT_METHOD ] = $method; }
		}
		if ( $history ) { $this->meta[ PaymentAttempt::META_ATTEMPTS ] = $history; }
	}
	public function get_id() { return 321; }
	public function get_total() { return '12.34'; }
	public function get_currency() { return 'EUR'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() {}
	public $transaction_id = '';
	public function is_paid() { return '' !== $this->transaction_id; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function payment_complete( $id ) { $this->transaction_id = $id; }
}

function reconcile_method( string $recorded, string $remote, array $qr_methods = array( 'ideal', 'bancontact' ) ): array {
	$order = new FakeOrderForMethodVerification( $recorded );
	$payment = array(
		'id' => 'tr_method',
		'status' => 'open',
		'method' => $remote,
		'mode' => 'live',
		'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ),
		'metadata' => array( 'order_id' => '321' ),
	);
	return ( new PaymentReconciler( new Settings( array( 'mode' => 'live', 'qr_methods' => $qr_methods ) ) ) )->reconcile( $order, $payment, 'test' );
}

expect( 'open' === ( reconcile_method( 'ideal', 'ideal' )['status'] ?? '' ), 'a payment matching the recorded iDEAL method should pass' );
$mismatch = reconcile_method( 'ideal', 'pointofsale' );
expect( 'verification_failed' === ( $mismatch['status'] ?? '' ), 'a payment that differs from the recorded method should fail' );
expect( in_array( 'payment method mismatch', $mismatch['errors'] ?? array(), true ), 'a recorded-method failure should identify the mismatch' );
expect( 'open' === ( reconcile_method( '', 'bancontact' )['status'] ?? '' ), 'Bancontact should pass for a pre-existing attempt without a recorded method' );
$unsupported = reconcile_method( '', 'creditcard' );
expect( 'verification_failed' === ( $unsupported['status'] ?? '' ), 'an unsupported unrecorded method should fail' );
expect( in_array( 'payment method is not supported', $unsupported['errors'] ?? array(), true ), 'an unsupported method should be identified' );
// A shop that never enabled QR must keep rejecting iDEAL/Bancontact payments
// that reach the (unauthenticated) webhook without a matching recorded attempt.
$disabled = reconcile_method( '', 'ideal', array() );
expect( 'verification_failed' === ( $disabled['status'] ?? '' ), 'an unrecorded iDEAL payment must fail when QR is disabled' );
expect( 'open' === ( reconcile_method( '', 'pointofsale', array() )['status'] ?? '' ), 'terminal payments must still pass when QR is disabled' );
expect( 'verification_failed' === ( reconcile_method( '', 'bancontact', array( 'ideal' ) )['status'] ?? '' ), 'a QR method the shop did not enable must fail' );

// Payments that are no longer current (abandoned, or superseded by a retry)
// are verified against the method recorded in the attempt history — even when
// the merchant has since disabled that QR method — and IDs this order never
// created are rejected regardless of metadata.
function reconcile_history( array $history, string $remote, array $qr_methods = array() ): array {
	$order = new FakeOrderForMethodVerification( '', '', $history );
	$payment = array(
		'id' => 'tr_old',
		'status' => 'paid',
		'method' => $remote,
		'mode' => 'live',
		'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ),
		'metadata' => array( 'order_id' => '321' ),
	);
	return ( new PaymentReconciler( new Settings( array( 'mode' => 'live', 'qr_methods' => $qr_methods ) ) ) )->reconcile( $order, $payment, 'test' );
}
$abandoned_ideal = array( array( 'payment_id' => 'tr_old', 'status' => 'abandoned', 'method' => 'ideal' ) );
expect( 'paid' === ( reconcile_history( $abandoned_ideal, 'ideal' )['status'] ?? '' ), 'an abandoned iDEAL payment must still complete the order after QR was disabled' );
expect( 'verification_failed' === ( reconcile_history( $abandoned_ideal, 'pointofsale' )['status'] ?? '' ), 'an abandoned attempt must reject a payment of another method' );
expect( 'paid' === ( reconcile_history( array( array( 'payment_id' => 'tr_old', 'status' => 'abandoned' ) ), 'pointofsale' )['status'] ?? '' ), 'a pre-0.5.0 history entry without a method still accepts a terminal payment' );
$unknown = reconcile_history( array( array( 'payment_id' => 'tr_other', 'status' => 'paid', 'method' => 'ideal' ) ), 'ideal', array( 'ideal' ) );
expect( 'verification_failed' === ( $unknown['status'] ?? '' ), 'a payment ID this order never created must be rejected even with matching metadata' );
expect( in_array( 'payment is not known for this order', $unknown['errors'] ?? array(), true ), 'an unknown payment should be identified as such' );

echo "payment-reconciler-method ok\n";
