<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';

use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;

function payment_with_qr( string $status, string $src ): array {
	return array(
		'status' => $status,
		'details' => array( 'qrCode' => array( 'src' => $src, 'width' => '180', 'height' => 190, 'ignored' => true ) ),
	);
}

expect( null === MolliePaymentService::qr_code_from_payment( payment_with_qr( 'paid', 'https://example.test/qr.png' ) ), 'a final payment must not expose a QR code' );
expect( null === MolliePaymentService::qr_code_from_payment( array( 'status' => 'open' ) ), 'missing QR details should return null' );
expect( null === MolliePaymentService::qr_code_from_payment( payment_with_qr( 'open', 'http://example.test/qr.png' ) ), 'plain HTTP QR sources should be rejected' );
expect( null === MolliePaymentService::qr_code_from_payment( payment_with_qr( 'open', 'javascript:alert(1)' ) ), 'script QR sources should be rejected' );
expect(
	array( 'src' => 'data:image/png;base64,AAAA', 'width' => 180, 'height' => 190 ) === MolliePaymentService::qr_code_from_payment( payment_with_qr( 'open', 'data:image/png;base64,AAAA' ) ),
	'a data image should be returned with only normalized QR fields'
);
expect(
	array( 'src' => 'https://example.test/qr.png', 'width' => 180, 'height' => 190 ) === MolliePaymentService::qr_code_from_payment( payment_with_qr( 'open', 'https://example.test/qr.png' ) ),
	'an HTTPS image should be returned with only normalized QR fields'
);

echo "qr-code-filter ok\n";
