<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

class PaymentAttempt {
	public const META_CURRENT_ATTEMPT_ID = '_mtfwc_current_attempt_id';
	public const META_CURRENT_PAYMENT_ID = '_mtfwc_current_payment_id';
	public const META_CURRENT_TERMINAL_ID = '_mtfwc_current_terminal_id';
	public const META_CURRENT_PAYMENT_METHOD = '_mtfwc_current_payment_method';
	public const META_CURRENT_PAYMENT_STATUS = '_mtfwc_current_payment_status';
	public const META_CURRENT_PAYMENT_CREATED_AT = '_mtfwc_current_payment_created_at';
	public const META_ATTEMPTS = '_mtfwc_payment_attempts';
	public const META_ABANDONED_PAYMENT_IDS = '_mtfwc_abandoned_payment_ids';

	public static function current( $order ): ?array {
		$payment_id = $order->get_meta( self::META_CURRENT_PAYMENT_ID );
		if ( ! $payment_id ) { return null; }
		return array(
			'attempt_id' => (string) $order->get_meta( self::META_CURRENT_ATTEMPT_ID ),
			'payment_id' => (string) $payment_id,
			'terminal_id' => (string) $order->get_meta( self::META_CURRENT_TERMINAL_ID ),
			'method' => (string) $order->get_meta( self::META_CURRENT_PAYMENT_METHOD ),
			'status' => (string) $order->get_meta( self::META_CURRENT_PAYMENT_STATUS ),
			'created_at' => (string) $order->get_meta( self::META_CURRENT_PAYMENT_CREATED_AT ),
		);
	}

	public static function record_new( $order, array $payment, string $terminal_id, string $mode, string $method = 'pointofsale' ): array {
		$attempt = array(
			'attempt_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'attempt_', true ),
			'payment_id' => self::payment_id( $payment ),
			'terminal_id' => $terminal_id,
			'method' => $method,
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
		$order->update_meta_data( self::META_CURRENT_PAYMENT_METHOD, $method );
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
		// Only the current attempt owns the current-status pointer. A reconcile of
		// an abandoned payment (webhook or stale sweep) must not stamp its status
		// onto whatever attempt the cashier is running now.
		if ( (string) $order->get_meta( self::META_CURRENT_PAYMENT_ID ) === $payment_id ) {
			$order->update_meta_data( self::META_CURRENT_PAYMENT_STATUS, $status );
		}
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
	 * metadata order_id) or canceled by the stale-payment sweep, which finds it
	 * through META_ABANDONED_PAYMENT_IDS now that the current pointer is gone.
	 */
	public static function abandon_current( $order ): void {
		$payment_id = (string) $order->get_meta( self::META_CURRENT_PAYMENT_ID );
		if ( '' !== $payment_id ) {
			$status = (string) $order->get_meta( self::META_CURRENT_PAYMENT_STATUS );
			$history = self::history( $order );
			foreach ( $history as &$attempt ) {
				if ( ( $attempt['payment_id'] ?? '' ) === $payment_id && self::is_non_final( (string) ( $attempt['status'] ?? '' ) ) ) {
					$attempt['status'] = 'abandoned';
					$attempt['updated_at'] = gmdate( 'c' );
				}
			}
			unset( $attempt );
			$order->update_meta_data( self::META_ATTEMPTS, $history );
			// The payment is (as far as we know) still open at Mollie. Deleting the
			// current pointer would hide it from the stale-payment sweep, which
			// queries orders by meta key, so park the ID where the sweep looks.
			if ( self::is_non_final( $status ) ) {
				$abandoned = self::abandoned( $order );
				if ( ! in_array( $payment_id, $abandoned, true ) ) {
					$abandoned[] = $payment_id;
					$order->update_meta_data( self::META_ABANDONED_PAYMENT_IDS, $abandoned );
				}
			}
		}
		$order->delete_meta_data( self::META_CURRENT_ATTEMPT_ID );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_ID );
		$order->delete_meta_data( self::META_CURRENT_TERMINAL_ID );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_METHOD );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_STATUS );
		$order->delete_meta_data( self::META_CURRENT_PAYMENT_CREATED_AT );
		$order->save();
	}

	public static function history( $order ): array {
		$history = $order->get_meta( self::META_ATTEMPTS );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Payment IDs detached from this order while still open at Mollie.
	 *
	 * The stale-payment sweep queries orders on META_ABANDONED_PAYMENT_IDS, so an
	 * abandoned payment stays reachable by the WP-Cron backstop even though its
	 * current-attempt pointer is gone. Entries are dropped by forget_abandoned()
	 * once the payment reaches a final state.
	 */
	public static function abandoned( $order ): array {
		$ids = $order->get_meta( self::META_ABANDONED_PAYMENT_IDS );
		if ( ! is_array( $ids ) ) { return array(); }
		$unique = array();
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( '' !== $id && ! in_array( $id, $unique, true ) ) { $unique[] = $id; }
		}
		return $unique;
	}

	/** Stop chasing an abandoned payment: it reached a final state at Mollie. */
	public static function forget_abandoned( $order, string $payment_id ): void {
		$abandoned = self::abandoned( $order );
		if ( ! in_array( $payment_id, $abandoned, true ) ) { return; }
		$remaining = array_values( array_diff( $abandoned, array( $payment_id ) ) );
		// Delete rather than store an empty array: the sweep query matches on the
		// meta key existing, not on its contents.
		if ( empty( $remaining ) ) {
			$order->delete_meta_data( self::META_ABANDONED_PAYMENT_IDS );
		} else {
			$order->update_meta_data( self::META_ABANDONED_PAYMENT_IDS, $remaining );
		}
		$order->save();
	}

	public static function payment_id( array $payment ): string { return (string) ( $payment['id'] ?? '' ); }
	public static function payment_status( array $payment ): string { return (string) ( $payment['status'] ?? 'unknown' ); }
	public static function is_final_unpaid( string $status ): bool { return in_array( $status, array( 'failed', 'canceled', 'expired' ), true ); }
	public static function is_final( string $status ): bool { return 'paid' === $status || self::is_final_unpaid( $status ); }
	public static function is_non_final( string $status ): bool { return in_array( $status, array( 'open', 'pending', 'authorized', '' ), true ); }
	public static function is_qr_method( string $method ): bool { return in_array( $method, array( 'ideal', 'bancontact' ), true ); }
}
