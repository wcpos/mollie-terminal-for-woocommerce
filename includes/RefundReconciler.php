<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

class RefundReconciler {
	public const META_ATTEMPT_ID = '_mtfwc_refund_attempt_id';
	public const META_MOLLIE_REFUND_ID = '_mtfwc_mollie_refund_id';
	public const META_STATUS = '_mtfwc_refund_status';
	public const META_AMOUNT = '_mtfwc_refund_amount';
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
			if ( ! $attempt_id ) { $attempt_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'refund_', true ); $woo_refund->update_meta_data( self::META_ATTEMPT_ID, $attempt_id ); }
			foreach ( $refunds as $refund ) {
				$meta = $refund['metadata'] ?? array();
				if ( (string) ( $meta['order_id'] ?? '' ) === (string) $order->get_id() && (string) ( $meta['woo_refund_id'] ?? '' ) === (string) $woo_refund->get_id() && (string) ( $meta['refund_attempt_id'] ?? '' ) === (string) $attempt_id ) {
					return $this->store_refund( $woo_refund, $refund, $amount );
				}
			}
			$remaining = $this->remaining_refundable( $payment, $refunds, (string) $order->get_currency() );
			if ( ! Money::equals( Money::subtract( $remaining, Money::to_mollie_value( $amount, $order->get_currency() ), $order->get_currency() ), Money::subtract( $remaining, Money::to_mollie_value( $amount, $order->get_currency() ), $order->get_currency() ), $order->get_currency() ) ) { /* normalization only */ }
			$payload = array( 'amount' => array( 'currency' => $order->get_currency(), 'value' => Money::to_mollie_value( $amount, $order->get_currency() ) ), 'description' => $reason, 'metadata' => array( 'order_id' => (string) $order->get_id(), 'woo_refund_id' => (string) $woo_refund->get_id(), 'refund_attempt_id' => (string) $attempt_id ) );
			$refund = $this->client->create_refund( $payment_id, $payload );
			return $this->store_refund( $woo_refund, $refund, $amount );
		} );
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
