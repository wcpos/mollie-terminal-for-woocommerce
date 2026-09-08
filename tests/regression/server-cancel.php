<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php' ), 'server adapter is missing' );
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider as Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
class CancelClient extends MollieApiClient {
	public $script;
	public $calls = array();
	public $throw_delete = false;
	public function __construct() {}
	public function get_payment( string $payment_id, array $include = array() ): array {
		$this->calls[] = 'GET ' . $payment_id;
		$result = array_shift( $this->script );
		if ( $result instanceof Exception ) { throw $result; }
		return $result;
	}
	public function cancel_payment( string $payment_id ): array {
		$this->calls[] = 'DELETE ' . $payment_id;
		if ( $this->throw_delete ) { throw new RuntimeException( 'race' ); }
		return array();
	}
}
foreach ( array(
	array( array( array( 'status' => 'open', 'isCancelable' => true ), array( 'status' => 'canceled' ) ), false, 'final', true ),
	array( array( array( 'status' => 'open', 'isCancelable' => false ) ), false, 'requested', false ),
	array( array( array( 'status' => 'paid' ) ), false, 'requested', false ),
	array( array( array( 'status' => 'authorized' ) ), false, 'requested', false ),
	array( array( array( 'status' => 'canceled' ) ), false, 'final', false ),
	array( array( array( 'status' => 'expired' ) ), false, 'final', false ),
	array( array( array( 'status' => 'failed' ) ), false, 'final', false ),
	array( array( array( 'status' => 'open' ), array( 'status' => 'paid' ) ), true, 'requested', true ),
) as $case ) {
	$client = new CancelClient();
	list( $client->script, $client->throw_delete, $expected, $deleted ) = $case;
	$provider = new Provider( null, $client );
	expect( $expected === $provider->cancel( 'tr_x' ), 'cancel result' );
	expect( ( $deleted ? array( 'GET tr_x', 'DELETE tr_x', 'GET tr_x' ) : array( 'GET tr_x' ) ) === $client->calls, 'cancel sequence' );
}
$client->script = array( new RuntimeException( 'offline' ) );
$result = $provider->cancel( 'tr_x' );
expect( is_wp_error( $result ) && 502 === $result->get_error_data()['status'], 'first GET failure' );
$client->script = array( array( 'status' => 'open' ), new RuntimeException( 'offline' ) );
expect( is_wp_error( $provider->cancel( 'tr_x' ) ), 're-read failure is not final' );
$client->script = array( array( 'id' => 'tr_x', 'status' => 'authorized' ) );
expect( 'in_progress' === $provider->fetch( 'tr_x' )['status'], 'fetch normalizes' );
$client->script = array( new RuntimeException( 'offline' ) );
expect( is_wp_error( $provider->fetch( 'tr_x' ) ), 'fetch error normalized' );
echo "server-cancel ok\n";
