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
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
class ReaderClient extends MollieApiClient {
	public $error;
	public function __construct() {}
	public function list_terminals( string $profile_id = '', int $timeout = 0 ): array {
		if ( $this->error ) { throw $this->error; }
		return array( '_embedded' => array( 'terminals' => array(
			array( 'id' => 'term_A', 'description' => 'Desk', 'brand' => 'PAX', 'model' => 'A', 'status' => 'active', 'mode' => 'live' ),
			array( 'id' => 'term_B', 'description' => '', 'brand' => 'PAX', 'model' => 'B', 'status' => 'active' ),
			array( 'id' => 'term_C', 'status' => 'active' ),
			array( 'id' => 'term_D', 'status' => 'inactive', 'mode' => 'live' ),
			array( 'id' => 'term_E', 'status' => 'active', 'mode' => 'test' ),
			array( 'id' => 'term_F', 'status' => 'pending', 'mode' => 'live' ),
		) ) );
	}
}
$client = new ReaderClient();
$provider = new Provider( new Settings( array( 'mode' => 'live' ) ), $client );
expect( array(
	array( 'id' => 'term_A', 'label' => 'Desk', 'status' => 'online' ),
	array( 'id' => 'term_B', 'label' => 'PAX B', 'status' => 'online' ),
	array( 'id' => 'term_C', 'label' => 'term_C', 'status' => 'online' ),
) === $provider->list_readers(), 'active same-mode readers, labels and online projection' );
foreach ( array( new RuntimeException( 'offline' ), new InvalidArgumentException( 'bad request' ) ) as $error ) {
	$client->error = $error;
	$r = $provider->list_readers();
	expect( 'wcpos_provider_error' === $r->get_error_code() && $error->getMessage() === $r->get_error_message(), 'exception converted with message' );
	expect( array( 'status' => 502, 'detail' => array( 'code' => 'mollie_api_error', 'message' => $error->getMessage() ) ) === $r->get_error_data(), 'error detail shape' );
}
echo "server-list-readers ok\n";
