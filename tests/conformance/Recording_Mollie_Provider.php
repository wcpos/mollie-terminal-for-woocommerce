<?php
/**
 * The real adapter with each operation recorded on the fixture's transcript: the lessons count
 * what Pro asked the adapter to do, not the wire messages behind it.
 *
 * @package WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

/**
 * Registered in place of Mollie_Server_Provider for the suite. The adapter's own client (WordPress
 * HTTP) is used; the scripted Mollie sits behind `pre_http_request`. Every op records the mode the
 * credentials in use belong to.
 */
final class Recording_Mollie_Provider extends Mollie_Server_Provider {
	/**
	 * The installed fixture; set before Pro constructs the adapter.
	 *
	 * @var Mollie_Conformance_Fixture|null
	 */
	public static $fixture;

	/** {@inheritDoc} */
	public function create_reader_action( array $row, string $reader_id ) {
		$result = parent::create_reader_action( $row, $reader_id );
		// Mollie honours the Idempotency-Key (the row id): the payment a lost response created is
		// the one a replay gets back, so the fake knows it even when the adapter does not.
		$payment = self::$fixture->transport->payment_for_key( (string) $row['id'] ) ?? '';
		self::$fixture->record( 'create', $payment, 'amount=' . $row['amount'] . ' currency=' . $row['currency'] . ' reader=' . $reader_id . ' mode=' . self::mode() );
		return $result;
	}

	/** {@inheritDoc} */
	public function fetch( string $ref ) {
		self::$fixture->transport->advance( $ref );
		$result = parent::fetch( $ref );
		self::$fixture->record( 'fetch', $ref, 'mode=' . self::mode() );
		return $result;
	}

	/** {@inheritDoc} */
	public function cancel( string $ref ) {
		$result = parent::cancel( $ref );
		self::$fixture->record( 'cancel', $ref, 'mode=' . self::mode() );
		return $result;
	}

	/** {@inheritDoc} */
	public function refund( array $row, int $refund_id, string $amount ) {
		$result  = parent::refund( $row, $refund_id, $amount );
		$payment = (string) ( $row['provider_refs']['action'] ?? $row['provider_refs']['transaction_id'] ?? '' );
		self::$fixture->record( 'refund', $payment, 'amount=' . $amount . ' currency=' . $row['currency'] . ' mode=' . self::mode() . ' transaction_id=' . self::$fixture->alias( $payment ) );
		return $result;
	}

	/** {@inheritDoc} */
	public function verify_webhook( \WP_REST_Request $request ) {
		self::$fixture->record( 'webhook', (string) $request->get_param( 'id' ), 'event=payment.updated' );
		return parent::verify_webhook( $request );
	}

	/** The mode the configured credentials belong to. */
	private static function mode(): string {
		return ( new Settings() )->mode();
	}
}
