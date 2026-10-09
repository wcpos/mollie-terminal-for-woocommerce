<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class WebhookHandler {
	public function __construct() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_ajax_mtfwc_mollie_webhook', array( $this, 'handle' ) );
			add_action( 'wp_ajax_nopriv_mtfwc_mollie_webhook', array( $this, 'handle' ) );
		}
	}

	public function handle(): void {
		$this->process( sanitize_text_field( wp_unslash( $_POST['id'] ?? $_GET['id'] ?? '' ) ) );
		status_header( 200 ); echo 'OK'; exit;
	}

	/** Reconcile one delivery; every outcome is acknowledged, so Mollie stops retrying. */
	public function process( string $payment_id ): void {
		if ( '' === $payment_id ) {
			Logger::log( 'Mollie webhook received without payment ID.', array(), 'warning' );
			return;
		}
		// An attempt Pro adopted on upgrade is Pro's leg while its row is live: Pro's poll settles
		// it from Mollie's authenticated state within a poll interval, and this handler writes
		// nothing. The local lookup (the order by its attempt pointer, then Pro's row) runs before
		// any call to Mollie. Once Pro's leg has ended without money, a late result reaches the
		// order through this handler as before adoption.
		$known = Legacy_Adoption::is_adopted( $payment_id ) ? $this->find_order_by_attempt( $payment_id ) : null;
		if ( $known && Legacy_Adoption::owned_by_pro( $known, $payment_id ) ) {
			Logger::log( 'Mollie webhook for an attempt WooCommerce POS adopted; left to the POS.', array( 'payment_id' => $payment_id ), 'info' );
			return;
		}
		Logger::log( 'Mollie webhook received.', array( 'payment_id' => $payment_id ), 'info' );
		try {
			$settings = new Settings();
			$payment = ( new MollieApiClient( $settings->api_key() ) )->get_payment( $payment_id );
			$order = $this->find_order_for_payment( $payment_id, $payment );
			if ( ! $order ) {
				Logger::log( 'Mollie webhook received for unknown payment.', array( 'payment_id' => $payment_id ), 'warning' );
				return;
			}
			// Asked again after the call to Mollie: adoption may have run meanwhile. The reconciler
			// repeats the check under its completion claim, which adoption shares.
			if ( Legacy_Adoption::owned_by_pro( $order, $payment_id ) ) {
				Logger::log( 'Mollie webhook for an attempt WooCommerce POS adopted meanwhile; left to the POS.', array( 'payment_id' => $payment_id ), 'info' );
				return;
			}
			( new PaymentReconciler( $settings ) )->reconcile( $order, $payment, 'webhook' );
			Logger::log( 'Mollie webhook reconciled.', array( 'payment_id' => $payment_id, 'order_id' => (int) $order->get_id(), 'status' => $payment['status'] ?? '' ), 'success' );
		} catch ( Exception $e ) {
			Logger::log( 'Mollie webhook failed: ' . $e->getMessage(), array( 'payment_id' => $payment_id ), 'error' );
		}
	}

	private function find_order_for_payment( string $payment_id, array $payment ) {
		$order_id = (int) ( $payment['metadata']['order_id'] ?? 0 );
		if ( $order_id ) { $order = wc_get_order( $order_id ); if ( $order ) { return $order; } }
		return $this->find_order_by_attempt( $payment_id );
	}

	/** The order whose current attempt is this payment, without asking Mollie. */
	private function find_order_by_attempt( string $payment_id ) {
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 1, 'meta_key' => PaymentAttempt::META_CURRENT_PAYMENT_ID, 'meta_value' => $payment_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup per delivery.
		return is_array( $orders ) ? ( $orders[0] ?? null ) : null;
	}
}
