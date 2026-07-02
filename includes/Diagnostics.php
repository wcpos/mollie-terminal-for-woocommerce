<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

/**
 * Records plugin diagnostics to the WooCommerce status logs
 * (WooCommerce → Status → Logs, source "mollie-terminal-for-woocommerce").
 *
 * Nothing is written to the wp_options table. High-frequency AJAX/webhook
 * logging into an option array caused option bloat and offered no atomic
 * append guarantee under concurrent requests (issue #5). WC_Logger is the
 * durable, concurrency-safe sink WooCommerce already provides.
 */
class Diagnostics {
	public const LOG_SOURCE = 'mollie-terminal-for-woocommerce';

	/** Legacy option keys removed in favour of WC_Logger; cleaned up on demand. */
	private const LEGACY_OPTIONS = array(
		'mtfwc_last_api_error',
		'mtfwc_recent_diagnostic_events',
		'mtfwc_last_webhook_event',
	);

	public static function record( string $level, string $message, array $context = array() ): void {
		$level   = self::normalize_level( $level );
		$message = self::redact( $message );
		$context = self::redact_context( $context );

		$line = $message;
		if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( self::wc_level( $level ), $line, array( 'source' => self::LOG_SOURCE ) );
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[' . self::LOG_SOURCE . '] [' . $level . '] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function record_api_error( string $message, array $context = array() ): void {
		self::record( 'error', $message, $context );
	}

	/**
	 * Delete the pre-0.3.1 diagnostic options. Idempotent; call from a
	 * low-frequency context (e.g. the settings screen) so we do not run
	 * delete queries on every request.
	 */
	public static function cleanup_legacy_options(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	public static function redact( string $value ): string {
		$value = preg_replace( '/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer ***', $value );
		$value = preg_replace( '/(test|live)_[A-Za-z0-9]{20,}/', '$1_***', $value );
		return strlen( $value ) > 1000 ? substr( $value, 0, 1000 ) . '…' : $value;
	}

	private static function redact_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( self::is_sensitive_key( (string) $key ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}
		return $context;
	}

	private static function is_sensitive_key( string $key ): bool {
		foreach ( array( 'key', 'token', 'secret', 'authorization', 'password', 'bearer' ) as $needle ) {
			if ( false !== stripos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_level( string $level ): string {
		return in_array( $level, array( 'debug', 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info';
	}

	/** Map internal levels onto WC_Logger / PSR-3 levels ("success" is ours). */
	private static function wc_level( string $level ): string {
		return 'success' === $level ? 'info' : $level;
	}
}
