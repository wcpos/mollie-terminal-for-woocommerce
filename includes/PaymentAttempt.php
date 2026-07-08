<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

class PaymentAttempt {
	public const META_CURRENT_ATTEMPT_ID = '_mtfwc_current_attempt_id';
	public const META_CURRENT_PAYMENT_ID = '_mtfwc_current_payment_id';
	public const META_CURRENT_TERMINAL_ID = '_mtfwc_current_terminal_id';
	public const META_CURRENT_PAYMENT_STATUS = '_mtfwc_current_payment_status';
	public const META_CURRENT_PAYMENT_CREATED_AT = '_mtfwc_current_payment_created_at';
	public const META_ATTEMPTS = '_mtfwc_payment_attempts';

	public static function current( $order ): ?array {
		$payment_id = $order->get_meta( self::META_CURRENT_PAYMENT_ID );
		if ( ! $payment_id ) { return null; }
		return array(
			'attempt_id' => (string) $order->get_meta( self::META_CURRENT_ATTEMPT_ID ),
			'payment_id' => (string) $payment_id,
			'terminal_id' => (string) $order->get_meta( self::META_CURRENT_TERMINAL_ID ),
			'status' => (string) $order->get_meta( self::META_CURRENT_PAYMENT_STATUS ),
			'created_at' => (string) $order->get_meta( self::META_CURRENT_PAYMENT_CREATED_AT ),
		);
	}

	public static function record_new( $order, array $payment, string $terminal_id, string $mode ): array {
		$attempt = array(
			'attempt_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'attempt_', true ),
			'payment_id' => self::payment_id( $payment ),
			'terminal_id' => $terminal_id,
			'status' => self::payment_status( $payment ),
			'amount' => (string) ( $payment['amount']['value'] ?? '' ),
			'currency' => (string) ( $payment['amount']['currency'] ?? '' ),
			'mode' => $mode,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		$order->update_meta_data( self::META_CURRENT_ATTEMPT_ID, $attempt['attempt_id'] );
		$order->update_meta_data( self::META_CURRENT_PAYMENT_ID, $attempt['payment_id'] );
		$order->update_meta_data( self::META_CURRENT_TERMINAL_ID, $terminal_id );
		$order->update_meta_data( self::META_CURRENT_PAYMENT_STATUS, $attempt['status'] );
		$order->update_meta_data( self::META_CURRENT_PAYMENT_CREATED_AT, $attempt['created_at'] );
		$history = self::history( $order );
		$history[] = $attempt;
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
		return $attempt;
	}

	public static function update_status( $order, array $payment ): void {
		$payment_id = self::payment_id( $payment );
		$status = self::payment_status( $payment );
		$order->update_meta_data( self::META_CURRENT_PAYMENT_STATUS, $status );
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( ( $attempt['payment_id'] ?? '' ) === $payment_id ) {
				$attempt['status'] = $status;
				$attempt['updated_at'] = gmdate( 'c' );
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
	}

	/**
	 * Detach the current attempt from the order without touching Mollie.
	 *
	 * Used when a payment cannot be canceled remotely (typically an
	 * unresponsive/offline terminal): the cashier must regain control and be
	 * able to start a fresh payment or pick another method. The attempt is
	 * marked "abandoned" in the history for auditability, and the current
	 * pointer is cleared so start_payment_for_order() no longer reuses it. The
	 * lingering Mollie payment is reconciled later by the webhook (looked up via
	 * metadata order_id) or canceled by the stale-payment sweep.
	 */
	public static function abandon_current( $order ): void {
		$payment_id = (string) $order->get_meta( self::META_CURRENT_PAYMENT_ID );
		if ( '' !== $payment_id ) {
			$history = self::history( $order );
			foreach ( $history as &$attempt ) {
				if ( ( $attempt['payment_id'] ?? '' ) === $payment_id && self::is_non_final( (string) ( $attempt['status'] ?? '' ) ) ) {
					$attempt['status'] = 'abandoned';
					$attempt['updated_at'] = gmdate( 'c' );
				}
			}
			unset( $attempt );
			$order->update_meta_data( self::META_ATTEMPTS, $history );
		}
		$order->delete_meta_data( self::META_CURRENT_ATTEMPT_ID );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_ID );
		$order->delete_meta_data( self::META_CURRENT_TERMINAL_ID );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_STATUS );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_CREATED_AT );
		$order->save();
	}

	public static function history( $order ): array {
		$history = $order->get_meta( self::META_ATTEMPTS );
		return is_array( $history ) ? $history : array();
	}

	public static function payment_id( array $payment ): string { return (string) ( $payment['id'] ?? '' ); }
	public static function payment_status( array $payment ): string { return (string) ( $payment['status'] ?? 'unknown' ); }
	public static function is_final_unpaid( string $status ): bool { return in_array( $status, array( 'failed', 'canceled', 'expired' ), true ); }
	public static function is_non_final( string $status ): bool { return in_array( $status, array( 'open', 'pending', 'authorized', '' ), true ); }
}
