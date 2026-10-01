<?php
// Regression #21: webhook and poll can complete one Mollie payment twice,
// reducing stock twice. Both requests load the unpaid order before either
// reconciles, so the second request still holds an unpaid in-memory copy
// after the first has paid the database row. Completion must use fresh state.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';

use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

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

	public function save() {
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

function wc_get_order( $id ) {
	return isset( $GLOBALS['mtfwc_order_rows'][ $id ] ) ? new FakeRaceOrder( $GLOBALS['mtfwc_order_rows'][ $id ] ) : false;
}
function clean_post_cache( $id ) {}

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

echo "payment-complete-race ok\n";
