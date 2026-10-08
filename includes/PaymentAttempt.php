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
	// Stored (never a Mollie status): Mollie reported the payment paid, but it did
	// not verify against this order. Final, so the stale-payment sweep leaves it
	// alone; a cashier's poll, refresh, Start or cancel asks Mollie and verifies again.
	public const STATUS_PAID_UNVERIFIED = 'paid_unverified';
	// Serializes the abandoned list's read-modify-write: completion and cancel hold different locks.
	private const ABANDONED_LOCK = 'abandoned_list';
	// One order reload plus one meta save; a dead holder blocks the list no longer than this.
	private const ABANDONED_LOCK_TTL = 5;
	// Waits (ms) between claims while another request holds the list: ~1.5 s, short enough for AJAX.
	private const ABANDONED_LOCK_WAITS_MS = array( 100, 200, 400, 800 );

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

	/**
	 * Make this gateway the order's payment method.
	 *
	 * A Mollie payment is created and completed over AJAX or the webhook, never
	 * through the WooCommerce pay form that normally stamps the chosen gateway
	 * onto the order. Without this the order keeps whatever method it had (none,
	 * or the POS default such as cash) and everything keyed on the order's
	 * payment method reads the wrong gateway: the WooCommerce POS per-gateway
	 * order status, refund routing, and the "Payment via" label.
	 *
	 * Called only once Mollie confirms the payment, never when an attempt starts:
	 * an abandoned attempt must not leave Mollie on an order that is then paid
	 * another way. Does not save; the caller saves the order right after.
	 */
	public static function claim_order_gateway( $order, string $title ): void {
		if ( Settings::GATEWAY_ID === (string) $order->get_payment_method() && '' !== (string) $order->get_payment_method_title() ) { return; }
		$order->set_payment_method( Settings::GATEWAY_ID );
		$order->set_payment_method_title( $title );
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

	/** $status overrides the Mollie status that is stored (STATUS_PAID_UNVERIFIED). */
	public static function update_status( $order, array $payment, string $status = '' ): void {
		$payment_id = self::payment_id( $payment );
		$status = '' !== $status ? $status : self::payment_status( $payment );
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
			// queries orders by meta key, so park the ID where the sweep looks
			// before the pointer goes: a crash in between leaves it on both.
			if ( self::is_non_final( $status ) ) {
				self::change_abandoned( $order, function ( array $ids ) use ( $payment_id ) {
					return in_array( $payment_id, $ids, true ) ? $ids : array_merge( $ids, array( $payment_id ) );
				}, true );
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
		// Not listed when this request loaded the order: nothing to do. Listed
		// since by another request: it stays, and the sweep drops it next run.
		if ( ! in_array( $payment_id, self::abandoned( $order ), true ) ) { return; }
		self::change_abandoned( $order, function ( array $ids ) use ( $payment_id ) {
			return array_values( array_diff( $ids, array( $payment_id ) ) );
		}, false );
	}

	/**
	 * Read-modify-write the abandoned list as stored now, not as $order loaded it.
	 * The cancel path adds to it and reconciliation removes from it in different
	 * requests under different locks, and a completion can run for seconds
	 * (stock, emails): writing back a list read earlier would drop the other
	 * request's entry and leave a payment open at Mollie untracked (#32). The
	 * write goes through the re-read copy only, so $order never writes this key.
	 *
	 * Re-read, compute and save run under a per-order ABANDONED_LOCK, so two
	 * requests cannot both read the same list and the later save drop the
	 * earlier one's change (#33). When the lock cannot be claimed within
	 * ABANDONED_LOCK_WAITS_MS, or the claim hits a database error:
	 * - a removal ($write_without_lock false, forget_abandoned) gives up. A
	 *   leftover entry is harmless: the sweep resolves it again once the payment
	 *   is final and forgets it then, so the removal is idempotent;
	 * - an addition ($write_without_lock true, abandon_current) writes anyway,
	 *   still through the re-read: dropping it would hide a payment still open
	 *   at Mollie from the sweep, the worse failure.
	 */
	private static function change_abandoned( $order, callable $change, bool $write_without_lock ): void {
		$order_id = (int) $order->get_id();
		$claim = PaymentLock::claim( $order_id, self::ABANDONED_LOCK, self::ABANDONED_LOCK_TTL );
		foreach ( self::ABANDONED_LOCK_WAITS_MS as $wait_ms ) {
			if ( PaymentLock::HELD !== $claim ) { break; }
			usleep( $wait_ms * 1000 );
			$claim = PaymentLock::claim( $order_id, self::ABANDONED_LOCK, self::ABANDONED_LOCK_TTL );
		}
		if ( PaymentLock::ACQUIRED !== $claim ) {
			Logger::log( 'Mollie Terminal abandoned-payment list is busy' . ( $write_without_lock ? '; adding without the lock.' : '; leaving the entry for the next sweep.' ), array( 'order_id' => $order_id, 'claim' => $claim ), 'warning' );
			if ( ! $write_without_lock ) { return; }
		}
		try {
			$fresh = PaymentReconciler::reload_order( $order );
			$before = self::abandoned( $fresh );
			$after = $change( $before );
			if ( $after === $before ) { return; }
			// Delete rather than store an empty array: the sweep query matches on the
			// meta key existing, not on its contents.
			if ( empty( $after ) ) {
				$fresh->delete_meta_data( self::META_ABANDONED_PAYMENT_IDS );
			} else {
				$fresh->update_meta_data( self::META_ABANDONED_PAYMENT_IDS, $after );
			}
			$fresh->save();
		} finally {
			if ( PaymentLock::ACQUIRED === $claim ) {
				PaymentLock::release( $order_id, self::ABANDONED_LOCK );
			}
		}
	}

	public static function payment_id( array $payment ): string { return (string) ( $payment['id'] ?? '' ); }
	public static function payment_status( array $payment ): string { return (string) ( $payment['status'] ?? 'unknown' ); }
	public static function is_final_unpaid( string $status ): bool { return in_array( $status, array( 'failed', 'canceled', 'expired' ), true ); }
	public static function is_final( string $status ): bool { return 'paid' === $status || self::is_final_unpaid( $status ); }
	/** Stored attempt status for a payment Mollie reported paid, verified or not. */
	public static function reported_paid( string $status ): bool { return in_array( $status, array( 'paid', self::STATUS_PAID_UNVERIFIED ), true ); }
	public static function is_non_final( string $status ): bool { return in_array( $status, array( 'open', 'pending', 'authorized', '' ), true ); }
	public static function is_qr_method( string $method ): bool { return in_array( $method, array( 'ideal', 'bancontact' ), true ); }
}
