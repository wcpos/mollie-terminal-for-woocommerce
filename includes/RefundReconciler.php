<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieRefundPostUnansweredException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException;
use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

class RefundReconciler {
	public const META_ATTEMPT_ID = '_mtfwc_refund_attempt_id';
	public const META_MOLLIE_REFUND_ID = '_mtfwc_mollie_refund_id';
	public const META_STATUS = '_mtfwc_refund_status';
	public const META_AMOUNT = '_mtfwc_refund_amount';
	/** Cron hook that asks Mollie again about a refund whose POST went unanswered. */
	public const REASK_HOOK = 'mtfwc_reask_refund';
	/** Seconds before the first re-ask, and between re-asks; Mollie lists a refund it made at once, so this is for its outage. */
	public const REASK_DELAY = 120;
	/** Re-asks before giving up with an order note for staff. */
	public const REASK_LIMIT = 5;
	private $client;
	public function __construct( MollieApiClient $client ) { $this->client = $client; }

	public function refund( $order, $woo_refund, string $amount, string $reason = '', ?string $payment_id = null ): array {
		return PaymentLock::with_lock( (int) $order->get_id(), 'refund', function () use ( $order, $woo_refund, $amount, $reason, $payment_id ) {
			if ( null === $payment_id ) {
				$payment_id = (string) $order->get_transaction_id();
				if ( '' === $payment_id ) { $current = PaymentAttempt::current( $order ); $payment_id = $current['payment_id'] ?? ''; }
			}
			$existing = (string) $woo_refund->get_meta( self::META_MOLLIE_REFUND_ID );
			if ( '' !== $existing ) { return $this->refresh_refund( $woo_refund, $existing, $payment_id ); }
			if ( '' === $payment_id ) { throw new RuntimeException( 'No Mollie payment found for refund.' ); }
			$payment = $this->client->get_payment( $payment_id );
			$refunds = $this->refund_items( $this->client->list_refunds( $payment_id ) );
			$attempt_id = $woo_refund->get_meta( self::META_ATTEMPT_ID );
			if ( ! $attempt_id ) {
				// Saved before the POST: a response Mollie loses after creating the refund is retried
				// under this same id, and the retry finds that refund by its metadata instead of
				// creating a second one. An unsaved id would be a new id on a fresh read.
				$attempt_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'refund_', true );
				$woo_refund->update_meta_data( self::META_ATTEMPT_ID, $attempt_id );
				$woo_refund->save();
			}
			foreach ( $refunds as $refund ) {
				$meta = $refund['metadata'] ?? array();
				if ( (string) ( $meta['order_id'] ?? '' ) === (string) $order->get_id() && (string) ( $meta['woo_refund_id'] ?? '' ) === (string) $woo_refund->get_id() && (string) ( $meta['refund_attempt_id'] ?? '' ) === (string) $attempt_id ) {
					return $this->store_refund( $woo_refund, $refund, $amount );
				}
			}
			$remaining = $this->remaining_refundable( $payment, $refunds, (string) $order->get_currency() );
			if ( ! Money::equals( Money::subtract( $remaining, Money::to_mollie_value( $amount, $order->get_currency() ), $order->get_currency() ), Money::subtract( $remaining, Money::to_mollie_value( $amount, $order->get_currency() ), $order->get_currency() ), $order->get_currency() ) ) { /* normalization only */ }
			$payload = array( 'amount' => array( 'currency' => $order->get_currency(), 'value' => Money::to_mollie_value( $amount, $order->get_currency() ) ), 'description' => $reason, 'metadata' => array( 'order_id' => (string) $order->get_id(), 'woo_refund_id' => (string) $woo_refund->get_id(), 'refund_attempt_id' => (string) $attempt_id ) );
			try {
				// The attempt id is the Idempotency-Key too: a POST Mollie received but did not answer
				// is replayed under it and Mollie hands back the refund it made.
				$refund = $this->client->create_refund( $payment_id, $payload, (string) $attempt_id );
			} catch ( MollieUnansweredException $e ) {
				// Only the POST itself: an unanswered read before it created nothing and stays an
				// error. The refund may exist now; the caller keeps the record and asks again.
				throw new MollieRefundPostUnansweredException( $e->getMessage(), (string) $attempt_id, (string) $payment_id );
			}
			return $this->store_refund( $woo_refund, $refund, $amount );
		} );
	}

	/**
	 * The first unanswered POST for a refund record: say so on the order and schedule the first ask.
	 *
	 * @param \WC_Order $order      Parent order.
	 * @param int       $refund_id  WooCommerce refund record.
	 * @param string    $payment_id Mollie payment.
	 * @param string    $amount     Refund amount, major units.
	 */
	public static function unanswered_post( $order, int $refund_id, string $payment_id, string $amount ): void {
		$order->add_order_note( sprintf( 'Mollie Terminal: the refund of %s was sent but Mollie did not answer; it is unconfirmed and will be checked again.', $amount ) );
		$order->save();
		self::schedule_reask( $refund_id, $payment_id, $amount, 1 );
	}

	/**
	 * Schedule the next ask about a refund whose POST went unanswered.
	 *
	 * @param int    $refund_id  WooCommerce refund record.
	 * @param string $payment_id Mollie payment.
	 * @param string $amount     Refund amount, major units.
	 * @param int    $try        Which ask this is.
	 */
	public static function schedule_reask( int $refund_id, string $payment_id, string $amount, int $try ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) { return; }
		$args = array( $refund_id, $payment_id, $amount, $try );
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::REASK_HOOK, $args ) ) { return; }
		wp_schedule_single_event( time() + self::REASK_DELAY, self::REASK_HOOK, $args );
	}

	/**
	 * The scheduled ask: find the refund Mollie made under the attempt id (or make it under that
	 * same id), record it on the refund record, and tell staff on the order. Mollie still not
	 * answering waits another step; after the last step staff are asked to check the dashboard.
	 *
	 * @param int    $refund_id  WooCommerce refund record.
	 * @param string $payment_id Mollie payment.
	 * @param string $amount     Refund amount, major units.
	 * @param int    $try        Which ask this is.
	 * @param MollieApiClient|null $client The client to ask with; the configured one by default.
	 */
	public static function reask( $refund_id, $payment_id, $amount, $try = 1, $client = null ): void {
		$refund = wc_get_order( (int) $refund_id );
		$order  = $refund ? wc_get_order( (int) $refund->get_parent_id() ) : false;
		if ( ! $refund || ! $order ) { return; }
		$client = $client ?: new \WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient( ( new Settings() )->api_key() );
		try {
			$result = ( new self( $client ) )->refund( $order, $refund, (string) $amount, (string) $refund->get_reason(), (string) $payment_id );
			if ( in_array( (string) $result['mollie_status'], array( 'failed', 'canceled' ), true ) ) {
				$order->add_order_note( sprintf( 'Mollie Terminal: Mollie reports the refund of %s as %s (refund %s). The WooCommerce refund record stands; check the Mollie dashboard before refunding again.', $amount, $result['mollie_status'], $result['refund_id'] ) );
			} else {
				$order->add_order_note( sprintf( 'Mollie Terminal: the refund of %s is confirmed with Mollie (refund %s, %s).', $amount, $result['refund_id'], $result['mollie_status'] ) );
			}
			$order->save();
		} catch ( PaymentLockHeldException $e ) {
			// Another operation holds the order: not an answer from Mollie, so this try is not spent.
			self::schedule_reask( (int) $refund_id, (string) $payment_id, (string) $amount, (int) $try );
		} catch ( MollieRefundPostUnansweredException | MollieUnansweredException | PaymentLockErrorException $e ) {
			// Mollie silent, or the database refusing the lock: a try is spent, so a persistent fault
			// ends with the dashboard note rather than a reschedule forever.
			if ( (int) $try >= self::REASK_LIMIT ) {
				Logger::log( 'Mollie Terminal gave up confirming a refund.', array( 'refund_id' => (int) $refund_id, 'payment_id' => $payment_id ), 'error' );
				$order->add_order_note( sprintf( 'Mollie Terminal: the refund of %s could not be confirmed with Mollie. Check it in the Mollie dashboard before refunding again.', $amount ) );
				$order->save();
				return;
			}
			self::schedule_reask( (int) $refund_id, (string) $payment_id, (string) $amount, (int) $try + 1 );
		} catch ( RuntimeException $e ) {
			// Mollie answered, and refused: no refund was made. The record stands for staff to act on.
			Logger::log( 'Mollie refused a refund on the re-ask: ' . $e->getMessage(), array( 'refund_id' => (int) $refund_id, 'payment_id' => $payment_id ), 'error' );
			$order->add_order_note( sprintf( 'Mollie Terminal: Mollie refused the refund of %s (%s); no refund was made. The WooCommerce refund record stands; refund it in the Mollie dashboard or delete the record.', $amount, $e->getMessage() ) );
			$order->save();
		}
	}

	/**
	 * A refund Mollie first reported as queued/pending/processing settles later and
	 * nothing else re-reads it (payment webhooks carry the payment, not its refunds),
	 * so a replay refreshes the stored status; Mollie being unreachable keeps the last one.
	 */
	private function refresh_refund( $woo_refund, string $refund_id, string $payment_id ): array {
		$status = (string) $woo_refund->get_meta( self::META_STATUS );
		if ( '' !== $payment_id ) {
			try {
				$remote = $this->client->get_refund( $payment_id, $refund_id );
				$status = (string) ( $remote['status'] ?? $status );
				$woo_refund->update_meta_data( self::META_STATUS, $status );
				$woo_refund->save();
			} catch ( RuntimeException $e ) { /* keep the stored status */ }
		}
		return array( 'status' => 'already_refunded', 'refund_id' => $refund_id, 'mollie_status' => $status );
	}

	private function refund_items( array $response ): array { return $response['_embedded']['refunds'] ?? $response['items'] ?? $response; }
	private function remaining_refundable( array $payment, array $refunds, string $currency ): string {
		$remaining = (string) ( $payment['amount']['value'] ?? '0.00' );
		foreach ( $refunds as $refund ) { $remaining = Money::subtract( $remaining, (string) ( $refund['amount']['value'] ?? '0.00' ), $currency ); }
		return $remaining;
	}
	private function store_refund( $woo_refund, array $refund, string $amount ): array {
		$woo_refund->update_meta_data( self::META_MOLLIE_REFUND_ID, (string) ( $refund['id'] ?? '' ) );
		$woo_refund->update_meta_data( self::META_STATUS, (string) ( $refund['status'] ?? 'queued' ) );
		$woo_refund->update_meta_data( self::META_AMOUNT, $amount );
		$woo_refund->save();
		return array( 'status' => 'refunded', 'refund_id' => (string) ( $refund['id'] ?? '' ), 'mollie_status' => (string) ( $refund['status'] ?? 'queued' ) );
	}
}
