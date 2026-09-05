<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class RefundHandler {
	private $client;
	public function __construct( MollieApiClient $client ) { $this->client = $client; }
	public function process_refund( $order, $amount, string $reason = '' ) {
		try {
			$refund = null;
			foreach ( $order->get_refunds() as $candidate ) { if ( wc_format_decimal( $candidate->get_amount(), 2 ) === wc_format_decimal( $amount, 2 ) && ! $candidate->get_meta( RefundReconciler::META_MOLLIE_REFUND_ID ) ) { $refund = $candidate; break; } }
			if ( null === $refund ) { return new \WP_Error( 'mtfwc_refund_not_found', __( 'No matching WooCommerce refund found.', 'mollie-terminal-for-woocommerce' ) ); }
			return ( new RefundReconciler( $this->client ) )->refund( $order, $refund, (string) $amount, $reason );
		} catch ( Exception $e ) { Logger::log( 'Mollie refund failed: ' . $e->getMessage(), array(), 'error' ); return new \WP_Error( 'mtfwc_refund_failed', $e->getMessage() ); }
	}
}
