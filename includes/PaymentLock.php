<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use RuntimeException;

class PaymentLock {
	public static function acquire( int $order_id, string $operation, int $ttl = 30 ): bool {
		$key = self::key( $order_id, $operation );
		if ( get_transient( $key ) ) {
			return false;
		}
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mtfwc_', true );
		return (bool) set_transient( $key, array( 'operation' => $operation, 'created_at' => time(), 'token' => $token ), $ttl );
	}

	public static function release( int $order_id, string $operation ): void {
		delete_transient( self::key( $order_id, $operation ) );
	}

	public static function with_lock( int $order_id, string $operation, callable $callback, int $ttl = 30 ) {
		if ( ! self::acquire( $order_id, $operation, $ttl ) ) {
			throw new RuntimeException( 'Another Mollie Terminal operation is already running for this order.' );
		}
		try {
			return $callback();
		} finally {
			self::release( $order_id, $operation );
		}
	}

	private static function key( int $order_id, string $operation ): string {
		return 'mtfwc_lock_order_' . $order_id . '_' . sanitize_key( $operation );
	}
}
