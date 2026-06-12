<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

class Settings {
	public const GATEWAY_ID = 'mollie_terminal_for_woocommerce';

	private $options;

	public function __construct( ?array $options = null ) {
		$this->options = $options;
	}

	public function get( string $key, $default = '' ) {
		$options = $this->options;
		if ( null === $options ) {
			$options = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		}
		return $options[ $key ] ?? $default;
	}

	public function api_key(): string { return (string) $this->get( 'api_key', '' ); }
	public function profile_id(): string { return (string) $this->get( 'profile_id', '' ); }
	public function mode(): string { return 'live' === $this->get( 'mode', 'test' ) ? 'live' : 'test'; }
	public function default_terminal_id(): string { return (string) $this->get( 'default_terminal_id', '' ); }

	public function webhook_url(): string {
		return add_query_arg( array( 'action' => 'mtfwc_mollie_webhook' ), admin_url( 'admin-ajax.php' ) );
	}
}
