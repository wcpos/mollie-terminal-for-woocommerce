<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class RefundHandler {
	/** Refund objects WooCommerce created in this request, keyed by order id: `woocommerce_create_refund` fires right before the gateway is asked to refund, so this binds process_refund() to the exact record rather than inferring it from amount and ordering (two same-amount refunds in flight would otherwise both pick the newest). */
	private static $created = array();
	private $client;
	public function __construct( MollieApiClient $client ) { $this->client = $client; }

	/** `woocommerce_create_refund` callback. */
	public static function remember_refund( $refund, $args = array() ): void {
		// Only a refund that asks WooCommerce to reverse the payment reaches the gateway; a manual refund created earlier in the same request must not be mistaken for it.
		if ( empty( $args['refund_payment'] ) ) { return; }
		if ( is_object( $refund ) && method_exists( $refund, 'get_parent_id' ) ) { self::$created[ (int) $refund->get_parent_id() ] = $refund; }
	}

	public function process_refund( $order, $amount, string $reason = '' ) {
		try {
			$refund = self::$created[ (int) $order->get_id() ] ?? null;
			unset( self::$created[ (int) $order->get_id() ] );
			// The remembered record must still be the one this request is about: same amount, not yet linked to a Mollie refund.
			if ( null !== $refund && ( wc_format_decimal( $refund->get_amount(), 2 ) !== wc_format_decimal( $amount, 2 ) || $refund->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ) ) ) { $refund = null; }
			if ( null === $refund ) {
				// No hook fired for this request (a caller that bypassed wc_create_refund): fall back to the newest refund at this amount that is not yet linked to a Mollie refund.
				foreach ( $order->get_refunds() as $candidate ) { if ( wc_format_decimal( $candidate->get_amount(), 2 ) === wc_format_decimal( $amount, 2 ) && ! $candidate->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ) ) { $refund = $candidate; break; } }
			}
			if ( null === $refund ) { return new \WP_Error( 'mtfwc_refund_not_found', __( 'No matching WooCommerce refund found.', 'mollie-terminal-for-woocommerce' ) ); }
			return ( new RefundReconciler( $this->client ) )->refund( $order, $refund, (string) $amount, $reason );
		} catch ( Exception $e ) { Logger::log( 'Mollie refund failed: ' . $e->getMessage(), array(), 'error' ); return new \WP_Error( 'mtfwc_refund_failed', $e->getMessage() ); }
	}
}
