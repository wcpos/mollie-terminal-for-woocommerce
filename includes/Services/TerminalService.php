<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;

class TerminalService {
	private $client;
	private $settings;
	public function __construct( MollieApiClient $client, Settings $settings ) { $this->client = $client; $this->settings = $settings; }
	public function list_terminals( int $timeout = 0 ): array {
		// List all terminals on the account. The Profile ID setting was removed;
		// any value left in the database is intentionally ignored so upgraded
		// sites are not stuck on a single, now-uneditable profile.
		$result = $this->client->list_terminals( '', $timeout );
		return $result['_embedded']['terminals'] ?? $result['items'] ?? array();
	}
	public function create_pairing_code( string $name ): array { return $this->client->create_terminal_pairing_code( $this->settings->profile_id(), $name ); }
	public function validate_terminal( string $terminal_id ): array {
		if ( '' === $terminal_id ) { throw new RuntimeException( 'Terminal ID is required.' ); }
		$terminal = $this->client->get_terminal( $terminal_id );
		$status = strtolower( (string) ( $terminal['status'] ?? $terminal['mode'] ?? '' ) );
		if ( isset( $terminal['mode'] ) && $terminal['mode'] !== $this->settings->mode() ) {
			throw new RuntimeException( 'Terminal belongs to a different Mollie environment.' );
		}
		if ( in_array( $status, array( 'inactive', 'disabled' ), true ) ) {
			throw new RuntimeException( 'Selected terminal is not active.' );
		}
		return $terminal;
	}
}
