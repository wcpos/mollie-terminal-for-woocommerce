<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
class WC_Payment_Gateway {
	public $id, $method_title, $method_description, $supports, $title, $description, $form_fields;
	public function init_settings() {}
	public function get_option( $key, $default = '' ) { return $default; }
	public function admin_options() {}
}
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function wcpos_pro_requires( $version ) { return $GLOBALS['supported']; }
function wcpos_pro_register_server_provider( $gateway, $adapter ) {}
function admin_url( $path ) { return 'https://shop.example/admin/' . $path; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function esc_url( $url ) { return $url; }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
expect( file_exists( __DIR__ . '/../../includes/Server/Pos_Reader_Settings.php' ), 'reader settings bridge is missing' );
require_once __DIR__ . '/../../includes/Server/Pos_Reader_Settings.php';
use WCPOS\WooCommercePOS\MollieTerminal\Server\Pos_Reader_Settings as Readers;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
define( 'MTFWC_VERSION', 'test-version' );
$option = 'woocommerce_pos_settings_payment_gateways';
$gateway = Settings::GATEWAY_ID;
$legacy = array( 'default_terminal_id' => 'term_A', 'enabled_terminals' => array( 'term_B' ), 'lock_terminal' => 'yes' );
$options[ 'woocommerce_' . $gateway . '_settings' ] = $legacy;
$options[$option] = array( 'gateways' => array( 'cash' => array( 'enabled' => true ) ), 'other' => 'preserved' );
Readers::migrate_once();
expect( array( 'default_reader' => 'term_A', 'allowed_readers' => array( 'term_B' ), 'lock_to_default' => true ) === $options[$option]['gateways'][$gateway], 'migrate raw values without default appended' );
expect( MTFWC_VERSION === get_option( Readers::MIGRATED_OPTION ), 'migration marker' );
$before = $options[$option];
$options[ 'woocommerce_' . $gateway . '_settings' ] = array();
Readers::migrate_once();
expect( $before === $options[$option], 'second migration is a no-op' );
delete_option( Readers::MIGRATED_OPTION );
$options[ 'woocommerce_' . $gateway . '_settings' ] = $legacy;
$options[$option]['gateways'][$gateway] = array( 'default_reader' => 'pos_choice', 'enabled' => true );
Readers::migrate_once();
expect( array( 'default_reader' => 'pos_choice', 'enabled' => true, 'allowed_readers' => array( 'term_B' ), 'lock_to_default' => true ) === $options[$option]['gateways'][$gateway], 'merchant POS value preserved, absent keys filled' );
Readers::mirror( new Settings( array( 'default_terminal_id' => 'term_C', 'enabled_terminals' => array( 'term_D' ), 'lock_terminal' => 'no' ) ) );
expect( array( 'default_reader' => 'term_C', 'enabled' => true, 'allowed_readers' => array( 'term_D' ), 'lock_to_default' => false ) === $options[$option]['gateways'][$gateway], 'save overwrites all reader fields only' );
expect( array( 'enabled' => true ) === $options[$option]['gateways']['cash'] && 'preserved' === $options[$option]['other'], 'other settings preserved' );
Readers::mirror( new Settings( array( 'default_terminal_id' => '', 'enabled_terminals' => '', 'lock_terminal' => 'yes' ) ) );
expect( array() === $options[$option]['gateways'][$gateway]['allowed_readers'] && true === $options[$option]['gateways'][$gateway]['lock_to_default'], 'empty raw multiselect becomes array, lock copies raw yes' );
require_once __DIR__ . '/../../includes/Server/Registration.php';
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';
require_once __DIR__ . '/../../includes/Gateway.php';
$supported = true;
$ui = new \WCPOS\WooCommercePOS\MollieTerminal\Gateway();
$hook = 'woocommerce_update_options_payment_gateways_' . $gateway;
expect( array( $ui, 'mirror_pos_reader_settings' ) === ( $hooks[$hook][20][0] ?? null ), 'mirror after gateway save' );
expect( ! isset( $ui->form_fields['enabled_terminals'] ), 'unavailable terminal list omits multiselect' );
foreach ( array( 'default_terminal_id', 'lock_terminal' ) as $field ) {
	expect( false !== strpos( $ui->form_fields[$field]['description'], 'checkout terminal tile' ), 'tile settings copy' );
}
$options[ 'woocommerce_' . $gateway . '_settings' ] = $legacy;
$ui->mirror_pos_reader_settings();
expect( array( 'term_B' ) === $options[$option]['gateways'][$gateway]['allowed_readers'], 'mirror reads saved allowlist even when field omitted' );
ob_start(); $ui->admin_options(); $html = ob_get_clean();
expect( false !== strpos( $html, 'wcpos/v2/payments/webhook?provider=mollie' ), 'Pro diagnostics URL' );
$supported = false;
$before = $options[$option];
$options[ 'woocommerce_' . $gateway . '_settings' ] = array();
$ui->mirror_pos_reader_settings();
expect( $before === $options[$option], 'without supported Pro save does not mirror' );
ob_start(); $ui->admin_options(); $html = ob_get_clean();
expect( false !== strpos( $html, 'Requires WooCommerce POS Pro 1.11.0 or newer (legacy checkout only)' ), 'legacy diagnostics guidance' );
$ui->init_form_fields();
expect( false === strpos( $ui->form_fields['default_terminal_id']['description'], 'checkout terminal tile' ), 'no tile copy without Pro' );
echo "server-reader-settings ok\n";
