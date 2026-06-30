<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

class Diagnostics {
	public const OPTION_LAST_API_ERROR = 'mtfwc_last_api_error';
	public const OPTION_RECENT_EVENTS = 'mtfwc_recent_diagnostic_events';
	private const MAX_EVENTS = 50;

	public static function record( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$event = array(
			'time' => gmdate( 'c' ),
			'level' => self::normalize_level( $level ),
			'message' => self::redact( $message ),
			'context' => self::redact_context( $context ),
		);
		$events = self::recent_events();
		$events[] = $event;
		if ( count( $events ) > self::MAX_EVENTS ) {
			$events = array_slice( $events, -self::MAX_EVENTS );
		}
		update_option( self::OPTION_RECENT_EVENTS, $events, false );
	}

	public static function record_api_error( string $message, array $context = array() ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LAST_API_ERROR, self::redact( $message ), false );
		}
		self::record( 'error', $message, $context );
	}

	public static function last_api_error(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}
		$value = get_option( self::OPTION_LAST_API_ERROR, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function recent_events(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$events = get_option( self::OPTION_RECENT_EVENTS, array() );
		return is_array( $events ) ? array_values( $events ) : array();
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
}
