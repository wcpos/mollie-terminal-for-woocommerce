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
		if ( isset( $payment['method'] ) && 'pointofsale' !== $payment['method'] ) { $errors[] = 'payment method is not pointofsale'; }
		if ( isset( $payment['mode'] ) && $payment['mode'] !== $this->settings->mode() ) { $errors[] = 'environment mismatch'; }
		return array( 'valid' => empty( $errors ), 'errors' => $errors );
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
