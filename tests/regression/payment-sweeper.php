<?php
// Covers the stale-payment sweep per-order decision: only still-payable orders
// whose current Mollie attempt has been open past the threshold get canceled.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) { return $GLOBALS['sweeper_filters'][ $hook ] ?? $value; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForSweeper { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForSweeper(); }
$captured_order_queries = array();
// Orders carrying a current attempt / abandoned payments, oldest first; honours
// limit with offset (or paged) like WooCommerce, and counts what it returns.
$GLOBALS['sweeper_fetched'] = array();
$GLOBALS['sweeper_current_pool'] = array();
$GLOBALS['sweeper_abandoned_pool'] = array();
function wc_get_orders( $args ) {
	global $captured_order_queries;
	$captured_order_queries[] = $args;
	$pool = '_mtfwc_current_payment_id' === ( $args['meta_key'] ?? '' ) ? $GLOBALS['sweeper_current_pool'] : $GLOBALS['sweeper_abandoned_pool'];
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $args['limit'];
	$batch = array_slice( $pool, $offset, $args['limit'] );
	$GLOBALS['sweeper_fetched'][ $args['meta_key'] ] = ( $GLOBALS['sweeper_fetched'][ $args['meta_key'] ] ?? 0 ) + count( $batch );
	return $batch;
}
function wc_get_order( $id ) {
	$fresh = $GLOBALS['sweeper_fresh_order'] ?? null;
	return $fresh && $fresh->get_id() === $id ? $fresh : false;
}
function clean_post_cache( $id ) {}

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/PaymentSweeper.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

class FakeOrderForSweeper {
	public $meta = array();
	public $paid = false;
	public $notes = array();
	public $saves = 0;
	public $id = 4242;
	public function is_paid() { return $this->paid; }
	public function get_id() { return $this->id; }
	public $payment_method = '';
	public $payment_method_title = '';
	public function get_payment_method() { return $this->payment_method; }
	public function set_payment_method( $m ) { $this->payment_method = (string) $m; }
	public function set_payment_method_title( $t ) { $this->payment_method_title = (string) $t; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saves++; }
}

class CountingCancelService extends MolliePaymentService {
	public $cancel_calls = 0;
	public $abandoned_calls = 0;
	public $abandoned_result = array( 'tr_abandoned' => 'canceled' );
	public function __construct() {}
	public $polled = array();
	public function cancel_order_payment( $order, string $only_payment_id = '' ): array { $this->cancel_calls++; return array( 'status' => 'canceled' ); }
	// Stands in for the real recovery (asks Mollie, completes under the claim),
	// which payment-complete-race.php covers end to end.
	public function poll_order( $order, string $source = 'poll' ): array {
		$this->polled[] = array( $order->get_id(), $source );
		$order->paid = true;
		return array( 'status' => 'paid' );
	}
	public function cancel_abandoned_payments( $order ): array {
		if ( empty( PaymentAttempt::abandoned( $order ) ) ) { return array(); }
		$this->abandoned_calls++;
		return $this->abandoned_result;
	}
}

function make_sweeper_order( string $status, int $age_seconds, bool $paid = false ): FakeOrderForSweeper {
	$order = new FakeOrderForSweeper();
	$order->paid = $paid;
	if ( '' !== $status ) {
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_sweep';
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = $status;
		$order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_CREATED_AT ] = gmdate( 'c', time() - $age_seconds );
	}
	return $order;
}

// Stale open payment (20 min old) on a still-payable order -> swept.
$service = new CountingCancelService();
$sweeper = new PaymentSweeper( $service );
$order = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS );
expect( true === $sweeper->sweep_order( $order ), 'a stale open payment should be swept' );
expect( 1 === $service->cancel_calls, 'the sweep should cancel the stale payment' );
expect( ! empty( $order->notes ), 'the sweep should leave an order note' );

// Fresh open payment (30 s old) -> left alone.
$order = make_sweeper_order( 'open', 30 );
expect( false === $sweeper->sweep_order( $order ), 'a fresh open payment must not be swept' );
expect( 1 === $service->cancel_calls, 'no extra cancel for a fresh payment' );

// Paid order -> left alone even with an old attempt.
$order = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS, true );
expect( false === $sweeper->sweep_order( $order ), 'a paid order must not be swept' );

// No attempt -> left alone.
$order = make_sweeper_order( '', 0 );
expect( false === $sweeper->sweep_order( $order ), 'an order without an attempt must not be swept' );

// Final (canceled) attempt -> left alone.
$order = make_sweeper_order( 'canceled', 20 * MINUTE_IN_SECONDS );
expect( false === $sweeper->sweep_order( $order ), 'a final attempt must not be swept' );

expect( 1 === $service->cancel_calls, 'only the one stale open payment should have been canceled' );
expect( 0 === $service->abandoned_calls, 'orders without abandoned payments must not hit the abandoned path' );

// An abandoned payment has no current-attempt pointer, but it is still open at
// Mollie: the sweep must find it via META_ABANDONED_PAYMENT_IDS and resolve it.
$order = make_sweeper_order( '', 0 );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
expect( true === $sweeper->sweep_order( $order ), 'an abandoned payment should be swept' );
expect( 1 === $service->abandoned_calls, 'the sweep should resolve the abandoned payment' );
expect( 1 === $service->cancel_calls, 'an order without a current attempt needs no current-attempt cancel' );
expect( ! empty( $order->notes ), 'resolving an abandoned payment should leave an order note' );

// A paid order still gets its abandoned payments chased: the customer may have
// paid in cash while the terminal payment stayed open at Mollie.
$order = make_sweeper_order( '', 0, true );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
expect( true === $sweeper->sweep_order( $order ), 'a paid order with an abandoned payment should be swept' );
expect( 2 === $service->abandoned_calls, 'the abandoned payment is resolved even on a paid order' );
expect( 1 === $service->cancel_calls, 'a paid order must not have its current attempt canceled' );

// A still-open abandoned payment leaves no note (nothing was resolved yet) but
// still counts as swept so the next run retries it.
$order = make_sweeper_order( '', 0 );
$order->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
$service->abandoned_result = array( 'tr_abandoned' => 'still_open' );
expect( true === $sweeper->sweep_order( $order ), 'a still-open abandoned payment counts as swept' );
expect( empty( $order->notes ), 'an unresolved abandoned payment must not spam order notes' );

// Issue #21 review: the reconciler completes a re-read copy, leaving the caller's copy stale.
$stale = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS );
$stale->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned' );
$fresh = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS, true );
$GLOBALS['sweeper_fresh_order'] = $fresh;
$service = new CountingCancelService();
$service->abandoned_result = array( 'tr_abandoned' => 'paid' );
$sweeper = new PaymentSweeper( $service );
expect( true === $sweeper->sweep_order( $stale ), 'an abandoned paid payment should count as swept' );
expect( 0 === $service->cancel_calls, 'the re-read paid order must not have its current attempt canceled' );
expect( 0 === $stale->saves, 'the stale order copy must never be saved' );
expect( empty( $stale->notes ), 'the stale order copy must not receive notes' );
expect( $fresh->saves >= 1, 'the re-read order should be saved' );
expect( false !== strpos( implode( '\n', $fresh->notes ), 'tr_abandoned' ), 'the re-read order should receive the abandoned payment note' );
unset( $GLOBALS['sweeper_fresh_order'] );

// The sweep must filter inside the order query. The legacy posts order store
// ignores 'meta_query' (a doing_it_wrong notice at most), which made the cron
// load the oldest orders of any kind, refunds included, and fatal on
// WC_Order_Refund::is_paid().
$captured_order_queries = array();
$sweeper->sweep();
expect( 2 === count( $captured_order_queries ), 'the sweep runs one order query per meta key' );
foreach ( $captured_order_queries as $args ) {
	expect( 'shop_order' === ( $args['type'] ?? '' ), 'the sweep must ask for orders only, never refunds' );
	expect( ! array_key_exists( 'meta_query', $args ), 'meta_query is ignored by the posts store and must not be used' );
	expect( 'EXISTS' === ( $args['meta_compare'] ?? '' ), 'the sweep must filter on the meta key existing' );
}
expect(
	array( PaymentAttempt::META_CURRENT_PAYMENT_ID, PaymentAttempt::META_ABANDONED_PAYMENT_IDS ) === array_column( $captured_order_queries, 'meta_key' ),
	'the sweep must query the current-attempt key and then the abandoned-payments key'
);

// #31 review: orders whose attempt the sweep skips (paid_unverified, canceled)
// keep their meta and stay the oldest matches. A full batch of them must not hide
// a newer order whose completion died: the sweep pages past them on the same run.
$GLOBALS['sweeper_current_pool'] = array();
for ( $i = 0; $i < 30; $i++ ) {
	$skipped = make_sweeper_order( 0 === $i % 2 ? PaymentAttempt::STATUS_PAID_UNVERIFIED : 'canceled', 20 * MINUTE_IN_SECONDS );
	$skipped->id = 5000 + $i;
	$GLOBALS['sweeper_current_pool'][] = $skipped;
}
$recoverable = make_sweeper_order( 'paid', 20 * MINUTE_IN_SECONDS );
$recoverable->id = 6000;
$GLOBALS['sweeper_current_pool'][] = $recoverable;
$service = new CountingCancelService();
$sweeper = new PaymentSweeper( $service );
$captured_order_queries = array();
$sweeper->sweep();
expect( array( array( 6000, 'stale_sweep' ) ) === $service->polled, 'the sweep must reach a recoverable order behind a full batch of skipped ones on the same run (recovered: ' . json_encode( $service->polled ) . ')' );
expect( true === $recoverable->paid, 'the recoverable order is completed on that run' );
expect( 0 === $service->cancel_calls, 'skipped final attempts are never canceled' );
expect( array( PaymentAttempt::META_CURRENT_PAYMENT_ID, PaymentAttempt::META_CURRENT_PAYMENT_ID, PaymentAttempt::META_ABANDONED_PAYMENT_IDS ) === array_column( $captured_order_queries, 'meta_key' ), 'the current-attempt query reads batches until a short one; the abandoned query stops at an empty one' );

// The examined set is bounded: 300 skipped orders are read in pages of 25 up to 200.
$GLOBALS['sweeper_current_pool'] = array();
for ( $i = 0; $i < 300; $i++ ) {
	$skipped = make_sweeper_order( 'canceled', 20 * MINUTE_IN_SECONDS );
	$skipped->id = 7000 + $i;
	$GLOBALS['sweeper_current_pool'][] = $skipped;
}
$captured_order_queries = array();
$sweeper->sweep();
$current_queries = array_filter( $captured_order_queries, function ( $args ) { return PaymentAttempt::META_CURRENT_PAYMENT_ID === $args['meta_key']; } );
expect( 8 === count( $current_queries ), 'a run examines at most 200 orders (8 batches of 25) per query (ran ' . count( $current_queries ) . ' batches)' );

// #31 review: a batch size that does not divide 200 still fetches at most 200.
$GLOBALS['sweeper_filters']['mtfwc_stale_payment_batch'] = 150;
$captured_order_queries = array();
$GLOBALS['sweeper_fetched'] = array();
$sweeper->sweep();
expect( 200 === ( $GLOBALS['sweeper_fetched'][ PaymentAttempt::META_CURRENT_PAYMENT_ID ] ?? 0 ), 'with batches of 150 a run fetches at most 200 orders (fetched ' . ( $GLOBALS['sweeper_fetched'][ PaymentAttempt::META_CURRENT_PAYMENT_ID ] ?? 0 ) . ')' );
$current_queries = array_values( array_filter( $captured_order_queries, function ( $args ) { return PaymentAttempt::META_CURRENT_PAYMENT_ID === $args['meta_key']; } ) );
expect( array( array( 0, 150 ), array( 150, 50 ) ) === array_map( function ( $args ) { return array( $args['offset'], $args['limit'] ); }, $current_queries ), 'with batches of 150 the second batch is cut to 50, so at most 200 orders are fetched (got ' . json_encode( array_map( function ( $args ) { return array( $args['offset'], $args['limit'] ); }, $current_queries ) ) . ')' );
unset( $GLOBALS['sweeper_filters']['mtfwc_stale_payment_batch'] );

// #31 review: acting is bounded per list, one batch (25) each, so a full
// current-attempt list cannot starve the abandoned-only list.
$GLOBALS['sweeper_current_pool'] = array();
for ( $i = 0; $i < 60; $i++ ) {
	$stale = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS );
	$stale->id = 8000 + $i;
	$GLOBALS['sweeper_current_pool'][] = $stale;
}
for ( $i = 0; $i < 60; $i++ ) {
	$abandoned_only = make_sweeper_order( '', 0 );
	$abandoned_only->id = 9000 + $i;
	$abandoned_only->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_abandoned_' . $i );
	$GLOBALS['sweeper_abandoned_pool'][] = $abandoned_only;
}
// An order on both lists is swept once, as part of the current-attempt batch.
$GLOBALS['sweeper_abandoned_pool'][0] = $GLOBALS['sweeper_current_pool'][0];
$service = new CountingCancelService();
( new PaymentSweeper( $service ) )->sweep();
expect( 25 === $service->cancel_calls, 'the current-attempt list acts on one batch per run (canceled ' . $service->cancel_calls . ')' );
expect( 25 === $service->abandoned_calls, 'a full current-attempt list must not starve the abandoned-only list of its batch (resolved ' . $service->abandoned_calls . ')' );

// #31 review: an order on both lists past the first batch's budget (position 26+
// of the in-progress list) is not swept by the first batch, so the abandoned
// batch must still sweep it.
$late_overlap = $GLOBALS['sweeper_current_pool'][40];
$late_overlap->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_late_overlap' );
$GLOBALS['sweeper_abandoned_pool'] = array( $late_overlap );
$service = new CountingCancelService();
( new PaymentSweeper( $service ) )->sweep();
expect( 1 === $service->abandoned_calls, 'an overlapping order past the first batch\'s budget must still be checked by the abandoned batch (resolved ' . $service->abandoned_calls . ')' );
expect( 26 === $service->cancel_calls, 'the first batch cancels its 25; the abandoned batch sweeps the late overlapping order once, including its stale current attempt (canceled ' . $service->cancel_calls . ')' );
$GLOBALS['sweeper_current_pool'] = array();
$GLOBALS['sweeper_abandoned_pool'] = array();

// #32 review: a site that lowers the action budget to 1 must not multiply the
// scan queries: scanning keeps batches of at least 25 (at most 8 per list),
// while acting is still limited to 1 order per list.
$GLOBALS['sweeper_filters']['mtfwc_stale_payment_batch'] = 1;
for ( $i = 0; $i < 300; $i++ ) {
	$stale = make_sweeper_order( 'open', 20 * MINUTE_IN_SECONDS );
	$stale->id = 10000 + $i;
	$GLOBALS['sweeper_current_pool'][] = $stale;
	$abandoned_only = make_sweeper_order( '', 0 );
	$abandoned_only->id = 20000 + $i;
	$abandoned_only->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_budget_' . $i );
	$GLOBALS['sweeper_abandoned_pool'][] = $abandoned_only;
}
$captured_order_queries = array();
$GLOBALS['sweeper_fetched'] = array();
$service = new CountingCancelService();
( new PaymentSweeper( $service ) )->sweep();
$per_list = array_count_values( array_column( $captured_order_queries, 'meta_key' ) );
expect( 8 === ( $per_list[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] ?? 0 ) && 8 === ( $per_list[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] ?? 0 ), 'with the budget at 1 each list is scanned in at most 8 queries (ran ' . json_encode( $per_list ) . ')' );
expect( 200 === $GLOBALS['sweeper_fetched'][ PaymentAttempt::META_CURRENT_PAYMENT_ID ] && 200 === $GLOBALS['sweeper_fetched'][ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ], 'the 200-order scan cap still holds' );
expect( 1 === $service->cancel_calls && 1 === $service->abandoned_calls, 'the action budget of 1 per list still holds' );
unset( $GLOBALS['sweeper_filters']['mtfwc_stale_payment_batch'] );
$GLOBALS['sweeper_current_pool'] = array();
$GLOBALS['sweeper_abandoned_pool'] = array();

echo "payment-sweeper ok\n";
