<?php
// Issue #21 review: the completed order may be a re-read copy, leaving the
// AJAX order unpaid in memory. Every paid answer needs the thank-you URL.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function woocommerce_pos_request() { return true; }
function get_home_url( $blog_id = null, $path = '' ) { return 'https://example.test' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require_once __DIR__ . '/../../includes/AjaxHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;

class FakeOrderForPaidRedirect {
	public $paid = false;
	public function get_id() { return 123; }
	public function get_order_key() { return 'wc_order_test'; }
	public function is_paid() { return $this->paid; }
}

$order = new FakeOrderForPaidRedirect();
$redirect = 'https://example.test/wcpos-checkout/order-received/123?key=wc_order_test';
foreach ( array( 'paid', 'already_paid', 'conflict' ) as $status ) {
	$result = array( 'status' => $status, 'completing' => true, 'payment_id' => 'tr_test' );
	$expected = $result;
	$expected['redirect_url'] = $redirect;
	expect( $expected === AjaxHandler::with_paid_redirect( $result, $order ), "$status must get the redirect and preserve other result keys for an unpaid copy" );
}

foreach ( array( 'pending', 'open', 'canceled' ) as $status ) {
	$result = array( 'status' => $status, 'retry_allowed' => false );
	expect( $result === AjaxHandler::with_paid_redirect( $result, $order ), "$status must stay unchanged without a redirect for an unpaid order" );
}

$order->paid = true;
$result = array( 'status' => 'idle', 'retry_allowed' => false );
$expected = $result;
$expected['redirect_url'] = $redirect;
expect( $expected === AjaxHandler::with_paid_redirect( $result, $order ), 'a paid order must get the redirect regardless of result status and preserve other keys' );

echo "ajax-paid-redirect ok\n";
