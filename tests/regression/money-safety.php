<?php
require_once __DIR__ . '/../../includes/Utils/Money.php';
use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
expect( Money::to_mollie_value( '12.3', 'EUR' ) === '12.30' );
expect( Money::equals( '12.30', '12.3', 'EUR' ) );
expect( Money::subtract( '12.30', '2.05', 'EUR' ) === '10.25' );
try { Money::to_mollie_value( '12.00', 'USD' ); exit( 1 ); } catch ( InvalidArgumentException $e ) {}
try { Money::assert_positive( '0.00' ); exit( 1 ); } catch ( InvalidArgumentException $e ) {}
echo "money-safety ok\n";
