<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Server;

use WCPOS\WooCommercePOS\MollieTerminal\Settings;

final class Registration {
	// First Pro version with wcpos_pro_register_server_provider() and the shared server handler.
	public const REQUIRED_PRO_VERSION = '1.11.0';
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

	public static function activation_check( string $plugin_file ): void {
		if ( function_exists( 'wcpos_pro_requires' ) && ! wcpos_pro_requires( self::REQUIRED_PRO_VERSION ) ) {
			wcpos_pro_requires( self::REQUIRED_PRO_VERSION, $plugin_file );
		}
	}
}
