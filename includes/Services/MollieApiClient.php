<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Diagnostics;
use WCPOS\WooCommercePOS\MollieTerminal\Logger;

class MollieApiClient {
	private const BASE_URL = 'https://api.mollie.com/v2';
	private $api_key;

	public function __construct( string $api_key ) { $this->api_key = $api_key; }
	public function has_api_key(): bool { return '' !== trim( $this->api_key ); }

	public function get_profile( string $profile_id = 'me' ): array { return $this->request( 'GET', '/profiles/' . rawurlencode( $profile_id ) ); }
	public function list_terminals( string $profile_id = '', int $timeout = 0 ): array { return $this->request( 'GET', '/terminals?limit=250' . ( '' !== $profile_id ? '&profileId=' . rawurlencode( $profile_id ) : '' ), null, $timeout ); }
	public function get_terminal( string $terminal_id ): array { return $this->request( 'GET', '/terminals/' . rawurlencode( $terminal_id ) ); }
	public function create_terminal_pairing_code( string $profile_id, string $name ): array { return $this->request( 'POST', '/terminals', array( 'profileId' => $profile_id, 'description' => $name ) ); }
	public function create_payment( array $payload ): array { return $this->request( 'POST', '/payments', $payload ); }
	public function get_payment( string $payment_id ): array { return $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) ); }
	public function cancel_payment( string $payment_id ): array { return $this->request( 'DELETE', '/payments/' . rawurlencode( $payment_id ) ); }
	public function list_refunds( string $payment_id ): array { return $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) . '/refunds' ); }
	public function create_refund( string $payment_id, array $payload ): array { return $this->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/refunds', $payload ); }

	private function request( string $method, string $path, ?array $body = null, int $timeout = 0 ): array {
		if ( ! $this->has_api_key() ) {
			Diagnostics::record_api_error( 'Mollie API key is missing.', array( 'method' => $method, 'path' => $path ) );
			throw new RuntimeException( 'Mollie API key is missing.' );
		}
		$args = array(
			'method' => $method,
			'timeout' => $timeout > 0 ? $timeout : 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			Diagnostics::record_api_error( 'Mollie API transport error: ' . $response->get_error_message(), array( 'method' => $method, 'path' => $path ) );
			Logger::log( 'Mollie API transport error: ' . $response->get_error_message(), array( Logger::CONTEXT_DIAGNOSTICS_RECORDED => true ) );
			throw new RuntimeException( 'Mollie API request failed.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array( 'success' => true ) : json_decode( $raw, true );
		if ( ! is_array( $data ) ) { $data = array( 'raw' => $raw ); }
		if ( $code < 200 || $code >= 300 ) {
			$message = $data['detail'] ?? $data['title'] ?? 'Mollie API error.';
			Diagnostics::record_api_error( sprintf( 'Mollie API error (%s %s, HTTP %d): %s', $method, $path, $code, $message ), array( 'method' => $method, 'path' => $path, 'status' => $code, 'body' => $data ) );
			Logger::log( 'Mollie API error', array( Logger::CONTEXT_DIAGNOSTICS_RECORDED => true, 'status' => $code, 'body' => $data ) );
			throw new RuntimeException( $message );
		}
		Diagnostics::record( 'debug', sprintf( 'Mollie API request succeeded (%s %s).', $method, $path ), array( 'method' => $method, 'path' => $path, 'status' => $code ) );
		return $data;
	}
}
