<?php
// On upgrade, open terminal attempts the old panel left mid-flight are folded into Pro's ledger
// once, by their Mollie payment id, bounded to the orders snapshotted (as ids) when the pass began,
// under Free's order lock and the old paths' completion claim, on a fresh read; final attempts and
// paid orders are skipped (a QR attempt left open is adopted too). Under the QR carve-out nothing
// is adopted. Pro owns an adopted payment while its row is live.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/support/fake-wpdb.php';

require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/stubs/order-lock.php';
require_once __DIR__ . '/stubs/ledger.php';
function sanitize_key( $key ) { return $key; }
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Legacy_Adoption.php';

use WCPOS\WooCommercePOS\MollieTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentLock;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

class SilentLoggerForAdoption { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentLoggerForAdoption(); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_orders( $args ) { $GLOBALS['queries'][] = $args; return array_map( static function ( $o ) { return $o->get_id(); }, $GLOBALS['page'] ); }
function wcpos_pro_payment_id_for_action( $provider, $ref ) { $GLOBALS['lookups'][] = $provider; return $GLOBALS['adopted_map'][ $ref ] ?? null; }
function wcpos_pro_adopt_legacy_attempt( $order, $gateway_id, $ref, $amount, $currency ) {
	$GLOBALS['adopted'][] = array( $order->get_id(), $gateway_id, $ref, $amount, $currency );
	$answer = $GLOBALS['adopt_answer'] ?? array( 'id' => 'row' );
	if ( $answer instanceof Throwable ) { throw $answer; }
	return $answer;
}

$wpdb = new FakeWpdb();

class WC_Order {
	public $id; public $meta = array(); public $paid; public $saves = 0;
	public function __construct( $id, $payment_id, $method = 'pointofsale', $status = 'open', $paid = false ) {
		$this->id = $id; $this->paid = $paid;
		if ( '' !== $payment_id ) {
			$this->meta = array( PaymentAttempt::META_CURRENT_PAYMENT_ID => $payment_id, PaymentAttempt::META_CURRENT_PAYMENT_METHOD => $method, PaymentAttempt::META_CURRENT_PAYMENT_STATUS => $status );
		}
	}
	public function get_id() { return $this->id; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saves++; }
	public function is_paid() { return $this->paid; }
	public function needs_payment() { return ! $this->paid; }
	public function get_total() { return '12.50'; }
	public function get_currency() { return 'EUR'; }
}

function reset_state( array $page, array $fresh = array(), array $settings = array() ) {
	$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => $settings );
	$GLOBALS['page'] = $page;
	$by_id = array();
	foreach ( $page as $o ) { $by_id[ $o->get_id() ] = $o; }
	// The order read under the lock: the fresh copy where one is given, the snapshot's otherwise.
	$GLOBALS['orders'] = $fresh + $by_id;
	$GLOBALS['adopted'] = array();
	$GLOBALS['adopted_map'] = array();
	$GLOBALS['queries'] = array();
	$GLOBALS['lookups'] = array();
	$GLOBALS['ledger_rows'] = array();
	unset( $GLOBALS['adopt_answer'] );
	$GLOBALS['wpdb']->rows = array();
	Order_Lock::$locked = array();
	Order_Lock::$refuse = array();
}

// 1. The snapshot is ids only; each order is judged on its fresh read under the lock: open terminal
//    attempts on unpaid orders are adopted by Mollie payment id, everything else is left alone.
reset_state( array(
	new WC_Order( 1, 'tr_live' ),
	new WC_Order( 2, 'tr_qr', 'ideal' ),                       // a QR attempt left open when QR was switched off
	new WC_Order( 3, 'tr_done', 'pointofsale', 'canceled' ),   // final
	new WC_Order( 4, 'tr_paid', 'pointofsale', 'paid' ),       // the old sweep's recovery, not adoption
	new WC_Order( 5, 'tr_cash', 'pointofsale', 'open', true ), // paid meanwhile by cash
	new WC_Order( 6, '' ),                                     // no attempt
	new WC_Order( 7, 'tr_adopted' ),
	new WC_Order( 8, 'tr_pending', 'pointofsale', 'pending' ),
) );
$GLOBALS['adopted_map'] = array( 'tr_adopted' => 'row-7' );
Legacy_Adoption::upgrade();
expect( array(
	array( 1, Settings::GATEWAY_ID, 'tr_live', '12.50', 'EUR' ),
	array( 2, Settings::GATEWAY_ID, 'tr_qr', '12.50', 'EUR' ),
	array( 8, Settings::GATEWAY_ID, 'tr_pending', '12.50', 'EUR' ),
) === $GLOBALS['adopted'], 'only open attempts on unpaid orders are adopted, by Mollie payment id; a QR attempt left open too' );
expect( array( 1, 2, 3, 4, 5, 6, 7, 8 ) === Order_Lock::$locked, 'every snapshotted order is judged under the lock' );
expect( 'tr_live' === $GLOBALS['orders'][1]->meta[ Legacy_Adoption::META_ADOPTED ] && 1 === $GLOBALS['orders'][1]->saves, 'the adopted reference is kept on the order' );
expect( ! isset( $GLOBALS['orders'][7]->meta[ Legacy_Adoption::META_ADOPTED ] ), 'an already adopted attempt is not written again' );
expect( array_unique( $GLOBALS['lookups'] ) === array( 'mollie' ), 'adoption is looked up under the provider family' );
expect( array() === $GLOBALS['wpdb']->rows, 'the completion claim is released after each adoption' );
$q = $GLOBALS['queries'][0];
expect( 'shop_order' === $q['type'] && array( 'pending', 'failed', 'pos-open', 'pos-partial' ) === $q['status'] && -1 === $q['limit'] && 'ID' === $q['orderby'] && 'ids' === $q['return'] && PaymentAttempt::META_CURRENT_PAYMENT_ID === $q['meta_key'] && 'EXISTS' === $q['meta_compare'] && ! isset( $q['meta_query'] ), 'the snapshot reads the ids of every order still waiting for payment that carries an attempt pointer, through the shortcut both order stores honour, loading no order' );
expect( 1 === count( $GLOBALS['queries'] ), 'the snapshot is taken once' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'] && ! isset( $GLOBALS['options']['mtfwc_adoption_queue'] ), 'a short queue finishes the pass and clears the queue' );
Legacy_Adoption::upgrade();
expect( 1 === count( $GLOBALS['queries'] ), 'a finished pass is idle' );

// 2. The fresh copy read under the lock decides: paid meanwhile, or a new attempt (retry) replaced it.
reset_state(
	array( new WC_Order( 10, 'tr_a' ), new WC_Order( 11, 'tr_b' ) ),
	array( 10 => new WC_Order( 10, 'tr_a', 'pointofsale', 'open', true ), 11 => new WC_Order( 11, 'tr_b2', 'pointofsale', 'canceled' ) )
);
Legacy_Adoption::upgrade();
expect( array() === $GLOBALS['adopted'], 'the order read under the lock decides: paid meanwhile, or a finished retried attempt, is not adopted' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'], 'skipped entries leave the queue' );

// 3. A full page leaves the remainder queued; the next request drains it from the same snapshot.
$orders = array();
for ( $i = 100; $i < 126; $i++ ) { $orders[] = new WC_Order( $i, 'tr_' . $i ); }
reset_state( $orders );
Legacy_Adoption::upgrade();
expect( 25 === count( $GLOBALS['adopted'] ) && array( 125 => 1 ) === $GLOBALS['options']['mtfwc_adoption_queue'], 'a request works one page and leaves the remainder queued' );
expect( ! isset( $GLOBALS['options']['mtfwc_adoption_version'] ), 'the pass is not finished while entries remain' );
$GLOBALS['page'] = array(); // a live query now would find nothing
Legacy_Adoption::upgrade();
expect( 26 === count( $GLOBALS['adopted'] ) && 'tr_125' === $GLOBALS['adopted'][25][2] && 1 === count( $GLOBALS['queries'] ), 'the next request drains the snapshot without querying again' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'], 'the pass finishes once the queue is empty' );

// 4. A held order lock, or a completion claim another request holds, keeps the entry queued; Pro
//    refusing the adoption drops it.
reset_state( array( new WC_Order( 30, 'tr_busy' ), new WC_Order( 31, 'tr_refused' ), new WC_Order( 32, 'tr_completing' ) ) );
Order_Lock::$refuse = array( 30 );
$GLOBALS['wpdb']->rows['mtfwc_lock_order_32_complete_payment'] = json_encode( array( 'token' => 'other', 'expires_at' => time() + 60 ) );
$GLOBALS['adopt_answer'] = new WP_Error( 'wcpos_adopt_unsupported', 'no adapter' );
Legacy_Adoption::upgrade();
expect( array( 30 => 1, 32 => 1 ) === $GLOBALS['options']['mtfwc_adoption_queue'], 'a held lock or a completion in flight keeps the entry queued; a refusal drops it' );
expect( array( 31, 32 ) === Order_Lock::$locked && array( array( 31, Settings::GATEWAY_ID, 'tr_refused', '12.50', 'EUR' ) ) === $GLOBALS['adopted'], 'the busy orders were not adopted' );
expect( isset( $GLOBALS['wpdb']->rows['mtfwc_lock_order_32_complete_payment'] ), 'the other request\'s claim is left standing' );
// 4b. Pro throwing is final for that order too: dropped, the claim released, never retried on every request.
reset_state( array( new WC_Order( 35, 'tr_throws' ) ) );
$GLOBALS['adopt_answer'] = new RuntimeException( 'boom' );
function wcpos_pro_adopt_legacy_attempt_throwing() {}
$result = Legacy_Adoption::adopt_order( 35 );
expect( is_wp_error( $result ) && 'mtfwc_adoption_failed' === $result->get_error_code() && array() === $GLOBALS['wpdb']->rows, 'an exception from Pro is a final refusal and the claim is released' );
Legacy_Adoption::upgrade();
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'], 'the pass finishes past an order Pro threw on' );

// 5. Under the QR carve-out nothing is adopted and the pass is not marked done.
reset_state( array( new WC_Order( 40, 'tr_qr_store' ) ), array(), array( 'qr_methods' => array( 'ideal' ) ) );
Legacy_Adoption::upgrade();
expect( array() === $GLOBALS['adopted'] && array() === $GLOBALS['queries'], 'with a QR method enabled the old panel owns its attempts: no query, nothing adopted' );
expect( ! isset( $GLOBALS['options']['mtfwc_adoption_version'] ) && ! isset( $GLOBALS['options']['mtfwc_adoption_queue'] ), 'the pass waits for the QR methods to be switched off' );

// 6. Pro's panel adopts one order on render, through the same rule.
reset_state( array() );
$GLOBALS['orders'][50] = new WC_Order( 50, 'tr_render' );
$row = Legacy_Adoption::adopt_order( 50 );
expect( array( 'id' => 'row' ) === $row && array( array( 50, Settings::GATEWAY_ID, 'tr_render', '12.50', 'EUR' ) ) === $GLOBALS['adopted'] && array( 50 ) === Order_Lock::$locked, 'a single order is adopted on demand under the lock' );
$GLOBALS['adopted_map'] = array( 'tr_render' => 'row' );
expect( null === Legacy_Adoption::adopt_order( 50 ) && 1 === count( $GLOBALS['adopted'] ), 'an adopted order is left alone' );
expect( null === Legacy_Adoption::adopt_order( 51 ), 'an unknown order is nothing' );

// 7. Pro owns an adopted payment while its row is live (pending, authorized, captured): by the
//    current attempt or by the reference kept at adoption. Once the row has ended without money
//    the old paths may act again; a record whose row cannot be read counts as owned.
reset_state( array() );
$GLOBALS['adopted_map'] = array( 'tr_kept' => 'row-x' );
foreach ( array( 'pending' => true, 'authorized' => true, 'captured' => true, 'voided' => false, 'failed' => false, 'expired' => false ) as $status => $owned ) {
	$order = new WC_Order( 60, 'tr_kept' );
	$GLOBALS['ledger_rows'][60] = array( array( 'id' => 'row-x', 'status' => $status ) );
	expect( $owned === Legacy_Adoption::owns_order( $order ), 'ownership follows the row: ' . $status );
}
$GLOBALS['ledger_rows'][61] = array( array( 'id' => 'row-x', 'status' => 'pending' ) );
$order = new WC_Order( 61, 'tr_new' ); $order->meta[ Legacy_Adoption::META_ADOPTED ] = 'tr_kept';
expect( Legacy_Adoption::owns_order( $order ), 'the reference kept at adoption is recognised after the current pointer moved on' );
$GLOBALS['ledger_rows'][62] = array();
expect( Legacy_Adoption::owned_by_pro( new WC_Order( 62, 'tr_kept' ), 'tr_kept' ), 'a record whose row cannot be found counts as owned' );
expect( ! Legacy_Adoption::owns_order( new WC_Order( 63, 'tr_new' ) ), 'an unadopted attempt is nobody\'s but the old panel\'s' );
expect( 'tr_x' === Legacy_Adoption::action_ref( new WC_Order( 64, 'tr_x', 'bancontact' ) ) && 'tr_x' === Legacy_Adoption::action_ref( new WC_Order( 65, 'tr_x', 'pointofsale', 'authorized' ) ) && '' === Legacy_Adoption::action_ref( new WC_Order( 66, 'tr_x', 'pointofsale', 'paid' ) ), 'the action reference is the open attempt\'s Mollie payment id, whatever its method' );

echo "legacy-adoption ok\n";
