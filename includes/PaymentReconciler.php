<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

class PaymentReconciler {
	private $settings;
	public function __construct( ?Settings $settings = null ) { $this->settings = $settings ?: new Settings(); }

	public function reconcile( $order, array $payment, string $source ): array {
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
		$order->set_transaction_id( $payment_id );
		$order->payment_complete( $payment_id );
		$order->add_order_note( sprintf( 'Mollie Terminal payment completed via %s.', $source ) );
		$order->save();
		return array( 'status' => 'paid' );
	}
}
