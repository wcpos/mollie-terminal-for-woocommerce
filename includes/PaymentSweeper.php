<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

/**
 * Backstop for payments that were never resolved in the browser.
 *
 * The panel auto-cancels a payment when its poll loop times out, and fires a
 * best-effort cancel beacon when the checkout page is closed. Neither fires if
 * the browser is killed, the network drops, or the tab is discarded — leaving a
 * payment lingering "open" on the Mollie account. A WP-Cron sweep cancels those:
 * for each still-payable order whose current Mollie attempt has been open longer
 * than the stale threshold, it runs the normal cancel (which cancels at Mollie
 * when allowed, or abandons the attempt locally when the terminal never
 * responded).
 */
class PaymentSweeper {
	public const CRON_HOOK = 'mtfwc_sweep_stale_payments';
	public const SCHEDULE = 'mtfwc_ten_minutes';
	// Orders whose attempt the sweep skips (paid_unverified, canceled, ...) keep
	// their meta and stay the oldest matches, so each query reads past them in
	// batches, fetching at most this many orders per meta key per run.
	private const MAX_EXAMINED_PER_QUERY = 200;
	// Smallest scan batch, independent of the mtfwc_stale_payment_batch action
	// budget: a site lowering that budget to cut work must not multiply queries
	// (at most 200 / 25 = 8 per list per run).
	private const MIN_SCAN_PAGE = 25;

	private $service;

	public function __construct( ?MolliePaymentService $service = null ) {
		$this->service = $service;
		if ( ! function_exists( 'add_action' ) ) { return; }
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/** Register the custom 10-minute cron interval. */
	public function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) { $schedules = array(); }
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes (Mollie Terminal cleanup)', 'mollie-terminal-for-woocommerce' ),
		);
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) { return; }
		while ( $ts = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/** Number of seconds a payment may stay open before the sweep cancels it. */
	public static function stale_threshold(): int {
		$seconds = (int) apply_filters( 'mtfwc_stale_payment_seconds', 10 * MINUTE_IN_SECONDS );
		return $seconds > 0 ? $seconds : 10 * MINUTE_IN_SECONDS;
	}

	public function sweep(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) { return; }
		$limit = max( 1, (int) apply_filters( 'mtfwc_stale_payment_batch', 25 ) );
		$page = max( self::MIN_SCAN_PAGE, $limit );
		// Orders whose current attempt may have gone stale in the browser.
		$current = $this->find_orders( array( 'pending', 'failed' ), PaymentAttempt::META_CURRENT_PAYMENT_ID, $page );
		// Orders holding a payment that was abandoned locally while still open at
		// Mollie. Their current-attempt pointer is gone, so the query above cannot
		// see them, and their status is irrelevant: the order may since have been
		// paid in cash or cancelled while the payment stayed open.
		$abandoned = $this->find_orders( 'any', PaymentAttempt::META_ABANDONED_PAYMENT_IDS, $page );
		// Each list acts on (makes Mollie calls for) at most one batch per run, as
		// when each query fetched a single batch, so neither list starves the other.
		// An order on both lists that the first batch already swept is skipped by
		// the second; one past the first batch's budget is still swept by the second.
		$visited = array();
		$swept = $this->sweep_batch( $current, $limit, $visited );
		$swept += $this->sweep_batch( array_diff_key( $abandoned, $visited ), $limit, $visited );
		if ( $swept > 0 ) {
			Logger::log( 'Mollie terminal stale-payment sweep finished.', array( 'canceled' => $swept, 'scanned' => count( $current ) + count( $abandoned ) ), 'info' );
		}
	}

	/** Sweeps orders until $budget of them were acted on; adds every order it swept to $visited. */
	private function sweep_batch( array $orders, int $budget, array &$visited ): int {
		$swept = 0;
		foreach ( $orders as $id => $order ) {
			if ( $swept >= $budget ) { break; }
			$visited[ $id ] = true;
			if ( $this->sweep_order( $order ) ) { $swept++; }
		}
		return $swept;
	}

	/**
	 * Oldest matching orders keyed by ID, fetched in batches of $page until
	 * MAX_EXAMINED_PER_QUERY orders are fetched. All batches are read before any
	 * order is swept, so orders leaving the set (completed, cancelled) cannot
	 * shift a later batch past an unseen order.
	 *
	 * @param string|array $status
	 */
	private function find_orders( $status, string $meta_key, int $page ): array {
		$found = array();
		for ( $offset = 0; $offset < self::MAX_EXAMINED_PER_QUERY; $offset += $size ) {
			$size = min( $page, self::MAX_EXAMINED_PER_QUERY - $offset );
			$batch = $this->find_orders_page( $status, $meta_key, $size, $offset );
			foreach ( $batch as $order ) { $found[ (int) $order->get_id() ] = $order; }
			if ( count( $batch ) < $size ) { break; }
		}
		return $found;
	}

	/** @param string|array $status */
	private function find_orders_page( $status, string $meta_key, int $limit, int $offset ): array {
		$orders = wc_get_orders(
			array(
				// Refunds are order objects too and come back by default. They never
				// carry the attempt meta and lack the WC_Order methods the sweep calls.
				'type'         => 'shop_order',
				'limit'        => $limit,
				'offset'       => $offset,
				'status'       => $status,
				'orderby'      => 'date',
				'order'        => 'ASC',
				// Not 'meta_query': the legacy posts order store ignores that argument
				// (a doing_it_wrong notice at most) and returns the oldest orders of
				// any kind. The meta_key shortcut is honoured by both stores.
				'meta_key'     => $meta_key,
				'meta_compare' => 'EXISTS',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Cancel one order's stale open payment if it qualifies. Returns true when a
	 * cancel/abandon was attempted. Kept separate from the DB query so it can be
	 * unit-tested with a plain fake order.
	 */
	public function sweep_order( $order ): bool {
		if ( ! $order ) { return false; }
		// Abandoned payments first: resolving one can complete the order, in which
		// case there is no stale current attempt left worth canceling.
		$swept = $this->sweep_abandoned( $order );
		// Resolving an abandoned payment can complete the order on a re-read copy (#21).
		// Continue from the database's version so a stale copy is neither saved nor used to cancel a paid order's current attempt.
		if ( $swept ) {
			$order = PaymentReconciler::reload_order( $order );
		}
		if ( $order->is_paid() ) { return $swept; }
		$current = PaymentAttempt::current( $order );
		if ( ! $current || empty( $current['payment_id'] ) ) { return $swept; }
		// An attempt Pro adopted on upgrade is Pro's leg: its deadline, cancel and settlement are
		// Free's, and this sweep must neither cancel nor complete it.
		if ( Legacy_Adoption::owned_by_pro( $order, (string) $current['payment_id'] ) ) { return $swept; }
		$status = (string) ( $current['status'] ?? '' );
		if ( 'paid' === $status ) {
			// A completion died after storing the verified paid attempt, before
			// payment_complete(). The poll path asks Mollie again and completes it under
			// the completion claim, once. A paid_unverified attempt is not 'paid': left alone.
			Logger::log( 'Recovering a paid Mollie terminal payment whose order was not completed.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ), 'warning' );
			try {
				$this->service()->poll_order( $order, 'stale_sweep' );
			} catch ( Exception $e ) {
				Logger::log( 'Paid-payment recovery failed for order: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id() ), 'error' );
			}
			return true;
		}
		if ( ! PaymentAttempt::is_non_final( $status ) ) { return $swept; }
		$created = strtotime( (string) ( $current['created_at'] ?? '' ) );
		if ( ! $created || ( time() - $created ) < self::stale_threshold() ) { return $swept; }
		Logger::log( 'Sweeping stale open Mollie terminal payment.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ), 'info' );
		try {
			$result = $this->service()->cancel_order_payment( $order );
			$order->add_order_note( sprintf( 'Mollie Terminal: stale open payment swept by automatic cleanup (result: %s).', is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '' ) );
			$order->save();
		} catch ( Exception $e ) {
			Logger::log( 'Stale-payment sweep failed for order: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id() ), 'error' );
		}
		return true;
	}

	/**
	 * Retry the payments that cancel_order_payment() had to abandon locally: they
	 * are still open at Mollie until it cancels or expires them, or until the
	 * terminal reports that the customer paid after all.
	 */
	private function sweep_abandoned( $order ): bool {
		if ( empty( PaymentAttempt::abandoned( $order ) ) ) { return false; }
		Logger::log( 'Sweeping abandoned Mollie terminal payments.', array( 'order_id' => (int) $order->get_id(), 'payment_ids' => PaymentAttempt::abandoned( $order ) ), 'info' );
		try {
			$results = $this->service()->cancel_abandoned_payments( $order );
			$order = PaymentReconciler::reload_order( $order );
			foreach ( $results as $payment_id => $outcome ) {
				if ( 'still_open' === $outcome ) { continue; }
				$order->add_order_note( sprintf( 'Mollie Terminal: abandoned payment %s resolved by automatic cleanup (result: %s).', $payment_id, $outcome ) );
			}
			$order->save();
		} catch ( Exception $e ) {
			Logger::log( 'Abandoned-payment sweep failed for order: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id() ), 'error' );
		}
		return true;
	}

	private function service(): MolliePaymentService {
		if ( ! $this->service ) {
			$settings = new Settings();
			$this->service = new MolliePaymentService( new MollieApiClient( $settings->api_key(), 8 ), $settings );
		}
		return $this->service;
	}
}
