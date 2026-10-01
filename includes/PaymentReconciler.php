<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

class PaymentReconciler {
	// Covers payment_complete() (status, stock, emails); a dead request's claim
	// can be taken over after this interval.
	private const COMPLETE_LOCK_TTL = 120;

	private $settings;
	public function __construct( ?Settings $settings = null ) { $this->settings = $settings ?: new Settings(); }

	public function reconcile( $order, array $payment, string $source ): array {
		if ( 'paid' !== ( $payment['status'] ?? 'unknown' ) ) {
			return $this->apply_payment( $order, $payment, $source );
		}
		$order_id = (int) $order->get_id();
		if ( ! PaymentLock::acquire( $order_id, 'complete_payment', self::COMPLETE_LOCK_TTL ) ) {
			Logger::log( 'Mollie Terminal payment completion already in progress for this order.', array( 'order_id' => $order_id, 'payment_id' => PaymentAttempt::payment_id( $payment ), 'source' => $source ), 'info' );
			// Mollie reports paid and another request holds the claim and is
			// completing the order, so tell the cashier it is paid if verified.
			if ( $this->verify_payment( $order, $payment )['valid'] ) {
				return array( 'status' => 'paid', 'completing' => true );
			}
			return array( 'status' => 'pending', 'retry_allowed' => false );
		}
		try {
			// The claim serializes completion across webhook, poll and sweep.
			// Reload: this request may hold a copy from before another completed it (#21).
			$fresh = self::reload_order( $order );
			return $this->apply_payment( $fresh, $payment, $source );
		} finally {
			PaymentLock::release( $order_id, 'complete_payment' );
		}
	}

	private static function reload_order( $order ) {
		$id = $order->get_id();
		if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $id ); }
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		}
		return function_exists( 'wc_get_order' ) ? ( wc_get_order( $id ) ?: $order ) : $order;
	}

	private function apply_payment( $order, array $payment, string $source ): array {
		$verification = $this->verify_payment( $order, $payment );
		$status = (string) ( $payment['status'] ?? 'unknown' );
		PaymentAttempt::update_status( $order, $payment );
		// Whatever resolved it — webhook, poll, cancel or the stale sweep — a
		// payment in a final state no longer needs the sweep to chase it.
		if ( PaymentAttempt::is_final( $status ) ) {
			PaymentAttempt::forget_abandoned( $order, PaymentAttempt::payment_id( $payment ) );
		}
		if ( ! $verification['valid'] ) {
			$order->add_order_note( sprintf( 'Mollie Terminal payment verification failed via %s: %s', $source, implode( '; ', $verification['errors'] ) ) );
			$order->save();
			return array( 'status' => 'verification_failed', 'payment_status' => $status, 'errors' => $verification['errors'] );
		}
		if ( 'paid' === $status ) {
			return $this->complete_paid_order( $order, $payment, $source );
		}
		if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			$order->add_order_note( sprintf( 'Mollie Terminal payment %s via %s.', $status, $source ) );
			$order->save();
			return array( 'status' => $status, 'retry_allowed' => true );
		}
		return array( 'status' => in_array( $status, array( 'open', 'pending', 'authorized' ), true ) ? $status : 'unknown', 'retry_allowed' => false );
	}

	private function verify_payment( $order, array $payment ): array {
		$errors = array();
		$payment_id = PaymentAttempt::payment_id( $payment );
		$current = PaymentAttempt::current( $order );
		$metadata_order_id = (int) ( $payment['metadata']['order_id'] ?? 0 );
		if ( $current && $current['payment_id'] !== $payment_id && $metadata_order_id !== (int) $order->get_id() ) { $errors[] = 'payment ID does not match this order'; }
		if ( isset( $payment['amount']['value'] ) && ! Money::equals( (string) $payment['amount']['value'], (string) $order->get_total(), (string) $order->get_currency() ) ) { $errors[] = 'amount mismatch'; }
		if ( isset( $payment['amount']['currency'] ) && strtoupper( (string) $payment['amount']['currency'] ) !== strtoupper( (string) $order->get_currency() ) ) { $errors[] = 'currency mismatch'; }
		$method   = (string) ( $payment['method'] ?? '' );
		$recorded = self::recorded_method( $order, $payment_id );
		if ( null === $recorded ) {
			// Every payment this plugin creates is written to the attempt history,
			// so an ID we never recorded cannot pay this order — even when its
			// metadata order_id collides (shops sharing one Mollie profile).
			$errors[] = 'payment is not known for this order';
		} elseif ( '' !== $recorded ) {
			if ( $method !== $recorded ) { $errors[] = 'payment method mismatch'; }
		} elseif ( ! in_array( $method, array_merge( array( 'pointofsale' ), $this->settings->qr_methods() ), true ) ) {
			// Attempts recorded before 0.5.0 carry no method: accept only what
			// this shop can actually have started.
			$errors[] = 'payment method is not supported';
		}
		if ( isset( $payment['mode'] ) && $payment['mode'] !== $this->settings->mode() ) { $errors[] = 'environment mismatch'; }
		return array( 'valid' => empty( $errors ), 'errors' => $errors );
	}

	/**
	 * Method this shop recorded when it created $payment_id for $order: the
	 * current attempt first, then the attempt history (abandoned attempts lose
	 * their current pointer but keep their history entry). Returns '' for an
	 * attempt recorded before methods were stored, null for an unknown payment.
	 */
	private static function recorded_method( $order, string $payment_id ): ?string {
		$current = PaymentAttempt::current( $order );
		if ( $current && $current['payment_id'] === $payment_id ) { return (string) $current['method']; }
		foreach ( PaymentAttempt::history( $order ) as $attempt ) {
			if ( ( $attempt['payment_id'] ?? '' ) === $payment_id ) { return (string) ( $attempt['method'] ?? '' ); }
		}
		return in_array( $payment_id, PaymentAttempt::abandoned( $order ), true ) ? '' : null;
	}

	private function complete_paid_order( $order, array $payment, string $source ): array {
		$payment_id = PaymentAttempt::payment_id( $payment );
		if ( $order->is_paid() ) {
			if ( $order->get_transaction_id() === $payment_id ) { return array( 'status' => 'paid', 'idempotent' => true ); }
			$order->add_order_note( 'Mollie Terminal payment paid but order already paid by another transaction.' );
			$order->save();
			return array( 'status' => 'conflict' );
		}
		// The pay form never runs for a Mollie payment, so nothing else stamps the
		// gateway on the order; do it here so payment_complete() resolves the
		// WooCommerce POS order status for this gateway, not an empty/default one.
		PaymentAttempt::claim_order_gateway( $order, $this->settings->title() );
		$order->set_transaction_id( $payment_id );
		$order->payment_complete( $payment_id );
		$order->add_order_note( sprintf( 'Mollie Terminal payment completed via %s.', $source ) );
		$order->save();
		return array( 'status' => 'paid' );
	}
}
