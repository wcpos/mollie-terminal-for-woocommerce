<?php
// Test double for Free's payment ledger: rows per order id in $GLOBALS['ledger_rows'].
namespace WCPOS\WooCommercePOS\Payments\Contract;

if ( ! class_exists( Ledger::class ) ) {
	class Ledger {
		public const LIVE_STATUSES = array( 'pending', 'authorized', 'captured' );
		public const COUNTING_STATUSES = array( 'authorized', 'captured' );
		public static function instance() { return new self(); }
		public function read( $order ) { return $GLOBALS['ledger_rows'][ $order->get_id() ] ?? array(); }
		public function find( $order, $id ) { foreach ( $this->read( $order ) as $row ) { if ( ( $row['id'] ?? null ) === $id ) { return $row; } } return null; }
	}
}
