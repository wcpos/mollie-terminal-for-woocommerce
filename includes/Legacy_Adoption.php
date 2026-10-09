<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

/**
 * Fold attempts the old order-pay panel left mid-flight into Pro's ledger on upgrade: one pass
 * per plugin version, 25 orders per request, from a snapshot taken when the pass begins.
 *
 * Only terminal (`pointofsale`) attempts are adopted: Pro's panel drives terminals, and a QR
 * attempt belongs to the old panel, which stays while QR methods are enabled. The action
 * reference Pro's provider polls and cancels is the Mollie payment id itself, so an adopted
 * attempt needs nothing beyond the ledger row: Mollie expires an unpaid terminal payment on
 * its own and `Mollie_Server_Provider::fetch()` reads it directly.
 */
final class Legacy_Adoption {
	/** The version that introduced adoption; its pass runs once, and this marks it done. */
	public const VERSION = '1.0.0';
	/** Candidates per `init` request; keeps the request that triggers it short. */
	public const PAGE_SIZE = 25;
	/**
	 * Order statuses that still need payment: WooCommerce's own two plus the two Free adds
	 * through `woocommerce_valid_order_statuses_for_payment` (POS open and partially paid
	 * orders). `needs_payment()` on the rows read stays the truth.
	 */
	public const UNPAID_STATUSES = array( 'pending', 'failed', 'pos-open', 'pos-partial' );
	/** Provider family, as Mollie_Server_Provider::provider() reports it. */
	public const PROVIDER = 'mollie';
	/** The action reference Pro adopted, kept on the order for good. */
	public const META_ADOPTED = '_mtfwc_adopted_ref';
	private const QUEUE_OPTION   = 'mtfwc_adoption_queue';
	private const VERSION_OPTION = 'mtfwc_adoption_version';

	/**
	 * The action reference Pro's provider uses for an attempt the old panel started: the Mollie
	 * payment id of the order's current terminal attempt while it is still open. '' for a QR
	 * attempt, a final one, or no attempt.
	 */
	public static function action_ref( $order ): string {
		$current = PaymentAttempt::current( $order );
		if ( ! $current || 'pointofsale' !== (string) ( $current['method'] ?? '' ) || ! PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) {
			return '';
		}
		return (string) $current['payment_id'];
	}

	/** Whether Pro adopted this payment from the old panel; its outcome is then Pro's. */
	public static function is_adopted( string $ref ): bool {
		return '' !== $ref && function_exists( 'wcpos_pro_payment_id_for_action' ) && null !== wcpos_pro_payment_id_for_action( self::PROVIDER, $ref );
	}

	/**
	 * Whether Pro adopted the attempt on this order: by its current payment, or by the reference
	 * kept at adoption, which outlives the current pointer.
	 */
	public static function is_adopted_order( $order ): bool {
		if ( ! function_exists( 'wcpos_pro_payment_id_for_action' ) ) {
			return false;
		}
		$current = PaymentAttempt::current( $order );
		return self::is_adopted( (string) ( $current['payment_id'] ?? '' ) ) || self::is_adopted( (string) $order->get_meta( self::META_ADOPTED ) );
	}

	/**
	 * Run the next page of adoption, until every candidate snapshotted at the start has been seen.
	 *
	 * Only while the order-pay page runs through Pro's panel: under the QR carve-out the old panel
	 * stays whole and owns its attempts, so nothing is adopted and the pass is not marked done;
	 * it runs when the merchant disables the QR methods.
	 */
	public static function upgrade(): void {
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) || ! ( new Settings() )->uses_pro_panel() ) {
			return;
		}
		// The candidates are snapshotted once, as order ids, when the pass begins: every order
		// still waiting for payment that carries an attempt pointer. Ids only, so the one request
		// that takes the snapshot loads no order objects; each order is read, and judged, on its
		// own page below. Paging a live filter by offset would skip rows as webhooks move orders
		// out of it, and an attempt the old panel starts later is never a candidate (under Pro's
		// panel the old panel can start none).
		$queue = get_option( self::QUEUE_OPTION, null );
		if ( ! is_array( $queue ) ) {
			$ids = wc_get_orders(
				array(
					'type'         => 'shop_order',
					// Only orders still waiting for payment: a completed sale keeps its attempt
					// pointer for good, and a store's whole Mollie history must not be read.
					'status'       => self::UNPAID_STATUSES,
					'limit'        => -1,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'ids',
					// The shortcut both order stores honour; `meta_query` is dropped by the posts store.
					'meta_key'     => PaymentAttempt::META_CURRENT_PAYMENT_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off upgrade pass.
					'meta_compare' => 'EXISTS',
				)
			);
			$queue = array_fill_keys( array_map( 'intval', is_array( $ids ) ? $ids : array() ), 1 );
			update_option( self::QUEUE_OPTION, $queue, false );
		}
		$page = array_slice( $queue, 0, self::PAGE_SIZE, true );
		foreach ( $page as $order_id => $unused ) {
			$result = self::adopt_order( (int) $order_id );
			if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'wcpos_payment_locked', 'mtfwc_adoption_no_lock', 'mtfwc_adoption_completing' ), true ) ) {
				// A held lock is a till at work on that order: it stays in the queue for the next
				// request. Any other refusal is final for this order and is logged.
				Logger::log( 'Legacy Mollie adoption deferred for order ' . $order_id . ': ' . $result->get_error_code(), array( 'order_id' => (int) $order_id ), 'info' );
				continue;
			}
			if ( is_wp_error( $result ) ) {
				Logger::log( 'Legacy Mollie adoption failed for order ' . $order_id . ': ' . $result->get_error_code(), array( 'order_id' => (int) $order_id ), 'error' );
			}
			unset( $queue[ $order_id ] );
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( array() === $queue ) {
			delete_option( self::QUEUE_OPTION );
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}
	}

	/**
	 * Adopt the order's open terminal attempt, if it has one Pro does not own yet: under Free's
	 * per-order lock, on a fresh read, and under the old paths' own completion claim. Run by the
	 * upgrade pass for each snapshotted order, and by Pro's panel before it renders, so an attempt
	 * the pass has not reached yet (or one a QR switch-off left behind) is Pro's before the page
	 * can offer a second charge.
	 *
	 * @return array|null|\WP_Error The row, null when nothing applied, or a lock's refusal.
	 */
	public static function adopt_order( int $order_id ) {
		return self::with_order_lock(
			$order_id,
			static function () use ( $order_id ) {
				// Read the order under the lock and judge that copy: an open terminal attempt on an
				// order still waiting for payment, not adopted yet. A QR attempt, a final one, a paid
				// order or a leg a till recorded meanwhile is left alone.
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh ) {
					return null;
				}
				$ref = self::action_ref( $fresh );
				if ( '' === $ref || self::is_adopted( $ref ) || $fresh->is_paid() || ! $fresh->needs_payment() ) {
					return null;
				}
				// The old paths complete a paid payment under their own per-order claim; while one
				// holds it this attempt is mid-completion, and the caller tries again later.
				try {
					return PaymentLock::with_lock(
						$order_id,
						'complete_payment',
						static function () use ( $fresh, $ref ) {
							$row = wcpos_pro_adopt_legacy_attempt( $fresh, Settings::GATEWAY_ID, $ref, (string) $fresh->get_total(), $fresh->get_currency() );
							if ( is_array( $row ) ) {
								$fresh->update_meta_data( self::META_ADOPTED, $ref );
								$fresh->save();
							}
							return $row;
						}
					);
				} catch ( \RuntimeException $e ) {
					return new \WP_Error( 'mtfwc_adoption_completing', $e->getMessage() );
				}
			}
		);
	}

	/** Run under Free's per-order lock, the one every ledger write takes. */
	private static function with_order_lock( int $order_id, callable $callback ) {
		if ( ! class_exists( '\WCPOS\WooCommercePOS\Payments\Contract\Order_Lock' ) ) {
			return new \WP_Error( 'mtfwc_adoption_no_lock', 'The POS order lock is unavailable.' );
		}
		return \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::instance()->with_lock( $order_id, $callback );
	}
}
