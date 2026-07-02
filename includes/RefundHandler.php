<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;

class RefundHandler {
	private $client;
	public function __construct( MollieApiClient $client ) { $this->client = $client; }
	public function process_refund( $order, $amount, string $reason = '' ) {
		try {
			$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $amount, 'reason' => $reason ) );
			if ( is_wp_error( $refund ) ) { return $refund; }
			return ( new RefundReconciler( $this->client ) )->refund( $order, $refund, (string) $amount, $reason );
		} catch ( Exception $e ) { Diagnostics::record( 'error', 'Mollie refund failed: ' . $e->getMessage() ); return new \WP_Error( 'mtfwc_refund_failed', $e->getMessage() ); }
	}
}
