<?php
// Regression #21: webhook and poll can complete one Mollie payment twice,
// reducing stock twice. Both requests load the unpaid order before either
// reconciles, so the second request still holds an unpaid in-memory copy
// after the first has paid the database row. Completion must use fresh state.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
class NoopWooLoggerForRace { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForRace(); }

require_once __DIR__ . '/support/fake-wpdb.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentLock;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

class FakeRaceClient extends MollieApiClient {
	public $payment;
	public $gets = 0;
	public function __construct( array $payment ) { $this->payment = $payment; }
	public function get_payment( string $payment_id, array $include = array() ): array { $this->gets++; return $this->payment; }
}

class FakeRaceOrder {
	private $row;
	private $changed_fields = array();
	private $changed_meta = array();

	public function __construct( array $row ) { $this->row = $row; }
	public function get_id() { return 38029; }
	public function get_total() { return '45.00'; }
	public function get_currency() { return 'EUR'; }
	public function get_meta( $key ) { return $this->row['meta'][ $key ] ?? ''; }
	public function get_transaction_id() { return $this->row['transaction_id']; }
	public function get_payment_method() { return $this->row['payment_method']; }
	public function get_payment_method_title() { return $this->row['payment_method_title']; }
	public function is_paid() { return in_array( $this->row['status'], array( 'processing', 'completed' ), true ); }

	public function update_meta_data( $key, $value ) {
		if ( ! array_key_exists( $key, $this->row['meta'] ) || $this->row['meta'][ $key ] !== $value ) {
			$this->row['meta'][ $key ] = $value;
			$this->changed_meta[ $key ] = true;
		}
	}

	public function delete_meta_data( $key ) {
		if ( array_key_exists( $key, $this->row['meta'] ) ) {
			unset( $this->row['meta'][ $key ] );
			$this->changed_meta[ $key ] = true;
		}
	}

	public function set_transaction_id( $id ) {
		if ( $this->row['transaction_id'] !== $id ) {
			$this->row['transaction_id'] = $id;
			$this->changed_fields['transaction_id'] = true;
		}
	}

	public function set_payment_method( $method ) {
		if ( $this->row['payment_method'] !== $method ) {
			$this->row['payment_method'] = $method;
			$this->changed_fields['payment_method'] = true;
		}
	}

	public function set_payment_method_title( $title ) {
		if ( $this->row['payment_method_title'] !== $title ) {
			$this->row['payment_method_title'] = $title;
			$this->changed_fields['payment_method_title'] = true;
		}
	}

	public function add_order_note( $note ) { $GLOBALS['mtfwc_order_notes'][] = $note; }

	// WC_Data::read_meta_data(): the 'orders' meta cache unless forced to read the
	// data store, which with HPOS data caching still serves OrdersTableDataStoreMeta's cache.
	public function read_meta_data( $force_read = false ) {
		if ( $force_read ) {
			$this->row['meta'] = $GLOBALS['mtfwc_hpos_meta_cache'][ $this->get_id() ] ?? $GLOBALS['mtfwc_order_rows'][ $this->get_id() ]['meta'];
			$GLOBALS['mtfwc_meta_cache'][ $this->get_id() ] = $this->row['meta'];
			$this->changed_meta = array();
		}
	}

	public function save() {
		$GLOBALS['mtfwc_saves']++;
		// WC_Data persists changes, not the entire stale snapshot; notes are immediate.
		foreach ( $this->changed_fields as $field => $changed ) {
			$GLOBALS['mtfwc_order_rows'][ $this->get_id() ][ $field ] = $this->row[ $field ];
		}
		foreach ( $this->changed_meta as $key => $changed ) {
			if ( array_key_exists( $key, $this->row['meta'] ) ) {
				$GLOBALS['mtfwc_order_rows'][ $this->get_id() ]['meta'][ $key ] = $this->row['meta'][ $key ];
			} else {
				unset( $GLOBALS['mtfwc_order_rows'][ $this->get_id() ]['meta'][ $key ] );
			}
		}
		$this->changed_fields = array();
		$this->changed_meta = array();
	}

	public function payment_complete( $transaction_id ) {
		expect( isset( $GLOBALS['wpdb']->rows['mtfwc_lock_order_38029_complete_payment'] ), 'completion must hold the claim' );
		if ( $GLOBALS['mtfwc_throw_completion'] ) {
			$GLOBALS['mtfwc_throw_completion'] = false;
			throw new RuntimeException( 'completion failed' );
		}
		if ( ! in_array( $this->row['status'], array( 'pending', 'failed', 'on-hold' ), true ) ) { return false; }
		$this->row['status'] = 'processing';
		$this->changed_fields['status'] = true;
		$this->set_transaction_id( $transaction_id );
		// Stand in for wc_maybe_reduce_stock_levels on woocommerce_payment_complete:
		// its stock guard is also read-then-write across requests.
		$GLOBALS['mtfwc_stock_reductions']++;
		$GLOBALS['mtfwc_payment_complete_calls']++;
		$this->save();
		return true;
	}
}

// Each request keeps the order it loaded until clean_post_cache() (posts store)
// or OrderCache::remove() (HPOS) drops it. HPOS datastore caching keeps the
// order row until clear_cached_data(), and order meta comes from the request's
// 'orders' meta cache, which none of those clear, until read_meta_data(true).
// Under that, HPOS data caching keeps order meta in OrdersTableDataStoreMeta's
// cache until its clear_cached_data(), which even a forced read goes through.
// Tests put a stale entry in those caches to stand in for this request's.
function wc_get_order( $id ) {
	if ( $GLOBALS['mtfwc_missing_order'] || ! isset( $GLOBALS['mtfwc_order_rows'][ $id ] ) ) { return false; }
	if ( isset( $GLOBALS['mtfwc_hpos_cache'][ $id ] ) ) { return clone $GLOBALS['mtfwc_hpos_cache'][ $id ]; }
	$row = $GLOBALS['mtfwc_hpos_data_cache'][ $id ] ?? $GLOBALS['mtfwc_order_rows'][ $id ];
	if ( isset( $GLOBALS['mtfwc_hpos_meta_cache'][ $id ] ) ) { $row['meta'] = $GLOBALS['mtfwc_hpos_meta_cache'][ $id ]; }
	if ( isset( $GLOBALS['mtfwc_meta_cache'][ $id ] ) ) { $row['meta'] = $GLOBALS['mtfwc_meta_cache'][ $id ]; }
	if ( isset( $GLOBALS['mtfwc_hpos_data_cache'][ $id ] ) ) { return new FakeRaceOrder( $row ); }
	if ( ! isset( $GLOBALS['mtfwc_post_cache'][ $id ] ) ) {
		$GLOBALS['mtfwc_post_cache'][ $id ] = new FakeRaceOrder( $row );
	}
	return clone $GLOBALS['mtfwc_post_cache'][ $id ];
}
function clean_post_cache( $id ) {
	unset( $GLOBALS['mtfwc_post_cache'][ $id ] );
	$GLOBALS['mtfwc_cleaned_posts'][] = $id;
}

function reset_race_order() {
	$GLOBALS['mtfwc_order_rows'] = array(
		38029 => array(
			'status' => 'pending',
			'transaction_id' => '',
			'payment_method' => '',
			'payment_method_title' => '',
			'meta' => array(
				PaymentAttempt::META_CURRENT_PAYMENT_ID => 'tr_7urSttWjQiFgHYcNHfYXJ',
				PaymentAttempt::META_CURRENT_PAYMENT_STATUS => 'open',
				PaymentAttempt::META_CURRENT_PAYMENT_METHOD => 'pointofsale',
			),
		),
	);
	$GLOBALS['mtfwc_order_notes'] = array();
	$GLOBALS['mtfwc_stock_reductions'] = 0;
	$GLOBALS['mtfwc_payment_complete_calls'] = 0;
	$GLOBALS['mtfwc_post_cache'] = array();
	$GLOBALS['mtfwc_hpos_cache'] = array();
	$GLOBALS['mtfwc_hpos_data_cache'] = array();
	$GLOBALS['mtfwc_meta_cache'] = array();
	$GLOBALS['mtfwc_hpos_meta_cache'] = array();
	$GLOBALS['mtfwc_cleaned_posts'] = array();
	$GLOBALS['mtfwc_saves'] = 0;
	$GLOBALS['mtfwc_throw_completion'] = false;
	$GLOBALS['mtfwc_missing_order'] = false;
}

$payment = array(
	'id' => 'tr_7urSttWjQiFgHYcNHfYXJ',
	'status' => 'paid',
	'method' => 'pointofsale',
	'mode' => 'live',
	'amount' => array( 'value' => '45.00', 'currency' => 'EUR' ),
	'metadata' => array( 'order_id' => '38029' ),
);
$reconciler = new PaymentReconciler( new Settings( array( 'mode' => 'live' ) ) );

// Scenario 1: webhook and poll load their copies while the same order is unpaid.
reset_race_order();
$webhook_copy = wc_get_order( 38029 );
$poll_copy = wc_get_order( 38029 );
$webhook_result = $reconciler->reconcile( $webhook_copy, $payment, 'webhook' );
expect( 'paid' === ( $webhook_result['status'] ?? '' ), 'the webhook must reconcile the paid payment successfully' );
$poll_result = $reconciler->reconcile( $poll_copy, $payment, 'poll' );
expect( 'paid' === ( $poll_result['status'] ?? '' ), 'the poll must reconcile the same paid payment successfully' );
// Check stock first so the unfixed code fails on the merchant-visible regression.
expect( 1 === $GLOBALS['mtfwc_stock_reductions'], 'a paid payment reconciled by webhook and poll must reduce stock once (reduced ' . $GLOBALS['mtfwc_stock_reductions'] . ' times)' );
expect( 1 === $GLOBALS['mtfwc_payment_complete_calls'], 'webhook and poll must complete payment once (completed ' . $GLOBALS['mtfwc_payment_complete_calls'] . ' times)' );
expect( true === ( $poll_result['idempotent'] ?? false ), 'the poll using a stale unpaid copy must report idempotent completion' );
expect( $payment['id'] === $GLOBALS['mtfwc_order_rows'][38029]['transaction_id'], 'the order must retain the Mollie payment transaction ID' );
expect( 'processing' === $GLOBALS['mtfwc_order_rows'][38029]['status'], 'the paid order must remain processing' );
$completion_notes = array_filter( $GLOBALS['mtfwc_order_notes'], function ( $note ) {
	return false !== strpos( $note, 'Mollie Terminal payment completed via' );
} );
expect( 1 === count( $completion_notes ), 'webhook and poll must write exactly one payment completion note (wrote ' . count( $completion_notes ) . ')' );
expect( array( 38029, 38029 ) === $GLOBALS['mtfwc_cleaned_posts'], 'both paid reconciliations must invalidate the post cache' );

// Scenario 2: another transaction paid the order after this request loaded it.
reset_race_order();
$stale = wc_get_order( 38029 );
$GLOBALS['mtfwc_order_rows'][38029]['status'] = 'processing';
$GLOBALS['mtfwc_order_rows'][38029]['transaction_id'] = 'tr_otherPayment';
$conflict_result = $reconciler->reconcile( $stale, $payment, 'poll' );
expect( 'conflict' === ( $conflict_result['status'] ?? '' ), 'a stale copy of an order paid by another transaction must report conflict' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'], 'a conflicting payment must not call payment_complete()' );
expect( 0 === $GLOBALS['mtfwc_stock_reductions'], 'a conflicting payment must not reduce stock' );
expect( 'tr_otherPayment' === $GLOBALS['mtfwc_order_rows'][38029]['transaction_id'], 'a conflicting payment must not overwrite the other transaction ID' );

// Scenario 3: another request holds the claim. Holding it is no proof the order
// gets completed: the holder can die before payment_complete(). The poll must stay
// non-terminal until the database shows the order paid by this payment (#27).
reset_race_order();
$key = 'mtfwc_lock_order_38029_complete_payment';
$unchanged = $GLOBALS['mtfwc_order_rows'][38029];
expect( PaymentLock::acquire( 38029, 'complete_payment', 120 ), 'another request claims completion' );
$busy = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( array( 'status' => 'pending', 'completing' => true, 'retry_allowed' => false ) === $busy, 'a held claim on a still-unpaid order must keep the poll non-terminal (got ' . json_encode( $busy ) . ')' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'] && 0 === $GLOBALS['mtfwc_stock_reductions'], 'a busy completion must not complete or reduce stock' );
expect( $unchanged === $GLOBALS['mtfwc_order_rows'][38029] && 0 === $GLOBALS['mtfwc_saves'], 'a busy completion must not touch or save the order' );
expect( array( 38029 ) === $GLOBALS['mtfwc_cleaned_posts'], 'a busy completion must re-read the order before answering' );
$invalid_payment = $payment;
$invalid_payment['amount']['value'] = '99.00';
$invalid_busy = $reconciler->reconcile( wc_get_order( 38029 ), $invalid_payment, 'poll' );
expect( array( 'status' => 'pending', 'retry_allowed' => false ) === $invalid_busy, 'a busy completion must not report an unverified payment as paid' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'] && 0 === $GLOBALS['mtfwc_stock_reductions'], 'an unverified busy completion must not complete or reduce stock' );
expect( $unchanged === $GLOBALS['mtfwc_order_rows'][38029] && 0 === $GLOBALS['mtfwc_saves'], 'an unverified busy completion must not touch or save the order' );
PaymentLock::release( 38029, 'complete_payment' );
$retried = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( 'paid' === $retried['status'] && 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'the next poll must complete exactly once' );
expect( ! isset( $wpdb->rows[ $key ] ), 'successful completion must release the claim' );

// Scenario 3b: the claim holder has completed the order; this request still holds
// an unpaid copy. The re-read shows it paid by this payment, so report paid.
reset_race_order();
$stale = wc_get_order( 38029 );
expect( PaymentLock::acquire( 38029, 'complete_payment', 120 ), 'the completing request holds the claim' );
$GLOBALS['mtfwc_order_rows'][38029]['status'] = 'processing';
$GLOBALS['mtfwc_order_rows'][38029]['transaction_id'] = $payment['id'];
$done = $reconciler->reconcile( $stale, $payment, 'poll' );
expect( 'paid' === ( $done['status'] ?? '' ), 'a held claim on an order already paid by this payment must report paid (got ' . json_encode( $done ) . ')' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'] && 0 === $GLOBALS['mtfwc_saves'], 'reporting a completed order must not complete or save again' );
// Paid by another transaction while the claim is held: not this payment's completion.
$GLOBALS['mtfwc_order_rows'][38029]['transaction_id'] = 'tr_otherPayment';
$other = $reconciler->reconcile( $stale, $payment, 'poll' );
expect( 'paid' !== ( $other['status'] ?? '' ), 'a held claim must not report paid for an order paid by another transaction' );
PaymentLock::release( 38029, 'complete_payment' );

// Scenario 3c: the claim holder died before payment_complete(). Polls stay
// non-terminal while its claim is live, and the first poll after it expires
// takes over and completes the order once.
reset_race_order();
$wpdb->rows[ $key ] = json_encode( array( 'token' => 'dying', 'expires_at' => time() + 120 ) );
$waiting = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( 'paid' !== ( $waiting['status'] ?? '' ), 'a dead holder\'s live claim must not make the poll report paid' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'], 'a dead holder\'s live claim must not complete the order' );
$wpdb->rows[ $key ] = json_encode( array( 'token' => 'dying', 'expires_at' => time() - 1 ) );
$taken_over = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( 'paid' === $taken_over['status'] && 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'the poll must take over an expired claim and complete once' );

// Scenario 3d: the claim insert fails (database error). Nobody holds the claim,
// so a verified paid payment must not be reported paid; the next poll retries.
reset_race_order();
$wpdb->insert_error = true;
expect( PaymentLock::ERROR === PaymentLock::claim( 38029, 'complete_payment', 120 ), 'a failed claim insert must report a database error, not a held claim' );
$errored = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( array( 'status' => 'pending', 'completing' => true, 'retry_allowed' => false ) === $errored, 'a database error on the claim must keep the poll non-terminal, never paid (got ' . json_encode( $errored ) . ')' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'] && 0 === $GLOBALS['mtfwc_saves'], 'a database error on the claim must not complete or save the order' );
try {
	PaymentLock::with_lock( 38029, 'complete_payment', function () { expect( false, 'with_lock must not run its callback without the claim' ); } );
	expect( false, 'with_lock must throw when the claim cannot be written' );
} catch ( RuntimeException $e ) {
	expect( false !== strpos( $e->getMessage(), 'already running' ), 'with_lock keeps its exception on a failed claim' );
}
$wpdb->insert_error = false;
$recovered = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( 'paid' === $recovered['status'] && 1 === $GLOBALS['mtfwc_payment_complete_calls'], 'the poll after the database recovers must complete once' );

// Scenario 3e (#28 review): the claim holder died after update_status() saved the
// attempt as paid but before payment_complete(). poll_order() must not echo the
// stored "paid" for an unpaid order: it stays non-terminal while the dead claim
// is live, and the poll after it expires completes the order exactly once.
reset_race_order();
$holder_copy = wc_get_order( 38029 );
PaymentAttempt::update_status( $holder_copy, $payment );
$GLOBALS['mtfwc_post_cache'] = array();
expect( 'paid' === $GLOBALS['mtfwc_order_rows'][38029]['meta'][ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] && 'pending' === $GLOBALS['mtfwc_order_rows'][38029]['status'], 'setup: the attempt is saved paid on a still-unpaid order' );
$wpdb->rows[ $key ] = json_encode( array( 'token' => 'died-after-update', 'expires_at' => time() + 120 ) );
$poll_client = new FakeRaceClient( $payment );
$service = new MolliePaymentService( $poll_client, new Settings( array( 'mode' => 'live' ) ) );
$first = $service->poll_order( wc_get_order( 38029 ) );
expect( 'paid' !== ( $first['status'] ?? '' ), 'a stored paid attempt on an unpaid order must not be reported paid while the dead claim is live (got ' . json_encode( $first ) . ')' );
expect( true === ( $first['completing'] ?? false ), 'the poll must report the verified payment as completing' );
expect( 1 === $poll_client->gets && 0 === $GLOBALS['mtfwc_payment_complete_calls'], 'the poll must re-check Mollie and leave completion to the claim' );
$wpdb->rows[ $key ] = json_encode( array( 'token' => 'died-after-update', 'expires_at' => time() - 1 ) );
$second = $service->poll_order( wc_get_order( 38029 ) );
expect( 'paid' === ( $second['status'] ?? '' ), 'the poll after the dead claim expires must report paid (got ' . json_encode( $second ) . ')' );
expect( 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'the poll after expiry must complete the order exactly once' );
expect( 'processing' === $GLOBALS['mtfwc_order_rows'][38029]['status'] && $payment['id'] === $GLOBALS['mtfwc_order_rows'][38029]['transaction_id'], 'the order must be paid by the Mollie payment' );
$GLOBALS['mtfwc_post_cache'] = array();
$third = $service->poll_order( wc_get_order( 38029 ) );
expect( 'paid' === ( $third['status'] ?? '' ) && 2 === $poll_client->gets && 1 === $GLOBALS['mtfwc_payment_complete_calls'], 'once the order is paid, the stored paid attempt is answered without asking Mollie again' );

// Scenario 4: recover a claim left by a request that died.
reset_race_order();
$wpdb->rows[ $key ] = json_encode( array( 'token' => 'dead', 'expires_at' => time() - 60 ) );
$recovered = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'sweep' );
expect( 'paid' === $recovered['status'] && 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'an expired claim must allow one completion' );
expect( ! isset( $wpdb->rows[ $key ] ), 'recovered completion must release the claim' );

// Scenario 5: an exception must release the claim so the next request can finish.
reset_race_order();
$GLOBALS['mtfwc_throw_completion'] = true;
try {
	$reconciler->reconcile( wc_get_order( 38029 ), $payment, 'webhook' );
	expect( false, 'completion exception must propagate' );
} catch ( RuntimeException $e ) {
	expect( 'completion failed' === $e->getMessage(), 'the original exception must propagate' );
}
expect( ! isset( $wpdb->rows[ $key ] ), 'a throwing completion must release the claim' );
$retried = $reconciler->reconcile( wc_get_order( 38029 ), $payment, 'poll' );
expect( 'paid' === $retried['status'] && 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'retry after an exception must complete once' );

// Scenario 6: non-paid payments do not need the completion claim or reload.
reset_race_order();
expect( PaymentLock::acquire( 38029, 'complete_payment', 120 ), 'hold completion while checking an open payment' );
$held = $wpdb->rows[ $key ];
$open_payment = array_merge( $payment, array( 'status' => 'open' ) );
$open = $reconciler->reconcile( wc_get_order( 38029 ), $open_payment, 'poll' );
expect( 'open' === $open['status'], 'an open payment must stay open despite a held completion claim' );
expect( $held === $wpdb->rows[ $key ] && array() === $GLOBALS['mtfwc_cleaned_posts'], 'an open payment must not claim or reload' );
PaymentLock::release( 38029, 'complete_payment' );

// A missing WooCommerce order uses the object supplied by a unit-test caller.
reset_race_order();
$copy = wc_get_order( 38029 );
$GLOBALS['mtfwc_missing_order'] = true;
$fallback = $reconciler->reconcile( $copy, $payment, 'poll' );
expect( 'paid' === $fallback['status'] && $copy->is_paid(), 'a missing reload must use the given order' );

// HPOS has a separate order cache which must also be invalidated.
class FakeRaceOrderCache {
	public function remove( $id ) { unset( $GLOBALS['mtfwc_hpos_cache'][ $id ] ); }
}
class_alias( FakeRaceOrderCache::class, 'Automattic\\WooCommerce\\Caches\\OrderCache' );
// Like WooCommerce 11.1.2: a row-cache delete fails when the entry is already
// gone (expired), and the meta cache is cleared only for ids whose row delete succeeded.
class FakeRaceOrdersTableDataStore {
	public function clear_cached_data( array $order_ids ) {
		$deleted = array();
		foreach ( $order_ids as $id ) {
			$deleted[ $id ] = isset( $GLOBALS['mtfwc_hpos_data_cache'][ $id ] );
			unset( $GLOBALS['mtfwc_hpos_data_cache'][ $id ] );
		}
		( new FakeRaceOrdersTableDataStoreMeta() )->clear_cached_data( array_keys( array_filter( $deleted ) ) );
		return $deleted;
	}
}
class_alias( FakeRaceOrdersTableDataStore::class, 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' );
class FakeRaceOrdersTableDataStoreMeta {
	public function clear_cached_data( array $object_ids ) {
		foreach ( $object_ids as $id ) { unset( $GLOBALS['mtfwc_hpos_meta_cache'][ $id ] ); }
		return array_fill_keys( $object_ids, true );
	}
}
class_alias( FakeRaceOrdersTableDataStoreMeta::class, 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStoreMeta' );
function wc_get_container() {
	return new class {
		public function get( $class ) {
			if ( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' === $class ) { return new FakeRaceOrdersTableDataStore(); }
			if ( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStoreMeta' === $class ) { return new FakeRaceOrdersTableDataStoreMeta(); }
			expect( 'Automattic\\WooCommerce\\Caches\\OrderCache' === $class, 'reload must request the HPOS order cache' );
			return new FakeRaceOrderCache();
		}
	};
}
reset_race_order();
$stale = wc_get_order( 38029 );
$GLOBALS['mtfwc_hpos_cache'][38029] = clone $stale;
$GLOBALS['mtfwc_order_rows'][38029]['status'] = 'processing';
$GLOBALS['mtfwc_order_rows'][38029]['transaction_id'] = $payment['id'];
$hpos = $reconciler->reconcile( $stale, $payment, 'poll' );
expect( true === ( $hpos['idempotent'] ?? false ) && 0 === $GLOBALS['mtfwc_payment_complete_calls'], 'HPOS reload must see completion from another request' );

// #23: with HPOS datastore caching on, the order row is cached apart from OrderCache.
reset_race_order();
$webhook_copy = wc_get_order( 38029 );
$poll_copy = wc_get_order( 38029 );
$unpaid_row = $GLOBALS['mtfwc_order_rows'][38029];
$reconciler->reconcile( $webhook_copy, $payment, 'webhook' );
// The poll's request cached the row while it was unpaid.
$GLOBALS['mtfwc_hpos_data_cache'][38029] = $unpaid_row;
$cached = $reconciler->reconcile( $poll_copy, $payment, 'poll' );
expect( 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'the reload must clear the HPOS datastore cache (completed ' . $GLOBALS['mtfwc_payment_complete_calls'] . ' times)' );
expect( true === ( $cached['idempotent'] ?? false ), 'the reload past the HPOS datastore cache must see the completion from the other request' );

// #23: a stale HPOS datastore row must not complete an order another transaction paid.
reset_race_order();
$stale = wc_get_order( 38029 );
$GLOBALS['mtfwc_hpos_data_cache'][38029] = $GLOBALS['mtfwc_order_rows'][38029];
$GLOBALS['mtfwc_order_rows'][38029]['status'] = 'processing';
$GLOBALS['mtfwc_order_rows'][38029]['transaction_id'] = 'tr_otherPayment';
$conflict = $reconciler->reconcile( $stale, $payment, 'poll' );
expect( 0 === $GLOBALS['mtfwc_payment_complete_calls'] && 0 === $GLOBALS['mtfwc_stock_reductions'], 'a stale HPOS datastore row must not complete an order paid by another transaction' );
expect( 'conflict' === ( $conflict['status'] ?? '' ), 'a stale HPOS datastore row of an order paid by another transaction must report conflict' );
expect( 'tr_otherPayment' === $GLOBALS['mtfwc_order_rows'][38029]['transaction_id'], 'a stale HPOS datastore row must not overwrite the other transaction ID' );

// #23: the reload must read meta past the request's 'orders' meta cache. This
// request loaded the order while tr_7urSttWjQiFgHYcNHfYXJ was current; another
// request then started tr_newAttempt, which Mollie now reports paid.
reset_race_order();
$stale = wc_get_order( 38029 );
$old_meta = $GLOBALS['mtfwc_order_rows'][38029]['meta'];
$new_payment = array_merge( $payment, array( 'id' => 'tr_newAttempt', 'status' => 'open' ) );
PaymentAttempt::record_new( wc_get_order( 38029 ), $new_payment, 'term_1', 'live' );
$GLOBALS['mtfwc_meta_cache'][38029] = $old_meta;
$GLOBALS['mtfwc_order_notes'] = array();
$fresh_meta = $reconciler->reconcile( $stale, array_merge( $new_payment, array( 'status' => 'paid' ) ), 'poll' );
expect( array() === array_values( array_filter( $GLOBALS['mtfwc_order_notes'], function ( $note ) { return false !== strpos( $note, 'verification failed' ); } ) ), 'the reload must read meta past the meta cache, so a payment another request recorded verifies (notes: ' . implode( ' | ', $GLOBALS['mtfwc_order_notes'] ) . ')' );
expect( 'paid' === ( $fresh_meta['status'] ?? '' ) && 1 === $GLOBALS['mtfwc_payment_complete_calls'], 'a paid payment recorded by another request must complete the order once' );
expect( 'tr_newAttempt' === $GLOBALS['mtfwc_order_rows'][38029]['transaction_id'], 'the order must carry the paid payment ID' );

// #25: with HPOS data caching on, the order's row entry already expired but its
// meta entry still holds the attempt from before another request started
// tr_newAttempt. The row delete fails, so the data store leaves the meta cache,
// and a forced meta read still comes from it: the reload must clear it directly.
reset_race_order();
$stale = wc_get_order( 38029 );
$GLOBALS['mtfwc_hpos_meta_cache'][38029] = $GLOBALS['mtfwc_order_rows'][38029]['meta'];
PaymentAttempt::record_new( new FakeRaceOrder( $GLOBALS['mtfwc_order_rows'][38029] ), $new_payment, 'term_1', 'live' );
$GLOBALS['mtfwc_order_notes'] = array();
$hpos_meta = $reconciler->reconcile( $stale, array_merge( $new_payment, array( 'status' => 'paid' ) ), 'webhook' );
expect( array() === array_values( array_filter( $GLOBALS['mtfwc_order_notes'], function ( $note ) { return false !== strpos( $note, 'verification failed' ); } ) ), 'the reload must clear the HPOS meta cache even when the row-cache delete fails (notes: ' . implode( ' | ', $GLOBALS['mtfwc_order_notes'] ) . ')' );
expect( 'paid' === ( $hpos_meta['status'] ?? '' ) && 1 === $GLOBALS['mtfwc_payment_complete_calls'] && 1 === $GLOBALS['mtfwc_stock_reductions'], 'a paid payment under a stale HPOS meta cache must complete the order once' );
expect( ! isset( $GLOBALS['mtfwc_hpos_meta_cache'][38029] ), 'the reload must leave no stale HPOS meta cache entry behind' );

echo "payment-complete-race ok\n";
