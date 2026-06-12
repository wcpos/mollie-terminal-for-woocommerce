<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Utils;

use InvalidArgumentException;

class Money {
	private const SCALE = 2;

	public static function to_mollie_value( $amount, string $currency ): string {
		self::assert_supported_pos_currency( $currency );
		$normalized = self::normalize( (string) $amount );
		self::assert_positive( $normalized );
		return $normalized;
	}

	public static function equals( string $left, string $right, string $currency ): bool {
		self::assert_supported_pos_currency( $currency );
		return self::minor_units( $left ) === self::minor_units( $right );
	}

	public static function subtract( string $left, string $right, string $currency ): string {
		self::assert_supported_pos_currency( $currency );
		$minor = self::minor_units( $left ) - self::minor_units( $right );
		if ( $minor < 0 ) {
			throw new InvalidArgumentException( 'Amount cannot become negative.' );
		}
		return self::format_minor_units( $minor );
	}

	public static function assert_positive( string $amount ): void {
		if ( self::minor_units( $amount ) <= 0 ) {
			throw new InvalidArgumentException( 'Amount must be positive.' );
		}
	}

	public static function assert_supported_pos_currency( string $currency ): void {
		if ( 'EUR' !== strtoupper( $currency ) ) {
			throw new InvalidArgumentException( 'Mollie Terminal POS payments currently support EUR only.' );
		}
	}

	private static function normalize( string $amount ): string {
		if ( function_exists( 'wc_format_decimal' ) ) {
			$amount = wc_format_decimal( $amount, self::SCALE, false );
		}
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $amount ) ) {
			throw new InvalidArgumentException( 'Invalid money value.' );
		}
		return self::format_minor_units( self::minor_units( $amount ) );
	}

	private static function minor_units( string $amount ): int {
		$amount = trim( $amount );
		$negative = str_starts_with( $amount, '-' );
		$amount = ltrim( $amount, '+-' );
		$parts = explode( '.', $amount, 2 );
		$whole = preg_replace( '/\D/', '', $parts[0] ?: '0' );
		$fraction = substr( str_pad( preg_replace( '/\D/', '', $parts[1] ?? '' ), self::SCALE, '0' ), 0, self::SCALE );
		$minor = (int) $whole * 100 + (int) $fraction;
		return $negative ? -$minor : $minor;
	}

	private static function format_minor_units( int $minor ): string {
		$sign = $minor < 0 ? '-' : '';
		$minor = abs( $minor );
		return sprintf( '%s%d.%02d', $sign, intdiv( $minor, 100 ), $minor % 100 );
	}
}
