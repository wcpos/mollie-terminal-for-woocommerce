<?php
// A refund on an order with a counting Pro leg goes through Pro; the old path refunds only a
// payment the old panel completed, never a Pro leg's Mollie payment id that Free copied onto the
// order; when Pro cannot allocate the amount and an old-panel payment exists, the old path takes it.
namespace WCPOS\WooCommercePOS\Payments\Contract {
	class Ledger {
		public const COUNTING_STATUSES = array( 'authorized', 'captured' );
		public static function instance() { return new self(); }
		public function read( $order ) { return $GLOBALS['rows']; }
	}
}

namespace {
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/support/fake-wpdb.php';
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } public function get_error_message() { return 'offline'; } } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_action() {}
function sanitize_key( $key ) { return $key; }
function get_option( $key, $default = false ) { return array( 'api_key' => 'live_k' ); }
function wc_format_decimal( $value, $dp = 2 ) { return number_format( (float) $value, $dp, '.', '' ); }
function wc_get_order( $id ) { return 55 === (int) $id ? $GLOBALS['order'] : false; }
function wcpos_pro_order_pay_refund( $order, $amount, $reason ) { $GLOBALS['pro'][] = array( $amount, $reason ); return $GLOBALS['pro_answer']; }
function wp_remote_request( $url, $args ) { $GLOBALS['http'][] = $url; return new WP_Error( 'http_request_failed', 'offline' ); }
class SilentLoggerForRefunds { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentLoggerForRefunds(); }
class WC_Payment_Gateway {}

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/RefundReconciler.php';
require_once __DIR__ . '/../../includes/RefundHandler.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

use WCPOS\WooCommercePOS\MollieTerminal\Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;

$wpdb = new FakeWpdb();
class FakeRefund { public function get_amount() { return '5.00'; } public function get_meta( $key ) { return ''; } public function get_id() { return 9; } public function update_meta_data( $k, $v ) {} public function get_reason() { return ''; } }
class FakeOrderForRefunds {
	public $txn = ''; public $history = array();
	public function get_id() { return 55; }
	public function get_transaction_id() { return $this->txn; }
	public function get_meta( $key ) { return PaymentAttempt::META_ATTEMPTS === $key ? $this->history : ( PaymentAttempt::META_ABANDONED_PAYMENT_IDS === $key ? array() : '' ); }
	public function get_refunds() { return array( new FakeRefund() ); }
	public function get_currency() { return 'EUR'; }
}
function run( array $rows, string $txn, array $history, $pro_answer ) {
	$GLOBALS['rows'] = $rows; $GLOBALS['pro'] = array(); $GLOBALS['http'] = array(); $GLOBALS['pro_answer'] = $pro_answer;
	$GLOBALS['order'] = new FakeOrderForRefunds(); $GLOBALS['order']->txn = $txn; $GLOBALS['order']->history = $history;
	return $GLOBALS['gateway']->process_refund( 55, '5.00', 'why' );
}
$GLOBALS['gateway'] = ( new ReflectionClass( Gateway::class ) )->newInstanceWithoutConstructor();
$pro_row = array( 'method_id' => 'mollie_terminal_for_woocommerce', 'status' => 'captured', 'capture_mode' => 'server', 'provider_refs' => array( 'action' => 'tr_pro', 'mollie_payment' => 'tr_pro', 'transaction_id' => 'tr_pro' ) );
$old_paid = array( array( 'payment_id' => 'tr_old', 'status' => 'paid' ) );
$not_allocatable = new WP_Error( 'wcpos_refund_not_allocatable', 'no' );

// 1. A counting Pro leg: Pro refunds; the old path is not touched.
expect( true === run( array( $pro_row ), 'tr_pro', array(), true ) && array( array( '5.00', 'why' ) ) === $GLOBALS['pro'] && array() === $GLOBALS['http'], 'a Pro leg refunds through Pro' );
// 2. Pro cannot allocate and the order carries an old-panel payment: the old path refunds THAT one.
$result = run( array( $pro_row ), 'tr_pro', $old_paid, $not_allocatable );
expect( array( 'https://api.mollie.com/v2/payments/tr_old' ) === $GLOBALS['http'], 'the old path refunds the old-panel payment, never the Pro leg Free copied onto the order' );
// 3. Pro cannot allocate and there is no old-panel payment: Pro's answer stands.
expect( $not_allocatable === run( array( $pro_row ), 'tr_pro', array(), $not_allocatable ) && array() === $GLOBALS['http'], 'without an old-panel payment Pro\'s refusal is the answer' );
// 4. No Pro leg: the old path with the order's transaction id, as before.
$result = run( array(), 'tr_old', array(), null );
expect( array() === $GLOBALS['pro'] && array( 'https://api.mollie.com/v2/payments/tr_old' ) === $GLOBALS['http'], 'without a Pro leg the old path refunds the transaction id' );
// 5. The transaction id names a Pro leg that no longer counts (voided), no old-panel payment: nothing to refund.
$voided = $pro_row; $voided['status'] = 'voided';
$result = run( array( $voided ), 'tr_pro', array(), null );
expect( is_wp_error( $result ) && 'mtfwc_refund_not_found' === $result->get_error_code() && array() === $GLOBALS['http'], 'a Pro leg\'s id never reaches the old path' );
// 6. The transaction id (the payment that completed the order) wins over the history; the history's
//    newest paid attempt is the fallback when the transaction id is a Pro leg's.
run( array(), 'tr_old', array( array( 'payment_id' => 'tr_first', 'status' => 'paid' ), array( 'payment_id' => 'tr_conflict', 'status' => 'paid' ) ), null );
expect( array( 'https://api.mollie.com/v2/payments/tr_old' ) === $GLOBALS['http'], 'the transaction id is refunded, not a later paid attempt recorded as a conflict' );
run( array( $voided ), 'tr_pro', array( array( 'payment_id' => 'tr_first', 'status' => 'paid' ), array( 'payment_id' => 'tr_x', 'status' => 'canceled' ), array( 'payment_id' => 'tr_last', 'status' => 'paid' ) ), null );
expect( array( 'https://api.mollie.com/v2/payments/tr_last' ) === $GLOBALS['http'], 'with the transaction id a Pro leg\'s, the newest paid old-panel attempt is refunded' );
// 7. A paid attempt in the history that is itself a Pro leg (the keypad payment) never reaches the old path.
run( array( $voided ), 'tr_pro', array( array( 'payment_id' => 'tr_pro', 'status' => 'paid' ) ), null );
expect( array() === $GLOBALS['http'] && is_wp_error( $result = null ) === false, 'a Pro leg in the history is not an old-panel payment' );
$result = run( array( $voided ), 'tr_pro', array( array( 'payment_id' => 'tr_pro', 'status' => 'paid' ) ), null );
expect( is_wp_error( $result ) && 'mtfwc_refund_not_found' === $result->get_error_code() && array() === $GLOBALS['http'], 'nothing to refund when the only paid attempt is the Pro leg' );

echo "process-refund-routing ok\n";
}
