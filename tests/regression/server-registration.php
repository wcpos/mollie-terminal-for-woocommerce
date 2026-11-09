<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Registration.php' ), 'registration is missing' );
require_once __DIR__ . '/../../includes/Server/Registration.php';
require_once __DIR__ . '/../../includes/Server/Pos_Reader_Settings.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Registration;
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
define( 'ABSPATH', __DIR__ );
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://shop.example/plugins/mollie/'; }
function register_activation_hook( $file, $callback ) { $GLOBALS['activation'] = $callback; }
function register_deactivation_hook( $file, $callback ) {}
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function wp_doing_ajax() { return false; }
function esc_html__( $text ) { return $text; }
require_once __DIR__ . '/../../mollie-terminal-for-woocommerce.php';
// Pro defines wcpos_pro_requires() and the registration API from its own plugins_loaded hook at
// priority 20; the gate runs after that, and provider registration happens inside it.
expect( 'WCPOS\\WooCommercePOS\\MollieTerminal\\init' === ( $hooks['plugins_loaded'][30][0] ?? null ), 'gate runs at 30, after Pro loads' );
expect( ! isset( $hooks['plugins_loaded'][11] ), 'nothing runs before Pro loads' );
expect( 1 === count( $hooks['plugins_loaded'][30] ), 'registration happens inside the gate, not on its own hook' );
expect( ! method_exists( Registration::class, 'activation_check' ), 'the Pro-less activation path is gone' );
expect( '2.0.0' === Registration::REQUIRED_PRO_VERSION, 'the floor is Pro 2.0.0' );
$registered = array();
$requires_calls = array();
expect( false === Registration::register() && array() === $registered, 'no Pro registers nothing' );
$hooks = array();
WCPOS\WooCommercePOS\MollieTerminal\init();
expect( isset( $hooks['admin_notices'] ) && ! isset( $hooks['woocommerce_payment_gateways'] ), 'no Pro gets a notice and no gateway' );
// Conditional declarations happen at runtime, so the first assertions really have no Pro functions.
if ( ! function_exists( 'wcpos_pro_requires' ) ) {
	function wcpos_pro_requires( $version, $file = '' ) { $GLOBALS['requires_calls'][] = array( $version, $file ); return $GLOBALS['supported']; }
	function wcpos_pro_register_server_provider( $gateway, $class ) { $GLOBALS['registered'][] = array( $gateway, $class ); }
}
$supported = false;
expect( false === Registration::register() && array() === $registered, 'old Pro does not register' );
$requires_calls = array();
$hooks = array();
WCPOS\WooCommercePOS\MollieTerminal\init();
expect( array( array( '2.0.0', realpath( __DIR__ . '/../../mollie-terminal-for-woocommerce.php' ) ) ) === $requires_calls, 'the gate asks Pro for 2.0.0 and names the plugin file' );
expect( isset( $hooks['admin_notices'] ) && array() === $registered && ! isset( $hooks['woocommerce_payment_gateways'] ), 'old Pro gets a notice, no gateway and no provider' );
$supported = true;
$requires_calls = array();
$hooks = array();
$registered = array();
WCPOS\WooCommercePOS\MollieTerminal\init();
expect( in_array( array( WCPOS\WooCommercePOS\MollieTerminal\Gateway::class, 'register_gateway' ), $hooks['woocommerce_payment_gateways'][10] ?? array(), true ), 'supported Pro registers the gateway from init()' );
expect( array( array( Settings::GATEWAY_ID, Mollie_Server_Provider::class ) ) === $registered, 'supported Pro registers the provider from init()' );
expect( ! isset( $hooks['admin_notices'] ), 'supported Pro gets no notice' );
foreach ( array( 'woocommerce_create_refund', 'wp_ajax_mtfwc_mollie_webhook', 'wp_ajax_nopriv_mtfwc_mollie_webhook', 'woocommerce_order_status_changed', 'mtfwc_retry_open_payment_cancel', 'cron_schedules', 'mtfwc_sweep_stale_payments' ) as $hook ) {
	expect( isset( $hooks[ $hook ] ), 'the gate still registers ' . $hook );
}
expect( MTFWC_VERSION === get_option( WCPOS\WooCommercePOS\MollieTerminal\Server\Pos_Reader_Settings::MIGRATED_OPTION ), 'reader settings migrate once inside the gate' );
$registered = array();
expect( true === Registration::register() && array() === $registered, 'registration idempotent after init()' );
$supported = false;
$requires_calls = array();
call_user_func( $activation );
expect( 1 === count( $requires_calls ) && '2.0.0' === $requires_calls[0][0] && realpath( __DIR__ . '/../../mollie-terminal-for-woocommerce.php' ) === $requires_calls[0][1], 'activation hook records the Pro requirement after the PHP check' );
echo "server-registration ok\n";
