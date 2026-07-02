<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

/**
 * Logger for the Mollie Terminal integration.
 *
 * Follows the WooCommerce POS terminal-gateway logging convention shared by the
 * Stripe, SumUp, PayArc and Square terminal plugins: everything is written to
 * the WooCommerce status logs (WooCommerce → Status → Logs, source
 * "mollie-terminal-for-woocommerce"). Sensitive values are redacted, as the
 * PayArc/Square loggers also do, because this plugin logs Mollie API payloads.
 *
 * NOTE: do not put any SQL queries in this class, eg: options table lookup.
 */
class Logger {
	public const WC_LOG_FILENAME = 'mollie-terminal-for-woocommerce';

	/** @var null|\WC_Logger */
	public static $logger;

	/** @var null|string */
	public static $log_level;

	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Write a redacted message to the WooCommerce status logs.
	 *
	 * Argument order matches the PayArc terminal plugin's logger
	 * (message, context, level) so the family stays consistent.
	 *
	 * @param mixed  $message Message to log (non-strings are stringified).
	 * @param array  $context Extra context appended to the message, redacted.
	 * @param string $level   PSR-3 level; the internal "success" maps to "info".
	 */
	public static function log( $message, array $context = array(), string $level = '' ): void {
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'mtfwc_logging', true, $message ) ) {
			return;
		}
		if ( '' === $level ) {
			$level = self::$log_level ? self::$log_level : 'info';
		}
		if ( ! is_string( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
		$line = self::redact( (string) $message );
		if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
			$line .= ' ' . wp_json_encode( self::redact_context( $context ) );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			self::$logger->log( self::wc_level( $level ), $line, array( 'source' => self::WC_LOG_FILENAME ) );
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[' . self::WC_LOG_FILENAME . '] [' . $level . '] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Convenience wrapper for error-level logging. */
	public static function log_api_error( string $message, array $context = array() ): void {
		self::log( $message, $context, 'error' );
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

	/** Map the internal "success" level onto a PSR-3 / WC_Logger level. */
	private static function wc_level( string $level ): string {
		$level = in_array( $level, array( 'debug', 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info';
		return 'success' === $level ? 'info' : $level;
	}
}
