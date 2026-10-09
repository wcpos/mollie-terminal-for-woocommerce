<?php
// The gateway is offered on POS requests and on the order-pay page a POS user opens while the
// POS switch is on, never on the shop checkout, and never because a site once saved the old
// web-checkout setting. The old panel's script is enqueued on the order-pay page alone.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

class WC_Payment_Gateway {
	public $enabled = 'yes'; // The old web-checkout setting, saved before the upgrade.
	public function is_available() { return 'yes' === $this->enabled; }
}
function woocommerce_pos_request() { return $GLOBALS['pos_request']; }
function is_checkout_pay_page() { return $GLOBALS['order_pay']; }
function current_user_can( $capability ) { return 'access_woocommerce_pos' === $capability && $GLOBALS['pos_user']; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function wcpos_get_settings( $id, $key = null ) { return array( 'gateways' => array( 'mollie_terminal_for_woocommerce' => array( 'enabled' => $GLOBALS['pos_switch'] ) ) ); }
function wp_enqueue_script( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_enqueue_style( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_localize_script() {}
function apply_filters( $hook, $value ) { return $value; }
function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function __( $text, $domain = null ) { return $text; }
if ( ! defined( 'MTFWC_VERSION' ) ) { define( 'MTFWC_VERSION', '1.0.0-test' ); }
if ( ! defined( 'MTFWC_PLUGIN_URL' ) ) { define( 'MTFWC_PLUGIN_URL', 'https://shop.example/plugins/mollie/' ); }

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Gateway.php';

$gateway = ( new ReflectionClass( WCPOS\WooCommercePOS\MollieTerminal\Gateway::class ) )->newInstanceWithoutConstructor();

// key, pos request, order-pay page, pos user, pos switch => available
$cases = array(
	array( 'live_key', true, false, false, true, true, 'a POS request with a key is offered the gateway' ),
	array( 'live_key', true, false, false, false, true, 'on a POS request the switch is Free\'s gateway filter to apply, not this method' ),
	array( '', true, false, false, true, false, 'no API key, nothing is offered' ),
	array( 'live_key', false, true, true, true, true, 'the order-pay page opened by a POS user is offered the gateway' ),
	array( 'live_key', false, true, true, false, false, 'the order-pay page honours the POS switch: off means not offered' ),
	array( 'live_key', false, true, false, true, false, 'the order-pay page without the POS capability is not' ),
	array( 'live_key', false, false, true, true, false, 'the shop checkout is never offered the gateway, whatever the saved enabled option' ),
);
foreach ( $cases as $case ) {
	list( $key, $GLOBALS['pos_request'], $GLOBALS['order_pay'], $GLOBALS['pos_user'], $GLOBALS['pos_switch'], $expected, $message ) = $case;
	$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => array( 'api_key' => $key, 'enabled' => 'yes' ) );
	expect( $expected === $gateway->is_available(), $message );
}

// The old panel's script is enqueued on the order-pay page only, and only under the QR carve-out
// (order-pay-panel-is-pro.php covers Pro's panel enqueuing nothing).
$GLOBALS['options']['woocommerce_mollie_terminal_for_woocommerce_settings']['qr_methods'] = array( 'ideal' );
foreach ( array( true => array( 'mtfwc-payment', 'mtfwc-payment' ), false => array() ) as $pay_page => $expected ) {
	$GLOBALS['order_pay'] = (bool) $pay_page;
	$GLOBALS['enqueued'] = array();
	$gateway->enqueue_payment_scripts();
	expect( $expected === $GLOBALS['enqueued'], $pay_page ? 'the panel script and style are enqueued on the order-pay page' : 'nothing is enqueued off the order-pay page' );
}

echo "gateway-availability ok\n";
