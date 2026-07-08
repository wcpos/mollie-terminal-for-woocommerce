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
		$limit = (int) apply_filters( 'mtfwc_stale_payment_batch', 25 );
		$orders = array();
		// Orders whose current attempt may have gone stale in the browser.
		foreach ( $this->find_orders( array( 'pending', 'failed' ), PaymentAttempt::META_CURRENT_PAYMENT_ID, $limit ) as $order ) {
			$orders[ (int) $order->get_id() ] = $order;
		}
		// Orders holding a payment that was abandoned locally while still open at
		// Mollie. Their current-attempt pointer is gone, so the query above cannot
		// see them, and their status is irrelevant: the order may since have been
		// paid in cash or cancelled while the payment stayed open.
		foreach ( $this->find_orders( 'any', PaymentAttempt::META_ABANDONED_PAYMENT_IDS, $limit ) as $order ) {
			$orders[ (int) $order->get_id() ] = $order;
		}
		if ( empty( $orders ) ) { return; }
		$swept = 0;
		foreach ( $orders as $order ) {
			if ( $this->sweep_order( $order ) ) { $swept++; }
		}
		if ( $swept > 0 ) {
			Logger::log( 'Mollie terminal stale-payment sweep finished.', array( 'canceled' => $swept, 'scanned' => count( $orders ) ), 'info' );
		}
	}

	/** @param string|array $status */
	private function find_orders( $status, string $meta_key, int $limit ): array {
		$orders = wc_get_orders(
			array(
				'limit'      => $limit,
				'status'     => $status,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array(
					array( 'key' => $meta_key, 'compare' => 'EXISTS' ),
				),
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
		if ( $order->is_paid() ) { return $swept; }
		$current = PaymentAttempt::current( $order );
		if ( ! $current || empty( $current['payment_id'] ) ) { return $swept; }
		if ( ! PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) { return $swept; }
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
