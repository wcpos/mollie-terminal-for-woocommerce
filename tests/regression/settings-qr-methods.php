<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function get_option( $key, $default = array() ) { return $default; }

require_once __DIR__ . '/../../includes/Settings.php';

use WCPOS\WooCommercePOS\MollieTerminal\Settings;

expect( array() === ( new Settings() )->qr_methods(), 'QR methods should be disabled by default' );
expect( array( 'ideal' ) === ( new Settings( array( 'qr_methods' => 'ideal' ) ) )->qr_methods(), 'a stored string should be normalized' );
expect( array( 'ideal', 'bancontact' ) === ( new Settings( array( 'qr_methods' => array( 'bancontact', 'invalid', 'ideal' ) ) ) )->qr_methods(), 'valid methods should be returned in fixed order' );
expect( array() === ( new Settings( array( 'qr_methods' => 123 ) ) )->qr_methods(), 'non-string, non-array values should be rejected' );

echo "settings-qr-methods ok\n";
