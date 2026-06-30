<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

class Logger {
	public const CONTEXT_DIAGNOSTICS_RECORDED = '_mtfwc_diagnostics_recorded';

	public static function log( string $message, array $context = array() ): void {
		$diagnostics_recorded = ! empty( $context[ self::CONTEXT_DIAGNOSTICS_RECORDED ] );
		unset( $context[ self::CONTEXT_DIAGNOSTICS_RECORDED ] );
		$sanitized = self::redact( $message );
		if ( ! empty( $context ) ) {
			$sanitized .= ' ' . wp_json_encode( self::redact_context( $context ) );
		}
		if ( ! $diagnostics_recorded && class_exists( Diagnostics::class ) ) {
			Diagnostics::record( 'info', $message, $context );
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $sanitized, array( 'source' => 'mollie-terminal-for-woocommerce' ) );
			return;
		}
		error_log( '[mollie-terminal-for-woocommerce] ' . $sanitized );
	}

	private static function redact( string $value ): string {
		$value = preg_replace( '/(test|live)_[A-Za-z0-9]{20,}/', '$1_***', $value );
		return preg_replace( '/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer ***', $value );
	}

	private static function redact_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( false !== stripos( (string) $key, 'key' ) || false !== stripos( (string) $key, 'token' ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}
		return $context;
	}
}
