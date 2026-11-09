<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Server;

use WCPOS\WooCommercePOS\MollieTerminal\Settings;

final class Registration {
	// First Pro release the extension runs on: the shared payments base and the order-pay panel.
	public const REQUIRED_PRO_VERSION = '2.0.0';
	private static $registered = false;

	public static function pro_supported(): bool {
		return function_exists( 'wcpos_pro_register_server_provider' ) && function_exists( 'wcpos_pro_requires' ) && wcpos_pro_requires( self::REQUIRED_PRO_VERSION );
	}

	public static function register(): bool {
		if ( ! self::pro_supported() ) { return false; }
		if ( ! self::$registered ) {
			wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Mollie_Server_Provider::class );
			Pos_Reader_Settings::migrate_once();
			self::$registered = true;
		}
		return true;
	}
}
