<?php
// On upgrade, open terminal attempts the old panel left mid-flight are folded into Pro's ledger
// once, by their Mollie payment id, bounded to the orders snapshotted when the pass began, under
// Free's order lock with a fresh read; QR attempts, final attempts and paid orders are skipped.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/stubs/order-lock.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/Legacy_Adoption.php';

use WCPOS\WooCommercePOS\MollieTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

class SilentLoggerForAdoption { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentLoggerForAdoption(); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_orders( $args ) { $GLOBALS['queries'][] = $args; return $GLOBALS['page']; }
function wcpos_pro_payment_id_for_action( $provider, $ref ) { $GLOBALS['lookups'][] = $provider; return $GLOBALS['adopted_map'][ $ref ] ?? null; }
function wcpos_pro_adopt_legacy_attempt( $order, $gateway_id, $ref, $amount, $currency ) {
	$GLOBALS['adopted'][] = array( $order->get_id(), $gateway_id, $ref, $amount, $currency );
	return $GLOBALS['adopt_answer'] ?? array( 'id' => 'row' );
}

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

function reset_state( array $page, array $fresh = array() ) {
	$GLOBALS['options'] = array();
	$GLOBALS['page'] = $page;
	$GLOBALS['by_id'] = array();
	foreach ( $page as $o ) { $GLOBALS['by_id'][ $o->get_id() ] = $o; }
	// The order read under the lock: the fresh copy where one is given, the snapshot's otherwise.
	$GLOBALS['orders'] = $fresh + $GLOBALS['by_id'];
	$GLOBALS['adopted'] = array();
	$GLOBALS['adopted_map'] = array();
	$GLOBALS['queries'] = array();
	$GLOBALS['lookups'] = array();
	unset( $GLOBALS['adopt_answer'] );
	Order_Lock::$locked = array();
	Order_Lock::$refuse = array();
}

// 1. The snapshot: open terminal attempts on unpaid orders only, adopted by Mollie payment id.
reset_state( array(
	new WC_Order( 1, 'tr_live' ),
	new WC_Order( 2, 'tr_qr', 'ideal' ),                      // a QR attempt stays with the old panel
	new WC_Order( 3, 'tr_done', 'pointofsale', 'canceled' ),  // final
	new WC_Order( 4, 'tr_paid', 'pointofsale', 'paid' ),      // the old sweeper's recovery, not adoption
	new WC_Order( 5, 'tr_cash', 'pointofsale', 'open', true ), // paid meanwhile by cash
	new WC_Order( 6, '' ),                                    // no attempt
	new WC_Order( 7, 'tr_adopted' ),
	new WC_Order( 8, 'tr_pending', 'pointofsale', 'pending' ),
) );
$GLOBALS['adopted_map'] = array( 'tr_adopted' => 'row-7' );
Legacy_Adoption::upgrade();
expect( array(
	array( 1, Settings::GATEWAY_ID, 'tr_live', '12.50', 'EUR' ),
	array( 8, Settings::GATEWAY_ID, 'tr_pending', '12.50', 'EUR' ),
) === $GLOBALS['adopted'], 'only open terminal attempts on unpaid orders are adopted, by Mollie payment id' );
expect( array( 1, 7, 8 ) === Order_Lock::$locked, 'the lock is taken before the adopted check is repeated' );
expect( 'tr_live' === $GLOBALS['by_id'][1]->meta[ Legacy_Adoption::META_ADOPTED ] && 1 === $GLOBALS['by_id'][1]->saves, 'the adopted reference is kept on the order' );
expect( ! isset( $GLOBALS['by_id'][7]->meta[ Legacy_Adoption::META_ADOPTED ] ), 'an already adopted attempt is not written again' );
expect( array_unique( $GLOBALS['lookups'] ) === array( 'mollie' ), 'adoption is looked up under the provider family' );
$q = $GLOBALS['queries'][0];
expect( 'shop_order' === $q['type'] && array( 'pending', 'failed', 'pos-open', 'pos-partial' ) === $q['status'] && -1 === $q['limit'] && 'ID' === $q['orderby'] && PaymentAttempt::META_CURRENT_PAYMENT_ID === $q['meta_key'] && 'EXISTS' === $q['meta_compare'] && ! isset( $q['meta_query'] ), 'the snapshot reads every order still waiting for payment that carries an attempt pointer, through the shortcut both order stores honour' );
expect( 1 === count( $GLOBALS['queries'] ), 'the snapshot is taken once' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'] && ! isset( $GLOBALS['options']['mtfwc_adoption_queue'] ), 'a short queue finishes the pass and clears the queue' );
Legacy_Adoption::upgrade();
expect( 1 === count( $GLOBALS['queries'] ), 'a finished pass is idle' );

// 2. The fresh copy read under the lock decides: paid meanwhile, or a new attempt (retry) replaced it.
reset_state(
	array( new WC_Order( 10, 'tr_a' ), new WC_Order( 11, 'tr_b' ) ),
	array( 10 => new WC_Order( 10, 'tr_a', 'pointofsale', 'open', true ), 11 => new WC_Order( 11, 'tr_b2' ) )
);
Legacy_Adoption::upgrade();
expect( array() === $GLOBALS['adopted'], 'the order read under the lock decides: paid meanwhile, or a retried attempt, is not adopted' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'], 'skipped entries leave the queue' );

// 3. A full page leaves the remainder queued; the next request drains it from the same snapshot.
$orders = array();
for ( $i = 100; $i < 126; $i++ ) { $orders[] = new WC_Order( $i, 'tr_' . $i ); }
reset_state( $orders );
Legacy_Adoption::upgrade();
expect( 25 === count( $GLOBALS['adopted'] ) && array( 125 => 'tr_125' ) === $GLOBALS['options']['mtfwc_adoption_queue'], 'a request works one page and leaves the remainder queued' );
expect( ! isset( $GLOBALS['options']['mtfwc_adoption_version'] ), 'the pass is not finished while entries remain' );
$GLOBALS['page'] = array(); // a live query now would find nothing
Legacy_Adoption::upgrade();
expect( 26 === count( $GLOBALS['adopted'] ) && 'tr_125' === $GLOBALS['adopted'][25][2] && 1 === count( $GLOBALS['queries'] ), 'the next request drains the snapshot without querying again' );
expect( Legacy_Adoption::VERSION === $GLOBALS['options']['mtfwc_adoption_version'], 'the pass finishes once the queue is empty' );

// 4. A held lock keeps its entry queued; Pro refusing the adoption drops it.
reset_state( array( new WC_Order( 30, 'tr_busy' ), new WC_Order( 31, 'tr_refused' ) ) );
Order_Lock::$refuse = array( 30 );
$GLOBALS['adopt_answer'] = new WP_Error( 'wcpos_adopt_unsupported', 'no adapter' );
Legacy_Adoption::upgrade();
expect( array( 30 => 'tr_busy' ) === $GLOBALS['options']['mtfwc_adoption_queue'], 'a held lock keeps the entry queued; a refusal drops it' );
expect( array( 31 ) === Order_Lock::$locked && array( array( 31, Settings::GATEWAY_ID, 'tr_refused', '12.50', 'EUR' ) ) === $GLOBALS['adopted'], 'the busy order was not touched' );

// 5. Adopted orders are recognised by the current attempt and by the reference kept at adoption.
reset_state( array() );
$GLOBALS['adopted_map'] = array( 'tr_kept' => 'row-x' );
$order = new WC_Order( 40, 'tr_kept' );
expect( Legacy_Adoption::is_adopted_order( $order ), 'an adopted current attempt is recognised' );
$order = new WC_Order( 41, 'tr_new' ); $order->meta[ Legacy_Adoption::META_ADOPTED ] = 'tr_kept';
expect( Legacy_Adoption::is_adopted_order( $order ), 'the reference kept at adoption is recognised after the current pointer moved on' );
expect( ! Legacy_Adoption::is_adopted_order( new WC_Order( 42, 'tr_new' ) ), 'an unadopted attempt is not' );
expect( '' === Legacy_Adoption::action_ref( new WC_Order( 43, 'tr_x', 'bancontact' ) ) && 'tr_x' === Legacy_Adoption::action_ref( new WC_Order( 44, 'tr_x', 'pointofsale', 'authorized' ) ), 'the action reference is the open terminal attempt\'s Mollie payment id' );

echo "legacy-adoption ok\n";
