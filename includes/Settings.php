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

	/**
	 * Terminals the merchant allows at checkout. Empty means all active
	 * terminals are allowed (no restriction configured). When a restriction is
	 * configured, the default terminal is always included — the merchant chose
	 * it explicitly, and excluding it would brick checkout when the terminal
	 * selection is locked to the default.
	 */
	public function enabled_terminal_ids(): array {
		$value = $this->get( 'enabled_terminals', array() );
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : array( $value );
		}
		if ( ! is_array( $value ) ) { return array(); }
		$ids = array_values( array_filter( array_map( 'strval', $value ) ) );
		$default = $this->default_terminal_id();
		if ( $ids && '' !== $default && ! in_array( $default, $ids, true ) ) {
			$ids[] = $default;
		}
		return $ids;
	}

	/**
	 * When locked, cashiers cannot pick a terminal at checkout — the default
	 * terminal is always used. Only effective when a default is configured.
	 */
	public function lock_terminal(): bool {
		return 'yes' === $this->get( 'lock_terminal', 'no' ) && '' !== $this->default_terminal_id();
	}

	public function webhook_url(): string {
		return add_query_arg( array( 'action' => 'mtfwc_mollie_webhook' ), admin_url( 'admin-ajax.php' ) );
	}
}
