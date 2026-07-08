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
		$orders = wc_get_orders(
			array(
				'limit'      => (int) apply_filters( 'mtfwc_stale_payment_batch', 25 ),
				'status'     => array( 'pending', 'failed' ),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array(
					array( 'key' => PaymentAttempt::META_CURRENT_PAYMENT_ID, 'compare' => 'EXISTS' ),
				),
			)
		);
		if ( empty( $orders ) ) { return; }
		$swept = 0;
		foreach ( $orders as $order ) {
			if ( $this->sweep_order( $order ) ) { $swept++; }
		}
		if ( $swept > 0 ) {
			Logger::log( 'Mollie terminal stale-payment sweep finished.', array( 'canceled' => $swept, 'scanned' => count( $orders ) ), 'info' );
		}
	}

	/**
	 * Cancel one order's stale open payment if it qualifies. Returns true when a
	 * cancel/abandon was attempted. Kept separate from the DB query so it can be
	 * unit-tested with a plain fake order.
	 */
	public function sweep_order( $order ): bool {
		if ( ! $order || $order->is_paid() ) { return false; }
		$current = PaymentAttempt::current( $order );
		if ( ! $current || empty( $current['payment_id'] ) ) { return false; }
		if ( ! PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) { return false; }
		$created = strtotime( (string) ( $current['created_at'] ?? '' ) );
		if ( ! $created || ( time() - $created ) < self::stale_threshold() ) { return false; }
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

	private function service(): MolliePaymentService {
		if ( ! $this->service ) {
			$settings = new Settings();
			$this->service = new MolliePaymentService( new MollieApiClient( $settings->api_key(), 8 ), $settings );
		}
		return $this->service;
	}
}
