<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;

foreach ( array( 'open' => 'pending', 'pending' => 'in_progress', 'authorized' => 'in_progress', 'paid' => 'completed', 'canceled' => 'cancelled', 'expired' => 'expired', 'failed' => 'failed', 'surprise' => 'failed' ) as $status => $expected ) {
	$result = Provider::normalize( array( 'id' => 'tr_x', 'status' => $status ) );
	expect( $expected === $result['status'], 'wrong status for ' . $status );
	expect( null === $result['amount'] && null === $result['currency'], 'missing money must remain null' );
	expect( ( 'failed' === $expected ) === array_key_exists( 'failure_reason', $result ), 'failure reason only on failed' );
	if ( 'failed' === $expected ) { expect( 'provider_error' === $result['failure_reason'], 'default failure reason' ); }
}
$payment = array( 'id' => 'tr_x', 'status' => 'failed', 'mode' => 'live', 'amount' => array( 'value' => '12.340', 'currency' => 'EUR' ), 'statusReason' => array( 'code' => 'declined' ), 'details' => array( 'failureReason' => 'fallback', 'terminalId' => 'term_A', 'cardLabel' => 'Visa', 'cardNumber' => '1234', 'cardCountryCode' => '', 'cardAudience' => null, 'cardFunding' => 123, 'receipt' => array( 'authorizationCode' => 'abc', 'cardReadMethod' => 'contactless', 'cardVerificationMethod' => 'pin' ) ) );
$result = Provider::normalize( $payment );
expect( 'declined' === $result['failure_reason'], 'statusReason wins' );
expect( '12.340' === $result['amount'] && 'EUR' === $result['currency'], 'money strings pass through exactly' );
expect( array( 'mollie_payment' => 'tr_x', 'mollie_mode' => 'live', 'reader' => 'term_A' ) === $result['provider_refs'], 'refs must contain only owned keys' );
expect( array( 'card_label' => 'Visa', 'card_last4' => '1234', 'auth_code' => 'abc', 'read_method' => 'contactless', 'verification' => 'pin', 'mollie_payment' => 'tr_x' ) === $result['receipt'], 'receipt keeps non-empty strings only' );
unset( $payment['statusReason'] );
expect( 'fallback' === Provider::normalize( $payment )['failure_reason'], 'details failure fallback' );
$payment['status'] = 'surprise';
expect( 'provider_error' === Provider::normalize( $payment )['failure_reason'], 'unknown status ignores provider reason' );
$payment['details']['cardCountryCode'] = 'NL';
$payment['details']['cardAudience'] = 'consumer';
$payment['details']['cardFunding'] = 'debit';
$result = Provider::normalize( $payment );
expect( 'NL' === $result['receipt']['card_country'] && 'consumer' === $result['receipt']['card_audience'] && 'debit' === $result['receipt']['card_funding'], 'remaining receipt fields' );
echo "server-status-normalize ok\n";
