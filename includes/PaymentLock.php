<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use RuntimeException;

// INSERT IGNORE claims the options table's unique option_name atomically;
// transients can be cache-only and the add-option API upserts existing rows.
// Compare-and-delete keeps stale holders from deleting a replacement claim.
class PaymentLock {
	private static $held = array();

	public const ACQUIRED = 'acquired';
	public const HELD = 'held';
	// The claim could not be written: callers must not read this as "another request holds it".
	public const ERROR = 'error';

	public static function acquire( int $order_id, string $operation, int $ttl = 30 ): bool {
		return self::ACQUIRED === self::claim( $order_id, $operation, $ttl );
	}

	/** Returns ACQUIRED, HELD (a live claim exists) or ERROR (the database refused the insert). */
	public static function claim( int $order_id, string $operation, int $ttl = 30 ): string {
		global $wpdb;
		$key = self::key( $order_id, $operation );
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mtfwc_', true );
		$value = json_encode( array( 'token' => $token, 'expires_at' => time() + $ttl ) );
		$claim = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value );
		$inserted = $wpdb->query( $claim );
		if ( 1 === $inserted ) {
			self::$held[ $key ] = $value;
			return self::ACQUIRED;
		}
		if ( false === $inserted ) {
			return self::ERROR;
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$lock = json_decode( (string) $existing, true );
		if ( ! isset( $lock['expires_at'] ) || ! is_numeric( $lock['expires_at'] ) || $lock['expires_at'] < time() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $existing ) );
			$inserted = $wpdb->query( $claim );
			if ( 1 === $inserted ) {
				self::$held[ $key ] = $value;
				return self::ACQUIRED;
			}
			if ( false === $inserted ) {
				return self::ERROR;
			}
		}
		return self::HELD;
	}

	public static function release( int $order_id, string $operation ): void {
		global $wpdb;
		$key = self::key( $order_id, $operation );
		if ( isset( self::$held[ $key ] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, self::$held[ $key ] ) );
			unset( self::$held[ $key ] );
		}
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
