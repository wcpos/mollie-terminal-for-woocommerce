<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Server;

use WCPOS\WooCommercePOS\MollieTerminal\Settings;

final class Pos_Reader_Settings {
	// Seed once without replacing reader choices already made in POS.
	public const MIGRATED_OPTION = 'mtfwc_pos_reader_settings_migrated';
	// Pro reads these keys directly from Free's payment gateway settings option.
	private const SETTINGS_OPTION = 'woocommerce_pos_settings_payment_gateways';

	public static function migrate_once(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) { return; }
		self::mirror( new Settings(), false );
		update_option( self::MIGRATED_OPTION, MTFWC_VERSION, false );
	}

	public static function mirror( Settings $settings, bool $overwrite = true ): void {
		$options = get_option( self::SETTINGS_OPTION, array() );
		$gateway = $options['gateways'][ Settings::GATEWAY_ID ] ?? array();
		$raw = $settings->get( 'enabled_terminals', array() );
		$values = array(
			'default_reader' => $settings->default_terminal_id(),
			'allowed_readers' => array_values( array_filter( (array) $raw, static function ( $id ) { return is_string( $id ) && '' !== $id; } ) ),
			// Effective lock only: a lock with no default would leave the till with no reader.
			'lock_to_default' => $settings->lock_terminal(),
		);
		$options['gateways'][ Settings::GATEWAY_ID ] = $overwrite ? array_replace( $gateway, $values ) : $gateway + $values;
		update_option( self::SETTINGS_OPTION, $options );
	}
}
