<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function wp_generate_uuid4() { return 'attempt-uuid'; }
class FakeOrder {
	public $meta = array();
	public $saved = false;
	public function get_meta( $key ) { return $this->meta[$key] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
	public function save() { $this->saved = true; }
}
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
$order = new FakeOrder();
$payment = array( 'id' => 'tr_123', 'status' => 'open', 'amount' => array( 'value' => '12.34', 'currency' => 'EUR' ) );
$attempt = PaymentAttempt::record_new( $order, $payment, 'term_1', 'test' );
expect( $attempt['payment_id'] === 'tr_123', 'expectation failed' );
expect( PaymentAttempt::current( $order )['status'] === 'open', 'expectation failed' );
PaymentAttempt::update_status( $order, array( 'id' => 'tr_123', 'status' => 'paid' ) );
expect( PaymentAttempt::current( $order )['status'] === 'paid', 'expectation failed' );
$history = PaymentAttempt::history( $order );
expect( count( $history ) === 1 && $history[0]['status'] === 'paid', 'expectation failed' );
echo "payment-attempt-history ok\n";
