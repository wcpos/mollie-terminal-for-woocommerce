<?php
// Regression #27: one abandoned-payment sweep resolves payment A as paid and
// payment B as canceled. reconcile() completes A on a re-read copy, so if B is
// then applied to the sweep's stale copy, B's save writes back the old attempt
// history and abandoned-ID list, resurrecting the paid A as abandoned.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class NoopWooLoggerForMixedBatch { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new NoopWooLoggerForMixedBatch(); }
// Every load reads the stored row, like a request after reload_order() cleared its caches.
function wc_get_order( $id ) { return isset( $GLOBALS['mixed_rows'][ $id ] ) ? new FakeMixedBatchOrder( $GLOBALS['mixed_rows'][ $id ] ) : false; }

require_once __DIR__ . '/support/fake-wpdb.php';
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
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

// WC_Data-style order: save() persists only the fields and meta keys this copy changed.
class FakeMixedBatchOrder {
	private $row;
	private $changed_fields = array();
	private $changed_meta = array();
	public function __construct( array $row ) { $this->row = $row; }
	public function get_id() { return 4242; }
	public function get_total() { return '45.00'; }
	public function get_currency() { return 'EUR'; }
	public function get_meta( $key ) { return $this->row['meta'][ $key ] ?? ''; }
	public function get_transaction_id() { return $this->row['transaction_id']; }
	public function get_payment_method() { return $this->row['payment_method']; }
	public function get_payment_method_title() { return $this->row['payment_method_title']; }
	public function is_paid() { return in_array( $this->row['status'], array( 'processing', 'completed' ), true ); }
	public function read_meta_data( $force_read = false ) {}
	public function add_order_note( $note ) { $GLOBALS['mixed_notes'][] = $note; }
	public function update_meta_data( $key, $value ) { $this->row['meta'][ $key ] = $value; $this->changed_meta[ $key ] = true; }
	public function delete_meta_data( $key ) { unset( $this->row['meta'][ $key ] ); $this->changed_meta[ $key ] = true; }
	private function set_field( $field, $value ) { $this->row[ $field ] = $value; $this->changed_fields[ $field ] = true; }
	public function set_transaction_id( $id ) { $this->set_field( 'transaction_id', $id ); }
	public function set_payment_method( $method ) { $this->set_field( 'payment_method', $method ); }
	public function set_payment_method_title( $title ) { $this->set_field( 'payment_method_title', $title ); }
	public function payment_complete( $transaction_id ) {
		$GLOBALS['mixed_payment_complete_calls']++;
		$this->set_field( 'status', 'processing' );
		$this->set_transaction_id( $transaction_id );
		$this->save();
		return true;
	}
	public function save() {
		foreach ( array_keys( $this->changed_fields ) as $field ) {
			$GLOBALS['mixed_rows'][4242][ $field ] = $this->row[ $field ];
		}
		foreach ( array_keys( $this->changed_meta ) as $key ) {
			if ( array_key_exists( $key, $this->row['meta'] ) ) {
				$GLOBALS['mixed_rows'][4242]['meta'][ $key ] = $this->row['meta'][ $key ];
			} else {
				unset( $GLOBALS['mixed_rows'][4242]['meta'][ $key ] );
			}
		}
		$this->changed_fields = array();
		$this->changed_meta = array();
	}
}

class FakeMixedBatchClient extends MollieApiClient {
	public $payments = array();
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array { return $this->payments[ $payment_id ]; }
	public function cancel_payment( string $payment_id ): array { return array(); }
}

function mixed_attempt( string $payment_id ): array {
	return array( 'payment_id' => $payment_id, 'method' => 'pointofsale', 'status' => 'abandoned', 'amount' => '45.00', 'currency' => 'EUR', 'mode' => 'live' );
}
function mixed_payment( string $payment_id, string $status ): array {
	return array( 'id' => $payment_id, 'status' => $status, 'method' => 'pointofsale', 'mode' => 'live', 'amount' => array( 'value' => '45.00', 'currency' => 'EUR' ), 'metadata' => array( 'order_id' => '4242' ) );
}

$cases = array(
	'paid then canceled' => array( 'tr_A' => 'paid', 'tr_B' => 'canceled' ),
	'paid then failed'   => array( 'tr_A' => 'paid', 'tr_B' => 'failed' ),
	'paid then expired'  => array( 'tr_A' => 'paid', 'tr_B' => 'expired' ),
	'canceled then paid' => array( 'tr_B' => 'canceled', 'tr_A' => 'paid' ),
);
foreach ( $cases as $label => $outcomes ) {
	$GLOBALS['mixed_rows'] = array(
		4242 => array(
			'status' => 'pending',
			'transaction_id' => '',
			'payment_method' => '',
			'payment_method_title' => '',
			'meta' => array(
				PaymentAttempt::META_ATTEMPTS => array_map( 'mixed_attempt', array_keys( $outcomes ) ),
				PaymentAttempt::META_ABANDONED_PAYMENT_IDS => array_keys( $outcomes ),
			),
		),
	);
	$GLOBALS['mixed_notes'] = array();
	$GLOBALS['mixed_payment_complete_calls'] = 0;
	$client = new FakeMixedBatchClient();
	foreach ( $outcomes as $payment_id => $status ) { $client->payments[ $payment_id ] = mixed_payment( $payment_id, $status ); }
	$service = new MolliePaymentService( $client, new Settings( array( 'mode' => 'live' ) ) );
	$sweeper = new PaymentSweeper( $service );

	expect( true === $sweeper->sweep_order( wc_get_order( 4242 ) ), "$label: the abandoned batch should count as swept" );
	$row = $GLOBALS['mixed_rows'][4242];
	expect( 'processing' === $row['status'] && 'tr_A' === $row['transaction_id'], "$label: the paid abandoned payment must complete the order" );
	expect( 1 === $GLOBALS['mixed_payment_complete_calls'], "$label: the order must be completed exactly once" );
	$still_abandoned = $row['meta'][ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] ?? array();
	expect( ! in_array( 'tr_A', (array) $still_abandoned, true ), "$label: a later payment's save must not resurrect the paid payment as abandoned (abandoned: " . json_encode( $still_abandoned ) . ')' );
	expect( array() === (array) $still_abandoned, "$label: every resolved payment must leave the abandoned list" );
	$history = array_column( $row['meta'][ PaymentAttempt::META_ATTEMPTS ], 'status', 'payment_id' );
	expect( 'paid' === $history['tr_A'], "$label: the attempt history must keep the paid status (history: " . json_encode( $history ) . ')' );
	expect( $outcomes['tr_B'] === $history['tr_B'], "$label: the attempt history must record the other payment's final status" );
}

echo "payment-sweeper-mixed-batch ok\n";
